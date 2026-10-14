<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/ocr_auth.php';
apply_security_headers();
start_app_session();
$token = ocr_read_token($_COOKIE['access_token'] ?? null);
if (!$token) { header('Location: ../../frontend/login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../frontend/index.php');
    exit;
}
ocr_require_post_csrf();

$providerId = filter_input(INPUT_POST, 'id_proveedor', FILTER_VALIDATE_INT) ?: 0;
$documentId = filter_input(INPUT_POST, 'id_documento', FILTER_VALIDATE_INT) ?: 0;
$stars = filter_input(INPUT_POST, 'estrellas', FILTER_VALIDATE_INT) ?: 0;
$comment = isset($_POST['comentario']) && is_string($_POST['comentario']) ? trim($_POST['comentario']) : '';
$comment = mb_substr(strip_tags($comment), 0, 255, 'UTF-8');
if ($providerId < 1 || $documentId < 1 || $stars < 1 || $stars > 5) {
    $_SESSION['documento_flash'] = 'No se pudo guardar la calificación. Revisa los datos e inténtalo de nuevo.';
    header('Location: ../../frontend/proveedor.php?id=' . max(1, $providerId));
    exit;
}

try {
    $pdo = database();
    $ownedPurchase = $pdo->prepare('SELECT 1 FROM documento_compra WHERE id_documento = :doc AND id_proveedor = :provider AND dni_usuario = :dni LIMIT 1');
    $ownedPurchase->execute(['doc' => $documentId, 'provider' => $providerId, 'dni' => $token['sub']]);
    if (!$ownedPurchase->fetchColumn()) throw new RuntimeException('Compra no autorizada para calificar.');
    $save = $pdo->prepare(
        'INSERT INTO calificacion (id_proveedor, dni_usuario, estrellas, comentario)
         VALUES (:provider, :dni, :stars, :comment)
         ON DUPLICATE KEY UPDATE estrellas = VALUES(estrellas), comentario = VALUES(comentario), fecha = CURRENT_TIMESTAMP'
    );
    $save->execute(['provider' => $providerId, 'dni' => $token['sub'], 'stars' => $stars, 'comment' => $comment !== '' ? $comment : null]);
    $_SESSION['documento_flash'] = 'Gracias. Tu calificación quedó guardada.';
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $_SESSION['documento_flash'] = 'No se pudo guardar la calificación.';
}
header('Location: ../../frontend/proveedor.php?id=' . $providerId);
exit;
