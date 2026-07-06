<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/paths.php';
session_start();

if (!empty($_SESSION['id_rol']) || !empty($_SESSION['usuario']['id_rol'])) {
    $redirectByRole = [
        1 => 'admin/index.php',
        2 => 'coordinador/index.php',
        3 => 'instructor/index.php',
        4 => 'aprendiz/index.php',
    ];
    $roleId = (int)($_SESSION['id_rol'] ?? $_SESSION['usuario']['id_rol']);
    if (isset($redirectByRole[$roleId])) {
        header('Location: ' . app_url($redirectByRole[$roleId]));
        exit;
    }
}

if (!empty($_SESSION['rol'])) {
    $normalizedRole = mb_strtolower((string)$_SESSION['rol'], 'UTF-8');
    if (str_contains($normalizedRole, 'administrador')) {
        header('Location: ' . app_url('admin/index.php'));
        exit;
    }
    if (str_contains($normalizedRole, 'coordinador')) {
        header('Location: ' . app_url('coordinador/index.php'));
        exit;
    }
    if (str_contains($normalizedRole, 'instructor')) {
        header('Location: ' . app_url('instructor/index.php'));
        exit;
    }
    if (str_contains($normalizedRole, 'aprendiz')) {
        header('Location: ' . app_url('aprendiz/index.php'));
        exit;
    }
}

if (!empty($_SESSION['rol'])) {
    switch ($_SESSION['rol']) {
        case 'Administrador':
            header('Location: ' . app_url('admin/index.php'));
            exit;
        case 'Coordinador Académico':
            header('Location: ' . app_url('coordinador/index.php'));
            exit;
        case 'Instructor':
            header('Location: ' . app_url('instructor/index.php'));
            exit;
        case 'Aprendiz':
            header('Location: ' . app_url('aprendiz/index.php'));
            exit;
    }
}

$error = $_SESSION['login_error'] ?? '';
$oldCorreo = $_SESSION['login_old_correo'] ?? '';
unset($_SESSION['login_error'], $_SESSION['login_old_correo']);

if (empty($_SESSION['csrf_login'])) {
    $_SESSION['csrf_login'] = bin2hex(random_bytes(32));
}
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>

