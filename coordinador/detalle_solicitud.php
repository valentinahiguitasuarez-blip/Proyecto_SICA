<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([2]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/coordinador_panel.php';

$pageTitle = 'Detalle de solicitud - Coordinador SICA';
$pageStyles = ['css/instructor.css', 'css/admin.css'];

$usuario = coord_user();
$coordinadorId = (int)($usuario['id_documento'] ?? 0);
$eventoRaw = trim((string)($_GET['id'] ?? ''));
$idEvento = ctype_digit($eventoRaw) ? (int)$eventoRaw : 0;

if (empty($_SESSION['csrf_coord_requests'])) {
    $_SESSION['csrf_coord_requests'] = bin2hex(random_bytes(32));
}

$message = $_SESSION['coord_requests_message'] ?? '';
$messageType = $_SESSION['coord_requests_message_type'] ?? 'success';
unset($_SESSION['coord_requests_message'], $_SESSION['coord_requests_message_type']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = (string)($_POST['csrf_coord_requests'] ?? '');
    $postId = (int)($_POST['id_evento'] ?? 0);
    $accion = (string)($_POST['accion'] ?? '');
    $observacion = trim((string)($_POST['observacion'] ?? ''));

    if (!hash_equals((string)$_SESSION['csrf_coord_requests'], $csrf)) {
        $_SESSION['coord_requests_message'] = 'La sesión expiró. Intenta de nuevo.';
        $_SESSION['coord_requests_message_type'] = 'danger';
    } else {
        try {
            coord_register_decision($pdo, $coordinadorId, $postId, $accion, $observacion);
            $_SESSION['coord_requests_message'] = 'Decisión registrada. El administrador podrá notificar al instructor.';
            $_SESSION['coord_requests_message_type'] = 'success';
        } catch (Throwable $exception) {
            $_SESSION['coord_requests_message'] = $exception->getMessage() !== ''
                ? $exception->getMessage()
                : 'No fue posible registrar la decisión.';
            $_SESSION['coord_requests_message_type'] = 'danger';
            error_log('SICA coordinador detalle decision: ' . $exception->getMessage());
        }
    }

    header('Location: ' . app_url('coordinador/detalle_solicitud.php?id=' . $postId));
    exit;
}

$evento = null;
try {
    $stmt = $pdo->prepare(coord_event_query() . ' WHERE e.id_evento = :id AND e.id_coordinador = :coordinador LIMIT 1');
    $stmt->execute([':id' => $idEvento, ':coordinador' => $coordinadorId]);
    $evento = $stmt->fetch() ?: null;
} catch (Throwable $exception) {
    error_log('SICA coordinador detalle: ' . $exception->getMessage());
}
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>
<?php coord_layout_start('solicitudes'); ?>

<header class="instructor-topbar">
    <div>
        <p class="eyebrow">Detalle</p>
        <h1>Detalle de la solicitud</h1>
        <span>Revisa la información completa antes de registrar tu decisión.</span>
    </div>
    <a class="top-action" href="<?= coord_h(app_url('coordinador/solicitudes.php')) ?>">Volver a solicitudes</a>
</header>

<?php if ($message !== ''): ?>
    <div class="form-message <?= coord_h($messageType) ?>"><?= coord_h($message) ?></div>
<?php endif; ?>

<?php if (!$evento): ?>
    <section class="panel"><div class="empty-state">La solicitud no existe o no está asignada a tu coordinación.</div></section>
<?php else: ?>
    <?php
    $fecha = new DateTime((string)$evento['fecha_evento']);
    $estado = (string)$evento['estado'];
    $steps = coord_detail_steps($evento);
    $instructor = trim((string)$evento['nombre'] . ' ' . (string)$evento['apellido']);
    $instructor = $instructor !== '' ? $instructor : 'Instructor SICA';
    ?>
    <section class="panel detail-request-panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Solicitud <?= coord_h($evento['codigo_evento']) ?></p>
                <h2><?= coord_h($evento['nombre_evento']) ?></h2>
                <span class="panel-subtitle"><?= coord_h($evento['descripcion'] ?: 'Sin descripción registrada') ?></span>
            </div>
            <span class="status-pill <?= coord_h(coord_pill_class($estado)) ?>"><?= coord_h($estado) ?></span>
        </div>

        <div class="stepper detail-stepper" aria-label="Estado de la solicitud">
            <?php foreach ($steps as $step): ?>
                <div class="<?= coord_h(coord_detail_step_class($step, $estado)) ?>">
                    <div class="dot"></div>
                    <div class="label"><?= coord_h($step['label'] . $step['extra']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="detail-grid">
            <div class="detail-box"><span>Auditorio</span><strong><?= coord_h($evento['nombre_auditorio']) ?> / Bloque <?= coord_h($evento['bloque']) ?></strong></div>
            <div class="detail-box"><span>Fecha</span><strong><?= coord_h($fecha->format('d/m/Y')) ?></strong></div>
            <div class="detail-box"><span>Hora</span><strong><?= coord_h(coord_hora12((string)$evento['hora_inicio'])) ?> a <?= coord_h(coord_hora12((string)$evento['hora_fin'])) ?></strong></div>
            <div class="detail-box"><span>Tipo</span><strong><?= coord_h($evento['nombre_tipo']) ?></strong></div>
            <div class="detail-box"><span>Código</span><strong><?= coord_h($evento['codigo_evento']) ?></strong></div>
            <div class="detail-box"><span>Capacidad</span><strong><?= coord_h($evento['capacidad']) ?> personas</strong></div>
            <div class="detail-box"><span>Instructor</span><strong><?= coord_h($instructor) ?></strong></div>
            <div class="detail-box"><span>Correo</span><strong><?= coord_h($evento['correo'] ?? 'No registrado') ?></strong></div>
        </div>

        <article class="detail-observation <?= trim((string)$evento['observacion']) !== '' ? '' : 'muted' ?>">
            <span>Observación de coordinación</span>
            <strong><?= coord_h($evento['observacion'] ?: 'Sin observaciones registradas') ?></strong>
        </article>
    </section>

    <?php if ($estado === 'Pendiente'): ?>
        <section class="panel detail-actions-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Decisión académica</p>
                    <h2>Registrar aprobación o cancelación</h2>
                    <span class="panel-subtitle">La observación es obligatoria si cancelas la reserva. Máximo 220 caracteres.</span>
                </div>
            </div>
            <form class="coord-decision-form coord-decision-form--detail" method="post" action="<?= coord_h(app_url('coordinador/detalle_solicitud.php?id=' . (int)$evento['id_evento'])) ?>" novalidate>
                <input type="hidden" name="csrf_coord_requests" value="<?= coord_h($_SESSION['csrf_coord_requests']) ?>">
                <input type="hidden" name="id_evento" value="<?= coord_h($evento['id_evento']) ?>">
                <label>
                    <span>Observación para el instructor</span>
                    <textarea name="observacion" maxlength="220" minlength="0" placeholder="Motivo o recomendación de coordinación (obligatorio al cancelar)" data-char-counter></textarea>
                    <small class="char-counter" aria-live="polite"></small>
                </label>
                <div class="coord-decision-actions">
                    <button class="primary-btn" type="submit" name="accion" value="aprobar"
                            data-confirm-kicker="Revisión académica"
                            data-confirm-title="Aprobar reserva"
                            data-confirm-message="La reserva quedará aprobada y el administrador podrá notificar al instructor."
                            data-confirm-text="Sí, aprobar">Aprobar reserva</button>
                    <button class="secondary-btn danger-btn" type="submit" name="accion" value="cancelar"
                            data-confirm-kicker="Revisión académica"
                            data-confirm-title="Cancelar reserva"
                            data-confirm-message="La solicitud quedará cancelada. Verifica que la observación explique el motivo."
                            data-confirm-text="Sí, cancelar">Cancelar reserva</button>
                    <a class="top-action" href="<?= coord_h(app_url('coordinador/calendario.php')) ?>">Ver calendario</a>
                </div>
            </form>
        </section>
    <?php elseif ($estado === 'Activo'): ?>
        <section class="panel detail-actions-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Reserva confirmada</p>
                    <h2>Evento aprobado</h2>
                    <span class="panel-subtitle">El instructor ya puede usar el código de pre-registro para sus aprendices.</span>
                </div>
            </div>
            <div class="hero-actions">
                <a class="top-action" href="<?= coord_h(app_url('coordinador/historial.php')) ?>">Ver en historial</a>
                <a class="top-action" href="<?= coord_h(app_url('coordinador/calendario.php')) ?>">Ver calendario</a>
            </div>
        </section>
    <?php elseif ($estado === 'Cancelado'): ?>
        <section class="panel detail-actions-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Reserva cancelada</p>
                    <h2>Solicitud no autorizada</h2>
                    <span class="panel-subtitle">Esta solicitud fue cancelada. El administrador puede notificar al instructor con el motivo registrado.</span>
                </div>
                <span class="status-pill info">Cancelada</span>
            </div>
            <?php if (trim((string)$evento['observacion']) !== ''): ?>
                <article class="detail-observation">
                    <span>Observación registrada</span>
                    <strong><?= coord_h($evento['observacion']) ?></strong>
                </article>
            <?php endif; ?>
            <div class="hero-actions">
                <a class="top-action" href="<?= coord_h(app_url('coordinador/historial.php')) ?>">Ver en historial</a>
                <a class="top-action" href="<?= coord_h(app_url('coordinador/solicitudes.php')) ?>">Volver a solicitudes</a>
            </div>
        </section>
    <?php elseif ($estado === 'Finalizado'): ?>
        <section class="panel detail-actions-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Evento finalizado</p>
                    <h2>Ciclo completo</h2>
                    <span class="panel-subtitle">Este evento ya concluyó y quedó registrado en el historial de coordinación.</span>
                </div>
                <span class="status-pill ok">Finalizado</span>
            </div>
            <div class="hero-actions">
                <a class="top-action" href="<?= coord_h(app_url('coordinador/historial.php')) ?>">Ver en historial</a>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php coord_layout_end(); ?>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
