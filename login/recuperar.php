<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/paths.php';
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/password_reset_mail.php';
session_start();

$pageTitle = 'Recuperar contrasena - SICA';
$message = '';
$messageType = 'info';
$oldCorreo = '';

if (empty($_SESSION['csrf_recover'])) {
    $_SESSION['csrf_recover'] = bin2hex(random_bytes(32));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = (string)($_POST['csrf_recover'] ?? '');
    $oldCorreo = mb_strtolower(trim((string)($_POST['correo'] ?? '')), 'UTF-8');

    if (!hash_equals((string)$_SESSION['csrf_recover'], $csrf)) {
        $message = 'La sesion expiro. Recarga la pagina e intenta de nuevo.';
        $messageType = 'danger';
    } elseif ($oldCorreo === '' || strlen($oldCorreo) > 100 || !filter_var($oldCorreo, FILTER_VALIDATE_EMAIL)) {
        $message = 'Ingresa un correo valido.';
        $messageType = 'danger';
    } else {
        $stmt = $pdo->prepare(
            'SELECT u.id_documento, u.nombre, u.apellido, u.correo
             FROM usuario u
             INNER JOIN estado e ON e.id_estado = u.id_estado
             WHERE u.correo = :correo
               AND e.nombre_estado = :activo
             LIMIT 1'
        );
        $stmt->execute([
            ':correo' => $oldCorreo,
            ':activo' => 'Activo',
        ]);
        $usuario = $stmt->fetch();

        if ($usuario) {
            $expiresAt = (new DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s');
            $code = '';
            $tokenHash = '';

            for ($attempt = 0; $attempt < 5; $attempt++) {
                $candidateCode = (string)random_int(100000, 999999);
                $candidateHash = hash('sha256', (string)$usuario['id_documento'] . '|' . $candidateCode);
                $exists = $pdo->prepare('SELECT id_reset FROM password_reset WHERE token_hash = :token_hash LIMIT 1');
                $exists->execute([':token_hash' => $candidateHash]);

                if (!$exists->fetch()) {
                    $code = $candidateCode;
                    $tokenHash = $candidateHash;
                    break;
                }
            }

            if ($code === '' || $tokenHash === '') {
                error_log('SICA: no se pudo generar codigo unico de recuperacion para ' . $oldCorreo);
                $_SESSION['reset_notice'] = 'Si el correo existe, enviaremos un codigo de 6 digitos para restablecer la contrasena.';
                header('Location: ' . app_url('login/restablecer.php?correo=' . rawurlencode($oldCorreo)));
                exit;
            }

            $pdo->prepare('UPDATE password_reset SET usado = 1 WHERE id_documento = :id_documento AND usado = 0')
                ->execute([':id_documento' => $usuario['id_documento']]);

            $insert = $pdo->prepare(
                'INSERT INTO password_reset (id_documento, token_hash, fecha_expiracion)
                 VALUES (:id_documento, :token_hash, :fecha_expiracion)'
            );
            $insert->execute([
                ':id_documento' => $usuario['id_documento'],
                ':token_hash' => $tokenHash,
                ':fecha_expiracion' => $expiresAt,
            ]);

            $sent = sendPasswordResetMail($usuario, $code);

            if (!$sent) {
                error_log('SICA: no se pudo enviar correo de recuperacion a ' . $oldCorreo);
            }
        }

        $_SESSION['reset_notice'] = 'Si el correo existe, enviaremos un codigo de 6 digitos para restablecer la contrasena.';
        header('Location: ' . app_url('login/restablecer.php?correo=' . rawurlencode($oldCorreo)));
        exit;
    }
}
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>

<main class="login-page">
    <section class="login-shell" aria-label="Recuperar contrasena">
        <div class="login-card">
            <div class="login-logo" aria-label="SICA">
                <span>SICA</span>
            </div>
            <header class="login-header">
                <h1>Recuperar contrase&ntilde;a</h1>
                <p>Ingresa tu correo personal y te enviaremos un codigo seguro.</p>
            </header>

            <?php if ($message !== ''): ?>
                <div class="alert alert-<?= htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8') ?> shadow-sm" role="alert">
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form id="recoverForm" method="post" action="<?= htmlspecialchars(app_url('login/recuperar.php'), ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
                <input type="hidden" name="csrf_recover" value="<?= htmlspecialchars($_SESSION['csrf_recover'], ENT_QUOTES, 'UTF-8') ?>">
                <div class="login-field">
                    <span class="field-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="20" height="20">
                            <path d="M4.75 5h14.5C20.22 5 21 5.78 21 6.75v10.5c0 .97-.78 1.75-1.75 1.75H4.75C3.78 19 3 18.22 3 17.25V6.75C3 5.78 3.78 5 4.75 5Zm.77 2 6.48 4.56L18.48 7H5.52Z" fill="currentColor"/>
                        </svg>
                    </span>
                    <input type="email" class="form-control" name="correo" placeholder="Correo personal" value="<?= htmlspecialchars($oldCorreo, ENT_QUOTES, 'UTF-8') ?>" required maxlength="100">
                </div>
                <button type="submit" class="login-submit">Enviar codigo</button>
            </form>

            <p class="login-register"><a href="<?= htmlspecialchars(app_url('login/index.php'), ENT_QUOTES, 'UTF-8') ?>">Volver al inicio de sesi&oacute;n</a></p>
        </div>
    </section>
</main>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
