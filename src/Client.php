<?php

namespace Anore;

use Anore\Exception\ApiConnectionException;
use Anore\Exception\ApiException;
use Anore\Model\Balance;
use Anore\Model\Payment;
use Anore\Model\PaymentList;
use Anore\Model\Payout;
use Anore\Model\PayoutFees;
use Anore\Model\PayoutRates;

class Client
{
    const DEFAULT_BASE_URL = 'https://api.anore.cc/api/v1';
    const USER_AGENT = 'anore-php/1.2.0';

    private $apiKey;

    private $secret;

    private $baseUrl;

    private $maxRetries;

    private $timeout;

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
        $this->baseUrl = self::normalizeBaseUrl($options['baseUrl'] ?? self::DEFAULT_BASE_URL);
        $this->maxRetries = $options['maxRetries'] ?? 2;
        $this->timeout = $options['timeout'] ?? 30;
        if (!is_int($this->maxRetries) || $this->maxRetries < 0) {
            throw new \InvalidArgumentException('Anore\\Client: maxRetries must be a nonnegative integer');
        }
        if (!is_int($this->timeout) || $this->timeout <= 0) {
            throw new \InvalidArgumentException('Anore\\Client: timeout must be a positive number of seconds');
        }
    }

    public function createPayment(array $params): Payment
    {
        $amount = $params['amount'] ?? null;
        $description = $params['description'] ?? '';
        self::validateAmount($amount, 'createPayment');
        if ($description === '') {
            throw new \InvalidArgumentException('createPayment: description is required');
        }
        $body = ['amount' => $amount, 'description' => $description];
        $fields = [
            'orderId' => 'orderId',
            'shopId' => 'shopId',
            'currency' => 'currency',
            'methods' => 'methods',
            'getbackUrl' => 'getbackurl',
            'successUrl' => 'successurl',
            'failUrl' => 'failurl',
            'callbackUrl' => 'callbackUrl',
            'email' => 'email',
        ];
        foreach ($fields as $source => $target) {
            if (isset($params[$source])) {
                $body[$target] = $params[$source];
            }
        }
        return new Payment($this->request('POST', '/payments', $body));
    }

    public function getPayment(string $id): Payment
    {
        if ($id === '') {
            throw new \InvalidArgumentException('getPayment: id is required');
        }
        return new Payment($this->request('GET', '/payments/' . rawurlencode($id), null));
    }

    public function listPayments(array $params = []): PaymentList
    {
        $query = ['limit' => $params['limit'] ?? 50, 'offset' => $params['offset'] ?? 0];
        foreach (['shopId', 'status', 'from', 'to'] as $key) {
            if (array_key_exists($key, $params) && $params[$key] !== null && $params[$key] !== '') {
                $query[$key] = $params[$key];
            }
        }
        return new PaymentList($this->request('GET', '/payments?' . http_build_query($query), null));
    }

    public function getBalance(?int $shopId = null): Balance
    {
        return new Balance($this->request('GET', $this->shopPath('/balance', $shopId), null));
    }

    public function getPayoutFees(?int $shopId = null): PayoutFees
    {
        return new PayoutFees($this->request('GET', $this->shopPath('/payouts/fees', $shopId), null));
    }

    public function getPayoutRates(?int $shopId = null): PayoutRates
    {
        return new PayoutRates($this->request('GET', $this->shopPath('/payouts/rates', $shopId), null));
    }

    public function createPayout(array $params): Payout
    {
        $amount = $params['amount'] ?? null;
        $method = $params['method'] ?? '';
        $address = $params['address'] ?? '';
        self::validateAmount($amount, 'createPayout');
        if ($method === '') {
            throw new \InvalidArgumentException('createPayout: method is required');
        }
        if ($address === '') {
            throw new \InvalidArgumentException('createPayout: address is required');
        }
        $body = ['amount' => $amount, 'method' => $method, 'address' => $address];
        foreach (['shopId', 'bank', 'externalId'] as $key) {
            if (array_key_exists($key, $params) && $params[$key] !== null && $params[$key] !== '') {
                $body[$key] = $params[$key];
            }
        }
        return new Payout($this->request('POST', '/payouts', $body));
    }

    public function getPayout(string $id): Payout
    {
        if ($id === '') {
            throw new \InvalidArgumentException('getPayout: id is required');
        }
        return new Payout($this->request('GET', '/payouts/' . rawurlencode($id), null));
    }

    private static function normalizeBaseUrl(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Anore\\Client: baseUrl must be an HTTP(S) API URL without credentials, query or fragment');
        }
        $path = rtrim($parts['path'] ?? '', '/');
        if ($path === '') {
            $path = '/api/v1';
        } elseif ($path === '/api') {
            $path .= '/v1';
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $parts['scheme'] . '://' . $parts['host'] . $port . $path;
    }

    private static function validateAmount($amount, string $operation): void
    {
        if (!(is_int($amount) || is_float($amount)) || !is_finite((float) $amount) || $amount <= 0) {
            throw new \InvalidArgumentException($operation . ': amount must be a finite number > 0');
        }
    }

    private function shopPath(string $path, ?int $shopId): string
    {
        return $shopId === null ? $path : $path . '?' . http_build_query(['shopId' => $shopId]);
    }

    private function request(string $method, string $path, ?array $body): array
    {
        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                throw new \InvalidArgumentException('could not encode request body: ' . json_last_error_msg());
            }
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'User-Agent: ' . self::USER_AGENT,
            'Accept: application/json',
        ];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            if ($this->secret) {
                $headers[] = 'X-ZPay-Signature: ' . hash_hmac('sha256', $payload, $this->secret);
            }
        }

        // POST не повторяем: после 5xx или обрыва неизвестно, создан ли платёж или выплата.
        $retries = $method === 'GET' ? $this->maxRetries : 0;
        $backoffMs = 500;
        for ($attempt = 0; ; $attempt++) {
            $ch = curl_init($this->baseUrl . $path);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }

            $raw = curl_exec($ch);
            if ($raw === false) {
                $error = curl_error($ch);
                curl_close($ch);
                if ($attempt < $retries) {
                    usleep($backoffMs * 1000);
                    $backoffMs = min($backoffMs * 2, 4000);
                    continue;
                }
                throw new ApiConnectionException('could not reach anore API: ' . $error);
            }

            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $rawHeaders = substr($raw, 0, $headerSize);
            $bodyStr = substr($raw, $headerSize);
            curl_close($ch);

            $data = self::decodeObject($bodyStr);
            if ($code >= 200 && $code < 300) {
                if ($data === null) {
                    throw new ApiException('API returned an invalid JSON object', $code, $this->requestId($rawHeaders));
                }
                return $data;
            }
            if ($code >= 500 && $attempt < $retries) {
                usleep($backoffMs * 1000);
                $backoffMs = min($backoffMs * 2, 4000);
                continue;
            }
            $data = $data ?? [];
            $message = $data['message'] ?? ($data['error'] ?? null);
            if (!is_string($message)) {
                $message = 'request failed';
            }
            throw ApiException::forStatus($code, $message, $this->requestId($rawHeaders));
        }
    }

    private static function decodeObject(string $json): ?array
    {
        $trimmed = ltrim($json);
        if ($trimmed === '' || $trimmed[0] !== '{') {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    private function requestId(string $rawHeaders): ?string
    {
        if (preg_match('/^x-request-id:\s*(.+)$/im', $rawHeaders, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
