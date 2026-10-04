<?php

// Фейковый API для tests/run.php. Первый сегмент пути — режим ответа.
$log = getenv('ANORE_TEST_LOG');
$raw = file_get_contents('php://input');
$headers = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
file_put_contents($log, json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $_SERVER['REQUEST_URI'],
    'signature' => $headers['x-zpay-signature'] ?? null,
    'authorization' => $headers['authorization'] ?? null,
    'raw' => $raw,
]) . "\n", FILE_APPEND);

$mode = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'))[0];
$calls = count(file($log));
header('Content-Type: application/json');
header('X-Request-Id: fixture-request');

switch ($mode) {
    case 'e503':
        http_response_code(503);
        echo '{"message":"busy"}';
        break;
    case 'flaky':
        if ($calls % 2 === 1) {
            http_response_code(503);
            echo '{"message":"busy"}';
        } else {
            echo '{"id":"ok"}';
        }
        break;
    case 'e400':
        http_response_code(400);
        echo '{"message":"amount must be > 0"}';
        break;
    case 'notjson':
        echo '<html>oops</html>';
        break;
    default:
        echo json_encode(['success' => true, 'id' => 'WD-42', 'methodCode' => 'sbp', 'method' => 'СБП',
            'statusRevision' => 2, 'manualCorrection' => true, 'paymentUrl' => 'https://pay.anore.cc/x']);
}
