<?php

namespace Anore;

use Anore\Exception\ApiConnectionException;
use Anore\Exception\ApiException;
use Anore\Model\Payment;

/**
 * Client for the anore payments API. Zero dependencies — uses the cURL extension.
 *
 *   $anore = new \Anore\Client('an_live_xxxxxxxxxxxxxxxx');
 *
 *   $payment = $anore->createPayment([
 *       'amount'      => 1500,
 *       'description' => 'Подписка Pro',
 *       'orderId'     => 'order_42',
 *       'shopId'      => 1, // обязателен для аккаунтовых ключей (an_*)
 *   ]);
 *   echo $payment->paymentUrl();
 *
 *   $status = $anore->getPayment($payment->id());
 *   echo $status->status() . ' ' . ($status->paid() ? 'true' : 'false');
 */
class Client
{
    const DEFAULT_BASE_URL = 'https://api.anore.cc/v1';
    const USER_AGENT = 'anore-php/1.0.0';

    /** @var string */
    private $apiKey;
    /** @var string|null */
    private $secret;
    /** @var string */
    private $baseUrl;
    /** @var int */
    private $maxRetries;
    /** @var int seconds */
    private $timeout;

    /**
     * @param string $apiKey  Key from the dashboard (an_live_… / an_test_…).
     * @param array  $options secret, baseUrl, maxRetries, timeout
     */
    public function __construct(string $apiKey, array $options = [])
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('Anore\\Client: apiKey is required');
        }
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('Anore\\Client: the cURL PHP extension is required');
        }
        $this->apiKey = $apiKey;
        $this->secret = $options['secret'] ?? null;
        $this->baseUrl = rtrim($options['baseUrl'] ?? self::DEFAULT_BASE_URL, '/');
        $this->maxRetries = $options['maxRetries'] ?? 2;
        $this->timeout = $options['timeout'] ?? 30;
    }

    /**
     * Create a payment / invoice (POST /payments).
     *
     * @param array $params amount (float, >0, required), description (string, required),
     *                      orderId (string), shopId (int — required for account-level keys)
     */
    public function createPayment(array $params): Payment
    {
        $amount = $params['amount'] ?? 0;
        $description = $params['description'] ?? '';
        if (!($amount > 0)) {
            throw new \InvalidArgumentException('createPayment: amount must be > 0');
        }
        if ($description === '') {
            throw new \InvalidArgumentException('createPayment: description is required');
        }
        $body = ['amount' => $amount, 'description' => $description];
        if (!empty($params['orderId'])) {
            $body['orderId'] = $params['orderId'];
        }
        if (!empty($params['shopId'])) {
            $body['shopId'] = $params['shopId'];
        }
        return new Payment($this->request('POST', '/payments', $body));
    }

    /** Fetch payment status (GET /payments/{id}). status() is "new" | "paid" | "expired". */
    public function getPayment(string $id): Payment
    {
        if ($id === '') {
            throw new \InvalidArgumentException('getPayment: id is required');
        }
        return new Payment($this->request('GET', '/payments/' . rawurlencode($id), null));
    }

    private function request(string $method, string $path, ?array $body): array
    {
        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE);

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'User-Agent: ' . self::USER_AGENT,
            'Accept: application/json',
        ];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            if ($this->secret) {
                $headers[] = 'Anore-Signature: ' . hash_hmac('sha256', $payload, $this->secret);
            }
        }

        $backoffMs = 500;
        $lastErr = null;
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            $ch = curl_init($this->baseUrl . $path);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }

            $raw = curl_exec($ch);
            if ($raw === false) {
                $lastErr = new ApiConnectionException('could not reach anore API: ' . curl_error($ch));
                curl_close($ch);
                if ($attempt < $this->maxRetries) {
                    usleep($backoffMs * 1000);
                    $backoffMs = min($backoffMs * 2, 4000);
                    continue;
                }
                throw $lastErr;
            }

            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $rawHeaders = substr($raw, 0, $headerSize);
            $bodyStr = substr($raw, $headerSize);
            curl_close($ch);

            $data = $bodyStr === '' ? [] : (json_decode($bodyStr, true) ?: []);

            if ($code >= 200 && $code < 300) {
                return is_array($data) ? $data : [];
            }
            if ($code >= 500 && $attempt < $this->maxRetries) {
                usleep($backoffMs * 1000);
                $backoffMs = min($backoffMs * 2, 4000);
                continue;
            }
            $message = $data['message'] ?? ($data['error'] ?? 'request failed');
            throw ApiException::forStatus($code, $message, $this->requestId($rawHeaders));
        }

        throw $lastErr ?? new ApiConnectionException('could not reach anore API');
    }

    private function requestId(string $rawHeaders): ?string
    {
        if (preg_match('/^x-request-id:\s*(.+)$/im', $rawHeaders, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
