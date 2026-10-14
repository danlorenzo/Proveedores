<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/config/config.php';
apply_security_headers();

start_app_session();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$email = '';
$showRegister = ($_GET['form'] ?? '') === 'registro';
$registrationError = (string) ($_SESSION['registration_error'] ?? '');
$registrationValues = $_SESSION['registration_values'] ?? [];
$registrationFieldErrors = $_SESSION['registration_field_errors'] ?? [];
unset($_SESSION['registration_error'], $_SESSION['registration_values'], $_SESSION['registration_field_errors']);
$registrationComplete = ($_GET['registro'] ?? '') === 'ok';
$sessionClosed = ($_GET['sesion'] ?? '') === 'cerrada';
$cargos = [];
$empresas = [];

try {
    $pdo = database();
    $cargos = $pdo->query(
        'SELECT c.id_cargo, c.nombre AS cargo, d.nombre AS departamento
           FROM cargo c INNER JOIN departamento d ON d.id_departamento = c.id_departamento
          ORDER BY d.nombre, c.nombre'
    )->fetchAll();
    $empresas = $pdo->query(
        'SELECT id_empresa_grupo, nombre FROM empresa_grupo ORDER BY nombre'
    )->fetchAll();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    if ($registrationError === '') {
        $registrationError = 'No se pudo conectar con la base de datos.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $email = isset($_POST['correo']) && is_string($_POST['correo']) ? trim($_POST['correo']) : '';
    $password = isset($_POST['contrasena']) && is_string($_POST['contrasena']) ? $_POST['contrasena'] : '';
    $csrf = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = 'Ingresa un correo válido y tu contraseña.';
    } else {
        try {
            $statement = database()->prepare(
                'SELECT u.dni, u.primer_nombre, u.primer_apellido,
                        u.correo_verificado, c.password_hash
                   FROM usuario u
                   INNER JOIN credencial_usuario c ON c.dni_usuario = u.dni
                  WHERE u.correo = :correo
                  LIMIT 1'
            );
            $statement->execute(['correo' => $email]);
            $user = $statement->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                $error = 'No pudimos iniciar sesión. Revisa tu correo y contraseña.';
            } else {
                session_regenerate_id(true);
                $token = create_jwt(
                    $user['dni'],
                    trim($user['primer_nombre'] . ' ' . $user['primer_apellido'])
                );
                $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

                setcookie('access_token', $token, [
                    'expires' => time() + JWT_TTL_SECONDS,
                    'path' => '/',
                    'secure' => $isHttps,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);

                header('Location: index.php');
                exit;
            }
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            $error = 'No se pudo conectar con el sistema. Revisa la configuración de la base de datos.';
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#c62828">
    <title>Iniciar sesión | Biblioteca de Proveedores CAP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root { --cap-red: #c62828; --cap-deep: #a71920; --ink: #242424; }
        body {
            min-height: 100vh;
            color: var(--ink);
            background: #f7f7f8;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
        }
        .login-shell { min-height: 100vh; }
        .brand-panel {
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 280px;
            padding: clamp(2rem, 6vw, 5rem);
            color: #fff;
            background: linear-gradient(145deg, var(--cap-red), var(--cap-deep));
        }
        .brand-panel::before, .brand-panel::after {
            position: absolute;
            content: "";
            border: 1px solid rgb(255 255 255 / 15%);
            border-radius: 50%;
            pointer-events: none;
        }
        .brand-panel::before { width: 440px; height: 440px; right: -170px; bottom: -155px; }
        .brand-panel::after { width: 300px; height: 300px; right: -100px; bottom: -85px; }
        .brand-mark { position: relative; z-index: 1; display: flex; align-items: center; gap: .8rem; }
        .brand-symbol {
            display: block; width: 52px; height: 56px; object-fit: contain;
            filter: brightness(0) invert(1);
        }
        .brand-name { font-size: 2.2rem; font-weight: 900; letter-spacing: .07em; }
        .brand-copy { position: relative; z-index: 1; max-width: 430px; }
        .brand-copy h1 { font-family: "Oswald", "Arial Narrow", system-ui, sans-serif; font-size: clamp(2.2rem, 4vw, 3.8rem); font-weight: 700; letter-spacing: .015em; line-height: 1.08; }
        .form-panel { display: flex; align-items: center; justify-content: center; padding: 2rem 1.25rem; }
        .login-card { width: min(100%, 440px); padding: clamp(1.5rem, 4vw, 2.75rem); background: #fff; border: 1px solid #ececef; border-radius: 1.25rem; box-shadow: 0 18px 55px rgb(26 26 26 / 8%); }
        .login-card h2 { font-size: 1.8rem; font-weight: 750; letter-spacing: -.03em; }
        .form-label { font-weight: 650; }
        .form-control { min-height: 48px; border-color: #d9d9de; border-radius: .65rem; }
        .form-control:focus { border-color: #86b7fe; box-shadow: 0 0 0 .22rem rgb(13 110 253 / 18%); }
        .password-toggle { min-width: 48px; border-color: #d9d9de; color: #555; }
        .password-toggle:hover, .password-toggle:focus, .password-toggle:active { color: #444 !important; background: #f5f5f6 !important; border-color: #b8b8bf !important; }
        .password-toggle svg { display: block; flex: 0 0 auto; }
        .password-row .form-control { border-top-right-radius: 0; border-bottom-right-radius: 0; }
        .password-row .password-toggle { border-top-right-radius: .65rem; border-bottom-right-radius: .65rem; }
        .btn-cap { min-height: 49px; color: #fff; background: var(--cap-red); border: 0; border-radius: .65rem; font-weight: 700; }
        .btn-cap:hover, .btn-cap:focus { color: #fff; background: var(--cap-deep); }
        .small-note { color: #707078; }
        @media (min-width: 992px) {
            body { overflow: hidden; }
            .login-shell { height: 100vh; height: 100dvh; min-height: 0; overflow: hidden; }
            .brand-panel { height: 100vh; height: 100dvh; min-height: 0; }
            .form-panel { height: 100vh; height: 100dvh; min-height: 0; overflow-y: auto; align-items: center; overscroll-behavior: contain; }
            body.register-mode .form-panel { align-items: flex-start; }
        }
        @media (max-width: 991.98px) {
            body { overflow: auto; }
            .login-shell { height: auto; overflow: visible; }
            .form-panel { height: auto; overflow: visible; }
        }
    </style>
</head>
<body class="<?= $showRegister ? 'register-mode' : '' ?>">
<main class="container-fluid p-0">
    <div class="row g-0 login-shell">
        <section class="col-lg-6 brand-panel" aria-label="Biblioteca de Proveedores CAP">
            <div class="brand-mark">
                <img class="brand-symbol" src="../assets/img/cap-emblem.svg" alt="">
                <span class="brand-name">CAP</span>
            </div>
            <div class="brand-copy my-5">
                <h1>Biblioteca de Proveedores</h1>
                <p class="mt-3 mb-0 fs-5 text-white-50">Toda la información de tus proveedores, en un solo lugar.</p>
            </div>
            <p class="small mb-0 position-relative">Acceso exclusivo para colaboradores del Grupo CAP</p>
        </section>

        <section class="col-lg-6 form-panel">
            <div class="login-card">
                <div id="loginView" <?= $showRegister ? 'class="d-none"' : '' ?>>
                    <div class="mb-4">
                        <span class="badge rounded-pill text-bg-light border mb-3">Grupo CAP</span>
                        <h2 class="mb-2">Bienvenido</h2>
                        <p class="text-secondary mb-0">Inicia sesión para continuar.</p>
                    </div>
                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <form method="post" action="login.php" autocomplete="on">
                        <input type="hidden" name="action" value="login">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="mb-3">
                            <label for="correo_login" class="form-label">Correo electrónico</label>
                            <input class="form-control" id="correo_login" name="correo" type="email" autocomplete="username"
                                   placeholder="tu-correo@ejemplo.com" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="mb-4">
                            <label for="contrasena_login" class="form-label">Contraseña</label>
                            <div class="input-group password-row">
                                <input class="form-control" id="contrasena_login" name="contrasena" type="password"
                                       autocomplete="current-password" placeholder="Ingresa tu contraseña" required>
                                <button class="btn btn-outline-secondary password-toggle" type="button" data-toggle-password="contrasena_login" aria-label="Mostrar contraseña" aria-pressed="false">
                                    <svg class="eye-open" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                    <svg class="eye-closed d-none" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"></path><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a16.8 16.8 0 0 1-3.1 3.9M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.2 0 2.3-.2 3.3-.6"></path></svg>
                                </button>
                            </div>
                        </div>
                        <button class="btn btn-cap w-100" type="submit">Iniciar sesión</button>
                    </form>
                    <p class="small-note text-center mt-4 mb-0">¿Aún no tienes cuenta? <button class="btn btn-link p-0 align-baseline" id="showRegister" type="button">Regístrate aquí</button>.</p>
                </div>

                <div id="registerView" <?= $showRegister ? '' : 'class="d-none"' ?>>
                    <div class="mb-4">
                        <span class="badge rounded-pill text-bg-light border mb-3">Grupo CAP</span>
                        <h2 class="mb-2">Crear cuenta</h2>
                        <p class="text-secondary mb-0">Completa tus datos para registrarte.</p>
                    </div>
                    <?php if ($registrationError !== ''): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($registrationError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if (!$cargos || !$empresas): ?>
                        <div class="alert alert-warning" role="alert">Faltan empresas o cargos. Solicita al administrador que complete esos datos.</div>
                    <?php else: ?>
                        <form method="post" action="../backend/controllers/registro.php" autocomplete="on">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <div class="row g-3">
                                <div class="col-6">
                                    <label class="form-label" for="primer_nombre">Primer nombre</label>
                                    <input class="form-control <?= isset($registrationFieldErrors['primer_nombre']) ? 'is-invalid' : '' ?>" id="primer_nombre" name="primer_nombre" maxlength="15" pattern="[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]+" title="Solo letras, sin espacios ni signos" oninput="this.value=this.value.replace(/[^A-Za-zÁÉÍÓÚÜÑáéíóúüñ]/g,'')" value="<?= htmlspecialchars($registrationValues['primer_nombre'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                                    <?php if (isset($registrationFieldErrors['primer_nombre'])): ?><div class="invalid-feedback d-block"><?= htmlspecialchars($registrationFieldErrors['primer_nombre'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
                                </div>
                                <div class="col-6">
                                    <label class="form-label" for="segundo_nombre">Segundo nombre</label>
                                    <input class="form-control <?= isset($registrationFieldErrors['segundo_nombre']) ? 'is-invalid' : '' ?>" id="segundo_nombre" name="segundo_nombre" maxlength="15" pattern="[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]+" title="Solo letras, sin espacios ni signos" oninput="this.value=this.value.replace(/[^A-Za-zÁÉÍÓÚÜÑáéíóúüñ]/g,'')" value="<?= htmlspecialchars($registrationValues['segundo_nombre'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                                    <?php if (isset($registrationFieldErrors['segundo_nombre'])): ?><div class="invalid-feedback d-block"><?= htmlspecialchars($registrationFieldErrors['segundo_nombre'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
                                </div>
                                <div class="col-6">
                                    <label class="form-label" for="primer_apellido">Primer apellido</label>
                                    <input class="form-control <?= isset($registrationFieldErrors['primer_apellido']) ? 'is-invalid' : '' ?>" id="primer_apellido" name="primer_apellido" maxlength="15" pattern="[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]+" title="Solo letras, sin espacios ni signos" oninput="this.value=this.value.replace(/[^A-Za-zÁÉÍÓÚÜÑáéíóúüñ]/g,'')" value="<?= htmlspecialchars($registrationValues['primer_apellido'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                                    <?php if (isset($registrationFieldErrors['primer_apellido'])): ?><div class="invalid-feedback d-block"><?= htmlspecialchars($registrationFieldErrors['primer_apellido'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
                                </div>
                                <div class="col-6">
                                    <label class="form-label" for="segundo_apellido">Segundo apellido</label>
                                    <input class="form-control <?= isset($registrationFieldErrors['segundo_apellido']) ? 'is-invalid' : '' ?>" id="segundo_apellido" name="segundo_apellido" maxlength="15" pattern="[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]+" title="Solo letras, sin espacios ni signos" oninput="this.value=this.value.replace(/[^A-Za-zÁÉÍÓÚÜÑáéíóúüñ]/g,'')" value="<?= htmlspecialchars($registrationValues['segundo_apellido'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                    <?php if (isset($registrationFieldErrors['segundo_apellido'])): ?><div class="invalid-feedback d-block"><?= htmlspecialchars($registrationFieldErrors['segundo_apellido'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
                                </div>
                                <div class="col-6">
                                    <label class="form-label" for="dni">DNI</label>
                                    <input class="form-control <?= isset($registrationFieldErrors['dni']) ? 'is-invalid' : '' ?>" id="dni" name="dni" type="text" inputmode="numeric" pattern="[0-9]{13}" maxlength="13" title="Escribe exactamente 13 números" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,13)" value="<?= htmlspecialchars($registrationValues['dni'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                                    <?php if (isset($registrationFieldErrors['dni'])): ?><div class="invalid-feedback d-block"><?= htmlspecialchars($registrationFieldErrors['dni'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
                                </div>
                                <div class="col-6">
                                    <label class="form-label" for="celular">Celular o teléfono</label>
                                    <input class="form-control <?= isset($registrationFieldErrors['celular']) ? 'is-invalid' : '' ?>" id="celular" name="celular" type="tel" inputmode="numeric" pattern="[23789][0-9]{7}" maxlength="8" title="8 números: 2 para línea fija o 3, 7, 8 o 9 para móvil" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,8)" value="<?= htmlspecialchars($registrationValues['celular'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                                    <?php if (isset($registrationFieldErrors['celular'])): ?><div class="invalid-feedback d-block"><?= htmlspecialchars($registrationFieldErrors['celular'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="correo_registro">Correo personal</label>
                                    <input class="form-control <?= isset($registrationFieldErrors['correo']) ? 'is-invalid' : '' ?>" id="correo_registro" name="correo" type="email" maxlength="50" autocomplete="email" placeholder="nombre@correo.com" value="<?= htmlspecialchars($registrationValues['correo'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                                    <div id="correoFeedback" class="invalid-feedback <?= isset($registrationFieldErrors['correo']) ? 'd-block' : '' ?>"><?= htmlspecialchars($registrationFieldErrors['correo'] ?? 'Ingresa una dirección de correo válida.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                </div>
                                <div class="col-12"><label class="form-label" for="id_empresa_grupo">Empresa de Grupo CAP</label><select class="form-select" id="id_empresa_grupo" name="id_empresa_grupo" required><option value="">Selecciona una empresa</option><?php foreach ($empresas as $empresa): ?><option value="<?= (int) $empresa['id_empresa_grupo'] ?>" <?= (string) ($registrationValues['id_empresa_grupo'] ?? '') === (string) $empresa['id_empresa_grupo'] ? 'selected' : '' ?>><?= htmlspecialchars($empresa['nombre'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                <div class="col-12"><label class="form-label" for="id_cargo">Cargo</label><select class="form-select" id="id_cargo" name="id_cargo" required><option value="">Selecciona un cargo</option><?php foreach ($cargos as $cargo): ?><option value="<?= (int) $cargo['id_cargo'] ?>" <?= (string) ($registrationValues['id_cargo'] ?? '') === (string) $cargo['id_cargo'] ? 'selected' : '' ?>><?= htmlspecialchars($cargo['cargo'] . ' · ' . $cargo['departamento'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                <div class="col-12">
                                    <label class="form-label" for="contrasena_registro">Contraseña</label>
                                    <div class="input-group password-row">
                                        <input class="form-control <?= isset($registrationFieldErrors['contrasena']) ? 'is-invalid' : '' ?>" id="contrasena_registro" name="contrasena" type="password" autocomplete="new-password" minlength="8" pattern="\S{8,}" oninput="this.value=this.value.replace(/\s/g,'')" required>
                                        <button class="btn btn-outline-secondary password-toggle" type="button" data-toggle-password="contrasena_registro" aria-label="Mostrar contraseña" aria-pressed="false">
                                            <svg class="eye-open" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                            <svg class="eye-closed d-none" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"></path><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a16.8 16.8 0 0 1-3.1 3.9M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.2 0 2.3-.2 3.3-.6"></path></svg>
                                        </button>
                                    </div>
                                    <div class="form-text">Mínimo 8 caracteres, una mayúscula, un número y un símbolo; sin espacios.</div>
                                    <?php if (isset($registrationFieldErrors['contrasena'])): ?><div class="invalid-feedback d-block"><?= htmlspecialchars($registrationFieldErrors['contrasena'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="confirmar_contrasena">Confirmar contraseña</label>
                                    <div class="input-group password-row">
                                        <input class="form-control <?= isset($registrationFieldErrors['confirmar_contrasena']) ? 'is-invalid' : '' ?>" id="confirmar_contrasena" name="confirmar_contrasena" type="password" autocomplete="new-password" minlength="8" pattern="\S{8,}" oninput="this.value=this.value.replace(/\s/g,'')" required>
                                        <button class="btn btn-outline-secondary password-toggle" type="button" data-toggle-password="confirmar_contrasena" aria-label="Mostrar contraseña" aria-pressed="false">
                                            <svg class="eye-open" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                            <svg class="eye-closed d-none" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"></path><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a16.8 16.8 0 0 1-3.1 3.9M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.2 0 2.3-.2 3.3-.6"></path></svg>
                                        </button>
                                    </div>
                                    <div id="passwordMismatch" class="invalid-feedback <?= isset($registrationFieldErrors['confirmar_contrasena']) ? 'd-block' : '' ?>"><?= htmlspecialchars($registrationFieldErrors['confirmar_contrasena'] ?? 'Las contraseñas no coinciden.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                </div>
                                <div class="col-12 d-grid mt-2"><button class="btn btn-cap" type="submit">Enviar registro</button></div>
                            </div>
                        </form>
                    <?php endif; ?>
                    <p class="small-note text-center mt-4 mb-0">¿Ya tienes cuenta? <button class="btn btn-link p-0 align-baseline" id="showLogin" type="button">Inicia sesión</button>.</p>
                </div>
            </div>
        </section>
    </div>
</main>
<div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div id="registrationToast" class="toast align-items-center text-bg-success border-0" role="status" aria-live="polite" aria-atomic="true">
        <div class="d-flex"><div class="toast-body">Datos enviados. Tu cuenta fue creada correctamente y ya puedes iniciar sesión.</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button></div>
    </div>
    <div id="logoutToast" class="toast align-items-center border-0" role="status" aria-live="polite" aria-atomic="true" style="background:#16877f;color:#fff">
        <div class="d-flex"><div class="toast-body"><i class="bi bi-hand-wave-fill me-2" aria-hidden="true"></i>Sesión cerrada, nos vemos hasta la próxima</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button></div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const loginView = document.getElementById('loginView');
    const registerView = document.getElementById('registerView');
    document.getElementById('showRegister')?.addEventListener('click', () => {
        loginView.classList.add('d-none');
        registerView.classList.remove('d-none');
        document.body.classList.add('register-mode');
        document.querySelector('.form-panel').scrollTop = 0;
        document.getElementById('primer_nombre').focus();
    });
    document.getElementById('showLogin')?.addEventListener('click', () => {
        registerView.classList.add('d-none');
        loginView.classList.remove('d-none');
        document.body.classList.remove('register-mode');
        document.querySelector('.form-panel').scrollTop = 0;
        document.getElementById('correo_login').focus();
    });
    document.querySelectorAll('[data-toggle-password]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.togglePassword);
            const reveal = input.type === 'password';
            input.type = reveal ? 'text' : 'password';
            button.setAttribute('aria-label', reveal ? 'Ocultar contraseña' : 'Mostrar contraseña');
            button.setAttribute('aria-pressed', reveal ? 'true' : 'false');
            button.querySelector('.eye-open').classList.toggle('d-none', reveal);
            button.querySelector('.eye-closed').classList.toggle('d-none', !reveal);
        });
    });

    const registrationForm = registerView.querySelector('form');
    const emailInput = document.getElementById('correo_registro');
    const emailFeedback = document.getElementById('correoFeedback');
    if (emailInput) {
        emailInput.addEventListener('input', () => {
            const invalid = emailInput.value !== '' && !emailInput.validity.valid;
            emailInput.classList.toggle('is-invalid', invalid);
            emailFeedback.classList.toggle('d-block', invalid);
            if (invalid) emailFeedback.textContent = 'Ingresa una dirección de correo válida.';
        });
        emailInput.addEventListener('blur', () => {
            if (emailInput.value !== '' && !emailInput.validity.valid) {
                emailInput.classList.add('is-invalid');
                emailFeedback.textContent = 'Ingresa una dirección de correo válida.';
                emailFeedback.classList.add('d-block');
            }
        });
    }

    if (registrationForm) {
        const passwordInput = document.getElementById('contrasena_registro');
        const confirmationInput = document.getElementById('confirmar_contrasena');
        const mismatch = document.getElementById('passwordMismatch');
        const checkPasswords = (showError) => {
            const hasBoth = passwordInput.value !== '' && confirmationInput.value !== '';
            const different = hasBoth && passwordInput.value !== confirmationInput.value;
            confirmationInput.classList.toggle('is-invalid', different && showError);
            mismatch.classList.toggle('d-block', different && showError);
            return !different;
        };

        passwordInput.addEventListener('input', () => checkPasswords(true));
        confirmationInput.addEventListener('input', () => checkPasswords(true));
        registrationForm.addEventListener('submit', (event) => {
            if (!checkPasswords(true)) {
                event.preventDefault();
                confirmationInput.focus();
            }
        });
    }
    <?php if ($registrationComplete): ?>
    bootstrap.Toast.getOrCreateInstance(document.getElementById('registrationToast'), { delay: 6000 }).show();
    const loginUrl = new URL(window.location.href);
    loginUrl.searchParams.delete('registro');
    window.history.replaceState({}, '', loginUrl.pathname + loginUrl.search + loginUrl.hash);
    <?php endif; ?>
    <?php if ($sessionClosed): ?>
    bootstrap.Toast.getOrCreateInstance(document.getElementById('logoutToast'), { delay: 7000 }).show();
    <?php endif; ?>
</script>
</body>
</html>
