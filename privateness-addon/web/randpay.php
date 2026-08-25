<?php
declare(strict_types=1);

require __DIR__ . '/../lib/Container.php';

use lib\Container;
use lib\Emercoin;

const RP_INPUT_TXID = 'ecececececececececececececececececececececececececececececec';
const RP_DEFAULT_RISK = 50;
const RP_MAX_RISK = 10000;
const RP_TIMEOUT = 180;
const RP_MAX_RAW_BYTES = 1048576;

function fail_request(int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    die($message . "\n");
}

function b64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64url_decode(string $data): string {
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $data)) {
        fail_request(400, 'Invalid RandPay token');
    }
    $padding = (4 - strlen($data) % 4) % 4;
    $decoded = base64_decode(strtr($data . str_repeat('=', $padding), '-_', '+/'), true);
    if ($decoded === false) {
        fail_request(400, 'Invalid RandPay token');
    }
    return $decoded;
}

function addon_state_dir(): string {
    $configured = getenv('NVS_RANDPAY_STATE_DIR');
    return $configured !== false && $configured !== ''
        ? rtrim($configured, '/') : dirname(__DIR__) . '/.nvs-batch-addon';
}

function signing_key(): string {
    $configured = getenv('NVS_RANDPAY_KEY_FILE');
    $path = $configured !== false && $configured !== ''
        ? $configured : addon_state_dir() . '/randpay.key';
    $key = @file_get_contents($path);
    if (!is_string($key) || strlen($key) !== 32) {
        fail_request(503, 'RandPay server key is unavailable');
    }
    return $key;
}

function amount_to_micro($value): int {
    if (is_float($value)) {
        $value = number_format($value, 6, '.', '');
    } elseif (is_int($value)) {
        $value = (string) $value;
    } else {
        $value = trim((string) $value);
    }
    if (!preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,6}))?$/', $value, $match)) {
        fail_request(500, 'Invalid EMC quote precision');
    }
    $fraction = str_pad(isset($match[2]) ? $match[2] : '', 6, '0');
    $whole = (int) $match[1];
    if ($whole > intdiv(PHP_INT_MAX - (int) $fraction, 1000000)) {
        fail_request(500, 'EMC quote is too large');
    }
    return $whole * 1000000 + (int) $fraction;
}

function micro_to_amount(int $micro): string {
    return intdiv($micro, 1000000) . '.' . str_pad((string) ($micro % 1000000), 6, '0', STR_PAD_LEFT);
}

function slot_id(): string {
    $slot = isset($_REQUEST['slot']) ? (string) $_REQUEST['slot'] : '';
    if (!preg_match('/^[0-9a-f]{32}$/', $slot)) {
        fail_request(400, 'Invalid slot identifier');
    }
    return $slot;
}

function payable_slot(string $slotId, bool $allowPaid = false): array {
    $slot = Container::createSlots()->showSlot($slotId);
    if (empty($slot)) {
        fail_request(404, 'Slot not found');
    }
    if ($slot['status'] !== 'GENERATED' && $slot['status'] !== 'UPDATED' &&
        !($allowPaid && $slot['status'] === 'PAYED')) {
        fail_request(409, 'Slot is not awaiting payment');
    }
    if (empty($slot['addr']['EMC']['min_sum'])) {
        fail_request(409, 'Slot has no EMC quote');
    }
    return $slot;
}

function make_token(string $slot, int $risk, int $expiry, int $expectedMicro): string {
    $payload = implode('|', ['v1', $slot, $risk, $expiry, $expectedMicro]);
    return b64url_encode($payload . hash_hmac('sha256', $payload, signing_key(), true));
}

