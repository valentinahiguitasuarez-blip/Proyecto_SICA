<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([3]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/instructor_panel.php';

$pageTitle = 'Disponibilidad - Instructor SICA';
$pageStyles = ['css/instructor.css'];
$user = instructor_user();
$idInstructor = (int)($user['id_documento'] ?? 0);
$message = $_SESSION['instructor_message'] ?? '';
$messageType = $_SESSION['instructor_message_type'] ?? 'success';
$conflictDetails = $_SESSION['instructor_conflict'] ?? null;
unset($_SESSION['instructor_message'], $_SESSION['instructor_message_type'], $_SESSION['instructor_conflict']);

if (empty($_SESSION['csrf_instructor_request'])) {
    $_SESSION['csrf_instructor_request'] = bin2hex(random_bytes(32));
}

function instructor_disponibilidad_hora12(?string $hora): string
{
    $hora = trim((string)$hora);
    if ($hora === '') {
        return '';
    }

    $timestamp = strtotime($hora);
    if ($timestamp === false) {
        return $hora;
    }

    $formatted = date('g:i A', $timestamp);
    return str_replace(['AM', 'PM'], ['a. m.', 'p. m.'], $formatted);
}

$auditorios = instructor_rows($pdo, "SELECT a.* FROM auditorio a INNER JOIN estado e ON e.id_estado = a.id_estado WHERE e.nombre_estado = 'Activo' ORDER BY a.nombre_auditorio");
$tipos = instructor_rows($pdo, 'SELECT * FROM tipo_evento ORDER BY nombre_tipo');
$selectedAuditorio = (int)($_GET['auditorio'] ?? ($auditorios[0]['id_auditorio'] ?? 0));
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) ? (string)$_GET['mes'] : date('Y-m');
$prefillDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fecha'] ?? '')) ? (string)$_GET['fecha'] : '';
$monthLabels = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = (string)($_POST['csrf'] ?? '');
    $idAuditorio = (int)($_POST['id_auditorio'] ?? 0);
    $idTipo = (int)($_POST['id_tipo_evento'] ?? 0);
    $fecha = (string)($_POST['fecha_evento'] ?? '');
    $inicioRaw = trim((string)($_POST['hora_inicio'] ?? ''));
    $finRaw = trim((string)($_POST['hora_fin'] ?? ''));
    $inicioPeriodo = strtoupper(trim((string)($_POST['inicio_periodo'] ?? 'AM')));
    $finPeriodo = strtoupper(trim((string)($_POST['fin_periodo'] ?? 'AM')));
    $titulo = trim((string)($_POST['nombre_evento'] ?? ''));
    $descripcion = trim((string)($_POST['descripcion'] ?? ''));
    $today = date('Y-m-d');
    $error = '';

    $convertTo24Hour = static function (string $time, string $period): ?string {
            if (!preg_match('/^(0?[1-9]|1[0-2]):([0-5][0-9])$/', $time, $matches)) {
                return null;
            }
            $hour = (int)$matches[1];
            $minute = $matches[2];

            if ($period === 'AM') {
                $hour = $hour === 12 ? 0 : $hour;
            } elseif ($period === 'PM') {
                $hour = $hour === 12 ? 12 : $hour + 12;
            } else {
                return null;
            }

            return sprintf('%02d:%s', $hour, $minute);
        };
    if (!hash_equals((string)$_SESSION['csrf_instructor_request'], $csrf)) {
        $error = 'La sesion expiro. Recarga la pagina e intenta de nuevo.';
    } elseif ($idAuditorio <= 0 || $idTipo <= 0 || $titulo === '' || strlen($titulo) > 100) {
        $error = 'Completa el auditorio, tipo de evento y titulo.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha < $today) {
        $error = 'Selecciona una fecha valida desde hoy en adelante.';
    } else {
        $inicio = $convertTo24Hour($inicioRaw, $inicioPeriodo);
        $fin = $convertTo24Hour($finRaw, $finPeriodo);

        if ($inicio === null || $fin === null) {
            $error = 'El formato de hora no es valido. Usa HH:MM y selecciona AM o PM.';
        } elseif ($inicio >= $fin) {
            $error = 'La hora final debe ser mayor que la hora inicial.';
        }
    }

    if ($error === '') {
        $overlaps = instructor_rows(
            $pdo,
            "SELECT e.nombre_evento, e.hora_inicio, e.hora_fin,
                    a.nombre_auditorio, a.bloque,
                    u.nombre, u.apellido
             FROM evento e
             INNER JOIN estado es ON es.id_estado = e.id_estado
             INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
             LEFT JOIN usuario u ON u.id_documento = e.id_solicitante
             WHERE e.id_auditorio = :auditorio
               AND e.fecha_evento = :fecha
               AND es.nombre_estado IN ('Activo', 'Pendiente')
               AND NOT (e.hora_fin <= :inicio OR e.hora_inicio >= :fin)
             ORDER BY
               CASE es.nombre_estado WHEN 'Activo' THEN 1 ELSE 2 END,
               e.hora_inicio ASC
             LIMIT 1",
            [':auditorio' => $idAuditorio, ':fecha' => $fecha, ':inicio' => $inicio . ':00', ':fin' => $fin . ':00']
        );
        if ($overlaps) {
            $eventoOcupado = $overlaps[0];
            $instructorOcupado = trim((string)($eventoOcupado['nombre'] ?? '') . ' ' . (string)($eventoOcupado['apellido'] ?? ''));
            $error = 'No pudimos enviar la solicitud porque esa franja horaria ya esta ocupada.';
            $conflictDetails = [
                'evento' => (string)$eventoOcupado['nombre_evento'],
                'instructor' => $instructorOcupado !== '' ? $instructorOcupado : 'Instructor SICA',
                'auditorio' => (string)$eventoOcupado['nombre_auditorio'] . ' / Bloque ' . (string)$eventoOcupado['bloque'],
                'horario' => instructor_disponibilidad_hora12((string)$eventoOcupado['hora_inicio']) . ' - ' . instructor_disponibilidad_hora12((string)$eventoOcupado['hora_fin']),
            ];
        }
    }

    if ($error !== '') {
        $_SESSION['instructor_message'] = $error;
        $_SESSION['instructor_message_type'] = 'danger';
        if (is_array($conflictDetails)) {
            $_SESSION['instructor_conflict'] = $conflictDetails;
        }
        header('Location: ' . app_url('instructor/disponibilidad.php?auditorio=' . $idAuditorio . '&mes=' . substr($fecha ?: $month, 0, 7)));
        exit;
    }

    $estadoPendiente = instructor_estado_id($pdo, 'Pendiente');
    $codigo = 'SOL' . date('ymdHis') . random_int(10, 99);
    $stmt = $pdo->prepare(
        'INSERT INTO evento (nombre_evento, descripcion, fecha_evento, hora_inicio, hora_fin, codigo_evento, id_auditorio, id_tipo_evento, id_solicitante, id_coordinador, observacion, fecha_aprobacion, id_estado)
         VALUES (:nombre, :descripcion, :fecha, :inicio, :fin, :codigo, :auditorio, :tipo, :solicitante, NULL, NULL, NULL, :estado)'
    );
    $stmt->execute([
        ':nombre' => $titulo,
        ':descripcion' => $descripcion,
        ':fecha' => $fecha,
        ':inicio' => $inicio . ':00',
        ':fin' => $fin . ':00',
        ':codigo' => $codigo,
        ':auditorio' => $idAuditorio,
        ':tipo' => $idTipo,
        ':solicitante' => $idInstructor,
        ':estado' => $estadoPendiente,
    ]);

    $_SESSION['instructor_message'] = 'Solicitud enviada. Administracion la revisara y recibiras la respuesta en SICA.';
    header('Location: ' . app_url('instructor/detalle_solicitud.php?id=' . (int)$pdo->lastInsertId()));
    exit;
}

