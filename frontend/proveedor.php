<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/config/config.php';
apply_security_headers();
start_app_session();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

function proveedor_base64url_decode(string $value): string|false
{
    $padding = strlen($value) % 4;
    if ($padding !== 0) $value .= str_repeat('=', 4 - $padding);
    return base64_decode(strtr($value, '-_', '+/'), true);
}

function proveedor_read_token(?string $token): ?array
{
    if (!$token) return null;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$headerPart, $payloadPart, $signaturePart] = $parts;
    $headerJson = proveedor_base64url_decode($headerPart);
    $payloadJson = proveedor_base64url_decode($payloadPart);
    $signature = proveedor_base64url_decode($signaturePart);
    if ($headerJson === false || $payloadJson === false || $signature === false) return null;
    $header = json_decode($headerJson, true);
    $payload = json_decode($payloadJson, true);
    if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256' || !is_array($payload)) return null;
    $expected = hash_hmac('sha256', $headerPart . '.' . $payloadPart, JWT_SECRET, true);
    if (!hash_equals($expected, $signature) || !isset($payload['sub'], $payload['exp']) || (int) $payload['exp'] <= time()) return null;
    return $payload;
}

function proveedor_escape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function proveedor_safe_media_url(mixed $value): string
{
    $url = trim((string) ($value ?? ''));
    if ($url === '' || str_starts_with($url, '//') || str_contains($url, "\\")) return '';
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if ($scheme !== null && strtolower((string) $scheme) !== 'https') return '';
    return $scheme === null && !str_starts_with($url, '/') ? '../' . $url : $url;
}

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$token = proveedor_read_token($_COOKIE['access_token'] ?? null);
if ($token === null) {
    setcookie('access_token', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax']);
    header('Location: login.php');
    exit;
}
refresh_access_token_cookie($token);

$providerId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$provider = null;
$accountCargo = '';
$accountName = 'Usuario';
$accountInitial = 'U';
$accountPhoto = '';
$phones = [];
$purchases = [];
$purchaseItems = [];
$rateDocumentId = filter_input(INPUT_GET, 'calificar', FILTER_VALIDATE_INT) ?: 0;
$canRatePurchase = false;
$providerFlash = $_SESSION['documento_flash'] ?? '';
unset($_SESSION['documento_flash']);
$search = isset($_GET['q']) && is_scalar($_GET['q']) ? trim((string) $_GET['q']) : '';
$perPage = 10;
$page = isset($_GET['page']) && is_scalar($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$totalPurchases = 0;
$totalPages = 1;
$loadError = false;
try {
    $pdo = database();
    $accountStatement = $pdo->prepare(
        'SELECT u.primer_nombre, u.segundo_nombre, u.primer_apellido, u.segundo_apellido,
                u.foto_perfil_url, c.nombre AS cargo
           FROM usuario u INNER JOIN cargo c ON c.id_cargo = u.id_cargo
          WHERE u.dni = :dni LIMIT 1'
    );
    $accountStatement->execute(['dni' => $token['sub']]);
    $account = $accountStatement->fetch();
    $accountCargo = $account ? $account['cargo'] : '';
    if ($account) {
        $accountName = trim(implode(' ', array_filter([$account['primer_nombre'], $account['segundo_nombre'], $account['primer_apellido'], $account['segundo_apellido']]))) ?: 'Usuario';
        $accountInitial = mb_strtoupper(mb_substr($accountName, 0, 1, 'UTF-8'), 'UTF-8');
        $accountPhoto = proveedor_safe_media_url($account['foto_perfil_url'] ?? '');
    }
    if (!$providerId || $providerId < 1) {
        $loadError = true;
    } else {
        $providerStatement = $pdo->prepare(
            'SELECT p.id_proveedor, p.nombre_empresa, p.rtn, p.logo_url, p.direccion,
                    p.fecha_registro, cat.nombre AS categoria,
                    v.promedio_estrellas, v.total_calificaciones
               FROM proveedor p
               INNER JOIN categoria cat ON cat.id_categoria = p.id_categoria
               INNER JOIN vw_proveedor_resumen v ON v.id_proveedor = p.id_proveedor
              WHERE p.id_proveedor = :id LIMIT 1'
        );
        $providerStatement->execute(['id' => $providerId]);
        $provider = $providerStatement->fetch();
        if (!$provider) {
            $loadError = true;
        } else {
            $phoneStatement = $pdo->prepare(
                'SELECT numero, tipo, es_whatsapp FROM proveedor_telefono WHERE id_proveedor = :id ORDER BY es_whatsapp DESC, id_telefono'
            );
            $phoneStatement->execute(['id' => $providerId]);
            $phones = $phoneStatement->fetchAll();

            $purchaseSql =
                'SELECT dc.id_documento, dc.tipo_documento, dc.numero_documento, dc.referencia_externa,
                        dc.nombre_emisor, dc.cai, dc.fecha_documento, dc.atendido_por, dc.subtotal,
                        dc.isv, dc.total, dc.moneda,
                        dc.imagen_url, dc.metodo_ingreso, eg.nombre AS empresa,
                        (SELECT GROUP_CONCAT(dd.descripcion ORDER BY dd.id_detalle SEPARATOR ", ")
                           FROM detalle_documento dd WHERE dd.id_documento = dc.id_documento) AS productos
                   FROM documento_compra dc
                   INNER JOIN empresa_grupo eg ON eg.id_empresa_grupo = dc.id_empresa_grupo
                  WHERE dc.id_proveedor = :provider_id';
            $purchaseParameters = ['provider_id' => $providerId];
            if ($search !== '') {
                $purchaseSql .= ' AND (
                    dc.numero_documento LIKE :document_number
                    OR dc.tipo_documento LIKE :document_type
                    OR eg.nombre LIKE :company
                    OR DATE_FORMAT(dc.fecha_documento, "%d/%m/%Y") LIKE :purchase_date
                    OR EXISTS (SELECT 1 FROM detalle_documento dd2 WHERE dd2.id_documento = dc.id_documento AND dd2.descripcion LIKE :product)
                )';
                $needle = '%' . $search . '%';
                $purchaseParameters += [
                    'document_number' => $needle,
                    'document_type' => $needle,
                    'company' => $needle,
                    'purchase_date' => $needle,
                    'product' => $needle,
                ];
            }
            $countSql = 'SELECT COUNT(*) FROM documento_compra dc INNER JOIN empresa_grupo eg ON eg.id_empresa_grupo = dc.id_empresa_grupo WHERE dc.id_proveedor = :provider_id';
            if ($search !== '') {
                $countSql .= ' AND (dc.numero_documento LIKE :document_number OR dc.tipo_documento LIKE :document_type OR eg.nombre LIKE :company OR DATE_FORMAT(dc.fecha_documento, "%d/%m/%Y") LIKE :purchase_date OR EXISTS (SELECT 1 FROM detalle_documento dd2 WHERE dd2.id_documento = dc.id_documento AND dd2.descripcion LIKE :product))';
            }
            $countStatement = $pdo->prepare($countSql);
            $countStatement->execute($purchaseParameters);
            $totalPurchases = (int) $countStatement->fetchColumn();
            $totalPages = max(1, (int) ceil($totalPurchases / $perPage));
            $page = min($page, $totalPages);
            $purchaseSql .= ' ORDER BY dc.fecha_documento DESC, dc.id_documento DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage);
            $purchaseStatement = $pdo->prepare($purchaseSql);
            $purchaseStatement->execute($purchaseParameters);
            $purchases = $purchaseStatement->fetchAll();
            if ($purchases) {
                $purchaseIds = array_map(static fn(array $row): int => (int) $row['id_documento'], $purchases);
                $marks = implode(',', array_fill(0, count($purchaseIds), '?'));
                $detailStatement = $pdo->prepare("SELECT id_documento, descripcion, cantidad, precio_unitario, imagen_url FROM detalle_documento WHERE id_documento IN ($marks) ORDER BY id_detalle");
                $detailStatement->execute($purchaseIds);
                foreach ($detailStatement->fetchAll() as $item) $purchaseItems[(int) $item['id_documento']][] = $item;
            }
            if ($rateDocumentId > 0) {
                $rateCheck = $pdo->prepare('SELECT 1 FROM documento_compra WHERE id_documento = :doc AND id_proveedor = :provider AND dni_usuario = :dni LIMIT 1');
                $rateCheck->execute(['doc' => $rateDocumentId, 'provider' => $providerId, 'dni' => $token['sub']]);
                $canRatePurchase = (bool) $rateCheck->fetchColumn();
            }
        }
    }
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $loadError = true;
}

$providerName = $provider ? proveedor_escape($provider['nombre_empresa']) : 'Proveedor';
$userName = proveedor_escape($token['name'] ?? 'Usuario');
$rating = (float) ($provider['promedio_estrellas'] ?? 0);
function proveedor_page_url(int $targetPage): string
{
    $params = ['id' => isset($_GET['id']) && is_scalar($_GET['id']) ? (int) $_GET['id'] : 0, 'page' => max(1, $targetPage)];
    if (isset($_GET['q']) && is_scalar($_GET['q']) && (string) $_GET['q'] !== '') $params['q'] = (string) $_GET['q'];
    return 'proveedor.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $providerName ?> | Biblioteca de Proveedores CAP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root { --cap-red: #c62828; --cap-deep: #a71920; --ink: #281818; }
        body { min-height: 100vh; color: var(--ink); background: #fff; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }
        .topbar { height: 82px; background: #fff; border-bottom: 1px solid #e5e5e8; }
        .topbar nav { position: relative; height: 82px; }
        .topbar .page-title { position: absolute; left: 50%; transform: translateX(-50%); white-space: nowrap; }
        .cap-name { color: var(--cap-red); font-size: 1.6rem; font-weight: 900; letter-spacing: .08em; }
        .account-toggle { display: flex; align-items: center; gap: .6rem; max-width: min(38vw, 270px); padding: .3rem .75rem .3rem .35rem; color: #261919; border: 1px solid #e5b7b7; border-radius: 999px; background: #fff4f4; font-weight: 700; }
        .account-toggle:hover, .account-toggle:focus { color: #a71920; border-color: #d98989; background: #ffeded; }
        .account-avatar { display: grid; width: 2.35rem; height: 2.35rem; flex: 0 0 2.35rem; place-items: center; overflow: hidden; border-radius: 50%; color: #fff; background: var(--cap-red); font-weight: 800; }
        .account-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .account-menu .dropdown-menu { min-width: 13rem; border: 1px solid #eee; border-radius: .8rem; box-shadow: 0 12px 30px rgb(20 20 20 / 10%); }
        .account-menu .dropdown-item { cursor: pointer; }
        .page-title { color: var(--cap-red); font-family: "Oswald", "Arial Narrow", system-ui, sans-serif; font-size: clamp(2rem, 3vw, 2.55rem); font-weight: 700; letter-spacing: .015em; line-height: 1; }
        .provider-layout { height: calc(100vh - 82px); height: calc(100dvh - 82px); overflow: hidden; }
        .provider-side { height: 100%; overflow-y: auto; overscroll-behavior: contain; padding: clamp(1.25rem, 2.5vw, 2rem); background: var(--cap-red); }
        .provider-info { padding: 1rem; background: #fff; border: 1px solid rgb(255 255 255 / 70%); border-radius: .8rem; box-shadow: 0 14px 35px rgb(70 0 0 / 18%); }
        .provider-hero { display: grid; min-height: 220px; place-items: center; overflow: hidden; border: 1px solid #e9e1e1; border-radius: .65rem; background: linear-gradient(145deg, #fff, #fff4f4); color: var(--cap-red); font-size: 5rem; font-weight: 800; }
        .provider-hero img { width: 100%; max-height: 280px; object-fit: contain; }
        .provider-info dt { margin-top: .9rem; color: #777; font-size: .76rem; font-weight: 650; text-transform: uppercase; letter-spacing: .04em; }
        .provider-info dd { margin-bottom: 0; font-weight: 650; overflow-wrap: anywhere; }
        .rating-stars { color: #e4a300; font-size: 1.35rem; letter-spacing: .08em; }
        .purchase-area { display: flex; min-height: 0; height: 100%; flex-direction: column; overflow: hidden; padding: clamp(1.25rem, 3vw, 2.5rem); color: #252525; background: #f7f7f8; }
        .purchase-tools { position: relative; z-index: 2; flex: 0 0 auto; padding-bottom: 1rem; color: #252525; background: #f7f7f8; }
        .purchase-live-wrap { position: relative; flex: 1 1 320px; max-width: 440px; }
        .history-suggestions { position: absolute; z-index: 10; top: calc(100% + .35rem); right: 0; left: 0; overflow: hidden; border: 1px solid #e4e4e8; border-radius: .8rem; background: #fff; box-shadow: 0 12px 30px rgb(20 20 20 / 14%); }
        .search-suggestion { display: flex; width: 100%; align-items: center; gap: .8rem; padding: .7rem .9rem; border: 0; border-bottom: 1px solid #eee; color: #261919; background: #fff; text-align: left; text-decoration: none; }
        .search-suggestion:last-child { border-bottom: 0; }
        .search-suggestion:hover, .search-suggestion:focus { color: #a71920; background: #fff5f5; }
        .suggestion-mark { display: grid; width: 2.5rem; height: 2.5rem; flex: 0 0 2.5rem; place-items: center; overflow: hidden; border-radius: .5rem; color: var(--cap-red); background: #fff0f0; font-weight: 800; }
        .purchase-scroll { min-height: 0; flex: 1 1 auto; overflow-y: auto; overscroll-behavior: contain; padding-bottom: 3rem; }
        .purchase-heading { color: #252525; font-family: "Oswald", "Arial Narrow", system-ui, sans-serif; font-size: clamp(2rem, 3vw, 2.7rem); font-weight: 700; }
        .purchase-card { height: 100%; overflow: hidden; background: #fff; border: 1px solid rgb(0 0 0 / 9%); border-radius: .8rem; box-shadow: 0 12px 28px rgb(65 0 0 / 12%); }
        .purchase-image { display: grid; height: 190px; place-items: center; overflow: hidden; background: #fff; color: #aaa; }
        .purchase-image img { width: 100%; height: 100%; object-fit: contain; padding: .75rem; }
        .purchase-image svg { width: 3rem; height: 3rem; }
        .btn-cap { color: #fff; background: var(--cap-red); border: 0; font-weight: 650; }
        .btn-cap:hover { color: #fff; background: var(--cap-deep); }
        .purchase-empty { padding: 3rem 1rem; text-align: center; background: #fff; border-radius: .8rem; }
        .site-footer { margin-top: 2rem; color: #3f474d; background: #e9ecef; font-size: .88rem; }
        .site-footer a { color: #33424a; }
        .back-arrow { display: grid; width: 2.75rem; height: 2.75rem; margin-bottom: .75rem; place-items: center; color: #fff; border-radius: 50%; background: rgb(80 0 0 / 35%); text-decoration: none; }
        .back-arrow:hover, .back-arrow:focus { color: #fff; background: rgb(80 0 0 / 58%); }
        @media (max-width: 767.98px) {
            .topbar { height: auto; min-height: 82px; }
            .topbar nav { display: grid !important; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); height: auto; min-height: 82px; gap: .5rem !important; }
            .topbar nav > a { grid-column: 1; margin: 0; min-width: 0; }
            .topbar .page-title { display: block !important; position: static; grid-column: 2; max-width: 100%; min-width: 0; transform: none; white-space: normal; text-align: center; font-size: clamp(1rem, 3.5vw, 1.35rem); line-height: 1.05; }
            .topbar nav > div { grid-column: 3; justify-self: end; min-width: 0; }
            .account-toggle { width: auto; height: auto; justify-content: center; max-width: 100%; min-width: 0; padding: .375rem .75rem; }
            .account-toggle > .text-start, .account-toggle::after { display: none !important; }
            .provider-layout { height: auto; overflow: visible; }
            .provider-side { height: auto; overflow: visible; }
            .purchase-area { height: auto; min-height: 75vh; overflow: visible; }
            .purchase-scroll { overflow: visible; }
            .purchase-live-wrap { flex: 1 1 100%; width: 100%; max-width: none; min-width: 0; }
            .provider-info { position: static; }
            .provider-hero { min-height: 180px; }
        }
        @media (max-width: 575.98px) {
            .purchase-grid > [class*="col-"] { flex: 0 0 auto; width: 50%; }
            .purchase-image { height: 110px; }
            .purchase-card > .p-3 { padding: .65rem !important; }
            .purchase-card .small { font-size: .72rem; }
            .purchase-card .border.rounded-3 { gap: .4rem !important; padding: .4rem !important; }
            .purchase-card .border.rounded-3 img,
            .purchase-card .border.rounded-3 [aria-hidden="true"] { width: 42px !important; height: 42px !important; flex: 0 0 42px; }
        }
    </style>
    <link href="../assets/css/mobile-header.css?v=4" rel="stylesheet">
</head>
<body data-csrf-token="<?= proveedor_escape($_SESSION['csrf_token']) ?>">
<header class="topbar">
    <nav class="container-fluid px-3 px-lg-4 d-flex align-items-center justify-content-between gap-3 py-3">
        <a class="d-flex align-items-center gap-2 text-decoration-none" href="index.php" aria-label="Inicio CAP">
            <img src="../assets/img/cap-emblem.svg" width="40" height="44" alt="">
            <span class="cap-name">CAP</span>
        </a>
        <h1 class="page-title mb-0 d-none d-sm-block">Biblioteca de Proveedores</h1>
        <div class="dropdown account-menu">
            <button class="btn account-toggle dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Abrir menú de perfil">
                <span class="account-avatar"><?php if ($accountPhoto !== ''): ?><img src="<?= proveedor_escape($accountPhoto) ?>" alt=""><?php else: ?><?= proveedor_escape($accountInitial) ?><?php endif; ?></span>
                <span class="text-start text-truncate"><span class="d-block text-truncate"><?= proveedor_escape($accountName) ?></span><small class="d-block fw-normal text-secondary text-truncate"><?= proveedor_escape($accountCargo) ?></small></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="perfil.php">Ir a mi perfil</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><form action="../backend/controllers/logout.php" method="post" class="px-2"><input type="hidden" name="csrf_token" value="<?= proveedor_escape($_SESSION['csrf_token']) ?>"><button class="dropdown-item text-danger" type="submit">Cerrar sesión</button></form></li>
            </ul>
        </div>
    </nav>
</header>
<?php if ($loadError): ?>
    <main class="container py-5"><div class="alert alert-danger" role="alert">No se pudo encontrar este proveedor o cargar su información. <a href="index.php" class="alert-link">Volver al inicio</a>.</div></main>
    <?php include __DIR__ . '/partials/footer.php'; ?>
<?php else: ?>
<div class="container-fluid"><div class="row provider-layout">
    <aside class="col-md-4 col-lg-3 provider-side">
        <a class="back-arrow" href="index.php" aria-label="Volver al inicio"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg></a>
        <section class="provider-info">
            <div class="provider-hero">
                <?php if (proveedor_safe_media_url($provider['logo_url'] ?? '') !== ''): ?><img src="<?= proveedor_escape(proveedor_safe_media_url($provider['logo_url'])) ?>" alt="Logo de <?= $providerName ?>">
                <?php else: ?><span aria-hidden="true"><?= proveedor_escape(mb_strtoupper(mb_substr($provider['nombre_empresa'], 0, 1, 'UTF-8'), 'UTF-8')) ?></span><?php endif; ?>
            </div>
            <h2 class="h4 fw-bold mt-3 mb-0"><?= $providerName ?></h2>
            <dl class="mb-0">
                <dt>RTN</dt><dd><?= proveedor_escape($provider['rtn'] ?: 'No registrado') ?></dd>
                <dt>Categoría</dt><dd><?= proveedor_escape($provider['categoria']) ?></dd>
                <dt>Dirección de empresa</dt><dd><?= proveedor_escape($provider['direccion'] ?: 'No registrada') ?></dd>
                <dt>Fecha de ingreso</dt><dd><?= proveedor_escape(date('d/m/Y', strtotime($provider['fecha_registro']))) ?></dd>
                <?php if ($phones): ?><dt>Número de contacto</dt><?php foreach ($phones as $phone): ?>
                    <?php $localPhone = preg_replace('/\D+/', '', $phone['numero']); $phoneUrl = $phone['es_whatsapp'] ? 'https://wa.me/504' . $localPhone : 'tel:+504' . $localPhone; ?>
                    <dd><a href="<?= proveedor_escape($phoneUrl) ?>" target="_blank" rel="noopener noreferrer">+504 <?= proveedor_escape($phone['numero']) ?><?= $phone['es_whatsapp'] ? ' · WhatsApp' : '' ?></a></dd>
                <?php endforeach; endif; ?>
            </dl>
            <div class="rating-stars mt-4" aria-label="Calificación promedio">
                <?= str_repeat('★', (int) round($rating)) . str_repeat('☆', 5 - (int) round($rating)) ?>
                <span class="d-block small text-secondary"><?= $provider['promedio_estrellas'] === null ? 'Sin calificaciones' : number_format($rating, 1) . ' · ' . (int) $provider['total_calificaciones'] . ' reseñas' ?></span>
            </div>
        </section>
    </aside>
    <main class="col-md-8 col-lg-9 purchase-area">
        <div class="purchase-tools">
        <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
            <div><p class="text-uppercase small fw-bold mb-1">Proveedor: <?= $providerName ?></p><h2 class="purchase-heading mb-0">Historial de compras</h2></div>
            <div class="purchase-live-wrap">
            <form id="historyLiveSearch" data-mode="compras" data-endpoint="../backend/controllers/buscar-historial.php?tipo=compras" data-provider-id="<?= (int) $provider['id_proveedor'] ?>" action="proveedor.php" method="get">
                <input type="hidden" name="id" value="<?= (int) $provider['id_proveedor'] ?>">
                <label class="visually-hidden" for="q">Buscar en las compras</label>
                <div class="input-group"><input class="form-control" id="q" name="q" value="<?= proveedor_escape($search) ?>" placeholder="Buscar por producto, documento o empresa"><button class="btn btn-cap" type="submit">Buscar</button></div>
            </form>
            <div class="history-suggestions d-none" id="historySuggestions" role="listbox" aria-label="Coincidencias de compras"></div>
            </div>
        </div>
        </div>
        <div class="purchase-scroll">
        <?php if ($providerFlash !== ''): ?><div class="alert alert-success" role="status"><?= proveedor_escape($providerFlash) ?></div><?php endif; ?>
        <?php if (!$purchases): ?>
            <div class="purchase-empty"><h3 class="h5">Aún no hay compras registradas</h3><p class="text-secondary mb-0">Las facturas y recibos asociados a este proveedor aparecerán aquí.</p></div>
        <?php else: ?>
            <div class="row g-3 g-xl-4 purchase-grid">
                <?php foreach ($purchases as $purchase): ?>
                    <div class="col-12 col-sm-6 col-xl-4" data-live-search-item="<?= proveedor_escape(mb_strtolower(implode(' ', [$purchase['tipo_documento'], $purchase['numero_documento'] ?? '', $purchase['empresa'], $purchase['productos'] ?? '', date('d/m/Y', strtotime($purchase['fecha_documento']))]), 'UTF-8')) ?>">
                        <article class="purchase-card">
                            <div class="purchase-image">
                                <?php $primaryItem = $purchaseItems[(int) $purchase['id_documento']][0] ?? null; ?>
                                <?php if ($primaryItem && proveedor_safe_media_url($primaryItem['imagen_url'] ?? '') !== ''): ?><img src="<?= proveedor_escape(proveedor_safe_media_url($primaryItem['imagen_url'])) ?>" alt="Imagen de referencia: <?= proveedor_escape($primaryItem['descripcion']) ?>" loading="lazy">
                                <?php else: ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M6 2h9l5 5v15H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Z"></path><path d="M14 2v6h6M8 13h8M8 17h8"></path></svg><?php endif; ?>
                            </div>
                            <div class="p-3">
                                <?php $documentLabels = ['factura' => 'Factura', 'recibo' => 'Recibo', 'recibo_honorarios' => 'Recibo por honorarios', 'amazon' => 'Compra de Amazon', 'otro' => 'Otro comprobante']; ?>
                                <div class="d-flex justify-content-between gap-2 align-items-start"><h3 class="h6 fw-bold mb-1"><?= proveedor_escape($documentLabels[$purchase['tipo_documento']] ?? 'Comprobante') ?></h3><span class="badge text-bg-light"><?= proveedor_escape(strtoupper($purchase['metodo_ingreso'])) ?></span></div>
                                <p class="small text-secondary mb-2"><strong>Factura/recibo:</strong> <?= proveedor_escape($purchase['numero_documento'] ?: ($purchase['referencia_externa'] ?: 'No indicado')) ?><br><strong>Fecha:</strong> <?= proveedor_escape(date('d/m/Y', strtotime($purchase['fecha_documento']))) ?></p>
                                <?php if (!empty($purchase['nombre_emisor']) && $purchase['nombre_emisor'] !== $provider['nombre_empresa']): ?><p class="small mb-2">Vendedor indicado: <?= proveedor_escape($purchase['nombre_emisor']) ?></p><?php endif; ?>
                                <p class="small mb-2"><strong>Empresa:</strong> <?= proveedor_escape($purchase['empresa']) ?></p>
                                <?php foreach ($purchaseItems[(int) $purchase['id_documento']] ?? [] as $item): ?>
                                    <div class="border rounded-3 p-2 mb-2 d-flex gap-2 align-items-center">
                                        <?php if (proveedor_safe_media_url($item['imagen_url'] ?? '') !== ''): ?><img src="<?= proveedor_escape(proveedor_safe_media_url($item['imagen_url'])) ?>" alt="Imagen de referencia: <?= proveedor_escape($item['descripcion']) ?>" loading="lazy" style="width:64px;height:64px;object-fit:contain;background:#f7f7f7;border-radius:.5rem">
                                        <?php else: ?><div aria-hidden="true" class="d-grid place-items-center text-secondary" style="width:64px;height:64px;background:#f7f7f7;border-radius:.5rem">📦</div><?php endif; ?>
                                        <div class="small"><strong><?= proveedor_escape($item['descripcion']) ?></strong><?php if ($item['cantidad'] !== null): ?><br>Cantidad: <?= proveedor_escape($item['cantidad']) ?><?php endif; ?></div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($purchaseItems[(int) $purchase['id_documento']])): ?><p class="small text-secondary mb-2"><strong>Producto o servicio:</strong> <?= proveedor_escape($purchase['productos'] ?? 'Sin detalle') ?></p><?php endif; ?>
                                <div class="d-flex justify-content-between border-top pt-2 mt-2"><span class="fw-semibold">Total</span><strong><?= proveedor_escape($purchase['moneda']) ?> <?= number_format((float) $purchase['total'], 2) ?></strong></div>
                                <?php if (proveedor_safe_media_url($purchase['imagen_url'] ?? '') !== ''): ?><a class="btn btn-outline-danger btn-sm mt-3" href="<?= proveedor_escape(proveedor_safe_media_url($purchase['imagen_url'])) ?>" target="_blank" rel="noopener noreferrer">Ver documento</a><?php endif; ?>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (!$loadError && $totalPages > 1): ?>
            <nav class="mt-4" id="purchasePagination" aria-label="Paginación del historial de compras"><ul class="pagination justify-content-center flex-wrap">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= proveedor_escape(proveedor_page_url($page - 1)) ?>">Anterior</a></li>
                <?php for ($number = max(1, $page - 2); $number <= min($totalPages, $page + 2); $number++): ?><li class="page-item <?= $number === $page ? 'active' : '' ?>"><a class="page-link" href="<?= proveedor_escape(proveedor_page_url($number)) ?>"><?= $number ?></a></li><?php endfor; ?>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= proveedor_escape(proveedor_page_url($page + 1)) ?>">Siguiente</a></li>
            </ul><p class="small text-center text-secondary">Mostrando <?= count($purchases) ?> de <?= $totalPurchases ?> compras</p></nav>
        <?php endif; ?>
        <?php include __DIR__ . '/partials/footer.php'; ?>
        </div>
    </main>
</div></div>
<?php endif; ?>
<?php if ($canRatePurchase && $provider): ?>
<div class="modal fade" id="ratePurchaseModal" tabindex="-1" aria-labelledby="ratePurchaseTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post" action="../backend/controllers/calificar_proveedor.php"><div class="modal-header"><h2 class="modal-title h5" id="ratePurchaseTitle">¿Cómo fue el producto o servicio?</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button></div><div class="modal-body"><input type="hidden" name="csrf_token" value="<?= proveedor_escape($_SESSION['csrf_token']) ?>"><input type="hidden" name="id_documento" value="<?= (int) $rateDocumentId ?>"><input type="hidden" name="id_proveedor" value="<?= (int) $provider['id_proveedor'] ?>"><label class="form-label" for="ratingStars">Calificación</label><select class="form-select" id="ratingStars" name="estrellas" required><option value="">Elige de 1 a 5</option><option value="1">1 · Muy mala</option><option value="2">2 · Mala</option><option value="3">3 · Regular</option><option value="4">4 · Buena</option><option value="5">5 · Excelente</option></select><label class="form-label mt-3" for="ratingComment">Comentario (opcional)</label><textarea class="form-control" id="ratingComment" name="comentario" maxlength="255" rows="3"></textarea></div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Ahora no</button><button class="btn btn-danger" type="submit">Guardar calificación</button></div></form></div></div></div><script>window.addEventListener('load',()=>{const m=document.getElementById('ratePurchaseModal');bootstrap.Modal.getOrCreateInstance(m).show();m.addEventListener('hidden.bs.modal',()=>{const u=new URL(location.href);u.searchParams.delete('calificar');history.replaceState({},'',u);});});</script>
<?php endif; ?>
<script src="../assets/js/renovar-sesion.js" defer></script>
<script src="../assets/js/buscar-historial.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>