function verify_token(string $token, bool $allowExpired = false): array {
    $wire = b64url_decode($token);
    if (strlen($wire) < 33) {
        fail_request(400, 'Invalid RandPay token');
    }
    $payload = substr($wire, 0, -32);
    $mac = substr($wire, -32);
    if (!hash_equals(hash_hmac('sha256', $payload, signing_key(), true), $mac)) {
        fail_request(403, 'Invalid RandPay authorization');
    }
    $fields = explode('|', $payload);
    if (count($fields) !== 5 || $fields[0] !== 'v1' ||
        !preg_match('/^[0-9a-f]{32}$/', $fields[1]) ||
        !ctype_digit($fields[2]) || !ctype_digit($fields[3]) || !ctype_digit($fields[4])) {
        fail_request(400, 'Malformed RandPay authorization');
    }
    if (!$allowExpired && (int) $fields[3] < time()) {
        fail_request(410, 'RandPay challenge expired');
    }
    return [
        'slot' => $fields[1], 'risk' => (int) $fields[2], 'expiry' => (int) $fields[3],
        'expected_micro' => (int) $fields[4]
    ];
}

function receipt_path(string $slot): string {
    return addon_state_dir() . '/randpay-receipts/' . $slot . '.xml';
}

function authorization_payload(array $record): string {
    return implode('|', [
        'v1', $record['slot'], $record['token_sha256'], $record['txid'], $record['risk'],
        $record['amount_micro'], $record['won'], $record['sent'], $record['accepted_utc']
    ]);
}

function save_authorization(array $record): void {
    $dir = dirname(receipt_path($record['slot']));
    if (!is_dir($dir) || !is_writable($dir)) {
        fail_request(503, 'RandPay receipt directory is unavailable');
    }
    $mac = hash_hmac('sha256', authorization_payload($record), signing_key());
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
        '<randpayAuthorization version="1" slot="' . htmlspecialchars($record['slot'], ENT_XML1, 'UTF-8') .
        '" token-sha256="' . $record['token_sha256'] . '" txid="' . $record['txid'] .
        '" risk="' . $record['risk'] . '" amount-micro-emc="' . $record['amount_micro'] .
        '" won="' . $record['won'] . '" sent="' . $record['sent'] .
        '" accepted-utc="' . $record['accepted_utc'] . '"><hmac algorithm="HMAC-SHA256">' .
        $mac . '</hmac></randpayAuthorization>' . "\n";
    $temporary = tempnam($dir, '.randpay.');
    if ($temporary === false || file_put_contents($temporary, $xml, LOCK_EX) === false ||
        !chmod($temporary, 0600) || !rename($temporary, receipt_path($record['slot']))) {
        if (is_string($temporary)) {
            @unlink($temporary);
        }
        fail_request(503, 'Could not persist RandPay authorization');
    }
}

function load_authorization(string $slot, string $token): ?array {
    $path = receipt_path($slot);
    if (!is_file($path)) {
        return null;
    }
    $doc = new DOMDocument();
    if (!$doc->load($path, LIBXML_NONET | LIBXML_NOBLANKS) ||
        $doc->documentElement->tagName !== 'randpayAuthorization') {
        fail_request(503, 'Stored RandPay authorization is invalid');
    }
    $root = $doc->documentElement;
    $record = [
        'slot' => $root->getAttribute('slot'),
        'token_sha256' => $root->getAttribute('token-sha256'),
        'txid' => $root->getAttribute('txid'),
        'risk' => (int) $root->getAttribute('risk'),
        'amount_micro' => (int) $root->getAttribute('amount-micro-emc'),
        'won' => $root->getAttribute('won'),
        'sent' => $root->getAttribute('sent'),
        'accepted_utc' => $root->getAttribute('accepted-utc')
    ];
    $macNode = $root->getElementsByTagName('hmac')->item(0);
    $storedMac = $macNode ? trim($macNode->textContent) : '';
    if ($record['slot'] !== $slot || $record['token_sha256'] !== hash('sha256', $token) ||
        !hash_equals(hash_hmac('sha256', authorization_payload($record), signing_key()), $storedMac)) {
        fail_request(403, 'Stored RandPay authorization does not match this request');
    }
    return $record;
}

function public_url(): string {
    $configured = getenv('NVS_PUBLIC_URL');
    $url = $configured !== false && $configured !== '' ? $configured : 'https://nvs.ness.cx';
    if (!preg_match('#^https://[A-Za-z0-9.-]+(?::[0-9]+)?$#', $url)) {
        fail_request(503, 'NVS_PUBLIC_URL must be an HTTPS origin');
    }
    return rtrim($url, '/');
}

