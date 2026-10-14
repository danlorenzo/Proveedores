<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/config/ocr_auth.php';
apply_security_headers();
start_app_session();
$token = ocr_require_user();
$initialMode = isset($_GET['modo']) && $_GET['modo'] === 'manual' ? 'manual' : 'ocr';
$embeddedMode = isset($_GET['embedded']) && $_GET['embedded'] === '1';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$companies = [];
$providers = [];
$loadError = '';
try {
    $pdo = database();
    $companies = $pdo->query('SELECT id_empresa_grupo, nombre, rtn FROM empresa_grupo ORDER BY nombre')->fetchAll();
    $providers = $pdo->query('SELECT id_proveedor, nombre_empresa, rtn, id_categoria FROM proveedor ORDER BY nombre_empresa')->fetchAll();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $loadError = 'No se pudieron cargar los catálogos. Revisa la conexión con la base de datos.';
}
$flash = $_SESSION['documento_flash'] ?? '';
unset($_SESSION['documento_flash']);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#c62828">
    <title>Ingresar compra | Biblioteca de Proveedores CAP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --cap-red: #c62828; --cap-deep: #a71920; }
        body { min-height: 100vh; background: #f5f6f8; color: #262626; }
        .topbar { background: #fff; border-bottom: 1px solid #e6e6e8; }
        .cap-mark { width: 42px; height: 46px; }
        .cap-name { color: var(--cap-red); font-size: 1.6rem; font-weight: 900; letter-spacing: .08em; }
        .page-title { color: var(--cap-red); font-weight: 800; }
        .entry-shell { max-width: 1050px; margin: 2rem auto; }
        .entry-card { border: 1px solid #e7e7eb; border-radius: 1rem; box-shadow: 0 12px 35px rgb(20 20 20 / 6%); }
        .method-card { min-height: 145px; border: 2px solid #e5e5e8; border-radius: .9rem; transition: .15s ease; }
        .method-card.active { border-color: var(--cap-red); background: #fff8f8; }
        .btn-cap { border: 0; color: #fff; background: var(--cap-red); font-weight: 650; }
        .btn-cap:hover { color: #fff; background: var(--cap-deep); }
        .ocr-progress-circle { position: relative; width: 7.5rem; height: 7.5rem; margin: .25rem auto 1.25rem; }
        .ocr-progress-circle svg { display: block; width: 100%; height: 100%; transform: rotate(-90deg); }
        .ocr-progress-circle circle { fill: none; stroke-width: 6; }
        .ocr-progress-circle .circle-track { stroke: #f4d1d3; }
        .ocr-progress-circle .circle-value { stroke: #c62828; stroke-linecap: round; stroke-dasharray: 276.46; stroke-dashoffset: 276.46; transition: stroke-dashoffset .2s ease; }
        .ocr-progress-value { position: absolute; inset: 0; display: grid; place-items: center; color: #a71920; font-size: 1.2rem; font-weight: 700; }
        .field-warning { color: #795500; }
        .source-note { font-size: .86rem; color: #63666a; }
        .item-table { min-width: 850px; }
        body.embedded-entry { min-height: 100%; background: transparent; }
        body.embedded-entry > header, body.embedded-entry > .site-footer { display: none !important; }
        body.embedded-entry .entry-shell { max-width: none; min-height: 100vh; margin: 0; padding: 0; display: flex; align-items: center; justify-content: center; }
        body.embedded-entry .entry-card { width: 100%; border: 0; border-radius: 0; background: transparent; box-shadow: none; }
        body.embedded-entry .entry-card .card-body { padding: 0 !important; background: transparent; }
        body.embedded-entry .entry-intro { display: none; }
        body.embedded-entry #ocrPanel { display: none !important; }
        body.embedded-entry .modal { position: static !important; width: 100%; height: auto; overflow: visible; background: transparent; }
        body.embedded-entry .modal-dialog { width: 100%; max-width: 100%; margin: 0 auto; transform: none !important; }
        body.embedded-entry .modal-content { max-height: min(78vh, 850px); border-radius: .75rem; }
        body.embedded-entry .modal-backdrop { display: none !important; }
        body.embedded-entry #ocrLoadingModal .modal-dialog { min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        body.embedded-entry #ocrLoadingModal .modal-content { width: min(100%, 500px); max-height: none; }
        body.embedded-entry #reviewModal .modal-dialog { width: min(1100px, 96vw); height: min(90dvh, 950px); }
        body.embedded-entry #reviewModal .modal-content { height: 100%; }
        body.embedded-entry #reviewModal .modal-body { overflow-y: auto; }
        @media (max-width: 767.98px) {
            .topbar nav { display: grid !important; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); gap: .5rem !important; }
            .topbar nav > a:first-child { grid-column: 1; min-width: 0; }
            .topbar nav > h1 { grid-column: 2; max-width: 100%; min-width: 0; text-align: center; font-size: clamp(1rem, 3.5vw, 1.35rem); line-height: 1.05; }
            .topbar nav > a:last-child { grid-column: 3; justify-self: end; white-space: nowrap; }
            .entry-shell { margin-top: 1rem; }
        }
    </style>
    <link href="../assets/css/mobile-header.css?v=4" rel="stylesheet">
</head>
<body<?= $embeddedMode ? ' class="embedded-entry"' : '' ?>>
<header class="topbar">
    <nav class="container d-flex align-items-center justify-content-between py-3">
        <a class="d-flex align-items-center gap-2 text-decoration-none" href="index.php" aria-label="Volver al inicio">
            <img class="cap-mark" src="../assets/img/cap-emblem.svg" alt="">
            <span class="cap-name">CAP</span>
        </a>
        <h1 class="h4 page-title mb-0">Ingresar compra</h1>
        <a class="btn btn-outline-secondary" href="index.php">Volver</a>
    </nav>
</header>
<?php if ($embeddedMode): ?><div class="alert alert-warning d-none m-3" id="embeddedOcrError" role="alert"></div><?php endif; ?>
<main class="container entry-shell">
    <?php if ($flash !== ''): ?><div class="alert alert-warning" role="alert"><?= htmlspecialchars((string) $flash, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($loadError !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?></div><?php else: ?>
    <section class="card entry-card">
        <div class="card-body p-3 p-md-4 p-lg-5">
            <p class="entry-intro text-secondary mb-4">Puedes escanear el comprobante para sugerir los datos o capturarlos manualmente. Revisa toda la información antes de guardar.</p>
            <section id="ocrPanel" class="border rounded-3 p-3 p-md-4 mb-4 <?= $initialMode === 'manual' ? 'd-none' : '' ?>" aria-labelledby="uploadTitle">
                <h2 class="h5 fw-bold" id="uploadTitle">Selecciona el comprobante</h2>
                <p class="small text-secondary">Admite foto JPG/JPEG, PNG o WEBP y archivos PDF. En celular también puedes tomar una foto con la cámara.</p>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <button class="btn btn-outline-danger" type="button" id="fileButton">Tomar foto o elegir archivo</button>
                    <button class="btn btn-cap d-none" type="button" id="analyzeButton">Analizar documento</button>
                    <span class="small text-secondary" id="fileName">Ningún archivo seleccionado</span>
                </div>
                <input class="visually-hidden" type="file" id="documentFile" name="documento_archivo" accept="image/jpeg,image/png,image/webp,application/pdf" form="documentForm">
                <div class="alert alert-warning mt-3 d-none mb-0" id="ocrError" role="alert"></div>
                <p class="source-note mt-3 mb-0">El motor y el modelo de español se cargan en el navegador la primera vez; la imagen se procesa en este dispositivo. PDF de varias páginas: se analizan hasta 5 páginas.</p>
            </section>

            <form id="documentForm" action="../backend/controllers/guardar_documento.php" method="post" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <?php if ($embeddedMode): ?><input type="hidden" name="desde_index_modal" value="1"><?php endif; ?>
                <input type="hidden" name="metodo_ingreso" id="methodInput" value="ocr">
                <input type="hidden" name="texto_ocr" id="rawOcrText">

                <div class="modal fade" id="ocrLoadingModal" tabindex="-1" aria-labelledby="ocrLoadingTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
                    <div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow">
                        <div class="modal-body text-center p-4 p-md-5">
                            <div class="ocr-progress-circle" role="progressbar" aria-label="Progreso de lectura del comprobante" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                                <svg viewBox="0 0 100 100" aria-hidden="true"><circle class="circle-track" cx="50" cy="50" r="44"></circle><circle class="circle-value" id="ocrCircleValue" cx="50" cy="50" r="44"></circle></svg>
                                <span class="ocr-progress-value" id="ocrPercent">0%</span>
                            </div>
                            <h2 class="h5 fw-bold" id="ocrLoadingTitle">Leyendo comprobante</h2>
                            <p class="text-secondary mb-0" id="ocrStatus" aria-live="polite">Preparando lector OCR…</p>
                        </div>
                    </div></div>
                </div>

                <div class="modal fade" id="reviewModal" tabindex="-1" aria-labelledby="reviewModalTitle" aria-hidden="true">
                  <div class="modal-dialog modal-xl modal-dialog-scrollable">
                   <div class="modal-content">
                    <div class="modal-header"><div><h2 class="modal-title h5 fw-bold" id="reviewModalTitle">Revisa y confirma la compra</h2><p class="small text-secondary mb-0">Edita los campos que el OCR no leyó correctamente.</p></div><button type="button" class="btn-close" id="reviewCloseButton" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                    <div class="modal-body">
                <section id="reviewFields" class="d-none">
                    <div class="alert alert-info" id="reviewNotice" role="status">Los valores detectados son sugerencias. Confírmalos contra el comprobante.</div>
                    <h2 class="h5 fw-bold mb-3">Datos de la compra</h2>
                    <div class="row g-3">
                        <div class="col-12"><h3 class="h6 fw-bold mb-0">Datos del comprobante fiscal</h3><p class="small text-secondary mb-0">Número, CAI y fecha que aparecen impresos en la factura o recibo.</p></div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="tipoDocumento">Tipo de comprobante *</label>
                            <select class="form-select" id="tipoDocumento" name="tipo_documento" required>
                                <option value="factura">Factura</option>
                                <option value="recibo">Recibo</option>
                                <option value="recibo_honorarios">Recibo por honorarios</option>
                                <option value="amazon">Compra de Amazon</option>
                                <option value="otro">Otro comprobante</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="numeroDocumento">Número de factura/recibo</label>
                            <input class="form-control" id="numeroDocumento" name="numero_documento" maxlength="25" autocomplete="off">
                            <div class="form-text">Para Amazon puedes guardar el número de pedido en Referencia externa.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="referencia">Referencia externa / pedido / casillero</label>
                            <input class="form-control" id="referencia" name="referencia_externa" maxlength="80" autocomplete="off" placeholder="Pedido de Amazon, tracking o referencia del casillero">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="cai">CAI</label>
                            <input class="form-control" id="cai" name="cai" maxlength="40" autocomplete="off">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="fechaDocumento">Fecha del comprobante *</label>
                            <input class="form-control" type="date" id="fechaDocumento" name="fecha_documento" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="moneda">Moneda *</label>
                            <select class="form-select" id="moneda" name="moneda"><option value="HNL">HNL · Lempira</option><option value="USD">USD · Dólar estadounidense</option><option value="EUR">EUR · Euro</option><option value="CAD">CAD · Dólar canadiense</option><option value="OTR">Otra / no identificada</option></select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="atendidoPor">Atendido por (opcional)</label>
                            <input class="form-control" id="atendidoPor" name="atendido_por" maxlength="100">
                            <div class="form-text">Déjalo vacío si la factura no indica el nombre.</div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <h2 class="h5 fw-bold mb-3">Proveedor / emisor</h2>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="emisorNombre">Proveedor existente / nombre del proveedor *</label>
                            <input class="form-control" id="emisorNombre" name="nombre_emisor" list="proveedoresRegistrados" maxlength="150" autocomplete="off" required>
                            <datalist id="proveedoresRegistrados">
                                <?php foreach ($providers as $provider): ?>
                                    <option value="<?= htmlspecialchars($provider['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <div class="form-text">El OCR propone el nombre. Al guardar, el sistema buscará el proveedor en la base de datos y creará su ficha si aún no existe.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="rtnEmisor">RTN del emisor (Honduras)</label>
                            <input class="form-control" id="rtnEmisor" name="rtn_emisor" inputmode="numeric" maxlength="14" autocomplete="off">
                        </div>
                        <div class="col-md-6 d-flex align-items-end"><p class="small text-secondary mb-2" id="providerMatchMessage" aria-live="polite">Si es un proveedor nuevo, su ficha se guardará junto con la compra y quedará como “Sin clasificar”.</p></div>
                        <div class="col-12"><div class="row g-3" id="newProviderFields">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="phone">Teléfonos públicos del proveedor</label>
                            <input class="form-control" id="phone" name="telefono_proveedor" inputmode="tel" maxlength="64" placeholder="2238-9554, 9921-3321">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="emailProveedor">Correo electrónico del proveedor</label>
                            <input class="form-control" id="emailProveedor" type="email" name="correo_proveedor" maxlength="120" autocomplete="email">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <button class="btn btn-outline-secondary" type="button" id="publicSearchButton">Buscar datos públicos del proveedor</button>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="phoneSource">Fuente del teléfono (si se consultó en internet)</label>
                            <input class="form-control" type="url" id="phoneSource" name="fuente_telefono" maxlength="500" placeholder="https://sitio-oficial-del-proveedor/...">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold" for="direccionProveedor">Dirección pública del proveedor</label>
                            <input class="form-control" id="direccionProveedor" name="direccion_proveedor" maxlength="255">
                        </div>
                        </div></div>
                    </div>

                    <hr class="my-4">
                    <h2 class="h5 fw-bold mb-3">Empresa CAP que realizó la compra</h2>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="rtnCliente">RTN del cliente (si aparece)</label>
                            <input class="form-control" id="rtnCliente" name="rtn_cliente" inputmode="numeric" maxlength="14" autocomplete="off">
                            <div class="form-text" id="companyMatchMessage">Si el comprobante no muestra el RTN —como puede ocurrir en compras por casillero— selecciona manualmente la empresa CAP que realizó la compra.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="empresaGrupo">Empresa CAP *</label>
                            <select class="form-select" id="empresaGrupo" name="id_empresa_grupo" required>
                                <option value="">Seleccionar empresa CAP</option>
                                <?php foreach ($companies as $company): ?><option value="<?= (int) $company['id_empresa_grupo'] ?>" data-rtn="<?= htmlspecialchars($company['rtn'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($company['nombre'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($company['rtn'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <hr class="my-4">
                    <h2 class="h5 fw-bold mb-3">Montos e impuestos</h2>
                    <p class="small text-secondary">Se guardan los importes tal como aparecen en el comprobante. El sistema no calcula ni completa importes faltantes.</p>
                    <div class="row g-3">
                        <div class="col-6 col-md-3"><label class="form-label" for="subtotal">Subtotal</label><input class="form-control money-field" type="number" min="0" step="0.01" id="subtotal" name="subtotal" placeholder="No indicado"></div>
                        <div class="col-6 col-md-3"><label class="form-label" for="exento">Exento</label><input class="form-control money-field" type="number" min="0" step="0.01" id="exento" name="subtotal_exento" placeholder="No indicado"></div>
                        <div class="col-6 col-md-3"><label class="form-label" for="exonerado">Exonerado</label><input class="form-control money-field" type="number" min="0" step="0.01" id="exonerado" name="subtotal_exonerado" placeholder="No indicado"></div>
                        <div class="col-6 col-md-3"><label class="form-label" for="gravado15">Gravado 15%</label><input class="form-control money-field" type="number" min="0" step="0.01" id="gravado15" name="subtotal_gravado_15" placeholder="No indicado"></div>
                        <div class="col-6 col-md-3"><label class="form-label" for="isv15">ISV (15%)</label><input class="form-control money-field" type="number" min="0" step="0.01" id="isv15" name="isv" placeholder="No indicado"></div>
                        <div class="col-6 col-md-3"><label class="form-label" for="descuento">Descuento</label><input class="form-control money-field" type="number" min="0" step="0.01" id="descuento" name="descuento_total" placeholder="No indicado"></div>
                        <div class="col-6 col-md-3"><label class="form-label" for="envio">Envío</label><input class="form-control money-field" type="number" min="0" step="0.01" id="envio" name="costo_envio" placeholder="No indicado"></div>
                        <div class="col-6 col-md-3"><label class="form-label" for="isr">Retención ISR</label><input class="form-control money-field" type="number" min="0" step="0.01" id="isr" name="retencion_isr" placeholder="No indicado"></div>
                        <div class="col-12 col-md-6"><label class="form-label fw-bold" for="total">Total pagado *</label><input class="form-control form-control-lg" type="number" min="0" step="0.01" id="total" name="total" required></div>
                    </div>
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-4 mb-2">
                        <div><h2 class="h5 fw-bold mb-1">Artículos comprados</h2><p class="small text-secondary mb-0">Tabla editable. Cantidad, precio y descuento se dejan vacíos si no aparecen en la factura.</p></div>
                        <button class="btn btn-outline-danger btn-sm" type="button" id="addItemRow">Agregar detalle</button>
                    </div>
                    <div class="table-responsive border rounded-3"><table class="table table-bordered table-striped align-middle mb-0 item-table"><thead class="table-light"><tr><th>Artículo o servicio</th><th>Cantidad</th><th>Precio unitario</th><th>Descuento</th><th>Imagen de referencia</th><th>Acción</th></tr></thead><tbody id="itemRows"><tr class="item-row"><td><input class="form-control item-description" name="detalle_descripcion[]" maxlength="255" aria-label="Artículo o servicio"></td><td><input class="form-control" name="detalle_cantidad[]" type="number" min="0.01" step="0.01" aria-label="Cantidad (si aparece)"></td><td><input class="form-control" name="detalle_precio[]" type="number" min="0" step="0.01" aria-label="Precio unitario (si aparece)"></td><td><input class="form-control" name="detalle_descuento[]" type="number" min="0" step="0.01" aria-label="Descuento (si aparece)"></td><td><div class="input-group"><input class="form-control item-image-url" name="detalle_imagen_url[]" type="url" maxlength="255" placeholder="URL HTTPS" aria-label="Imagen de referencia"><button class="btn btn-outline-secondary find-product-image" type="button">Buscar</button></div></td><td><button class="btn btn-link text-danger btn-sm remove-item-row" type="button">Quitar</button></td></tr></tbody></table></div>
                    <div class="mt-4">
                        <label class="form-label fw-semibold" for="rawTextPreview">Texto leído por OCR (opcional, editable)</label>
                        <textarea class="form-control" id="rawTextPreview" rows="5" maxlength="60000" placeholder="Aquí aparece el texto reconocido para que puedas verificarlo."></textarea>
                    </div>
                    <div class="alert alert-warning mt-4 mb-0 field-warning"><strong>Importante:</strong> los datos detectados son sugerencias; el OCR puede equivocarse, sobre todo con letra manuscrita, sellos o fotos borrosas. Verifica los datos y selecciona la empresa CAP que realizó la compra. El RTN del cliente solo se requiere si aparece en el comprobante.</div>
                </section>
                    </div>
                    <div class="modal-footer"><a class="btn btn-outline-secondary me-auto" id="reviewCancelButton" href="index.php">Cancelar</a><button class="btn btn-cap btn-lg d-none" id="saveButton" type="submit">Confirmar y guardar compra</button></div>
                   </div>
                  </div>
                </div>
            </form>
        </div>
    </section>
    <?php endif; ?>
</main>
<script>
window.CAP_OCR_CONTEXT = {
  initialMode: <?= json_encode($initialMode) ?>,
  companies: <?= json_encode(array_map(static fn(array $company): array => ['id' => (int) $company['id_empresa_grupo'], 'name' => $company['nombre'], 'rtn' => $company['rtn']], $companies), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  providers: <?= json_encode(array_map(static fn(array $provider): array => ['id' => (int) $provider['id_proveedor'], 'name' => $provider['nombre_empresa'], 'rtn' => $provider['rtn'], 'category' => (int) $provider['id_categoria']], $providers), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};
</script>
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5.0.4/dist/tesseract.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/ingresar-documento.js?v=ocr-autoproveedor-20261014-1" defer></script>
</body>
</html>
