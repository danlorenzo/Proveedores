<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/config/config.php';
apply_security_headers();
start_app_session();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

function base64url_decode_value(string $value): string|false
{
    $padding = strlen($value) % 4;
    if ($padding !== 0) {
        $value .= str_repeat('=', 4 - $padding);
    }
    return base64_decode(strtr($value, '-_', '+/'), true);
}

function read_access_token(?string $token): ?array
{
    if (!$token) {
        return null;
    }

    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
    $headerJson = base64url_decode_value($encodedHeader);
    $payloadJson = base64url_decode_value($encodedPayload);
    $signature = base64url_decode_value($encodedSignature);
    if ($headerJson === false || $payloadJson === false || $signature === false) {
        return null;
    }

    $header = json_decode($headerJson, true);
    $payload = json_decode($payloadJson, true);
    if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256' || !is_array($payload)) {
        return null;
    }

    $expected = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, JWT_SECRET, true);
    if (!hash_equals($expected, $signature)) {
        return null;
    }

    if (!isset($payload['sub'], $payload['exp']) || (int) $payload['exp'] <= time()) {
        return null;
    }

    return $payload;
}

function index_media_url(mixed $value): string
{
    $url = trim((string) ($value ?? ''));
    if ($url === '' || str_starts_with($url, '//') || str_contains($url, "\\")) return '';
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if ($scheme !== null && strtolower((string) $scheme) !== 'https') return '';
    return $scheme === null && !str_starts_with($url, '/') ? '../' . $url : $url;
}

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$token = read_access_token($_COOKIE['access_token'] ?? null);
if ($token === null) {
    setcookie('access_token', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    header('Location: login.php');
    exit;
}
refresh_access_token_cookie($token);

$categories = [];
$providers = [];
$accountCargo = '';
$accountPhoto = '';
$accountFullName = '';
$totalProviders = 0;
$totalPages = 1;
$loadError = '';
$search = isset($_GET['q']) && is_scalar($_GET['q']) ? trim((string) $_GET['q']) : '';
$categoryId = filter_input(INPUT_GET, 'categoria', FILTER_VALIDATE_INT) ?: null;
$sortOptions = ['recientes', 'peor', 'mejor', 'popular'];
$perPage = 10;
$page = isset($_GET['page']) && is_scalar($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$sort = isset($_GET['orden']) && is_scalar($_GET['orden']) ? (string) $_GET['orden'] : 'recientes';
if (!in_array($sort, $sortOptions, true)) {
    $sort = 'recientes';
}
$orderBy = match ($sort) {
    'peor' => 'v.promedio_estrellas IS NULL ASC, v.promedio_estrellas ASC, v.nombre_empresa ASC',
    'mejor' => 'v.promedio_estrellas IS NULL ASC, v.promedio_estrellas DESC, v.nombre_empresa ASC',
    'popular' => 'v.total_calificaciones DESC, v.promedio_estrellas DESC, v.nombre_empresa ASC',
    default => 'p.fecha_registro DESC, v.nombre_empresa ASC',
};

try {
    $pdo = database();
    $categories = $pdo->query('SELECT id_categoria, nombre FROM categoria ORDER BY nombre')->fetchAll();
    $accountStatement = $pdo->prepare(
        'SELECT u.primer_nombre, u.segundo_nombre, u.primer_apellido, u.segundo_apellido,
                u.foto_perfil_url, c.nombre AS cargo
           FROM usuario u
           INNER JOIN cargo c ON c.id_cargo = u.id_cargo
          WHERE u.dni = :dni LIMIT 1'
    );
    $accountStatement->execute(['dni' => $token['sub']]);
    $account = $accountStatement->fetch();
    if ($account) {
        $accountCargo = $account['cargo'];
        $accountPhoto = index_media_url($account['foto_perfil_url'] ?? '');
        $accountFullName = trim(implode(' ', array_filter([
            $account['primer_nombre'], $account['segundo_nombre'],
            $account['primer_apellido'], $account['segundo_apellido'],
        ])));
    }

    $sql = 'SELECT v.id_proveedor, v.nombre_empresa, v.rtn, v.logo_url,
                   v.promedio_estrellas, v.total_calificaciones, p.fecha_registro
              FROM vw_proveedor_resumen v
              INNER JOIN proveedor p ON p.id_proveedor = v.id_proveedor';
    $conditions = [];
    $parameters = [];
    if ($search !== '') {
        $conditions[] = '(v.nombre_empresa LIKE :search_name OR v.rtn LIKE :search_rtn)';
        $parameters['search_name'] = '%' . $search . '%';
        $parameters['search_rtn'] = '%' . $search . '%';
    }
    if ($categoryId !== null) {
        $conditions[] = 'EXISTS (SELECT 1 FROM proveedor p WHERE p.id_proveedor = v.id_proveedor AND p.id_categoria = :category_id)';
        $parameters['category_id'] = $categoryId;
    }
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $countSql = 'SELECT COUNT(*) FROM vw_proveedor_resumen v INNER JOIN proveedor p ON p.id_proveedor = v.id_proveedor';
    if ($conditions) {
        $countSql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $countStatement = $pdo->prepare($countSql);
    $countStatement->execute($parameters);
    $totalProviders = (int) $countStatement->fetchColumn();
    $totalPages = max(1, (int) ceil($totalProviders / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $sql .= ' ORDER BY ' . $orderBy;
    $sql .= ' LIMIT ' . $perPage . ' OFFSET ' . $offset;

    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    $providers = $statement->fetchAll();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $loadError = 'No se pudo cargar la información. Revisa que la base de datos esté disponible.';
}

$userName = htmlspecialchars((string) ($token['name'] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
$accountName = htmlspecialchars($accountFullName !== '' ? $accountFullName : (string) ($token['name'] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
$accountInitial = htmlspecialchars(mb_strtoupper(mb_substr($accountFullName !== '' ? $accountFullName : 'U', 0, 1, 'UTF-8'), 'UTF-8'), ENT_QUOTES, 'UTF-8');
$activeCategory = (int) $categoryId;

function index_page_url(int $targetPage): string
{
    $parameters = ['page' => max(1, $targetPage)];
    foreach (['q', 'categoria', 'orden'] as $key) {
        if (isset($_GET[$key]) && is_scalar($_GET[$key]) && (string) $_GET[$key] !== '') {
            $parameters[$key] = (string) $_GET[$key];
        }
    }
    return 'index.php?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#c62828">
    <title>Biblioteca de Proveedores | Grupo CAP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root { --cap-red: #c62828; --cap-deep: #a71920; --ink: #252525; }
        body { min-height: 100vh; color: var(--ink); background: #f7f7f8; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }
        .topbar { min-height: 82px; background: #fff; border-bottom: 1px solid #e5e5e8; }
        .topbar nav { position: relative; min-height: 82px; }
        .topbar .page-title { position: absolute; left: 50%; transform: translateX(-50%); white-space: nowrap; }
        .cap-mark { display: inline-grid; width: 44px; height: 48px; place-items: center; }
        .cap-mark img { display: block; width: 100%; height: 100%; }
        .cap-name { color: var(--cap-red); font-size: 1.6rem; font-weight: 900; letter-spacing: .08em; }
        .page-title { margin: 0; color: var(--cap-red); font-family: system-ui, -apple-system, "Segoe UI", sans-serif; font-size: clamp(2rem, 3vw, 2.55rem); font-weight: 700; letter-spacing: .015em; line-height: 1; text-shadow: 0 2px 0 rgb(167 25 32 / 10%); }
        .btn-cap { color: #fff; background: var(--cap-red); border: 0; font-weight: 650; }
        .btn-cap:hover { color: #fff; background: var(--cap-deep); }
        .sidebar { position: relative; min-height: 100%; background: #c62828; border-right: 1px solid #e5e5e8; }
        .sidebar::after { position: absolute; top: 100%; left: 0; width: 100%; height: 2rem; background: #c62828; content: ""; }
        .sidebar-title { color: #fff; }
        .category-toggle { display: flex; width: 100%; align-items: center; justify-content: space-between; padding: .65rem .75rem; border: 1px solid rgb(255 255 255 / 45%); border-radius: .5rem; color: #fff; background: rgb(255 255 255 / 10%); font-weight: 700; text-align: left; }
        .category-toggle:hover, .category-toggle:focus { color: var(--cap-deep); background: rgb(255 255 255 / 88%); }
        .category-toggle svg { transition: transform .2s ease; }
        .category-toggle[aria-expanded="true"] svg { transform: rotate(180deg); }
        .category-link { display: block; padding: .55rem .75rem; color: #fff; text-decoration: none; border-radius: .5rem; font-weight: 600; transition: background-color .15s ease, color .15s ease; }
        .category-link:hover, .category-link.active { color: var(--cap-deep); background: rgb(255 255 255 / 88%); }
        .search-panel, .provider-card { background: #fff; border: 1px solid #e8e8eb; border-radius: 1rem; }
        .search-panel { padding: 1rem; }
        .search-live-wrap { position: relative; margin-bottom: 1.5rem; }
        .search-live-wrap .search-panel { margin-bottom: 0 !important; }
        .search-suggestions { position: absolute; z-index: 20; top: calc(100% + .35rem); right: 0; left: 0; overflow: hidden; border: 1px solid #e4e4e8; border-radius: .8rem; background: #fff; box-shadow: 0 12px 30px rgb(20 20 20 / 14%); }
        .search-suggestion { display: flex; width: 100%; align-items: center; gap: .8rem; padding: .7rem .9rem; border: 0; border-bottom: 1px solid #eee; color: var(--ink); background: #fff; text-align: left; text-decoration: none; }
        .search-suggestion:last-child { border-bottom: 0; }
        .search-suggestion:hover, .search-suggestion:focus { color: var(--cap-deep); background: #fff5f5; }
        .suggestion-mark { display: grid; width: 2.5rem; height: 2.5rem; flex: 0 0 2.5rem; place-items: center; overflow: hidden; border-radius: .5rem; color: var(--cap-red); background: #fff0f0; font-weight: 800; }
        .suggestion-mark img { width: 100%; height: 100%; object-fit: cover; }
        .provider-card { height: 100%; overflow: hidden; transition: transform .15s ease, box-shadow .15s ease; }
        .provider-link { display: block; height: 100%; color: inherit; text-decoration: none; }
        .provider-link:focus-visible { outline: 3px solid #86b7fe; outline-offset: 3px; border-radius: 1rem; }
        .provider-card:hover { transform: translateY(-3px); box-shadow: 0 12px 30px rgb(20 20 20 / 8%); }
        .provider-logo { display: grid; height: 170px; place-items: center; overflow: hidden; color: var(--cap-red); background: linear-gradient(145deg, #fff2f2, #f1f1f3); font-size: 3rem; font-weight: 800; }
        .provider-logo img { width: 100%; height: 100%; object-fit: cover; }
        .stars { color: #e6a700; letter-spacing: .08em; }
        .account-toggle { color: var(--ink); border: 1px solid #e5b7b7; border-radius: 999px; background: #fff4f4; font-weight: 700; }
        .account-toggle:hover, .account-toggle:focus { color: var(--cap-deep); border-color: #d98989; background: #ffeded; }
        .account-menu .dropdown-menu { min-width: 12rem; border: 1px solid #eee; border-radius: .8rem; box-shadow: 0 12px 30px rgb(20 20 20 / 10%); }
        .account-avatar { display: grid; width: 2.35rem; height: 2.35rem; flex: 0 0 2.35rem; place-items: center; overflow: hidden; border-radius: 50%; color: #fff; background: var(--cap-red); font-size: 1rem; font-weight: 800; }
        .account-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .app-layout { height: calc(100vh - 82px); height: calc(100dvh - 82px); overflow: visible; }
        .content-main { display: flex; min-height: 0; height: 100%; flex-direction: column; overflow: hidden; }
        .sticky-tools { position: relative; z-index: 5; flex: 0 0 auto; padding-bottom: .25rem; background: #f7f7f8; }
        .provider-scroll { min-height: 0; flex: 1 1 auto; overflow-y: auto; padding-bottom: 3rem; }
        .site-footer { color: #3f474d; background: #e9ecef; font-size: .88rem; }
        .site-footer a { color: #33424a; }
        .site-footer { margin-top: 2rem; }
        .add-provider-fab { position: fixed; right: 1.5rem; bottom: 1.5rem; z-index: 1030; display: grid; width: 3.7rem; height: 3.7rem; place-items: center; border: 0; border-radius: 50%; color: #fff; background: linear-gradient(145deg, #d93636, #a71920); box-shadow: 0 8px 24px rgb(167 25 32 / 35%); font-size: 2rem; line-height: 1; transition: transform .15s ease; }
        .add-provider-fab:hover, .add-provider-fab:focus { color: #fff; background: #94131b; transform: translateY(-2px); }
        .add-provider-menu { min-width: 15rem; padding: .5rem; border: 1px solid #eee; border-radius: .8rem; box-shadow: 0 12px 30px rgb(20 20 20 / 12%); }
        #entryFormModal { padding: 0 !important; overflow: hidden; }
        .entry-form-dialog { width: 100vw; height: 100dvh; max-width: none; margin: 0; }
        #entryFormModal .modal-content { position: relative; width: 100%; height: 100%; border: 0 !important; background: transparent; box-shadow: none !important; pointer-events: none; }
        #entryFormModal .modal-header { position: absolute; top: .75rem; right: .75rem; z-index: 2; padding: 0; border: 0; pointer-events: auto; }
        #entryFormModal .modal-title { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        #entryFormModal .modal-body { display: flex; width: 100%; height: 100%; align-items: center; justify-content: center; padding: 0 !important; pointer-events: none; }
        .entry-form-frame { display: block; width: 100vw; height: 100dvh; border: 0; background: transparent; pointer-events: auto; }
        .empty-state { padding: 3.5rem 1rem; text-align: center; color: #707078; background: #fff; border: 1px dashed #d7d7dc; border-radius: 1rem; }
        @media (min-width: 768px) { .sidebar { height: calc(100vh - 82px); overflow: visible; } }
        @media (max-width: 767.98px) {
            .topbar nav { display: grid !important; grid-template-columns: auto minmax(0, 1fr) auto; gap: .5rem !important; }
            .topbar .page-title { position: static; transform: none; white-space: normal; text-align: center; font-size: clamp(1rem, 3.8vw, 1.3rem); line-height: 1.05; }
            .topbar nav > a { grid-column: 1; }
            .topbar nav > h1 { grid-column: 2; }
            .topbar nav > .account-menu { grid-column: 3; justify-self: end; }
            .app-layout { height: auto; overflow: visible; }
            .sidebar { min-height: 0; height: auto; border-right: 0; border-bottom: 1px solid #e5e5e8; }
            .sidebar::after { display: none; }
            .content-main { height: auto; overflow: visible; }
            .provider-scroll { overflow: visible; }
            .provider-logo { height: 145px; }
            .sidebar-title { color: #fff; }
            .category-link { color: #fff; }
            .category-link:hover, .category-link.active { color: var(--cap-deep); background: rgb(255 255 255 / 88%); }
        }
    </style>
    <link href="../assets/css/mobile-header.css?v=4" rel="stylesheet">
</head>
<body data-csrf-token="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
<header class="topbar">
    <nav class="container-fluid px-3 px-lg-4 d-flex align-items-center justify-content-between gap-3 py-3">
        <a class="d-flex align-items-center gap-2 text-decoration-none" href="index.php" aria-label="Inicio CAP">
            <span class="cap-mark" aria-hidden="true"><img src="../assets/img/cap-emblem.svg" alt=""></span><span class="cap-name">CAP</span>
        </a>
        <h1 class="page-title h3 mb-0">Biblioteca de Proveedores</h1>
        <div class="dropdown account-menu">
            <button class="btn account-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Cuenta de usuario">
                <span class="account-avatar" aria-hidden="true"><?php if ($accountPhoto !== ''): ?><img src="<?= htmlspecialchars($accountPhoto, ENT_QUOTES, 'UTF-8') ?>" alt=""><?php else: ?><?= $accountInitial ?><?php endif; ?></span>
                <span class="d-none d-sm-inline text-start"><span class="d-block"><?= $accountName ?></span><?php if ($accountCargo !== ''): ?><small class="d-block text-secondary fw-normal"><?= htmlspecialchars($accountCargo, ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="perfil.php">Ir al perfil</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><form action="../backend/controllers/logout.php" method="post" class="m-0"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"><button class="dropdown-item text-danger" type="submit">Cerrar sesión</button></form></li>
            </ul>
        </div>
    </nav>
</header>

<div class="container-fluid">
    <div class="row app-layout">
        <aside class="col-md-3 col-lg-2 sidebar p-3 p-lg-4">
            <h2 class="h6 fw-bold mb-3 sidebar-title d-none d-md-block">Categorías</h2>
            <button class="category-toggle d-md-none mb-0" type="button" data-bs-toggle="collapse" data-bs-target="#categoryLinks" aria-expanded="false" aria-controls="categoryLinks">
                <span>Categorías</span>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </button>
            <div class="collapse d-md-block" id="categoryLinks">
                <a class="category-link <?= $activeCategory === 0 ? 'active' : '' ?>" href="index.php">Todas</a>
                <?php foreach ($categories as $category): ?>
                    <a class="category-link <?= $activeCategory === (int) $category['id_categoria'] ? 'active' : '' ?>"
                       href="?categoria=<?= (int) $category['id_categoria'] ?>">
                        <?= htmlspecialchars($category['nombre'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </aside>

        <main class="col-md-9 col-lg-10 content-main px-3 px-lg-5 py-3 py-lg-4">
            <div class="sticky-tools">
            <div class="search-live-wrap">
            <form id="providerSearchForm" class="search-panel row g-2 align-items-center" action="index.php" method="get">
                <?php if ($categoryId !== null): ?>
                    <input type="hidden" name="categoria" value="<?= $activeCategory ?>">
                <?php endif; ?>
                <input type="hidden" name="orden" value="<?= htmlspecialchars($sort, ENT_QUOTES, 'UTF-8') ?>">
                <div class="col-12 col-lg">
                    <label class="visually-hidden" for="q">Buscar proveedor por nombre o RTN</label>
                    <input class="form-control" id="q" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="Buscar por nombre de empresa o RTN">
                </div>
                <div class="col-12 col-lg-auto d-grid">
                    <button class="btn btn-cap px-4" type="submit">Buscar</button>
                </div>
            </form>
            <div class="search-suggestions d-none" id="providerSuggestions" role="listbox" aria-label="Coincidencias de proveedores"></div>
            </div>
            <form class="d-flex flex-wrap align-items-center gap-2 mb-4" action="index.php" method="get">
                <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
                <?php if ($categoryId !== null): ?><input type="hidden" name="categoria" value="<?= $activeCategory ?>"><?php endif; ?>
                <label for="orden" class="form-label mb-0 fw-semibold">Ordenar por</label>
                <select class="form-select w-auto" id="orden" name="orden" onchange="this.form.submit()">
                    <option value="recientes" <?= $sort === 'recientes' ? 'selected' : '' ?>>Últimos agregados</option>
                    <option value="peor" <?= $sort === 'peor' ? 'selected' : '' ?>>Peor calificación</option>
                    <option value="mejor" <?= $sort === 'mejor' ? 'selected' : '' ?>>Mejor calificación</option>
                    <option value="popular" <?= $sort === 'popular' ? 'selected' : '' ?>>Más populares</option>
                </select>
            </form>
            </div>

            <div class="provider-scroll">
            <?php if ($loadError !== ''): ?>
                <div class="alert alert-danger" role="alert"><?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?></div>
            <?php elseif (!$providers): ?>
                <div class="empty-state">
                    <h2 class="h5 text-dark">Aún no hay proveedores para mostrar</h2>
                    <p class="mb-0">Cuando registres proveedores, aparecerán aquí.</p>
                </div>
            <?php else: ?>
                <div class="row g-3 g-lg-4">
                    <?php foreach ($providers as $provider): ?>
                        <?php $providerName = htmlspecialchars($provider['nombre_empresa'], ENT_QUOTES, 'UTF-8'); ?>
                        <div class="col-12 col-sm-6 col-xl-4 col-xxl-3">
                            <a class="provider-link" data-provider-name="<?= htmlspecialchars(mb_strtolower($provider['nombre_empresa'], 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>" data-provider-rtn="<?= htmlspecialchars($provider['rtn'], ENT_QUOTES, 'UTF-8') ?>" href="proveedor.php?id=<?= (int) $provider['id_proveedor'] ?>">
                            <article class="provider-card">
                                <div class="provider-logo">
                                    <?php if (index_media_url($provider['logo_url'] ?? '') !== ''): ?>
                                        <img src="<?= htmlspecialchars(index_media_url($provider['logo_url']), ENT_QUOTES, 'UTF-8') ?>" alt="Logo de <?= $providerName ?>">
                                    <?php else: ?>
                                        <span aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($provider['nombre_empresa'], 0, 1, 'UTF-8'), 'UTF-8'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="p-3">
                                    <h2 class="h6 fw-bold text-truncate" title="<?= $providerName ?>"><?= $providerName ?></h2>
                                    <div class="stars" aria-label="Promedio de calificación">
                                        <?php $rating = (float) ($provider['promedio_estrellas'] ?? 0); ?>
                                        <?= str_repeat('★', (int) round($rating)) . str_repeat('☆', 5 - (int) round($rating)) ?>
                                        <span class="small text-secondary ms-1"><?= $provider['promedio_estrellas'] === null ? 'Sin calificaciones' : number_format($rating, 1) . ' · ' . (int) $provider['total_calificaciones'] . ' reseñas' ?></span>
                                    </div>
                                </div>
                            </article>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (!$loadError && $totalPages > 1): ?>
                <nav class="mt-4" id="providerPagination" aria-label="Paginación de proveedores">
                    <ul class="pagination justify-content-center flex-wrap">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars(index_page_url($page - 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Página anterior">Anterior</a></li>
                        <?php for ($number = max(1, $page - 2); $number <= min($totalPages, $page + 2); $number++): ?>
                            <li class="page-item <?= $number === $page ? 'active' : '' ?>"><a class="page-link" href="<?= htmlspecialchars(index_page_url($number), ENT_QUOTES, 'UTF-8') ?>" aria-current="<?= $number === $page ? 'page' : 'false' ?>"><?= $number ?></a></li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars(index_page_url($page + 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Página siguiente">Siguiente</a></li>
                    </ul>
                    <p class="text-center small text-secondary">Mostrando <?= count($providers) ?> de <?= $totalProviders ?> proveedores</p>
                </nav>
            <?php endif; ?>
            </div>
        </main>
    </div>
</div>
        <?php include __DIR__ . '/partials/footer.php'; ?>
<button class="add-provider-fab position-fixed bottom-0 end-0 m-4" type="button" data-bs-toggle="modal" data-bs-target="#entryChoiceModal" aria-label="Ingresar datos" title="Ingresar datos">+</button>
<input class="visually-hidden" type="file" id="entryFilePicker" accept="image/jpeg,image/png,image/webp,application/pdf" aria-label="Tomar foto o elegir archivo">
<div class="modal fade" id="entryFormModal" tabindex="-1" aria-labelledby="entryFormModalTitle" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered entry-form-dialog"><div class="modal-content border-0 shadow">
    <div class="modal-header py-2"><h2 class="modal-title h6 fw-bold mb-0" id="entryFormModalTitle">Registrar compra</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar formulario"></button></div>
    <div class="modal-body p-0"><iframe class="entry-form-frame" id="entryFormFrame" title="Formulario de registro"></iframe></div>
  </div></div>
</div>
<div class="modal fade" id="entryChoiceModal" tabindex="-1" aria-labelledby="entryChoiceTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow">
    <div class="modal-header"><h2 class="modal-title h5 fw-bold" id="entryChoiceTitle">¿Cómo quieres ingresar los datos?</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
    <div class="modal-body">
      <div id="entryMethodChoices" class="d-grid gap-2">
        <button class="btn btn-outline-danger text-start p-3" type="button" data-entry-method="ocr">Tomar foto o elegir archivo con OCR</button>
        <button class="btn btn-outline-secondary text-start p-3" type="button" data-entry-method="manual">Escribir los datos manualmente</button>
      </div>
      <div id="entryTargetChoices" class="d-none">
        <button class="btn btn-link px-0 mb-2" type="button" id="entryBackButton">← Cambiar método</button>
        <p class="text-secondary">¿Qué deseas registrar?</p>
        <div class="d-grid gap-2"><a class="btn btn-danger p-3" id="entryProviderLink" href="ingresar_proveedor.php?modo=ocr">Nuevo proveedor</a><a class="btn btn-outline-danger p-3" id="entryPurchaseLink" href="ingresar_documento.php?modo=ocr">Nueva compra</a></div>
      </div>
    </div>
  </div></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const entryFilePicker = document.getElementById('entryFilePicker');
const entryFormModalElement = document.getElementById('entryFormModal');
const entryFormModal = bootstrap.Modal.getOrCreateInstance(entryFormModalElement);
const entryFormFrame = document.getElementById('entryFormFrame');
const showEntryForm = (src, title) => {
  document.getElementById('entryFormModalTitle').textContent = title;
  entryFormFrame.src = src;
  const choicesModal = document.getElementById('entryChoiceModal');
  if (choicesModal.classList.contains('show')) {
    choicesModal.addEventListener('hidden.bs.modal', () => entryFormModal.show(), { once: true });
    bootstrap.Modal.getOrCreateInstance(choicesModal).hide();
  } else {
    entryFormModal.show();
  }
};
window.addEventListener('message', (event) => {
  if (event.origin !== window.location.origin || event.source !== entryFormFrame.contentWindow) return;
  if (event.data?.type === 'cap-entry-title' && typeof event.data.title === 'string') {
    document.getElementById('entryFormModalTitle').textContent = event.data.title;
  }
  if (event.data?.type === 'cap-entry-close') entryFormModal.hide();
});
entryFormModalElement.addEventListener('hidden.bs.modal', () => { entryFormFrame.src = 'about:blank'; });
const openEntryFileDb = () => new Promise((resolve, reject) => {
  const request = indexedDB.open('cap-mobile-entry', 1);
  request.onupgradeneeded = () => request.result.createObjectStore('files', { keyPath: 'id' });
  request.onsuccess = () => resolve(request.result);
  request.onerror = () => reject(request.error);
});

document.querySelectorAll('[data-entry-method]').forEach((button) => button.addEventListener('click', () => {
  const mode = button.dataset.entryMethod;
  if (mode === 'ocr') {
    entryFilePicker.click();
    return;
  }
  document.getElementById('entryProviderLink').href = `ingresar_proveedor.php?modo=${mode}`;
  document.getElementById('entryPurchaseLink').href = `ingresar_documento.php?modo=${mode}`;
  document.getElementById('entryMethodChoices').classList.add('d-none');
  document.getElementById('entryTargetChoices').classList.remove('d-none');
}));

document.getElementById('entryProviderLink').addEventListener('click', (event) => {
  event.preventDefault();
  showEntryForm(`${event.currentTarget.getAttribute('href')}&embedded=1`, 'Nuevo proveedor');
});
document.getElementById('entryPurchaseLink').addEventListener('click', (event) => {
  event.preventDefault();
  showEntryForm(`${event.currentTarget.getAttribute('href')}&embedded=1`, 'Registrar compra');
});

entryFilePicker.addEventListener('change', async () => {
  const file = entryFilePicker.files[0];
  if (!file) return;
  const allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
  if (!allowedTypes.includes(file.type)) {
    window.alert('Formato no admitido. Elige JPG, PNG, WEBP o PDF.');
    entryFilePicker.value = '';
    return;
  }
  if (file.size > 15 * 1024 * 1024) {
    window.alert('El archivo supera el límite de 15 MB.');
    entryFilePicker.value = '';
    return;
  }
  try {
    const db = await openEntryFileDb();
    const transaction = db.transaction('files', 'readwrite');
    transaction.objectStore('files').put({ id: 'pending', file });
    await new Promise((resolve, reject) => {
      transaction.oncomplete = resolve;
      transaction.onerror = () => reject(transaction.error);
      transaction.onabort = () => reject(transaction.error);
    });
    db.close();
    showEntryForm('ingresar_documento.php?modo=ocr&seleccion=1&embedded=1', 'Procesar comprobante');
  } catch (error) {
    entryFilePicker.value = '';
    window.alert('No se pudo preparar el archivo. Intenta de nuevo o elígelo desde el formulario.');
  }
});
document.getElementById('entryBackButton').addEventListener('click', () => {
  document.getElementById('entryTargetChoices').classList.add('d-none');
  document.getElementById('entryMethodChoices').classList.remove('d-none');
});
document.getElementById('entryChoiceModal').addEventListener('hidden.bs.modal', () => {
  document.getElementById('entryTargetChoices').classList.add('d-none');
  document.getElementById('entryMethodChoices').classList.remove('d-none');
});
</script>
<script src="../assets/js/renovar-sesion.js" defer></script>
<script src="../assets/js/buscar-proveedores.js" defer></script>
</body>
</html>
