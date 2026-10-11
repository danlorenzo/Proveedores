<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
apply_security_headers();
start_app_session();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}
$csrf = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}
$raw = $_COOKIE['access_token'] ?? '';
$parts = explode('.', $raw);
$decode = static function (string $value): string|false {
    $padding = strlen($value) % 4;
    if ($padding) $value .= str_repeat('=', 4 - $padding);
    return base64_decode(strtr($value, '-_', '+/'), true);
};
if (count($parts) !== 3) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}
[$headerPart, $payloadPart, $signaturePart] = $parts;
$headerJson = $decode($headerPart);
$payloadJson = $decode($payloadPart);
$signature = $decode($signaturePart);
$header = $headerJson === false ? null : json_decode($headerJson, true);
$payload = $payloadJson === false ? null : json_decode($payloadJson, true);
$expected = hash_hmac('sha256', $headerPart . '.' . $payloadPart, JWT_SECRET, true);
if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256' || !is_array($payload)
    || $signature === false || !hash_equals($expected, $signature)
    || empty($payload['sub']) || empty($payload['exp']) || (int) $payload['exp'] <= time()) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}
refresh_access_token_cookie($payload);
echo json_encode(['ok' => true]);
