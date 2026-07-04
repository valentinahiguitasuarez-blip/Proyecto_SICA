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
unset($_SESSION['instructor_message'], $_SESSION['instructor_message_type']);

if (empty($_SESSION['csrf_instructor_request'])) {
    $_SESSION['csrf_instructor_request'] = bin2hex(random_bytes(32));
}

$auditorios = instructor_rows($pdo, "SELECT a.* FROM auditorio a INNER JOIN estado e ON e.id_estado = a.id_estado WHERE e.nombre_estado = 'Activo' ORDER BY a.nombre_auditorio");
$tipos = instructor_rows($pdo, 'SELECT * FROM tipo_evento ORDER BY nombre_tipo');
$selectedAuditorio = (int)($_GET['auditorio'] ?? ($auditorios[0]['id_auditorio'] ?? 0));
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) ? (string)$_GET['mes'] : date('Y-m');
$prefillDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fecha'] ?? '')) ? (string)$_GET['fecha'] : '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = (string)($_POST['csrf'] ?? '');
    $idAuditorio = (int)($_POST['id_auditorio'] ?? 0);
    $idTipo = (int)($_POST['id_tipo_evento'] ?? 0);
    $fecha = (string)($_POST['fecha_evento'] ?? '');
    $inicio = (string)($_POST['hora_inicio'] ?? '');
    $fin = (string)($_POST['hora_fin'] ?? '');
    $titulo = trim((string)($_POST['nombre_evento'] ?? ''));
    $descripcion = trim((string)($_POST['descripcion'] ?? ''));
    $today = date('Y-m-d');

    $error = '';
    if (!hash_equals((string)$_SESSION['csrf_instructor_request'], $csrf)) {
        $error = 'La sesion expiro. Recarga la pagina e intenta de nuevo.';
    } elseif ($idAuditorio <= 0 || $idTipo <= 0 || $titulo === '' || strlen($titulo) > 100) {
        $error = 'Completa el auditorio, tipo de evento y titulo.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha < $today) {
        $error = 'Selecciona una fecha valida desde hoy en adelante.';
    } elseif (!preg_match('/^\d{2}:\d{2}$/', $inicio) || !preg_match('/^\d{2}:\d{2}$/', $fin) || $inicio >= $fin) {
        $error = 'La hora final debe ser mayor que la hora inicial.';
    } elseif (strlen($descripcion) > 150) {
        $error = 'La descripcion no puede superar 150 caracteres.';
    }

    if ($error === '') {
        $overlap = instructor_scalar(
            $pdo,
            "SELECT COUNT(*)
             FROM evento e
             INNER JOIN estado es ON es.id_estado = e.id_estado
             WHERE e.id_auditorio = :auditorio
               AND e.fecha_evento = :fecha
               AND es.nombre_estado IN ('Activo', 'Pendiente')
               AND NOT (e.hora_fin <= :inicio OR e.hora_inicio >= :fin)",
            [':auditorio' => $idAuditorio, ':fecha' => $fecha, ':inicio' => $inicio . ':00', ':fin' => $fin . ':00']
        );
        if ($overlap > 0) {
            $error = 'Ese horario ya tiene un evento o una solicitud pendiente.';
        }
    }

    if ($error !== '') {
        $_SESSION['instructor_message'] = $error;
        $_SESSION['instructor_message_type'] = 'danger';
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
    instructor_event_query() . ' WHERE e.id_auditorio = :auditorio AND DATE_FORMAT(e.fecha_evento, "%Y-%m") = :mes ORDER BY e.fecha_evento, e.hora_inicio',
    [':auditorio' => $selectedAuditorio, ':mes' => $month]
);
$eventsByDay = [];
foreach ($events as $event) {
    $eventsByDay[(string)$event['fecha_evento']][] = $event;
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

<section class="calendar-layout">
    <article class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Auditorio</p>
                <h2><?= instructor_h($start->format('F Y')) ?></h2>
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
                <div class="calendar-cell">
                    <div class="calendar-date">
                        <span><?= instructor_h($day) ?></span>
                        <a class="calendar-request-link" href="<?= instructor_h(app_url('instructor/disponibilidad.php?auditorio=' . $selectedAuditorio . '&mes=' . $month . '&fecha=' . $date)) ?>">Crear</a>
                    </div>
                    <?php foreach ($eventsByDay[$date] ?? [] as $event): ?>
                        <?php $class = (string)$event['estado'] === 'Pendiente' ? 'pending' : ((string)$event['estado'] === 'Activo' ? '' : 'busy'); ?>
                        <a class="calendar-event <?= instructor_h($class) ?>" href="<?= instructor_h(app_url('instructor/detalle_solicitud.php?id=' . (int)$event['id_evento'])) ?>">
                            <?= instructor_h(substr((string)$event['hora_inicio'], 0, 5)) ?> <?= instructor_h($event['nombre_evento']) ?>
                        </a>
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
        <form class="form-grid" method="post">
            <input type="hidden" name="csrf" value="<?= instructor_h($_SESSION['csrf_instructor_request']) ?>">
            <label class="wide"><span>Auditorio</span><select name="id_auditorio" required><?php foreach ($auditorios as $auditorio): ?><option value="<?= instructor_h($auditorio['id_auditorio']) ?>" <?= (int)$auditorio['id_auditorio'] === $selectedAuditorio ? 'selected' : '' ?>><?= instructor_h($auditorio['nombre_auditorio']) ?> - capacidad <?= instructor_h($auditorio['capacidad']) ?></option><?php endforeach; ?></select></label>
            <label><span>Fecha</span><input type="date" name="fecha_evento" min="<?= instructor_h(date('Y-m-d')) ?>" value="<?= instructor_h($prefillDate) ?>" required></label>
            <label><span>Tipo</span><select name="id_tipo_evento" required><?php foreach ($tipos as $tipo): ?><option value="<?= instructor_h($tipo['id_tipo_evento']) ?>"><?= instructor_h($tipo['nombre_tipo']) ?></option><?php endforeach; ?></select></label>
            <label><span>Hora inicio</span><input type="time" name="hora_inicio" value="08:00" required></label>
            <label><span>Hora fin</span><input type="time" name="hora_fin" value="10:00" required></label>
            <label class="wide"><span>Titulo del evento</span><input type="text" name="nombre_evento" maxlength="100" required placeholder="Ej: Taller de orientacion"></label>
            <label class="wide"><span>Descripcion</span><textarea name="descripcion" maxlength="150" placeholder="Cuéntanos el objetivo del evento"></textarea></label>
            <button class="primary-btn wide" type="submit">Enviar solicitud</button>
        </form>
    </aside>
</section>

<?php instructor_layout_end(); ?>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
