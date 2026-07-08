<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([2]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/coordinador_panel.php';

$pageTitle = 'Calendario de auditorios - Coordinador SICA';
$pageStyles = ['css/instructor.css'];

$usuario = coord_user();
$coordinadorId = (int)($usuario['id_documento'] ?? 0);

$auditorios = [];
$events = [];
$eventsByDay = [];
$selectedAuditorioData = null;
$monthLabels = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];

try {
    $auditorios = coord_rows(
        $pdo,
        "SELECT a.*, e.nombre_estado AS estado
         FROM auditorio a
         INNER JOIN estado e ON e.id_estado = a.id_estado
         WHERE e.nombre_estado = 'Activo'
         ORDER BY a.nombre_auditorio"
    );
} catch (Throwable $exception) {
    error_log('SICA coordinador calendario auditorios: ' . $exception->getMessage());
}

$auditorioRaw = trim((string)($_GET['auditorio'] ?? ''));
$selectedAuditorio = $auditorioRaw !== '' && ctype_digit($auditorioRaw) ? (int)$auditorioRaw : (int)($auditorios[0]['id_auditorio'] ?? 0);
foreach ($auditorios as $auditorio) {
    if ((int)$auditorio['id_auditorio'] === $selectedAuditorio) {
        $selectedAuditorioData = $auditorio;
        break;
    }
}
if (!$selectedAuditorioData && $auditorios) {
    $selectedAuditorioData = $auditorios[0];
    $selectedAuditorio = (int)$selectedAuditorioData['id_auditorio'];
}

$monthRaw = (string)($_GET['mes'] ?? '');
$month = preg_match('/^\d{4}-\d{2}$/', $monthRaw) && checkdate((int)substr($monthRaw, 5, 2), 1, (int)substr($monthRaw, 0, 4))
    ? $monthRaw
    : date('Y-m');

$fechaRaw = trim((string)($_GET['fecha'] ?? ''));
$selectedDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaRaw)
    && checkdate((int)substr($fechaRaw, 5, 2), (int)substr($fechaRaw, 8, 2), (int)substr($fechaRaw, 0, 4))
    && str_starts_with($fechaRaw, $month . '-')
    ? $fechaRaw
    : '';

$start = new DateTimeImmutable($month . '-01');
$prevMonth = $start->modify('-1 month')->format('Y-m');
$nextMonth = $start->modify('+1 month')->format('Y-m');
$daysInMonth = (int)$start->format('t');
$firstWeekday = (int)$start->format('N');

$monthStats = ['libres' => 0, 'pendientes' => 0, 'reservados' => 0];

