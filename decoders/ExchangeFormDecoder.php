<?php

namespace decoders;

require_once __DIR__ . '/../lib/iDecoder.php';

use lib\iDecoder;

class ExchangeFormDecoder implements iDecoder {

    public function encodeName(array $fields): string
    {
        return "worm:exchange:ness_exchange_v1_v2";
    }

    public function encodeValue(array $fields): string
    {
        $title = htmlspecialchars($fields['title'], ENT_QUOTES | ENT_XML1, 'UTF-8');
        $url = htmlspecialchars($fields['url'], ENT_QUOTES | ENT_XML1, 'UTF-8');
        return <<<WORM
<worm>
    <exchange type="ness-exchange-v1-v2" title="$title" url="$url"/>
</worm> 
WORM;
    }

    public function decodeValue(string $value): array
    {
        $xmlString = preg_replace("/<!--.+?-->/i", '', $value);
        $xmlString = preg_replace('/”/i', '"', $xmlString);
        if (stripos($xmlString, '<!DOCTYPE') !== false || stripos($xmlString, '<!ENTITY') !== false) {
            throw new \InvalidArgumentException('Invalid exchange descriptor');
        }
        $xmlObject = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NONET);
        if ($xmlObject === false) { throw new \InvalidArgumentException('Invalid exchange descriptor'); }
        // Accept the historical encoder's token element as well as exchange.
        $entry = isset($xmlObject->exchange) ? $xmlObject->exchange : $xmlObject->token;

        $title = (string) $entry['title'];
        $url = (string) $entry['url'];

        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)) {
            throw new \InvalidArgumentException('Invalid exchange service URL');
        }
        return [
            "url" => $url,
            "title" => $title,
        ];
    }
}