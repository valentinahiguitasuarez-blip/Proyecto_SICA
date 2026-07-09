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
$hasDocumentTypeColumn = false;
$message = $_SESSION['admin_users_message'] ?? '';
$messageType = $_SESSION['admin_users_message_type'] ?? 'success';
unset($_SESSION['admin_users_message'], $_SESSION['admin_users_message_type']);

try {
    $roles = admin_rows($pdo, 'SELECT id_rol, nombre_rol FROM rol ORDER BY id_rol ASC');
    $estados = admin_rows($pdo, 'SELECT id_estado, nombre_estado FROM estado ORDER BY id_estado ASC');
    $hasDocumentTypeColumn = count(admin_rows($pdo, "SHOW COLUMNS FROM usuario LIKE 'tipo_documento'")) > 0;
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
$accountStates = array_values(array_filter(
    $estados,
    static fn(array $state): bool => in_array(
        mb_strtolower((string)$state['nombre_estado'], 'UTF-8'),
        ['activo', 'inactivo'],
        true
    )
));
$accountStateIds = array_map(static fn(array $state): int => (int)$state['id_estado'], $accountStates);
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
$roleNamesById = [];
foreach ($roles as $role) {
    $roleNamesById[(int)$role['id_rol']] = (string)$role['nombre_rol'];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = (string)($_POST['csrf_admin_users'] ?? '');
    $action = (string)($_POST['accion'] ?? 'actualizar');

    if (!hash_equals((string)$_SESSION['csrf_admin_users'], $csrf)) {
        $_SESSION['admin_users_message'] = 'La sesion expiro. Intenta de nuevo.';
        $_SESSION['admin_users_message_type'] = 'danger';
    } elseif ($action === 'crear') {
        $documentType = (string)($_POST['nuevo_tipo_documento'] ?? 'CC');
        $newDocumentRaw = trim((string)($_POST['nuevo_documento'] ?? ''));
        $newDocument = ctype_digit($newDocumentRaw) ? (int)$newDocumentRaw : 0;
        $newName = trim((string)($_POST['nuevo_nombre'] ?? ''));
        $newLastName = trim((string)($_POST['nuevo_apellido'] ?? ''));
        $newMail = mb_strtolower(trim((string)($_POST['nuevo_correo'] ?? '')), 'UTF-8');
        $newPhone = trim((string)($_POST['nuevo_telefono'] ?? ''));
        $newPassword = (string)($_POST['nuevo_contrasena'] ?? '');
        $newRole = (int)($_POST['nuevo_id_rol'] ?? 0);
        $newState = (int)($_POST['nuevo_id_estado'] ?? 0);
        $newFichaRaw = trim((string)($_POST['nuevo_id_ficha'] ?? ''));
        $newFicha = $newFichaRaw === '' ? null : (int)$newFichaRaw;
        $newRoleName = mb_strtolower($roleNamesById[$newRole] ?? '', 'UTF-8');
        if (!str_contains($newRoleName, 'aprendiz')) {
            $newFicha = null;
        }

        if (!in_array($documentType, ['CC', 'TI', 'CE', 'PEP'], true)) {
            $_SESSION['admin_users_message'] = 'Selecciona un tipo de documento valido.';
            $_SESSION['admin_users_message_type'] = 'danger';
        } elseif ($newDocument <= 0 || strlen($newDocumentRaw) > 20) {
            $_SESSION['admin_users_message'] = 'El documento debe tener solo numeros y maximo 20 digitos.';
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
        } elseif (!password_meets_policy($newPassword)) {
            $_SESSION['admin_users_message'] = password_policy_message();
            $_SESSION['admin_users_message_type'] = 'danger';
        } elseif (!in_array($newRole, $roleIds, true) || !in_array($newState, $accountStateIds, true)) {
            $_SESSION['admin_users_message'] = 'Selecciona un rol y estado validos.';
            $_SESSION['admin_users_message_type'] = 'danger';
        } elseif ($newFicha !== null && !in_array($newFicha, $fichaIds, true)) {
            $_SESSION['admin_users_message'] = 'Selecciona una ficha valida o deja el campo sin ficha.';
            $_SESSION['admin_users_message_type'] = 'danger';
        } else {
            try {
                $columns = 'id_documento, nombre, apellido, correo, contrasena, telefono, fecha_registro, id_rol, id_ficha, id_estado';
                $values = ':id_documento, :nombre, :apellido, :correo, :contrasena, :telefono, CURDATE(), :id_rol, :id_ficha, :id_estado';
                $insertParams = [
                    ':id_documento' => $newDocument,
                    ':nombre' => $newName,
                    ':apellido' => $newLastName,
                    ':correo' => $newMail,
                    ':contrasena' => password_hash($newPassword, PASSWORD_DEFAULT),
                    ':telefono' => $newPhone !== '' ? $newPhone : null,
                    ':id_rol' => $newRole,
                    ':id_ficha' => $newFicha,
                    ':id_estado' => $newState,
                ];

                if ($hasDocumentTypeColumn) {
                    $columns .= ', tipo_documento';
                    $values .= ', :tipo_documento';
                    $insertParams[':tipo_documento'] = $documentType;
                }

                $insert = $pdo->prepare(
                    'INSERT INTO usuario (' . $columns . ')
                     VALUES (' . $values . ')'
                );
                $insert->execute($insertParams);

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
        $editDocumentType = (string)($_POST['tipo_documento'] ?? 'CC');
        $editName = trim((string)($_POST['nombre'] ?? ''));
        $editLastName = trim((string)($_POST['apellido'] ?? ''));
        $editMail = mb_strtolower(trim((string)($_POST['correo'] ?? '')), 'UTF-8');
        $editPhone = trim((string)($_POST['telefono'] ?? ''));
        $editFichaRaw = trim((string)($_POST['id_ficha'] ?? ''));
        $editFicha = $editFichaRaw === '' ? null : (int)$editFichaRaw;
        $newRole = (int)($_POST['id_rol'] ?? 0);
        $newState = (int)($_POST['id_estado'] ?? 0);
        $editRoleName = mb_strtolower($roleNamesById[$newRole] ?? '', 'UTF-8');
        if (!str_contains($editRoleName, 'aprendiz')) {
            $editFicha = null;
        }

        if ($targetDocument <= 0 || !in_array($newRole, $roleIds, true) || !in_array($newState, $accountStateIds, true)) {
            $_SESSION['admin_users_message'] = 'Selecciona un usuario, rol y estado validos.';
            $_SESSION['admin_users_message_type'] = 'danger';
        } elseif (!in_array($editDocumentType, ['CC', 'TI', 'CE', 'PEP'], true)) {
            $_SESSION['admin_users_message'] = 'Selecciona un tipo de documento valido.';
            $_SESSION['admin_users_message_type'] = 'danger';
        } elseif ($editName === '' || $editLastName === '' || strlen($editName) > 50 || strlen($editLastName) > 50) {
            $_SESSION['admin_users_message'] = 'Escribe nombre y apellido de maximo 50 caracteres.';
            $_SESSION['admin_users_message_type'] = 'danger';
        } elseif ($editMail === '' || strlen($editMail) > 60 || !filter_var($editMail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['admin_users_message'] = 'Escribe un correo valido de maximo 60 caracteres.';
            $_SESSION['admin_users_message_type'] = 'danger';
        } elseif ($editPhone !== '' && strlen($editPhone) > 15) {
            $_SESSION['admin_users_message'] = 'El telefono no puede superar 15 caracteres.';
            $_SESSION['admin_users_message_type'] = 'danger';
        } elseif ($editFicha !== null && !in_array($editFicha, $fichaIds, true)) {
            $_SESSION['admin_users_message'] = 'Selecciona una ficha valida o deja el campo sin ficha.';
            $_SESSION['admin_users_message_type'] = 'danger';
        } elseif ($targetDocument === $adminDocument && ($newRole !== 1 || ($activeStateId !== null && $newState !== $activeStateId))) {
            $_SESSION['admin_users_message'] = 'No puedes quitarte el acceso administrativo desde tu propia cuenta.';
            $_SESSION['admin_users_message_type'] = 'danger';
        } else {
            try {
                $duplicate = $pdo->prepare('SELECT id_documento FROM usuario WHERE correo = :correo AND id_documento <> :id_documento LIMIT 1');
                $duplicate->execute([
                    ':correo' => $editMail,
                    ':id_documento' => $targetDocument,
                ]);
                if ($duplicate->fetch()) {
                    throw new RuntimeException('correo_duplicado');
                }

                $typeSql = $hasDocumentTypeColumn ? ', tipo_documento = :tipo_documento' : '';
                $update = $pdo->prepare(
                    'UPDATE usuario
                     SET nombre = :nombre,
                         apellido = :apellido,
                         correo = :correo,
                         telefono = :telefono,
                         id_ficha = :id_ficha,
                         id_rol = :id_rol,
                         id_estado = :id_estado
                         ' . $typeSql . '
                     WHERE id_documento = :id_documento'
                );
                $updateParams = [
                    ':nombre' => $editName,
                    ':apellido' => $editLastName,
                    ':correo' => $editMail,
                    ':telefono' => $editPhone !== '' ? $editPhone : null,
                    ':id_ficha' => $editFicha,
                    ':id_rol' => $newRole,
                    ':id_estado' => $newState,
                    ':id_documento' => $targetDocument,
                ];
                if ($hasDocumentTypeColumn) {
                    $updateParams[':tipo_documento'] = $editDocumentType;
                }
                $update->execute($updateParams);

                $_SESSION['admin_users_message'] = 'Usuario actualizado correctamente.';
                $_SESSION['admin_users_message_type'] = 'success';
            } catch (Throwable $exception) {
                $_SESSION['admin_users_message'] = $exception->getMessage() === 'correo_duplicado'
                    ? 'Ese correo ya esta registrado por otro usuario.'
                    : 'No fue posible actualizar el usuario.';
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
    $searchColumns = [
        'CAST(u.id_documento AS CHAR)',
        'u.nombre',
        'u.apellido',
        "CONCAT(u.nombre, ' ', u.apellido)",
        "CONCAT(u.apellido, ' ', u.nombre)",
        'u.correo',
        'CAST(f.id_ficha AS CHAR)',
        'p.nombre_programa',
    ];
    $searchParts = [];
    foreach ($searchColumns as $index => $column) {
        $param = ':search_' . $index;
        $searchParts[] = $column . ' LIKE ' . $param;
        $params[$param] = '%' . $search . '%';
    }
    $where[] = '(' . implode(' OR ', $searchParts) . ')';
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

    $documentTypeSelect = $hasDocumentTypeColumn ? 'u.tipo_documento,' : "'CC' AS tipo_documento,";

    $users = admin_rows(
        $pdo,
        'SELECT u.id_documento, ' . $documentTypeSelect . ' u.nombre, u.apellido, u.correo, u.telefono, u.foto_perfil, u.fecha_registro,
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
        ' GROUP BY u.id_documento, ' . ($hasDocumentTypeColumn ? 'u.tipo_documento, ' : '') . 'u.nombre, u.apellido, u.correo, u.telefono, u.foto_perfil, u.fecha_registro,
                 u.id_rol, u.id_estado, u.id_ficha, r.nombre_rol, es.nombre_estado,
                 p.nombre_programa, j.nombre_jornada
          ORDER BY u.fecha_registro DESC, u.nombre ASC',
        $params
    );
} catch (Throwable $exception) {
    error_log('SICA admin usuarios: ' . $exception->getMessage());
}
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>

<main class="admin-dashboard">
    <aside class="admin-sidebar" aria-label="Menu del administrador">
        <a class="admin-brand admin-brand--with-mark" href="<?= admin_h(app_url('admin/index.php')) ?>">
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
            <a href="<?= admin_h(app_url('admin/index.php')) ?>"><span class="nav-symbol nav-symbol-dashboard" aria-hidden="true"></span>Panel de Control</a>
            <a class="active" href="<?= admin_h(app_url('admin/usuarios.php')) ?>"><span class="nav-symbol nav-symbol-users" aria-hidden="true"></span>Usuarios</a>
            <a href="<?= admin_h(app_url('admin/solicitudes.php')) ?>"><span class="nav-symbol nav-symbol-reservations" aria-hidden="true"></span>Solicitudes de Reserva</a>
            <a href="<?= admin_h(app_url('admin/auditorios.php')) ?>"><span class="nav-symbol nav-symbol-auditoriums" aria-hidden="true"></span>Auditorios</a>
            <a href="<?= admin_h(app_url('admin/reportes.php')) ?>"><span class="nav-symbol nav-symbol-reports" aria-hidden="true"></span>Reportes</a>
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
                <div class="admin-panel-actions">
                    <a class="admin-create-user-open admin-create-user-secondary" href="<?= admin_h(app_url('admin/solicitudes_usuarios.php')) ?>">Solicitudes</a>
                    <button type="button" class="admin-create-user-open" data-open-create-user>Crear usuario</button>
                </div>
            </div>

            <form class="admin-user-filters" method="get" action="<?= admin_h(app_url('admin/usuarios.php')) ?>">
                <label>
                    <span>Busqueda rapida</span>
                    <input type="search" name="q" value="<?= admin_h($search) ?>">
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
                        <?php foreach ($accountStates as $estado): ?>
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
                    $isApprenticeUser = str_contains(mb_strtolower((string)$item['nombre_rol'], 'UTF-8'), 'aprendiz');
                    $photo = trim((string)($item['foto_perfil'] ?? ''));
                    ?>
                    <article class="admin-user-card">
                        <div class="admin-user-avatar">
                            <?php if ($photo !== ''): ?>
                                <img src="<?= admin_h(app_url($photo)) ?>" alt="">
                            <?php else: ?>
                                <?= admin_h($initials) ?>
                            <?php endif; ?>
                        </div>
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
                                <span><?= admin_h($item['tipo_documento'] ?? 'CC') ?> <strong><?= admin_h($item['id_documento']) ?></strong></span>
                                <?php if ($isApprenticeUser): ?>
                                    <span>Ficha <strong><?= admin_h($item['id_ficha'] ?? 'Sin ficha') ?></strong></span>
                                    <span>Pre-registros <strong><?= admin_h((int)$item['preregistros']) ?></strong></span>
                                    <span>Asistencias <strong><?= admin_h((int)$item['asistencias']) ?></strong></span>
                                <?php endif; ?>
                            </div>

                            <?php if ($isApprenticeUser): ?>
                                <p>
                                    <?= admin_h($item['nombre_programa'] ?? 'Programa no asignado') ?>
                                    <?php if (!empty($item['nombre_jornada'])): ?>
                                        &middot; <?= admin_h($item['nombre_jornada']) ?>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <details class="admin-user-edit">
                            <summary><?= $isCurrentAdmin ? 'Protegido' : 'Editar' ?></summary>
                            <form class="admin-user-actions" method="post" action="<?= admin_h(app_url('admin/usuarios.php')) ?>">
                                <input type="hidden" name="csrf_admin_users" value="<?= admin_h($_SESSION['csrf_admin_users']) ?>">
                                <input type="hidden" name="accion" value="actualizar">
                                <input type="hidden" name="id_documento" value="<?= admin_h($item['id_documento']) ?>">
                                <div class="admin-user-actions-title">
                                    <strong>Datos personales</strong>
                                    <small>Actualiza informacion de contacto e identificacion.</small>
                                </div>
                                <label>
                                    <span>Nombre</span>
                                    <input type="text" name="nombre" value="<?= admin_h($item['nombre']) ?>" maxlength="50" required <?= $isCurrentAdmin ? 'disabled' : '' ?>>
                                </label>
                                <label>
                                    <span>Apellido</span>
                                    <input type="text" name="apellido" value="<?= admin_h($item['apellido']) ?>" maxlength="50" required <?= $isCurrentAdmin ? 'disabled' : '' ?>>
                                </label>
                                <label>
                                    <span>Correo</span>
                                    <input type="email" name="correo" value="<?= admin_h($item['correo']) ?>" maxlength="60" required <?= $isCurrentAdmin ? 'disabled' : '' ?>>
                                </label>
                                <label>
                                    <span>Telefono</span>
                                    <input type="text" name="telefono" value="<?= admin_h($item['telefono'] ?? '') ?>" maxlength="15" placeholder="Opcional" <?= $isCurrentAdmin ? 'disabled' : '' ?>>
                                </label>
                                <label>
                                    <span>Tipo documento</span>
                                    <select name="tipo_documento" <?= $isCurrentAdmin ? 'disabled' : '' ?>>
                                        <?php foreach (['CC' => 'CC', 'TI' => 'TI', 'CE' => 'CE', 'PEP' => 'PEP'] as $docType => $docLabel): ?>
                                            <option value="<?= admin_h($docType) ?>" <?= (string)($item['tipo_documento'] ?? 'CC') === $docType ? 'selected' : '' ?>>
                                                <?= admin_h($docLabel) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <div class="admin-user-actions-title admin-user-access-title">
                                    <strong>Acceso</strong>
                                    <small>Define rol, estado y ficha solo para aprendices.</small>
                                </div>
                                <label>
                                    <span>Rol</span>
                                    <select name="id_rol" data-role-select <?= $isCurrentAdmin ? 'disabled' : '' ?>>
                                        <?php foreach ($roles as $role): ?>
                                            <option value="<?= admin_h($role['id_rol']) ?>" <?= (int)$item['id_rol'] === (int)$role['id_rol'] ? 'selected' : '' ?>>
                                                <?= admin_h($role['nombre_rol']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="admin-user-actions-wide" data-ficha-field>
                                    <span>Ficha</span>
                                    <div class="admin-ficha-field">
                                        <input type="text" name="id_ficha" value="<?= admin_h($item['id_ficha'] ?? '') ?>" list="fichasUsuario" inputmode="numeric" placeholder="Buscar por ficha o programa" <?= $isCurrentAdmin ? 'disabled' : '' ?>>
                                    </div>
                                </label>
                                <label>
                                    <span>Estado</span>
                                    <select name="id_estado" <?= $isCurrentAdmin ? 'disabled' : '' ?>>
                                        <?php foreach ($accountStates as $estado): ?>
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
                <span>Tipo documento</span>
                <select name="nuevo_tipo_documento" required>
                    <option value="CC">C&eacute;dula de ciudadan&iacute;a</option>
                    <option value="TI">Tarjeta de identidad</option>
                    <option value="CE">C&eacute;dula de extranjer&iacute;a</option>
                    <option value="PEP">PEP</option>
                </select>
            </label>
            <label>
                <span>Documento</span>
                <input type="text" name="nuevo_documento" inputmode="numeric" pattern="\d{1,20}" maxlength="20" required>
            </label>
            <label>
                <span>Nombre</span>
                <input type="text" name="nuevo_nombre" maxlength="50" required>
            </label>
            <label>
                <span>Apellido</span>
                <input type="text" name="nuevo_apellido" maxlength="50" required>
            </label>
            <label>
                <span>Correo</span>
                <input type="email" name="nuevo_correo" maxlength="60" required>
            </label>
            <label>
                <span>Telefono</span>
                <input type="text" name="nuevo_telefono" maxlength="15">
            </label>
            <label>
                <span>Contrasena temporal</span>
                <input type="text" name="nuevo_contrasena" minlength="6" maxlength="72" required>
            </label>
            <label>
                <span>Rol</span>
                <select name="nuevo_id_rol" required data-role-select>
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
                    <?php foreach ($accountStates as $estado): ?>
                        <option value="<?= admin_h($estado['id_estado']) ?>" <?= (int)$estado['id_estado'] === $defaultStateId ? 'selected' : '' ?>>
                            <?= admin_h($estado['nombre_estado']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="admin-create-user-wide" data-ficha-field>
                <span>Ficha</span>
                <div class="admin-ficha-field">
                    <input type="text" name="nuevo_id_ficha" list="fichasUsuario" inputmode="numeric" placeholder="Buscar por numero de ficha o programa">
                </div>
            </label>
        </div>

        <div class="admin-create-user-submit">
            <small>La persona ingresara con este correo y la contrasena temporal.</small>
            <button type="submit">Crear usuario</button>
        </div>
    </form>
</dialog>

<datalist id="fichasUsuario">
    <?php foreach ($fichas as $ficha): ?>
        <option value="<?= admin_h($ficha['id_ficha']) ?>">
            <?= admin_h($ficha['id_ficha']) ?> - <?= admin_h($ficha['nombre_programa'] ?? 'Programa no asignado') ?><?= !empty($ficha['nombre_jornada']) ? ' / ' . admin_h($ficha['nombre_jornada']) : '' ?>
        </option>
    <?php endforeach; ?>
</datalist>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const dialog = document.getElementById('createUserDialog');
    const openButton = document.querySelector('[data-open-create-user]');
    const closeButton = document.querySelector('[data-close-create-user]');

    const updateFichaVisibility = function (select) {
        const form = select.closest('form');
        if (!form) {
            return;
        }

        const fichaField = form.querySelector('[data-ficha-field]');
        if (!fichaField) {
            return;
        }

        const selectedText = select.options[select.selectedIndex] ? select.options[select.selectedIndex].textContent.toLowerCase() : '';
        const isApprentice = selectedText.includes('aprendiz');
        const fichaInput = fichaField.querySelector('input');

        fichaField.hidden = !isApprentice;
        if (!isApprentice && fichaInput) {
            fichaInput.value = '';
        }
    };

    document.querySelectorAll('[data-role-select]').forEach(function (select) {
        updateFichaVisibility(select);
        select.addEventListener('change', function () {
            updateFichaVisibility(select);
        });
    });

    if (dialog && openButton) {
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
    }
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