<main class="login-page">
    <section class="login-shell" aria-label="Inicio de sesi&oacute;n">
        <div class="theme-actions" aria-label="Controles de apariencia">
            <button type="button" class="theme-icon-btn theme-light" aria-label="Modo claro">
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
                    <path d="M12 5.25a.75.75 0 0 1-.75-.75V3a.75.75 0 0 1 1.5 0v1.5a.75.75 0 0 1-.75.75Zm0 15.75a.75.75 0 0 1-.75-.75v-1.5a.75.75 0 0 1 1.5 0v1.5a.75.75 0 0 1-.75.75Zm6.36-12.61a.75.75 0 0 1-.53-1.28l1.06-1.06a.75.75 0 0 1 1.06 1.06l-1.06 1.06a.75.75 0 0 1-.53.22ZM5.58 19.67a.75.75 0 0 1-.53-1.28l1.06-1.06a.75.75 0 0 1 1.06 1.06l-1.06 1.06a.75.75 0 0 1-.53.22ZM21 12.75h-1.5a.75.75 0 0 1 0-1.5H21a.75.75 0 0 1 0 1.5Zm-16.5 0H3a.75.75 0 0 1 0-1.5h1.5a.75.75 0 0 1 0 1.5Zm14.92 6.92a.75.75 0 0 1-.53-.22l-1.06-1.06a.75.75 0 1 1 1.06-1.06l1.06 1.06a.75.75 0 0 1-.53 1.28ZM6.64 8.39a.75.75 0 0 1-.53-.22L5.05 7.11a.75.75 0 0 1 1.06-1.06l1.06 1.06a.75.75 0 0 1-.53 1.28ZM12 7.5a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9Z" fill="currentColor"/>
                </svg>
            </button>
            <button type="button" class="theme-toggle" aria-label="Cambiar tema" aria-pressed="false">
                <span></span>
            </button>
            <button type="button" class="theme-icon-btn theme-dark" aria-label="Modo oscuro">
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
                    <path d="M20.7 15.18a.75.75 0 0 1 .12.76 9.75 9.75 0 1 1-12.76-12.76.75.75 0 0 1 .88.25.74.74 0 0 1-.03.91A7.75 7.75 0 0 0 19.66 15.1a.75.75 0 0 1 1.04.08Z" fill="currentColor"/>
                </svg>
            </button>
        </div>
        <div class="login-card">
            <div class="access-signature" aria-hidden="true">
                <svg viewBox="0 0 320 56">
                    <path class="signature-path" d="M18 36 H72 L92 16 H136 L152 36 H206 L226 16 H302" />
                    <path class="signature-glow" d="M18 36 H72 L92 16 H136 L152 36 H206 L226 16 H302" />
                    <circle cx="92" cy="16" r="4" />
                    <circle cx="152" cy="36" r="4" />
                    <circle cx="226" cy="16" r="4" />
                </svg>
            </div>
            <div class="login-logo" aria-label="SICA">
                <span>SICA</span>
            </div>
            <header class="login-header">
                <h1>Iniciar sesi&oacute;n</h1>
                <p>Ingresa tus credenciales para acceder al sistema</p>
            </header>

            <?php if ($error): ?>
                <div class="alert alert-danger shadow-sm" role="alert">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form id="loginForm" action="validar_login.php" method="post" autocomplete="off" novalidate>
                <input type="hidden" name="csrf_login" value="<?= htmlspecialchars($_SESSION['csrf_login'], ENT_QUOTES, 'UTF-8') ?>">
                <div class="login-field">
                    <span class="field-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="20" height="20">
                            <path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-4.2 0-7 2.12-7 5.28A1.72 1.72 0 0 0 6.72 21h10.56A1.72 1.72 0 0 0 19 19.28C19 16.12 16.2 14 12 14Z" fill="currentColor"/>
                        </svg>
                    </span>
                    <input type="email" class="form-control" id="correo" name="correo" placeholder="Correo personal" value="<?= htmlspecialchars($oldCorreo, ENT_QUOTES, 'UTF-8') ?>" required maxlength="60" autocomplete="new-password" autocapitalize="none" inputmode="email" spellcheck="false" readonly data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other">
                    <div class="invalid-feedback">Ingresa un correo personal v&aacute;lido, m&aacute;ximo 60 caracteres.</div>
                </div>

                <div class="login-field password-field"><span class="field-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="20" height="20">
                            <path d="M17 9V7A5 5 0 0 0 7 7v2H5.75A1.75 1.75 0 0 0 4 10.75v8.5C4 20.22 4.78 21 5.75 21h12.5c.97 0 1.75-.78 1.75-1.75v-8.5C20 9.78 19.22 9 18.25 9H17Zm-8.5 0V7a3.5 3.5 0 1 1 7 0v2h-7Z" fill="currentColor"/>
                        </svg>
                    </span><input type="password" class="form-control" id="contrasena" name="contrasena" placeholder="Contrase&ntilde;a" required minlength="8" maxlength="72" autocomplete="new-password" readonly data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other"><button type="button" class="password-eye" aria-label="Mostrar contrase&ntilde;a">
                        <svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true">
                            <path d="M12 5.5c5.2 0 8.48 4.4 9.55 6.1a.75.75 0 0 1 0 .8c-1.07 1.7-4.35 6.1-9.55 6.1s-8.48-4.4-9.55-6.1a.75.75 0 0 1 0-.8C3.52 9.9 6.8 5.5 12 5.5Zm0 2c-3.8 0-6.43 3-7.5 4.5 1.07 1.5 3.7 4.5 7.5 4.5s6.43-3 7.5-4.5C18.43 10.5 15.8 7.5 12 7.5Zm0 1.75a2.75 2.75 0 1 1 0 5.5 2.75 2.75 0 0 1 0-5.5Z" fill="currentColor"/>
                        </svg>
                    </button><div class="invalid-feedback">La contrase&ntilde;a debe ser segura.</div></div>
                <div class="password-rules" id="passwordRules" aria-live="polite">
                    <span data-rule="length">Faltan minimo 8 caracteres</span>
                    <span data-rule="upper">Falta una mayuscula</span>
                    <span data-rule="lower">Falta una minuscula</span>
                    <span data-rule="number">Falta un numero</span>
                    <span data-rule="special">Falta un caracter especial</span>
                </div>

                <div class="login-options">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="" id="recordarme">
                        <label class="form-check-label" for="recordarme">Recordarme</label>
                    </div>
                    <a href="<?= htmlspecialchars(app_url('login/recuperar.php'), ENT_QUOTES, 'UTF-8') ?>">&iquest;Olvidaste tu contrase&ntilde;a?</a>
                </div>

                <button type="submit" class="login-submit">
                    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
                        <path d="M10.25 5.75a.75.75 0 0 1 .75-.75h7.25c.97 0 1.75.78 1.75 1.75v10.5c0 .97-.78 1.75-1.75 1.75H11a.75.75 0 0 1 0-1.5h7.25a.25.25 0 0 0 .25-.25V6.75a.25.25 0 0 0-.25-.25H11a.75.75 0 0 1-.75-.75Zm2.72 3.22a.75.75 0 0 1 1.06 0l2.5 2.5a.75.75 0 0 1 0 1.06l-2.5 2.5a.75.75 0 1 1-1.06-1.06l1.22-1.22H4.75a.75.75 0 0 1 0-1.5h9.44l-1.22-1.22a.75.75 0 0 1 0-1.06Z" fill="currentColor"/>
                    </svg>
                    Iniciar sesi&oacute;n
                </button>
            </form>

            <p class="login-register">&iquest;No tienes una cuenta? <a href="#">Comun&iacute;cate con el administrador</a></p>
        </div>
    </section>
</main>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>

