<?php
declare(strict_types=1);

// Configuración local para XAMPP. Cambia estos valores según tu entorno.
const DB_HOST = '127.0.0.1';
const DB_NAME = 'biblioteca_proveedores';
const DB_USER = 'root';
const DB_PASSWORD = '';

// Solo para desarrollo local: reemplaza por una clave secreta aleatoria antes
// de publicar el sistema. En producción, carga esta clave desde el entorno.
const JWT_SECRET = 'cambia-esta-clave-local-por-una-secreta-de-64-caracteres';
const JWT_TTL_SECONDS = 28800;

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.gc_maxlifetime', '28800');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function refresh_access_token_cookie(array $payload): void
{
    if (empty($payload['sub'])) return;
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $token = create_jwt((string) $payload['sub'], (string) ($payload['name'] ?? 'Usuario'));
    setcookie('access_token', $token, [
        'expires' => time() + JWT_TTL_SECONDS,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function apply_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
    header('Cache-Control: no-store, private');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data: blob: https:; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; worker-src 'self' blob: https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; connect-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com");
}

function database(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function create_jwt(string $dni, string $nombre): string
{
    $now = time();
    $header = ['typ' => 'JWT', 'alg' => 'HS256'];
    $payload = [
        'sub' => $dni,
        'name' => $nombre,
        'iat' => $now,
        'exp' => $now + JWT_TTL_SECONDS,
    ];

    $unsigned = base64url_encode(json_encode($header, JSON_THROW_ON_ERROR)) . '.'
        . base64url_encode(json_encode($payload, JSON_THROW_ON_ERROR));
    $signature = hash_hmac('sha256', $unsigned, JWT_SECRET, true);

    return $unsigned . '.' . base64url_encode($signature);
}
