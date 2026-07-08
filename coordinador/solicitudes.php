<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([2]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/coordinador_panel.php';

$pageTitle = 'Solicitudes pendientes - Coordinador SICA';
$pageStyles = ['css/instructor.css', 'css/admin.css'];

$usuario = coord_user();
$coordinadorId = (int)($usuario['id_documento'] ?? 0);

if (empty($_SESSION['csrf_coord_requests'])) {
    $_SESSION['csrf_coord_requests'] = bin2hex(random_bytes(32));
}

$message = $_SESSION['coord_requests_message'] ?? '';
$messageType = $_SESSION['coord_requests_message_type'] ?? 'success';
unset($_SESSION['coord_requests_message'], $_SESSION['coord_requests_message_type']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = (string)($_POST['csrf_coord_requests'] ?? '');
    $idEvento = (int)($_POST['id_evento'] ?? 0);
    $accion = (string)($_POST['accion'] ?? '');
    $observacion = trim((string)($_POST['observacion'] ?? ''));
    $redirect = trim((string)($_POST['redirect'] ?? ''));

    if (!hash_equals((string)$_SESSION['csrf_coord_requests'], $csrf)) {
        $_SESSION['coord_requests_message'] = 'La sesión expiró. Intenta de nuevo.';
        $_SESSION['coord_requests_message_type'] = 'danger';
    } else {
        try {
            coord_register_decision($pdo, $coordinadorId, $idEvento, $accion, $observacion);
            $_SESSION['coord_requests_message'] = 'Decisión registrada. El administrador podrá notificar al instructor.';
            $_SESSION['coord_requests_message_type'] = 'success';
        } catch (Throwable $exception) {
            $_SESSION['coord_requests_message'] = $exception->getMessage() !== ''
                ? $exception->getMessage()
                : 'No fue posible registrar la decisión.';
            $_SESSION['coord_requests_message_type'] = 'danger';
            error_log('SICA coordinador solicitudes: ' . $exception->getMessage());
        }
    }

    $destino = $redirect !== '' && str_starts_with($redirect, app_url('coordinador/'))
        ? $redirect
        : app_url('coordinador/solicitudes.php');
    header('Location: ' . $destino);
    exit;
}

$pendientes = [];
$monthLabels = [1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'];

try {
    $pendientes = coord_rows(
        $pdo,
        coord_event_query() . "
         WHERE e.id_coordinador = :coordinador AND es.nombre_estado = 'Pendiente'
         ORDER BY e.fecha_evento ASC, e.hora_inicio ASC",
        [':coordinador' => $coordinadorId]
    );
} catch (Throwable $exception) {
    error_log('SICA coordinador solicitudes listado: ' . $exception->getMessage());
}

$pendientesCount = count($pendientes);
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>
<?php coord_layout_start('solicitudes'); ?>

<header class="instructor-topbar">
    <div>
        <p class="eyebrow">Solicitudes</p>
        <h1>Revisión académica de auditorios</h1>
        <span>Aprueba o cancela las reservas pendientes asignadas por administración.</span>
    </div>
    <div class="topbar-actions">
        <a class="top-action" href="<?= coord_h(app_url('coordinador/index.php')) ?>">Dashboard</a>
        <a class="top-action" href="<?= coord_h(app_url('coordinador/calendario.php')) ?>">Calendario</a>
    </div>
</header>

<?php if ($message !== ''): ?>
    <div class="form-message <?= coord_h($messageType) ?>"><?= coord_h($message) ?></div>
<?php endif; ?>

<section class="panel">
    <div class="panel-head">
        <div>
            <p class="eyebrow">Pendientes por decidir</p>
            <h2><?= coord_h($pendientesCount) ?> solicitud(es) en espera</h2>
            <span class="panel-subtitle">Cada decisión queda registrada en el historial y habilita la notificación al instructor.</span>
        </div>
        <span class="status-pill pending"><?= coord_h($pendientesCount) ?> pendientes</span>
    </div>

    <div class="request-list">
        <?php if (!$pendientes): ?>
            <div class="empty-state">No tienes solicitudes pendientes. Cuando administración te remita una reserva, aparecerá aquí.</div>
        <?php endif; ?>

        <?php foreach ($pendientes as $solicitud): ?>
            <?php
            $fecha = new DateTime((string)$solicitud['fecha_evento']);
            $instructor = trim((string)$solicitud['nombre'] . ' ' . (string)$solicitud['apellido']);
            $instructor = $instructor !== '' ? $instructor : 'Instructor SICA';
            $steps = coord_detail_steps($solicitud);
            ?>
            <article class="coord-pending-card">
                <div class="request-row pending">
                    <div class="request-date"><?= coord_h($fecha->format('d')) ?> <?= coord_h($monthLabels[(int)$fecha->format('n')]) ?></div>
                    <div class="request-content">
                        <div class="request-title">
                            <h3><?= coord_h($solicitud['nombre_evento']) ?></h3>
                            <span class="badge-new">Pendiente</span>
                        </div>
                        <small><?= coord_h($solicitud['nombre_auditorio']) ?> / <?= coord_h($solicitud['nombre_tipo']) ?> · <?= coord_h(coord_hora12((string)$solicitud['hora_inicio'])) ?> a <?= coord_h(coord_hora12((string)$solicitud['hora_fin'])) ?></small>
                        <small>Instructor: <?= coord_h($instructor) ?> · Código <?= coord_h($solicitud['codigo_evento']) ?></small>
                        <div class="stepper" aria-hidden="true">
                            <?php foreach ($steps as $step): ?>
                                <div class="<?= coord_h(coord_detail_step_class($step, 'Pendiente')) ?>"><div class="dot"></div><div class="label"><?= coord_h($step['label']) ?></div></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <a class="status-pill pending" href="<?= coord_h(app_url('coordinador/detalle_solicitud.php?id=' . (int)$solicitud['id_evento'])) ?>">Ver detalle</a>
                </div>

                <form class="coord-decision-form" method="post" action="<?= coord_h(app_url('coordinador/solicitudes.php')) ?>" novalidate>
                    <input type="hidden" name="csrf_coord_requests" value="<?= coord_h($_SESSION['csrf_coord_requests']) ?>">
                    <input type="hidden" name="id_evento" value="<?= coord_h($solicitud['id_evento']) ?>">
                    <label>
                        <span>Observación para el instructor <em class="field-hint">(obligatoria al cancelar)</em></span>
                        <textarea name="observacion" maxlength="220" placeholder="Motivo o recomendación de coordinación" data-char-counter></textarea>
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
                        <a class="top-action" href="<?= coord_h(app_url('coordinador/detalle_solicitud.php?id=' . (int)$solicitud['id_evento'])) ?>">Abrir detalle</a>
                    </div>
                </form>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<?php coord_layout_end(); ?>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
