<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([2]);
require_once __DIR__ . '/../config/conexion.php';

$pageTitle = 'Coordinador Academico - SICA';
$pageStyles = ['css/admin.css'];

$usuario = $_SESSION['usuario'] ?? [];
$coordinadorId = (int)($usuario['id_documento'] ?? 0);
$coordinadorName = trim((string)($usuario['nombre'] ?? 'Coordinador'));
$coordinadorMail = (string)($usuario['correo'] ?? 'coordinador@sica.edu.co');

function coord_h(string|int|null $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function coord_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function coord_scalar(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function coord_estado_id(PDO $pdo, string $estado): int
{
    $stmt = $pdo->prepare('SELECT id_estado FROM estado WHERE nombre_estado = :estado LIMIT 1');
    $stmt->execute([':estado' => $estado]);
    return (int)$stmt->fetchColumn();
}

function coord_status_class(string $estado): string
{
    return match ($estado) {
        'Activo' => 'approved',
        'Pendiente' => 'pending',
        'Cancelado' => 'rejected',
        'Finalizado' => 'finished',
        default => 'neutral',
    };
}

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
$auditorios = [];
$usuariosPorRol = [];
$counts = ['Pendiente' => 0, 'Activo' => 0, 'Cancelado' => 0, 'Finalizado' => 0];
$monthLabels = [1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'];
$monthFullLabels = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];

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

    $auditorios = coord_rows(
        $pdo,
        'SELECT a.nombre_auditorio, a.bloque, a.capacidad, es.nombre_estado AS estado,
                COUNT(e.id_evento) eventos_asignados
         FROM auditorio a
         INNER JOIN estado es ON es.id_estado = a.id_estado
         LEFT JOIN evento e ON e.id_auditorio = a.id_auditorio
         GROUP BY a.id_auditorio, a.nombre_auditorio, a.bloque, a.capacidad, es.nombre_estado
         ORDER BY a.nombre_auditorio ASC'
    );

    $usuariosPorRol = coord_rows(
        $pdo,
        'SELECT r.nombre_rol, COUNT(*) total
         FROM usuario u
         INNER JOIN rol r ON r.id_rol = u.id_rol
         GROUP BY r.id_rol, r.nombre_rol
         ORDER BY r.nombre_rol ASC'
    );
} catch (Throwable $exception) {
    error_log('SICA coordinador solicitudes: ' . $exception->getMessage());
}

$totalSolicitudes = array_sum($counts);
$pendientes = (int)($counts['Pendiente'] ?? 0);
$aprobadas = (int)($counts['Activo'] ?? 0);
$rechazadas = (int)($counts['Cancelado'] ?? 0);
$finalizadas = (int)($counts['Finalizado'] ?? 0);
$hoy = new DateTimeImmutable('now');
$eventosCalendario = array_slice($solicitudes, 0, 6);
$eventosAprobados = array_values(array_filter(
    $solicitudes,
    static fn(array $solicitud): bool => (string)$solicitud['estado'] === 'Activo'
));
$eventosAprobados = array_slice($eventosAprobados, 0, 3);
$capacidadTotal = array_sum(array_map(static fn(array $auditorio): int => (int)$auditorio['capacidad'], $auditorios));
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>

<main class="admin-dashboard">
    <aside class="admin-sidebar" aria-label="Menu del coordinador">
        <a class="admin-brand" href="<?= coord_h(app_url('coordinador/index.php')) ?>">
            <span>
                <strong>SICA</strong>
                <small>Coordinacion de auditorios</small>
            </span>
        </a>

        <section class="admin-profile" aria-label="Coordinador activo">
            <div class="admin-avatar">CO</div>
            <div>
                <strong><?= coord_h($coordinadorName) ?></strong>
                <small><?= coord_h($coordinadorMail) ?></small>
                <span>En linea</span>
            </div>
        </section>

        <nav class="admin-nav">
            <a class="active" href="<?= coord_h(app_url('coordinador/index.php')) ?>"><span>PC</span>Panel Coordinador</a>
            <details class="coord-nav-group" open>
                <summary><span>SA</span><strong>Solicitudes de Auditorio</strong></summary>
                <a href="#solicitudes"><span></span>Todas las solicitudes</a>
                <a href="#pendientes"><span></span>Pendientes por aprobar</a>
                <a href="#aprobados"><span></span>Aprobadas</a>
                <a href="#rechazadas"><span></span>Rechazadas</a>
                <a href="#historial"><span></span>Historial</a>
            </details>
            <a href="#calendario"><span>CA</span>Calendario institucional</a>
            <a href="#aprobados"><span>EA</span>Eventos aprobados</a>
            <a href="#auditorios"><span>AU</span>Auditorios</a>
            <a href="#reportes"><span>RP</span>Reportes e Indicadores</a>
            <a href="#usuarios"><span>UR</span>Usuarios y Roles</a>
            <a href="#configuracion"><span>CF</span>Configuracion</a>
            <a class="nav-logout-link" href="<?= coord_h(app_url('login/logout.php')) ?>"><span>SL</span>Cerrar sesion</a>
        </nav>

        <section class="coord-help-card" aria-label="Ayuda del coordinador">
            <strong>Ruta de revision</strong>
            <small>Revisa disponibilidad, decide la solicitud y deja una observacion clara para el instructor.</small>
        </section>
    </aside>

    <section class="admin-main">
        <header class="admin-topbar">
            <div>
                <p class="admin-eyebrow">Coordinacion</p>
                <h1>Bienvenida, <?= coord_h($coordinadorName) ?></h1>
                <span>Revisa, aprueba y gestiona las solicitudes de auditorio remitidas por administracion.</span>
            </div>
            <div class="coord-top-date">
                <span><?= coord_h($hoy->format('d')) ?></span>
                <strong><?= coord_h($monthFullLabels[(int)$hoy->format('n')]) ?> <?= coord_h($hoy->format('Y')) ?></strong>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <div class="admin-alert <?= coord_h($messageType) ?>">
                <?= coord_h($message) ?>
            </div>
        <?php endif; ?>

        <section class="admin-metrics coord-metrics" aria-label="Resumen de solicitudes">
            <article class="admin-metric">
                <span>Total solicitudes</span>
                <strong><?= coord_h($totalSolicitudes) ?></strong>
                <small>Todas las solicitudes</small>
            </article>
            <article class="admin-metric">
                <span>Pendientes por aprobar</span>
                <strong><?= coord_h($pendientes) ?></strong>
                <small>Esperan tu revision</small>
            </article>
            <article class="admin-metric">
                <span>Aprobadas</span>
                <strong><?= coord_h($aprobadas) ?></strong>
                <small>Solicitudes autorizadas</small>
            </article>
            <article class="admin-metric">
                <span>Rechazadas</span>
                <strong><?= coord_h($rechazadas) ?></strong>
                <small>No autorizadas</small>
            </article>
        </section>

        <section class="coord-workspace">
            <div class="coord-main-column">
                <section class="admin-panel reservations-panel coord-requests-panel" id="solicitudes">
                    <div class="admin-panel-head">
                        <div>
                            <p class="admin-eyebrow">Solicitudes pendientes por aprobar</p>
                            <h2>Revision academica de auditorios</h2>
                        </div>
                        <a href="#pendientes">Ver pendientes</a>
                    </div>

                    <div class="admin-reservation-list" id="pendientes">
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
                                <span><?= coord_h(substr((string)$solicitud['hora_inicio'], 0, 5) . ' - ' . substr((string)$solicitud['hora_fin'], 0, 5)) ?></span>
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
                                    <button type="submit" name="accion" value="aprobar"
                                            data-confirm-kicker="Revision academica"
                                            data-confirm-title="Aprobar reserva"
                                            data-confirm-message="La reserva quedara aprobada y el administrador podra notificar al instructor."
                                            data-confirm-text="Si, aprobar">Aprobar reserva</button>
                                    <button class="danger" type="submit" name="accion" value="cancelar"
                                            data-confirm-kicker="Revision academica"
                                            data-confirm-title="Cancelar reserva"
                                            data-confirm-message="La solicitud quedara cancelada. Verifica que la observacion explique el motivo."
                                            data-confirm-text="Si, cancelar">Cancelar reserva</button>
                                </div>
                            <?php else: ?>
                                <small class="admin-flow-note">Decision registrada. El administrador comunica la respuesta al instructor.</small>
                            <?php endif; ?>
                        </form>
                    </article>
                <?php endforeach; ?>
                    </div>
                </section>

                <section class="coord-bottom-grid">
                    <article class="admin-panel" id="aprobados">
                        <div class="admin-panel-head">
                            <div>
                                <p class="admin-eyebrow">Eventos aprobados</p>
                                <h2>Proximos auditorios confirmados</h2>
                            </div>
                        </div>
                        <div class="coord-approved-list">
                            <?php if (!$eventosAprobados): ?>
                                <article class="admin-empty-state">
                                    <strong>No hay eventos aprobados.</strong>
                                    <span>Cuando apruebes solicitudes apareceran en este resumen.</span>
                                </article>
                            <?php endif; ?>
                            <?php foreach ($eventosAprobados as $evento): ?>
                                <?php $fechaAprobada = new DateTime((string)$evento['fecha_evento']); ?>
                                <div>
                                    <time><strong><?= coord_h($fechaAprobada->format('d')) ?></strong><span><?= coord_h($monthLabels[(int)$fechaAprobada->format('n')]) ?></span></time>
                                    <span>
                                        <strong><?= coord_h($evento['nombre_evento']) ?></strong>
                                        <small><?= coord_h($evento['nombre_auditorio']) ?> - <?= coord_h(substr((string)$evento['hora_inicio'], 0, 5)) ?> a <?= coord_h(substr((string)$evento['hora_fin'], 0, 5)) ?></small>
                                    </span>
                                    <em>Confirmado</em>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </article>

                    <article class="admin-panel">
                        <div class="admin-panel-head">
                            <div>
                                <p class="admin-eyebrow">Indicadores</p>
                                <h2>Uso de auditorios</h2>
                            </div>
                        </div>
                        <div class="coord-progress-list">
                            <div><span>Aprobadas</span><strong><?= coord_h($aprobadas) ?></strong><i style="width: <?= coord_h($totalSolicitudes > 0 ? (int)round(($aprobadas / $totalSolicitudes) * 100) : 0) ?>%"></i></div>
                            <div><span>Pendientes</span><strong><?= coord_h($pendientes) ?></strong><i style="width: <?= coord_h($totalSolicitudes > 0 ? (int)round(($pendientes / $totalSolicitudes) * 100) : 0) ?>%"></i></div>
                            <div><span>Rechazadas</span><strong><?= coord_h($rechazadas) ?></strong><i style="width: <?= coord_h($totalSolicitudes > 0 ? (int)round(($rechazadas / $totalSolicitudes) * 100) : 0) ?>%"></i></div>
                        </div>
                    </article>
                </section>
            </div>

            <aside class="coord-side-column" aria-label="Panel de apoyo del coordinador">
                <section class="admin-panel coord-calendar" id="calendario">
                    <div class="admin-panel-head">
                        <div>
                            <p class="admin-eyebrow">Calendario</p>
                            <h2>Uso de auditorios</h2>
                        </div>
                    </div>
                    <div class="coord-calendar-head">
                        <strong><?= coord_h($monthFullLabels[(int)$hoy->format('n')]) ?> <?= coord_h($hoy->format('Y')) ?></strong>
                        <span>Hoy <?= coord_h($hoy->format('d')) ?></span>
                    </div>
                    <div class="coord-calendar-list">
                        <?php if (!$eventosCalendario): ?>
                            <span>No hay eventos para mostrar.</span>
                        <?php endif; ?>
                        <?php foreach ($eventosCalendario as $evento): ?>
                            <?php $fechaEvento = new DateTime((string)$evento['fecha_evento']); ?>
                            <div class="<?= coord_h(coord_status_class((string)$evento['estado'])) ?>">
                                <time><?= coord_h($fechaEvento->format('d')) ?> <?= coord_h($monthLabels[(int)$fechaEvento->format('n')]) ?></time>
                                <strong><?= coord_h($evento['nombre_evento']) ?></strong>
                                <small><?= coord_h($evento['estado']) ?> - <?= coord_h($evento['nombre_auditorio']) ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="admin-panel coord-status-panel" id="rechazadas">
                    <div class="admin-panel-head">
                        <div>
                            <p class="admin-eyebrow">Estado</p>
                            <h2>Solicitudes por estado</h2>
                        </div>
                    </div>
                    <div class="coord-ring"><strong><?= coord_h($totalSolicitudes) ?></strong><span>Total</span></div>
                    <div class="coord-status-list">
                        <div><span class="pending"></span>Pendientes<strong><?= coord_h($pendientes) ?></strong></div>
                        <div><span class="approved"></span>Aprobadas<strong><?= coord_h($aprobadas) ?></strong></div>
                        <div><span class="rejected"></span>Rechazadas<strong><?= coord_h($rechazadas) ?></strong></div>
                        <div><span class="finished"></span>Finalizadas<strong><?= coord_h($finalizadas) ?></strong></div>
                    </div>
                </section>

                <section class="admin-panel coord-quick-panel">
                    <div class="admin-panel-head">
                        <div>
                            <p class="admin-eyebrow">Acciones</p>
                            <h2>Acciones rapidas</h2>
                        </div>
                    </div>
                    <div>
                        <a href="#solicitudes">Revisar solicitudes</a>
                        <a href="#calendario">Ver calendario</a>
                        <a href="#aprobados">Eventos aprobados</a>
                    </div>
                </section>
            </aside>
        </section>

        <section class="coord-extra-grid" id="historial" aria-label="Paneles adicionales del coordinador">
            <article class="admin-panel" id="auditorios">
                <div class="admin-panel-head">
                    <div>
                        <p class="admin-eyebrow">Auditorios</p>
                        <h2>Espacios disponibles para revision</h2>
                    </div>
                </div>
                <div class="coord-auditorium-list">
                    <?php foreach ($auditorios as $auditorio): ?>
                        <div>
                            <span><?= coord_h($auditorio['nombre_auditorio']) ?> / Bloque <?= coord_h($auditorio['bloque']) ?></span>
                            <strong><?= coord_h($auditorio['capacidad']) ?> cupos</strong>
                            <small><?= coord_h($auditorio['estado']) ?> - <?= coord_h($auditorio['eventos_asignados']) ?> eventos</small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="admin-panel" id="reportes">
                <div class="admin-panel-head">
                    <div>
                        <p class="admin-eyebrow">Reportes</p>
                        <h2>Indicadores para decision</h2>
                    </div>
                </div>
                <div class="coord-report-grid">
                    <div><span>Capacidad total</span><strong><?= coord_h($capacidadTotal) ?></strong><small>Cupos disponibles</small></div>
                    <div><span>Solicitudes cerradas</span><strong><?= coord_h($aprobadas + $rechazadas + $finalizadas) ?></strong><small>Con decision registrada</small></div>
                    <div><span>Pendientes</span><strong><?= coord_h($pendientes) ?></strong><small>Requieren revision</small></div>
                </div>
            </article>

            <article class="admin-panel" id="usuarios">
                <div class="admin-panel-head">
                    <div>
                        <p class="admin-eyebrow">Usuarios y roles</p>
                        <h2>Resumen de actores SICA</h2>
                    </div>
                </div>
                <div class="coord-role-list">
                    <?php foreach ($usuariosPorRol as $rol): ?>
                        <div>
                            <span><?= coord_h($rol['nombre_rol']) ?></span>
                            <strong><?= coord_h($rol['total']) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="admin-panel" id="configuracion">
                <div class="admin-panel-head">
                    <div>
                        <p class="admin-eyebrow">Configuracion</p>
                        <h2>Preferencias del coordinador</h2>
                    </div>
                </div>
                <div class="coord-config-card">
                    <span>Sesion activa</span>
                    <strong><?= coord_h($coordinadorName) ?></strong>
                    <small><?= coord_h($coordinadorMail) ?></small>
                    <p>Las decisiones aprobadas o canceladas quedan registradas para que administracion notifique al instructor.</p>
                </div>
            </article>
        </section>
    </section>
</main>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
