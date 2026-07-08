<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([2]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/coordinador_panel.php';

$pageTitle = 'Dashboard coordinador - SICA';
$pageStyles = ['css/instructor.css', 'css/admin.css'];

$usuario = coord_user();
$coordinadorId = (int)($usuario['id_documento'] ?? 0);
$coordinadorName = coord_full_name($usuario);

$counts = ['Pendiente' => 0, 'Activo' => 0, 'Cancelado' => 0, 'Finalizado' => 0];
$pendientesPreview = [];
$eventosAprobados = [];
$monthLabels = [1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'];
$monthFullLabels = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];

try {
    foreach (coord_rows(
        $pdo,
        'SELECT es.nombre_estado, COUNT(*) total
         FROM evento e
         INNER JOIN estado es ON es.id_estado = e.id_estado
         WHERE e.id_coordinador = :coordinador
         GROUP BY es.nombre_estado',
        [':coordinador' => $coordinadorId]
    ) as $row) {
        $counts[(string)$row['nombre_estado']] = (int)$row['total'];
    }

    $pendientesPreview = coord_rows(
        $pdo,
        coord_event_query() . "
         WHERE e.id_coordinador = :coordinador AND es.nombre_estado = 'Pendiente'
         ORDER BY e.fecha_evento ASC, e.hora_inicio ASC
         LIMIT 5",
        [':coordinador' => $coordinadorId]
    );

    $eventosAprobados = coord_rows(
        $pdo,
        coord_event_query() . "
         WHERE e.id_coordinador = :coordinador
           AND es.nombre_estado = 'Activo'
           AND e.fecha_evento >= CURDATE()
         ORDER BY e.fecha_evento ASC, e.hora_inicio ASC
         LIMIT 4",
        [':coordinador' => $coordinadorId]
    );
} catch (Throwable $exception) {
    error_log('SICA coordinador dashboard: ' . $exception->getMessage());
}

$totalSolicitudes = array_sum($counts);
$pendientesCount = (int)($counts['Pendiente'] ?? 0);
$aprobadas = (int)($counts['Activo'] ?? 0);
$rechazadas = (int)($counts['Cancelado'] ?? 0);
$finalizadas = (int)($counts['Finalizado'] ?? 0);
$hoy = new DateTimeImmutable('now');
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>
<?php coord_layout_start('dashboard'); ?>

<header class="instructor-topbar">
    <div>
        <p class="eyebrow">Dashboard</p>
        <h1>Bienvenida, <?= coord_h($coordinadorName) ?></h1>
        <span>Resumen de tu coordinación académica de auditorios.</span>
    </div>
    <div class="topbar-actions">
        <?php if ($pendientesCount > 0): ?>
            <a class="primary-btn" href="<?= coord_h(app_url('coordinador/solicitudes.php')) ?>">Revisar solicitudes (<?= coord_h($pendientesCount) ?>)</a>
        <?php else: ?>
            <a class="top-action" href="<?= coord_h(app_url('coordinador/solicitudes.php')) ?>">Solicitudes</a>
        <?php endif; ?>
    </div>
</header>

<section class="coord-overview-head">
    <article class="coord-overview-card">
        <p class="eyebrow">Hoy</p>
        <strong><?= coord_h($hoy->format('d')) ?> de <?= coord_h($monthFullLabels[(int)$hoy->format('n')]) ?></strong>
        <span><?= coord_h($hoy->format('Y')) ?></span>
    </article>
    <article class="coord-overview-card highlight">
        <p class="eyebrow">Atención inmediata</p>
        <strong><?= coord_h($pendientesCount) ?></strong>
        <span>solicitud(es) pendiente(s) por revisar</span>
    </article>
    <article class="coord-overview-card">
        <p class="eyebrow">Decisiones registradas</p>
        <strong><?= coord_h($aprobadas + $rechazadas + $finalizadas) ?></strong>
        <span>en tu historial de coordinación</span>
    </article>
</section>

<section class="metric-grid" aria-label="Resumen de coordinación">
    <article class="metric-tile blue"><span>Total asignadas</span><strong><?= coord_h($totalSolicitudes) ?></strong><small>Solicitudes en tu bandeja</small><em>Resumen</em></article>
    <article class="metric-tile amber"><span>Pendientes</span><strong><?= coord_h($pendientesCount) ?></strong><small>Requieren decisión</small><em>Prioridad</em></article>
    <article class="metric-tile navy"><span>Aprobadas</span><strong><?= coord_h($aprobadas) ?></strong><small>Reservas activas</small><em>Activas</em></article>
    <article class="metric-tile green"><span>Finalizadas</span><strong><?= coord_h($finalizadas) ?></strong><small>Eventos concluidos</small><em>Cierre</em></article>
</section>

<section class="dashboard-grid">
    <article class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Bandeja de entrada</p>
                <h2>Próximas solicitudes por revisar</h2>
                <span class="panel-subtitle">Vista rápida. Para aprobar o cancelar entra a Solicitudes.</span>
            </div>
            <a class="top-action" href="<?= coord_h(app_url('coordinador/solicitudes.php')) ?>">Ir a solicitudes</a>
        </div>

        <div class="request-list">
            <?php if (!$pendientesPreview): ?>
                <div class="empty-state">No tienes solicitudes pendientes en este momento.</div>
            <?php endif; ?>

            <?php foreach ($pendientesPreview as $solicitud): ?>
                <?php
                $fecha = new DateTime((string)$solicitud['fecha_evento']);
                $instructor = trim((string)$solicitud['nombre'] . ' ' . (string)$solicitud['apellido']);
                $instructor = $instructor !== '' ? $instructor : 'Instructor SICA';
                ?>
                <article class="request-row pending">
                    <div class="request-date"><?= coord_h($fecha->format('d')) ?> <?= coord_h($monthLabels[(int)$fecha->format('n')]) ?></div>
                    <div class="request-content">
                        <div class="request-title">
                            <h3><?= coord_h($solicitud['nombre_evento']) ?></h3>
                        </div>
                        <small><?= coord_h($solicitud['nombre_auditorio']) ?> · <?= coord_h(coord_hora12((string)$solicitud['hora_inicio'])) ?> a <?= coord_h(coord_hora12((string)$solicitud['hora_fin'])) ?></small>
                        <small>Instructor: <?= coord_h($instructor) ?></small>
                    </div>
                    <a class="status-pill pending" href="<?= coord_h(app_url('coordinador/detalle_solicitud.php?id=' . (int)$solicitud['id_evento'])) ?>">Ver</a>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ($pendientesCount > count($pendientesPreview)): ?>
            <div class="coord-dashboard-foot">
                <a class="primary-btn" href="<?= coord_h(app_url('coordinador/solicitudes.php')) ?>">Ver las <?= coord_h($pendientesCount) ?> solicitudes pendientes</a>
            </div>
        <?php endif; ?>
    </article>

    <aside class="quick-panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Accesos rápidos</p>
                <h2>Operaciones</h2>
            </div>
        </div>
        <div class="quick-actions">
            <a href="<?= coord_h(app_url('coordinador/solicitudes.php')) ?>"><span>SR</span><strong>Solicitudes</strong><small><?= coord_h($pendientesCount) ?> pendiente(s)</small></a>
            <a href="<?= coord_h(app_url('coordinador/calendario.php')) ?>"><span>CA</span><strong>Calendario</strong><small>Ocupación por auditorio</small></a>
            <a href="<?= coord_h(app_url('coordinador/auditorios.php')) ?>"><span>AU</span><strong>Auditorios</strong><small>Capacidad y dotación</small></a>
            <a href="<?= coord_h(app_url('coordinador/historial.php')) ?>"><span>HI</span><strong>Historial</strong><small>Decisiones registradas</small></a>
        </div>

        <div class="panel-head panel-sub">
            <div>
                <p class="eyebrow">Próximos aprobados</p>
                <h2>Eventos confirmados</h2>
            </div>
        </div>
        <?php if (!$eventosAprobados): ?>
            <div class="empty-state">Aún no tienes eventos aprobados próximos.</div>
        <?php endif; ?>
        <?php foreach ($eventosAprobados as $evento): ?>
            <?php $fechaAprobada = new DateTime((string)$evento['fecha_evento']); ?>
            <article class="coord-mini-event">
                <time><?= coord_h($fechaAprobada->format('d')) ?> <?= coord_h($monthLabels[(int)$fechaAprobada->format('n')]) ?></time>
                <div>
                    <strong><?= coord_h($evento['nombre_evento']) ?></strong>
                    <small><?= coord_h($evento['nombre_auditorio']) ?> · <?= coord_h(coord_hora12((string)$evento['hora_inicio'])) ?></small>
                </div>
                <a class="status-pill navy" href="<?= coord_h(app_url('coordinador/detalle_solicitud.php?id=' . (int)$evento['id_evento'])) ?>">Activo</a>
            </article>
        <?php endforeach; ?>
    </aside>
</section>

<section class="panel">
    <div class="panel-head">
        <div>
            <p class="eyebrow">Estado general</p>
            <h2>Indicadores de tu coordinación</h2>
        </div>
    </div>
    <div class="coord-status-summary">
        <div><span>Pendientes</span><strong><?= coord_h($pendientesCount) ?></strong></div>
        <div><span>Aprobadas</span><strong><?= coord_h($aprobadas) ?></strong></div>
        <div><span>Canceladas</span><strong><?= coord_h($rechazadas) ?></strong></div>
        <div><span>Finalizadas</span><strong><?= coord_h($finalizadas) ?></strong></div>
    </div>
</section>

<?php coord_layout_end(); ?>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
