<?php

namespace Anore;

use Anore\Exception\SignatureException;
use Anore\Model\WebhookEvent;

class Webhooks
{

    public static function verify(string $rawBody, string $signature, string $secret): bool
    {
        if ($rawBody === '' || $secret === '' || !preg_match('/^[0-9a-fA-F]{64}$/', trim($signature))) {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, strtolower(trim($signature)));
    }

    public static function parse(string $rawBody, string $signature, string $secret): WebhookEvent
    {
        if (!self::verify($rawBody, $signature, $secret)) {
            throw new SignatureException('webhook signature verification failed');
        }
        $data = json_decode($rawBody, true);
        if (!is_array($data) || ltrim($rawBody)[0] !== '{') {
            throw new \UnexpectedValueException('webhook payload must be a JSON object');
        }
        return new WebhookEvent($data);
    }
}