$start = new DateTimeImmutable($month . '-01');
$prevMonth = $start->modify('-1 month')->format('Y-m');
$nextMonth = $start->modify('+1 month')->format('Y-m');
$daysInMonth = (int)$start->format('t');
$firstWeekday = (int)$start->format('N');
$events = instructor_rows(
    $pdo,
    instructor_event_query() . " WHERE e.id_auditorio = :auditorio AND DATE_FORMAT(e.fecha_evento, \"%Y-%m\") = :mes AND es.nombre_estado IN ('Activo', 'Pendiente') ORDER BY e.fecha_evento, e.hora_inicio",
    [':auditorio' => $selectedAuditorio, ':mes' => $month]
);
$eventsByDay = [];
foreach ($events as $event) {
    $eventsByDay[(string)$event['fecha_evento']][] = $event;
}
// Determine whether the selected date already has an approved (Activo) event.
$dateHasActive = false;
if ($prefillDate !== '' && isset($eventsByDay[$prefillDate])) {
    foreach ($eventsByDay[$prefillDate] as $event) {
        if (trim((string)$event['estado']) === 'Activo') {
            $dateHasActive = true;
            break;
        }
    }
}
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>
<?php instructor_layout_start('disponibilidad'); ?>

<header class="instructor-topbar">
    <div>
        <p class="eyebrow">Disponibilidad</p>
        <h1>Calendario de auditorios</h1>
        <span>Selecciona una fecha libre y envia tu solicitud desde esta pantalla.</span>
    </div>
    <div class="topbar-actions">
        <a class="top-action" href="<?= instructor_h(app_url('instructor/mis_solicitudes.php')) ?>">Mis solicitudes</a>
    </div>
