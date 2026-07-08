<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([1]);
require_once __DIR__ . '/../config/conexion.php';

$pageTitle = 'Perfil del Administrador - SICA';
$pageStyles = ['css/admin.css'];

$usuario = $_SESSION['usuario'] ?? [];
$adminId = (int)($usuario['id_documento'] ?? 0);

function admin_p_h(string|int|null $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function admin_p_spaces(string $value): string
{
    return preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
}

function admin_p_name_valid(string $value, int $max): bool
{
    return (bool)preg_match('/^[\p{L}\s\'-]{2,' . $max . '}$/u', $value);
}

if (empty($_SESSION['csrf_admin_profile'])) {
    $_SESSION['csrf_admin_profile'] = bin2hex(random_bytes(32));
}

$message = $_SESSION['admin_profile_message'] ?? '';
$messageType = $_SESSION['admin_profile_message_type'] ?? 'success';
unset($_SESSION['admin_profile_message'], $_SESSION['admin_profile_message_type']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = (string)($_POST['csrf_admin_profile'] ?? '');
    $nombre = admin_p_spaces((string)($_POST['nombre'] ?? ''));
    $apellido = admin_p_spaces((string)($_POST['apellido'] ?? ''));
    $correo = mb_strtolower(trim((string)($_POST['correo'] ?? '')), 'UTF-8');
    $telefono = trim((string)($_POST['telefono'] ?? ''));

    if (!hash_equals((string)$_SESSION['csrf_admin_profile'], $csrf)) {
        $_SESSION['admin_profile_message'] = 'La sesion expiro. Intenta de nuevo.';
        $_SESSION['admin_profile_message_type'] = 'danger';
    } elseif (!admin_p_name_valid($nombre, 50)) {
        $_SESSION['admin_profile_message'] = 'El nombre debe tener entre 2 y 50 letras.';
        $_SESSION['admin_profile_message_type'] = 'danger';
    } elseif (!admin_p_name_valid($apellido, 60)) {
        $_SESSION['admin_profile_message'] = 'El apellido debe tener entre 2 y 60 letras.';
        $_SESSION['admin_profile_message_type'] = 'danger';
    } elseif (strlen($correo) > 100 || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['admin_profile_message'] = 'Escribe un correo valido de maximo 100 caracteres.';
        $_SESSION['admin_profile_message_type'] = 'danger';
    } elseif ($telefono !== '' && !preg_match('/^\d{10}$/', $telefono)) {
        $_SESSION['admin_profile_message'] = 'El telefono debe tener exactamente 10 numeros.';
        $_SESSION['admin_profile_message_type'] = 'danger';
    } else {
        try {
            $correoStmt = $pdo->prepare(
                'SELECT id_documento FROM usuario WHERE correo = :correo AND id_documento <> :id LIMIT 1'
            );
            $correoStmt->execute([':correo' => $correo, ':id' => $adminId]);
            if ($correoStmt->fetch()) {
                throw new RuntimeException('Ese correo ya esta registrado en otra cuenta.');
            }

            $stmt = $pdo->prepare(
                'UPDATE usuario
                 SET nombre = :nombre,
                     apellido = :apellido,
                     correo = :correo,
                     telefono = :telefono
                 WHERE id_documento = :id'
            );
            $stmt->execute([
                ':nombre' => $nombre,
                ':apellido' => $apellido,
                ':correo' => $correo,
                ':telefono' => $telefono !== '' ? $telefono : null,
                ':id' => $adminId,
            ]);

            $_SESSION['usuario']['nombre'] = $nombre;
            $_SESSION['usuario']['apellido'] = $apellido;
            $_SESSION['usuario']['correo'] = $correo;
            $_SESSION['admin_profile_message'] = 'Perfil actualizado correctamente.';
            $_SESSION['admin_profile_message_type'] = 'success';
        } catch (Throwable $exception) {
            $_SESSION['admin_profile_message'] = $exception->getMessage() !== ''
                ? $exception->getMessage()
                : 'No fue posible actualizar el perfil.';
            $_SESSION['admin_profile_message_type'] = 'danger';
            error_log('SICA admin perfil: ' . $exception->getMessage());
        }
    }

    header('Location: ' . app_url('admin/perfil.php'));
    exit;
}

$perfil = null;
try {
    $stmt = $pdo->prepare(
        'SELECT u.id_documento, u.nombre, u.apellido, u.correo, u.telefono, u.fecha_registro,
                r.nombre_rol, es.nombre_estado
         FROM usuario u
         INNER JOIN rol r ON r.id_rol = u.id_rol
         INNER JOIN estado es ON es.id_estado = u.id_estado
         WHERE u.id_documento = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $adminId]);
    $perfil = $stmt->fetch();
} catch (Throwable $exception) {
    error_log('SICA admin perfil carga: ' . $exception->getMessage());
}

