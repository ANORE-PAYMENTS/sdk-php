# ANORE — PHP SDK

Официальный SDK ANORE для платежей, подписок и выплат. Нулевые runtime-зависимости; требуются PHP 7.4+, `ext-curl` и `ext-json`.

## Установка

```bash
composer require anore/sdk
```

Версию релиза определяет Git-тег; поле `version` намеренно отсутствует в `composer.json`.

## Платежи

```php
require 'vendor/autoload.php';

$anore = new Anore\Client('an_live_xxxxxxxxxxxxxxxx');

$payment = $anore->createPayment([
    'amount' => 1500,
    'description' => 'Подписка Pro',
    'orderId' => 'order_42',
    'shopId' => 1,
    'callbackUrl' => 'https://shop.example/anore/webhook',
    'email' => 'buyer@example.com',
    'user_identity' => [
        'type' => 'telegram_id',
        'value' => '9223372036854775807',
        'telegram_username' => '@buyer_name',
    ],
]);

echo $payment->paymentUrl();
$status = $anore->getPayment($payment->id());
$page = $anore->listPayments(['shopId' => 1, 'status' => 'paid', 'limit' => 20]);
```

`user_identity` поддерживается только при создании ANORE-платежа. Объект имеет строгий whitelist:

- `type`: `telegram_id`, `email`, `username` или `external_id`;
- `value`: для `telegram_id` — положительный canonical signed-int64 (`1`…`9223372036854775807`), для остальных типов — непустая строка до 190 байт;
- необязательный `telegram_username` разрешён только для `telegram_id` и должен соответствовать `@?[A-Za-z0-9_]{5,32}`.

Если у кассы включён franchise mode, передавайте филиал в каждом платеже:

```php
$payment = $anore->createPayment([
    'amount' => 1500,
    'description' => 'Заказ',
    'user_identity' => ['type' => 'email', 'value' => 'buyer@example.com'],
    'franchise' => [
        'type' => 'website',
        'address' => 'https://branch.example',
        'owner_telegram_id' => 123456789,
        'owner_telegram_username' => '@owner_name',
    ],
]);
```

`franchise` также имеет строгий whitelist: `type` (`telegram_bot` или `website`), `address`, `owner_telegram_id` и необязательный `owner_telegram_username`. Адрес бота — Telegram username; адрес сайта — canonical HTTPS origin без credentials, порта, path, query или fragment. Объекты валидируются до сетевого запроса. Они принадлежат только ANORE: SDK не добавляет их в response-модели, webhook callbacks или логи и не передаёт их Payso.

## Подписки

```php
$catalog = $anore->getSubscriptionCatalog();

$binding = $anore->createSubscription([
    'planId' => 'pro-monthly',
    'customerId' => 'customer-42',
    'customerEmail' => 'buyer@example.com',
    'shopId' => 1,
    'idempotencyKey' => 'binding-customer-42',
]);

echo $binding->qrUrl();
$status = $anore->getSubscription($binding->subscriptionId());
$charge = $anore->chargeSubscription($binding->subscriptionId(), 'charge-2026-11-customer-42');
```

Ключ `Idempotency-Key` всегда задаёт вызывающий код (непустая строка до 128 байт). SDK никогда автоматически не повторяет POST. При `503 binding_reconciling` сохраните тот же ключ и идентичное тело, не создавайте новый ключ и не запускайте параллельную привязку. Структурированное тело ошибки доступно через `$e->getResponseBody()`, включая `subscriptionId`, если API его вернул.

## Выплаты

```php
$balance = $anore->getBalance(1);
$fees = $anore->getPayoutFees(1);
$rates = $anore->getPayoutRates(1);
$page = $anore->listPayouts(['shopId' => 1, 'limit' => 50, 'cursor' => $cursor]);

$payout = $anore->createPayout([
    'amount' => 5000,
    'method' => 'usdt_ton',
    'address' => 'UQ...',
    'shopId' => 1,
    'externalId' => 'payout_42',
]);
$status = $anore->getPayout($payout->id());
```

## Webhooks

Проверяйте `Anore-Signature` по сырому телу запроса:

```php
use Anore\Webhooks;
use Anore\Exception\SignatureException;

$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_ANORE_SIGNATURE'] ?? '';

try {
    $event = Webhooks::parse($raw, $sig, getenv('ANORE_WEBHOOK_SECRET'));
    if ($event->isSucceeded()) {
        // Отгрузить заказ ровно один раз.
    }
    http_response_code(200);
} catch (SignatureException $e) {
    http_response_code(403);
}
```

## API

| Метод | Результат |
|---|---|
| `createPayment([...])` | `Payment` |
| `getPayment($id)` | `Payment` |
| `listPayments([...])` | `PaymentList` |
| `getSubscriptionCatalog()` | `SubscriptionCatalog` |
| `createSubscription([...])` | `SubscriptionBinding` |
| `getSubscription($id)` | `SubscriptionStatus` |
| `chargeSubscription($id, $idempotencyKey)` | `SubscriptionCharge` |
| `getBalance($shopId)` | `Balance` |
| `getPayoutFees($shopId)` | `PayoutFees` |
| `getPayoutRates($shopId)` | `PayoutRates` |
| `listPayouts([...])` | `PayoutList` |
| `createPayout([...])` | `Payout` |
| `getPayout($id)` | `Payout` |
| `Webhooks::verify(...)` | `bool` |
| `Webhooks::parse(...)` | `WebhookEvent` |

Ошибки API наследуют `Anore\Exception\ApiException` и предоставляют `getStatus()`, `getRequestId()` и `getResponseBody()`. GET повторяются на сетевых ошибках/5xx согласно `maxRetries`; POST остаются one-shot.

Полная документация: https://anore.cc/docs
