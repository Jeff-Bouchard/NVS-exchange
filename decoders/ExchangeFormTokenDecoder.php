<?php

namespace decoders;

require_once __DIR__ . '/../lib/iDecoder.php';

use lib\iDecoder;

class ExchangeFormTokenDecoder implements iDecoder {

    public function encodeName(array $fields): string
    {
        return "worm:token:ness_exchange_v1_v2:$fields[address]:$fields[pay_address]";
    }

    public function encodeValue(array $fields): string
    {
        $address = htmlspecialchars($fields['address'], ENT_QUOTES | ENT_XML1, 'UTF-8');
        $payAddress = htmlspecialchars($fields['pay_address'], ENT_QUOTES | ENT_XML1, 'UTF-8');
        return <<<WORM
<worm>
    <token type="ness-exchange-v1-v2" address="$address" pay_address="$payAddress"/>
</worm> 
WORM;
    }

    public function decodeValue(string $value): array
    {
        $xmlString = preg_replace("/<!--.+?-->/i", '', $value);
        $xmlString = preg_replace('/”/i', '"', $xmlString);
        if (stripos($xmlString, '<!DOCTYPE') !== false || stripos($xmlString, '<!ENTITY') !== false) {
            throw new \InvalidArgumentException('Invalid exchange token');
        }
        $xmlObject = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NONET);
        if ($xmlObject === false || !isset($xmlObject->token)) { throw new \InvalidArgumentException('Invalid exchange token'); }

        $address = (string) $xmlObject->token['address'];
        $pay_address = (string) $xmlObject->token['pay_address'];

        return [
            "address" => $address,
            "pay_address" => $pay_address,
        ];
    }
}