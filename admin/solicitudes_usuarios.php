<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([1]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/smtp_mailer.php';

$pageTitle = 'Solicitudes de acceso - Administrador SICA';
$pageStyles = ['css/admin.css'];

$usuario = $_SESSION['usuario'] ?? [];
$adminName = trim((string)($usuario['nombre'] ?? 'Administrador'));
$adminMail = (string)($usuario['correo'] ?? 'admin@sica.edu.co');
$adminDocument = (int)($usuario['id_documento'] ?? 0);

function admin_access_h(string|int|null $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function admin_access_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function admin_access_temp_password(): string
{
    return 'SICA-' . (string)random_int(100000, 999999);
}

function admin_access_send_credentials(array $request, string $password): bool
{
    $name = trim((string)$request['nombre'] . ' ' . (string)$request['apellido']);
    $body = "Hola {$name},\n\n"
        . "Tu cuenta SICA fue aprobada.\n\n"
        . "Correo de ingreso: {$request['correo']}\n"
        . "Contrasena temporal: {$password}\n\n"
        . "Ingresa a SICA y cambia tu contrasena desde recuperacion si lo necesitas.\n\n"
        . "Equipo SICA";

    return sica_send_mail((string)$request['correo'], 'Acceso aprobado - SICA', $body);
}

if (empty($_SESSION['csrf_admin_access'])) {
    $_SESSION['csrf_admin_access'] = bin2hex(random_bytes(32));
}

$message = $_SESSION['admin_access_message'] ?? '';
$messageType = $_SESSION['admin_access_type'] ?? 'success';
unset($_SESSION['admin_access_message'], $_SESSION['admin_access_type']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = (string)($_POST['csrf_admin_access'] ?? '');
    $idSolicitud = (int)($_POST['id_solicitud'] ?? 0);
    $action = (string)($_POST['accion'] ?? '');

    if (!hash_equals((string)$_SESSION['csrf_admin_access'], $csrf)) {
        $_SESSION['admin_access_message'] = 'La sesion expiro. Intenta de nuevo.';
        $_SESSION['admin_access_type'] = 'danger';
    } elseif ($idSolicitud <= 0 || !in_array($action, ['aprobar', 'rechazar'], true)) {
        $_SESSION['admin_access_message'] = 'Solicitud o accion invalida.';
        $_SESSION['admin_access_type'] = 'danger';
    } else {
        try {
            $stmt = $pdo->prepare(
                "SELECT s.*, r.nombre_rol
                 FROM solicitud_usuario s
                 INNER JOIN rol r ON r.id_rol = s.id_rol
                 WHERE s.id_solicitud = :id AND s.estado = 'Pendiente'
                 LIMIT 1"
            );
            $stmt->execute([':id' => $idSolicitud]);
            $request = $stmt->fetch();

            if (!$request) {
                throw new RuntimeException('La solicitud ya fue atendida o no existe.');
            }

            if ($action === 'rechazar') {
                $update = $pdo->prepare(
                    "UPDATE solicitud_usuario
                     SET estado = 'Rechazada', fecha_respuesta = NOW(), id_admin_respuesta = :admin, observacion = :observacion
                     WHERE id_solicitud = :id"
                );
                $update->execute([
                    ':admin' => $adminDocument,
                    ':observacion' => trim((string)($_POST['observacion'] ?? 'Rechazada por administrador')),
                    ':id' => $idSolicitud,
                ]);
                $_SESSION['admin_access_message'] = 'Solicitud rechazada.';
                $_SESSION['admin_access_type'] = 'success';
            } else {
                $exists = $pdo->prepare('SELECT id_documento FROM usuario WHERE id_documento = :doc OR correo = :correo LIMIT 1');
                $exists->execute([
                    ':doc' => $request['id_documento'],
                    ':correo' => $request['correo'],
                ]);
                if ($exists->fetch()) {
                    throw new RuntimeException('Ya existe un usuario con ese documento o correo.');
                }

                $password = admin_access_temp_password();
                $pdo->beginTransaction();
                $insert = $pdo->prepare(
                    'INSERT INTO usuario
                        (id_documento, tipo_documento, nombre, apellido, correo, contrasena, telefono, fecha_registro, id_rol, id_ficha, id_estado)
                     VALUES
                        (:id_documento, :tipo_documento, :nombre, :apellido, :correo, :contrasena, :telefono, CURDATE(), :id_rol, :id_ficha, 1)'
                );
                $insert->execute([
                    ':id_documento' => $request['id_documento'],
                    ':tipo_documento' => $request['tipo_documento'],
                    ':nombre' => $request['nombre'],
                    ':apellido' => $request['apellido'],
                    ':correo' => $request['correo'],
                    ':contrasena' => password_hash($password, PASSWORD_DEFAULT),
                    ':telefono' => $request['telefono'],
                    ':id_rol' => $request['id_rol'],
                    ':id_ficha' => $request['id_ficha'],
                ]);

                $update = $pdo->prepare(
                    "UPDATE solicitud_usuario
                     SET estado = 'Aprobada', fecha_respuesta = NOW(), id_admin_respuesta = :admin
                     WHERE id_solicitud = :id"
                );
                $update->execute([':admin' => $adminDocument, ':id' => $idSolicitud]);
                $pdo->commit();

                $sent = admin_access_send_credentials($request, $password);
                $_SESSION['admin_access_message'] = $sent
                    ? 'Cuenta creada y correo enviado. Contrasena temporal: ' . $password
                    : 'Cuenta creada, pero el correo no se pudo enviar. Contrasena temporal: ' . $password;
                $_SESSION['admin_access_type'] = $sent ? 'success' : 'danger';
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['admin_access_message'] = $exception->getMessage() ?: 'No fue posible atender la solicitud.';
            $_SESSION['admin_access_type'] = 'danger';
            error_log('SICA solicitudes usuarios: ' . $exception->getMessage());
        }
    }

    header('Location: ' . app_url('admin/solicitudes_usuarios.php'));
    exit;
}

$requests = [];
try {
    $requests = admin_access_rows(
        $pdo,
        "SELECT s.*, r.nombre_rol, f.id_ficha, p.nombre_programa, j.nombre_jornada
         FROM solicitud_usuario s
         INNER JOIN rol r ON r.id_rol = s.id_rol
         LEFT JOIN ficha f ON f.id_ficha = s.id_ficha
         LEFT JOIN programa p ON p.id_programa = f.id_programa
         LEFT JOIN jornada j ON j.id_jornada = p.id_jornada
         ORDER BY FIELD(s.estado, 'Pendiente', 'Aprobada', 'Rechazada'), s.fecha_solicitud DESC
         LIMIT 80"
    );
} catch (Throwable $exception) {
    error_log('SICA solicitudes usuarios listado: ' . $exception->getMessage());
}
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>

<main class="admin-dashboard">
    <aside class="admin-sidebar" aria-label="Menu del administrador">
        <a class="admin-brand admin-brand--with-mark" href="<?= admin_access_h(app_url('admin/index.php')) ?>"><span><strong>SICA</strong><small>Sistema Inteligente de Control de Asistencia</small></span></a>
        <section class="admin-profile" aria-label="Administrador activo">
            <div class="admin-avatar">AD</div>
            <div><strong><?= admin_access_h($adminName) ?></strong><small><?= admin_access_h($adminMail) ?></small><span>En linea</span></div>
        </section>
        <nav class="admin-nav">
            <a href="<?= admin_access_h(app_url('admin/index.php')) ?>"><span class="nav-symbol nav-symbol-dashboard" aria-hidden="true"></span>Panel de Control</a>
            <a href="<?= admin_access_h(app_url('admin/usuarios.php')) ?>"><span class="nav-symbol nav-symbol-users" aria-hidden="true"></span>Usuarios</a>
            <a class="active" href="<?= admin_access_h(app_url('admin/solicitudes_usuarios.php')) ?>"><span class="nav-symbol nav-symbol-access" aria-hidden="true"></span>Solicitudes de Acceso</a>
            <a href="<?= admin_access_h(app_url('admin/solicitudes.php')) ?>"><span class="nav-symbol nav-symbol-reservations" aria-hidden="true"></span>Solicitudes de Reserva</a>
            <a href="<?= admin_access_h(app_url('admin/correos.php')) ?>"><span class="nav-symbol nav-symbol-mail" aria-hidden="true"></span>Correos y Notificaciones</a>
        </nav>
    </aside>

    <section class="admin-main">
        <header class="admin-topbar">
            <div><p class="admin-eyebrow">Acceso</p><h1>Solicitudes de usuarios</h1><span>Aprueba cuentas y envia contrasenas temporales rapidamente.</span></div>
            <div class="admin-top-actions"><a class="admin-logout" href="<?= admin_access_h(app_url('login/logout.php')) ?>">Cerrar sesion</a></div>
        </header>

        <?php if ($message !== ''): ?>
            <div class="admin-alert <?= admin_access_h($messageType) ?>"><?= admin_access_h($message) ?></div>
        <?php endif; ?>

        <section class="admin-panel users-panel">
            <div class="admin-panel-head"><div><p class="admin-eyebrow">Bandeja</p><h2>Solicitudes recibidas</h2></div></div>
            <div class="admin-users-list">
                <?php if (!$requests): ?>
                    <article class="admin-empty-state"><strong>No hay solicitudes.</strong><span>Cuando alguien solicite acceso, aparecera aqui.</span></article>
                <?php endif; ?>
                <?php foreach ($requests as $request): ?>
                    <?php $isPending = (string)$request['estado'] === 'Pendiente'; ?>
                    <article class="admin-user-card">
                        <div class="admin-user-avatar"><?= admin_access_h(mb_strtoupper(mb_substr((string)$request['nombre'], 0, 1) . mb_substr((string)$request['apellido'], 0, 1))) ?></div>
                        <div class="admin-user-main">
                            <div class="admin-user-title">
                                <div><h3><?= admin_access_h(trim((string)$request['nombre'] . ' ' . (string)$request['apellido'])) ?></h3><span><?= admin_access_h($request['correo']) ?></span></div>
                                <div class="admin-user-tags"><span><?= admin_access_h($request['nombre_rol']) ?></span><em><?= admin_access_h($request['estado']) ?></em></div>
                            </div>
                            <div class="admin-user-details">
                                <span><?= admin_access_h($request['tipo_documento']) ?> <strong><?= admin_access_h($request['id_documento']) ?></strong></span>
                                <?php if (!empty($request['id_ficha'])): ?><span>Ficha <strong><?= admin_access_h($request['id_ficha']) ?></strong></span><?php endif; ?>
                                <span>Solicitada <strong><?= admin_access_h(substr((string)$request['fecha_solicitud'], 0, 16)) ?></strong></span>
                            </div>
                            <?php if (!empty($request['nombre_programa'])): ?><p><?= admin_access_h($request['nombre_programa']) ?><?= !empty($request['nombre_jornada']) ? ' · ' . admin_access_h($request['nombre_jornada']) : '' ?></p><?php endif; ?>
                        </div>
                        <?php if ($isPending): ?>
                            <form class="admin-access-actions" method="post" action="<?= admin_access_h(app_url('admin/solicitudes_usuarios.php')) ?>">
                                <input type="hidden" name="csrf_admin_access" value="<?= admin_access_h($_SESSION['csrf_admin_access']) ?>">
                                <input type="hidden" name="id_solicitud" value="<?= admin_access_h($request['id_solicitud']) ?>">
                                <button type="submit" name="accion" value="aprobar">Aprobar</button>
                                <button class="secondary" type="submit" name="accion" value="rechazar">Rechazar</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </section>
</main>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
