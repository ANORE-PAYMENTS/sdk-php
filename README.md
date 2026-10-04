# anore — PHP SDK

Официальный SDK для приёма платежей через [anore](https://anore.cc). Без зависимостей, только расширения `curl` и `json` из ядра PHP. PHP 7.4+.

## Структура

```
php/
├── composer.json
└── src/
    ├── Client.php             платежи, баланс и выплаты (cURL + ретраи)
    ├── Webhooks.php           verify / parse
    ├── Model/
    │   ├── Model.php          база моделей
    │   ├── Payment.php        модель платежа
    │   └── WebhookEvent.php   модель события вебхука
    └── Exception/             иерархия ошибок
```

## Установка

### Composer

```bash
composer require anore/sdk
```

```php
require 'vendor/autoload.php';
use Anore\Client;
use Anore\Webhooks;
```

Без Composer — подключите PSR-4 автозагрузчик на папку `src/` с префиксом `Anore\`.

## Быстрый старт

```php
$anore = new Anore\Client('an_live_xxxxxxxxxxxxxxxx');

// 1. создать счёт
$payment = $anore->createPayment([
    'amount'      => 1500,
    'description' => 'Подписка Pro',
    'orderId'     => 'order_42',
    'shopId'      => 1, // обязателен для аккаунтовых ключей (an_*)
    'callbackUrl' => 'https://shop.example/anore/webhook', // необязательно: вебхук для этого заказа
    'email'       => 'buyer@example.com',                  // необязательно: чек покупателю
]);
echo $payment->paymentUrl(); // отправьте клиента сюда

// 2. проверить статус
$status = $anore->getPayment($payment->id());
echo $status->status(); // paid

$page = $anore->listPayments(['shopId' => 1, 'status' => 'paid', 'limit' => 20]);
$balance = $anore->getBalance(1);

$payout = $anore->createPayout([
    'amount' => 5000,
    'method' => 'usdt_ton',
    'address' => 'UQ...',
    'shopId' => 1,
    'externalId' => 'payout_42',
]);
echo $payout->id();
```

## Проверка вебхука

При оплате anore шлёт `POST` на ваш URL с заголовком `Anore-Signature`.
Проверяйте подпись по **сырому** телу запроса (не декодированному JSON):

Тот же обработчик принимает `payout.created`, `payout.processing`, `payout.succeeded`, `payout.failed` и `payout.updated` (ручная корректировка статуса, см. `statusRevision`); используйте `$event->isPayout()`.

```php
use Anore\Webhooks;
use Anore\Exception\SignatureException;

$raw = file_get_contents('php://input'); // сырое тело
$sig = $_SERVER['HTTP_ANORE_SIGNATURE'] ?? '';

try {
    $event = Webhooks::parse($raw, $sig, getenv('ANORE_WEBHOOK_SECRET'));
    if ($event->isSucceeded()) {
        // отгрузить заказ $event->id() / $event->orderId()
    }
    http_response_code(200);
} catch (SignatureException $e) {
    http_response_code(403); // подпись не сошлась
}
```

## Обработка ошибок

```php
use Anore\Exception\ValidationException;
use Anore\Exception\AuthenticationException;
use Anore\Exception\ApiConnectionException;

try {
    $anore->createPayment(['amount' => 1500, 'description' => 'Заказ', 'shopId' => 1]);
} catch (ValidationException $e) {       // 400 — кривой запрос
} catch (AuthenticationException $e) {   // 401 — неверный ключ
} catch (ApiConnectionException $e) {    // сеть недоступна
}
```

## Справка

| API | Описание |
|-----|----------|
| `new Anore\Client($apiKey, $options)` | клиент; `$options`: `secret`, `baseUrl`, `maxRetries`, `timeout` |
| `createPayment([...])` | создать счёт → `Payment` |
| `getPayment($id)` | статус → `Payment` (`->status()`, `->paid()`) |
| `listPayments([...])` | страница платежей → `PaymentList` |
| `getBalance($shopId)` | баланс → `Balance` |
| `getPayoutFees($shopId)` | комиссии → `PayoutFees` |
| `getPayoutRates($shopId)` | курсы → `PayoutRates` |
| `createPayout([...])` | заявка → `Payout` |
| `getPayout($id)` | статус выплаты → `Payout` |
| `Anore\Webhooks::verify($rawBody, $signature, $secret)` | проверка подписи → `bool` |
| `Anore\Webhooks::parse($rawBody, $signature, $secret)` | проверка + разбор → `WebhookEvent` (бросает `SignatureException`) |

Ошибки: `ValidationException` (400), `AuthenticationException` (401), `ForbiddenException` (403), `NotFoundException` (404), `ServerException` (5xx), `ApiConnectionException` (сеть), `SignatureException` (подпись). База — `AnoreException`, у `ApiException` есть `getStatus()` и `getRequestId()`.

Полная документация: https://anore.cc/docs
