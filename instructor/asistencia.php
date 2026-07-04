<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([3]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/instructor_panel.php';

$pageTitle = 'Asistencia - Instructor SICA';
$pageStyles = ['css/instructor.css'];
$idInstructor = (int)(instructor_user()['id_documento'] ?? 0);
$eventos = instructor_rows($pdo, instructor_event_query() . " WHERE e.id_solicitante = :id ORDER BY e.fecha_evento DESC, e.hora_inicio DESC", [':id' => $idInstructor]);
$selectedId = (int)($_GET['evento'] ?? ($eventos[0]['id_evento'] ?? 0));
$evento = null;
foreach ($eventos as $item) {
    if ((int)$item['id_evento'] === $selectedId) {
        $evento = $item;
        break;
    }
}
$participantes = $evento ? instructor_scalar($pdo, 'SELECT COUNT(*) FROM preregistro WHERE id_evento = :id', [':id' => (int)$evento['id_evento']]) : 0;
$qrPayload = $evento ? instructor_event_qr_payload($evento) : '';
$qrImageUrl = $evento ? instructor_qr_image_url($qrPayload, 240) : '';
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>
<?php instructor_layout_start('asistencia'); ?>

<header class="instructor-topbar">
    <div>
        <p class="eyebrow">Asistencia / Codigo</p>
        <h1>Codigo de ingreso</h1>
        <span>Consulta el codigo del evento. Solo queda habilitado para asistencia cuando coordinacion aprueba la solicitud.</span>
    </div>
    <a class="top-action" href="<?= instructor_h(app_url('instructor/participantes.php')) ?>">Participantes</a>
</header>

<section class="participants-layout">
    <article class="panel">
        <div class="panel-head">
            <div><p class="eyebrow">Eventos solicitados</p><h2>Selecciona evento</h2></div>
        </div>
        <form class="calendar-toolbar" method="get">
            <select name="evento" onchange="this.form.submit()">
                <?php foreach ($eventos as $item): ?>
                    <option value="<?= instructor_h($item['id_evento']) ?>" <?= (int)$item['id_evento'] === $selectedId ? 'selected' : '' ?>><?= instructor_h($item['nombre_evento']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if (!$evento): ?>
            <div class="empty-state">No tienes eventos solicitados todavia. Crea una solicitud desde disponibilidad para generar el codigo del evento.</div>
        <?php else: ?>
            <div class="detail-grid">
                <div class="detail-box"><span>Fecha</span><strong><?= instructor_h((new DateTime((string)$evento['fecha_evento']))->format('d/m/Y')) ?></strong></div>
                <div class="detail-box"><span>Hora</span><strong><?= instructor_h(substr((string)$evento['hora_inicio'], 0, 5)) ?> a <?= instructor_h(substr((string)$evento['hora_fin'], 0, 5)) ?></strong></div>
                <div class="detail-box"><span>Pre-registrados</span><strong><?= instructor_h($participantes) ?></strong></div>
                <div class="detail-box"><span>Estado</span><strong><?= instructor_h($evento['estado']) ?></strong></div>
                <div class="detail-box"><span>Auditorio</span><strong><?= instructor_h($evento['nombre_auditorio']) ?></strong></div>
                <div class="detail-box"><span>Codigo</span><strong><?= instructor_h($evento['codigo_evento']) ?></strong></div>
            </div>
        <?php endif; ?>
    </article>

    <aside class="panel">
        <?php if ($evento): ?>
            <div class="qr-card <?= (string)$evento['estado'] !== 'Activo' ? 'locked' : '' ?>">
                <img src="<?= instructor_h($qrImageUrl) ?>" alt="Codigo QR del evento <?= instructor_h($evento['nombre_evento']) ?>">
                <strong><?= instructor_h($evento['codigo_evento']) ?></strong>
                <span class="panel-subtitle"><?= instructor_h($evento['nombre_evento']) ?></span>
                <small>Al escanearlo abre el pre-registro del aprendiz para este evento.</small>
                <?php if ((string)$evento['estado'] === 'Activo'): ?>
                    <a class="primary-btn" href="<?= instructor_h(app_url('instructor/descargar_codigo.php?evento=' . (int)$evento['id_evento'])) ?>">Descargar QR</a>
                <?php else: ?>
                    <span class="status-pill pending">Pendiente de aprobacion</span>
                    <small>El QR se activara para asistencia cuando coordinacion apruebe el evento.</small>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">Codigo pendiente.</div>
        <?php endif; ?>
    </aside>
</section>

<?php instructor_layout_end(); ?>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
