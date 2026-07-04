<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([3]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/instructor_panel.php';

$pageTitle = 'Instructor - SICA';
$pageStyles = ['css/instructor.css'];
$user = instructor_user();
$idInstructor = (int)($user['id_documento'] ?? 0);
$nombre = trim((string)($user['nombre'] ?? 'Instructor'));

$stats = [
    'auditorios' => instructor_scalar($pdo, "SELECT COUNT(*) FROM auditorio a INNER JOIN estado e ON e.id_estado = a.id_estado WHERE e.nombre_estado = 'Activo'"),
    'solicitudes' => instructor_scalar($pdo, 'SELECT COUNT(*) FROM evento WHERE id_solicitante = :id', [':id' => $idInstructor]),
    'pendientes' => instructor_scalar($pdo, "SELECT COUNT(*) FROM evento ev INNER JOIN estado es ON es.id_estado = ev.id_estado WHERE ev.id_solicitante = :id AND es.nombre_estado = 'Pendiente'", [':id' => $idInstructor]),
    'aprobadas' => instructor_scalar($pdo, "SELECT COUNT(*) FROM evento ev INNER JOIN estado es ON es.id_estado = ev.id_estado WHERE ev.id_solicitante = :id AND es.nombre_estado = 'Activo'", [':id' => $idInstructor]),
    'preregistros' => instructor_scalar($pdo, 'SELECT COUNT(*) FROM preregistro pr INNER JOIN evento ev ON ev.id_evento = pr.id_evento WHERE ev.id_solicitante = :id', [':id' => $idInstructor]),
    'asistencias' => instructor_scalar($pdo, "SELECT COUNT(*) FROM preregistro pr INNER JOIN evento ev ON ev.id_evento = pr.id_evento WHERE ev.id_solicitante = :id AND pr.asistencia <> 'Pendiente'", [':id' => $idInstructor]),
];

$proximas = instructor_rows(
    $pdo,
    instructor_event_query() . ' WHERE e.id_solicitante = :id ORDER BY e.fecha_evento DESC, e.hora_inicio DESC LIMIT 5',
    [':id' => $idInstructor]
);

$agenda = instructor_rows(
    $pdo,
    instructor_event_query() . ' WHERE e.id_solicitante = :id ORDER BY CASE WHEN e.fecha_evento >= CURDATE() THEN 0 ELSE 1 END, e.fecha_evento ASC, e.hora_inicio ASC LIMIT 5',
    [':id' => $idInstructor]
);
$eventoDestacado = $agenda[0] ?? null;
$participantesDestacados = $eventoDestacado
    ? instructor_scalar($pdo, 'SELECT COUNT(*) FROM preregistro WHERE id_evento = :id', [':id' => (int)$eventoDestacado['id_evento']])
    : 0;
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>
<?php instructor_layout_start('dashboard'); ?>

<header class="instructor-topbar">
    <div>
        <p class="eyebrow">Dashboard instructor</p>
        <h1>Bienvenido, <?= instructor_h($nombre) ?></h1>
        <span>Consulta disponibilidad, solicita auditorios y administra la asistencia de tus eventos.</span>
    </div>
    <div class="topbar-actions">
        <a class="top-action" href="<?= instructor_h(app_url('instructor/disponibilidad.php')) ?>">Nueva solicitud</a>
        <a class="danger-btn" href="<?= instructor_h(app_url('login/logout.php')) ?>">Cerrar sesion</a>
    </div>
</header>

<section class="dashboard-hero">
    <div class="dashboard-hero-copy">
        <span class="live-pill">Panel activo</span>
        <p class="eyebrow">Centro de control</p>
        <h2>Gestiona auditorios, codigos y asistencia con una vista mas clara.</h2>
        <p>SICA concentra tus solicitudes, el estado de aprobacion, los participantes y el codigo de ingreso para que cada evento avance sin perder trazabilidad.</p>
        <div class="hero-actions">
            <a class="primary-btn" href="<?= instructor_h(app_url('instructor/disponibilidad.php')) ?>">Crear solicitud</a>
            <a class="secondary-btn" href="<?= instructor_h(app_url('instructor/asistencia.php')) ?>">Abrir codigos</a>
        </div>
    </div>
    <div class="dashboard-command" aria-label="Resumen operativo">
        <div class="command-top">
            <span>SICA</span>
            <strong>Auditorios</strong>
        </div>
        <div class="command-map" aria-hidden="true">
            <span class="map-node main-node">QR</span>
            <span class="map-node node-a">DI</span>
            <span class="map-node node-b">SO</span>
            <span class="map-node node-c">AS</span>
            <i></i>
        </div>
        <div class="command-stats">
            <div><span>Solicitudes</span><strong><?= instructor_h($stats['solicitudes']) ?></strong></div>
            <div><span>Pre-registros</span><strong><?= instructor_h($stats['preregistros']) ?></strong></div>
        </div>
    </div>
</section>

<section class="metric-grid" aria-label="Resumen del instructor">
    <article class="metric-tile blue"><span>Auditorios activos</span><strong><?= instructor_h($stats['auditorios']) ?></strong><small>Disponibles para programar</small><em>Disponibilidad</em></article>
    <article class="metric-tile green"><span>Pre-registros</span><strong><?= instructor_h($stats['preregistros']) ?></strong><small>Aprendices inscritos</small><em>Participantes</em></article>
    <article class="metric-tile amber"><span>Pendientes</span><strong><?= instructor_h($stats['pendientes']) ?></strong><small>En revision administrativa</small><em>Seguimiento</em></article>
    <article class="metric-tile navy"><span>Asistencias</span><strong><?= instructor_h($stats['asistencias']) ?></strong><small>Confirmadas en eventos</small><em>Control</em></article>
</section>

<section class="dashboard-grid">
    <article class="next-event-card">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Evento destacado</p>
                <h2><?= $eventoDestacado ? instructor_h($eventoDestacado['nombre_evento']) : 'Tu proximo evento aparecera aqui' ?></h2>
            </div>
            <?php if ($eventoDestacado): ?>
                <span class="status-pill <?= instructor_h(instructor_status_class((string)$eventoDestacado['estado'])) ?>"><?= instructor_h($eventoDestacado['estado']) ?></span>
            <?php endif; ?>
        </div>
        <?php if ($eventoDestacado): ?>
            <?php $fechaDestacada = new DateTime((string)$eventoDestacado['fecha_evento']); ?>
            <div class="event-spotlight">
                <div class="event-date-box">
                    <strong><?= instructor_h($fechaDestacada->format('d')) ?></strong>
                    <span><?= instructor_h($fechaDestacada->format('M')) ?></span>
                </div>
                <div class="event-detail-list">
                    <div><span>Auditorio</span><strong><?= instructor_h($eventoDestacado['nombre_auditorio']) ?></strong></div>
                    <div><span>Horario</span><strong><?= instructor_h(substr((string)$eventoDestacado['hora_inicio'], 0, 5)) ?> - <?= instructor_h(substr((string)$eventoDestacado['hora_fin'], 0, 5)) ?></strong></div>
                    <div><span>Codigo</span><strong><?= instructor_h($eventoDestacado['codigo_evento']) ?></strong></div>
                    <div><span>Pre-registrados</span><strong><?= instructor_h($participantesDestacados) ?></strong></div>
                </div>
            </div>
            <div class="hero-actions">
                <a class="primary-btn" href="<?= instructor_h(app_url('instructor/detalle_solicitud.php?id=' . (int)$eventoDestacado['id_evento'])) ?>">Ver detalle</a>
                <a class="secondary-btn" href="<?= instructor_h(app_url('instructor/asistencia.php?evento=' . (int)$eventoDestacado['id_evento'])) ?>">Codigo QR</a>
            </div>
        <?php else: ?>
            <div class="empty-state">Crea una solicitud desde disponibilidad para activar el seguimiento del evento, codigo y participantes.</div>
            <a class="primary-btn" href="<?= instructor_h(app_url('instructor/disponibilidad.php')) ?>">Nueva solicitud</a>
        <?php endif; ?>
    </article>

    <aside class="quick-panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Accesos rapidos</p>
                <h2>Operaciones</h2>
            </div>
        </div>
        <div class="quick-actions">
            <a href="<?= instructor_h(app_url('instructor/disponibilidad.php')) ?>"><span>DI</span><strong>Disponibilidad</strong><small>Revisar auditorios</small></a>
            <a href="<?= instructor_h(app_url('instructor/mis_solicitudes.php')) ?>"><span>SO</span><strong>Solicitudes</strong><small>Estados y observaciones</small></a>
            <a href="<?= instructor_h(app_url('instructor/asistencia.php')) ?>"><span>QR</span><strong>Codigos</strong><small>Ingreso al evento</small></a>
            <a href="<?= instructor_h(app_url('instructor/participantes.php')) ?>"><span>PA</span><strong>Participantes</strong><small>Pre-registros y asistencia</small></a>
        </div>
    </aside>
</section>

<section class="panel process-panel">
    <div class="panel-head">
        <div>
            <p class="eyebrow">Proceso</p>
            <h2>Ruta operativa del evento</h2>
            <span class="panel-subtitle">Un flujo corto para separar espacios sin perder trazabilidad.</span>
        </div>
    </div>
    <div class="process-grid">
        <article class="process-step"><b>1</b><strong>Consulta disponibilidad</strong><p>Elige auditorio, mes y revisa cruces.</p></article>
        <article class="process-step"><b>2</b><strong>Envia solicitud</strong><p>Registra fecha, hora, tipo y descripcion.</p></article>
        <article class="process-step"><b>3</b><strong>Revision</strong><p>Coordinacion aprueba o rechaza la solicitud.</p></article>
        <article class="process-step"><b>4</b><strong>Asistencia</strong><p>Usa el codigo del evento y consulta participantes.</p></article>
    </div>
</section>

<section class="dashboard-grid lower-dashboard">
<section class="panel activity-panel">
    <div class="panel-head">
        <div>
            <p class="eyebrow">Actividad</p>
            <h2>Movimiento reciente</h2>
        </div>
    </div>
    <div class="activity-list">
        <?php if (!$proximas): ?>
            <div class="empty-state">Aun no hay actividad para mostrar.</div>
        <?php endif; ?>
        <?php foreach (array_slice($proximas, 0, 4) as $evento): ?>
            <?php $fecha = new DateTime((string)$evento['fecha_evento']); ?>
            <article class="activity-item">
                <span class="activity-dot <?= instructor_h(instructor_status_class((string)$evento['estado'])) ?>"></span>
                <div>
                    <strong><?= instructor_h($evento['nombre_evento']) ?></strong>
                    <small><?= instructor_h($evento['estado']) ?> - <?= instructor_h($fecha->format('d/m/Y')) ?> - <?= instructor_h($evento['nombre_auditorio']) ?></small>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="panel request-panel">
    <div class="panel-head">
        <div>
            <p class="eyebrow">Eventos solicitados</p>
            <h2>Seguimiento de tus solicitudes</h2>
        </div>
        <a class="secondary-btn" href="<?= instructor_h(app_url('instructor/mis_solicitudes.php')) ?>">Ver todas</a>
    </div>
    <div class="request-list">
        <?php if (!$proximas): ?>
            <div class="empty-state">Todavia no tienes solicitudes. Empieza revisando la disponibilidad de auditorios.</div>
        <?php endif; ?>
        <?php foreach ($proximas as $evento): ?>
            <?php $fecha = new DateTime((string)$evento['fecha_evento']); ?>
            <article class="request-row">
                <div class="request-date"><?= instructor_h($fecha->format('d M')) ?></div>
                <div>
                    <h3><?= instructor_h($evento['nombre_evento']) ?></h3>
                    <small><?= instructor_h($evento['nombre_auditorio']) ?> - <?= instructor_h(substr((string)$evento['hora_inicio'], 0, 5)) ?> a <?= instructor_h(substr((string)$evento['hora_fin'], 0, 5)) ?></small>
                </div>
                <a class="status-pill <?= instructor_h(instructor_status_class((string)$evento['estado'])) ?>" href="<?= instructor_h(app_url('instructor/detalle_solicitud.php?id=' . (int)$evento['id_evento'])) ?>"><?= instructor_h($evento['estado']) ?></a>
            </article>
        <?php endforeach; ?>
    </div>
</section>
</section>

<?php instructor_layout_end(); ?>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