if ($selectedAuditorio > 0) {
    try {
        $events = coord_rows(
            $pdo,
            'SELECT e.id_evento, e.nombre_evento, e.fecha_evento, e.hora_inicio, e.hora_fin,
                    es.nombre_estado AS estado, e.id_coordinador, u.nombre, u.apellido
             FROM evento e
             INNER JOIN estado es ON es.id_estado = e.id_estado
             LEFT JOIN usuario u ON u.id_documento = e.id_solicitante
             WHERE e.id_auditorio = :auditorio
               AND DATE_FORMAT(e.fecha_evento, "%Y-%m") = :mes
               AND es.nombre_estado IN (\'Pendiente\', \'Activo\')
             ORDER BY e.fecha_evento, e.hora_inicio',
            [':auditorio' => $selectedAuditorio, ':mes' => $month]
        );
    } catch (Throwable $exception) {
        error_log('SICA coordinador calendario eventos: ' . $exception->getMessage());
    }
}

foreach ($events as $event) {
    $eventsByDay[(string)$event['fecha_evento']][] = $event;
}

for ($day = 1; $day <= $daysInMonth; $day++) {
    $date = $start->setDate((int)$start->format('Y'), (int)$start->format('m'), $day)->format('Y-m-d');
    $dayEvents = $eventsByDay[$date] ?? [];
    if ($dayEvents === []) {
        $monthStats['libres']++;
        continue;
    }
    $hasActive = false;
    $hasPending = false;
    foreach ($dayEvents as $dayEvent) {
        if ((string)$dayEvent['estado'] === 'Activo') {
            $hasActive = true;
        }
        if ((string)$dayEvent['estado'] === 'Pendiente') {
            $hasPending = true;
        }
    }
    if ($hasActive) {
        $monthStats['reservados']++;
    } elseif ($hasPending) {
        $monthStats['pendientes']++;
    }
}

$misEventosMes = count(array_filter(
    $events,
    static fn(array $event): bool => (int)$event['id_coordinador'] === $coordinadorId
));

$selectedDayEvents = $selectedDate !== '' ? ($eventsByDay[$selectedDate] ?? []) : [];
$selectedDayLabel = '';
if ($selectedDate !== '') {
    try {
        $dt = new DateTimeImmutable($selectedDate);
        $selectedDayLabel = $dt->format('d') . ' de ' . $monthLabels[(int)$dt->format('n')] . ' ' . $dt->format('Y');
    } catch (Throwable) {
        $selectedDayLabel = $selectedDate;
    }
}

$baseUrl = static function (int $auditorio, string $mes, string $fecha = ''): string {
    $params = ['auditorio' => $auditorio, 'mes' => $mes];
    if ($fecha !== '') {
        $params['fecha'] = $fecha;
    }
    return app_url('coordinador/calendario.php?' . http_build_query($params));
};
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>
<?php coord_layout_start('calendario'); ?>

<header class="instructor-topbar">
    <div>
        <p class="eyebrow">Ocupación de auditorios</p>
        <h1>Calendario de coordinación</h1>
        <span>Consulta disponibilidad, reservas aprobadas y solicitudes pendientes antes de decidir.</span>
    </div>
    <div class="topbar-actions">
        <a class="top-action" href="<?= coord_h(app_url('coordinador/index.php')) ?>">Dashboard</a>
        <a class="top-action" href="<?= coord_h(app_url('coordinador/solicitudes.php')) ?>">Solicitudes</a>
    </div>
</header>

<section class="metric-grid coord-calendar-metrics" aria-label="Resumen del mes">
    <article class="metric-tile green"><span>Días libres</span><strong><?= coord_h($monthStats['libres']) ?></strong><small>Sin reservas ni pendientes</small><em>Mes</em></article>
    <article class="metric-tile amber"><span>Con pendientes</span><strong><?= coord_h($monthStats['pendientes']) ?></strong><small>Solicitudes por revisar</small><em>Revisión</em></article>
    <article class="metric-tile navy"><span>Con reservas</span><strong><?= coord_h($monthStats['reservados']) ?></strong><small>Eventos ya aprobados</small><em>Activos</em></article>
    <article class="metric-tile blue"><span>Tus solicitudes</span><strong><?= coord_h($misEventosMes) ?></strong><small>En este auditorio y mes</small><em>Bandeja</em></article>
</section>

<section class="calendar-layout">
    <article class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Auditorio</p>
                <h2><?= coord_h($monthLabels[(int)$start->format('n')] . ' ' . $start->format('Y')) ?></h2>
                <span class="panel-subtitle">Haz clic en un día para ver el detalle.</span>
            </div>
        </div>

        <form class="calendar-toolbar" method="get">
            <a href="<?= coord_h($baseUrl($selectedAuditorio, $prevMonth)) ?>">Anterior</a>
            <select name="auditorio" onchange="this.form.submit()">
                <?php foreach ($auditorios as $auditorio): ?>
                    <option value="<?= coord_h($auditorio['id_auditorio']) ?>" <?= (int)$auditorio['id_auditorio'] === $selectedAuditorio ? 'selected' : '' ?>>
                        <?= coord_h($auditorio['nombre_auditorio']) ?> / Bloque <?= coord_h($auditorio['bloque']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="mes" value="<?= coord_h($month) ?>">
            <a href="<?= coord_h($baseUrl($selectedAuditorio, $nextMonth)) ?>">Siguiente</a>
        </form>

        <?php if ($selectedAuditorioData): ?>
            <article class="auditorium-feature-card" aria-label="Características del auditorio seleccionado">
                <div>
                    <p class="eyebrow">Características del auditorio</p>
                    <h3><?= coord_h($selectedAuditorioData['nombre_auditorio']) ?></h3>
                    <span>Información disponible para decidir antes de aprobar una solicitud.</span>
                </div>
                <div class="auditorium-feature-grid">
                    <span><strong><?= coord_h($selectedAuditorioData['bloque']) ?></strong><small class="feature-label">Bloque</small></span>
                    <span><strong><?= coord_h($selectedAuditorioData['capacidad']) ?></strong><small class="feature-label">Cupos máximos</small></span>
                    <span><strong class="<?= coord_h(coord_dotacion_value_class($selectedAuditorioData['cantidad_computadores'] ?? null)) ?>"><?= coord_h(coord_computadores_label($selectedAuditorioData['cantidad_computadores'] ?? null)) ?></strong><small class="feature-label">Computadores</small></span>
                    <span><strong class="<?= coord_h(coord_dotacion_value_class($selectedAuditorioData['tiene_aire_acondicionado'] ?? null)) ?>"><?= coord_h(coord_dotacion_label($selectedAuditorioData['tiene_aire_acondicionado'] ?? null)) ?></strong><small class="feature-label">Aire acondicionado</small></span>
                    <span><strong class="<?= coord_h(coord_dotacion_value_class($selectedAuditorioData['tiene_ventilador'] ?? null)) ?>"><?= coord_h(coord_dotacion_label($selectedAuditorioData['tiene_ventilador'] ?? null)) ?></strong><small class="feature-label">Ventilador</small></span>
                    <span><strong class="<?= coord_h(coord_dotacion_value_class($selectedAuditorioData['tiene_tablero'] ?? null)) ?>"><?= coord_h(coord_dotacion_label($selectedAuditorioData['tiene_tablero'] ?? null)) ?></strong><small class="feature-label">Tablero / pizarra</small></span>
                    <span><strong class="<?= coord_h(coord_dotacion_value_class($selectedAuditorioData['tiene_televisor'] ?? null)) ?>"><?= coord_h(coord_dotacion_label($selectedAuditorioData['tiene_televisor'] ?? null)) ?></strong><small class="feature-label">Televisor</small></span>
                    <span><strong><?= coord_h($selectedAuditorioData['estado']) ?></strong><small class="feature-label">Estado</small></span>
                </div>
            </article>
        <?php endif; ?>

        <div class="calendar-legend" aria-label="Leyenda del calendario">
            <span class="legend-free">Libre</span>
            <span class="legend-pending">Con pendientes</span>
            <span class="legend-active">Con reservas</span>
            <span class="legend-mine">Tu solicitud asignada</span>
        </div>

        <?php if (!$auditorios): ?>
            <div class="empty-state">No hay auditorios activos registrados.</div>
        <?php else: ?>
            <div class="calendar-grid" role="grid" aria-label="Calendario mensual de auditorio">
                <?php foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $dayName): ?>
                    <div class="calendar-day-name" role="columnheader"><?= coord_h($dayName) ?></div>
                <?php endforeach; ?>

                <?php for ($i = 1; $i < $firstWeekday; $i++): ?>
                    <div class="calendar-cell muted" aria-hidden="true"></div>
                <?php endfor; ?>

                <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                    <?php
                    $date = $start->setDate((int)$start->format('Y'), (int)$start->format('m'), $day)->format('Y-m-d');
                    $dayEvents = $eventsByDay[$date] ?? [];
                    $hasActive = false;
                    $hasPending = false;
                    $mineCount = 0;
                    foreach ($dayEvents as $dayEvent) {
                        if ((string)$dayEvent['estado'] === 'Activo') {
                            $hasActive = true;
                        }
                        if ((string)$dayEvent['estado'] === 'Pendiente') {
                            $hasPending = true;
                        }
                        if ((int)$dayEvent['id_coordinador'] === $coordinadorId) {
                            $mineCount++;
                        }
                    }
                    $dayClass = $dayEvents === [] ? 'available' : ($hasActive ? 'has-active' : 'has-pending');
                    $dayText = $dayEvents === [] ? 'Libre' : ($hasActive ? 'Con reservas' : 'Con pendientes');
                    $isSelected = $selectedDate === $date;
                    $dayUrl = $baseUrl($selectedAuditorio, $month, $date);
                    ?>
                    <div class="calendar-cell <?= coord_h(trim($dayClass . ($isSelected ? ' is-selected' : ''))) ?>" role="gridcell">
                        <div class="calendar-date">
                            <a class="calendar-day-link" href="<?= coord_h($dayUrl) ?>"><?= coord_h($day) ?></a>
                            <?php if ($mineCount > 0): ?>
                                <span class="calendar-mine-badge"><?= coord_h($mineCount) ?> mío</span>
                            <?php endif; ?>
                        </div>
                        <small class="calendar-day-status"><?= coord_h($dayText) ?></small>
                        <?php foreach ($dayEvents as $dayEvent): ?>
                            <?php $eventClass = (string)$dayEvent['estado'] === 'Pendiente' ? 'pending' : 'busy'; ?>
                            <span class="calendar-event <?= coord_h($eventClass) ?>">
                                <strong><?= coord_h(coord_hora12((string)$dayEvent['hora_inicio'])) ?></strong>
                                <?= coord_h($dayEvent['nombre_evento']) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </article>

    <aside class="panel coord-calendar-side" aria-label="Detalle del día seleccionado">
        <?php if ($selectedDate !== ''): ?>
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Detalle del día</p>
                    <h2><?= coord_h($selectedDayLabel) ?></h2>
                    <span class="panel-subtitle">Reservas y solicitudes registradas en este auditorio.</span>
                </div>
            </div>

            <?php if (!$selectedDayEvents): ?>
                <div class="empty-state">Este día está libre en el auditorio seleccionado.</div>
            <?php else: ?>
                <div class="coord-calendar-day-list">
                    <?php foreach ($selectedDayEvents as $event): ?>
                        <?php
                        $isMine = (int)$event['id_coordinador'] === $coordinadorId;
                        $instructor = trim((string)$event['nombre'] . ' ' . (string)$event['apellido']);
                        $instructor = $instructor !== '' ? $instructor : 'Instructor SICA';
                        $estado = (string)$event['estado'];
                        $eventClass = $estado === 'Pendiente' ? 'pending' : 'busy';
                        ?>
                        <div class="<?= $isMine ? 'coord-calendar-day-item is-mine' : 'coord-calendar-day-item' ?>">
                            <span class="calendar-event <?= coord_h($eventClass) ?>">
                                <strong><?= coord_h(coord_hora12((string)$event['hora_inicio'])) ?> a <?= coord_h(coord_hora12((string)$event['hora_fin'])) ?></strong>
                                <?= coord_h($event['nombre_evento']) ?>
                            </span>
                            <small>Instructor: <?= coord_h($instructor) ?></small>
                            <span class="status-pill <?= coord_h(coord_pill_class($estado)) ?>"><?= coord_h($estado) ?></span>
                            <?php if ($isMine): ?>
                                <div class="hero-actions">
                                    <?php if ($estado === 'Pendiente'): ?>
                                        <a class="primary-btn" href="<?= coord_h(app_url('coordinador/detalle_solicitud.php?id=' . (int)$event['id_evento'])) ?>">Revisar solicitud</a>
                                    <?php else: ?>
                                        <a class="secondary-btn" href="<?= coord_h(app_url('coordinador/detalle_solicitud.php?id=' . (int)$event['id_evento'])) ?>">Ver detalle</a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <small class="coord-calendar-note">Asignada a otro coordinador.</small>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Detalle del día</p>
                    <h2>Selecciona un día</h2>
                    <span class="panel-subtitle">Haz clic en cualquier día del calendario para ver sus eventos.</span>
                </div>
            </div>
            <div class="empty-state">Ningún día seleccionado. Haz clic en una fecha para consultar las reservas de ese día.</div>
        <?php endif; ?>
    </aside>
</section>

<?php coord_layout_end(); ?>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
