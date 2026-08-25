<?php
declare(strict_types=1);

require __DIR__ . '/../lib/Container.php';

use lib\Container;

header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    die("POST an XML nameBatch\n");
}

$xml = isset($_POST['batch']) ? (string) $_POST['batch'] : '';
if ($xml === '' || strlen($xml) > 524288 || stripos($xml, '<!DOCTYPE') !== false) {
    http_response_code(400);
    die("Invalid batch size\n");
}

$doc = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA);
libxml_clear_errors();
libxml_use_internal_errors($previous);
if (!$ok || $doc->documentElement->tagName !== 'nameBatch') {
    http_response_code(400);
    die("Invalid nameBatch XML\n");
}

$root = $doc->documentElement;
if ($root->getAttribute('version') !== '1' ||
    $root->getAttribute('execution') !== 'sequential-name-new' ||
    !preg_match('/^[0-9a-f]{32}$/', $root->getAttribute('id'))) {
    http_response_code(400);
    die("Unsupported batch envelope\n");
}

$records = [];
$maxDays = 100;
foreach ($root->childNodes as $record) {
    if (!($record instanceof DOMElement)) {
        continue;
    }
    if ($record->tagName !== 'record' || $record->getAttribute('operation') !== 'NEW') {
        http_response_code(400);
        die("Only NEW record operations are accepted\n");
    }
    $name = $record->getAttribute('name');
    $days = (int) $record->getAttribute('days');
    $valueNode = $record->getElementsByTagName('value')->item(0);
    $value = $valueNode ? $valueNode->textContent : '';
    if (!preg_match('/^worm:[0-9a-f]{40}$/', $name) || isset($records[$name]) ||
        $days < 100 || $days > 9999 || strlen($value) < 1 || strlen($value) > 20480) {
        http_response_code(400);
        die("Invalid or duplicate record\n");
    }
    $valueDoc = new DOMDocument();
    if (!$valueDoc->loadXML($value, LIBXML_NONET | LIBXML_NOBLANKS) ||
        $valueDoc->documentElement->tagName !== 'objectAnchor' ||
        $valueDoc->documentElement->getAttribute('name') !== $name) {
        http_response_code(400);
        die("Invalid signed objectAnchor\n");
    }
    $anchor = $valueDoc->documentElement;
    $object = $anchor->getAttribute('object');
    $authority = $anchor->getAttribute('authority');
    $objectHash = $anchor->getAttribute('object-sha256');
    $signatureNode = $anchor->getElementsByTagName('signature')->item(0);
    $publicKey = strncmp($authority, 'ed25519:', 8) === 0
        ? base64_decode(substr($authority, 8), true) : false;
    $signature = $signatureNode ? base64_decode($signatureNode->textContent, true) : false;
    $unsigned = '<objectAnchor version="2" name="' . $name . '" object="' . $object .
        '" authority="' . $authority . '" object-sha256="' . $objectHash . '"></objectAnchor>';
    if ($anchor->getAttribute('version') !== '2' ||
        !preg_match('/^[0-9a-f]{40}$/', $object) ||
        !preg_match('/^[0-9a-f]{64}$/', $objectHash) ||
        !$signatureNode || $signatureNode->getAttribute('algorithm') !== 'Ed25519' ||
        $publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ||
        $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES ||
        !sodium_crypto_sign_verify_detached($signature, $unsigned, $publicKey)) {
        http_response_code(400);
        die("Invalid objectAnchor signature\n");
    }
    $records[$name] = ['value' => $value, 'days' => $days];
    $maxDays = max($maxDays, $days);
}

$count = count($records);
if ($count < 1 || $count > 250 || (int) $root->getAttribute('count') !== $count) {
    http_response_code(400);
    die("Batch count must be 1 through 250\n");
}

$slots = Container::createSlots();
if ((time() - $slots->lastSlotTime()) < 30) {
    http_response_code(429);
    die("Time restriction (30 sec)\n");
}

$batchName = 'batch:' . hash('sha256', $xml);
if (!empty($slots->findSlot($batchName))) {
    http_response_code(409);
    die("Batch payment slot already exists\n");
}

$slotId = $slots->createSlot($batchName, $xml, '', $maxDays, $count);
header('Location: /slot.php?slot=' . rawurlencode($slotId), true, 303);
