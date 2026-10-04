<?php

// Запуск: php tests/run.php (нужны ext-curl и php -S).
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen('Anore\\'))) . '.php';
    if (strpos($class, 'Anore\\') === 0 && is_file($file)) {
        require $file;
    }
});

use Anore\Client;
use Anore\Exception\ServerException;
use Anore\Exception\SignatureException;
use Anore\Exception\ApiException;
use Anore\Exception\ValidationException;
use Anore\Webhooks;

$log = tempnam(sys_get_temp_dir(), 'anore');
$port = 18000 + random_int(0, 999);
putenv('ANORE_TEST_LOG=' . $log);
$devNull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/router.php'],
    [1 => ['file', $devNull, 'w'], 2 => ['file', $devNull, 'w']],
    $pipes
);
register_shutdown_function(function () use ($server, $log) {
    proc_terminate($server);
    @unlink($log);
});
$origin = 'http://127.0.0.1:' . $port;
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}

$failed = 0;
function check(string $name, callable $fn): void
{
    global $failed, $log;
    file_put_contents($log, '');
    try {
        $fn();
        echo "PASS $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL $name: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}
function same($expected, $actual, string $what = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($what . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function calls(): array
{
    global $log;
    return array_map(function ($line) { return json_decode($line, true); }, file($log, FILE_IGNORE_NEW_LINES));
}
function throws(string $class, callable $fn): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return;
        }
        throw new RuntimeException("expected $class, got " . get_class($e) . ': ' . $e->getMessage());
    }
    throw new RuntimeException("expected $class, nothing thrown");
}
function client(string $base, array $options = []): Client
{
    return new Client('fixture-key', $options + ['secret' => 'fixture-secret', 'baseUrl' => $base, 'maxRetries' => 1]);
}

check('createPayment sends current fields signed over exact bytes', function () use ($origin) {
    client($origin)->createPayment([
        'amount' => 12.34, 'description' => 'Оплата заказа', 'orderId' => '0', 'shopId' => 7, 'currency' => 'usd',
        'methods' => ['card', 'crypto-old'], 'getbackUrl' => 'https://m.example/back', 'successUrl' => 'https://m.example/ok',
        'failUrl' => 'https://m.example/fail', 'callbackUrl' => 'https://m.example/hook', 'email' => 'customer@example.test',
    ]);
    $call = calls()[0];
    same('/api/v1/payments', $call['path'], 'path');
    same('Bearer fixture-key', $call['authorization'], 'authorization');
    same([
        'amount' => 12.34, 'description' => 'Оплата заказа', 'orderId' => '0', 'shopId' => 7, 'currency' => 'usd',
        'methods' => ['card', 'crypto-old'], 'getbackurl' => 'https://m.example/back', 'successurl' => 'https://m.example/ok',
        'failurl' => 'https://m.example/fail', 'callbackUrl' => 'https://m.example/hook', 'email' => 'customer@example.test',
    ], json_decode($call['raw'], true), 'body');
    same(hash_hmac('sha256', $call['raw'], 'fixture-secret'), $call['signature'], 'signature');
});

check('base URLs do not duplicate paths', function () use ($origin) {
    same('https://api.anore.cc/api/v1', Client::DEFAULT_BASE_URL);
    foreach (['', '/', '/api', '/api/v1/', '/v1/', '/proxy/api/'] as $suffix) {
        (new Client('key', ['baseUrl' => $origin . $suffix]))->getPayment('a/b?c');
    }
    same(['/api/v1/payments/a%2Fb%3Fc', '/api/v1/payments/a%2Fb%3Fc', '/api/v1/payments/a%2Fb%3Fc',
        '/api/v1/payments/a%2Fb%3Fc', '/v1/payments/a%2Fb%3Fc', '/proxy/api/payments/a%2Fb%3Fc'],
        array_column(calls(), 'path'));
});

check('shop-scoped endpoints and payout model', function () use ($origin) {
    $client = client($origin);
    $client->listPayments(['shopId' => 7, 'status' => 'paid', 'from' => '2026-10-01', 'limit' => 20]);
    $client->getBalance(7);
    $client->getPayoutFees(7);
    $client->getPayoutRates(7);
    $payout = $client->getPayout('WD-42');
    same(['/api/v1/payments?limit=20&offset=0&shopId=7&status=paid&from=2026-10-01', '/api/v1/balance?shopId=7',
        '/api/v1/payouts/fees?shopId=7', '/api/v1/payouts/rates?shopId=7', '/api/v1/payouts/WD-42'], array_column(calls(), 'path'));
    same('sbp', $payout->method());
    same(2, $payout->statusRevision());
    same(true, $payout->manualCorrection());
});

check('createPayout sends all fields', function () use ($origin) {
    client($origin)->createPayout(['amount' => 5000, 'method' => 'usdt_erc20', 'address' => '0x123', 'shopId' => 7, 'externalId' => 'm-42']);
    same(['amount' => 5000, 'method' => 'usdt_erc20', 'address' => '0x123', 'shopId' => 7, 'externalId' => 'm-42'],
        json_decode(calls()[0]['raw'], true));
});

check('GET retries server errors', function () use ($origin) {
    same('ok', client($origin . '/flaky')->getPayment('x')->id());
    same(2, count(calls()));
});

check('POST never retries server errors', function () use ($origin) {
    $client = client($origin . '/e503', ['maxRetries' => 3]);
    throws(ServerException::class, function () use ($client) { $client->createPayment(['amount' => 1, 'description' => 't']); });
    throws(ServerException::class, function () use ($client) {
        $client->createPayout(['amount' => 5000, 'method' => 'card', 'address' => '4111111111111111']);
    });
    same(2, count(calls()));
});

check('API errors keep message, status and request id', function () use ($origin) {
    try {
        client($origin . '/e400')->getPayment('x');
        throw new RuntimeException('nothing thrown');
    } catch (ValidationException $e) {
        same('amount must be > 0', $e->getMessage());
        same(400, $e->getStatus());
        same('fixture-request', $e->getRequestId());
    }
});

check('non-JSON success is rejected', function () use ($origin) {
    throws(ApiException::class, function () use ($origin) { client($origin . '/notjson')->getPayment('x'); });
});

check('invalid input fails before networking', function () {
    $client = new Client('key', ['baseUrl' => 'http://127.0.0.1:9']);
    foreach ([0, -1, NAN, INF, true, '10', null] as $amount) {
        throws(InvalidArgumentException::class, function () use ($client, $amount) {
            $client->createPayment(['amount' => $amount, 'description' => 'x']);
        });
    }
    throws(InvalidArgumentException::class, function () { new Client('key', ['maxRetries' => -1]); });
    throws(InvalidArgumentException::class, function () { new Client('key', ['baseUrl' => 'https://u:p@api.anore.cc']); });
});

check('webhooks verify raw body and parse payout events', function () {
    $raw = json_encode(['event' => 'payout.succeeded', 'id' => 'WD-1', 'externalId' => 'm-1', 'shopId' => 7,
        'status' => 'paid', 'txHash' => 'abc', 'manualCorrection' => false, 'statusRevision' => 0]);
    $event = Webhooks::parse($raw, strtoupper(hash_hmac('sha256', $raw, 'whsec')), 'whsec');
    same(true, $event->isPayout());
    same(true, $event->isSucceeded());
    same('m-1', $event->externalId());
    same(0, $event->statusRevision());
    same(false, Webhooks::verify($raw, hash_hmac('sha256', $raw, 'other'), 'whsec'));
    same(false, Webhooks::verify($raw, '', 'whsec'));
    throws(SignatureException::class, function () use ($raw) { Webhooks::parse($raw, str_repeat('0', 64), 'whsec'); });
    $list = '[1,2]';
    throws(UnexpectedValueException::class, function () use ($list) { Webhooks::parse($list, hash_hmac('sha256', $list, 'w'), 'w'); });
});

echo $failed === 0 ? "all PHP SDK checks passed\n" : "$failed PHP SDK checks failed\n";
exit($failed === 0 ? 0 : 1);
