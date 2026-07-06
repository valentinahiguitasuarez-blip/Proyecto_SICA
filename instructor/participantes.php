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

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    // common fields
    $csrf = (string)($_POST['csrf'] ?? '');
    $eventId = (int)($_POST['id_evento'] ?? 0);

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

        if ($action === 'add_participant') {
            $documentoAprendiz = (int)($_POST['id_documento'] ?? 0);

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
        } elseif ($action === 'remove_participant') {
            $idPrereg = (int)($_POST['id_preregistro'] ?? 0);
            if ($idPrereg <= 0) {
                throw new RuntimeException('Selecciona un participante valido para eliminar.');
            }

            $del = $pdo->prepare('DELETE FROM preregistro WHERE id_preregistro = :id AND id_evento = :evento');
            $del->execute([':id' => $idPrereg, ':evento' => $eventId]);
            if ($del->rowCount() === 0) {
                throw new RuntimeException('No se encontro el preregistro o no pertenece a este evento.');
            }

            $_SESSION['participants_message'] = 'Participante eliminado correctamente.';
            $_SESSION['participants_message_type'] = 'success';
        }
        elseif ($action === 'add_ficha') {
            $idFicha = (int)($_POST['id_ficha'] ?? 0);
            if ($idFicha <= 0) {
                throw new RuntimeException('Ficha inválida.');
            }

            // select learners in that ficha who match role and are active and not already preregistered
            $sel = $pdo->prepare("SELECT u.id_documento
                FROM usuario u
                INNER JOIN rol r ON r.id_rol = u.id_rol
                LEFT JOIN estado es ON es.id_estado = u.id_estado
                WHERE u.id_ficha = :idf
                  AND (u.id_rol = 4 OR LOWER(r.nombre_rol) LIKE '%aprendiz%')
                  AND (es.nombre_estado IS NULL OR es.nombre_estado = 'Activo')
                  AND NOT EXISTS (
                      SELECT 1 FROM preregistro px WHERE px.id_evento = :evento AND px.id_documento = u.id_documento
                  )");
            $sel->execute([':idf' => $idFicha, ':evento' => $eventId]);
            $rows = $sel->fetchAll(PDO::FETCH_COLUMN);

            if (!$rows) {
                $_SESSION['participants_message'] = 'No hay aprendices disponibles en esa ficha para agregar.';
                $_SESSION['participants_message_type'] = 'warning';
            } else {
                $pdo->beginTransaction();
                try {
                    $ins = $pdo->prepare('INSERT INTO preregistro (id_documento, id_evento, fecha_registro, asistencia) VALUES (:documento, :evento, CURDATE(), :asistencia)');
                    $count = 0;
                    foreach ($rows as $doc) {
                        $ins->execute([':documento' => $doc, ':evento' => $eventId, ':asistencia' => 'Pendiente']);
                        $count += $ins->rowCount();
                    }
                    $pdo->commit();
                    $_SESSION['participants_message'] = "Se agregaron {$count} aprendices de la ficha al evento.";
                    $_SESSION['participants_message_type'] = 'success';
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            }
        }
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
// search term for available learners
$buscar = trim((string)($_GET['buscar'] ?? ''));
$vista = (string)($_GET['vista'] ?? 'registrados');
$baseParams = 'evento=' . (int)$selectedId;
if ($buscar !== '') {
    $baseParams .= '&buscar=' . rawurlencode($buscar);
}

$aprendicesDisponibles = [];
if ($evento) {
        $baseSql = "SELECT u.id_documento, u.nombre, u.apellido, u.correo, u.id_ficha, pr.nombre_programa, j.nombre_jornada
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
             )";

        $params = [':evento' => (int)$evento['id_evento']];
        if ($buscar !== '') {
                $baseSql .= " AND (u.nombre LIKE :buscar OR u.apellido LIKE :buscar OR CAST(u.id_documento AS CHAR) LIKE :buscar OR CAST(f.id_ficha AS CHAR) LIKE :buscar)";
                $params[':buscar'] = '%' . $buscar . '%';
        }

        $baseSql .= "\n     ORDER BY u.id_ficha ASC, u.nombre ASC, u.apellido ASC\n     LIMIT 30";
        $aprendicesDisponibles = instructor_rows($pdo, $baseSql, $params);
}
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
        <!-- Tabs -->
        <div class="panel-head">
            <div>
                <p class="eyebrow">Vista</p>
                <h2>Opciones</h2>
            </div>
            <div class="topbar-actions">
                <a class="<?= $vista === 'registrados' ? 'primary-btn' : 'secondary-btn' ?>" href="<?= instructor_h(app_url('instructor/participantes.php?' . $baseParams . '&vista=registrados')) ?>">Ver registrados</a>
                <a class="<?= $vista === 'agregar' ? 'primary-btn' : 'secondary-btn' ?>" href="<?= instructor_h(app_url('instructor/participantes.php?' . $baseParams . '&vista=agregar' . ($buscar !== '' ? '&buscar=' . rawurlencode($buscar) : ''))) ?>">Agregar aprendices</a>
            </div>
        </div>

        <?php if ($vista === 'registrados'): ?>
        <div class="request-list">
            <?php if (!$participantes): ?><div class="empty-state">Todavia no hay aprendices pre-registrados para este evento.</div><?php endif; ?>
            <?php
                // Attendance progress and ordering
                $totalRegs = count($participantes);
                $pendientes = count(array_filter($participantes, function($p){ return (string)$p['asistencia'] === 'Pendiente'; }));
                $confirmadas = $totalRegs - $pendientes;

                // Partition by hora: arrived (hora not null) and not arrived (hora null or pendiente)
                $arrived = array_filter($participantes, function($p){ return !empty($p['hora']); });
                $notArrived = array_filter($participantes, function($p){ return empty($p['hora']) || (string)$p['asistencia'] === 'Pendiente'; });

                // sort arrived by hora DESC (most recent first)
                usort($arrived, function($a, $b){
                    $ta = strtotime((string)($a['hora'] ?? '00:00:00')) ?: 0;
                    $tb = strtotime((string)($b['hora'] ?? '00:00:00')) ?: 0;
                    return $tb <=> $ta;
                });
            ?>

            <!-- Progress bar -->
            <div class="panel">
                <div class="panel-head">
                    <div>
                        <p class="eyebrow">Asistencia</p>
                        <h2>Resumen de asistencia</h2>
                    </div>
                </div>
                <?php $pct = $totalRegs > 0 ? (int)round(($confirmadas / $totalRegs) * 100) : 0; ?>
                <div>
                    <strong><?= instructor_h($confirmadas) ?> de <?= instructor_h($totalRegs) ?> asistieron (<?= instructor_h($pct) ?>%)</strong>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?= instructor_h((string)$pct) ?>%;"></div>
                    </div>
                </div>
            </div>

            <?php if (empty($arrived) && empty($notArrived)): ?><div class="empty-state">Todavia no hay aprendices pre-registrados para este evento.</div><?php endif; ?>

            <?php $counter = 0; ?>
            <?php foreach ($arrived as $participante): $counter++; $full = trim((string)$participante['nombre'] . ' ' . (string)$participante['apellido']); ?>
                <article class="participant-row">
                    <b><?= instructor_h($counter) ?></b>
                    <div>
                        <strong><?= instructor_h($full) ?></strong>
                        <small><?= instructor_h($participante['correo']) ?> · Ficha <?= instructor_h($participante['id_ficha'] ?? 'N/A') ?> · Llegada <?= instructor_h(substr((string)$participante['hora'],0,5)) ?></small>
                    </div>
                    <span class="status-pill <?= (string)$participante['asistencia'] === 'Pendiente' ? 'pending' : 'ok' ?>"><?= instructor_h($participante['asistencia']) ?></span>
                    <form method="post" onsubmit="return confirm('Eliminar participante?');">
                        <input type="hidden" name="csrf" value="<?= instructor_h($_SESSION['csrf_participants']) ?>">
                        <input type="hidden" name="action" value="remove_participant">
                        <input type="hidden" name="id_preregistro" value="<?= instructor_h($participante['id_preregistro']) ?>">
                        <input type="hidden" name="id_evento" value="<?= instructor_h($evento['id_evento']) ?>">
                        <button class="danger-btn" type="submit">Eliminar</button>
                    </form>
                </article>
            <?php endforeach; ?>

            <?php if (!empty($notArrived)): ?>
                <div class="panel-head">
                    <div>
                        <p class="eyebrow">Aún no han llegado</p>
                        <h2>Participantes pendientes</h2>
                    </div>
                </div>
                <?php foreach ($notArrived as $participante): $counter++; $full = trim((string)$participante['nombre'] . ' ' . (string)$participante['apellido']); ?>
                    <article class="participant-row">
                        <b><?= instructor_h($counter) ?></b>
                        <div>
                            <strong><?= instructor_h($full) ?></strong>
                            <small><?= instructor_h($participante['correo']) ?> · Ficha <?= instructor_h($participante['id_ficha'] ?? 'N/A') ?></small>
                        </div>
                        <span class="status-pill <?= (string)$participante['asistencia'] === 'Pendiente' ? 'pending' : 'ok' ?>"><?= instructor_h($participante['asistencia']) ?></span>
                        <form method="post" onsubmit="return confirm('Eliminar participante?');">
                            <input type="hidden" name="csrf" value="<?= instructor_h($_SESSION['csrf_participants']) ?>">
                            <input type="hidden" name="action" value="remove_participant">
                            <input type="hidden" name="id_preregistro" value="<?= instructor_h($participante['id_preregistro']) ?>">
                            <input type="hidden" name="id_evento" value="<?= instructor_h($evento['id_evento']) ?>">
                            <button class="danger-btn" type="submit">Eliminar</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
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
        <?php if ($vista === 'agregar'): ?>
            <div>
                <form method="get" class="calendar-toolbar">
                    <input type="hidden" name="evento" value="<?= instructor_h($evento['id_evento']) ?>">
                    <input type="hidden" name="vista" value="agregar">
                    <input type="text" name="buscar" placeholder="Buscar por nombre, apellido, documento o ficha" value="<?= instructor_h($buscar) ?>">
                    <button class="secondary-btn" type="submit">Buscar</button>
                </form>
            </div>
            <div class="available-learners">
                <?php if (!$aprendicesDisponibles): ?>
                    <div class="empty-state">No hay aprendices disponibles para agregar a este evento.</div>
                <?php else: ?>
                    <?php
                        // group by id_ficha
                        $groups = [];
                        foreach ($aprendicesDisponibles as $a) {
                            $key = $a['id_ficha'] ?? 'sin_ficha';
                            if (!isset($groups[$key])) $groups[$key] = [];
                            $groups[$key][] = $a;
                        }
                    ?>
                    <?php foreach ($groups as $ficha => $members): ?>
                        <?php $first = $members[0]; $fichaLabel = $ficha === 'sin_ficha' ? 'Sin ficha' : $ficha; ?>
                        <section class="panel">
                            <div class="panel-head">
                                <div>
                                    <p class="eyebrow">Ficha</p>
                                    <h2>Ficha <?= instructor_h($fichaLabel) ?></h2>
                                    <span class="panel-subtitle"><?= instructor_h($first['nombre_programa'] ?? 'Programa no asignado') ?></span>
                                </div>
                                <div class="topbar-actions">
                                    <form method="post" onsubmit="return confirm('Agregar toda la ficha al evento?');">
                                        <input type="hidden" name="csrf" value="<?= instructor_h($_SESSION['csrf_participants']) ?>">
                                        <input type="hidden" name="action" value="add_ficha">
                                        <input type="hidden" name="id_evento" value="<?= instructor_h($evento['id_evento']) ?>">
                                        <input type="hidden" name="id_ficha" value="<?= instructor_h($ficha) ?>">
                                        <button class="secondary-btn" type="submit">Agregar toda la ficha</button>
                                    </form>
                                </div>
                            </div>
                            <div class="available-learners">
                                <?php foreach ($members as $aprendiz): $full = trim((string)$aprendiz['nombre'] . ' ' . (string)$aprendiz['apellido']); ?>
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
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php instructor_layout_end(); ?>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
