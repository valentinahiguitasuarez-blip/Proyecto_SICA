<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([1]);
require_once __DIR__ . '/../config/conexion.php';

$pageTitle = 'Usuarios - Administrador SICA';
$pageStyles = ['css/admin.css'];

$usuario = $_SESSION['usuario'] ?? [];
$adminName = trim((string)($usuario['nombre'] ?? 'Administrador'));
$adminMail = (string)($usuario['correo'] ?? 'admin@sica.edu.co');
$adminDocument = (int)($usuario['id_documento'] ?? 0);

function admin_h(string|int|null $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function admin_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function admin_scalar(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

if (empty($_SESSION['csrf_admin_users'])) {
    $_SESSION['csrf_admin_users'] = bin2hex(random_bytes(32));
}

$roles = [];
$estados = [];
$fichas = [];
$message = $_SESSION['admin_users_message'] ?? '';
$messageType = $_SESSION['admin_users_message_type'] ?? 'success';
unset($_SESSION['admin_users_message'], $_SESSION['admin_users_message_type']);

try {
    $roles = admin_rows($pdo, 'SELECT id_rol, nombre_rol FROM rol ORDER BY id_rol ASC');
    $estados = admin_rows($pdo, 'SELECT id_estado, nombre_estado FROM estado ORDER BY id_estado ASC');
    $fichas = admin_rows(
        $pdo,
        'SELECT f.id_ficha, p.nombre_programa, j.nombre_jornada
         FROM ficha f
         LEFT JOIN programa p ON p.id_programa = f.id_programa
         LEFT JOIN jornada j ON j.id_jornada = p.id_jornada
         ORDER BY f.id_ficha DESC'
    );
} catch (Throwable $exception) {
    error_log('SICA admin usuarios catalogos: ' . $exception->getMessage());
}

$roleIds = array_map(static fn(array $role): int => (int)$role['id_rol'], $roles);
$stateIds = array_map(static fn(array $state): int => (int)$state['id_estado'], $estados);
$fichaIds = array_map(static fn(array $ficha): int => (int)$ficha['id_ficha'], $fichas);
$activeStateId = null;
$apprenticeRoleId = null;
foreach ($estados as $estado) {
    if (mb_strtolower((string)$estado['nombre_estado'], 'UTF-8') === 'activo') {
        $activeStateId = (int)$estado['id_estado'];
        break;
    }
}
foreach ($roles as $role) {
    if (str_contains(mb_strtolower((string)$role['nombre_rol'], 'UTF-8'), 'aprendiz')) {
        $apprenticeRoleId = (int)$role['id_rol'];
        break;
    }
}
$defaultRoleId = $apprenticeRoleId ?? ($roleIds[0] ?? 0);
$defaultStateId = $activeStateId ?? ($stateIds[0] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = (string)($_POST['csrf_admin_users'] ?? '');
    $action = (string)($_POST['accion'] ?? 'actualizar');

    if (!hash_equals((string)$_SESSION['csrf_admin_users'], $csrf)) {
        $_SESSION['admin_users_message'] = 'La sesion expiro. Intenta de nuevo.';
        $_SESSION['admin_users_message_type'] = 'danger';
    } elseif ($action === 'crear') {
        $newDocument = (int)($_POST['nuevo_documento'] ?? 0);
        $newName = trim((string)($_POST['nuevo_nombre'] ?? ''));
        $newLastName = trim((string)($_POST['nuevo_apellido'] ?? ''));
        $newMail = mb_strtolower(trim((string)($_POST['nuevo_correo'] ?? '')), 'UTF-8');
        $newPhone = trim((string)($_POST['nuevo_telefono'] ?? ''));
        $newPassword = (string)($_POST['nuevo_contrasena'] ?? '');
        $newRole = (int)($_POST['nuevo_id_rol'] ?? 0);
        $newState = (int)($_POST['nuevo_id_estado'] ?? 0);
        $newFichaRaw = trim((string)($_POST['nuevo_id_ficha'] ?? ''));
        $newFicha = $newFichaRaw === '' ? null : (int)$newFichaRaw;

        if ($newDocument <= 0) {
            $_SESSION['admin_users_message'] = 'El documento debe ser un numero valido.';
            $_SESSION['admin_users_message_type'] = 'danger';
        } elseif ($newName === '' || $newLastName === '' || strlen($newName) > 50 || strlen($newLastName) > 50) {
            $_SESSION['admin_users_message'] = 'Escribe nombre y apellido de maximo 50 caracteres.';
            $_SESSION['admin_users_message_type'] = 'danger';
        } elseif ($newMail === '' || strlen($newMail) > 60 || !filter_var($newMail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['admin_users_message'] = 'Escribe un correo valido de maximo 60 caracteres.';
            $_SESSION['admin_users_message_type'] = 'danger';
        } elseif ($newPhone !== '' && strlen($newPhone) > 15) {
            $_SESSION['admin_users_message'] = 'El telefono no puede superar 15 caracteres.';
            $_SESSION['admin_users_message_type'] = 'danger';
        } elseif (strlen($newPassword) < 6 || strlen($newPassword) > 72) {
            $_SESSION['admin_users_message'] = 'La contrasena temporal debe tener entre 6 y 72 caracteres.';
            $_SESSION['admin_users_message_type'] = 'danger';
        } elseif (!in_array($newRole, $roleIds, true) || !in_array($newState, $stateIds, true)) {
            $_SESSION['admin_users_message'] = 'Selecciona un rol y estado validos.';
            $_SESSION['admin_users_message_type'] = 'danger';
        } elseif ($newFicha !== null && !in_array($newFicha, $fichaIds, true)) {
            $_SESSION['admin_users_message'] = 'Selecciona una ficha valida o deja el campo sin ficha.';
            $_SESSION['admin_users_message_type'] = 'danger';
        } else {
            try {
                $insert = $pdo->prepare(
                    'INSERT INTO usuario
                        (id_documento, nombre, apellido, correo, contrasena, telefono, fecha_registro, id_rol, id_ficha, id_estado)
                     VALUES
                        (:id_documento, :nombre, :apellido, :correo, :contrasena, :telefono, CURDATE(), :id_rol, :id_ficha, :id_estado)'
                );
                $insert->execute([
                    ':id_documento' => $newDocument,
                    ':nombre' => $newName,
                    ':apellido' => $newLastName,
                    ':correo' => $newMail,
                    ':contrasena' => password_hash($newPassword, PASSWORD_DEFAULT),
                    ':telefono' => $newPhone !== '' ? $newPhone : null,
                    ':id_rol' => $newRole,
                    ':id_ficha' => $newFicha,
                    ':id_estado' => $newState,
                ]);

                $_SESSION['admin_users_message'] = 'Usuario creado correctamente. Puede ingresar con el correo y la contrasena temporal.';
                $_SESSION['admin_users_message_type'] = 'success';
            } catch (PDOException $exception) {
                $_SESSION['admin_users_message'] = $exception->getCode() === '23000'
                    ? 'Ya existe un usuario con ese documento o correo.'
                    : 'No fue posible crear el usuario.';
                $_SESSION['admin_users_message_type'] = 'danger';
                error_log('SICA admin usuarios crear: ' . $exception->getMessage());
            } catch (Throwable $exception) {
                $_SESSION['admin_users_message'] = 'No fue posible crear el usuario.';
                $_SESSION['admin_users_message_type'] = 'danger';
                error_log('SICA admin usuarios crear: ' . $exception->getMessage());
            }
        }
    } else {
        $targetDocument = (int)($_POST['id_documento'] ?? 0);
        $newRole = (int)($_POST['id_rol'] ?? 0);
        $newState = (int)($_POST['id_estado'] ?? 0);

        if ($targetDocument <= 0 || !in_array($newRole, $roleIds, true) || !in_array($newState, $stateIds, true)) {
            $_SESSION['admin_users_message'] = 'Selecciona un usuario, rol y estado validos.';
            $_SESSION['admin_users_message_type'] = 'danger';
        } elseif ($targetDocument === $adminDocument && ($newRole !== 1 || ($activeStateId !== null && $newState !== $activeStateId))) {
            $_SESSION['admin_users_message'] = 'No puedes quitarte el acceso administrativo desde tu propia cuenta.';
            $_SESSION['admin_users_message_type'] = 'danger';
        } else {
            try {
                $update = $pdo->prepare(
                    'UPDATE usuario
                     SET id_rol = :id_rol,
                         id_estado = :id_estado
                     WHERE id_documento = :id_documento'
                );
                $update->execute([
                    ':id_rol' => $newRole,
                    ':id_estado' => $newState,
                    ':id_documento' => $targetDocument,
                ]);

                $_SESSION['admin_users_message'] = 'Usuario actualizado correctamente.';
                $_SESSION['admin_users_message_type'] = 'success';
            } catch (Throwable $exception) {
                $_SESSION['admin_users_message'] = 'No fue posible actualizar el usuario.';
                $_SESSION['admin_users_message_type'] = 'danger';
                error_log('SICA admin usuarios actualizar: ' . $exception->getMessage());
            }
        }
    }

    header('Location: ' . app_url('admin/usuarios.php'));
    exit;
}

$search = trim((string)($_GET['q'] ?? ''));
$roleFilter = (int)($_GET['rol'] ?? 0);
$stateFilter = (int)($_GET['estado'] ?? 0);
$params = [];
$where = [];

if ($search !== '') {
    $where[] = '(CAST(u.id_documento AS CHAR) LIKE :search OR u.nombre LIKE :search OR u.apellido LIKE :search OR u.correo LIKE :search OR CAST(f.id_ficha AS CHAR) LIKE :search OR p.nombre_programa LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}

if ($roleFilter > 0) {
    $where[] = 'u.id_rol = :role_filter';
    $params[':role_filter'] = $roleFilter;
}

if ($stateFilter > 0) {
    $where[] = 'u.id_estado = :state_filter';
    $params[':state_filter'] = $stateFilter;
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$users = [];
$stats = [
    'total' => 0,
    'activos' => 0,
    'aprendices' => 0,
    'instructores' => 0,
];

try {
    $stats['total'] = admin_scalar($pdo, 'SELECT COUNT(*) FROM usuario');
    $stats['activos'] = admin_scalar(
        $pdo,
        "SELECT COUNT(*)
         FROM usuario u
         INNER JOIN estado es ON es.id_estado = u.id_estado
         WHERE es.nombre_estado = 'Activo'"
    );
    $stats['aprendices'] = admin_scalar(
        $pdo,
        "SELECT COUNT(*)
         FROM usuario u
         INNER JOIN rol r ON r.id_rol = u.id_rol
         WHERE LOWER(r.nombre_rol) LIKE '%aprendiz%'"
    );
    $stats['instructores'] = admin_scalar(
        $pdo,
        "SELECT COUNT(*)
         FROM usuario u
         INNER JOIN rol r ON r.id_rol = u.id_rol
         WHERE LOWER(r.nombre_rol) LIKE '%instructor%'"
    );

    $users = admin_rows(
        $pdo,
        'SELECT u.id_documento, u.nombre, u.apellido, u.correo, u.telefono, u.fecha_registro,
                u.id_rol, u.id_estado, u.id_ficha, r.nombre_rol, es.nombre_estado,
                p.nombre_programa, j.nombre_jornada,
                COUNT(pr.id_preregistro) AS preregistros,
                SUM(CASE WHEN pr.asistencia <> \'Pendiente\' THEN 1 ELSE 0 END) AS asistencias
         FROM usuario u
         INNER JOIN rol r ON r.id_rol = u.id_rol
         INNER JOIN estado es ON es.id_estado = u.id_estado
         LEFT JOIN ficha f ON f.id_ficha = u.id_ficha
         LEFT JOIN programa p ON p.id_programa = f.id_programa
         LEFT JOIN jornada j ON j.id_jornada = p.id_jornada
         LEFT JOIN preregistro pr ON pr.id_documento = u.id_documento' .
            $whereSql .
        ' GROUP BY u.id_documento, u.nombre, u.apellido, u.correo, u.telefono, u.fecha_registro,
                 u.id_rol, u.id_estado, u.id_ficha, r.nombre_rol, es.nombre_estado,
                 p.nombre_programa, j.nombre_jornada
          ORDER BY u.fecha_registro DESC, u.nombre ASC
          LIMIT 80',
        $params
    );
} catch (Throwable $exception) {
    error_log('SICA admin usuarios: ' . $exception->getMessage());
}
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>

<main class="admin-dashboard">
    <aside class="admin-sidebar" aria-label="Menu del administrador">
        <a class="admin-brand" href="<?= admin_h(app_url('admin/index.php')) ?>">
            <span>
                <strong>SICA</strong>
                <small>Sistema Inteligente de Control de Asistencia</small>
            </span>
        </a>

        <section class="admin-profile" aria-label="Administrador activo">
            <div class="admin-avatar">AD</div>
            <div>
                <strong><?= admin_h($adminName) ?></strong>
                <small><?= admin_h($adminMail) ?></small>
                <span>En linea</span>
            </div>
        </section>

        <nav class="admin-nav">
            <a href="<?= admin_h(app_url('admin/index.php')) ?>"><span>PC</span>Panel de Control</a>
            <a class="active" href="<?= admin_h(app_url('admin/usuarios.php')) ?>"><span>US</span>Usuarios</a>
            <a href="<?= admin_h(app_url('admin/solicitudes.php')) ?>"><span>SR</span>Solicitudes de Reserva</a>
            <a href="<?= admin_h(app_url('admin/correos.php')) ?>"><span>CN</span>Correos y Notificaciones</a>
            <a href="<?= admin_h(app_url('admin/auditorios.php')) ?>"><span>AU</span>Auditorios</a>
            <a href="<?= admin_h(app_url('admin/reportes.php')) ?>"><span>RP</span>Reportes</a>
        </nav>
    </aside>

    <section class="admin-main">
        <header class="admin-topbar">
            <div>
                <p class="admin-eyebrow">Usuarios del sistema</p>
                <h1>Gestion de usuarios</h1>
                <span>Administra las cuentas que participan en pre-registros, eventos y asistencias del auditorio.</span>
            </div>
            <div class="admin-top-actions">
                <a href="<?= admin_h(app_url('admin/index.php')) ?>">Panel <strong>IN</strong></a>
                <a class="admin-logout" href="<?= admin_h(app_url('login/logout.php')) ?>">Cerrar sesion</a>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <div class="admin-alert <?= admin_h($messageType) ?>">
                <?= admin_h($message) ?>
            </div>
        <?php endif; ?>

        <section class="admin-metrics users-metrics" aria-label="Resumen de usuarios">
            <article class="admin-metric">
                <span>Total usuarios</span>
                <strong><?= admin_h($stats['total']) ?></strong>
                <small>Cuentas registradas</small>
            </article>
            <article class="admin-metric">
                <span>Activos</span>
                <strong><?= admin_h($stats['activos']) ?></strong>
                <small>Con acceso vigente</small>
            </article>
            <article class="admin-metric">
                <span>Aprendices</span>
                <strong><?= admin_h($stats['aprendices']) ?></strong>
                <small>Usuarios con ficha</small>
            </article>
            <article class="admin-metric">
                <span>Instructores</span>
                <strong><?= admin_h($stats['instructores']) ?></strong>
                <small>Gestionan eventos</small>
            </article>
        </section>

        <section class="admin-panel users-panel">
            <div class="admin-panel-head">
                <div>
                    <p class="admin-eyebrow">Directorio</p>
                    <h2>Usuarios registrados</h2>
                </div>
                <button type="button" class="admin-create-user-open" data-open-create-user>Crear usuario</button>
            </div>

            <form class="admin-user-filters" method="get" action="<?= admin_h(app_url('admin/usuarios.php')) ?>">
                <label>
                    <span>Busqueda rapida</span>
                    <input type="search" name="q" value="<?= admin_h($search) ?>" placeholder="Nombre, correo, documento o ficha">
                </label>
                <label>
                    <span>Rol</span>
                    <select name="rol">
                        <option value="0">Todos</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= admin_h($role['id_rol']) ?>" <?= $roleFilter === (int)$role['id_rol'] ? 'selected' : '' ?>>
                                <?= admin_h($role['nombre_rol']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Estado</span>
                    <select name="estado">
                        <option value="0">Todos</option>
                        <?php foreach ($estados as $estado): ?>
                            <option value="<?= admin_h($estado['id_estado']) ?>" <?= $stateFilter === (int)$estado['id_estado'] ? 'selected' : '' ?>>
                                <?= admin_h($estado['nombre_estado']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit">Filtrar</button>
                <a href="<?= admin_h(app_url('admin/usuarios.php')) ?>">Limpiar</a>
            </form>

            <div class="admin-users-list">
                <?php if (!$users): ?>
                    <article class="admin-empty-state">
                        <strong>No hay usuarios para mostrar.</strong>
                        <span>Prueba con otro filtro o verifica que existan usuarios registrados.</span>
                    </article>
                <?php endif; ?>

                <?php foreach ($users as $item): ?>
                    <?php
                    $fullName = trim((string)$item['nombre'] . ' ' . (string)$item['apellido']);
                    $initials = mb_strtoupper(mb_substr((string)$item['nombre'], 0, 1, 'UTF-8') . mb_substr((string)$item['apellido'], 0, 1, 'UTF-8'), 'UTF-8');
                    $initials = $initials !== '' ? $initials : 'US';
                    $isCurrentAdmin = (int)$item['id_documento'] === $adminDocument;
                    ?>
                    <article class="admin-user-card">
                        <div class="admin-user-avatar"><?= admin_h($initials) ?></div>
                        <div class="admin-user-main">
                            <div class="admin-user-title">
                                <div>
                                    <h3><?= admin_h($fullName !== '' ? $fullName : 'Usuario SICA') ?></h3>
                                    <span><?= admin_h($item['correo']) ?></span>
                                </div>
                                <div class="admin-user-tags">
                                    <span><?= admin_h($item['nombre_rol']) ?></span>
                                    <em><?= admin_h($item['nombre_estado']) ?></em>
                                </div>
                            </div>

                            <div class="admin-user-details">
                                <span>Documento <strong><?= admin_h($item['id_documento']) ?></strong></span>
                                <span>Ficha <strong><?= admin_h($item['id_ficha'] ?? 'Sin ficha') ?></strong></span>
                                <span>Pre-registros <strong><?= admin_h((int)$item['preregistros']) ?></strong></span>
                                <span>Asistencias <strong><?= admin_h((int)$item['asistencias']) ?></strong></span>
                            </div>

                            <p>
                                <?= admin_h($item['nombre_programa'] ?? 'Programa no asignado') ?>
                                <?php if (!empty($item['nombre_jornada'])): ?>
                                    · <?= admin_h($item['nombre_jornada']) ?>
                                <?php endif; ?>
                            </p>
                        </div>

                        <details class="admin-user-edit">
                            <summary><?= $isCurrentAdmin ? 'Protegido' : 'Editar' ?></summary>
                            <form class="admin-user-actions" method="post" action="<?= admin_h(app_url('admin/usuarios.php')) ?>">
                                <input type="hidden" name="csrf_admin_users" value="<?= admin_h($_SESSION['csrf_admin_users']) ?>">
                                <input type="hidden" name="accion" value="actualizar">
                                <input type="hidden" name="id_documento" value="<?= admin_h($item['id_documento']) ?>">
                                <label>
                                    <span>Rol</span>
                                    <select name="id_rol" <?= $isCurrentAdmin ? 'disabled' : '' ?>>
                                        <?php foreach ($roles as $role): ?>
                                            <option value="<?= admin_h($role['id_rol']) ?>" <?= (int)$item['id_rol'] === (int)$role['id_rol'] ? 'selected' : '' ?>>
                                                <?= admin_h($role['nombre_rol']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span>Estado</span>
                                    <select name="id_estado" <?= $isCurrentAdmin ? 'disabled' : '' ?>>
                                        <?php foreach ($estados as $estado): ?>
                                            <option value="<?= admin_h($estado['id_estado']) ?>" <?= (int)$item['id_estado'] === (int)$estado['id_estado'] ? 'selected' : '' ?>>
                                                <?= admin_h($estado['nombre_estado']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <?php if ($isCurrentAdmin): ?>
                                    <small>Tu cuenta se protege para no perder acceso.</small>
                                <?php else: ?>
                                    <button type="submit">Guardar cambios</button>
                                <?php endif; ?>
                            </form>
                        </details>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </section>
</main>

<dialog class="admin-create-user-dialog" id="createUserDialog" aria-labelledby="createUserDialogTitle">
    <form class="admin-create-user-form" method="post" action="<?= admin_h(app_url('admin/usuarios.php')) ?>">
        <div class="admin-create-user-head">
            <div>
                <p class="admin-eyebrow">Nueva cuenta</p>
                <h2 id="createUserDialogTitle">Crear usuario</h2>
            </div>
            <button type="button" class="admin-create-user-close" data-close-create-user aria-label="Cerrar">&times;</button>
        </div>

        <input type="hidden" name="csrf_admin_users" value="<?= admin_h($_SESSION['csrf_admin_users']) ?>">
        <input type="hidden" name="accion" value="crear">

        <div class="admin-create-user-grid">
            <label>
                <span>Documento</span>
                <input type="number" name="nuevo_documento" min="1" required placeholder="Ej. 1234567890">
            </label>
            <label>
                <span>Nombre</span>
                <input type="text" name="nuevo_nombre" maxlength="50" required placeholder="Nombre">
            </label>
            <label>
                <span>Apellido</span>
                <input type="text" name="nuevo_apellido" maxlength="50" required placeholder="Apellido">
            </label>
            <label>
                <span>Correo</span>
                <input type="email" name="nuevo_correo" maxlength="60" required placeholder="correo@sena.edu.co">
            </label>
            <label>
                <span>Telefono</span>
                <input type="text" name="nuevo_telefono" maxlength="15" placeholder="Opcional">
            </label>
            <label>
                <span>Contrasena temporal</span>
                <input type="text" name="nuevo_contrasena" minlength="6" maxlength="72" required placeholder="Ej. Aprendiz123#">
            </label>
            <label>
                <span>Rol</span>
                <select name="nuevo_id_rol" required>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= admin_h($role['id_rol']) ?>" <?= (int)$role['id_rol'] === $defaultRoleId ? 'selected' : '' ?>>
                            <?= admin_h($role['nombre_rol']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Estado</span>
                <select name="nuevo_id_estado" required>
                    <?php foreach ($estados as $estado): ?>
                        <option value="<?= admin_h($estado['id_estado']) ?>" <?= (int)$estado['id_estado'] === $defaultStateId ? 'selected' : '' ?>>
                            <?= admin_h($estado['nombre_estado']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="admin-create-user-wide">
                <span>Ficha</span>
                <select name="nuevo_id_ficha">
                    <option value="">Sin ficha</option>
                    <?php foreach ($fichas as $ficha): ?>
                        <option value="<?= admin_h($ficha['id_ficha']) ?>">
                            <?= admin_h($ficha['id_ficha']) ?> - <?= admin_h($ficha['nombre_programa'] ?? 'Programa no asignado') ?><?= !empty($ficha['nombre_jornada']) ? ' / ' . admin_h($ficha['nombre_jornada']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="admin-create-user-submit">
            <small>La persona ingresara con este correo y la contrasena temporal.</small>
            <button type="submit">Crear usuario</button>
        </div>
    </form>
</dialog>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const dialog = document.getElementById('createUserDialog');
    const openButton = document.querySelector('[data-open-create-user]');
    const closeButton = document.querySelector('[data-close-create-user]');

    if (!dialog || !openButton) {
        return;
    }

    openButton.addEventListener('click', function () {
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', 'open');
        }
    });

    if (closeButton) {
        closeButton.addEventListener('click', function () {
            dialog.close();
        });
    }

    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) {
            dialog.close();
        }
    });
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
