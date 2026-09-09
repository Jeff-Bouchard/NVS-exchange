<?php
require_once __DIR__ . '/../decoders/ExchangeFormDecoder.php';
require_once __DIR__ . '/../decoders/ExchangeFormTokenDecoder.php';
require_once __DIR__ . '/../lib/Sqlite.php';
require_once __DIR__ . '/../lib/Container.php';
$count = 0;
function expect($condition, string $message): void {
    global $count;
    if (!$condition) { throw new RuntimeException($message); }
    $count++; echo "PASS $message\n";
}
$descriptor = new decoders\ExchangeFormDecoder();
$fields = ['title' => 'NESS & NCH "exchange"', 'url' => 'https://exchange.example/api?one=1&two=2'];
expect($descriptor->decodeValue($descriptor->encodeValue($fields)) == $fields, 'exchange descriptor round trip and XML escaping');
expect($descriptor->decodeValue('<worm><token title="Legacy" url="https://exchange.example/"/></worm>')['title'] === 'Legacy', 'historical descriptor element supported');
$decoder = new decoders\ExchangeFormTokenDecoder();
$fields = ['address' => 'A&B"C', 'pay_address' => 'DEF'];
expect($decoder->decodeValue($decoder->encodeValue($fields)) === $fields, 'exchange token XML attributes safely round trip');
try { $descriptor->decodeValue('<!DOCTYPE worm [<!ENTITY t SYSTEM "file:///etc/passwd">]><worm/>'); expect(false, 'DTD rejected'); }
catch (InvalidArgumentException $error) { expect(true, 'DTD rejected'); }
expect(class_exists('wallets\\Ness') && class_exists('wallets\\NCH'), 'generated-address wallet classes load');
$file = tempnam(sys_get_temp_dir(), 'exchange-test-');
try {
    $db = new lib\Sqlite($file);
    expect($db->createSlot('test:name', 'hello', '{}', '', str_repeat('a', 32)), 'slot created with absolute SQLite path');
    $slot = $db->getSlot(str_repeat('a', 32));
    expect($slot['name'] === 'test:name' && $slot['status'] === 'GENERATED', 'slot reads back with existing schema');
    $db->setSlotPayed($slot['slot_id']);
    expect($db->getSlot($slot['slot_id'])['status'] === 'PAYED', 'legacy payment status persists');
} finally { unset($db); unlink($file); }
echo "$count checks passed\n";
