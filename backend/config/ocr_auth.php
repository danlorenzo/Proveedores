<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function ocr_read_token(?string $token): ?array
{
    if (!$token) return null;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$head, $body, $signature] = $parts;
    $decode = static function (string $value): string|false {
        $padding = strlen($value) % 4;
        if ($padding) $value .= str_repeat('=', 4 - $padding);
        return base64_decode(strtr($value, '-_', '+/'), true);
    };
    $headerJson = $decode($head);
    $payloadJson = $decode($body);
    $provided = $decode($signature);
    if ($headerJson === false || $payloadJson === false || $provided === false) return null;
    $header = json_decode($headerJson, true);
    $payload = json_decode($payloadJson, true);
    $expected = hash_hmac('sha256', $head . '.' . $body, JWT_SECRET, true);
    if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256' || !is_array($payload)
        || !hash_equals($expected, $provided) || empty($payload['sub'])
        || empty($payload['exp']) || (int) $payload['exp'] <= time()) return null;
    return $payload;
}

function ocr_require_user(): array
{
    $payload = ocr_read_token($_COOKIE['access_token'] ?? null);
    if ($payload === null) {
        header('Location: login.php');
        exit;
    }
    return $payload;
}

function ocr_require_post_csrf(): void
{
    $provided = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $provided)) {
        http_response_code(419);
        exit('El formulario venció. Recarga la página e inténtalo otra vez.');
    }
}
