<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
apply_security_headers();

if (session_status() !== PHP_SESSION_ACTIVE) {
    start_app_session();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || empty($_SESSION['csrf_token'])
    || !isset($_POST['csrf_token'])
    || !is_string($_POST['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403);
    exit('La solicitud de cierre de sesión no es válida.');
}
$_SESSION = [];
session_destroy();

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
setcookie('access_token', '', [
    'expires' => time() - 3600,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

header('Location: ../../frontend/login.php?sesion=cerrada');
exit;
