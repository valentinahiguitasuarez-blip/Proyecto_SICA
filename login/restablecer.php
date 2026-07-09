<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/paths.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/conexion.php';
session_start();

$pageTitle = 'Restablecer contrasena - SICA';
$correo = mb_strtolower(trim((string)($_GET['correo'] ?? $_POST['correo'] ?? '')), 'UTF-8');
$message = (string)($_SESSION['reset_notice'] ?? '');
$messageType = $message !== '' ? 'success' : 'danger';
unset($_SESSION['reset_notice']);

if (empty($_SESSION['csrf_reset'])) {
    $_SESSION['csrf_reset'] = bin2hex(random_bytes(32));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = (string)($_POST['csrf_reset'] ?? '');
    $code = preg_replace('/\D+/', '', (string)($_POST['codigo'] ?? '')) ?? '';
    $password = (string)($_POST['contrasena'] ?? '');
    $passwordConfirm = (string)($_POST['confirmar_contrasena'] ?? '');

    if (!hash_equals((string)$_SESSION['csrf_reset'], $csrf)) {
        $message = 'La sesion expiro. Recarga la pagina e intenta de nuevo.';
        $messageType = 'danger';
    } elseif ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $message = 'Ingresa el correo donde recibiste el codigo.';
        $messageType = 'danger';
    } elseif (strlen($code) !== 6) {
        $message = 'El codigo debe tener 6 digitos.';
        $messageType = 'danger';
    } elseif (strlen($password) < 6 || strlen($password) > 72) {
        $message = 'La contrasena debe tener entre 6 y 72 caracteres.';
        $messageType = 'danger';
    } elseif (!password_meets_policy($password)) {
        $message = password_policy_message();
        $messageType = 'danger';
    } elseif ($password !== $passwordConfirm) {
        $message = 'Las contrasenas no coinciden.';
        $messageType = 'danger';
    } else {
        $stmt = $pdo->prepare(
            'SELECT u.id_documento, u.correo
             FROM usuario u
             INNER JOIN estado e ON e.id_estado = u.id_estado
             WHERE u.correo = :correo
               AND e.nombre_estado = :activo
             LIMIT 1'
        );
        $stmt->execute([
            ':correo' => $correo,
            ':activo' => 'Activo',
        ]);
        $usuario = $stmt->fetch();

        $resetData = null;
        if ($usuario) {
            $codeHash = hash('sha256', (string)$usuario['id_documento'] . '|' . $code);
            $resetStmt = $pdo->prepare(
                'SELECT id_reset, id_documento
                 FROM password_reset
                 WHERE id_documento = :id_documento
                   AND token_hash = :token_hash
                   AND usado = 0
                   AND fecha_expiracion >= NOW()
                 LIMIT 1'
            );
            $resetStmt->execute([
                ':id_documento' => $usuario['id_documento'],
                ':token_hash' => $codeHash,
            ]);
            $resetData = $resetStmt->fetch();
        }

        if (!$usuario || !$resetData) {
            $message = 'El codigo no es valido o ya expiro.';
            $messageType = 'danger';
        } else {
            $pdo->beginTransaction();
            try {
                $update = $pdo->prepare('UPDATE usuario SET contrasena = :hash WHERE id_documento = :id_documento');
                $update->execute([
                    ':hash' => password_hash($password, PASSWORD_DEFAULT),
                    ':id_documento' => $resetData['id_documento'],
                ]);

                $mark = $pdo->prepare('UPDATE password_reset SET usado = 1 WHERE id_documento = :id_documento AND usado = 0');
                $mark->execute([':id_documento' => $resetData['id_documento']]);

                $pdo->commit();
                unset($_SESSION['csrf_reset']);
                $_SESSION['login_success'] = 'Contrasena actualizada. Inicia sesion con tu nueva contrasena.';
                header('Location: ' . app_url('login/index.php'));
                exit;
            } catch (Throwable $exception) {
                $pdo->rollBack();
                error_log('SICA: error restableciendo contrasena por codigo: ' . $exception->getMessage());
                $message = 'No fue posible actualizar la contrasena.';
                $messageType = 'danger';
            }
        }
    }
}
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>

