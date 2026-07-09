<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([1]);
require_once __DIR__ . '/../config/conexion.php';

$pageTitle = 'Administrador - SICA';
$pageStyles = ['css/admin.css'];

$usuario = $_SESSION['usuario'] ?? [];
$adminName = trim((string)($usuario['nombre'] ?? 'Administrador'));
$adminMail = (string)($usuario['correo'] ?? 'admin@sica.edu.co');

function h(string|int|null $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function scalarQuery(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function rowsQuery(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$stats = [
    'usuarios' => 0,
    'eventos' => 0,
    'asistencias' => 0,
    'correos' => 0,
    'correos_pendientes' => 0,
    'auditorios' => 0,
];
$reservas = ['Pendiente' => 0, 'Activo' => 0, 'Cancelado' => 0, 'Finalizado' => 0];
$eventosRecientes = [];
$auditorios = [];
$bandejaTrabajo = [];
$eventosHoy = [];
$todayStats = [
    'solicitudes' => 0,
    'aprobadas' => 0,
    'correos' => 0,
    'eventos_activos' => 0,
    'auditorios_ocupados' => 0,
];

try {
    $stats['usuarios'] = scalarQuery($pdo, 'SELECT COUNT(*) FROM usuario');
    $stats['eventos'] = scalarQuery(
        $pdo,
        "SELECT COUNT(*)
         FROM evento e
         INNER JOIN estado es ON es.id_estado = e.id_estado
         WHERE es.nombre_estado IN ('Activo', 'Finalizado')"
    );
    $stats['asistencias'] = scalarQuery($pdo, "SELECT COUNT(*) FROM preregistro WHERE asistencia <> 'Pendiente'");
    $stats['correos'] = scalarQuery($pdo, 'SELECT COUNT(*) FROM evento WHERE id_coordinador IS NOT NULL');
    $stats['correos_pendientes'] = scalarQuery(
        $pdo,
        "SELECT COUNT(*)
         FROM evento e
         INNER JOIN estado es ON es.id_estado = e.id_estado
         WHERE es.nombre_estado IN ('Activo', 'Cancelado')
           AND e.id_coordinador IS NOT NULL
           AND e.fecha_aprobacion IS NOT NULL"
    );
    $stats['auditorios'] = scalarQuery(
        $pdo,
        "SELECT COUNT(*)
         FROM auditorio a
         INNER JOIN estado es ON es.id_estado = a.id_estado
         WHERE es.nombre_estado = 'Activo'"
    );

    foreach (rowsQuery(
        $pdo,
        'SELECT es.nombre_estado AS estado, COUNT(*) total
         FROM evento e
         INNER JOIN estado es ON es.id_estado = e.id_estado
         GROUP BY es.nombre_estado'
    ) as $row) {
        if (array_key_exists((string)$row['estado'], $reservas)) {
            $reservas[(string)$row['estado']] = (int)$row['total'];
        }
    }

    $eventosRecientes = rowsQuery(
        $pdo,
        'SELECT e.id_evento, e.nombre_evento, e.fecha_evento, e.hora_inicio, e.hora_fin,
                e.id_coordinador, e.fecha_aprobacion, es.nombre_estado AS estado,
                a.nombre_auditorio, u.nombre, u.apellido
         FROM evento e
         INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
         INNER JOIN estado es ON es.id_estado = e.id_estado
         LEFT JOIN usuario u ON u.id_documento = e.id_solicitante
         ORDER BY e.fecha_evento DESC, e.hora_inicio DESC
         LIMIT 4'
    );

    $bandejaTrabajo = rowsQuery(
        $pdo,
        'SELECT e.id_evento, e.nombre_evento, e.fecha_evento, e.hora_inicio,
                e.id_coordinador, e.fecha_aprobacion, es.nombre_estado AS estado,
                a.nombre_auditorio
         FROM evento e
         INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
         INNER JOIN estado es ON es.id_estado = e.id_estado
         WHERE es.nombre_estado = \'Pendiente\'
            OR (
                es.nombre_estado IN (\'Activo\', \'Cancelado\')
                AND e.id_coordinador IS NOT NULL
                AND e.fecha_aprobacion IS NOT NULL
            )
         ORDER BY
            CASE
                WHEN es.nombre_estado = \'Pendiente\' AND e.id_coordinador IS NULL THEN 1
                WHEN es.nombre_estado = \'Pendiente\' THEN 2
                WHEN es.nombre_estado IN (\'Activo\', \'Cancelado\') AND e.fecha_aprobacion IS NOT NULL THEN 3
                ELSE 4
            END,
            e.fecha_evento ASC,
            e.hora_inicio ASC
         LIMIT 4'
    );

    $auditorios = rowsQuery(
        $pdo,
        'SELECT a.nombre_auditorio, a.bloque, a.capacidad, aes.nombre_estado AS estado,
                COUNT(e.id_evento) eventos_programados
         FROM auditorio a
         INNER JOIN estado aes ON aes.id_estado = a.id_estado
         LEFT JOIN evento e ON e.id_auditorio = a.id_auditorio AND e.id_estado = 1
         GROUP BY a.id_auditorio, a.nombre_auditorio, a.bloque, a.capacidad, aes.nombre_estado
         ORDER BY a.nombre_auditorio ASC'
    );

    $eventosHoy = rowsQuery(
        $pdo,
        'SELECT e.nombre_evento, e.hora_inicio, e.hora_fin, a.nombre_auditorio, es.nombre_estado AS estado
         FROM evento e
         INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
         INNER JOIN estado es ON es.id_estado = e.id_estado
         WHERE e.fecha_evento = CURDATE()
         ORDER BY e.hora_inicio ASC
         LIMIT 6'
    );

    $todayStats['solicitudes'] = scalarQuery($pdo, 'SELECT COUNT(*) FROM evento WHERE fecha_evento = CURDATE()');
    $todayStats['aprobadas'] = scalarQuery(
        $pdo,
        "SELECT COUNT(*)
         FROM evento e
         INNER JOIN estado es ON es.id_estado = e.id_estado
         WHERE e.fecha_evento = CURDATE() AND es.nombre_estado = 'Activo'"
    );
    $todayStats['correos'] = scalarQuery($pdo, 'SELECT COUNT(*) FROM evento WHERE DATE(COALESCE(fecha_aprobacion, fecha_evento)) = CURDATE() AND id_coordinador IS NOT NULL');
    $todayStats['eventos_activos'] = scalarQuery(
        $pdo,
        "SELECT COUNT(*)
         FROM evento e
         INNER JOIN estado es ON es.id_estado = e.id_estado
         WHERE e.fecha_evento = CURDATE() AND es.nombre_estado = 'Activo'"
    );
    $todayStats['auditorios_ocupados'] = scalarQuery(
        $pdo,
        "SELECT COUNT(DISTINCT e.id_auditorio)
         FROM evento e
         INNER JOIN estado es ON es.id_estado = e.id_estado
         WHERE e.fecha_evento = CURDATE() AND es.nombre_estado = 'Activo'"
    );
} catch (Throwable $exception) {
    error_log('SICA admin dashboard: ' . $exception->getMessage());
}

$monthLabels = [1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'];
$totalReservas = max(1, array_sum($reservas));
$notificationTotal = (int)($reservas['Pendiente'] ?? 0) + (int)($stats['correos_pendientes'] ?? 0);
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>

<main class="admin-dashboard">
    <aside class="admin-sidebar" aria-label="Menu del administrador">
        <a class="admin-brand admin-brand--with-mark" href="<?= h(app_url('admin/index.php')) ?>">
            <span>
                <strong>SICA</strong>
                <small>Sistema Inteligente de Control de Asistencia</small>
            </span>
        </a>

        <a class="admin-profile" href="<?= h(app_url('admin/perfil.php')) ?>" aria-label="Ver perfil del administrador">
            <div class="admin-avatar">AD</div>
            <div>
                <strong><?= h($adminName) ?></strong>
                <small><?= h($adminMail) ?></small>
                <span>En linea</span>
            </div>
        </a>

        <nav class="admin-nav">
            <a class="active" href="<?= h(app_url('admin/index.php')) ?>"><span class="nav-symbol nav-symbol-dashboard" aria-hidden="true"></span>Panel de Control</a>
            <a href="<?= h(app_url('admin/usuarios.php')) ?>"><span class="nav-symbol nav-symbol-users" aria-hidden="true"></span>Usuarios</a>
            <a href="<?= h(app_url('admin/solicitudes.php')) ?>"><span class="nav-symbol nav-symbol-reservations" aria-hidden="true"></span>Solicitudes de Reserva</a>
            <a href="<?= h(app_url('admin/auditorios.php')) ?>"><span class="nav-symbol nav-symbol-auditoriums" aria-hidden="true"></span>Auditorios</a>
            <a href="<?= h(app_url('admin/reportes.php')) ?>"><span class="nav-symbol nav-symbol-reports" aria-hidden="true"></span>Reportes</a>
        </nav>

    </aside>

    <section class="admin-main">
        <header class="admin-topbar">
            <div>
                <p class="admin-eyebrow">Panel administrativo</p>
                <h1>Bienvenido, <?= h($adminName) ?></h1>
                <span>Revisa lo pendiente y entra rapido a cada modulo.</span>
            </div>
            <div class="admin-top-actions">
                <div class="admin-notification-center" aria-label="Centro de notificaciones">
                    <strong><?= h($notificationTotal) ?></strong>
                    <span>Alertas</span>
                    <small><?= h($reservas['Pendiente']) ?> solicitudes - <?= h($stats['correos_pendientes'] ?? 0) ?> correos</small>
                </div>
                <a class="admin-logout" href="<?= h(app_url('login/logout.php')) ?>">Cerrar sesion</a>
            </div>
        </header>

        <section class="admin-home-grid">
            <article class="admin-panel admin-work-queue admin-home-primary">
                <div class="admin-panel-head">
                    <div>
                        <p class="admin-eyebrow">Pendientes</p>
                        <h2>Por atender</h2>
                    </div>
                    <a href="<?= h(app_url('admin/solicitudes.php')) ?>">Ver todos</a>
                </div>

                <div class="admin-task-list">
                    <?php if (!$bandejaTrabajo): ?>
                        <div class="admin-empty-state">
                            <strong>No hay solicitudes pendientes.</strong>
                            <span>Cuando llegue una reserva nueva, aparecera aqui.</span>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($bandejaTrabajo as $item): ?>
                        <?php
                            $estado = (string)$item['estado'];
                            $prioridad = 'Respuesta';
                            $priorityClass = 'low';
                            $estadoTrabajo = 'Por notificar';
                            $accionTrabajo = 'Revisar';
                            if ($estado === 'Pendiente' && empty($item['id_coordinador'])) {
                                $prioridad = 'Nueva';
                                $priorityClass = 'high';
                                $estadoTrabajo = 'Por asignar';
                                $accionTrabajo = 'Asignar';
                            } elseif ($estado === 'Pendiente') {
                                $prioridad = 'Revision';
                                $priorityClass = 'medium';
                                $estadoTrabajo = 'En revision';
                            } elseif (!empty($item['fecha_aprobacion'])) {
                                $prioridad = 'Respuesta';
                                $priorityClass = 'low';
                                $estadoTrabajo = 'Por notificar';
                                $accionTrabajo = 'Notificar';
                            }
                        ?>
                        <a class="admin-task-item <?= h($priorityClass) ?>" href="<?= h(app_url('admin/solicitudes.php?q=' . urlencode((string)$item['nombre_evento']))) ?>">
                            <span><?= h($prioridad) ?></span>
                            <div>
                                <strong><?= h($item['nombre_evento']) ?></strong>
                                <small class="admin-task-meta-clean"><?= h($item['nombre_auditorio']) ?> - <?= h($estadoTrabajo) ?></small>
                                <small><?= h($item['nombre_auditorio']) ?> · <?= h($estadoTrabajo) ?></small>
                            </div>
                            <em><?= h($accionTrabajo) ?></em>
                        </a>
                    <?php endforeach; ?>
                </div>
            </article>

            <aside class="admin-side-stack" aria-label="Acciones y agenda">
                <section class="quick-actions admin-home-actions">
                    <div>
                        <span class="admin-eyebrow">Accesos</span>
                        <h2>Modulos</h2>
                    </div>
                    <nav aria-label="Acciones rapidas del administrador">
                        <a href="<?= h(app_url('admin/solicitudes.php')) ?>"><span class="module-symbol module-symbol-reservations" aria-hidden="true"></span>Solicitudes</a>
                        <a href="<?= h(app_url('admin/usuarios.php')) ?>"><span class="module-symbol module-symbol-users" aria-hidden="true"></span>Usuarios</a>
                        <a href="<?= h(app_url('admin/auditorios.php')) ?>"><span class="module-symbol module-symbol-auditoriums" aria-hidden="true"></span>Auditorios</a>
                        <a href="<?= h(app_url('admin/reportes.php')) ?>"><span class="module-symbol module-symbol-reports" aria-hidden="true"></span>Reportes</a>
                    </nav>
                </section>

                <article class="admin-panel admin-today-card">
                    <div class="admin-panel-head">
                        <div>
                            <p class="admin-eyebrow">Hoy</p>
                            <h2>Agenda del dia</h2>
                        </div>
                        <strong class="admin-occupancy"><?= h($todayStats['auditorios_ocupados']) ?> / <?= h(max(1, count($auditorios))) ?></strong>
                    </div>
                    <div class="admin-day-list">
                        <?php if (!$eventosHoy): ?>
                            <div>
                                <strong>Sin eventos para hoy</strong>
                                <span>La agenda esta libre.</span>
                            </div>
                        <?php endif; ?>
                        <?php foreach ($eventosHoy as $eventoHoy): ?>
                            <div>
                                <time><?= h(substr((string)$eventoHoy['hora_inicio'], 0, 5)) ?></time>
                                <strong><?= h($eventoHoy['nombre_evento']) ?></strong>
                                <span class="admin-day-meta-clean"><?= h($eventoHoy['nombre_auditorio']) ?> - <?= h($eventoHoy['estado']) ?></span>
                                <span><?= h($eventoHoy['nombre_auditorio']) ?> · <?= h($eventoHoy['estado']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>
            </aside>
        </section>

        <section class="admin-secondary-grid" aria-label="Informacion complementaria">
            <article class="admin-panel">
                <div class="admin-panel-head">
                    <div>
                        <p class="admin-eyebrow">Proximos</p>
                        <h2>Eventos recientes</h2>
                    </div>
                    <a href="<?= h(app_url('admin/reportes.php')) ?>">Reportes</a>
                </div>
                <div class="admin-event-list">
                    <?php foreach ($eventosRecientes as $evento): ?>
                        <?php $fecha = new DateTime((string)$evento['fecha_evento']); ?>
                        <div class="admin-event-item">
                            <time>
                                <strong><?= h($fecha->format('d')) ?></strong>
                                <span><?= h($monthLabels[(int)$fecha->format('n')]) ?></span>
                            </time>
                            <div>
                                <strong><?= h($evento['nombre_evento']) ?></strong>
                                <span><?= h($evento['nombre_auditorio']) ?> · <?= h(substr((string)$evento['hora_inicio'], 0, 5)) ?> a <?= h(substr((string)$evento['hora_fin'], 0, 5)) ?></span>
                            </div>
                            <em><?= h($evento['estado']) ?></em>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="admin-panel">
                <div class="admin-panel-head">
                    <div>
                        <p class="admin-eyebrow">Estado</p>
                        <h2>Reservas</h2>
                    </div>
                </div>
                <div class="reservation-summary">
                    <?php foreach ($reservas as $estado => $total): ?>
                        <div>
                            <span><?= h($estado) ?></span>
                            <strong><?= h($total) ?></strong>
                            <small><?= h((int)round(($total / $totalReservas) * 100)) ?>%</small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="admin-panel admin-login-route">
                <div class="admin-panel-head">
                    <div>
                        <p class="admin-eyebrow">Acceso</p>
                        <h2>Inicio de sesion</h2>
                    </div>
                </div>
                <ol>
                    <li>El usuario entra con correo y contrasena.</li>
                    <li>El sistema valida que la cuenta este activa.</li>
                    <li>Segun el rol, lo envia a su panel.</li>
                </ol>
                <div>
                    <span>Admin</span>
                    <span>Coordinador</span>
                    <span>Instructor</span>
                    <span>Aprendiz</span>
                </div>
            </article>
        </section>

    </section>
</main>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
