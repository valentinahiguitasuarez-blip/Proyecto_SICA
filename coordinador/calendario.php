<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([2]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/coordinador_panel.php';

$pageTitle = 'Calendario de auditorios - Coordinador SICA';
$pageStyles = ['css/admin.css'];

$auditorios = coord_rows($pdo, "SELECT a.* FROM auditorio a INNER JOIN estado e ON e.id_estado = a.id_estado WHERE e.nombre_estado = 'Activo' ORDER BY a.nombre_auditorio");
$selectedAuditorio = (int)($_GET['auditorio'] ?? ($auditorios[0]['id_auditorio'] ?? 0));
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) ? (string)$_GET['mes'] : date('Y-m');

$start = new DateTimeImmutable($month . '-01');
$prevMonth = $start->modify('-1 month')->format('Y-m');
$nextMonth = $start->modify('+1 month')->format('Y-m');
$daysInMonth = (int)$start->format('t');
$firstWeekday = (int)$start->format('N');

$events = coord_rows(
    $pdo,
    'SELECT e.id_evento, e.nombre_evento, e.fecha_evento, e.hora_inicio, e.hora_fin, es.nombre_estado AS estado,
            u.nombre, u.apellido
     FROM evento e
     INNER JOIN estado es ON es.id_estado = e.id_estado
     LEFT JOIN usuario u ON u.id_documento = e.id_solicitante
     WHERE e.id_auditorio = :auditorio AND DATE_FORMAT(e.fecha_evento, "%Y-%m") = :mes
       AND es.nombre_estado IN (\'Pendiente\', \'Activo\')
     ORDER BY e.fecha_evento, e.hora_inicio',
    [':auditorio' => $selectedAuditorio, ':mes' => $month]
);
$eventsByDay = [];
foreach ($events as $event) {
    $eventsByDay[(string)$event['fecha_evento']][] = $event;
}
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>
<?php coord_layout_start('calendario'); ?>

        <header class="admin-topbar">
            <div>
                <p class="admin-eyebrow">Disponibilidad</p>
                <h1>Calendario de auditorios</h1>
                <span>Consulta que fechas estan ocupadas o pendientes antes de tomar una decision.</span>
            </div>
            <div class="admin-top-actions">
                <a href="<?= coord_h(app_url('coordinador/index.php')) ?>">Solicitudes <strong>SR</strong></a>
                <a href="<?= coord_h(app_url('coordinador/auditorios.php')) ?>">Auditorios <strong>AU</strong></a>
            </div>
        </header>

        <section class="admin-panel coord-calendar-panel">
            <div class="admin-panel-head">
                <div>
                    <p class="admin-eyebrow">Auditorio</p>
                    <h2><?= coord_h($start->format('F Y')) ?></h2>
                    <span class="admin-panel-note">Los eventos pendientes y aprobados aparecen dentro del dia para apoyar la revision de cruces.</span>
                </div>
            </div>
            <form class="coord-calendar-toolbar" method="get">
                <a class="coord-secondary-action" href="<?= coord_h(app_url('coordinador/calendario.php?auditorio=' . $selectedAuditorio . '&mes=' . $prevMonth)) ?>">Anterior</a>
                <label>
                    <span>Auditorio</span>
                    <select name="auditorio" onchange="this.form.submit()">
                    <?php foreach ($auditorios as $auditorio): ?>
                        <option value="<?= coord_h($auditorio['id_auditorio']) ?>" <?= (int)$auditorio['id_auditorio'] === $selectedAuditorio ? 'selected' : '' ?>>
                            <?= coord_h($auditorio['nombre_auditorio']) ?> / Bloque <?= coord_h($auditorio['bloque']) ?>
                        </option>
                    <?php endforeach; ?>
                    </select>
                </label>
                <input type="hidden" name="mes" value="<?= coord_h($month) ?>">
                <a class="coord-primary-action" href="<?= coord_h(app_url('coordinador/calendario.php?auditorio=' . $selectedAuditorio . '&mes=' . $nextMonth)) ?>">Siguiente</a>
            </form>
            <div class="coord-calendar-wide">
                <div class="coord-calendar-grid">
                    <?php foreach (['Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab', 'Dom'] as $day): ?><div class="coord-calendar-day-name"><?= coord_h($day) ?></div><?php endforeach; ?>
                    <?php for ($i = 1; $i < $firstWeekday; $i++): ?><div class="coord-calendar-cell muted"></div><?php endfor; ?>
                    <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                        <?php $date = $start->setDate((int)$start->format('Y'), (int)$start->format('m'), $day)->format('Y-m-d'); ?>
                        <?php $dayEvents = $eventsByDay[$date] ?? []; ?>
                        <div class="coord-calendar-cell<?= empty($dayEvents) ? ' available' : '' ?>">
                            <div class="coord-calendar-date"><span><?= coord_h($day) ?></span></div>
                            <?php foreach ($dayEvents as $event): ?>
                                <?php
                                $class = (string)$event['estado'] === 'Pendiente' ? 'pending' : 'busy';
                                $solicitante = trim((string)$event['nombre'] . ' ' . (string)$event['apellido']);
                                $horaLegible = coord_hora12((string)$event['hora_inicio']) . ' - ' . coord_hora12((string)$event['hora_fin']);
                                ?>
                                <span class="coord-calendar-event <?= coord_h($class) ?>" title="<?= coord_h($event['nombre_evento'] . ' - ' . $solicitante) ?>" aria-hidden="true">
                                    <strong><?= coord_h($horaLegible) ?></strong><br>
                                    <?= coord_h($event['nombre_evento']) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
        </section>
<?php coord_layout_end(); ?>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
