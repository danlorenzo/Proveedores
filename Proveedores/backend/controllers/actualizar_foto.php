<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
apply_security_headers();
start_app_session();
header('Content-Type: application/json; charset=utf-8');

function photo_json_error(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function photo_decode_token(?string $token): ?array
{
    if (!$token) return null;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$headerPart, $payloadPart, $signaturePart] = $parts;
    $decode = static function (string $value): string|false {
        $padding = strlen($value) % 4;
        if ($padding !== 0) $value .= str_repeat('=', 4 - $padding);
        return base64_decode(strtr($value, '-_', '+/'), true);
    };
    $headerJson = $decode($headerPart);
    $payloadJson = $decode($payloadPart);
    $signature = $decode($signaturePart);
    if ($headerJson === false || $payloadJson === false || $signature === false) return null;
    $headerData = json_decode($headerJson, true);
    $payload = json_decode($payloadJson, true);
    if (!is_array($headerData) || ($headerData['alg'] ?? '') !== 'HS256' || !is_array($payload)) return null;
    $expected = hash_hmac('sha256', $headerPart . '.' . $payloadPart, JWT_SECRET, true);
    if (!hash_equals($expected, $signature) || !isset($payload['sub'], $payload['exp']) || (int) $payload['exp'] <= time()) return null;
    return $payload;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') photo_json_error(405, 'Método no permitido.');
$csrf = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) photo_json_error(403, 'La sesión venció. Recarga el perfil e inténtalo de nuevo.');
$token = photo_decode_token($_COOKIE['access_token'] ?? null);
if ($token === null) photo_json_error(401, 'La sesión venció. Inicia sesión nuevamente.');
refresh_access_token_cookie($token);
if (!isset($_FILES['foto'])) photo_json_error(400, 'Selecciona una imagen válida.');
$uploadError = (int) ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE);
if (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) photo_json_error(413, 'La imagen supera el límite de carga configurado en XAMPP.');
if ($uploadError !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($_FILES['foto']['tmp_name'] ?? ''))) photo_json_error(400, 'No se pudo recibir la imagen. Inténtalo nuevamente.');
if ((int) $_FILES['foto']['size'] > 5 * 1024 * 1024) photo_json_error(413, 'La imagen supera el máximo de 5 MB.');
if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) photo_json_error(500, 'El servidor XAMPP necesita habilitar la extensión GD para guardar imágenes.');
$raw = file_get_contents($_FILES['foto']['tmp_name']);
$imageInfo = $raw === false ? false : @getimagesizefromstring($raw);
if ($imageInfo === false || !in_array($imageInfo['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp', 'image/avif'], true)) photo_json_error(415, 'Formato inválido. Usa JPG, PNG, WebP, GIF, BMP o AVIF.');
if ((int) $imageInfo[0] > 6000 || (int) $imageInfo[1] > 6000 || (int) $imageInfo[0] < 64 || (int) $imageInfo[1] < 64) {
    photo_json_error(413, 'La imagen debe medir entre 64 y 6000 píxeles por lado.');
}
$image = @imagecreatefromstring($raw);
if ($image === false) photo_json_error(415, 'XAMPP no pudo procesar este formato de imagen.');
$directory = dirname(__DIR__, 2) . '/uploads/perfiles';
if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
    imagedestroy($image);
    photo_json_error(500, 'No se pudo preparar el almacenamiento de fotos.');
}
if (!is_writable($directory)) {
    imagedestroy($image);
    photo_json_error(500, 'Apache de XAMPP no tiene permiso para escribir en uploads/perfiles. Revisa los permisos de esa carpeta.');
}
$filename = bin2hex(random_bytes(20)) . '.jpg';
$target = $directory . '/' . $filename;
if (!@imagejpeg($image, $target, 88)) {
    imagedestroy($image);
    photo_json_error(500, 'No se pudo guardar la imagen en uploads/perfiles. Revisa los permisos de escritura de XAMPP.');
}
imagedestroy($image);
$relativePath = 'uploads/perfiles/' . $filename;
try {
    $pdo = database();
    $update = $pdo->prepare('UPDATE usuario SET foto_perfil_url = :photo WHERE dni = :dni');
    $update->execute(['photo' => $relativePath, 'dni' => $token['sub']]);
    if ($update->rowCount() < 1) {
        $check = $pdo->prepare('SELECT 1 FROM usuario WHERE dni = :dni');
        $check->execute(['dni' => $token['sub']]);
        if (!$check->fetchColumn()) {
            @unlink($target);
            photo_json_error(404, 'No se encontró el perfil del usuario.');
        }
    }
} catch (Throwable $exception) {
    @unlink($target);
    error_log($exception->getMessage());
    photo_json_error(500, 'No se pudo actualizar la foto de perfil.');
}
echo json_encode(['ok' => true, 'photoUrl' => '../' . $relativePath], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
