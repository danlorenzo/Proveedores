<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/ocr_auth.php';
apply_security_headers(); start_app_session(); $user = ocr_read_token($_COOKIE['access_token'] ?? null); if (!$user) { header('Location: ../../frontend/login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../../frontend/ingresar_proveedor.php'); exit; }
ocr_require_post_csrf();
$value = static fn(string $key, int $max): string => isset($_POST[$key]) && is_string($_POST[$key]) ? trim(mb_substr($_POST[$key], 0, $max, 'UTF-8')) : '';
$name = $value('nombre_empresa', 150); $rtn = preg_replace('/\D/', '', $value('rtn', 30));
$category = filter_input(INPUT_POST, 'id_categoria', FILTER_VALIDATE_INT) ?: 0;
$phoneInput = $value('telefono', 64); $phones = [];
if ($phoneInput !== '') {
    preg_match_all('/(?:\+?504[\s-]?)?([23789]\d{3}[\s-]?\d{4})/', $phoneInput, $phoneMatches);
    $phones = array_values(array_unique(array_map(static fn(string $phone): string => preg_replace('/\D/', '', $phone), $phoneMatches[1] ?? [])));
    $unparsedPhoneText = preg_replace('/(?:\+?504[\s-]?)?[23789]\d{3}[\s-]?\d{4}|[\s,;\/|]+/', '', $phoneInput);
    if ($unparsedPhoneText !== '' || count($phones) === 0 || count($phones) > 8) $fail('Ingresa uno o varios teléfonos hondureños de 8 dígitos, separados por coma.');
}
$email = $value('correo', 120); $address = $value('direccion', 255);
$method = $value('metodo_ingreso', 10);
$fail = static function (string $message): void { $_SESSION['proveedor_flash'] = $message; $embedded = isset($_POST['desde_index_modal']) && $_POST['desde_index_modal'] === '1'; $mode = isset($_POST['metodo_ingreso']) && $_POST['metodo_ingreso'] === 'manual' ? 'manual' : 'ocr'; header('Location: ../../frontend/ingresar_proveedor.php?modo=' . $mode . ($embedded ? '&embedded=1' : '')); exit; };
if ($name === '' || $category < 1) $fail('El nombre y la categoría son obligatorios.');
if ($rtn !== '' && strlen($rtn) !== 14) $fail('El RTN debe tener exactamente 14 dígitos.');
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $fail('Ingresa un correo con formato válido.');
if (!in_array($method, ['ocr', 'manual'], true)) $fail('Selecciona un método de ingreso válido.');
try {
    $pdo = database();
    $categoryCheck = $pdo->prepare('SELECT 1 FROM categoria WHERE id_categoria=:id'); $categoryCheck->execute(['id'=>$category]);
    if (!$categoryCheck->fetchColumn()) $fail('La categoría seleccionada no existe.');
    if ($rtn !== '') { $exists = $pdo->prepare('SELECT id_proveedor FROM proveedor WHERE rtn=:rtn LIMIT 1'); $exists->execute(['rtn'=>$rtn]); if ($exists->fetchColumn()) $fail('Ya existe un proveedor con ese RTN.'); }
    $pdo->beginTransaction();
    $insert = $pdo->prepare('INSERT INTO proveedor (rtn,nombre_empresa,id_categoria,direccion,correo,dni_usuario_registro) VALUES (:rtn,:name,:category,:address,:email,:dni)');
    $insert->execute(['rtn'=>$rtn ?: null,'name'=>$name,'category'=>$category,'address'=>$address ?: null,'email'=>$email ?: null,'dni'=>$user['sub']]);
    $providerId = (int)$pdo->lastInsertId();
    if ($phones) {
        $addPhone = $pdo->prepare("INSERT INTO proveedor_telefono (id_proveedor,numero,tipo,origen_dato) VALUES (:id,:phone,:type,:origin)");
        foreach ($phones as $phone) {
            $addPhone->execute(['id'=>$providerId,'phone'=>$phone,'type'=>str_starts_with($phone,'2')?'telefono':'celular','origin'=>$method==='ocr'?'documento':'manual']);
        }
    }
    $pdo->commit();
    $destination = '../../frontend/proveedor.php?id=' . $providerId;
    if (isset($_POST['desde_index_modal']) && $_POST['desde_index_modal'] === '1') {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="es"><meta charset="utf-8"><script>window.top.location.replace(' . json_encode($destination, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ')</script></html>';
        exit;
    }
    header('Location: ' . $destination); exit;
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log($exception->getMessage());
    $fail('No se pudo registrar el proveedor. Verifica que no exista ya y vuelve a intentarlo.');
}