<main class="login-page">
    <section class="login-shell" aria-label="Restablecer contrasena">
        <div class="login-card">
            <div class="login-logo" aria-label="SICA">
                <span>SICA</span>
            </div>
            <header class="login-header">
                <h1>Codigo de seguridad</h1>
                <p>Escribe el codigo enviado a tu correo y crea una nueva contrase&ntilde;a.</p>
            </header>

            <?php if ($message !== ''): ?>
                <div class="alert alert-<?= htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8') ?> shadow-sm" role="alert">
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= htmlspecialchars(app_url('login/restablecer.php'), ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
                <input type="hidden" name="csrf_reset" value="<?= htmlspecialchars($_SESSION['csrf_reset'], ENT_QUOTES, 'UTF-8') ?>">

                <div class="login-field">
                    <span class="field-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="20" height="20">
                            <path d="M4.75 5h14.5C20.22 5 21 5.78 21 6.75v10.5c0 .97-.78 1.75-1.75 1.75H4.75C3.78 19 3 18.22 3 17.25V6.75C3 5.78 3.78 5 4.75 5Zm.77 2 6.48 4.56L18.48 7H5.52Z" fill="currentColor"/>
                        </svg>
                    </span>
                    <input type="email" class="form-control" name="correo" placeholder="Correo personal" value="<?= htmlspecialchars($correo, ENT_QUOTES, 'UTF-8') ?>" required maxlength="100">
                </div>

                <div class="login-field reset-code-field">
                    <span class="field-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="20" height="20">
                            <path d="M7 4h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm2 4v2h2V8H9Zm4 0v2h2V8h-2Zm-4 4v2h2v-2H9Zm4 0v2h2v-2h-2Z" fill="currentColor"/>
                        </svg>
                    </span>
                    <input type="text" class="form-control" name="codigo" placeholder="Codigo de 6 digitos" required minlength="6" maxlength="6" inputmode="numeric" pattern="[0-9]{6}">
                </div>

                <div class="login-field password-field">
                    <span class="field-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="20" height="20">
                            <path d="M17 9V7A5 5 0 0 0 7 7v2H5.75A1.75 1.75 0 0 0 4 10.75v8.5C4 20.22 4.78 21 5.75 21h12.5c.97 0 1.75-.78 1.75-1.75v-8.5C20 9.78 19.22 9 18.25 9H17Zm-8.5 0V7a3.5 3.5 0 1 1 7 0v2h-7Z" fill="currentColor"/>
                        </svg>
                    </span>
                    <input type="password" class="form-control" name="contrasena" placeholder="Nueva contrase&ntilde;a" required minlength="6" maxlength="72" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{6,72}" title="Debe incluir mayúscula, minúscula, número y carácter especial.">
                </div>

                <div class="login-field password-field">
                    <span class="field-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="20" height="20">
                            <path d="M9.55 16.4 5.8 12.65l1.4-1.4 2.35 2.35 7.25-7.25 1.4 1.4-8.65 8.65Z" fill="currentColor"/>
                        </svg>
                    </span>
                    <input type="password" class="form-control" name="confirmar_contrasena" placeholder="Confirmar contrase&ntilde;a" required minlength="6" maxlength="72">
                </div>

                <button type="submit" class="login-submit">Actualizar contrase&ntilde;a</button>
            </form>

            <p class="login-register"><a href="<?= htmlspecialchars(app_url('login/recuperar.php'), ENT_QUOTES, 'UTF-8') ?>">Enviar otro codigo</a></p>
            <p class="login-register"><a href="<?= htmlspecialchars(app_url('login/index.php'), ENT_QUOTES, 'UTF-8') ?>">Volver al inicio de sesi&oacute;n</a></p>
        </div>
    </section>
</main>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
