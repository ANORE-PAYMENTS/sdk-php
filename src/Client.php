<?php

namespace Anore;

use Anore\Exception\ApiConnectionException;
use Anore\Exception\ApiException;
use Anore\Model\Balance;
use Anore\Model\Payment;
use Anore\Model\PaymentList;
use Anore\Model\Payout;
use Anore\Model\PayoutFees;
use Anore\Model\PayoutList;
use Anore\Model\PayoutRates;
use Anore\Model\SubscriptionBinding;
use Anore\Model\SubscriptionCatalog;
use Anore\Model\SubscriptionCharge;
use Anore\Model\SubscriptionStatus;

class Client
{
    const DEFAULT_BASE_URL = 'https://api.anore.cc/api/v1';
    const USER_AGENT = 'anore-php/1.3.0';

    private $apiKey;
    private $secret;
    private $baseUrl;
    private $maxRetries;
    private $timeout;

    public function __construct(string $apiKey, array $options = [])
    {
        if ($apiKey === '' || preg_match('/[\r\n]/', $apiKey)) {
            throw new \InvalidArgumentException('Anore\\Client: apiKey is required and must not contain line breaks');
        }
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('Anore\\Client: the cURL PHP extension is required');
        }
        $this->apiKey = $apiKey;
        $this->secret = $options['secret'] ?? null;
        if (!is_string($this->secret) && $this->secret !== null) {
            throw new \InvalidArgumentException('Anore\\Client: secret must be a string');
        }
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
            'orderId' => 'orderId', 'shopId' => 'shopId', 'currency' => 'currency', 'methods' => 'methods',
            'getbackUrl' => 'getbackurl', 'successUrl' => 'successurl', 'failUrl' => 'failurl',
            'callbackUrl' => 'callbackUrl', 'email' => 'email',
        ];
        foreach ($fields as $source => $target) {
            if (array_key_exists($source, $params) && $params[$source] !== null) {
                $body[$target] = $params[$source];
            }
        }
        if (array_key_exists('user_identity', $params) && $params['user_identity'] !== null) {
            $body['user_identity'] = self::userIdentityBody($params['user_identity']);
        }
        if (array_key_exists('franchise', $params) && $params['franchise'] !== null) {
            $body['franchise'] = self::franchiseBody($params['franchise']);
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

    public function getSubscriptionCatalog(): SubscriptionCatalog
    {
        return new SubscriptionCatalog($this->request('GET', '/subscriptions/catalog', null));
    }

    public function createSubscription(array $params): SubscriptionBinding
    {
        self::rejectUnknownFields($params, ['planId', 'customerId', 'customerEmail', 'idempotencyKey', 'shopId'], 'createSubscription');
        foreach (['planId', 'customerId', 'customerEmail'] as $key) {
            if (!isset($params[$key]) || !is_string($params[$key]) || $params[$key] === '') {
                throw new \InvalidArgumentException('createSubscription: ' . $key . ' is required');
            }
        }
        $key = $params['idempotencyKey'] ?? null;
        self::validateIdempotencyKey($key, 'createSubscription');
        $body = [
            'planId' => self::boundedString($params['planId'], 80, 'createSubscription: planId'),
            'customerId' => self::boundedString($params['customerId'], 128, 'createSubscription: customerId'),
            'customerEmail' => self::email($params['customerEmail'], 'createSubscription: customerEmail'),
        ];
        if (array_key_exists('shopId', $params) && $params['shopId'] !== null) {
            if (!is_int($params['shopId']) || $params['shopId'] <= 0) {
                throw new \InvalidArgumentException('createSubscription: shopId must be a positive integer');
            }
            $body['shopId'] = $params['shopId'];
        }
        return new SubscriptionBinding($this->request(
            'POST', '/subscriptions', $body, ['Idempotency-Key: ' . $key]
        ));
    }

    public function getSubscription(int $id): SubscriptionStatus
    {
        self::validatePositiveInt($id, 'getSubscription');
        return new SubscriptionStatus($this->request('GET', '/subscriptions/' . $id, null));
    }

    public function chargeSubscription(int $id, string $idempotencyKey): SubscriptionCharge
    {
        self::validatePositiveInt($id, 'chargeSubscription');
        self::validateIdempotencyKey($idempotencyKey, 'chargeSubscription');
        return new SubscriptionCharge($this->request(
            'POST', '/subscriptions/' . $id . '/charges', null, ['Idempotency-Key: ' . $idempotencyKey]
        ));
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

    public function listPayouts(array $params = []): PayoutList
    {
        $limit = $params['limit'] ?? 50;
        if (!is_int($limit) || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('listPayouts: limit must be an integer between 1 and 100');
        }
        self::rejectUnknownFields($params, ['limit', 'shopId', 'cursor'], 'listPayouts');
        $query = ['limit' => $limit];
        foreach (['shopId', 'cursor'] as $key) {
            if (array_key_exists($key, $params) && $params[$key] !== null && $params[$key] !== '') {
                $query[$key] = $params[$key];
            }
        }
        if (isset($query['shopId']) && (!is_int($query['shopId']) || $query['shopId'] <= 0)) {
            throw new \InvalidArgumentException('listPayouts: shopId must be a positive integer');
        }
        if (isset($query['cursor']) && (!is_string($query['cursor']) || preg_match('/[\r\n]/', $query['cursor']))) {
            throw new \InvalidArgumentException('listPayouts: cursor must be a string without line breaks');
        }
        return new PayoutList($this->request('GET', '/payouts?' . http_build_query($query), null));
    }

    public function createPayout(array $params): Payout
    {
        foreach (array_keys($params) as $key) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('createPayout contains an invalid field name');
            }
        }
        $amount = $params['amount'] ?? null;
        $method = $params['method'] ?? '';
        $address = $params['address'] ?? '';
        if (!(is_int($amount) || is_float($amount)) || !is_finite((float) $amount)
            || $amount < 1 || floor((float) $amount) !== (float) $amount) {
            throw new \InvalidArgumentException('createPayout: amount must be a positive integral number');
        }
        if (!is_string($method) || trim($method) === '' || self::unicodeLength($method) > 190
            || preg_match('/[\p{Cc}\p{Cf}]/u', $method)) {
            throw new \InvalidArgumentException('createPayout: method must be a nonempty safe string');
        }
        if (!is_string($address) || trim($address) === '' || self::unicodeLength($address) > 190) {
            throw new \InvalidArgumentException('createPayout: address must be a string of 1 to 190 characters');
        }
        $body = $params;
        $body['amount'] = $amount;
        $body['method'] = $method;
        $body['address'] = $address;
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
        if (preg_match('/[\x00-\x20\x7f]/', $baseUrl)) {
            throw new \InvalidArgumentException('Anore\\Client: baseUrl must not contain whitespace or control characters');
        }
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

    private static function boundedString(string $value, int $maxLength, string $label): string
    {
        if (trim($value) === '' || self::unicodeLength($value) > $maxLength
            || preg_match('/[\p{Cc}\p{Cf}]/u', $value)) {
            throw new \InvalidArgumentException($label . ' must be a nonempty string of at most ' . $maxLength . ' characters');
        }
        return $value;
    }

    private static function unicodeLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }
        if (preg_match_all('/./us', $value, $matches) === false) {
            throw new \InvalidArgumentException('value must be valid UTF-8');
        }
        return count($matches[0]);
    }

    private static function normalizeNfkc(string $value, string $label): string
    {
        $normalized = \Normalizer::normalize($value, \Normalizer::FORM_KC);
        if (!is_string($normalized)) {
            throw new \InvalidArgumentException($label . ' must be valid Unicode');
        }
        return $normalized;
    }

    private static function email(string $value, string $label): string
    {
        $value = trim($value);
        if (strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException($label . ' must be a valid email address of at most 254 characters');
        }
        return $value;
    }

    private static function validatePositiveInt(int $value, string $operation): void
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException($operation . ': id must be a positive integer');
        }
    }

    private static function validateIdempotencyKey($value, string $operation): void
    {
        if (!is_string($value) || trim($value) === '' || self::unicodeLength($value) > 128
            || preg_match('/[\r\n]/', $value)) {
            throw new \InvalidArgumentException(
                $operation . ': idempotencyKey must be a nonempty string of at most 128 characters without line breaks'
            );
        }
    }

    private static function rejectUnknownFields(array $value, array $allowed, string $label): void
    {
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException($label . ' contains unsupported fields');
            }
        }
    }

    private static function exactObject($value, array $allowed, string $label): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException($label . ' must be an object');
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException($label . ' contains unsupported fields');
            }
        }
        return $value;
    }

    private static function telegramId($value, string $label)
    {
        if (is_int($value)) {
            if ($value <= 0) {
                throw new \InvalidArgumentException($label . ' must be a positive signed-64 integer');
            }
            return $value;
        }
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,18}$/D', $value)
            || strlen($value) === 19 && strcmp($value, '9223372036854775807') > 0) {
            throw new \InvalidArgumentException($label . ' must be a canonical positive signed-64 decimal string');
        }
        return $value;
    }

    private static function telegramUsername($value, string $label): string
    {
        if (!is_string($value) || !preg_match('/^@?[A-Za-z0-9_]{5,32}$/D', $value)) {
            throw new \InvalidArgumentException($label . ' must be a Telegram username');
        }
        return strtolower(ltrim($value, '@'));
    }

    private static function userIdentityBody($value): array
    {
        $label = 'createPayment: user_identity';
        $value = self::exactObject($value, ['type', 'value', 'telegram_username'], $label);
        $type = $value['type'] ?? null;
        if (!in_array($type, ['telegram_id', 'email', 'username', 'external_id'], true)) {
            throw new \InvalidArgumentException($label . ' has an unsupported type');
        }
        $identity = $value['value'] ?? null;
        if ($type === 'telegram_id') {
            $identity = self::telegramId($identity, $label . '.value');
        } elseif ($type === 'email') {
            $identity = strtolower(self::email($identity, $label . '.value'));
        } elseif (!is_string($identity)) {
            throw new \InvalidArgumentException($label . '.value must be a string');
        } else {
            $identity = trim(self::normalizeNfkc($identity, $label . '.value'));
            if ($identity === '' || self::unicodeLength($identity) > 190
                || preg_match('/[\p{Cc}\p{Cf}]/u', $identity)) {
                throw new \InvalidArgumentException($label . '.value must be a nonempty string of at most 190 characters without control characters');
            }
            if ($type === 'username') {
                $identity = mb_strtolower($identity, 'UTF-8');
            }
        }
        $body = ['type' => $type, 'value' => $identity];
        if (array_key_exists('telegram_username', $value)) {
            if ($type !== 'telegram_id') {
                throw new \InvalidArgumentException($label . '.telegram_username is allowed only with telegram_id');
            }
            $body['telegram_username'] = self::telegramUsername($value['telegram_username'], $label . '.telegram_username');
        }
        return $body;
    }

    private static function franchiseBody($value): array
    {
        $label = 'createPayment: franchise';
        $value = self::exactObject($value, ['type', 'address', 'owner_telegram_id', 'owner_telegram_username'], $label);
        $type = $value['type'] ?? null;
        if (!in_array($type, ['telegram_bot', 'website'], true)) {
            throw new \InvalidArgumentException($label . ' has an unsupported type');
        }
        $address = $value['address'] ?? null;
        if (!is_string($address) || trim($address) === '') {
            throw new \InvalidArgumentException($label . '.address is required');
        }
        if ($type === 'telegram_bot') {
            $address = self::telegramUsername($address, $label . '.address');
        } else {
            $address = self::validateHttpsOrigin($address, $label . '.address');
        }
        $body = [
            'type' => $type,
            'address' => $address,
            'owner_telegram_id' => self::telegramId($value['owner_telegram_id'] ?? null, $label . '.owner_telegram_id'),
        ];
        if (array_key_exists('owner_telegram_username', $value)) {
            $body['owner_telegram_username'] = self::telegramUsername(
                $value['owner_telegram_username'], $label . '.owner_telegram_username'
            );
        }
        return $body;
    }

    private static function validateHttpsOrigin(string $value, string $label): string
    {
        if (preg_match('/[\x00-\x20\x7f]/', $value) || strpos($value, '%') !== false) {
            throw new \InvalidArgumentException($label . ' must be a canonical HTTPS origin');
        }
        $parts = parse_url($value);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new \InvalidArgumentException($label . ' must be a canonical HTTPS origin');
        }
        $host = rtrim(strtolower($parts['host']), '.');
        if ($host[0] !== '[' || substr($host, -1) !== ']') {
            $flags = defined('IDNA_NONTRANSITIONAL_TO_ASCII') ? IDNA_NONTRANSITIONAL_TO_ASCII : 0;
            $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 1;
            $ascii = idn_to_ascii($host, $flags, $variant, $info);
            if (!is_string($ascii) || $ascii === '' || !empty($info['errors'])) {
                throw new \InvalidArgumentException($label . ' has an invalid host');
            }
            $host = strtolower($ascii);
        }
        if (!self::validOriginHost($host)) {
            throw new \InvalidArgumentException($label . ' has an invalid host');
        }
        return 'https://' . $host;
    }

    private static function validOriginHost(string $host): bool
    {
        $candidate = $host;
        if ($candidate[0] === '[' && substr($candidate, -1) === ']') {
            $candidate = substr($candidate, 1, -1);
        }
        if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (strlen($host) > 253 || strpos($host, '..') !== false) {
            return false;
        }
        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63
                || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label)) {
                return false;
            }
        }
        return true;
    }

    private function shopPath(string $path, ?int $shopId): string
    {
        if ($shopId !== null && $shopId <= 0) {
            throw new \InvalidArgumentException('shopId must be a positive integer');
        }
        return $shopId === null ? $path : $path . '?' . http_build_query(['shopId' => $shopId]);
    }

    private function request(string $method, string $path, ?array $body, array $extraHeaders = []): array
    {
        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                throw new \InvalidArgumentException('could not encode request body: ' . json_last_error_msg());
            }
        }
        $headers = array_merge([
            'Authorization: Bearer ' . $this->apiKey,
            'User-Agent: ' . self::USER_AGENT,
            'Accept: application/json',
        ], $extraHeaders);
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            if ($this->secret) {
                $headers[] = 'X-ZPay-Signature: ' . hash_hmac('sha256', $payload, $this->secret);
            }
        }

        $retries = $method === 'GET' ? $this->maxRetries : 0;
        $backoffMs = 500;
        for ($attempt = 0; ; $attempt++) {
            $ch = curl_init($this->baseUrl . $path);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
                CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => $this->timeout, CURLOPT_CONNECTTIMEOUT => 10,
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
            throw ApiException::forStatus($code, $message, $this->requestId($rawHeaders), $data);
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
        return preg_match('/^x-request-id:\s*(.+)$/im', $rawHeaders, $m) ? trim($m[1]) : null;
    }
}