function render_challenge(string $slotId, array $slot): void {
    $risk = isset($_GET['risk']) ? (int) $_GET['risk'] : RP_DEFAULT_RISK;
    if ($risk < 1 || $risk > RP_MAX_RISK) {
        fail_request(400, 'RandPay risk is outside the supported range');
    }
    $expectedMicro = amount_to_micro($slot['addr']['EMC']['min_sum']);
    if ($expectedMicro <= 0 || $expectedMicro > intdiv(PHP_INT_MAX, $risk)) {
        fail_request(409, 'RandPay settlement is outside the supported range');
    }
    $settlementMicro = $expectedMicro * $risk;
    $maxEmc = getenv('NVS_RANDPAY_MAX_EMC');
    $maxMicro = amount_to_micro($maxEmc !== false && $maxEmc !== '' ? $maxEmc : '1000');
    if ($settlementMicro > $maxMicro) {
        fail_request(409, 'Winning settlement exceeds the server safety limit');
    }
    $settlement = micro_to_amount($settlementMicro);
    $chap = (string) Emercoin::randpay_mkchap($settlement, $risk, RP_TIMEOUT);
    $parts = explode(':', $chap, 3);
    if (count($parts) !== 3 || (int) $parts[1] !== $risk || amount_to_micro($parts[0]) !== $settlementMicro) {
        fail_request(503, 'Emercoin returned a malformed RandPay challenge');
    }
    $expiry = time() + RP_TIMEOUT;
    $token = make_token($slotId, $risk, $expiry, $expectedMicro);
    $submit = public_url() . '/randpay.php?token=' . rawurlencode($token);
    $uri = 'emercoin://randpay?amount=' . rawurlencode($parts[0]) . '&risk=' . $risk .
        '&chap=' . rawurlencode($parts[2]) . '&timeout=' . RP_TIMEOUT . '&submit=' . rawurlencode($submit);
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Native EMC RandPay</title>
<style>body{margin:0;background:#07120f;color:#dff7ec;font:16px system-ui}main{max-width:620px;margin:auto;padding:22px}.card{background:#0d211a;border:1px solid #295b49;border-radius:18px;padding:18px;margin:14px 0}h1{font-size:25px}code{display:block;overflow-wrap:anywhere;color:#85f0c3}button,a{display:grid;place-items:center;min-height:52px;border-radius:12px;background:#62e6ad;color:#062016;text-decoration:none;font-weight:800;margin:10px 0;border:0;width:100%}.secondary{background:#183c30;color:#dff7ec}canvas{display:block;background:white;padding:12px;border-radius:14px;margin:15px auto}.warning{color:#ffc979}</style></head><body><main><h1>Native EMC RandPay</h1>
<div class="card"><strong>Expected service value</strong><code><?= htmlspecialchars(micro_to_amount($expectedMicro), ENT_QUOTES, 'UTF-8') ?> EMC</code><p>Risk <?= $risk ?> means a 1/<?= $risk ?> winning probability and a <?= htmlspecialchars($settlement, ENT_QUOTES, 'UTF-8') ?> EMC winning settlement. Both a valid win and a valid loss authorize the complete service.</p></div>
<canvas id="randpay-qr" width="280" height="280" aria-label="QR code for the native Emercoin RandPay request"></canvas>
<a href="<?= htmlspecialchars($uri, ENT_QUOTES, 'UTF-8') ?>">Open in an Emercoin RandPay wallet</a><button class="secondary" id="copy">Copy RandPay URI</button><code id="uri"><?= htmlspecialchars($uri, ENT_QUOTES, 'UTF-8') ?></code>
<div class="card warning">This is native Emercoin RandPay in EMC. It does not claim that NCH implements RandPay. Return to the slot page for the exact one-transaction NCH cohort payment.</div>
<a class="secondary" href="/slot.php?slot=<?= rawurlencode($slotId) ?>">Exact EMC / NESS / NCH payment options</a>
<form method="get" class="card"><input type="hidden" name="slot" value="<?= htmlspecialchars($slotId, ENT_QUOTES, 'UTF-8') ?>"><label>Aggregation risk <input name="risk" type="number" min="1" max="<?= RP_MAX_RISK ?>" value="<?= $risk ?>"></label><button type="submit">Create a fresh challenge</button></form>
</main><script src="/js/ness-qrcode.js"></script><script>const uri=document.getElementById('uri').textContent;NessQRCode.render(document.getElementById('randpay-qr'),uri);document.getElementById('copy').onclick=()=>navigator.clipboard.writeText(uri);</script></body></html>
<?php
}

function accept_ticket(string $token): void {
    $auth = verify_token($token, true);
    $slotId = $auth['slot'];
    $record = load_authorization($slotId, $token);
    if ($record === null && $auth['expiry'] < time()) {
        fail_request(410, 'RandPay challenge expired');
    }
    $slot = payable_slot($slotId, $record !== null);
    $currentExpected = amount_to_micro($slot['addr']['EMC']['min_sum']);
    if ($currentExpected !== $auth['expected_micro']) {
        fail_request(409, 'Slot quote changed after the RandPay challenge');
    }
    if ($record === null) {
        $raw = isset($_POST['rawtx']) ? trim((string) $_POST['rawtx']) : trim((string) file_get_contents('php://input'));
        if ($raw === '' || strlen($raw) > RP_MAX_RAW_BYTES || strlen($raw) % 2 !== 0 || !ctype_xdigit($raw)) {
            fail_request(400, 'Invalid RandPay raw transaction');
        }
        $decoded = Emercoin::decode_raw_transaction($raw);
        $hasRandPayInput = false;
        foreach (isset($decoded['vin']) ? $decoded['vin'] : [] as $input) {
            if (isset($input['txid']) && strtolower($input['txid']) === RP_INPUT_TXID) {
                $hasRandPayInput = true;
                break;
            }
        }
        if (!$hasRandPayInput || empty($decoded['vout'][0]['value'])) {
            fail_request(402, 'A native non-naive RandPay transaction is required');
        }
        $settlementMicro = $auth['expected_micro'] * $auth['risk'];
        if (amount_to_micro($decoded['vout'][0]['value']) !== $settlementMicro) {
            fail_request(402, 'RandPay transaction amount does not match the challenge');
        }
        $accepted = Emercoin::randpay_accept($raw, 2);
        $won = !empty($accepted['won']);
        $sent = !empty($accepted['sent']);
        if ((int) $accepted['risk'] !== $auth['risk'] ||
            amount_to_micro($accepted['expected']) !== $settlementMicro ||
            amount_to_micro($accepted['amount']) !== $settlementMicro ||
            ($won && !$sent) || (!$won && $sent)) {
            fail_request(402, 'Emercoin RandPay result does not match the signed challenge');
        }
        $record = [
            'slot' => $slotId, 'token_sha256' => hash('sha256', $token),
            'txid' => strtolower((string) $accepted['txid']), 'risk' => $auth['risk'],
            'amount_micro' => $settlementMicro, 'won' => $won ? 'true' : 'false',
            'sent' => $sent ? 'true' : 'false', 'accepted_utc' => gmdate('Y-m-d\TH:i:s\Z')
        ];
        save_authorization($record);
    }

    try {
        Container::createSlots()->processAuthorizedSlot($slotId);
    } catch (Throwable $error) {
        fail_request(503, 'RandPay authorized; EmerNVS execution will resume: ' . $error->getMessage());
    }
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<randpayReceipt version="1" slot="' . htmlspecialchars($slotId, ENT_XML1, 'UTF-8') .
        '" expected-micro-emc="' . $auth['expected_micro'] . '" settlement-micro-emc="' . $record['amount_micro'] .
        '" risk="' . $record['risk'] . '" won="' . $record['won'] . '" sent="' . $record['sent'] .
        '" txid="' . htmlspecialchars($record['txid'], ENT_XML1, 'UTF-8') . '" served="true" />' . "\n";
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = slot_id();
    render_challenge($id, payable_slot($id));
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_GET['token']) ? (string) $_GET['token'] : '';
    if ($token === '') {
        fail_request(400, 'Missing RandPay authorization token');
    }
    accept_ticket($token);
} else {
    header('Allow: GET, POST');
    fail_request(405, 'GET a challenge or POST a native RandPay transaction');
}
