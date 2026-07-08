<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([1]);
require_once __DIR__ . '/../config/conexion.php';

$pageTitle = 'Auditorios - Administrador SICA';
$pageStyles = ['css/admin.css'];

$usuario = $_SESSION['usuario'] ?? [];
$adminName = trim((string)($usuario['nombre'] ?? 'Administrador'));
$adminMail = (string)($usuario['correo'] ?? 'admin@sica.edu.co');

function admin_a_h(string|int|null $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function admin_a_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function admin_a_scalar(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function admin_a_equipment_label(mixed $value): string
{
    if ($value === null || $value === '') {
        return 'Por registrar';
    }

    return (int)$value === 1 ? 'Sí' : 'No';
}

function admin_a_equipment_value(array $source, string $key): ?int
{
    $value = (string)($source[$key] ?? '');
    if ($value === '') {
        return null;
    }

    return $value === '1' ? 1 : 0;
}

$auditorios = [];
$stats = ['activos' => 0, 'capacidad' => 0, 'eventos' => 0, 'ocupados' => 0];
$busqueda = trim((string)($_GET['q'] ?? ''));
$params = [];
$where = '';
$message = $_SESSION['admin_auditorio_message'] ?? '';
$messageType = $_SESSION['admin_auditorio_message_type'] ?? 'success';
unset($_SESSION['admin_auditorio_message'], $_SESSION['admin_auditorio_message_type']);

if (empty($_SESSION['csrf_admin_auditorios'])) {
    $_SESSION['csrf_admin_auditorios'] = bin2hex(random_bytes(32));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = (string)($_POST['csrf'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    $idAuditorio = (int)($_POST['id_auditorio'] ?? 0);
    $computadoresRaw = trim((string)($_POST['cantidad_computadores'] ?? ''));

    try {
        if (!hash_equals((string)$_SESSION['csrf_admin_auditorios'], $csrf)) {
            throw new RuntimeException('La sesión expiró. Intenta de nuevo.');
        }
        if ($action !== 'update_dotacion' || $idAuditorio <= 0) {
            throw new RuntimeException('No pudimos identificar el auditorio.');
        }
        if ($computadoresRaw !== '' && (!ctype_digit($computadoresRaw) || (int)$computadoresRaw > 999)) {
            throw new RuntimeException('La cantidad de computadores debe ser un número válido.');
        }

        $stmt = $pdo->prepare(
            'UPDATE auditorio
             SET cantidad_computadores = :computadores,
                 tiene_aire_acondicionado = :aire,
                 tiene_ventilador = :ventilador,
                 tiene_tablero = :tablero,
                 tiene_televisor = :televisor
             WHERE id_auditorio = :id'
        );
        $stmt->execute([
            ':computadores' => $computadoresRaw === '' ? null : (int)$computadoresRaw,
            ':aire' => admin_a_equipment_value($_POST, 'tiene_aire_acondicionado'),
            ':ventilador' => admin_a_equipment_value($_POST, 'tiene_ventilador'),
            ':tablero' => admin_a_equipment_value($_POST, 'tiene_tablero'),
            ':televisor' => admin_a_equipment_value($_POST, 'tiene_televisor'),
            ':id' => $idAuditorio,
        ]);

        $_SESSION['admin_auditorio_message'] = 'Dotación del auditorio actualizada.';
        $_SESSION['admin_auditorio_message_type'] = 'success';
    } catch (Throwable $exception) {
        $_SESSION['admin_auditorio_message'] = $exception->getMessage();
        $_SESSION['admin_auditorio_message_type'] = 'danger';
    }

    header('Location: ' . app_url('admin/auditorios.php'));
    exit;
}

if ($busqueda !== '') {
    $where = ' WHERE a.nombre_auditorio LIKE :q OR a.bloque LIKE :q OR es.nombre_estado LIKE :q';
    $params[':q'] = '%' . $busqueda . '%';
}

try {
    $stats['activos'] = admin_a_scalar(
        $pdo,
        "SELECT COUNT(*)
         FROM auditorio a
         INNER JOIN estado es ON es.id_estado = a.id_estado
         WHERE es.nombre_estado = 'Activo'"
    );
    $stats['capacidad'] = admin_a_scalar($pdo, 'SELECT COALESCE(SUM(capacidad), 0) FROM auditorio');
    $stats['eventos'] = admin_a_scalar($pdo, 'SELECT COUNT(*) FROM evento');
    $stats['ocupados'] = admin_a_scalar(
        $pdo,
        "SELECT COUNT(DISTINCT e.id_auditorio)
         FROM evento e
         INNER JOIN estado es ON es.id_estado = e.id_estado
         WHERE es.nombre_estado IN ('Activo', 'Pendiente')
           AND DATE(e.fecha_evento) >= CURDATE()"
    );

    $auditorios = admin_a_rows(
        $pdo,
        'SELECT a.id_auditorio, a.nombre_auditorio, a.bloque, a.capacidad,
                a.cantidad_computadores, a.tiene_aire_acondicionado, a.tiene_ventilador, a.tiene_tablero, a.tiene_televisor,
                es.nombre_estado AS estado,
                COUNT(e.id_evento) AS eventos_total,
                SUM(CASE WHEN evs.nombre_estado IN (\'Activo\', \'Pendiente\') AND DATE(e.fecha_evento) >= CURDATE() THEN 1 ELSE 0 END) AS eventos_proximos
         FROM auditorio a
         INNER JOIN estado es ON es.id_estado = a.id_estado
         LEFT JOIN evento e ON e.id_auditorio = a.id_auditorio
         LEFT JOIN estado evs ON evs.id_estado = e.id_estado' .
            $where .
        ' GROUP BY a.id_auditorio, a.nombre_auditorio, a.bloque, a.capacidad,
                   a.cantidad_computadores, a.tiene_aire_acondicionado, a.tiene_ventilador, a.tiene_tablero, a.tiene_televisor,
                   es.nombre_estado
          ORDER BY a.nombre_auditorio ASC',
        $params
    );
} catch (Throwable $exception) {
    error_log('SICA admin auditorios: ' . $exception->getMessage());
}
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>

<main class="admin-dashboard">
    <aside class="admin-sidebar" aria-label="Menu del administrador">
        <a class="admin-brand" href="<?= admin_a_h(app_url('admin/index.php')) ?>">
            <span><strong>SICA</strong><small>Sistema Inteligente de Control de Asistencia</small></span>
        </a>
        <section class="admin-profile" aria-label="Administrador activo">
            <div class="admin-avatar">AD</div>
            <div><strong><?= admin_a_h($adminName) ?></strong><small><?= admin_a_h($adminMail) ?></small><span>En linea</span></div>
        </section>
        <nav class="admin-nav">
            <a href="<?= admin_a_h(app_url('admin/index.php')) ?>"><span>PC</span>Panel de Control</a>
            <a href="<?= admin_a_h(app_url('admin/usuarios.php')) ?>"><span>US</span>Usuarios</a>
            <a href="<?= admin_a_h(app_url('admin/solicitudes.php')) ?>"><span>SR</span>Solicitudes de Reserva</a>
            <a href="<?= admin_a_h(app_url('admin/correos.php')) ?>"><span>CN</span>Correos y Notificaciones</a>
            <a class="active" href="<?= admin_a_h(app_url('admin/auditorios.php')) ?>"><span>AU</span>Auditorios</a>
            <a href="<?= admin_a_h(app_url('admin/reportes.php')) ?>"><span>RP</span>Reportes</a>
        </nav>
    </aside>

    <section class="admin-main">
        <header class="admin-topbar">
            <div>
                <p class="admin-eyebrow">Espacios</p>
                <h1>Auditorios</h1>
                <span>Consulta capacidad, estado y uso de los espacios disponibles para eventos.</span>
            </div>
            <div class="admin-top-actions">
                <a href="<?= admin_a_h(app_url('admin/solicitudes.php')) ?>">Reservas <strong>SR</strong></a>
                <a class="admin-logout" href="<?= admin_a_h(app_url('login/logout.php')) ?>">Cerrar sesion</a>
            </div>
        </header>

        <section class="admin-metrics reservation-metrics" aria-label="Resumen de auditorios">
            <article class="admin-metric"><span>Activos</span><strong><?= admin_a_h($stats['activos']) ?></strong><small>Disponibles para solicitud</small></article>
            <article class="admin-metric"><span>Capacidad total</span><strong><?= admin_a_h($stats['capacidad']) ?></strong><small>Cupos combinados</small></article>
            <article class="admin-metric"><span>Eventos</span><strong><?= admin_a_h($stats['eventos']) ?></strong><small>Programados historicos</small></article>
            <article class="admin-metric"><span>Con movimiento</span><strong><?= admin_a_h($stats['ocupados']) ?></strong><small>Activos o pendientes</small></article>
        </section>

        <?php if ($message !== ''): ?>
            <div class="admin-inline-message <?= admin_a_h($messageType) ?>"><?= admin_a_h($message) ?></div>
        <?php endif; ?>

        <section class="admin-panel reservations-panel">
            <div class="admin-panel-head">
                <div><p class="admin-eyebrow">Inventario</p><h2>Espacios registrados</h2></div>
            </div>

            <form class="admin-user-filters admin-mail-filters" method="get" action="<?= admin_a_h(app_url('admin/auditorios.php')) ?>">
                <label><span>Busqueda rapida</span><input type="search" name="q" value="<?= admin_a_h($busqueda) ?>" placeholder="Auditorio, bloque o estado"></label>
                <button type="submit">Buscar</button>
                <a href="<?= admin_a_h(app_url('admin/auditorios.php')) ?>">Limpiar</a>
            </form>

            <div class="admin-auditorium-grid">
                <?php if (!$auditorios): ?>
                    <article class="admin-empty-state"><strong>No hay auditorios para mostrar.</strong><span>Cuando registres espacios, apareceran aqui.</span></article>
                <?php endif; ?>
                <?php foreach ($auditorios as $auditorio): ?>
                    <article class="admin-auditorium-card">
                        <div>
                            <p class="admin-eyebrow">Bloque <?= admin_a_h($auditorio['bloque']) ?></p>
                            <h3><?= admin_a_h($auditorio['nombre_auditorio']) ?></h3>
                            <span><?= admin_a_h($auditorio['estado']) ?></span>
                        </div>
                        <div class="admin-reservation-meta">
                            <span>Capacidad <?= admin_a_h($auditorio['capacidad']) ?></span>
                            <span>Computadores <?= admin_a_h($auditorio['cantidad_computadores'] ?? 'Por registrar') ?></span>
                            <span>Aire <?= admin_a_h(admin_a_equipment_label($auditorio['tiene_aire_acondicionado'] ?? null)) ?></span>
                            <span>Ventilador <?= admin_a_h(admin_a_equipment_label($auditorio['tiene_ventilador'] ?? null)) ?></span>
                            <span>Tablero <?= admin_a_h(admin_a_equipment_label($auditorio['tiene_tablero'] ?? null)) ?></span>
                            <span>Televisor <?= admin_a_h(admin_a_equipment_label($auditorio['tiene_televisor'] ?? null)) ?></span>
                            <span><?= admin_a_h($auditorio['eventos_total'] ?? 0) ?> eventos</span>
                            <span><?= admin_a_h($auditorio['eventos_proximos'] ?? 0) ?> proximos</span>
                        </div>
                        <form class="admin-equipment-form" method="post">
                            <input type="hidden" name="csrf" value="<?= admin_a_h($_SESSION['csrf_admin_auditorios']) ?>">
                            <input type="hidden" name="action" value="update_dotacion">
                            <input type="hidden" name="id_auditorio" value="<?= admin_a_h($auditorio['id_auditorio']) ?>">
                            <label>
                                <span>Computadores</span>
                                <input type="number" name="cantidad_computadores" min="0" max="999" value="<?= admin_a_h($auditorio['cantidad_computadores'] ?? '') ?>" placeholder="Ej: 20">
                            </label>
                            <?php
                                $dotaciones = [
                                    'tiene_aire_acondicionado' => 'Aire acondicionado',
                                    'tiene_ventilador' => 'Ventilador',
                                    'tiene_tablero' => 'Tablero / pizarra',
                                    'tiene_televisor' => 'Televisor',
                                ];
                            ?>
                            <?php foreach ($dotaciones as $field => $label): ?>
                                <label>
                                    <span><?= admin_a_h($label) ?></span>
                                    <select name="<?= admin_a_h($field) ?>">
                                        <option value="" <?= ($auditorio[$field] ?? null) === null ? 'selected' : '' ?>>Por registrar</option>
                                        <option value="1" <?= (string)($auditorio[$field] ?? '') === '1' ? 'selected' : '' ?>>Sí</option>
                                        <option value="0" <?= (string)($auditorio[$field] ?? '') === '0' ? 'selected' : '' ?>>No</option>
                                    </select>
                                </label>
                            <?php endforeach; ?>
                            <button type="submit">Guardar dotación</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </section>
</main>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
