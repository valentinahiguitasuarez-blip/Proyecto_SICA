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
    'auditorios' => 0,
];
$reservas = ['Pendiente' => 0, 'Activo' => 0, 'Cancelado' => 0, 'Finalizado' => 0];
$eventosRecientes = [];
$correosRecientes = [];
$actividadReciente = [];
$auditorios = [];
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
        'SELECT e.nombre_evento, e.fecha_evento, e.hora_inicio, e.hora_fin, es.nombre_estado AS estado,
                a.nombre_auditorio, u.nombre, u.apellido
         FROM evento e
         INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
         INNER JOIN estado es ON es.id_estado = e.id_estado
         LEFT JOIN usuario u ON u.id_documento = e.id_solicitante
         ORDER BY e.fecha_evento DESC, e.hora_inicio DESC
         LIMIT 4'
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
} catch (Throwable $exception) {
    error_log('SICA admin dashboard: ' . $exception->getMessage());
}

$monthLabels = [1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'];
$maxMonth = max(1, max($eventosPorMes));
$totalReservas = max(1, array_sum($reservas));
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

        <section class="admin-profile" aria-label="Administrador activo">
            <div class="admin-avatar">AD</div>
            <div>
                <strong><?= h($adminName) ?></strong>
                <small><?= h($adminMail) ?></small>
                <span>En linea</span>
            </div>
        </section>

        <nav class="admin-nav">
            <a class="active" href="<?= h(app_url('admin/index.php')) ?>"><span>PC</span>Panel de Control</a>
            <a href="<?= h(app_url('admin/usuarios.php')) ?>"><span>US</span>Usuarios</a>
            <a href="#reservas"><span>SR</span>Solicitudes de Reserva</a>
            <a href="#correos"><span>CN</span>Correos y Notificaciones</a>
            <a href="#auditorios"><span>AU</span>Auditorios</a>
            <a href="#reportes"><span>RP</span>Reportes</a>
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
                <a href="#correos" aria-label="Correos pendientes">Correo <strong><?= h($stats['correos']) ?></strong></a>
                <a href="#reservas" aria-label="Solicitudes pendientes">Reservas <strong><?= h($reservas['Pendiente']) ?></strong></a>
                <a class="admin-logout" href="<?= h(app_url('login/logout.php')) ?>">Cerrar sesion</a>
            </div>
        </header>

        <section class="admin-metrics" aria-label="Indicadores generales">
            <article class="admin-metric">
                <span>Usuarios registrados</span>
                <strong><?= h($stats['usuarios']) ?></strong>
                <small>Gestion de cuentas y roles</small>
            </article>
            <article class="admin-metric">
                <span>Eventos programados</span>
                <strong><?= h($stats['eventos']) ?></strong>
                <small>Reservas aprobadas o activas</small>
            </article>
            <article class="admin-metric">
                <span>Asistencias registradas</span>
                <strong><?= h($stats['asistencias']) ?></strong>
                <small>Ingresos validados en auditorio</small>
            </article>
            <article class="admin-metric">
                <span>Correos enviados</span>
                <strong><?= h($stats['correos']) ?></strong>
                <small>Confirmaciones y novedades</small>
            </article>
            <article class="admin-metric">
                <span>Auditorios activos</span>
                <strong><?= h($stats['auditorios']) ?></strong>
                <small>Espacios disponibles</small>
            </article>
        </section>

        <section class="admin-grid">
            <article class="admin-panel upcoming-panel">
                <div class="admin-panel-head">
                    <div>
                        <p class="admin-eyebrow">Reservas</p>
                        <h2>Eventos y solicitudes recientes</h2>
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
                        <h2>Estado de espacios</h2>
                    </div>
                </div>
                <div class="auditorium-ring">
                    <strong><?= h(count($auditorios)) ?></strong>
                    <span>Total</span>
                </div>
                <div class="auditorium-list">
                    <?php foreach ($auditorios as $auditorio): ?>
                        <div>
                            <span><?= h($auditorio['nombre_auditorio']) ?></span>
                            <strong><?= h($auditorio['estado']) ?></strong>
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

        <section class="quick-actions" id="reportes">
            <h2>Acciones rapidas</h2>
            <div>
                <a href="<?= h(app_url('admin/usuarios.php')) ?>">Gestionar usuarios</a>
                <a href="#reservas">Revisar solicitudes</a>
                <a href="#correos">Enviar confirmacion</a>
                <a href="#auditorios">Ver auditorios</a>
                <a href="#reportes">Generar reporte</a>
            </div>
        </section>
    </section>
</main>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
