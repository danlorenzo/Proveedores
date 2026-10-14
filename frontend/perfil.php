<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/config/config.php';
apply_security_headers();
start_app_session();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function perfil_base64url_decode(string $value): string|false
{
    $padding = strlen($value) % 4;
    if ($padding !== 0) $value .= str_repeat('=', 4 - $padding);
    return base64_decode(strtr($value, '-_', '+/'), true);
}

function perfil_read_token(?string $token): ?array
{
    if (!$token) return null;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$headerPart, $payloadPart, $signaturePart] = $parts;
    $headerJson = perfil_base64url_decode($headerPart);
    $payloadJson = perfil_base64url_decode($payloadPart);
    $signature = perfil_base64url_decode($signaturePart);
    if ($headerJson === false || $payloadJson === false || $signature === false) return null;
    $header = json_decode($headerJson, true);
    $payload = json_decode($payloadJson, true);
    if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256' || !is_array($payload)) return null;
    $expected = hash_hmac('sha256', $headerPart . '.' . $payloadPart, JWT_SECRET, true);
    if (!hash_equals($expected, $signature) || !isset($payload['sub'], $payload['exp']) || (int) $payload['exp'] <= time()) return null;
    return $payload;
}

function perfil_escape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function perfil_media_url(mixed $value): string
{
    $url = trim((string) ($value ?? ''));
    if ($url === '' || str_starts_with($url, '//') || str_contains($url, "\\")) return '';
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if ($scheme !== null && strtolower((string) $scheme) !== 'https') return '';
    return $scheme === null && !str_starts_with($url, '/') ? '../' . $url : $url;
}

