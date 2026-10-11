<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
apply_security_headers();
start_app_session();

function redirect_with_registration_error(string $message, array $values = [], array $fieldErrors = []): void
{
    $_SESSION['registration_error'] = $message;
    $_SESSION['registration_values'] = $values;
    $_SESSION['registration_field_errors'] = $fieldErrors;
    header('Location: ../../frontend/login.php?form=registro');
    exit;
}

function registration_post_string(string $key): string
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../frontend/login.php?form=registro');
    exit;
}

$values = [
    'primer_nombre' => registration_post_string('primer_nombre'),
    'segundo_nombre' => registration_post_string('segundo_nombre'),
    'primer_apellido' => registration_post_string('primer_apellido'),
    'segundo_apellido' => registration_post_string('segundo_apellido'),
    'dni' => registration_post_string('dni'),
    'celular' => registration_post_string('celular'),
    'correo' => registration_post_string('correo'),
    'id_cargo' => registration_post_string('id_cargo'),
    'id_empresa_grupo' => registration_post_string('id_empresa_grupo'),
];
$password = isset($_POST['contrasena']) && is_string($_POST['contrasena']) ? $_POST['contrasena'] : '';
$passwordConfirm = isset($_POST['confirmar_contrasena']) && is_string($_POST['confirmar_contrasena']) ? $_POST['confirmar_contrasena'] : '';
$csrf = registration_post_string('csrf_token');

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    redirect_with_registration_error('El formulario venció. Vuelve a abrirlo e inténtalo de nuevo.');
}
if ($values['primer_nombre'] === '' || $values['segundo_nombre'] === '' || $values['primer_apellido'] === '') {
    $missing = [];
    if ($values['primer_nombre'] === '') $missing['primer_nombre'] = 'Este campo es obligatorio.';
    if ($values['segundo_nombre'] === '') $missing['segundo_nombre'] = 'Este campo es obligatorio.';
    if ($values['primer_apellido'] === '') $missing['primer_apellido'] = 'Este campo es obligatorio.';
    redirect_with_registration_error('', $values, $missing);
}
foreach (['primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido'] as $field) {
    if ($values[$field] === '' && $field === 'segundo_apellido') {
        continue;
    }
    if (!preg_match('/^\p{L}+$/u', $values[$field])) {
        redirect_with_registration_error('', $values, [$field => 'Usa solo letras, sin espacios, números ni signos.']);
    }
    if (mb_strlen($values[$field], 'UTF-8') > 15) {
        redirect_with_registration_error('', $values, [$field => 'Máximo 15 letras.']);
    }
}
if (!preg_match('/^[0-9]{13}$/', $values['dni'])) {
    redirect_with_registration_error('', $values, ['dni' => 'El DNI debe contener exactamente 13 números.']);
}
if (!preg_match('/^[23789][0-9]{7}$/', $values['celular'])) {
    redirect_with_registration_error('', $values, ['celular' => 'Usa 8 números: inicia con 2 para línea fija o 3, 7, 8 o 9 para móvil.']);
}
if (!filter_var($values['correo'], FILTER_VALIDATE_EMAIL) || strlen($values['correo']) > 50) {
    redirect_with_registration_error('', $values, ['correo' => 'Ingresa una dirección de correo válida.']);
}
if (!preg_match('/^(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9\s])\S{8,}$/u', $password)) {
    redirect_with_registration_error('', $values, ['contrasena' => 'Usa mínimo 8 caracteres, una mayúscula, un número y un símbolo; sin espacios.']);
}
if (!hash_equals($password, $passwordConfirm)) {
    redirect_with_registration_error('', $values, ['confirmar_contrasena' => 'Las contraseñas no coinciden.']);
}
if (!ctype_digit($values['id_cargo']) || !ctype_digit($values['id_empresa_grupo'])) {
    redirect_with_registration_error('Selecciona un cargo y una empresa válidos.', $values);
}

