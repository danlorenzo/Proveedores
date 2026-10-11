<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
apply_security_headers();
header('Content-Type: application/json; charset=utf-8');

function search_decode_token(?string $token): ?array
{
    if (!$token) return null;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$headerPart, $payloadPart, $signaturePart] = $parts;
    $decode = static function (string $value): string|false {
        $padding = strlen($value) % 4;
        if ($padding) $value .= str_repeat('=', 4 - $padding);
        return base64_decode(strtr($value, '-_', '+/'), true);
    };
    $headerJson = $decode($headerPart);
    $payloadJson = $decode($payloadPart);
    $signature = $decode($signaturePart);
    if ($headerJson === false || $payloadJson === false || $signature === false) return null;
    $header = json_decode($headerJson, true);
    $payload = json_decode($payloadJson, true);
    $expected = hash_hmac('sha256', $headerPart . '.' . $payloadPart, JWT_SECRET, true);
    if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256' || !is_array($payload)
        || !hash_equals($expected, $signature) || empty($payload['sub'])
        || empty($payload['exp']) || (int) $payload['exp'] <= time()) return null;
    return $payload;
}

$token = search_decode_token($_COOKIE['access_token'] ?? null);
if ($token === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'results' => []]);
    exit;
}
$query = isset($_GET['q']) && is_scalar($_GET['q']) ? trim((string) $_GET['q']) : '';
$category = isset($_GET['categoria']) && is_scalar($_GET['categoria']) ? filter_var($_GET['categoria'], FILTER_VALIDATE_INT) : null;
if ($query === '' || mb_strlen($query, 'UTF-8') > 80) {
    echo json_encode(['ok' => true, 'results' => []], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    $sql = 'SELECT v.id_proveedor, v.nombre_empresa, v.rtn, v.logo_url,
                   v.promedio_estrellas, v.total_calificaciones
              FROM vw_proveedor_resumen v';
    $parameters = ['name' => '%' . $query . '%', 'rtn' => '%' . $query . '%'];
    $conditions = ['(v.nombre_empresa LIKE :name OR v.rtn LIKE :rtn)'];
    if ($category !== null && $category > 0) {
        $conditions[] = 'EXISTS (SELECT 1 FROM proveedor p WHERE p.id_proveedor = v.id_proveedor AND p.id_categoria = :category)';
        $parameters['category'] = $category;
    }
    $sql .= ' WHERE ' . implode(' AND ', $conditions) . ' ORDER BY v.nombre_empresa LIMIT 8';
    $statement = database()->prepare($sql);
    $statement->execute($parameters);
    $results = $statement->fetchAll();
    foreach ($results as &$result) {
        $url = trim((string) ($result['logo_url'] ?? ''));
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $result['logo_url'] = ($url !== '' && !str_starts_with($url, '//') && !str_contains($url, "\\")
            && ($scheme === null || strtolower((string) $scheme) === 'https')) ? $url : '';
        $result['promedio_estrellas'] = $result['promedio_estrellas'] === null ? null : (float) $result['promedio_estrellas'];
        $result['total_calificaciones'] = (int) $result['total_calificaciones'];
    }
    unset($result);
    echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'results' => []]);
}
