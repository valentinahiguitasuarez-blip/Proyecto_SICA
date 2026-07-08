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
$eventoRaw = trim((string)($_GET['id'] ?? ''));
$idEvento = ctype_digit($eventoRaw) ? (int)$eventoRaw : 0;
$stmt = $pdo->prepare(instructor_event_query() . ' WHERE e.id_evento = :id AND e.id_solicitante = :instructor LIMIT 1');
$stmt->execute([':id' => $idEvento, ':instructor' => $idInstructor]);
$evento = $stmt->fetch();

function instructor_detail_steps(array $evento): array
{
    $estado = (string)($evento['estado'] ?? '');
    $decisionDate = '';

    try {
        if (!empty($evento['fecha_aprobacion'])) {
            $decisionDate = (new DateTime((string)$evento['fecha_aprobacion']))->format('d/m/Y');
        }
    } catch (Throwable) {
        $decisionDate = '';
    }

    return [
        ['key' => 'solicitado', 'label' => 'Solicitado', 'extra' => ''],
        ['key' => 'revision', 'label' => 'En revisión', 'extra' => ''],
        ['key' => 'decision', 'label' => 'Decisión', 'extra' => in_array($estado, ['Activo', 'Cancelado'], true) && $decisionDate !== '' ? ' - ' . $decisionDate : ''],
        ['key' => 'notificado', 'label' => 'Seguimiento', 'extra' => $estado === 'Finalizado' && $decisionDate !== '' ? ' - ' . $decisionDate : ''],
    ];
}

function instructor_detail_step_class(array $step, string $estado): string
{
    $active = match ($estado) {
        'Activo', 'Cancelado' => 'decision',
        'Pendiente' => 'revision',
        'Finalizado' => 'notificado',
        default => 'solicitado',
    };

    if ($active !== (string)$step['key']) {
        return 'step';
    }

    $statusClass = match ($estado) {
        'Activo' => 'state-active',
        'Pendiente' => 'state-pending',
        'Cancelado' => 'state-cancelled',
        'Finalizado' => 'state-finished',
        default => 'state-muted',
    };

    return 'step is-current ' . $statusClass;
}
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
    <?php
    $fecha = new DateTime((string)$evento['fecha_evento']);
    $estado = (string)$evento['estado'];
    $steps = instructor_detail_steps($evento);
    ?>
    <section class="panel detail-request-panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Solicitud <?= instructor_h($evento['codigo_evento']) ?></p>
                <h2><?= instructor_h($evento['nombre_evento']) ?></h2>
                <span class="panel-subtitle"><?= instructor_h($evento['descripcion'] ?: 'Sin descripción registrada') ?></span>
            </div>
            <span class="status-pill <?= instructor_h(instructor_status_class($estado)) ?>"><?= instructor_h($estado) ?></span>
        </div>

        <div class="stepper detail-stepper" aria-label="Estado de la solicitud">
            <?php foreach ($steps as $step): ?>
                <div class="<?= instructor_h(instructor_detail_step_class($step, $estado)) ?>">
                    <div class="dot"></div>
                    <div class="label"><?= instructor_h($step['label'] . $step['extra']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="detail-grid">
            <div class="detail-box"><span>Auditorio</span><strong><?= instructor_h($evento['nombre_auditorio']) ?> / Bloque <?= instructor_h($evento['bloque']) ?></strong></div>
            <div class="detail-box"><span>Fecha</span><strong><?= instructor_h($fecha->format('d/m/Y')) ?></strong></div>
            <div class="detail-box"><span>Hora</span><strong><?= instructor_h(instructor_hora12((string)$evento['hora_inicio'])) ?> a <?= instructor_h(instructor_hora12((string)$evento['hora_fin'])) ?></strong></div>
            <div class="detail-box"><span>Tipo</span><strong><?= instructor_h($evento['nombre_tipo']) ?></strong></div>
            <div class="detail-box"><span>Código</span><strong><?= instructor_h($evento['codigo_evento']) ?></strong></div>
            <div class="detail-box"><span>Capacidad</span><strong><?= instructor_h($evento['capacidad']) ?> personas</strong></div>
        </div>

        <article class="detail-observation <?= trim((string)$evento['observacion']) !== '' ? '' : 'muted' ?>">
            <span>Observación de coordinación</span>
            <strong><?= instructor_h($evento['observacion'] ?: 'Sin observaciones registradas') ?></strong>
        </article>
    </section>
    <?php if ($estado === 'Activo'): ?>
        <section class="panel detail-actions-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Código y participantes</p>
                    <h2>Pre-registro habilitado</h2>
                    <span class="panel-subtitle">Comparte el código del evento y revisa el listado de aprendices vinculados.</span>
                </div>
                <div class="hero-actions">
                    <a class="primary-btn" href="<?= instructor_h(app_url('instructor/asistencia.php?evento=' . (int)$evento['id_evento'])) ?>">Abrir código</a>
                    <a class="secondary-btn" href="<?= instructor_h(app_url('instructor/participantes.php?evento=' . (int)$evento['id_evento'])) ?>">Ver participantes</a>
                </div>
            </div>
        </section>
    <?php elseif ($estado === 'Pendiente'): ?>
        <section class="panel detail-actions-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">En revisión</p>
                    <h2>Solicitud pendiente de aprobación</h2>
                    <span class="panel-subtitle">El código de pre-registro se activará cuando coordinación apruebe la reserva.</span>
                </div>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php instructor_layout_end(); ?>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
