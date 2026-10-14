<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/ocr_auth.php';
apply_security_headers();
start_app_session();
$token = ocr_read_token($_COOKIE['access_token'] ?? null);
if (!$token) {
    header('Location: ../../frontend/login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../frontend/ingresar_documento.php');
    exit;
}
ocr_require_post_csrf();

function form_value(string $key, int $max = 500): string
{
    $value = isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
    return mb_substr($value, 0, $max, 'UTF-8');
}

function form_amount(string $key): ?float
{
    $value = isset($_POST[$key]) && is_scalar($_POST[$key]) ? trim((string) $_POST[$key]) : '';
    if ($value === '') return null;
    if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $value)) {
        throw new InvalidArgumentException('Revisa los importes: usa números y punto decimal.');
    }
    return (float) $value;
}

function fail_form(string $message): void
{
    $_SESSION['documento_flash'] = $message;
    $embedded = isset($_POST['desde_index_modal']) && $_POST['desde_index_modal'] === '1';
    $mode = isset($_POST['metodo_ingreso']) && $_POST['metodo_ingreso'] === 'manual' ? 'manual' : 'ocr';
    header('Location: ../../frontend/ingresar_documento.php?modo=' . $mode . ($embedded ? '&embedded=1' : ''));
    exit;
}

try {
    $allowedTypes = ['factura', 'recibo', 'recibo_honorarios', 'amazon', 'otro'];
    $type = form_value('tipo_documento', 30);
    $number = form_value('numero_documento', 25);
    $reference = form_value('referencia_externa', 80);
    $cai = form_value('cai', 40);
    $date = form_value('fecha_documento', 10);
    $currency = form_value('moneda', 3);
    $emitterName = form_value('nombre_emisor', 150);
    $emitterRtn = preg_replace('/\D/', '', form_value('rtn_emisor', 32));
    $clientRtn = preg_replace('/\D/', '', form_value('rtn_cliente', 32));
    $attended = form_value('atendido_por', 100);
    $rawOcr = form_value('texto_ocr', 60000);
    $method = form_value('metodo_ingreso', 10);
    $companyId = filter_var($_POST['id_empresa_grupo'] ?? null, FILTER_VALIDATE_INT);

    if (!in_array($type, $allowedTypes, true)) throw new InvalidArgumentException('Selecciona un tipo de comprobante válido.');
    if (!in_array($method, ['ocr', 'manual'], true)) throw new InvalidArgumentException('Método de ingreso no válido.');
    if ($emitterName === '') throw new InvalidArgumentException('El nombre del emisor o vendedor es obligatorio.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
        throw new InvalidArgumentException('Ingresa una fecha válida.');
    }
    if (!in_array($currency, ['HNL', 'USD', 'EUR', 'CAD', 'OTR'], true)) $currency = 'OTR';
    if ($type === 'factura' && $number === '') throw new InvalidArgumentException('La factura debe tener número de documento.');
    if ($type === 'recibo_honorarios' && $number === '') throw new InvalidArgumentException('Ingresa el número del recibo por honorarios.');
    if ($type === 'amazon' && $number === '' && $reference === '') throw new InvalidArgumentException('Ingresa el número del pedido o de la factura de Amazon.');
    if ($emitterRtn !== '' && strlen($emitterRtn) !== 14) throw new InvalidArgumentException('El RTN del emisor debe tener 14 dígitos, si aparece en el comprobante.');
    if ($clientRtn !== '' && strlen($clientRtn) !== 14) throw new InvalidArgumentException('El RTN del cliente debe tener 14 dígitos, si aparece en el comprobante.');
    if (!$companyId) throw new InvalidArgumentException('Selecciona la empresa CAP que realizó la compra.');
    if ($type !== 'recibo' && $type !== 'amazon' && $type !== 'otro' && $number === '') throw new InvalidArgumentException('Ingresa el número fiscal indicado en el comprobante.');

    $subtotal = form_amount('subtotal');
    $exento = form_amount('subtotal_exento');
    $exonerado = form_amount('subtotal_exonerado');
    $gravado15 = form_amount('subtotal_gravado_15');
    $isv = form_amount('isv');
    $discount = form_amount('descuento_total');
    $shipping = form_amount('costo_envio');
    $withholding = form_amount('retencion_isr');
    $total = form_amount('total');
    foreach ([$total, $subtotal, $exento, $exonerado, $gravado15, $isv, $discount, $shipping, $withholding] as $amount) {
        if ($amount !== null && $amount < 0) throw new InvalidArgumentException('Los importes no pueden ser negativos.');
    }
    if ($total === null) throw new InvalidArgumentException('El total pagado es obligatorio.');

    $itemDescriptions = isset($_POST['detalle_descripcion']) && is_array($_POST['detalle_descripcion']) ? $_POST['detalle_descripcion'] : [];
    $itemQuantities = isset($_POST['detalle_cantidad']) && is_array($_POST['detalle_cantidad']) ? $_POST['detalle_cantidad'] : [];
    $itemPrices = isset($_POST['detalle_precio']) && is_array($_POST['detalle_precio']) ? $_POST['detalle_precio'] : [];
    $itemDiscounts = isset($_POST['detalle_descuento']) && is_array($_POST['detalle_descuento']) ? $_POST['detalle_descuento'] : [];
    $itemImages = isset($_POST['detalle_imagen_url']) && is_array($_POST['detalle_imagen_url']) ? $_POST['detalle_imagen_url'] : [];
    if (count($itemDescriptions) > 30) throw new InvalidArgumentException('Puedes guardar hasta 30 líneas de producto por comprobante.');
    $items = [];
    foreach ($itemDescriptions as $index => $descriptionValue) {
        if (!is_string($descriptionValue)) continue;
        $description = trim(mb_substr($descriptionValue, 0, 255, 'UTF-8'));
        if ($description === '') continue;
        $quantityValue = $itemQuantities[$index] ?? '';
        $priceValue = $itemPrices[$index] ?? '';
        if (!is_scalar($quantityValue) || !is_scalar($priceValue)) throw new InvalidArgumentException('Revisa la cantidad y precio unitario.');
        $quantityValue = trim((string) $quantityValue);
        $priceValue = trim((string) $priceValue);
        if (($quantityValue !== '' && !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $quantityValue))
            || ($priceValue !== '' && !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $priceValue))) {
            throw new InvalidArgumentException('La cantidad y el precio deben ser números positivos si aparecen en el comprobante.');
        }
        $discountValue = $itemDiscounts[$index] ?? '';
        if (!is_scalar($discountValue)) {
            throw new InvalidArgumentException('Revisa los descuentos de las líneas de producto.');
        }
        $discountValue = trim((string) $discountValue);
        if ($discountValue !== '' && !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $discountValue)) throw new InvalidArgumentException('Revisa los descuentos de las líneas de producto.');
        $imageUrl = $itemImages[$index] ?? '';
        if (!is_scalar($imageUrl)) throw new InvalidArgumentException('La URL de imagen no es válida.');
        $imageUrl = trim((string) $imageUrl);
        if ($imageUrl !== '' && (strlen($imageUrl) > 255 || !filter_var($imageUrl, FILTER_VALIDATE_URL) || strtolower((string) parse_url($imageUrl, PHP_URL_SCHEME)) !== 'https')) {
            throw new InvalidArgumentException('La imagen de referencia debe ser una URL HTTPS válida de hasta 255 caracteres.');
        }
        $items[] = ['description' => $description, 'quantity' => $quantityValue !== '' ? (float) $quantityValue : null,
            'price' => $priceValue !== '' ? (float) $priceValue : null, 'discount' => $discountValue !== '' ? (float) $discountValue : null, 'image' => $imageUrl ?: null];
    }

    $pdo = database();
    $companyQuery = $pdo->prepare('SELECT id_empresa_grupo, rtn FROM empresa_grupo WHERE id_empresa_grupo = :id LIMIT 1');
    $companyQuery->execute(['id' => $companyId]);
    $company = $companyQuery->fetch();
    if (!$company) throw new InvalidArgumentException('La empresa CAP seleccionada no existe.');
    if ($clientRtn !== '' && $clientRtn !== preg_replace('/\D/', '', (string) $company['rtn'])) {
        throw new InvalidArgumentException('El RTN del cliente no coincide con la empresa CAP seleccionada. Verifica ambos datos.');
    }

    $savedPath = null;
    $file = $_FILES['documento_archivo'] ?? null;
    if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new InvalidArgumentException('No se pudo cargar el archivo; vuelve a seleccionarlo.');
        if ((int) $file['size'] > 15 * 1024 * 1024) throw new InvalidArgumentException('El comprobante supera el límite de 15 MB.');
        $tmp = (string) $file['tmp_name'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
        if (!isset($extensions[$mime])) throw new InvalidArgumentException('Formato de archivo no permitido. Usa JPG, PNG, WEBP o PDF.');
        if (str_starts_with($mime, 'image/')) {
            $imageInfo = @getimagesize($tmp);
            if (!$imageInfo || !in_array($imageInfo['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
                throw new InvalidArgumentException('El archivo no contiene una imagen válida.');
            }
        } elseif (file_get_contents($tmp, false, null, 0, 5) !== '%PDF-') {
            throw new InvalidArgumentException('El archivo no parece ser un PDF válido.');
        }
        $directory = dirname(__DIR__, 2) . '/uploads/documentos';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo crear la carpeta para guardar comprobantes.');
        }
        $filename = bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
        if (!move_uploaded_file($tmp, $directory . '/' . $filename)) throw new RuntimeException('No se pudo guardar el archivo subido.');
        $savedPath = 'uploads/documentos/' . $filename;
    }

    $phoneInput = form_value('telefono_proveedor', 64);
    $phones = [];
    $providerEmail = form_value('correo_proveedor', 120);
    $providerAddress = form_value('direccion_proveedor', 255);
    $phoneSource = form_value('fuente_telefono', 500);

    $pdo->beginTransaction();
    $linkedProviderId = null;
    $providerIsNew = false;
    if ($type === 'amazon') {
        // Amazon puede ser el canal de compra aunque el comprobante identifique
        // a un vendedor tercero. Conserva su nombre real en nombre_emisor y
        // agrupa el historial bajo el proveedor de la plataforma.
        $platformQuery = $pdo->prepare("SELECT id_proveedor FROM proveedor WHERE nombre_empresa = 'Amazon Marketplace' AND rtn IS NULL LIMIT 1");
        $platformQuery->execute();
        $platformId = $platformQuery->fetchColumn();
        if (!$platformId) {
            $categoryQuery = $pdo->prepare('SELECT id_categoria FROM categoria WHERE nombre = :name LIMIT 1');
            $categoryQuery->execute(['name' => 'Servicios']);
            $servicesCategory = $categoryQuery->fetchColumn();
            if (!$servicesCategory) {
                $pdo->prepare('INSERT INTO categoria (nombre) VALUES (:name)')->execute(['name' => 'Servicios']);
                $servicesCategory = $pdo->lastInsertId();
            }
            $pdo->prepare(
                'INSERT INTO proveedor (rtn, nombre_empresa, id_categoria, dni_usuario_registro)
                 VALUES (NULL, :name, :category, :dni)'
            )->execute(['name' => 'Amazon Marketplace', 'category' => $servicesCategory, 'dni' => $token['sub']]);
            $platformId = $pdo->lastInsertId();
        }
        $linkedProviderId = (int) $platformId;
    } else {
        $provider = null;
        if ($emitterRtn !== '') {
            $providerByRtn = $pdo->prepare('SELECT id_proveedor, rtn, nombre_empresa FROM proveedor WHERE rtn = :rtn LIMIT 1 FOR UPDATE');
            $providerByRtn->execute(['rtn' => $emitterRtn]);
            $provider = $providerByRtn->fetch() ?: null;
        }
        if (!$provider && $emitterRtn !== '') {
            $providerByName = $pdo->prepare(
                'SELECT id_proveedor, rtn, nombre_empresa FROM proveedor
                  WHERE LOWER(TRIM(nombre_empresa)) = LOWER(TRIM(:name))
                    AND (rtn IS NULL OR rtn = :rtn_match)
                  LIMIT 1 FOR UPDATE'
            );
            $providerByName->execute(['name' => $emitterName, 'rtn_match' => $emitterRtn]);
            $provider = $providerByName->fetch() ?: null;
        } elseif (!$provider) {
            $providerByName = $pdo->prepare(
                'SELECT id_proveedor, rtn, nombre_empresa FROM proveedor
                  WHERE LOWER(TRIM(nombre_empresa)) = LOWER(TRIM(:name)) LIMIT 1 FOR UPDATE'
            );
            $providerByName->execute(['name' => $emitterName]);
            $provider = $providerByName->fetch() ?: null;
        }

        if ($provider) {
            $linkedProviderId = (int) $provider['id_proveedor'];
            $emitterName = (string) $provider['nombre_empresa'];
            if ($emitterRtn === '') $emitterRtn = preg_replace('/\D/', '', (string) $provider['rtn']);
        } else {
            if ($providerEmail !== '' && !filter_var($providerEmail, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('El correo del proveedor nuevo no tiene un formato válido.');
            }
            if ($phoneInput !== '') {
                preg_match_all('/(?:\+?504[\s-]?)?([23789]\d{3}[\s-]?\d{4})/', $phoneInput, $phoneMatches);
                $phones = array_values(array_unique(array_map(static fn(string $phone): string => preg_replace('/\D/', '', $phone), $phoneMatches[1] ?? [])));
                $unparsedPhoneText = preg_replace('/(?:\+?504[\s-]?)?[23789]\d{3}[\s-]?\d{4}|[\s,;\/|]+/', '', $phoneInput);
                if ($unparsedPhoneText !== '' || count($phones) === 0 || count($phones) > 8) {
                    throw new InvalidArgumentException('Ingresa uno o varios teléfonos hondureños de 8 dígitos, separados por coma.');
                }
            }
            $categoryQuery = $pdo->prepare('SELECT id_categoria FROM categoria WHERE nombre = :name LIMIT 1');
            $categoryQuery->execute(['name' => 'Sin clasificar']);
            $categoryId = $categoryQuery->fetchColumn();
            if (!$categoryId) {
                $pdo->prepare('INSERT IGNORE INTO categoria (nombre) VALUES (:name)')->execute(['name' => 'Sin clasificar']);
                $categoryQuery->execute(['name' => 'Sin clasificar']);
                $categoryId = $categoryQuery->fetchColumn();
            }
            if (!$categoryId) throw new RuntimeException('No se pudo asignar una categoría provisional al proveedor.');
            $insertProvider = $pdo->prepare(
                'INSERT INTO proveedor (rtn, nombre_empresa, id_categoria, direccion, correo, dni_usuario_registro)
                 VALUES (:rtn, :name, :category, :address, :email, :dni)'
            );
            $insertProvider->execute([
                'rtn' => $emitterRtn !== '' ? $emitterRtn : null,
                'name' => $emitterName,
                'category' => (int) $categoryId,
                'address' => $providerAddress !== '' ? $providerAddress : null,
                'email' => $providerEmail !== '' ? $providerEmail : null,
                'dni' => $token['sub'],
            ]);
            $linkedProviderId = (int) $pdo->lastInsertId();
            $providerIsNew = true;
        }
    }
    $sourceOrigin = $phoneSource !== '' ? 'internet' : ($method === 'ocr' ? 'documento' : 'manual');
    if ($providerIsNew && $phones && $type !== 'amazon') {
        if ($phoneSource !== '' && (!filter_var($phoneSource, FILTER_VALIDATE_URL) || strtolower((string) parse_url($phoneSource, PHP_URL_SCHEME)) !== 'https')) {
            throw new InvalidArgumentException('La fuente del teléfono debe ser un enlace HTTPS válido.');
        }
        $insertPhone = $pdo->prepare(
            'INSERT INTO proveedor_telefono (id_proveedor, numero, tipo, es_whatsapp, origen_dato, fuente_url, verificado_en)
             VALUES (:provider, :phone, :type, 0, :origin, :source, :verified)
             ON DUPLICATE KEY UPDATE origen_dato = VALUES(origen_dato), fuente_url = VALUES(fuente_url), verificado_en = VALUES(verificado_en)'
        );
        foreach ($phones as $phone) {
            $insertPhone->execute(['provider' => $linkedProviderId, 'phone' => $phone, 'type' => str_starts_with($phone, '2') ? 'telefono' : 'celular',
                'origin' => $sourceOrigin, 'source' => $phoneSource ?: null, 'verified' => $phoneSource !== '' ? date('Y-m-d') : null]);
        }
    }
    $insertDocument = $pdo->prepare(
        'INSERT INTO documento_compra (
            id_proveedor, nombre_emisor, rtn_emisor, id_empresa_grupo, dni_usuario, rtn_cliente,
            tipo_documento, numero_documento, referencia_externa, cai, fecha_documento, atendido_por,
            subtotal, subtotal_exento, subtotal_exonerado, subtotal_gravado_15,
            isv, descuento_total, costo_envio, retencion_isr, total, moneda,
            imagen_url, texto_ocr, metodo_ingreso
        ) VALUES (
            :provider, :emitter, :emitter_rtn, :company, :dni, :client_rtn,
            :type, :number, :reference, :cai, :date, :attended,
            :subtotal, :exempt, :exonerated, :taxable15,
            :isv, :discount, :shipping, :withholding, :total, :currency,
            :image, :ocr_text, :method
        )'
    );
    $insertDocument->execute([
        'provider' => $linkedProviderId, 'emitter' => $emitterName,
        'emitter_rtn' => $emitterRtn !== '' ? $emitterRtn : null,
        'company' => (int) $company['id_empresa_grupo'], 'dni' => $token['sub'], 'client_rtn' => $clientRtn !== '' ? $clientRtn : null,
        'type' => $type, 'number' => $number !== '' ? $number : null, 'reference' => $reference !== '' ? $reference : null,
        'cai' => $cai !== '' ? $cai : null, 'date' => $date, 'attended' => $attended !== '' ? $attended : null,
        'subtotal' => $subtotal, 'exempt' => $exento, 'exonerated' => $exonerado, 'taxable15' => $gravado15,
        'isv' => $isv,
        'discount' => $discount, 'shipping' => $shipping, 'withholding' => $withholding, 'total' => $total,
        'currency' => $currency, 'image' => $savedPath, 'ocr_text' => $method === 'ocr' ? $rawOcr : null, 'method' => $method,
    ]);
    $documentId = (int) $pdo->lastInsertId();
    if ($items) {
        $insertItem = $pdo->prepare(
            'INSERT INTO detalle_documento (id_documento, descripcion, cantidad, precio_unitario, descuento, imagen_url)
             VALUES (:document, :description, :quantity, :price, :discount, :image)'
        );
        foreach ($items as $item) {
            $insertItem->execute(['document' => $documentId, 'description' => $item['description'],
                'quantity' => $item['quantity'], 'price' => $item['price'], 'discount' => $item['discount'], 'image' => $item['image']]);
        }
    }
    $pdo->commit();

    $_SESSION['documento_flash'] = 'Compra guardada correctamente.';
    if ($linkedProviderId) {
        $destination = '../../frontend/proveedor.php?id=' . $linkedProviderId . '&calificar=' . $documentId;
        if (form_value('desde_index_modal', 1) === '1') {
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!doctype html><html lang="es"><meta charset="utf-8"><script>window.top.location.replace(' . json_encode($destination, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ')</script></html>';
            exit;
        }
        header('Location: ' . $destination);
    } else {
        header('Location: ../../frontend/index.php');
    }
    exit;
} catch (InvalidArgumentException $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if (!empty($savedPath)) {
        $diskPath = dirname(__DIR__, 2) . '/' . $savedPath;
        if (is_file($diskPath)) unlink($diskPath);
    }
    fail_form($exception->getMessage());
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if (!empty($savedPath)) {
        $diskPath = dirname(__DIR__, 2) . '/' . $savedPath;
        if (is_file($diskPath)) unlink($diskPath);
    }
    error_log($exception->getMessage());
    fail_form('No se pudo guardar la compra. Verifica si la factura ya fue registrada y vuelve a intentarlo.');
}
