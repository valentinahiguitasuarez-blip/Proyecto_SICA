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
];

$proximas = instructor_rows(
    $pdo,
    instructor_event_query() . ' WHERE e.id_solicitante = :id ORDER BY e.fecha_evento DESC, e.hora_inicio DESC LIMIT 4',
    [':id' => $idInstructor]
);
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>
<?php instructor_layout_start('dashboard'); ?>

<header class="instructor-topbar">
    <div>
        <p class="eyebrow">Dashboard instructor</p>
        <h1>Bienvenido, <?= instructor_h($nombre) ?></h1>
        <span>Consulta disponibilidad, solicita auditorios y administra la asistencia de tus eventos.</span>
    </div>
    <a class="top-action" href="<?= instructor_h(app_url('instructor/disponibilidad.php')) ?>">Nueva solicitud</a>
</header>

<section class="hero-card">
    <div>
        <p class="eyebrow">Ruta de auditorios</p>
        <h2>Planea el evento, solicita el espacio y confirma asistencia.</h2>
        <p>SICA te muestra disponibilidad real, guarda tus solicitudes y te da un codigo de ingreso cuando el evento queda aprobado.</p>
        <div class="hero-actions">
            <a class="primary-btn" href="<?= instructor_h(app_url('instructor/disponibilidad.php')) ?>">Ver disponibilidad</a>
            <a class="secondary-btn" href="<?= instructor_h(app_url('instructor/mis_solicitudes.php')) ?>">Mis solicitudes</a>
        </div>
    </div>
</section>

<section class="metric-grid" aria-label="Resumen del instructor">
    <article class="metric-tile"><span>Auditorios activos</span><strong><?= instructor_h($stats['auditorios']) ?></strong><small>Disponibles para programar</small></article>
    <article class="metric-tile"><span>Solicitudes creadas</span><strong><?= instructor_h($stats['solicitudes']) ?></strong><small>Historial de reservas</small></article>
    <article class="metric-tile"><span>Pendientes</span><strong><?= instructor_h($stats['pendientes']) ?></strong><small>En revision administrativa</small></article>
    <article class="metric-tile"><span>Aprobadas</span><strong><?= instructor_h($stats['aprobadas']) ?></strong><small>Listas para asistencia</small></article>
</section>

<section class="action-grid">
    <article class="action-card">
        <span class="action-icon">DI</span>
        <h2>Ver disponibilidad</h2>
        <p>Explora el calendario por auditorio y elige una fecha sin cruces para tu evento.</p>
        <a class="primary-btn" href="<?= instructor_h(app_url('instructor/disponibilidad.php')) ?>">Abrir calendario</a>
    </article>
    <article class="action-card green">
        <span class="action-icon">SO</span>
        <h2>Mis solicitudes</h2>
        <p>Revisa estados, observaciones y detalles de cada solicitud enviada.</p>
        <a class="secondary-btn" href="<?= instructor_h(app_url('instructor/mis_solicitudes.php')) ?>">Ver solicitudes</a>
    </article>
</section>

<section class="panel">
    <div class="panel-head">
        <div>
            <p class="eyebrow">Proceso</p>
            <h2>Como funciona</h2>
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

<section class="panel">
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

<?php instructor_layout_end(); ?>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
