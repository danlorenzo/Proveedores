<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/config/ocr_auth.php';
apply_security_headers();
start_app_session();
$token = ocr_require_user();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$companies = [];
$categories = [];
$providers = [];
$loadError = '';
try {
    $pdo = database();
    $companies = $pdo->query('SELECT id_empresa_grupo, nombre, rtn FROM empresa_grupo ORDER BY nombre')->fetchAll();
    $categories = $pdo->query('SELECT id_categoria, nombre FROM categoria ORDER BY nombre')->fetchAll();
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
    <title>Ingresar compra | Proveedores CAP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --cap-red: #c62828; --cap-deep: #a71920; }
        body { min-height: 100vh; background: #f5f6f8; color: #262626; }
        .topbar { background: #fff; border-bottom: 1px solid #e6e6e8; }
        .cap-mark { width: 42px; height: 46px; }
        .cap-name { color: var(--cap-red); font-size: 1.4rem; font-weight: 900; letter-spacing: .08em; }
        .page-title { color: var(--cap-red); font-weight: 800; }
        .entry-shell { max-width: 1050px; margin: 2rem auto; }
        .entry-card { border: 1px solid #e7e7eb; border-radius: 1rem; box-shadow: 0 12px 35px rgb(20 20 20 / 6%); }
        .method-card { min-height: 145px; border: 2px solid #e5e5e8; border-radius: .9rem; transition: .15s ease; }
        .method-card.active { border-color: var(--cap-red); background: #fff8f8; }
        .btn-cap { border: 0; color: #fff; background: var(--cap-red); font-weight: 650; }
        .btn-cap:hover { color: #fff; background: var(--cap-deep); }
        .camera-option { display: none; }
        .progress { height: .6rem; }
        .field-warning { color: #795500; }
        .source-note { font-size: .86rem; color: #63666a; }
        @media (pointer: coarse) { .camera-option { display: inline-flex; } }
    </style>
</head>
<body>
<header class="topbar">
    <nav class="container d-flex align-items-center justify-content-between py-3">
        <a class="d-flex align-items-center gap-2 text-decoration-none" href="index.php" aria-label="Volver al inicio">
            <svg class="cap-mark" viewBox="0 0 48 52" aria-hidden="true"><polygon points="24,2 44,13 44,39 24,50 4,39 4,13" fill="#fff" stroke="#c62828" stroke-width="4" stroke-linejoin="round"/></svg>
            <span class="cap-name">CAP</span>
        </a>
        <h1 class="h4 page-title mb-0">Ingresar compra</h1>
        <a class="btn btn-outline-secondary" href="index.php">Volver</a>
    </nav>
</header>
<main class="container entry-shell">
    <?php if ($flash !== ''): ?><div class="alert alert-warning" role="alert"><?= htmlspecialchars((string) $flash, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($loadError !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?></div><?php else: ?>
    <section class="card entry-card">
        <div class="card-body p-3 p-md-4 p-lg-5">
            <p class="text-secondary mb-4">Puedes escanear el comprobante para sugerir los datos o capturarlos manualmente. Revisa toda la información antes de guardar.</p>
            <div class="row g-3 mb-4" id="methodPicker">
                <div class="col-md-6">
                    <button class="method-card active w-100 p-3 text-start" type="button" data-entry-mode="ocr">
                        <span class="d-block fs-3 mb-2" aria-hidden="true">▧</span>
                        <strong>Analizar foto o archivo</strong>
                        <span class="d-block text-secondary small mt-1">OCR en español · no se envía la imagen a un servicio de OCR externo.</span>
                    </button>
                </div>
                <div class="col-md-6">
                    <button class="method-card w-100 p-3 text-start" type="button" data-entry-mode="manual">
                        <span class="d-block fs-3 mb-2" aria-hidden="true">✎</span>
                        <strong>Ingresar datos manualmente</strong>
                        <span class="d-block text-secondary small mt-1">Disponible en celular y computadora.</span>
                    </button>
                </div>
            </div>

            <section id="ocrPanel" class="border rounded-3 p-3 p-md-4 mb-4" aria-labelledby="uploadTitle">
                <h2 class="h5 fw-bold" id="uploadTitle">Selecciona el comprobante</h2>
                <p class="small text-secondary">Admite foto JPG/JPEG, PNG o WEBP y archivos PDF. En celular también puedes tomar una foto con la cámara.</p>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <button class="btn btn-outline-danger camera-option" type="button" id="cameraButton">Tomar foto</button>
                    <button class="btn btn-outline-danger" type="button" id="fileButton">Elegir foto o PDF</button>
                    <button class="btn btn-cap d-none" type="button" id="analyzeButton">Analizar documento</button>
                    <span class="small text-secondary" id="fileName">Ningún archivo seleccionado</span>
                </div>
                <input class="visually-hidden" type="file" id="documentFile" name="documento_archivo" accept="image/jpeg,image/png,image/webp,application/pdf" form="documentForm">
                <input class="visually-hidden" type="file" id="cameraFile" accept="image/*" capture="environment">
                <div class="mt-3 d-none" id="ocrProgressBlock" aria-live="polite">
                    <div class="d-flex justify-content-between small mb-1"><span id="ocrStatus">Preparando lector OCR…</span><span id="ocrPercent">0%</span></div>
                    <div class="progress" role="progressbar" aria-label="Avance del análisis"><div class="progress-bar bg-danger" id="ocrProgress" style="width:0%"></div></div>
                </div>
                <div class="alert alert-warning mt-3 d-none mb-0" id="ocrError" role="alert"></div>
                <p class="source-note mt-3 mb-0">El motor y el modelo de español se cargan en el navegador la primera vez; la imagen se procesa en este dispositivo. PDF de varias páginas: se analizan hasta 5 páginas.</p>
            </section>

            <form id="documentForm" action="../backend/controllers/guardar_documento.php" method="post" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="metodo_ingreso" id="methodInput" value="ocr">
                <input type="hidden" name="texto_ocr" id="rawOcrText">

                <section id="reviewFields" class="d-none">
                    <div class="alert alert-info" id="reviewNotice" role="status">Los valores detectados son sugerencias. Confírmalos contra el comprobante.</div>
                    <h2 class="h5 fw-bold mb-3">Datos de la compra</h2>
                    <div class="row g-3">
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
                            <label class="form-label fw-semibold" for="atendidoPor">Atendido por</label>
                            <input class="form-control" id="atendidoPor" name="atendido_por" maxlength="100">
                        </div>
                    </div>

                    <hr class="my-4">
                    <h2 class="h5 fw-bold mb-3">Proveedor / emisor</h2>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="proveedorExistente">Proveedor existente</label>
                            <select class="form-select" id="proveedorExistente" name="id_proveedor">
                                <option value="">No vincular / proveedor nuevo</option>
                                <?php foreach ($providers as $provider): ?>
                                    <option value="<?= (int) $provider['id_proveedor'] ?>" data-name="<?= htmlspecialchars($provider['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?>" data-rtn="<?= htmlspecialchars($provider['rtn'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-category="<?= (int) $provider['id_categoria'] ?>"><?= htmlspecialchars($provider['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?> · RTN <?= htmlspecialchars($provider['rtn'] ?? 'no indicado', ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="emisorNombre">Nombre del emisor / vendedor *</label>
                            <input class="form-control" id="emisorNombre" name="nombre_emisor" maxlength="150" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="rtnEmisor">RTN del emisor (Honduras)</label>
                            <input class="form-control" id="rtnEmisor" name="rtn_emisor" inputmode="numeric" maxlength="14" autocomplete="off">
                            <div class="form-text" id="providerMatchMessage">Si se reconoce un RTN ya registrado, se vinculará a ese proveedor.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="categoriaProveedor">Categoría si es un proveedor nuevo</label>
                            <select class="form-select" id="categoriaProveedor" name="id_categoria">
                                <option value="">Seleccionar categoría</option>
                                <?php foreach ($categories as $category): ?><option value="<?= (int) $category['id_categoria'] ?>"><?= htmlspecialchars($category['nombre'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="phone">Teléfono público del proveedor</label>
                            <input class="form-control" id="phone" name="telefono_proveedor" inputmode="numeric" maxlength="8" pattern="[23789][0-9]{7}">
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
                    <p class="small text-secondary">Deja en cero los renglones que no aparezcan en el documento. Confirma que el total coincida.</p>
                    <div class="row g-3">
                        <div class="col-6 col-md-3"><label class="form-label" for="subtotal">Subtotal *</label><input class="form-control money-field" type="number" min="0" step="0.01" id="subtotal" name="subtotal" value="0.00" required></div>
                        <div class="col-6 col-md-3"><label class="form-label" for="exento">Exento</label><input class="form-control money-field" type="number" min="0" step="0.01" id="exento" name="subtotal_exento" value="0.00"></div>
                        <div class="col-6 col-md-3"><label class="form-label" for="exonerado">Exonerado</label><input class="form-control money-field" type="number" min="0" step="0.01" id="exonerado" name="subtotal_exonerado" value="0.00"></div>
                        <div class="col-6 col-md-3"><label class="form-label" for="gravado15">Gravado 15%</label><input class="form-control money-field" type="number" min="0" step="0.01" id="gravado15" name="subtotal_gravado_15" value="0.00"></div>
                        <div class="col-6 col-md-3"><label class="form-label" for="isv15">ISV (15%)</label><input class="form-control money-field" type="number" min="0" step="0.01" id="isv15" name="isv" value="0.00"></div>
                        <div class="col-6 col-md-3"><label class="form-label" for="descuento">Descuento</label><input class="form-control money-field" type="number" min="0" step="0.01" id="descuento" name="descuento_total" value="0.00"></div>
                        <div class="col-6 col-md-3"><label class="form-label" for="envio">Envío</label><input class="form-control money-field" type="number" min="0" step="0.01" id="envio" name="costo_envio" value="0.00"></div>
                        <div class="col-6 col-md-3"><label class="form-label" for="isr">Retención ISR</label><input class="form-control money-field" type="number" min="0" step="0.01" id="isr" name="retencion_isr" value="0.00"></div>
                        <div class="col-12 col-md-6"><label class="form-label fw-bold" for="total">Total pagado *</label><input class="form-control form-control-lg" type="number" min="0" step="0.01" id="total" name="total" value="0.00" required></div>
                    </div>
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-4 mb-2">
                        <div><h2 class="h5 fw-bold mb-1">Productos o servicios</h2><p class="small text-secondary mb-0">Confirma las líneas de la factura; el OCR deja esta sección vacía si no distingue cada renglón con claridad.</p></div>
                        <button class="btn btn-outline-danger btn-sm" type="button" id="addItemRow">Agregar detalle</button>
                    </div>
                    <div id="itemRows" class="vstack gap-2">
                        <div class="row g-2 align-items-end item-row">
                            <div class="col-12 col-md-6"><label class="form-label small">Descripción</label><input class="form-control" name="detalle_descripcion[]" maxlength="255"></div>
                            <div class="col-4 col-md-2"><label class="form-label small">Cantidad</label><input class="form-control" name="detalle_cantidad[]" type="number" min="0.01" step="0.01"></div>
                            <div class="col-4 col-md-2"><label class="form-label small">Precio unitario</label><input class="form-control" name="detalle_precio[]" type="number" min="0" step="0.01"></div>
                            <div class="col-4 col-md-2"><label class="form-label small">Descuento</label><input class="form-control" name="detalle_descuento[]" type="number" min="0" step="0.01" value="0"><button class="btn btn-link text-danger btn-sm px-0 remove-item-row" type="button">Quitar</button></div>
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="form-label fw-semibold" for="rawTextPreview">Texto leído por OCR (opcional, editable)</label>
                        <textarea class="form-control" id="rawTextPreview" rows="5" maxlength="60000" placeholder="Aquí aparece el texto reconocido para que puedas verificarlo."></textarea>
                    </div>
                    <div class="alert alert-warning mt-4 mb-0 field-warning"><strong>Importante:</strong> los datos detectados son sugerencias; el OCR puede equivocarse, sobre todo con letra manuscrita, sellos o fotos borrosas. Verifica los datos y selecciona la empresa CAP que realizó la compra. El RTN del cliente solo se requiere si aparece en el comprobante.</div>
                </section>
                <div class="d-flex justify-content-between align-items-center gap-2 mt-4">
                    <a class="btn btn-outline-secondary" href="index.php">Cancelar</a>
                    <button class="btn btn-cap btn-lg d-none" id="saveButton" type="submit">Guardar compra</button>
                </div>
            </form>
        </div>
    </section>
    <?php endif; ?>
</main>
<script>
window.CAP_OCR_CONTEXT = {
  companies: <?= json_encode(array_map(static fn(array $company): array => ['id' => (int) $company['id_empresa_grupo'], 'name' => $company['nombre'], 'rtn' => $company['rtn']], $companies), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  providers: <?= json_encode(array_map(static fn(array $provider): array => ['id' => (int) $provider['id_proveedor'], 'name' => $provider['nombre_empresa'], 'rtn' => $provider['rtn'], 'category' => (int) $provider['id_categoria']], $providers), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};
</script>
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5.0.4/dist/tesseract.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script src="../assets/js/ingresar-documento.js?v=ocr-fix-20261010" defer></script>
</body>
</html>