try {
    $pdo = database();
    $existingDni = $pdo->prepare('SELECT 1 FROM usuario WHERE dni = :dni LIMIT 1');
    $existingDni->execute(['dni' => $values['dni']]);
    if ($existingDni->fetchColumn()) {
        redirect_with_registration_error('', $values, ['dni' => 'Ya existe una cuenta con este DNI.']);
    }
    $existingPhone = $pdo->prepare('SELECT 1 FROM usuario WHERE celular = :celular LIMIT 1');
    $existingPhone->execute(['celular' => $values['celular']]);
    if ($existingPhone->fetchColumn()) {
        redirect_with_registration_error('', $values, ['celular' => 'Este número ya está registrado.']);
    }
    $existingEmail = $pdo->prepare('SELECT 1 FROM usuario WHERE correo = :correo LIMIT 1');
    $existingEmail->execute(['correo' => $values['correo']]);
    if ($existingEmail->fetchColumn()) {
        redirect_with_registration_error('', $values, ['correo' => 'Este correo ya está registrado.']);
    }

    $validCargo = $pdo->prepare('SELECT 1 FROM cargo WHERE id_cargo = :id');
    $validCargo->execute(['id' => (int) $values['id_cargo']]);
    $validEmpresa = $pdo->prepare('SELECT 1 FROM empresa_grupo WHERE id_empresa_grupo = :id');
    $validEmpresa->execute(['id' => (int) $values['id_empresa_grupo']]);
    if (!$validCargo->fetchColumn() || !$validEmpresa->fetchColumn()) {
        redirect_with_registration_error('Selecciona un cargo y una empresa válidos.', $values);
    }

    $pdo->beginTransaction();
    $insertUser = $pdo->prepare(
        'INSERT INTO usuario (
            dni, primer_nombre, segundo_nombre, primer_apellido, segundo_apellido,
            celular, correo, id_cargo, id_empresa_grupo, correo_verificado
        ) VALUES (
            :dni, :primer_nombre, :segundo_nombre, :primer_apellido, :segundo_apellido,
            :celular, :correo, :id_cargo, :id_empresa_grupo, 1
        )'
    );
    $insertUser->execute([
        'dni' => $values['dni'],
        'primer_nombre' => $values['primer_nombre'],
        'segundo_nombre' => $values['segundo_nombre'] !== '' ? $values['segundo_nombre'] : null,
        'primer_apellido' => $values['primer_apellido'],
        'segundo_apellido' => $values['segundo_apellido'] !== '' ? $values['segundo_apellido'] : null,
        'celular' => $values['celular'],
        'correo' => $values['correo'],
        'id_cargo' => (int) $values['id_cargo'],
        'id_empresa_grupo' => (int) $values['id_empresa_grupo'],
    ]);

    $insertPassword = $pdo->prepare(
        'INSERT INTO credencial_usuario (dni_usuario, password_hash)
         VALUES (:dni, :password_hash)'
    );
    $insertPassword->execute([
        'dni' => $values['dni'],
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    $pdo->commit();

    unset($_SESSION['registration_error'], $_SESSION['registration_values']);
    unset($_SESSION['registration_field_errors']);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    header('Location: ../../frontend/login.php?registro=ok');
    exit;
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log($exception->getMessage());
    if ($exception->getCode() === '23000') {
        $details = (string) ($exception->errorInfo[2] ?? '');
        if (stripos($details, 'celular') !== false) {
            redirect_with_registration_error('', $values, ['celular' => 'Este número ya está registrado.']);
        }
        if (stripos($details, 'correo') !== false || stripos($details, 'email') !== false) {
            redirect_with_registration_error('', $values, ['correo' => 'Este correo ya está registrado.']);
        }
        redirect_with_registration_error('', $values, ['dni' => 'Ya existe una cuenta con este DNI.']);
    }
    redirect_with_registration_error('No se pudo crear la cuenta. Revisa la conexión e inténtalo de nuevo.', $values);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log($exception->getMessage());
    redirect_with_registration_error('Ocurrió un error al registrar la cuenta.', $values);
}
