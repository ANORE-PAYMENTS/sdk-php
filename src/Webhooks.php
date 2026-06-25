<?php

namespace Anore;

use Anore\Exception\SignatureException;
use Anore\Model\WebhookEvent;

/** Webhook signature verification and payload parsing. */
class Webhooks
{
    /**
     * Verify an incoming webhook signature.
     *
     * @param string $rawBody   the RAW request body (never the re-encoded JSON)
     * @param string $signature the Anore-Signature header value
     * @param string $secret    signing secret from the dashboard (Webhooks tab)
     */
    public static function verify(string $rawBody, string $signature, string $secret): bool
    {
        if ($rawBody === '' || $signature === '' || $secret === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, strtolower(trim($signature)));
    }

    /**
     * Verify the signature and return the parsed event.
     *
     * @throws SignatureException if the signature does not match
     */
    public static function parse(string $rawBody, string $signature, string $secret): WebhookEvent
    {
        if (!self::verify($rawBody, $signature, $secret)) {
            throw new SignatureException('webhook signature verification failed');
        }
        $data = json_decode($rawBody, true);
        return new WebhookEvent(is_array($data) ? $data : []);
    }
}
