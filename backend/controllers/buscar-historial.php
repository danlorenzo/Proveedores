<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
apply_security_headers();
header('Content-Type: application/json; charset=utf-8');

function history_token(?string $token): ?array
{
    if (!$token) return null;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$head, $body, $signature] = $parts;
    $decode = static function (string $value): string|false {
        $pad = strlen($value) % 4;
        if ($pad) $value .= str_repeat('=', 4 - $pad);
        return base64_decode(strtr($value, '-_', '+/'), true);
    };
    $header = $decode($head);
    $payloadJson = $decode($body);
    $provided = $decode($signature);
    if ($header === false || $payloadJson === false || $provided === false) return null;
    $headerData = json_decode($header, true);
    $payload = json_decode($payloadJson, true);
    $expected = hash_hmac('sha256', $head . '.' . $body, JWT_SECRET, true);
    if (!is_array($headerData) || ($headerData['alg'] ?? '') !== 'HS256' || !is_array($payload)
        || !hash_equals($expected, $provided) || empty($payload['sub'])
        || empty($payload['exp']) || (int) $payload['exp'] <= time()) return null;
    return $payload;
}

$token = history_token($_COOKIE['access_token'] ?? null);
if ($token === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'results' => []]);
    exit;
}
$query = isset($_GET['q']) && is_scalar($_GET['q']) ? trim((string) $_GET['q']) : '';
$type = isset($_GET['tipo']) && is_scalar($_GET['tipo']) ? (string) $_GET['tipo'] : '';
if ($query === '' || mb_strlen($query, 'UTF-8') > 80 || !in_array($type, ['perfil', 'compras'], true)) {
    echo json_encode(['ok' => true, 'results' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = database();
    $needle = '%' . $query . '%';
    if ($type === 'perfil') {
        $sql = 'SELECT p.id_proveedor AS id, p.nombre_empresa AS label,
                       CONCAT("RTN ", p.rtn, " · ", c.nombre) AS detail
                  FROM proveedor p INNER JOIN categoria c ON c.id_categoria = p.id_categoria
                 WHERE p.dni_usuario_registro = :dni
                   AND (p.nombre_empresa LIKE :name OR p.rtn LIKE :rtn OR c.nombre LIKE :category)
                 ORDER BY p.fecha_registro DESC, p.nombre_empresa LIMIT 8';
        $statement = $pdo->prepare($sql);
        $statement->execute(['dni' => $token['sub'], 'name' => $needle, 'rtn' => $needle, 'category' => $needle]);
        $results = $statement->fetchAll();
        foreach ($results as &$result) $result['url'] = 'proveedor.php?id=' . (int) $result['id'];
        unset($result);
    } else {
        $providerId = isset($_GET['id']) && is_scalar($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : false;
        if (!$providerId || $providerId < 1) {
            echo json_encode(['ok' => true, 'results' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $sql = 'SELECT dc.id_documento AS id, dc.tipo_documento, dc.numero_documento,
                       eg.nombre AS empresa, DATE_FORMAT(dc.fecha_documento, "%d/%m/%Y") AS fecha,
                       (SELECT GROUP_CONCAT(dd.descripcion ORDER BY dd.id_detalle SEPARATOR ", ")
                          FROM detalle_documento dd WHERE dd.id_documento = dc.id_documento) AS productos
                  FROM documento_compra dc INNER JOIN empresa_grupo eg ON eg.id_empresa_grupo = dc.id_empresa_grupo
                 WHERE dc.id_proveedor = :provider_id
                   AND (dc.numero_documento LIKE :number OR dc.tipo_documento LIKE :type
                     OR eg.nombre LIKE :company OR DATE_FORMAT(dc.fecha_documento, "%d/%m/%Y") LIKE :date
                     OR EXISTS (SELECT 1 FROM detalle_documento dd2 WHERE dd2.id_documento = dc.id_documento AND dd2.descripcion LIKE :product))
                 ORDER BY dc.fecha_documento DESC, dc.id_documento DESC LIMIT 8';
        $statement = $pdo->prepare($sql);
        $statement->execute(['provider_id' => $providerId, 'number' => $needle, 'type' => $needle,
            'company' => $needle, 'date' => $needle, 'product' => $needle]);
        $results = [];
        foreach ($statement->fetchAll() as $row) {
            $results[] = [
                'id' => (int) $row['id'],
                'label' => trim(($row['tipo_documento'] ?? 'Documento') . ' ' . ($row['numero_documento'] ?? '')),
                'detail' => trim(($row['empresa'] ?? '') . ' · ' . ($row['fecha'] ?? '') . (!empty($row['productos']) ? ' · ' . $row['productos'] : '')),
            ];
        }
    }
    echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'results' => []]);
}
