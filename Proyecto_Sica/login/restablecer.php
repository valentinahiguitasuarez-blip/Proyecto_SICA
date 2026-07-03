<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/paths.php';
require_once __DIR__ . '/../config/conexion.php';
session_start();

$pageTitle = 'Restablecer contrasena - SICA';
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$tokenHash = $token !== '' ? hash('sha256', $token) : '';
$message = '';
$messageType = 'danger';
$validToken = false;
$resetData = null;

if (empty($_SESSION['csrf_reset'])) {
    $_SESSION['csrf_reset'] = bin2hex(random_bytes(32));
}

if ($tokenHash !== '') {
    $stmt = $pdo->prepare(
        'SELECT pr.id_reset, pr.id_documento, u.correo, u.nombre, u.apellido
         FROM password_reset pr
         INNER JOIN usuario u ON u.id_documento = pr.id_documento
         WHERE pr.token_hash = :token_hash
           AND pr.usado = 0
           AND pr.fecha_expiracion >= NOW()
         LIMIT 1'
    );
    $stmt->execute([':token_hash' => $tokenHash]);
    $resetData = $stmt->fetch();
    $validToken = (bool)$resetData;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = (string)($_POST['csrf_reset'] ?? '');
    $password = (string)($_POST['contrasena'] ?? '');
    $passwordConfirm = (string)($_POST['confirmar_contrasena'] ?? '');

    if (!$validToken) {
        $message = 'El enlace no es valido o ya expiro.';
    } elseif (!hash_equals((string)$_SESSION['csrf_reset'], $csrf)) {
        $message = 'La sesion expiro. Recarga la pagina e intenta de nuevo.';
    } elseif (strlen($password) < 6 || strlen($password) > 72) {
        $message = 'La contrasena debe tener entre 6 y 72 caracteres.';
    } elseif ($password !== $passwordConfirm) {
        $message = 'Las contrasenas no coinciden.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare('UPDATE usuario SET contrasena = :hash WHERE id_documento = :id_documento');
            $update->execute([
                ':hash' => $hash,
                ':id_documento' => $resetData['id_documento'],
            ]);

            $mark = $pdo->prepare('UPDATE password_reset SET usado = 1 WHERE id_reset = :id_reset');
            $mark->execute([':id_reset' => $resetData['id_reset']]);

            $pdo->commit();
            unset($_SESSION['csrf_reset']);
            $_SESSION['login_error'] = 'Contrasena actualizada. Inicia sesion con tu nueva contrasena.';
            header('Location: ' . app_url('login/index.php'));
            exit;
        } catch (Throwable $exception) {
            $pdo->rollBack();
            error_log('SICA: error restableciendo contrasena: ' . $exception->getMessage());
            $message = 'No fue posible actualizar la contrasena.';
        }
    }
}

if (!$validToken && $message === '') {
    $message = 'El enlace no es valido o ya expiro.';
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
                <h1>Nueva contrase&ntilde;a</h1>
                <p>Define una contrase&ntilde;a segura para entrar nuevamente a SICA.</p>
            </header>

            <?php if ($message !== ''): ?>
                <div class="alert alert-<?= htmlspecialchars($validToken ? $messageType : 'danger', ENT_QUOTES, 'UTF-8') ?> shadow-sm" role="alert">
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if ($validToken): ?>
                <form method="post" action="<?= htmlspecialchars(app_url('login/restablecer.php'), ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
                    <input type="hidden" name="csrf_reset" value="<?= htmlspecialchars($_SESSION['csrf_reset'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="login-field password-field">
                        <span class="field-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20">
                                <path d="M17 9V7A5 5 0 0 0 7 7v2H5.75A1.75 1.75 0 0 0 4 10.75v8.5C4 20.22 4.78 21 5.75 21h12.5c.97 0 1.75-.78 1.75-1.75v-8.5C20 9.78 19.22 9 18.25 9H17Zm-8.5 0V7a3.5 3.5 0 1 1 7 0v2h-7Z" fill="currentColor"/>
                            </svg>
                        </span>
                        <input type="password" class="form-control" name="contrasena" placeholder="Nueva contrase&ntilde;a" required minlength="6" maxlength="72">
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
            <?php endif; ?>

            <p class="login-register"><a href="<?= htmlspecialchars(app_url('login/index.php'), ENT_QUOTES, 'UTF-8') ?>">Volver al inicio de sesi&oacute;n</a></p>
        </div>
    </section>
</main>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
