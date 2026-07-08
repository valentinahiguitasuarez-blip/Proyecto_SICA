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
$correosRecientes = [];
$actividadReciente = [];
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
$eventosPorMes = array_fill(1, 12, 0);

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
         WHERE (es.nombre_estado = 'Pendiente' AND e.id_coordinador IS NULL)
            OR (e.id_coordinador IS NOT NULL AND e.fecha_aprobacion IS NOT NULL)"
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

    foreach (rowsQuery($pdo, 'SELECT MONTH(fecha_evento) mes, COUNT(*) total FROM evento GROUP BY MONTH(fecha_evento)') as $row) {
        $eventosPorMes[(int)$row['mes']] = (int)$row['total'];
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
         ORDER BY
            CASE
                WHEN es.nombre_estado = \'Pendiente\' AND e.id_coordinador IS NULL THEN 1
                WHEN es.nombre_estado = \'Pendiente\' THEN 2
                WHEN e.fecha_aprobacion IS NOT NULL THEN 3
                ELSE 4
            END,
            e.fecha_evento ASC,
            e.hora_inicio ASC
         LIMIT 6'
    );

    $correosRecientes = rowsQuery(
        $pdo,
        'SELECT e.nombre_evento, es.nombre_estado AS estado, e.fecha_aprobacion, e.fecha_evento,
                a.nombre_auditorio, u.correo
         FROM evento e
         INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
         INNER JOIN estado es ON es.id_estado = e.id_estado
         LEFT JOIN usuario u ON u.id_documento = e.id_solicitante
         ORDER BY COALESCE(e.fecha_aprobacion, e.fecha_evento) DESC
         LIMIT 4'
    );

    $actividadReciente = rowsQuery(
        $pdo,
        'SELECT u.nombre, u.apellido, u.fecha_registro, e.nombre_estado AS estado
         FROM usuario u
         INNER JOIN estado e ON e.id_estado = u.id_estado
         ORDER BY u.fecha_registro DESC
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
$maxMonth = max(1, max($eventosPorMes));
$totalReservas = max(1, array_sum($reservas));
$maxAuditoriumEvents = max(1, ...array_map(static fn(array $auditorio): int => (int)$auditorio['eventos_programados'], $auditorios ?: [['eventos_programados' => 0]]));
$timelineBase = $bandejaTrabajo[0] ?? null;
$notificationTotal = (int)($reservas['Pendiente'] ?? 0) + (int)($stats['correos_pendientes'] ?? 0);
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>

<main class="admin-dashboard">
    <aside class="admin-sidebar" aria-label="Menu del administrador">
        <a class="admin-brand" href="<?= h(app_url('admin/index.php')) ?>">
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
            <a class="active" href="<?= h(app_url('admin/index.php')) ?>"><span>PC</span>Panel de Control</a>
            <a href="<?= h(app_url('admin/usuarios.php')) ?>"><span>US</span>Usuarios</a>
            <a href="<?= h(app_url('admin/solicitudes.php')) ?>"><span>SR</span>Solicitudes de Reserva</a>
            <a href="<?= h(app_url('admin/correos.php')) ?>"><span>CN</span>Correos y Notificaciones</a>
            <a href="<?= h(app_url('admin/auditorios.php')) ?>"><span>AU</span>Auditorios</a>
            <a href="<?= h(app_url('admin/reportes.php')) ?>"><span>RP</span>Reportes</a>
        </nav>

    </aside>

    <section class="admin-main">
        <header class="admin-topbar">
            <div>
                <p class="admin-eyebrow">Panel administrativo</p>
                <h1>Bienvenido, <?= h($adminName) ?></h1>
                <span>Gestiona usuarios, reservas de auditorios y correos de confirmacion.</span>
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

        <form class="admin-global-search" action="<?= h(app_url('admin/solicitudes.php')) ?>" method="get" role="search">
            <span aria-hidden="true">BU</span>
            <input type="search" name="q" placeholder="Buscar usuario, evento, auditorio o codigo">
            <button type="submit">Buscar</button>
        </form>

        <section class="admin-focus-layout">
            <article class="admin-panel admin-work-queue">
                <div class="admin-panel-head">
                    <div>
                        <p class="admin-eyebrow">Bandeja de trabajo</p>
                        <h2>Solicitudes que requieren atencion</h2>
                    </div>
                    <a href="<?= h(app_url('admin/solicitudes.php')) ?>">Ver todas</a>
                </div>
                <div class="admin-work-table">
                    <div class="admin-work-head"><span>Prioridad</span><span>Evento</span><span>Estado</span><span>Accion</span></div>
                    <?php foreach ($bandejaTrabajo as $item): ?>
                        <?php
                            $estado = (string)$item['estado'];
                            $prioridad = 'Baja';
                            $priorityClass = 'low';
                            $estadoTrabajo = $estado;
                            if ($estado === 'Pendiente' && empty($item['id_coordinador'])) {
                                $prioridad = 'Alta';
                                $priorityClass = 'high';
                                $estadoTrabajo = 'Pendiente';
                            } elseif ($estado === 'Pendiente') {
                                $prioridad = 'Media';
                                $priorityClass = 'medium';
                                $estadoTrabajo = 'Esperando coordinacion';
                            } elseif (!empty($item['fecha_aprobacion'])) {
                                $estadoTrabajo = 'Listo para notificar';
                            }
                        ?>
                        <div class="admin-work-row <?= h($priorityClass) ?>">
                            <span><?= h($prioridad) ?></span>
                            <strong><?= h($item['nombre_evento']) ?></strong>
                            <em><?= h($estadoTrabajo) ?></em>
                            <a href="<?= h(app_url('admin/solicitudes.php?q=' . urlencode((string)$item['nombre_evento']))) ?>">Revisar</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <aside class="admin-focus-side" aria-label="Resumen operativo">
                <article class="admin-panel admin-today-card">
                    <div>
                        <p class="admin-eyebrow">Hoy</p>
                        <h2>Operacion del dia</h2>
                    </div>
                    <div class="admin-today-mini">
                        <div><strong><?= h($todayStats['solicitudes']) ?></strong><small>Solicitudes</small></div>
                        <div><strong><?= h($todayStats['correos']) ?></strong><small>Correos</small></div>
                        <div><strong><?= h($todayStats['eventos_activos']) ?></strong><small>Eventos</small></div>
                        <div><strong><?= h($todayStats['auditorios_ocupados']) ?> / <?= h(max(1, count($auditorios))) ?></strong><small>Auditorios</small></div>
                    </div>
                </article>

                <section class="quick-actions quick-actions-compact" id="reportes">
                    <div>
                        <span class="admin-eyebrow">Accesos</span>
                        <h2>Acciones rapidas</h2>
                    </div>
                    <nav aria-label="Acciones rapidas del administrador">
                        <a href="<?= h(app_url('admin/solicitudes.php')) ?>"><span>+</span>Nueva reserva</a>
                        <a href="<?= h(app_url('admin/usuarios.php')) ?>"><span>US</span>Crear usuario</a>
                        <a href="<?= h(app_url('admin/correos.php')) ?>"><span>CO</span>Enviar correo</a>
                        <a href="<?= h(app_url('admin/reportes.php')) ?>"><span>RP</span>Reporte</a>
                    </nav>
                </section>
            </aside>
        </section>

        <section class="admin-metrics admin-metrics-compact" aria-label="Indicadores generales">
            <article class="admin-metric">
                <span>Usuarios</span>
                <strong><?= h($stats['usuarios']) ?></strong>
                <small>Cuentas activas y roles</small>
            </article>
            <article class="admin-metric">
                <span>Eventos</span>
                <strong><?= h($stats['eventos']) ?></strong>
                <small>Reservas aprobadas</small>
            </article>
            <article class="admin-metric">
                <span>Correos pendientes</span>
                <strong><?= h($stats['correos_pendientes'] ?? 0) ?></strong>
                <small>Por confirmar o avisar</small>
            </article>
            <article class="admin-metric">
                <span>Auditorios</span>
                <strong><?= h($stats['auditorios']) ?></strong>
                <small>Espacios activos</small>
            </article>
        </section>

        <details class="admin-insights">
            <summary>
                <span>Analitica y trazabilidad</span>
                <strong>Ver detalles del sistema</strong>
            </summary>

            <section class="admin-insights-grid">
                <article class="admin-panel admin-flow-panel">
                    <div class="admin-panel-head">
                        <div>
                            <p class="admin-eyebrow">Flujo SICA</p>
                            <h2>Flujo de solicitudes</h2>
                        </div>
                    </div>
                    <ol class="admin-flow-list">
                        <li>Solicitud creada</li>
                        <li>Revision administrador</li>
                        <li>Enviada a coordinacion</li>
                        <li>Aprobada / Rechazada</li>
                        <li>Correo automatico</li>
                        <li>Reserva confirmada</li>
                        <li>Registro de asistencia</li>
                        <li>Certificado generado</li>
                    </ol>
                </article>

                <article class="admin-panel admin-timeline-panel">
                    <div class="admin-panel-head">
                        <div>
                            <p class="admin-eyebrow">Trazabilidad</p>
                            <h2>Linea de tiempo</h2>
                        </div>
                    </div>
                    <div class="admin-timeline">
                        <div><time>09:10</time><span>Solicitud recibida<?= $timelineBase ? ': ' . h($timelineBase['nombre_evento']) : '' ?></span></div>
                        <div><time>09:25</time><span>Coordinacion revisa disponibilidad</span></div>
                        <div><time>09:30</time><span>Correo enviado al responsable</span></div>
                        <div><time>09:45</time><span>Auditorio reservado y flujo activo</span></div>
                    </div>
                </article>
            </section>

            <section class="admin-grid">
            <article class="admin-panel upcoming-panel">
                <div class="admin-panel-head">
                    <div>
                        <p class="admin-eyebrow">Proximos eventos</p>
                        <h2>Agenda de auditorios</h2>
                    </div>
                    <a href="#reservas">Ver todas</a>
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
                                <span><?= h($evento['nombre_auditorio']) ?> - <?= h(substr((string)$evento['hora_inicio'], 0, 5)) ?> a <?= h(substr((string)$evento['hora_fin'], 0, 5)) ?></span>
                            </div>
                            <em><?= h($evento['estado']) ?></em>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="admin-panel chart-panel">
                <div class="admin-panel-head">
                    <div>
                        <p class="admin-eyebrow">Calendario</p>
                        <h2>Eventos por mes</h2>
                    </div>
                </div>
                <div class="admin-bars" aria-label="Grafica de eventos por mes">
                    <?php foreach ($eventosPorMes as $month => $count): ?>
                        <div class="admin-bar">
                            <span style="height: <?= h(max(8, (int)round(($count / $maxMonth) * 100))) ?>%"></span>
                            <small><?= h($monthLabels[$month]) ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="admin-panel auditorium-status" id="auditorios">
                <div class="admin-panel-head">
                    <div>
                        <p class="admin-eyebrow">Auditorios</p>
                        <h2>Estado de ocupacion</h2>
                    </div>
                </div>
                <div class="auditorium-ring">
                    <strong><?= h(count($auditorios)) ?></strong>
                    <span>Total</span>
                </div>
                <div class="auditorium-list auditorium-bars">
                    <?php foreach ($auditorios as $auditorio): ?>
                        <?php $ocupacion = min(100, (int)round(((int)$auditorio['eventos_programados'] / $maxAuditoriumEvents) * 100)); ?>
                        <div>
                            <span><?= h($auditorio['nombre_auditorio']) ?></span>
                            <strong><?= h($ocupacion) ?>%</strong>
                            <i><b style="width: <?= h($ocupacion) ?>%"></b></i>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="admin-panel" id="eventos-hoy">
                <div class="admin-panel-head">
                    <div>
                        <p class="admin-eyebrow">Hoy</p>
                        <h2>Eventos del dia</h2>
                    </div>
                </div>
                <div class="admin-today-events">
                    <?php if (!$eventosHoy): ?>
                        <div><strong>Sin eventos activos para hoy.</strong><span>La agenda aparecera aqui cuando haya reservas.</span></div>
                    <?php endif; ?>
                    <?php foreach ($eventosHoy as $eventoHoy): ?>
                        <div>
                            <time><?= h(substr((string)$eventoHoy['hora_inicio'], 0, 5)) ?></time>
                            <strong><?= h($eventoHoy['nombre_evento']) ?></strong>
                            <span><?= h($eventoHoy['nombre_auditorio']) ?> - <?= h($eventoHoy['estado']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="admin-panel reservation-panel" id="reservas">
                <div class="admin-panel-head">
                    <div>
                        <p class="admin-eyebrow">Solicitudes</p>
                        <h2>Reservas de auditorio</h2>
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

            <article class="admin-panel mail-panel" id="correos">
                <div class="admin-panel-head">
                    <div>
                        <p class="admin-eyebrow">Correos</p>
                        <h2>Notificaciones de reserva</h2>
                    </div>
                    <a href="#">Administrar</a>
                </div>
                <div class="admin-mail-list">
                    <?php foreach ($correosRecientes as $correo): ?>
                        <div>
                            <span>Correo</span>
                            <strong>Confirmacion de reserva - <?= h($correo['nombre_evento']) ?></strong>
                            <small>Para: <?= h($correo['correo'] ?? 'solicitante@sica.edu.co') ?></small>
                            <em><?= h($correo['estado']) ?></em>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="admin-panel activity-panel" id="usuarios">
                <div class="admin-panel-head">
                    <div>
                        <p class="admin-eyebrow">Usuarios</p>
                        <h2>Actividad reciente</h2>
                    </div>
                    <a href="#">Ver usuarios</a>
                </div>
                <div class="admin-activity-list">
                    <?php foreach ($actividadReciente as $actividad): ?>
                        <div>
                            <span>US</span>
                            <div>
                                <strong><?= h(trim((string)$actividad['nombre'] . ' ' . (string)$actividad['apellido'])) ?></strong>
                                <small><?= h($actividad['estado']) ?> - Registro <?= h($actividad['fecha_registro']) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>
            </section>
        </details>

    </section>
</main>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
