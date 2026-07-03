<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([3]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/instructor_panel.php';

$pageTitle = 'Mis solicitudes - Instructor SICA';
$pageStyles = ['css/instructor.css'];
$idInstructor = (int)(instructor_user()['id_documento'] ?? 0);
$estadoFiltro = trim((string)($_GET['estado'] ?? ''));
$params = [':id' => $idInstructor];
$where = ' WHERE e.id_solicitante = :id';
if ($estadoFiltro !== '') {
    $where .= ' AND es.nombre_estado = :estado';
    $params[':estado'] = $estadoFiltro;
}
$solicitudes = instructor_rows($pdo, instructor_event_query() . $where . ' ORDER BY e.fecha_evento DESC, e.hora_inicio DESC', $params);
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>
<?php instructor_layout_start('solicitudes'); ?>

<header class="instructor-topbar">
    <div>
        <p class="eyebrow">Mis solicitudes</p>
        <h1>Historial de reservas</h1>
        <span>Consulta estados, observaciones y detalles de tus solicitudes de auditorio.</span>
    </div>
    <a class="top-action" href="<?= instructor_h(app_url('instructor/disponibilidad.php')) ?>">Nueva solicitud</a>
</header>

<section class="panel">
    <div class="panel-head">
        <div>
            <p class="eyebrow">Solicitudes</p>
            <h2>Eventos solicitados</h2>
        </div>
        <form class="calendar-toolbar" method="get">
            <select name="estado" onchange="this.form.submit()">
                <option value="">Todos los estados</option>
                <?php foreach (['Pendiente','Activo','Cancelado','Finalizado'] as $estado): ?>
                    <option value="<?= instructor_h($estado) ?>" <?= $estadoFiltro === $estado ? 'selected' : '' ?>><?= instructor_h($estado) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="request-list">
        <?php if (!$solicitudes): ?><div class="empty-state">No hay solicitudes para este filtro.</div><?php endif; ?>
        <?php foreach ($solicitudes as $evento): ?>
            <?php $fecha = new DateTime((string)$evento['fecha_evento']); ?>
            <article class="request-row">
                <div class="request-date"><?= instructor_h($fecha->format('d M')) ?></div>
                <div>
                    <h3><?= instructor_h($evento['nombre_evento']) ?></h3>
                    <small><?= instructor_h($evento['nombre_auditorio']) ?> / <?= instructor_h($evento['nombre_tipo']) ?> - <?= instructor_h(substr((string)$evento['hora_inicio'], 0, 5)) ?> a <?= instructor_h(substr((string)$evento['hora_fin'], 0, 5)) ?></small>
                </div>
                <a class="status-pill <?= instructor_h(instructor_status_class((string)$evento['estado'])) ?>" href="<?= instructor_h(app_url('instructor/detalle_solicitud.php?id=' . (int)$evento['id_evento'])) ?>"><?= instructor_h($evento['estado']) ?></a>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<?php instructor_layout_end(); ?>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
