<?php
require_once __DIR__ . '/../lib/Http.php';
use lib\Http;

function portalSlot(string $kind, string $id, $slots, $exchange): array {
    $slot = $kind === 'exchange' ? $exchange->showSlot($id) : $slots->showSlot($id);
    if (!$slot) { throw new InvalidArgumentException('Request not found.'); }
    $result = ['slot_id' => $id, 'name' => $slot['name'], 'status' => strtolower($slot['status']), 'payments' => $slot['addr']];
    foreach (['address', 'pay_address', 'gen_address', 'hours', 'recieve', 'error'] as $key) {
        if (isset($slot[$key])) { $result[$key] = $slot[$key]; }
    }
    return $result;
}

try {
    Http::session('privateness_exchange');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        Http::json(['csrf' => $_SESSION['csrf']]);
    }
    $input = Http::input();
    $action = Http::text($input, 'action', 16);
    $kind = Http::text($input, 'kind', 16);
    if (!in_array($kind, ['nvs', 'exchange'], true) || !in_array($action, ['create', 'status'], true)) {
        throw new InvalidArgumentException('Unknown action.');
    }
    require_once __DIR__ . '/../lib/Container.php';
    require_once __DIR__ . '/../modules/ExchangeForm.php';
    // All portal slot creation/payment checks serialize across PHP sessions.
    $lock = fopen(__DIR__ . '/../data/portal.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('Unable to lock exchange'); }
    $exchange = $kind === 'exchange' ? lib\Container::createExchangeForm() : null;
    $slots = $exchange ? $exchange->getSlot() : lib\Container::createSlots();
    if ($action === 'status') {
        $id = Http::text($input, 'slot_id', 64);
        if (!preg_match('/^[a-f0-9]{32,64}$/D', $id)) { throw new InvalidArgumentException('Invalid request reference.'); }
        if (!isset($_SESSION['portal_slots'][$kind][$id])) {
            Http::json(['error' => 'This request belongs to another session. Use its original status page.'], 403);
        }
        if (!empty($input['process'])) { $slots->processSlot($id); }
        Http::json(portalSlot($kind, $id, $slots, $exchange));
    }
    if ($kind === 'exchange') {
        $address = Http::text($input, 'address', 64);
        $payAddress = Http::text($input, 'pay_address', 64);
        foreach ([$address, $payAddress] as $value) {
            if (!preg_match('/^[1-9A-HJ-NP-Za-km-z]{20,64}$/D', $value)) { throw new InvalidArgumentException('Enter valid NESS addresses.'); }
        }
        $fields = ['address' => $address, 'pay_address' => $payAddress];
        $existing = $exchange->findSlot($fields);
        $fingerprint = hash('sha256', json_encode($fields));
    } else {
        $name = Http::text($input, 'name', 512);
        $value = Http::text($input, 'value', 10240);
        $owner = Http::text($input, 'owner', 64);
        $days = filter_var($input['days'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 100, 'max_range' => 3650]]);
        if ($name === '' || $value === '' || !$days || preg_match('/[\\x00-\\x1f\\x7f]/', $name)) { throw new InvalidArgumentException('Check the record name, value, and duration.'); }
        if ($owner !== '' && !preg_match('/^[1-9A-HJ-NP-Za-km-z]{20,64}$/D', $owner)) { throw new InvalidArgumentException('Enter a valid EMC owner address.'); }
        $existing = $slots->findSlot($name);
        $fingerprint = hash('sha256', json_encode([$name, $value, $owner, $days]));
    }
    if (isset($_SESSION['portal_requests'][$fingerprint])) {
        Http::json(portalSlot($kind, $_SESSION['portal_requests'][$fingerprint], $slots, $exchange));
    }
    if ($existing) { throw new InvalidArgumentException('A request already exists for this record. Use its original status page.'); }
    if (time() - $slots->lastSlotTime() < 30) {
        header('Retry-After: 30');
        Http::json(['error' => 'Please wait 30 seconds between new exchange requests.'], 429);
    }
    if ($kind === 'exchange') {
        if (!$exchange->pingExchangeForm()) { throw new RuntimeException('Exchange is unavailable'); }
        if ($exchange->locateSlot($fields)) { throw new InvalidArgumentException('This exchange request is already registered.'); }
        $id = $exchange->createSlot($fields);
    } else {
        if ($slots->locateSlot($name)) { throw new InvalidArgumentException('This record is already registered. Use the existing record editor.'); }
        $id = $slots->createSlot($name, $value, $owner, $days);
    }
    $_SESSION['portal_requests'][$fingerprint] = $id;
    $_SESSION['portal_slots'][$kind][$id] = true;
    Http::json(portalSlot($kind, $id, $slots, $exchange), 201);
} catch (InvalidArgumentException $error) {
    Http::json(['error' => $error->getMessage()], 400);
} catch (Throwable $error) {
    Http::json(['error' => 'The exchange is unavailable or awaiting a network response. Retry the same request to recover it.'], 503);
}
