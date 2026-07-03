<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([3]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/instructor_panel.php';

$pageTitle = 'Detalle de solicitud - Instructor SICA';
$pageStyles = ['css/instructor.css'];
$idInstructor = (int)(instructor_user()['id_documento'] ?? 0);
$idEvento = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare(instructor_event_query() . ' WHERE e.id_evento = :id AND e.id_solicitante = :instructor LIMIT 1');
$stmt->execute([':id' => $idEvento, ':instructor' => $idInstructor]);
$evento = $stmt->fetch();
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>
<?php instructor_layout_start('solicitudes'); ?>

<header class="instructor-topbar">
    <div>
        <p class="eyebrow">Detalle</p>
        <h1>Detalle de la solicitud</h1>
        <span>Seguimiento del evento y estado administrativo.</span>
    </div>
    <a class="top-action" href="<?= instructor_h(app_url('instructor/mis_solicitudes.php')) ?>">Volver</a>
</header>

<?php if (!$evento): ?>
    <section class="panel"><div class="empty-state">La solicitud no existe o no pertenece a tu usuario.</div></section>
<?php else: ?>
    <?php $fecha = new DateTime((string)$evento['fecha_evento']); ?>
    <section class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Solicitud <?= instructor_h($evento['codigo_evento']) ?></p>
                <h2><?= instructor_h($evento['nombre_evento']) ?></h2>
                <span class="panel-subtitle"><?= instructor_h($evento['descripcion'] ?: 'Sin descripcion registrada') ?></span>
            </div>
            <span class="status-pill <?= instructor_h(instructor_status_class((string)$evento['estado'])) ?>"><?= instructor_h($evento['estado']) ?></span>
        </div>
        <div class="detail-grid">
            <div class="detail-box"><span>Auditorio</span><strong><?= instructor_h($evento['nombre_auditorio']) ?> / Bloque <?= instructor_h($evento['bloque']) ?></strong></div>
            <div class="detail-box"><span>Fecha</span><strong><?= instructor_h($fecha->format('d/m/Y')) ?></strong></div>
            <div class="detail-box"><span>Hora</span><strong><?= instructor_h(substr((string)$evento['hora_inicio'], 0, 5)) ?> a <?= instructor_h(substr((string)$evento['hora_fin'], 0, 5)) ?></strong></div>
            <div class="detail-box"><span>Tipo</span><strong><?= instructor_h($evento['nombre_tipo']) ?></strong></div>
            <div class="detail-box"><span>Codigo</span><strong><?= instructor_h($evento['codigo_evento']) ?></strong></div>
            <div class="detail-box"><span>Observacion</span><strong><?= instructor_h($evento['observacion'] ?: 'Sin observaciones') ?></strong></div>
        </div>
    </section>
    <?php if ((string)$evento['estado'] === 'Activo'): ?>
        <section class="panel">
            <div class="panel-head">
                <div><p class="eyebrow">Asistencia</p><h2>Codigo de ingreso disponible</h2></div>
                <a class="primary-btn" href="<?= instructor_h(app_url('instructor/asistencia.php?evento=' . (int)$evento['id_evento'])) ?>">Abrir codigo</a>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php instructor_layout_end(); ?>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
