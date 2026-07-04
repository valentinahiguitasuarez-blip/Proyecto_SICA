<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([3]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/instructor_panel.php';

$pageTitle = 'Participantes - Instructor SICA';
$pageStyles = ['css/instructor.css'];
$idInstructor = (int)(instructor_user()['id_documento'] ?? 0);
$message = $_SESSION['participants_message'] ?? '';
$messageType = $_SESSION['participants_message_type'] ?? 'success';
unset($_SESSION['participants_message'], $_SESSION['participants_message_type']);

if (empty($_SESSION['csrf_participants'])) {
    $_SESSION['csrf_participants'] = bin2hex(random_bytes(32));
}

$eventos = instructor_rows($pdo, instructor_event_query() . ' WHERE e.id_solicitante = :id ORDER BY e.fecha_evento DESC', [':id' => $idInstructor]);
$selectedId = (int)($_GET['evento'] ?? ($eventos[0]['id_evento'] ?? 0));
$evento = null;
foreach ($eventos as $item) {
    if ((int)$item['id_evento'] === $selectedId) {
        $evento = $item;
        break;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add_participant') {
    $csrf = (string)($_POST['csrf'] ?? '');
    $eventId = (int)($_POST['id_evento'] ?? 0);
    $documentoAprendiz = (int)($_POST['id_documento'] ?? 0);

    try {
        if (!hash_equals((string)$_SESSION['csrf_participants'], $csrf)) {
            throw new RuntimeException('La sesion expiro. Intenta de nuevo.');
        }

        $eventoValido = instructor_scalar($pdo, 'SELECT COUNT(*) FROM evento WHERE id_evento = :evento AND id_solicitante = :instructor', [
            ':evento' => $eventId,
            ':instructor' => $idInstructor,
        ]);
        if ($eventoValido === 0) {
            throw new RuntimeException('Selecciona un evento valido.');
        }

        $aprendizValido = instructor_scalar(
            $pdo,
            "SELECT COUNT(*)
             FROM usuario u
             INNER JOIN rol r ON r.id_rol = u.id_rol
             WHERE u.id_documento = :documento
               AND (u.id_rol = 4 OR LOWER(r.nombre_rol) LIKE '%aprendiz%')",
            [':documento' => $documentoAprendiz]
        );
        if ($aprendizValido === 0) {
            throw new RuntimeException('El aprendiz seleccionado no esta disponible.');
        }

        $duplicado = instructor_scalar($pdo, 'SELECT COUNT(*) FROM preregistro WHERE id_evento = :evento AND id_documento = :documento', [
            ':evento' => $eventId,
            ':documento' => $documentoAprendiz,
        ]);
        if ($duplicado > 0) {
            throw new RuntimeException('Ese aprendiz ya esta registrado en el evento.');
        }

        $insert = $pdo->prepare(
            'INSERT INTO preregistro (id_documento, id_evento, fecha_registro, asistencia)
             VALUES (:documento, :evento, CURDATE(), :asistencia)'
        );
        $insert->execute([
            ':documento' => $documentoAprendiz,
            ':evento' => $eventId,
            ':asistencia' => 'Pendiente',
        ]);

        $_SESSION['participants_message'] = 'Aprendiz agregado al evento como participante pendiente.';
        $_SESSION['participants_message_type'] = 'success';
    } catch (Throwable $exception) {
        $_SESSION['participants_message'] = $exception->getMessage();
        $_SESSION['participants_message_type'] = 'danger';
    }

    header('Location: ' . app_url('instructor/participantes.php?evento=' . $eventId));
    exit;
}

$participantes = $evento ? instructor_rows(
    $pdo,
    'SELECT p.id_preregistro, p.fecha_registro, p.asistencia, p.hora, u.id_documento, u.nombre, u.apellido, u.correo, f.id_ficha, pr.nombre_programa
     FROM preregistro p
     INNER JOIN usuario u ON u.id_documento = p.id_documento
     LEFT JOIN ficha f ON f.id_ficha = u.id_ficha
     LEFT JOIN programa pr ON pr.id_programa = f.id_programa
     WHERE p.id_evento = :evento
     ORDER BY p.fecha_registro DESC, u.nombre ASC',
    [':evento' => (int)$evento['id_evento']]
) : [];
$aprendicesDisponibles = $evento ? instructor_rows(
    $pdo,
    "SELECT u.id_documento, u.nombre, u.apellido, u.correo, u.id_ficha, pr.nombre_programa, j.nombre_jornada
     FROM usuario u
     INNER JOIN rol r ON r.id_rol = u.id_rol
     LEFT JOIN estado es ON es.id_estado = u.id_estado
     LEFT JOIN ficha f ON f.id_ficha = u.id_ficha
     LEFT JOIN programa pr ON pr.id_programa = f.id_programa
     LEFT JOIN jornada j ON j.id_jornada = pr.id_jornada
     WHERE (u.id_rol = 4 OR LOWER(r.nombre_rol) LIKE '%aprendiz%')
       AND (es.nombre_estado IS NULL OR es.nombre_estado = 'Activo')
       AND NOT EXISTS (
           SELECT 1
           FROM preregistro px
           WHERE px.id_evento = :evento
             AND px.id_documento = u.id_documento
       )
     ORDER BY u.id_ficha ASC, u.nombre ASC, u.apellido ASC
     LIMIT 30",
    [':evento' => (int)$evento['id_evento']]
) : [];
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>
<?php instructor_layout_start('participantes'); ?>

<header class="instructor-topbar">
    <div>
        <p class="eyebrow">Participantes</p>
        <h1>Aprendices registrados</h1>
        <span>Consulta quienes se pre-registraron y su estado de asistencia.</span>
    </div>
</header>

<?php if ($message !== ''): ?>
    <div class="form-message <?= instructor_h($messageType) ?>"><?= instructor_h($message) ?></div>
<?php endif; ?>

<section class="participants-layout">
    <article class="panel">
        <div class="panel-head">
            <div><p class="eyebrow">Evento</p><h2><?= instructor_h($evento['nombre_evento'] ?? 'Sin evento seleccionado') ?></h2></div>
            <?php if ($evento): ?>
                <div class="hero-actions">
                    <a class="secondary-btn" href="<?= instructor_h(app_url('instructor/exportar_participantes.php?tipo=csv&evento=' . (int)$evento['id_evento'])) ?>">Exportar CSV</a>
                    <a class="danger-btn" href="<?= instructor_h(app_url('instructor/exportar_participantes.php?tipo=pdf&evento=' . (int)$evento['id_evento'])) ?>">Exportar PDF</a>
                </div>
            <?php endif; ?>
        </div>
        <form class="calendar-toolbar" method="get">
            <select name="evento" onchange="this.form.submit()">
                <?php foreach ($eventos as $item): ?>
                    <option value="<?= instructor_h($item['id_evento']) ?>" <?= (int)$item['id_evento'] === $selectedId ? 'selected' : '' ?>><?= instructor_h($item['nombre_evento']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <div class="request-list">
            <?php if (!$participantes): ?><div class="empty-state">Todavia no hay aprendices pre-registrados para este evento.</div><?php endif; ?>
            <?php foreach ($participantes as $index => $participante): ?>
                <?php $full = trim((string)$participante['nombre'] . ' ' . (string)$participante['apellido']); ?>
                <article class="participant-row">
                    <b><?= instructor_h($index + 1) ?></b>
                    <div>
                        <strong><?= instructor_h($full) ?></strong>
                        <small><?= instructor_h($participante['correo']) ?> · Ficha <?= instructor_h($participante['id_ficha'] ?? 'N/A') ?></small>
                    </div>
                    <span class="status-pill <?= (string)$participante['asistencia'] === 'Pendiente' ? 'pending' : 'ok' ?>"><?= instructor_h($participante['asistencia']) ?></span>
                </article>
            <?php endforeach; ?>
        </div>
    </article>

    <aside class="panel">
        <div class="info-list">
            <div><strong><?= instructor_h(count($participantes)) ?></strong><span>Participantes registrados</span></div>
            <div><strong><?= instructor_h($evento['nombre_auditorio'] ?? 'N/A') ?></strong><span>Auditorio</span></div>
            <div><strong><?= instructor_h($evento ? (new DateTime((string)$evento['fecha_evento']))->format('d/m/Y') : 'N/A') ?></strong><span>Fecha del evento</span></div>
        </div>
    </aside>
</section>

<?php if ($evento): ?>
    <section class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Aprendices por ficha</p>
                <h2>Aprendices sugeridos</h2>
                <span class="panel-subtitle">Elige aprendices activos que todavía no han sido añadidos a este evento.</span>
            </div>
        </div>
        <div class="available-learners">
            <?php if (!$aprendicesDisponibles): ?>
                <div class="empty-state">No hay aprendices disponibles para agregar a este evento.</div>
            <?php endif; ?>
            <?php foreach ($aprendicesDisponibles as $aprendiz): ?>
                <?php $full = trim((string)$aprendiz['nombre'] . ' ' . (string)$aprendiz['apellido']); ?>
                <form class="available-learner-row" method="post">
                    <input type="hidden" name="csrf" value="<?= instructor_h($_SESSION['csrf_participants']) ?>">
                    <input type="hidden" name="action" value="add_participant">
                    <input type="hidden" name="id_evento" value="<?= instructor_h($evento['id_evento']) ?>">
                    <input type="hidden" name="id_documento" value="<?= instructor_h($aprendiz['id_documento']) ?>">
                    <b><?= instructor_h(mb_strtoupper(mb_substr((string)$aprendiz['nombre'], 0, 1, 'UTF-8') . mb_substr((string)$aprendiz['apellido'], 0, 1, 'UTF-8'), 'UTF-8')) ?></b>
                    <div>
                        <strong><?= instructor_h($full !== '' ? $full : 'Aprendiz SICA') ?></strong>
                        <small>
                            Ficha <?= instructor_h($aprendiz['id_ficha'] ?? 'N/A') ?>
                            · <?= instructor_h($aprendiz['nombre_programa'] ?? 'Programa no asignado') ?>
                            · <?= instructor_h($aprendiz['correo']) ?>
                        </small>
                    </div>
                    <button class="secondary-btn" type="submit">Agregar</button>
                </form>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php instructor_layout_end(); ?>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
