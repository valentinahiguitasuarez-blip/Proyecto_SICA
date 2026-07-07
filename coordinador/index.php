<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([2]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/coordinador_panel.php';

$pageTitle = 'Coordinador Academico - SICA';
$pageStyles = ['css/admin.css'];

$usuario = coord_user();
$coordinadorId = (int)($usuario['id_documento'] ?? 0);
$coordinadorName = coord_full_name($usuario);
$coordinadorMail = (string)($usuario['correo'] ?? 'coordinador@sica.edu.co');

if (empty($_SESSION['csrf_coord_requests'])) {
    $_SESSION['csrf_coord_requests'] = bin2hex(random_bytes(32));
}

$message = $_SESSION['coord_requests_message'] ?? '';
$messageType = $_SESSION['coord_requests_message_type'] ?? 'success';
unset($_SESSION['coord_requests_message'], $_SESSION['coord_requests_message_type']);

$estadoIds = [
    'Activo' => coord_estado_id($pdo, 'Activo'),
    'Cancelado' => coord_estado_id($pdo, 'Cancelado'),
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = (string)($_POST['csrf_coord_requests'] ?? '');
    $idEvento = (int)($_POST['id_evento'] ?? 0);
    $accion = (string)($_POST['accion'] ?? '');
    $observacion = trim((string)($_POST['observacion'] ?? ''));

    if (!hash_equals((string)$_SESSION['csrf_coord_requests'], $csrf)) {
        $_SESSION['coord_requests_message'] = 'La sesion expiro. Intenta de nuevo.';
        $_SESSION['coord_requests_message_type'] = 'danger';
    } elseif ($coordinadorId <= 0 || $idEvento <= 0 || !in_array($accion, ['aprobar', 'cancelar'], true)) {
        $_SESSION['coord_requests_message'] = 'Selecciona una decision valida.';
        $_SESSION['coord_requests_message_type'] = 'danger';
    } elseif (strlen($observacion) > 180) {
        $_SESSION['coord_requests_message'] = 'La observacion no puede superar 180 caracteres.';
        $_SESSION['coord_requests_message_type'] = 'danger';
    } else {
        try {
            $stmt = $pdo->prepare(
                'SELECT e.id_evento, e.fecha_evento, e.hora_inicio, e.hora_fin, e.id_auditorio, es.nombre_estado
                 FROM evento e
                 INNER JOIN estado es ON es.id_estado = e.id_estado
                 WHERE e.id_evento = :id_evento
                   AND e.id_coordinador = :coordinador
                 LIMIT 1'
            );
            $stmt->execute([':id_evento' => $idEvento, ':coordinador' => $coordinadorId]);
            $evento = $stmt->fetch();

            if (!$evento) {
                throw new RuntimeException('Solicitud no encontrada para tu coordinacion.');
            }
            if ((string)$evento['nombre_estado'] !== 'Pendiente') {
                throw new RuntimeException('Esta solicitud ya tiene una decision registrada.');
            }

            if ($accion === 'aprobar') {
                $overlap = coord_scalar(
                    $pdo,
                    "SELECT COUNT(*)
                     FROM evento e
                     INNER JOIN estado es ON es.id_estado = e.id_estado
                     WHERE e.id_evento <> :id_evento
                       AND e.id_auditorio = :auditorio
                       AND e.fecha_evento = :fecha
                       AND es.nombre_estado = 'Activo'
                       AND NOT (e.hora_fin <= :inicio OR e.hora_inicio >= :fin)",
                    [
                        ':id_evento' => $idEvento,
                        ':auditorio' => (int)$evento['id_auditorio'],
                        ':fecha' => (string)$evento['fecha_evento'],
                        ':inicio' => (string)$evento['hora_inicio'],
                        ':fin' => (string)$evento['hora_fin'],
                    ]
                );

                if ($overlap > 0) {
                    throw new RuntimeException('Ya existe una reserva aprobada para ese auditorio en ese horario.');
                }
            }

            $nuevoEstado = $accion === 'aprobar' ? 'Activo' : 'Cancelado';
            $update = $pdo->prepare(
                'UPDATE evento
                 SET id_estado = :estado,
                     observacion = :observacion,
                     fecha_aprobacion = NOW()
                 WHERE id_evento = :id_evento
                   AND id_coordinador = :coordinador'
            );
            $update->execute([
                ':estado' => $estadoIds[$nuevoEstado],
                ':observacion' => $observacion !== '' ? $observacion : null,
                ':id_evento' => $idEvento,
                ':coordinador' => $coordinadorId,
            ]);

            $_SESSION['coord_requests_message'] = 'Decision registrada. El administrador podra notificar al instructor.';
            $_SESSION['coord_requests_message_type'] = 'success';
        } catch (Throwable $exception) {
            $_SESSION['coord_requests_message'] = $exception->getMessage() !== ''
                ? $exception->getMessage()
                : 'No fue posible registrar la decision.';
            $_SESSION['coord_requests_message_type'] = 'danger';
            error_log('SICA coordinador decision: ' . $exception->getMessage());
        }
    }

    header('Location: ' . app_url('coordinador/index.php'));
    exit;
}

$solicitudes = [];
$counts = ['Pendiente' => 0, 'Activo' => 0, 'Cancelado' => 0, 'Finalizado' => 0];
$monthLabels = [1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'];

try {
    foreach (coord_rows(
        $pdo,
        'SELECT es.nombre_estado, COUNT(*) total
         FROM evento e
         INNER JOIN estado es ON es.id_estado = e.id_estado
         WHERE e.id_coordinador = :coordinador
         GROUP BY es.nombre_estado',
        [':coordinador' => $coordinadorId]
    ) as $row) {
        $counts[(string)$row['nombre_estado']] = (int)$row['total'];
    }

    $solicitudes = coord_rows(
        $pdo,
        'SELECT e.id_evento, e.nombre_evento, e.descripcion, e.fecha_evento, e.hora_inicio, e.hora_fin,
                e.codigo_evento, e.observacion, e.fecha_aprobacion, es.nombre_estado AS estado,
                a.nombre_auditorio, a.bloque, a.capacidad, te.nombre_tipo,
                u.nombre, u.apellido, u.correo
         FROM evento e
         INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
         INNER JOIN estado es ON es.id_estado = e.id_estado
         INNER JOIN tipo_evento te ON te.id_tipo_evento = e.id_tipo_evento
         LEFT JOIN usuario u ON u.id_documento = e.id_solicitante
         WHERE e.id_coordinador = :coordinador
         ORDER BY
            CASE es.nombre_estado
                WHEN \'Pendiente\' THEN 1
                WHEN \'Activo\' THEN 2
                WHEN \'Cancelado\' THEN 3
                ELSE 4
            END,
            e.fecha_evento ASC,
            e.hora_inicio ASC
         LIMIT 100',
        [':coordinador' => $coordinadorId]
    );
} catch (Throwable $exception) {
    error_log('SICA coordinador solicitudes: ' . $exception->getMessage());
}
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>
<?php coord_layout_start('solicitudes'); ?>

        <header class="admin-topbar">
            <div>
                <p class="admin-eyebrow">Coordinacion</p>
                <h1>Solicitudes de auditorio</h1>
                <span>Aprueba o cancela las reservas que el administrador te remitio para revision.</span>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <div class="admin-alert <?= coord_h($messageType) ?>">
                <?= coord_h($message) ?>
            </div>
        <?php endif; ?>

        <section class="admin-metrics reservation-metrics" aria-label="Resumen de solicitudes">
            <article class="admin-metric">
                <span>Pendientes</span>
                <strong><?= coord_h($counts['Pendiente'] ?? 0) ?></strong>
                <small>Por decidir</small>
            </article>
            <article class="admin-metric">
                <span>Aprobadas</span>
                <strong><?= coord_h($counts['Activo'] ?? 0) ?></strong>
                <small>Reservas activas</small>
            </article>
            <article class="admin-metric">
                <span>Canceladas</span>
                <strong><?= coord_h($counts['Cancelado'] ?? 0) ?></strong>
                <small>No autorizadas</small>
            </article>
            <article class="admin-metric">
                <span>Finalizadas</span>
                <strong><?= coord_h($counts['Finalizado'] ?? 0) ?></strong>
                <small>Cerradas</small>
            </article>
        </section>

        <section class="admin-panel reservations-panel">
            <div class="admin-panel-head">
                <div>
                    <p class="admin-eyebrow">Revision academica</p>
                    <h2>Solicitudes asignadas</h2>
                </div>
            </div>

            <div class="admin-reservation-list">
                <?php if (!$solicitudes): ?>
                    <article class="admin-empty-state">
                        <strong>No tienes solicitudes asignadas.</strong>
                        <span>Cuando el administrador te remita una reserva, aparecera aqui.</span>
                    </article>
                <?php endif; ?>

                <?php foreach ($solicitudes as $solicitud): ?>
                    <?php
                    $fecha = new DateTime((string)$solicitud['fecha_evento']);
                    $estado = (string)$solicitud['estado'];
                    $statusClass = coord_status_class($estado);
                    $instructor = trim((string)$solicitud['nombre'] . ' ' . (string)$solicitud['apellido']);
                    $instructor = $instructor !== '' ? $instructor : 'Instructor SICA';
                    ?>
                    <article class="admin-reservation-card <?= coord_h($statusClass) ?>">
                        <time>
                            <strong><?= coord_h($fecha->format('d')) ?></strong>
                            <span><?= coord_h($monthLabels[(int)$fecha->format('n')]) ?></span>
                        </time>

                        <div class="admin-reservation-main">
                            <div class="admin-reservation-title">
                                <div>
                                    <span class="admin-reservation-type"><?= coord_h($solicitud['nombre_tipo']) ?></span>
                                    <h3><?= coord_h($solicitud['nombre_evento']) ?></h3>
                                </div>
                                <em><?= coord_h($estado) ?></em>
                            </div>
                            <p><?= coord_h($solicitud['descripcion'] ?? 'Solicitud de reserva de auditorio.') ?></p>
                            <div class="admin-reservation-meta">
                                <span><?= coord_h(coord_hora12((string)$solicitud['hora_inicio']) . ' - ' . coord_hora12((string)$solicitud['hora_fin'])) ?></span>
                                <span><?= coord_h($solicitud['nombre_auditorio'] . ' / Bloque ' . $solicitud['bloque']) ?></span>
                                <span>Capacidad <?= coord_h($solicitud['capacidad']) ?></span>
                                <span>Codigo <?= coord_h($solicitud['codigo_evento']) ?></span>
                            </div>
                            <div class="admin-requester">
                                <strong><?= coord_h($instructor) ?></strong>
                                <small><?= coord_h($solicitud['correo'] ?? 'Correo no registrado') ?></small>
                            </div>
                            <?php if (!empty($solicitud['observacion'])): ?>
                                <div class="admin-observation">
                                    <?= coord_h($solicitud['observacion']) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <form class="admin-reservation-actions" method="post" action="<?= coord_h(app_url('coordinador/index.php')) ?>">
                            <input type="hidden" name="csrf_coord_requests" value="<?= coord_h($_SESSION['csrf_coord_requests']) ?>">
                            <input type="hidden" name="id_evento" value="<?= coord_h($solicitud['id_evento']) ?>">
                            <?php if ($estado === 'Pendiente'): ?>
                                <label>
                                    <span>Observacion para el instructor</span>
                                    <textarea name="observacion" maxlength="180" placeholder="Motivo o recomendacion de coordinacion"></textarea>
                                </label>
                                <div>
                                    <button type="submit" name="accion" value="aprobar">Aprobar reserva</button>
                                    <button class="danger" type="submit" name="accion" value="cancelar">Cancelar reserva</button>
                                </div>
                            <?php else: ?>
                                <small class="admin-flow-note">Decision registrada. El administrador comunica la respuesta al instructor.</small>
                            <?php endif; ?>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
<?php coord_layout_end(); ?>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