</header>

<?php if ($message !== ''): ?><div class="form-message <?= instructor_h($messageType) ?>"><?= instructor_h($message) ?></div><?php endif; ?>
<?php if (is_array($conflictDetails)): ?>
    <article class="schedule-conflict-card" aria-label="Detalle del horario ocupado">
        <div>
            <p class="eyebrow">Horario ocupado</p>
            <h2><?= instructor_h($conflictDetails['evento'] ?? 'Evento programado') ?></h2>
            <span>Ese cruce corresponde a <?= instructor_h($conflictDetails['instructor'] ?? 'otro instructor') ?>.</span>
        </div>
        <dl>
            <div>
                <dt>Auditorio</dt>
                <dd><?= instructor_h($conflictDetails['auditorio'] ?? 'Auditorio SICA') ?></dd>
            </div>
            <div>
                <dt>Horario</dt>
                <dd><?= instructor_h($conflictDetails['horario'] ?? 'Horario no disponible') ?></dd>
            </div>
        </dl>
        <strong>Elige otra franja horaria para enviar la solicitud.</strong>
    </article>
<?php endif; ?>

<section class="calendar-layout">
    <article class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Auditorio</p>
                <h2><?= instructor_h($monthLabels[(int)$start->format('n')] . ' ' . $start->format('Y')) ?></h2>
            </div>
        </div>
        <form class="calendar-toolbar" method="get">
            <a href="<?= instructor_h(app_url('instructor/disponibilidad.php?auditorio=' . $selectedAuditorio . '&mes=' . $prevMonth)) ?>">Anterior</a>
            <select name="auditorio" onchange="this.form.submit()">
                <?php foreach ($auditorios as $auditorio): ?>
                    <option value="<?= instructor_h($auditorio['id_auditorio']) ?>" <?= (int)$auditorio['id_auditorio'] === $selectedAuditorio ? 'selected' : '' ?>>
                        <?= instructor_h($auditorio['nombre_auditorio']) ?> / Bloque <?= instructor_h($auditorio['bloque']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="mes" value="<?= instructor_h($month) ?>">
            <a href="<?= instructor_h(app_url('instructor/disponibilidad.php?auditorio=' . $selectedAuditorio . '&mes=' . $nextMonth)) ?>">Siguiente</a>
        </form>
        <div class="calendar-grid">
            <?php foreach (['Lun','Mar','Mie','Jue','Vie','Sab','Dom'] as $day): ?><div class="calendar-day-name"><?= instructor_h($day) ?></div><?php endforeach; ?>
            <?php for ($i = 1; $i < $firstWeekday; $i++): ?><div class="calendar-cell muted"></div><?php endfor; ?>
            <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                <?php $date = $start->setDate((int)$start->format('Y'), (int)$start->format('m'), $day)->format('Y-m-d'); ?>
                <?php $events = $eventsByDay[$date] ?? []; 
                      $hasActive = false;
                      $hasPending = false;
                      foreach ($events as $ev) {
                          $estadoEvento = trim((string)$ev['estado']);
                          if ($estadoEvento === 'Activo') {
                              $hasActive = true;
                          }
                          if ($estadoEvento === 'Pendiente') {
                              $hasPending = true;
                          }
                      }
                      $dayStatusClass = empty($events) ? ' available' : ($hasActive ? ' has-active' : ' has-pending');
                      $dayStatusText = empty($events) ? 'Libre' : ($hasActive ? 'Con reservas' : 'Con pendientes');
                ?>
                <div class="calendar-cell<?= instructor_h($dayStatusClass) ?>">
                    <div class="calendar-date">
                        <span><?= instructor_h($day) ?></span>
                        <a class="calendar-request-link" href="<?= instructor_h(app_url('instructor/disponibilidad.php?auditorio=' . $selectedAuditorio . '&mes=' . $month . '&fecha=' . $date)) ?>">Crear</a>
                    </div>
                    <small class="calendar-day-status"><?= instructor_h($dayStatusText) ?></small>
                    <?php foreach ($events as $event): ?>
                        <?php $class = (string)$event['estado'] === 'Pendiente' ? 'pending' : 'busy'; ?>
                        <span class="calendar-event <?= instructor_h($class) ?>" title="<?= instructor_h($event['nombre_evento']) ?>" aria-hidden="true">
                            <strong><?= instructor_h(instructor_disponibilidad_hora12((string)$event['hora_inicio'])) ?></strong>
                            <?= instructor_h($event['nombre_evento']) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endfor; ?>
        </div>
    </article>

    <aside class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Nueva solicitud</p>
                <h2>Separar auditorio</h2>
            </div>
        </div>
        <form class="form-grid" method="post" novalidate>
            <input type="hidden" name="csrf" value="<?= instructor_h($_SESSION['csrf_instructor_request']) ?>">
            <label class="wide"><span>Auditorio</span><select name="id_auditorio" required><?php foreach ($auditorios as $auditorio): ?><option value="<?= instructor_h($auditorio['id_auditorio']) ?>" <?= (int)$auditorio['id_auditorio'] === $selectedAuditorio ? 'selected' : '' ?>><?= instructor_h($auditorio['nombre_auditorio']) ?> - capacidad <?= instructor_h($auditorio['capacidad']) ?></option><?php endforeach; ?></select></label>
            <label><span>Fecha</span><input type="date" name="fecha_evento" min="<?= instructor_h(date('Y-m-d')) ?>" value="<?= instructor_h($prefillDate) ?>" required></label>
            <label><span>Tipo</span><select name="id_tipo_evento" required><?php foreach ($tipos as $tipo): ?><option value="<?= instructor_h($tipo['id_tipo_evento']) ?>"><?= instructor_h($tipo['nombre_tipo']) ?></option><?php endforeach; ?></select></label>
            <label><span>Hora inicio</span>
                <div class="field-row">
                    <input type="text" name="hora_inicio" placeholder="8:00" inputmode="numeric" title="Ingresa la hora en formato HH:MM, por ejemplo 8:00" required>
                    <select name="inicio_periodo" required>
                        <option value="AM">AM</option>
                        <option value="PM">PM</option>
                    </select>
                </div>
            </label>
            <label><span>Hora fin</span>
                <div class="field-row">
                    <input type="text" name="hora_fin" placeholder="10:00" inputmode="numeric" title="Ingresa la hora en formato HH:MM, por ejemplo 10:00" required>
                    <select name="fin_periodo" required>
                        <option value="AM">AM</option>
                        <option value="PM">PM</option>
                    </select>
                </div>
            </label>
            <label class="wide"><span>Titulo del evento</span><input type="text" name="nombre_evento" maxlength="100" required placeholder="Ej: Taller de orientacion"></label>
            <label class="wide"><span>Descripcion</span><textarea name="descripcion" maxlength="150" placeholder="Cuéntanos el objetivo del evento"></textarea></label>
            <button class="primary-btn wide" type="submit">Enviar solicitud</button>
        </form>
    </aside>
</section>

<?php instructor_layout_end(); ?>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