function perfil_page_url(int $targetPage): string
{
    $parameters = ['page' => max(1, $targetPage)];
    if (isset($_GET['q']) && is_scalar($_GET['q']) && (string) $_GET['q'] !== '') {
        $parameters['q'] = (string) $_GET['q'];
    }
    return 'perfil.php?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
}

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$token = perfil_read_token($_COOKIE['access_token'] ?? null);
if ($token === null) {
    setcookie('access_token', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax']);
    header('Location: login.php');
    exit;
}
refresh_access_token_cookie($token);

$profile = null;
$providers = [];
$loadError = false;
$search = isset($_GET['q']) && is_scalar($_GET['q']) ? trim((string) $_GET['q']) : '';
$perPage = 10;
$page = isset($_GET['page']) && is_scalar($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$totalRecords = 0;
$totalPages = 1;
$providerFlash = $_SESSION['proveedor_flash'] ?? '';
unset($_SESSION['proveedor_flash']);
try {
    $pdo = database();
    $statement = $pdo->prepare(
        'SELECT u.dni, u.primer_nombre, u.segundo_nombre, u.primer_apellido,
                u.segundo_apellido, u.celular, u.correo, u.foto_perfil_url, u.fecha_registro,
                c.nombre AS cargo, d.nombre AS departamento, e.nombre AS empresa
           FROM usuario u
           INNER JOIN cargo c ON c.id_cargo = u.id_cargo
           INNER JOIN departamento d ON d.id_departamento = c.id_departamento
           INNER JOIN empresa_grupo e ON e.id_empresa_grupo = u.id_empresa_grupo
          WHERE u.dni = :dni LIMIT 1'
    );
    $statement->execute(['dni' => $token['sub']]);
    $profile = $statement->fetch();
    if (!$profile) {
        $loadError = true;
    } else {
        $sql = 'SELECT p.id_proveedor, p.nombre_empresa, p.rtn, p.logo_url,
                       p.fecha_registro, cat.nombre AS categoria,
                       v.promedio_estrellas, v.total_calificaciones
                  FROM proveedor p
                  INNER JOIN categoria cat ON cat.id_categoria = p.id_categoria
                  INNER JOIN vw_proveedor_resumen v ON v.id_proveedor = p.id_proveedor
                 WHERE p.dni_usuario_registro = :dni';
        $parameters = ['dni' => $profile['dni']];
        if ($search !== '') {
            $sql .= ' AND (p.nombre_empresa LIKE :nombre OR p.rtn LIKE :rtn OR cat.nombre LIKE :categoria)';
            $needle = '%' . $search . '%';
            $parameters['nombre'] = $needle;
            $parameters['rtn'] = $needle;
            $parameters['categoria'] = $needle;
        }
        $countSql = 'SELECT COUNT(*) FROM proveedor p INNER JOIN categoria cat ON cat.id_categoria = p.id_categoria WHERE p.dni_usuario_registro = :dni';
        $countParameters = ['dni' => $profile['dni']];
        if ($search !== '') {
            $countSql .= ' AND (p.nombre_empresa LIKE :nombre OR p.rtn LIKE :rtn OR cat.nombre LIKE :categoria)';
            $needle = '%' . $search . '%';
            $countParameters['nombre'] = $needle;
            $countParameters['rtn'] = $needle;
            $countParameters['categoria'] = $needle;
        }
        $countStatement = $pdo->prepare($countSql);
        $countStatement->execute($countParameters);
        $totalRecords = (int) $countStatement->fetchColumn();
        $totalPages = max(1, (int) ceil($totalRecords / $perPage));
        $page = min($page, $totalPages);
        $sql .= ' ORDER BY p.fecha_registro DESC, p.nombre_empresa LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage);
        $providerStatement = $pdo->prepare($sql);
        $providerStatement->execute($parameters);
        $providers = $providerStatement->fetchAll();
    }
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $loadError = true;
}

$fullName = $profile ? trim(implode(' ', array_filter([
    $profile['primer_nombre'], $profile['segundo_nombre'],
    $profile['primer_apellido'], $profile['segundo_apellido'],
]))) : 'Mi perfil';
$initial = $fullName !== '' ? mb_strtoupper(mb_substr($fullName, 0, 1, 'UTF-8'), 'UTF-8') : 'U';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Historial de proveedores | Biblioteca de Proveedores CAP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root { --cap-red: #c62828; --cap-deep: #a71920; --ink: #261919; }
        body { min-height: 100vh; color: var(--ink); background: #faf8f8; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }
        .topbar { min-height: 82px; background: #fff; border-bottom: 1px solid #e5e5e8; }
        .topbar nav { position: relative; min-height: 82px; }
        .topbar .page-title { position: absolute; left: 50%; transform: translateX(-50%); white-space: nowrap; }
        .cap-name { color: var(--cap-red); font-size: 1.6rem; font-weight: 900; letter-spacing: .08em; }
        .account-toggle { color: var(--ink); border: 1px solid #e5b7b7; border-radius: 999px; background: #fff4f4; font-weight: 700; }
        .account-toggle:hover, .account-toggle:focus { color: var(--cap-deep); border-color: #d98989; background: #ffeded; }
        .account-avatar { display: grid; width: 2.35rem; height: 2.35rem; flex: 0 0 2.35rem; place-items: center; overflow: hidden; border-radius: 50%; color: #fff; background: var(--cap-red); font-size: 1rem; font-weight: 800; }
        .account-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .account-menu .dropdown-menu { min-width: 12rem; border: 1px solid #eee; border-radius: .8rem; box-shadow: 0 12px 30px rgb(20 20 20 / 10%); }
        .page-title { color: var(--cap-red); font-family: "Oswald", "Arial Narrow", system-ui, sans-serif; font-size: clamp(2rem, 3vw, 2.55rem); font-weight: 700; letter-spacing: .015em; line-height: 1; }
        .page-layout { height: calc(100vh - 82px); height: calc(100dvh - 82px); overflow: hidden; }
        .profile-side { height: 100%; overflow-y: auto; overscroll-behavior: contain; padding: clamp(1.25rem, 3vw, 2.25rem); color: #fff; background: linear-gradient(160deg, #b71c1c 0%, #c62828 64%, #df8585 100%); }
        .profile-photo { display: grid; width: 100%; aspect-ratio: 1 / 1; max-height: 430px; place-items: center; overflow: hidden; border: 8px solid #fff; border-radius: .75rem; background: linear-gradient(145deg, #fff 0%, #f8eeee 100%); color: var(--cap-red); font-size: clamp(5rem, 12vw, 10rem); font-weight: 800; box-shadow: 0 12px 32px rgb(60 0 0 / 18%); }
        .profile-photo-button { display: block; width: 100%; padding: 0; border: 0; border-radius: .75rem; background: transparent; cursor: pointer; }
        .profile-photo img { width: 100%; height: 100%; object-fit: cover; }
        .profile-photo-wrap { position: relative; }
        .profile-back-arrow { display: inline-grid; width: 2.5rem; height: 2.5rem; margin-bottom: .8rem; place-items: center; border-radius: 50%; color: #fff; background: rgb(70 0 0 / 35%); text-decoration: none; }
        .profile-back-arrow:hover, .profile-back-arrow:focus { color: #fff; background: rgb(70 0 0 / 72%); }
        .profile-camera-button { position: absolute; right: 1rem; bottom: 1rem; display: grid; width: 2.8rem; height: 2.8rem; place-items: center; border: 2px solid #fff; border-radius: 50%; color: #fff; background: var(--cap-red); box-shadow: 0 4px 14px rgb(30 0 0 / 28%); }
        .profile-camera-button:hover, .profile-camera-button:focus { color: #fff; background: var(--cap-deep); }
        .profile-info { margin-top: 1.25rem; padding: 1rem; color: var(--ink); background: #fff; border-radius: .75rem; box-shadow: 0 8px 24px rgb(60 0 0 / 12%); }
        .profile-info dt { margin-top: .75rem; color: #777; font-size: .76rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
        .profile-info dd { margin-bottom: 0; font-weight: 650; overflow-wrap: anywhere; }
        .main-content { display: flex; min-height: 0; height: 100%; flex-direction: column; overflow: hidden; padding: clamp(1.25rem, 3vw, 2.75rem); }
        .history-tools { position: relative; z-index: 3; flex: 0 0 auto; padding-bottom: 1rem; background: #faf8f8; }
        .history-scroll { min-height: 0; flex: 1 1 auto; overflow-y: auto; overscroll-behavior: contain; padding-bottom: 1rem; }
        .history-search { max-width: 480px; margin-left: auto; }
        .history-live-wrap { position: relative; width: min(100%, 480px); margin-left: auto; }
        .history-suggestions { position: absolute; z-index: 10; top: calc(100% + .35rem); right: 0; left: 0; overflow: hidden; border: 1px solid #e4e4e8; border-radius: .8rem; background: #fff; box-shadow: 0 12px 30px rgb(20 20 20 / 14%); }
        .search-suggestion { display: flex; width: 100%; align-items: center; gap: .8rem; padding: .7rem .9rem; border: 0; border-bottom: 1px solid #eee; color: var(--ink); background: #fff; text-align: left; text-decoration: none; }
        .search-suggestion:last-child { border-bottom: 0; }
        .search-suggestion:hover, .search-suggestion:focus { color: var(--cap-deep); background: #fff5f5; }
        .suggestion-mark { display: grid; width: 2.5rem; height: 2.5rem; flex: 0 0 2.5rem; place-items: center; overflow: hidden; border-radius: .5rem; color: var(--cap-red); background: #fff0f0; font-weight: 800; }
        .history-list { display: grid; gap: 1rem; }
        .history-item { display: grid; grid-template-columns: minmax(0, 1fr) 150px; align-items: center; gap: 1.25rem; padding: 1.25rem; background: #fff; border: 1px solid #e9e3e3; border-radius: .9rem; box-shadow: 0 8px 24px rgb(35 15 15 / 5%); transition: transform .15s ease, box-shadow .15s ease; }
        .history-item:hover { transform: translateY(-2px); box-shadow: 0 12px 26px rgb(35 15 15 / 9%); }
        .history-logo { display: grid; width: 135px; height: 110px; place-items: center; overflow: hidden; border: 1px solid #eee; border-radius: .65rem; background: #fff8f8; color: var(--cap-red); font-size: 3rem; font-weight: 800; }
        .history-logo img { width: 100%; height: 100%; object-fit: contain; }
        .history-item h2 { font-weight: 750; }
        .stars { color: #e6a700; letter-spacing: .08em; }
        .btn-cap { color: #fff; background: var(--cap-red); border: 0; font-weight: 650; }
        .btn-cap:hover { color: #fff; background: var(--cap-deep); }
        .empty-state { padding: 2.5rem 1rem; text-align: center; color: #707078; background: #fff; border: 1px dashed #d7d7dc; border-radius: .9rem; }
        .site-footer { margin-top: 2.5rem; color: #3f474d; background: #e9ecef; font-size: .88rem; }
        .site-footer a { color: #33424a; }
        .crop-stage { width: min(100%, 420px); aspect-ratio: 1; margin: auto; overflow: hidden; border-radius: .75rem; background: #f0f0f0; touch-action: none; }
        #photoCropCanvas { display: block; width: 100%; height: 100%; cursor: grab; }
        #photoCropCanvas:active { cursor: grabbing; }
        .photo-drop-area { padding: 1.5rem; border: 2px dashed #c8c8ce; border-radius: .75rem; text-align: center; background: #fafafa; cursor: pointer; }
        .photo-drop-area.dragging { border-color: var(--cap-red); background: #fff3f3; }
        .modal-dialog-centered { min-height: calc(100% - 1rem); }
        @media (max-width: 767.98px) {
            html, body { height: auto; min-height: 100%; }
            body { overflow-y: auto; -webkit-overflow-scrolling: touch; }
            .page-layout { display: block; height: auto; min-height: 0; overflow: visible; }
            .profile-side { height: auto; max-height: none; overflow: visible; overscroll-behavior: auto; }
            .main-content { display: block; height: auto; min-height: 0; overflow: visible; }
            .history-scroll { height: auto; min-height: 0; overflow: visible; }
        }
        @media (max-width: 767.98px) {
            .topbar { height: auto; }
            .topbar nav { display: grid !important; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); height: auto; gap: .5rem !important; }
            .topbar nav > a { grid-column: 1; margin: 0; min-width: 0; }
            .topbar .page-title { display: block !important; position: static; grid-column: 2; max-width: 100%; min-width: 0; transform: none; white-space: normal; text-align: center; font-size: clamp(1rem, 3.5vw, 1.35rem); line-height: 1.05; }
            .topbar nav > .account-menu { grid-column: 3; justify-self: end; min-width: 0; }
            .account-toggle { padding: .375rem .75rem; }
            .account-toggle > .text-start, .account-toggle::after { display: none !important; }
        }
        @media (max-width: 575.98px) { .history-item { grid-template-columns: 1fr; } .history-logo { width: 100%; height: 130px; } }
    </style>
    <link href="../assets/css/mobile-header.css?v=4" rel="stylesheet">
</head>
<body data-csrf-token="<?= perfil_escape($_SESSION['csrf_token']) ?>">
<header class="topbar">
    <nav class="container-fluid px-3 px-lg-4 d-flex align-items-center justify-content-between gap-3 py-3">
        <a class="d-flex align-items-center gap-2 text-decoration-none" href="index.php" aria-label="Inicio CAP">
            <img src="../assets/img/cap-emblem.svg" width="40" height="44" alt="">
            <span class="cap-name">CAP</span>
        </a>
        <h1 class="page-title mb-0 d-none d-sm-block">Biblioteca de Proveedores</h1>
        <div class="dropdown account-menu">
            <button class="btn account-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Cuenta de usuario">
                <span class="account-avatar" aria-hidden="true"><?php if (perfil_media_url($profile['foto_perfil_url'] ?? '') !== ''): ?><img src="<?= perfil_escape(perfil_media_url($profile['foto_perfil_url'])) ?>" alt=""><?php else: ?><?= perfil_escape($initial) ?><?php endif; ?></span>
                <span class="d-none d-sm-inline text-start"><span class="d-block"><?= perfil_escape($fullName) ?></span><small class="d-block text-secondary fw-normal"><?= perfil_escape($profile['cargo'] ?? '') ?></small></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="index.php">Ir al inicio</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><form action="../backend/controllers/logout.php" method="post" class="m-0"><input type="hidden" name="csrf_token" value="<?= perfil_escape($_SESSION['csrf_token']) ?>"><button class="dropdown-item text-danger" type="submit">Cerrar sesión</button></form></li>
            </ul>
        </div>
    </nav>
</header>
<div class="container-fluid"><div class="row page-layout">
    <aside class="col-md-4 col-lg-3 profile-side">
        <?php if ($loadError): ?>
            <div class="alert alert-light" role="alert">No se pudo cargar el perfil.</div>
        <?php else: ?>
            <a class="profile-back-arrow" href="index.php" aria-label="Volver a proveedores"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg></a>
            <div class="profile-photo-wrap">
                <button class="profile-photo-button" type="button" data-bs-toggle="modal" data-bs-target="#photoConfirmModal" aria-label="Cambiar foto de perfil">
                    <span class="profile-photo" id="profilePhotoDisplay">
                        <?php if (perfil_media_url($profile['foto_perfil_url'] ?? '') !== ''): ?><img src="<?= perfil_escape(perfil_media_url($profile['foto_perfil_url'])) ?>" alt="Foto de <?= perfil_escape($fullName) ?>">
                        <?php else: ?><span aria-hidden="true"><?= perfil_escape($initial) ?></span><?php endif; ?>
                    </span>
                </button>
                <button class="profile-camera-button" type="button" data-bs-toggle="modal" data-bs-target="#photoConfirmModal" aria-label="Cambiar foto de perfil"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h3l1.5-2h7L17 7h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1Z"/><circle cx="12" cy="13" r="3.5"/></svg></button>
            </div>
            <dl class="profile-info">
                <dt>Nombre completo</dt><dd><?= perfil_escape($fullName) ?></dd>
                <dt>Cargo o puesto</dt><dd><?= perfil_escape($profile['cargo']) ?></dd>
                <dt>Área de su puesto</dt><dd><?= perfil_escape($profile['departamento']) ?></dd>
                <dt>Empresa a la que pertenece</dt><dd><?= perfil_escape($profile['empresa']) ?></dd>
                <dt>Correo electrónico</dt><dd><?= perfil_escape($profile['correo']) ?></dd>
                <dt>Celular de trabajo</dt><dd><?= perfil_escape($profile['celular']) ?></dd>
                <dt>Fecha de ingreso al sistema</dt><dd><?= perfil_escape(date('d/m/Y', strtotime($profile['fecha_registro']))) ?></dd>
            </dl>
        <?php endif; ?>
    </aside>
    <main class="col-md-8 col-lg-9 main-content">
        <?php if ($providerFlash !== ''): ?><div class="alert alert-success py-2" role="status"><?= perfil_escape($providerFlash) ?></div><?php endif; ?>
        <div class="history-tools d-flex flex-wrap align-items-end justify-content-between gap-3">
            <div><p class="text-uppercase small fw-bold text-secondary mb-1">Mi cuenta</p><h2 class="h2 fw-bold mb-0">Historial de proveedores ingresados</h2></div>
            <div class="history-live-wrap">
            <form class="history-search" id="historyLiveSearch" data-mode="perfil" data-endpoint="../backend/controllers/buscar-historial.php?tipo=perfil" action="perfil.php" method="get">
                <label class="visually-hidden" for="q">Buscar o filtrar proveedores</label>
                <div class="input-group"><input class="form-control" id="q" name="q" value="<?= perfil_escape($search) ?>" placeholder="Buscar por nombre, RTN o categoría"><button class="btn btn-cap" type="submit">Buscar</button></div>
            </form>
            <div class="history-suggestions d-none" id="historySuggestions" role="listbox" aria-label="Coincidencias"></div>
            </div>
        </div>
        <div class="history-scroll">
        <?php if ($loadError): ?>
            <div class="alert alert-danger" role="alert">No se pudo cargar el historial de proveedores.</div>
        <?php elseif (!$providers): ?>
            <div class="empty-state"><h3 class="h5 text-dark">Aún no hay proveedores en tu historial</h3><p class="mb-0">Los proveedores que registres aparecerán en esta sección.</p></div>
        <?php else: ?>
            <div class="history-list">
                <?php foreach ($providers as $provider): ?>
                    <?php $providerName = perfil_escape($provider['nombre_empresa']); $rating = (float) ($provider['promedio_estrellas'] ?? 0); ?>
                    <article class="history-item" data-live-search-item="<?= perfil_escape(mb_strtolower(implode(' ', [$provider['nombre_empresa'], $provider['rtn'], $provider['categoria']]), 'UTF-8')) ?>">
                        <div>
                            <h3 class="h5 mb-1"><?= $providerName ?></h3>
                            <div class="text-secondary small">Fecha de ingreso: <?= perfil_escape(date('d/m/Y', strtotime($provider['fecha_registro']))) ?></div>
                            <div class="text-secondary small">Categoría: <?= perfil_escape($provider['categoria']) ?> · RTN: <?= perfil_escape($provider['rtn']) ?></div>
                            <div class="stars mt-2" aria-label="Calificación promedio">
                                <?= str_repeat('★', (int) round($rating)) . str_repeat('☆', 5 - (int) round($rating)) ?>
                                <span class="small text-secondary ms-1"><?= $provider['promedio_estrellas'] === null ? 'Sin calificaciones' : number_format($rating, 1) ?></span>
                            </div>
                            <a class="btn btn-outline-danger btn-sm mt-3" href="proveedor.php?id=<?= (int) $provider['id_proveedor'] ?>">Ir al proveedor</a>
                            <a class="btn btn-outline-secondary btn-sm mt-3" href="editar_proveedor.php?id=<?= (int) $provider['id_proveedor'] ?>">Editar datos</a>
                        </div>
                        <div class="history-logo">
                        <?php if (perfil_media_url($provider['logo_url'] ?? '') !== ''): ?><img src="<?= perfil_escape(perfil_media_url($provider['logo_url'])) ?>" alt="Logo de <?= $providerName ?>">
                            <?php else: ?><span aria-hidden="true"><?= perfil_escape(mb_strtoupper(mb_substr($provider['nombre_empresa'], 0, 1, 'UTF-8'), 'UTF-8')) ?></span><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (!$loadError && $totalPages > 1): ?>
            <nav class="mt-4" id="historyPagination" aria-label="Paginación del historial">
                <ul class="pagination justify-content-center flex-wrap">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= perfil_escape(perfil_page_url($page - 1)) ?>" aria-label="Página anterior">Anterior</a></li>
                    <?php for ($number = max(1, $page - 2); $number <= min($totalPages, $page + 2); $number++): ?>
                        <li class="page-item <?= $number === $page ? 'active' : '' ?>"><a class="page-link" href="<?= perfil_escape(perfil_page_url($number)) ?>" aria-current="<?= $number === $page ? 'page' : 'false' ?>"><?= $number ?></a></li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= perfil_escape(perfil_page_url($page + 1)) ?>" aria-label="Página siguiente">Siguiente</a></li>
                </ul>
                <p class="text-center small text-secondary">Mostrando <?= count($providers) ?> de <?= $totalRecords ?> proveedores</p>
            </nav>
        <?php endif; ?>
        <?php include __DIR__ . '/partials/footer.php'; ?>
        </div>
    </main>
</div></div>
<div class="modal fade" id="photoConfirmModal" tabindex="-1" aria-labelledby="photoConfirmTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h2 class="modal-title fs-5" id="photoConfirmTitle">Cambiar foto de perfil</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
        <div class="modal-body">¿Quieres cambiar tu foto de perfil?</div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-cap" id="confirmPhotoChange">Sí, cambiar foto</button></div>
    </div></div>
</div>
<div class="modal fade" id="photoEditorModal" tabindex="-1" aria-labelledby="photoEditorTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
        <div class="modal-header"><h2 class="modal-title fs-5" id="photoEditorTitle">Agregar y ajustar foto</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
        <div class="modal-body">
            <input type="hidden" id="photoCsrfToken" value="<?= perfil_escape($_SESSION['csrf_token']) ?>">
            <input class="visually-hidden" id="profilePhotoFile" type="file" accept=".jpg,.jpeg,.png,.webp,.gif,.bmp,.avif,image/jpeg,image/pjpeg,image/png,image/webp,image/gif,image/bmp,image/avif">
            <div class="photo-drop-area mb-3" id="photoDropArea" role="button" tabindex="0">Arrastra una imagen aquí o <strong>búscala en tus archivos</strong><div class="small text-secondary mt-1">JPG, PNG, WebP, GIF, BMP o AVIF · máximo 5 MB</div></div>
            <div id="photoEditorControls" class="d-none">
                <div class="crop-stage mb-3"><canvas id="photoCropCanvas" width="512" height="512" aria-label="Vista previa recortada de la foto"></canvas></div>
                <button class="btn btn-outline-secondary btn-sm mb-3" type="button" id="chooseAnotherPhoto">Elegir otra imagen</button>
                <label class="form-label w-100" for="photoZoom">Zoom <input class="form-range mt-2" id="photoZoom" type="range" min="1" max="3" step="0.01" value="1"></label>
                <p class="small text-secondary mb-0">Arrastra la foto para centrarla y usa el control para acercar o alejar.</p>
            </div>
            <div id="photoUploadFeedback" class="small mt-3" aria-live="polite"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-cap" id="saveProfilePhoto" disabled>Guardar foto</button></div>
    </div></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/renovar-sesion.js" defer></script>
<script src="../assets/js/buscar-historial.js" defer></script>
<script>
(() => {
    const confirmModalEl = document.getElementById('photoConfirmModal');
    const editorModalEl = document.getElementById('photoEditorModal');
    const confirmModal = bootstrap.Modal.getOrCreateInstance(confirmModalEl);
    const editorModal = bootstrap.Modal.getOrCreateInstance(editorModalEl);
    let openEditorAfterConfirm = false;
    document.getElementById('confirmPhotoChange').addEventListener('click', () => {
        openEditorAfterConfirm = true;
        confirmModal.hide();
    });
    confirmModalEl.addEventListener('hidden.bs.modal', () => {
        if (openEditorAfterConfirm) {
            openEditorAfterConfirm = false;
            editorModal.show();
        }
    });
    editorModalEl.addEventListener('hidden.bs.modal', () => {
        fileInput.value = '';
        image = null;
        controls.classList.add('d-none');
        dropArea.hidden = false;
        feedback.textContent = '';
        saveButton.disabled = true;
        saveButton.textContent = 'Guardar foto';
    });

    const fileInput = document.getElementById('profilePhotoFile');
    const dropArea = document.getElementById('photoDropArea');
    const canvas = document.getElementById('photoCropCanvas');
    const ctx = canvas.getContext('2d');
    const zoomInput = document.getElementById('photoZoom');
    const controls = document.getElementById('photoEditorControls');
    const saveButton = document.getElementById('saveProfilePhoto');
    const feedback = document.getElementById('photoUploadFeedback');
    let image = null;
    let zoom = 1;
    let offsetX = 0;
    let offsetY = 0;
    let dragOrigin = null;

    const drawCrop = () => {
        if (!image) return;
        const baseScale = Math.max(canvas.width / image.naturalWidth, canvas.height / image.naturalHeight);
        const width = image.naturalWidth * baseScale * zoom;
        const height = image.naturalHeight * baseScale * zoom;
        const maxX = Math.max(0, (width - canvas.width) / 2);
        const maxY = Math.max(0, (height - canvas.height) / 2);
        offsetX = Math.max(-maxX, Math.min(maxX, offsetX));
        offsetY = Math.max(-maxY, Math.min(maxY, offsetY));
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(image, (canvas.width - width) / 2 + offsetX, (canvas.height - height) / 2 + offsetY, width, height);
    };

    const loadPhoto = (file) => {
        if (!file) return;
        const extensionAllowed = /\.(jpe?g|png|webp|gif|bmp|avif)$/i.test(file.name);
        const mimeAllowed = ['image/jpeg', 'image/jpg', 'image/pjpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp', 'image/avif'].includes(file.type.toLowerCase());
        if ((!mimeAllowed && !extensionAllowed) || file.size > 5 * 1024 * 1024) {
            feedback.className = 'small mt-3 text-danger';
            feedback.textContent = 'Selecciona una imagen JPG, PNG, WebP, GIF, BMP o AVIF de hasta 5 MB.';
            return;
        }
        const reader = new FileReader();
        reader.onload = () => {
            image = new Image();
            image.onload = () => {
                if (image.naturalWidth > 6000 || image.naturalHeight > 6000 || image.naturalWidth < 64 || image.naturalHeight < 64) {
                    image = null;
                    feedback.className = 'small mt-3 text-danger';
                    feedback.textContent = 'La imagen debe medir entre 64 y 6000 píxeles por lado.';
                    return;
                }
                zoom = 1;
                offsetX = 0;
                offsetY = 0;
                zoomInput.value = '1';
                dropArea.hidden = true;
                controls.classList.remove('d-none');
                saveButton.disabled = false;
                feedback.textContent = '';
                drawCrop();
            };
            image.onerror = () => {
                feedback.className = 'small mt-3 text-danger';
                feedback.textContent = 'No se pudo abrir la imagen. Prueba con otro archivo.';
            };
            image.src = reader.result;
        };
        reader.onerror = () => {
            feedback.className = 'small mt-3 text-danger';
            feedback.textContent = 'No se pudo leer el archivo. Vuelve a seleccionarlo.';
        };
        reader.readAsDataURL(file);
    };

    dropArea.addEventListener('click', () => fileInput.click());
    dropArea.addEventListener('keydown', (event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); fileInput.click(); } });
    fileInput.addEventListener('change', () => {
        loadPhoto(fileInput.files[0]);
        fileInput.value = '';
    });
    document.getElementById('chooseAnotherPhoto').addEventListener('click', () => fileInput.click());
    ['dragenter', 'dragover'].forEach((type) => dropArea.addEventListener(type, (event) => { event.preventDefault(); dropArea.classList.add('dragging'); }));
    ['dragleave', 'drop'].forEach((type) => dropArea.addEventListener(type, (event) => { event.preventDefault(); dropArea.classList.remove('dragging'); }));
    dropArea.addEventListener('drop', (event) => loadPhoto(event.dataTransfer.files[0]));
    zoomInput.addEventListener('input', () => { zoom = Number(zoomInput.value); drawCrop(); });
    canvas.addEventListener('pointerdown', (event) => { if (!image) return; dragOrigin = { x: event.clientX, y: event.clientY, offsetX, offsetY }; canvas.setPointerCapture(event.pointerId); });
    canvas.addEventListener('pointermove', (event) => {
        if (!dragOrigin) return;
        const bounds = canvas.getBoundingClientRect();
        offsetX = dragOrigin.offsetX + (event.clientX - dragOrigin.x) * (canvas.width / bounds.width);
        offsetY = dragOrigin.offsetY + (event.clientY - dragOrigin.y) * (canvas.height / bounds.height);
        drawCrop();
    });
    ['pointerup', 'pointercancel'].forEach((type) => canvas.addEventListener(type, () => { dragOrigin = null; }));

    saveButton.addEventListener('click', () => {
        if (!image) return;
        saveButton.disabled = true;
        saveButton.textContent = 'Guardando…';
        canvas.toBlob(async (blob) => {
            try {
                if (!blob) throw new Error('No se pudo preparar la imagen. Vuelve a seleccionarla.');
                const form = new FormData();
                form.append('foto', blob, 'perfil.jpg');
                form.append('csrf_token', document.getElementById('photoCsrfToken').value);
                const response = await fetch('../backend/controllers/actualizar_foto.php', { method: 'POST', body: form, credentials: 'same-origin' });
                const responseText = await response.text();
                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (_) {
                    throw new Error(`El servidor respondió con error HTTP ${response.status}. Revisa que Apache permita acceder a backend/controllers/actualizar_foto.php.`);
                }
                if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudo guardar la foto.');
                const display = document.getElementById('profilePhotoDisplay');
                const photo = document.createElement('img');
                photo.src = result.photoUrl + '?v=' + Date.now();
                photo.alt = 'Foto de perfil';
                display.replaceChildren(photo);
                feedback.className = 'small mt-3 text-success';
                feedback.textContent = 'Foto actualizada correctamente.';
                window.setTimeout(() => editorModal.hide(), 650);
            } catch (error) {
                feedback.className = 'small mt-3 text-danger';
                feedback.textContent = error.message || 'No se pudo guardar la foto.';
            } finally {
                saveButton.disabled = false;
                saveButton.textContent = 'Guardar foto';
            }
        }, 'image/jpeg', 0.9);
    });
})();
</script>
</body>
</html>