$adminName = trim((string)($perfil['nombre'] ?? ($usuario['nombre'] ?? 'Administrador')));
$adminLast = trim((string)($perfil['apellido'] ?? ($usuario['apellido'] ?? '')));
$adminMail = (string)($perfil['correo'] ?? ($usuario['correo'] ?? 'admin@sica.edu.co'));
$initials = strtoupper(substr($adminName, 0, 1) . substr($adminLast !== '' ? $adminLast : $adminName, 0, 1));
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>

<main class="admin-dashboard">
    <aside class="admin-sidebar" aria-label="Menu del administrador">
        <a class="admin-brand admin-brand--with-mark" href="<?= admin_p_h(app_url('admin/index.php')) ?>">
            <span><strong>SICA</strong><small>Sistema Inteligente de Control de Asistencia</small></span>
        </a>
        <a class="admin-profile" href="<?= admin_p_h(app_url('admin/perfil.php')) ?>" aria-label="Perfil del administrador">
            <div class="admin-avatar"><?= admin_p_h($initials) ?></div>
            <div><strong><?= admin_p_h($adminName) ?></strong><small><?= admin_p_h($adminMail) ?></small><span>En linea</span></div>
        </a>
        <nav class="admin-nav">
            <a href="<?= admin_p_h(app_url('admin/index.php')) ?>"><span class="nav-symbol nav-symbol-dashboard" aria-hidden="true"></span>Panel de Control</a>
            <a href="<?= admin_p_h(app_url('admin/usuarios.php')) ?>"><span class="nav-symbol nav-symbol-users" aria-hidden="true"></span>Usuarios</a>
            <a href="<?= admin_p_h(app_url('admin/solicitudes.php')) ?>"><span class="nav-symbol nav-symbol-reservations" aria-hidden="true"></span>Solicitudes de Reserva</a>
            <a href="<?= admin_p_h(app_url('admin/auditorios.php')) ?>"><span class="nav-symbol nav-symbol-auditoriums" aria-hidden="true"></span>Auditorios</a>
            <a href="<?= admin_p_h(app_url('admin/reportes.php')) ?>"><span class="nav-symbol nav-symbol-reports" aria-hidden="true"></span>Reportes</a>
        </nav>
    </aside>

    <section class="admin-main">
        <header class="admin-topbar">
            <div>
                <p class="admin-eyebrow">Perfil administrativo</p>
                <h1>Mis datos</h1>
                <span>Actualiza la informacion que usa SICA para identificar tu cuenta administrativa.</span>
            </div>
            <div class="admin-top-actions">
                <a href="<?= admin_p_h(app_url('admin/index.php')) ?>">Panel <strong>PC</strong></a>
                <a class="admin-logout" href="<?= admin_p_h(app_url('login/logout.php')) ?>">Cerrar sesion</a>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <div class="admin-alert <?= admin_p_h($messageType) ?>"><?= admin_p_h($message) ?></div>
        <?php endif; ?>

        <section class="admin-panel admin-profile-page">
            <div class="admin-profile-card">
                <div class="admin-avatar"><?= admin_p_h($initials) ?></div>
                <p class="admin-eyebrow">Credencial activa</p>
                <h2><?= admin_p_h(trim($adminName . ' ' . $adminLast)) ?></h2>
                <span><?= admin_p_h($adminMail) ?></span>
                <div class="admin-reservation-meta">
                    <span><?= admin_p_h($perfil['nombre_rol'] ?? 'Administrador') ?></span>
                    <span><?= admin_p_h($perfil['nombre_estado'] ?? 'Activo') ?></span>
                    <span>Documento <?= admin_p_h($perfil['id_documento'] ?? $adminId) ?></span>
                </div>
            </div>

            <form class="admin-profile-form" method="post" action="<?= admin_p_h(app_url('admin/perfil.php')) ?>">
                <input type="hidden" name="csrf_admin_profile" value="<?= admin_p_h($_SESSION['csrf_admin_profile']) ?>">
                <label><span>Nombre</span><input type="text" name="nombre" maxlength="50" value="<?= admin_p_h($adminName) ?>" required></label>
                <label><span>Apellido</span><input type="text" name="apellido" maxlength="60" value="<?= admin_p_h($adminLast) ?>" required></label>
                <label><span>Correo</span><input type="email" name="correo" maxlength="100" value="<?= admin_p_h($adminMail) ?>" required></label>
                <label><span>Telefono</span><input type="text" name="telefono" maxlength="10" value="<?= admin_p_h($perfil['telefono'] ?? '') ?>" placeholder="10 numeros"></label>
                <button type="submit">Guardar cambios</button>
            </form>
        </section>
    </section>
</main>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
