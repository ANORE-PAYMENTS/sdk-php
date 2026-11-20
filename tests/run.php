<?php

// Запуск: composer install && php tests/run.php (нужны ext-curl и php -S).
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
} else {
    throw new RuntimeException('Run composer install before the test suite');
}

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
    same('anore-php/1.3.0', $call['user_agent'], 'user agent');
    same([
        'amount' => 12.34, 'description' => 'Оплата заказа', 'orderId' => '0', 'shopId' => 7, 'currency' => 'usd',
        'methods' => ['card', 'crypto-old'], 'getbackurl' => 'https://m.example/back', 'successurl' => 'https://m.example/ok',
        'failurl' => 'https://m.example/fail', 'callbackUrl' => 'https://m.example/hook', 'email' => 'customer@example.test',
    ], json_decode($call['raw'], true), 'body');
    same(hash_hmac('sha256', $call['raw'], 'fixture-secret'), $call['signature'], 'signature');
});

check('client options reject invalid values before networking', function () use ($origin) {
    foreach (["key\r\nX-Injected: yes", ''] as $key) {
        throws(InvalidArgumentException::class, function () use ($key, $origin) {
            new Client($key, ['baseUrl' => $origin]);
        });
    }
    throws(InvalidArgumentException::class, function () use ($origin) {
        new Client('key', ['baseUrl' => $origin, 'secret' => ['not-a-string']]);
    });
    same([], calls());
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

check('createPayout preserves integral floats, aliases and extensions', function () use ($origin) {
    client($origin)->createPayout([
        'amount' => 5000.0,
        'method' => 'future_chain',
        'address' => str_repeat('я', 100),
        'metadata' => ['tenant' => 'branch-7'],
    ]);
    same([
        'amount' => 5000,
        'method' => 'future_chain',
        'address' => str_repeat('я', 100),
        'metadata' => ['tenant' => 'branch-7'],
    ], json_decode(calls()[0]['raw'], true));
    throws(InvalidArgumentException::class, function () use ($origin) {
        client($origin)->createPayout(['amount' => 1.5, 'method' => 'card', 'address' => '4111111111111111']);
    });
});

check('payment context uses exact snake_case whitelists', function () use ($origin) {
    client($origin)->createPayment([
        'amount' => 10,
        'description' => 'Context',
        'user_identity' => [
            'type' => 'telegram_id',
            'value' => '9223372036854775807',
            'telegram_username' => '@buyer_name',
        ],
        'franchise' => [
            'type' => 'website',
            'address' => 'https://branch.example/',
            'owner_telegram_id' => 123456789,
            'owner_telegram_username' => 'owner_name',
        ],
    ]);
    $body = json_decode(calls()[0]['raw'], true);
    same([
        'type' => 'telegram_id',
        'value' => '9223372036854775807',
        'telegram_username' => 'buyer_name',
    ], $body['user_identity']);
    same([
        'type' => 'website',
        'address' => 'https://branch.example',
        'owner_telegram_id' => 123456789,
        'owner_telegram_username' => 'owner_name',
    ], $body['franchise']);
});

check('Unicode contracts count and normalize characters through polyfills', function () use ($origin) {
    $client = client($origin);
    $client->createSubscription([
        'planId' => str_repeat('п', 80),
        'customerId' => str_repeat('к', 128),
        'customerEmail' => 'buyer@example.com',
        'idempotencyKey' => str_repeat('и', 128),
    ]);
    $client->createPayment([
        'amount' => 1,
        'description' => 'x',
        'user_identity' => ['type' => 'external_id', 'value' => "éＡＢＣ"],
        'franchise' => ['type' => 'website', 'address' => 'https://пример.рф/', 'owner_telegram_id' => 1],
    ]);
    $body = json_decode(calls()[1]['raw'], true);
    same('éABC', $body['user_identity']['value']);
    same('https://xn--e1afmkfd.xn--p1ai', $body['franchise']['address']);
    $client->createPayment([
        'amount' => 1,
        'description' => 'x',
        'user_identity' => ['type' => 'username', 'value' => 'ПОЛЬЗОВАТЕЛЬ'],
    ]);
    same('пользователь', json_decode(calls()[2]['raw'], true)['user_identity']['value']);
    foreach (['https://127.0.0.1', 'https://[2001:db8::1]'] as $address) {
        $client->createPayment([
            'amount' => 1,
            'description' => 'x',
            'franchise' => ['type' => 'website', 'address' => $address, 'owner_telegram_id' => 1],
        ]);
    }
    same('https://127.0.0.1', json_decode(calls()[3]['raw'], true)['franchise']['address']);
    same('https://[2001:db8::1]', json_decode(calls()[4]['raw'], true)['franchise']['address']);
});

check('invalid payment context fails before networking', function () use ($origin) {
    $client = client($origin);
    $invalidIdentities = [
        ['type' => 'telegram_id', 'value' => '01'],
        ['type' => 'telegram_id', 'value' => '9223372036854775808'],
        ['type' => 'email', 'value' => 'not-an-email'],
        ['type' => 'email', 'value' => 'x', 'telegram_username' => '@buyer_name'],
        ['type' => 'username', 'value' => "user\x00name"],
        ['type' => 'email', 'value' => 'x', 'extra' => true],
    ];
    foreach ($invalidIdentities as $value) {
        throws(InvalidArgumentException::class, function () use ($client, $value) {
            $client->createPayment(['amount' => 1, 'description' => 'x', 'user_identity' => $value]);
        });
    }
    $invalidFranchises = [
        ['type' => 'website', 'address' => 'http://branch.example', 'owner_telegram_id' => 1],
        ['type' => 'website', 'address' => 'https://branch.example/path', 'owner_telegram_id' => 1],
        ['type' => 'website', 'address' => 'https://branch.example:443', 'owner_telegram_id' => 1],
        ['type' => 'website', 'address' => 'https://-bad.com', 'owner_telegram_id' => 1],
        ['type' => 'website', 'address' => 'https://a..com', 'owner_telegram_id' => 1],
        ['type' => 'website', 'address' => 'https://foo_bar.com', 'owner_telegram_id' => 1],
        ['type' => 'website', 'address' => 'https://foo%2ebar.com', 'owner_telegram_id' => 1],
        ['type' => 'website', 'address' => "https://good.example\n.evil", 'owner_telegram_id' => 1],
        ['type' => 'telegram_bot', 'address' => '@x', 'owner_telegram_id' => 1],
        ['type' => 'telegram_bot', 'address' => '@valid_bot', 'owner_telegram_id' => 0],
        ['type' => 'telegram_bot', 'address' => '@valid_bot', 'owner_telegram_id' => 1, 'secret' => 'no'],
    ];
    foreach ($invalidFranchises as $value) {
        throws(InvalidArgumentException::class, function () use ($client, $value) {
            $client->createPayment(['amount' => 1, 'description' => 'x', 'franchise' => $value]);
        });
    }
    same([], calls());
});

check('protected payment context is stripped from payment models only', function () {
    $payment = new Anore\Model\Payment([
        'id' => 'p-1',
        'user_identity' => ['type' => 'email', 'value' => 'secret@example.com'],
        'franchise' => ['type' => 'website', 'address' => 'https://secret.example'],
    ]);
    same(['id' => 'p-1'], $payment->raw());
    $payout = new Anore\Model\Payout(['id' => 'WD-1', 'metadata' => ['franchise' => 'ordinary-value']]);
    same('ordinary-value', $payout->raw()['metadata']['franchise']);
});

check('subscriptions preserve caller idempotency and safe models', function () use ($origin) {
    $client = client($origin);
    $catalog = $client->getSubscriptionCatalog();
    $binding = $client->createSubscription([
        'planId' => 'plan-pro', 'customerId' => 'customer-42', 'customerEmail' => 'buyer@example.com',
        'shopId' => 7, 'idempotencyKey' => 'binding-key',
    ]);
    $status = $client->getSubscription(42);
    $charge = $client->chargeSubscription(42, 'charge-key');
    same(['/api/v1/subscriptions/catalog', '/api/v1/subscriptions', '/api/v1/subscriptions/42',
        '/api/v1/subscriptions/42/charges'], array_column(calls(), 'path'));
    same('binding-key', calls()[1]['idempotency_key']);
    same('charge-key', calls()[3]['idempotency_key']);
    same(['planId' => 'plan-pro', 'customerId' => 'customer-42', 'customerEmail' => 'buyer@example.com', 'shopId' => 7],
        json_decode(calls()[1]['raw'], true));
    same('', calls()[3]['raw']);
    same(null, $charge->chargeId());
    same('WD-42', $binding->raw()['id']);
    same([], $catalog->shops());
    same(null, $status->paymentMethod());
});

check('subscription idempotency keys fail before networking', function () use ($origin) {
    $client = client($origin);
    foreach (['', '   ', "line\nbreak", str_repeat('x', 129), null, 42] as $key) {
        throws(InvalidArgumentException::class, function () use ($client, $key) {
            $client->createSubscription([
                'planId' => 'p', 'customerId' => 'c', 'customerEmail' => 'e@example.com', 'idempotencyKey' => $key,
            ]);
        });
        if (is_string($key)) {
            throws(InvalidArgumentException::class, function () use ($client, $key) {
                $client->chargeSubscription(42, $key);
            });
        }
    }
    $client->chargeSubscription(42, str_repeat('с', 128));
    throws(InvalidArgumentException::class, function () use ($client) {
        $client->chargeSubscription(42, str_repeat('с', 129));
    });
    same(1, count(calls()));
});

check('subscription request validation rejects values outside the public contract', function () use ($origin) {
    $client = client($origin);
    foreach ([
        ['planId' => str_repeat('p', 81), 'customerId' => 'c', 'customerEmail' => 'e@example.com'],
        ['planId' => 'p', 'customerId' => str_repeat('c', 129), 'customerEmail' => 'e@example.com'],
        ['planId' => 'p', 'customerId' => 'c', 'customerEmail' => 'not-an-email'],
        ['planId' => 'p', 'customerId' => 'c', 'customerEmail' => 'e@example.com', 'shopId' => 0],
    ] as $params) {
        $params['idempotencyKey'] = 'key';
        throws(InvalidArgumentException::class, function () use ($client, $params) {
            $client->createSubscription($params);
        });
    }
    same([], calls());
});

check('list payouts uses cursor pagination', function () use ($origin) {
    $page = client($origin)->listPayouts(['shopId' => 7, 'limit' => 25, 'cursor' => 'opaque']);
    same('/api/v1/payouts?limit=25&shopId=7&cursor=opaque', calls()[0]['path']);
    same([], $page->payouts());
    foreach ([['limit' => 101], ['shopId' => 0], ['cursor' => [1]], ['unknown' => true]] as $params) {
        throws(InvalidArgumentException::class, function () use ($origin, $params) {
            client($origin)->listPayouts($params);
        });
    }
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
    throws(ServerException::class, function () use ($client) {
        $client->createSubscription([
            'planId' => 'p', 'customerId' => 'c', 'customerEmail' => 'e@example.com', 'idempotencyKey' => 'same-key',
        ]);
    });
    throws(ServerException::class, function () use ($client) { $client->chargeSubscription(42, 'same-key'); });
    same(4, count(calls()));
    try {
        $client->createSubscription([
            'planId' => 'p', 'customerId' => 'c', 'customerEmail' => 'e@example.com', 'idempotencyKey' => 'details-key',
        ]);
    } catch (ServerException $e) {
        same('binding_reconciling', $e->getResponseBody()['code']);
        same(42, $e->getResponseBody()['subscriptionId']);
    }
    same(5, count(calls()));
});

check('API errors keep message, status and request id', function () use ($origin) {
    try {
        client($origin . '/e400')->getPayment('x');
        throw new RuntimeException('nothing thrown');
    } catch (ValidationException $e) {
        same('amount must be > 0', $e->getMessage());
        same(400, $e->getStatus());
        same('fixture-request', $e->getRequestId());
        same('invalid_amount', $e->getResponseBody()['code']);
        same(42, $e->getResponseBody()['subscriptionId']);
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
