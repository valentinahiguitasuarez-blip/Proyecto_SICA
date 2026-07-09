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

function instructor_participantes_evento_operable(array $evento): bool
{
    if ((string)($evento['estado'] ?? '') !== 'Activo') {
        return false;
    }

    try {
        return new DateTime((string)$evento['fecha_evento']) >= new DateTime('today');
    } catch (Throwable) {
        return false;
    }
}

$eventos = instructor_rows($pdo, instructor_event_query() . ' WHERE e.id_solicitante = :id ORDER BY e.fecha_evento DESC', [':id' => $idInstructor]);
$eventoRaw = trim((string)($_GET['evento'] ?? ''));
$selectedId = $eventoRaw !== '' && ctype_digit($eventoRaw) ? (int)$eventoRaw : (int)($eventos[0]['id_evento'] ?? 0);
$evento = null;
foreach ($eventos as $item) {
    if ((int)$item['id_evento'] === $selectedId) {
        $evento = $item;
        break;
    }
}
$eventoOperable = $evento ? instructor_participantes_evento_operable($evento) : false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    // common fields
    $csrf = (string)($_POST['csrf'] ?? '');
    $eventId = (int)($_POST['id_evento'] ?? 0);
    $redirectEventId = $eventId > 0 ? $eventId : $selectedId;

    try {
        if (!hash_equals((string)$_SESSION['csrf_participants'], $csrf)) {
            throw new RuntimeException('La sesión expiró. Intenta de nuevo.');
        }
        if (!in_array($action, ['add_participant', 'remove_participant', 'add_ficha'], true)) {
            throw new RuntimeException('Acción no válida.');
        }

        $stmtEvento = $pdo->prepare(instructor_event_query() . ' WHERE e.id_evento = :evento AND e.id_solicitante = :instructor LIMIT 1');
        $stmtEvento->execute([
            ':evento' => $eventId,
            ':instructor' => $idInstructor,
        ]);
        $eventoOperacion = $stmtEvento->fetch();
        if (!$eventoOperacion) {
            throw new RuntimeException('Selecciona un evento válido.');
        }
        if (!instructor_participantes_evento_operable($eventoOperacion)) {
            throw new RuntimeException('Solo puedes modificar participantes de eventos activos y vigentes.');
        }

        if ($action === 'add_participant') {
            $documentoRaw = trim((string)($_POST['id_documento'] ?? ''));
            if (!ctype_digit($documentoRaw) || strlen($documentoRaw) > 20) {
                throw new RuntimeException('Selecciona un aprendiz válido.');
            }
            $documentoAprendiz = (int)$documentoRaw;

            $totalActual = instructor_scalar($pdo, 'SELECT COUNT(*) FROM preregistro WHERE id_evento = :evento', [':evento' => $eventId]);
            $capacidadEvento = max(0, (int)($eventoOperacion['capacidad'] ?? 0));
            if ($capacidadEvento > 0 && $totalActual >= $capacidadEvento) {
                throw new RuntimeException('El evento ya alcanzó la capacidad máxima del auditorio.');
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
                throw new RuntimeException('El aprendiz seleccionado no está disponible.');
            }

            $duplicado = instructor_scalar($pdo, 'SELECT COUNT(*) FROM preregistro WHERE id_evento = :evento AND id_documento = :documento', [
                ':evento' => $eventId,
                ':documento' => $documentoAprendiz,
            ]);
            if ($duplicado > 0) {
                throw new RuntimeException('Ese aprendiz ya está registrado en el evento.');
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
                throw new RuntimeException('Selecciona un participante válido para eliminar.');
            }

            $del = $pdo->prepare("DELETE FROM preregistro WHERE id_preregistro = :id AND id_evento = :evento AND asistencia = 'Pendiente' AND hora IS NULL");
            $del->execute([':id' => $idPrereg, ':evento' => $eventId]);
            if ($del->rowCount() === 0) {
                throw new RuntimeException('No se encontró el pre-registro o ya tiene ingreso registrado.');
            }

            $_SESSION['participants_message'] = 'Participante eliminado correctamente.';
            $_SESSION['participants_message_type'] = 'success';
        }
        elseif ($action === 'add_ficha') {
            $idFichaRaw = trim((string)($_POST['id_ficha'] ?? ''));
            if (!ctype_digit($idFichaRaw) || strlen($idFichaRaw) > 12) {
                throw new RuntimeException('Ficha inválida.');
            }
            $idFicha = (int)$idFichaRaw;

            $totalActual = instructor_scalar($pdo, 'SELECT COUNT(*) FROM preregistro WHERE id_evento = :evento', [':evento' => $eventId]);
            $capacidadEvento = max(0, (int)($eventoOperacion['capacidad'] ?? 0));
            $cuposRestantes = $capacidadEvento > 0 ? max(0, $capacidadEvento - $totalActual) : 9999;
            if ($cuposRestantes <= 0) {
                throw new RuntimeException('El evento ya alcanzó la capacidad máxima del auditorio.');
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
                $rows = array_slice($rows, 0, $cuposRestantes);
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

    header('Location: ' . app_url('instructor/participantes.php?evento=' . $redirectEventId));
    exit;
}

$participantes = $evento ? instructor_rows(
    $pdo,
    'SELECT p.id_preregistro, p.fecha_registro, p.asistencia, p.hora, u.id_documento, u.nombre, u.apellido, u.correo, f.id_ficha, pr.nombre_programa, j.nombre_jornada
     FROM preregistro p
     INNER JOIN usuario u ON u.id_documento = p.id_documento
     LEFT JOIN ficha f ON f.id_ficha = u.id_ficha
     LEFT JOIN programa pr ON pr.id_programa = f.id_programa
     LEFT JOIN jornada j ON j.id_jornada = pr.id_jornada
     WHERE p.id_evento = :evento
     ORDER BY p.fecha_registro DESC, u.nombre ASC',
    [':evento' => (int)$evento['id_evento']]
) : [];
// search term for available learners
$buscar = trim((string)($_GET['buscar'] ?? ''));
if (mb_strlen($buscar, 'UTF-8') > 60 || preg_match('/[\x00-\x1F]/', $buscar)) {
    $buscar = '';
}
$vista = (string)($_GET['vista'] ?? 'registrados');
if (!in_array($vista, ['registrados', 'agregar'], true)) {
    $vista = 'registrados';
}
$baseParams = 'evento=' . (int)$selectedId;
if ($buscar !== '') {
    $baseParams .= '&buscar=' . rawurlencode($buscar);
}

$aprendicesDisponibles = [];
$fichasDisponibles = [];
$fichasBusqueda = [];
$aprendicesPorFicha = [];
if ($evento) {
        $fichasSql = 'SELECT f.id_ficha, p.nombre_programa, j.nombre_jornada,
                (
                    SELECT COUNT(*)
                    FROM usuario u
                    INNER JOIN rol r ON r.id_rol = u.id_rol
                    LEFT JOIN estado esu ON esu.id_estado = u.id_estado
                    WHERE u.id_ficha = f.id_ficha
                      AND (u.id_rol = 4 OR LOWER(r.nombre_rol) LIKE \'%aprendiz%\')
                      AND (esu.nombre_estado IS NULL OR esu.nombre_estado = \'Activo\')
                ) AS total_aprendices,
                (
                    SELECT COUNT(*)
                    FROM usuario u
                    INNER JOIN rol r ON r.id_rol = u.id_rol
                    LEFT JOIN estado esu ON esu.id_estado = u.id_estado
                    WHERE u.id_ficha = f.id_ficha
                      AND (u.id_rol = 4 OR LOWER(r.nombre_rol) LIKE \'%aprendiz%\')
                      AND (esu.nombre_estado IS NULL OR esu.nombre_estado = \'Activo\')
                      AND NOT EXISTS (
                          SELECT 1 FROM preregistro px
                          WHERE px.id_evento = :evento_disponibles
                            AND px.id_documento = u.id_documento
                      )
                ) AS disponibles,
                (
                    SELECT COUNT(*)
                    FROM usuario u
                    INNER JOIN rol r ON r.id_rol = u.id_rol
                    LEFT JOIN estado esu ON esu.id_estado = u.id_estado
                    WHERE u.id_ficha = f.id_ficha
                      AND (u.id_rol = 4 OR LOWER(r.nombre_rol) LIKE \'%aprendiz%\')
                      AND (esu.nombre_estado IS NULL OR esu.nombre_estado = \'Activo\')
                      AND EXISTS (
                          SELECT 1 FROM preregistro px
                          WHERE px.id_evento = :evento_registrados
                            AND px.id_documento = u.id_documento
                      )
                ) AS registrados
         FROM ficha f
         INNER JOIN programa p ON p.id_programa = f.id_programa
         INNER JOIN jornada j ON j.id_jornada = p.id_jornada
         INNER JOIN estado es ON es.id_estado = f.id_estado
         WHERE es.nombre_estado = \'Activo\'';
        $fichasParams = [
            ':evento_disponibles' => (int)$evento['id_evento'],
            ':evento_registrados' => (int)$evento['id_evento'],
        ];
        $fichasSql .= ' ORDER BY f.id_ficha ASC';
        $fichasDisponibles = instructor_rows($pdo, $fichasSql, $fichasParams);

        $aprendicesFichaSql = "SELECT u.id_documento, u.nombre, u.apellido, u.correo, u.id_ficha,
                pr.nombre_programa, j.nombre_jornada,
                CASE WHEN px.id_preregistro IS NULL THEN 0 ELSE 1 END AS ya_registrado
         FROM usuario u
         INNER JOIN rol r ON r.id_rol = u.id_rol
         LEFT JOIN estado es ON es.id_estado = u.id_estado
         LEFT JOIN ficha f ON f.id_ficha = u.id_ficha
         LEFT JOIN programa pr ON pr.id_programa = f.id_programa
         LEFT JOIN jornada j ON j.id_jornada = pr.id_jornada
         LEFT JOIN preregistro px ON px.id_evento = :evento_ficha AND px.id_documento = u.id_documento
         WHERE (u.id_rol = 4 OR LOWER(r.nombre_rol) LIKE '%aprendiz%')
             AND (es.nombre_estado IS NULL OR es.nombre_estado = 'Activo')";
        $aprendicesFichaParams = [':evento_ficha' => (int)$evento['id_evento']];
        $aprendicesFichaSql .= "\n     ORDER BY u.id_ficha ASC, u.nombre ASC, u.apellido ASC";
        foreach (instructor_rows($pdo, $aprendicesFichaSql, $aprendicesFichaParams) as $aprendizFicha) {
            $aprendicesPorFicha[(string)($aprendizFicha['id_ficha'] ?? 'Sin ficha')][] = $aprendizFicha;
        }

        if ($buscar !== '') {
            $buscarLower = mb_strtolower($buscar, 'UTF-8');
            foreach ($fichasDisponibles as $ficha) {
                $idFicha = (string)$ficha['id_ficha'];
                $textoFicha = mb_strtolower($idFicha . ' ' . (string)($ficha['nombre_programa'] ?? '') . ' ' . (string)($ficha['nombre_jornada'] ?? ''), 'UTF-8');
                if (mb_strpos($textoFicha, $buscarLower, 0, 'UTF-8') !== false) {
                    $fichasBusqueda[] = $ficha;
                }
            }
        }

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
                $buscarParam = '%' . $buscar . '%';
                $baseSql .= " AND (u.nombre LIKE :buscar1 OR u.apellido LIKE :buscar2 OR CAST(u.id_documento AS CHAR) LIKE :buscar3 OR CAST(f.id_ficha AS CHAR) LIKE :buscar4)";
                $params[':buscar1'] = $buscarParam;
                $params[':buscar2'] = $buscarParam;
                $params[':buscar3'] = $buscarParam;
                $params[':buscar4'] = $buscarParam;
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
        <span>Consulta pre-registros, ingresos registrados y aprendices disponibles para este evento.</span>
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
                    <a class="secondary-btn" href="<?= instructor_h(app_url('instructor/exportar_participantes.php?tipo=excel&evento=' . (int)$evento['id_evento'])) ?>">Exportar Excel</a>
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
                $totalRegs = count($participantes);
                $ingresos = count(array_filter($participantes, function($p){
                    return in_array((string)$p['asistencia'], ['Asistió', 'Asistio'], true) || !empty($p['hora']);
                }));
                $pendientes = max(0, $totalRegs - $ingresos);

                $arrived = array_filter($participantes, function($p){
                    return in_array((string)$p['asistencia'], ['Asistió', 'Asistio'], true) || !empty($p['hora']);
                });
                $notArrived = array_filter($participantes, function($p){
                    return !in_array((string)$p['asistencia'], ['Asistió', 'Asistio'], true) && empty($p['hora']);
                });

                // sort arrived by hora DESC (most recent first)
                usort($arrived, function($a, $b){
                    $ta = strtotime((string)($a['hora'] ?? '00:00:00')) ?: 0;
                    $tb = strtotime((string)($b['hora'] ?? '00:00:00')) ?: 0;
                    return $tb <=> $ta;
                });
            ?>

            <div class="participant-summary-grid" aria-label="Resumen de participantes">
                <?php $pct = $totalRegs > 0 ? (int)round(($ingresos / $totalRegs) * 100) : 0; ?>
                <article>
                    <span>Pre-registros</span>
                    <strong><?= instructor_h($totalRegs) ?></strong>
                    <small>Total de aprendices vinculados</small>
                </article>
                <article class="ok">
                    <span>Ingresos registrados</span>
                    <strong><?= instructor_h($ingresos) ?></strong>
                    <small><?= instructor_h($pct) ?>% del listado</small>
                </article>
                <article class="pending">
                    <span>Pendientes</span>
                    <strong><?= instructor_h($pendientes) ?></strong>
                    <small>Sin hora de ingreso registrada</small>
                </article>
                <progress class="attendance-progress" value="<?= instructor_h($pct) ?>" max="100"><?= instructor_h($pct) ?>%</progress>
            </div>

            <?php if (empty($arrived) && empty($notArrived)): ?><div class="empty-state">Todavia no hay aprendices pre-registrados para este evento.</div><?php endif; ?>

            <?php if (!empty($arrived)): ?>
                <div class="panel-head">
                    <div>
                        <p class="eyebrow">Ingresos</p>
                        <h2>Ingresos registrados</h2>
                    </div>
                </div>
            <?php endif; ?>

            <?php $counter = 0; ?>
            <?php foreach ($arrived as $participante): $counter++; $full = trim((string)$participante['nombre'] . ' ' . (string)$participante['apellido']); ?>
                <article class="participant-row arrived">
                    <?php $avatarLabel = trim((string)mb_substr((string)$participante['nombre'], 0, 1, 'UTF-8') . (string)mb_substr((string)$participante['apellido'], 0, 1, 'UTF-8')); ?>
                    <?php $avatarText = $avatarLabel !== '' ? mb_strtoupper($avatarLabel, 'UTF-8') : '?'; ?>
                    <b><?= instructor_h($avatarText) ?></b>
                    <div>
                        <strong><?= instructor_h($full) ?></strong>
                        <small>Doc. <?= instructor_h($participante['id_documento']) ?> · <?= instructor_h($participante['correo']) ?> · Ficha <?= instructor_h($participante['id_ficha'] ?? 'N/A') ?><?= !empty($participante['nombre_jornada']) ? ' · ' . instructor_h($participante['nombre_jornada']) : '' ?> · Ingreso <?= instructor_h(instructor_hora12((string)$participante['hora'])) ?></small>
                    </div>
                    <span class="status-pill ok"><?= instructor_h($participante['asistencia']) ?></span>
                    <?php if ($eventoOperable && (string)$participante['asistencia'] === 'Pendiente' && empty($participante['hora'])): ?>
                        <form method="post"
                              data-confirm-kicker="Participantes"
                              data-confirm-title="Eliminar participante"
                              data-confirm-message="Este aprendiz saldra del listado del evento. Puedes volver a registrarlo si es necesario."
                              data-confirm-text="Si, eliminar">
                            <input type="hidden" name="csrf" value="<?= instructor_h($_SESSION['csrf_participants']) ?>">
                            <input type="hidden" name="action" value="remove_participant">
                            <input type="hidden" name="id_preregistro" value="<?= instructor_h($participante['id_preregistro']) ?>">
                            <input type="hidden" name="id_evento" value="<?= instructor_h($evento['id_evento']) ?>">
                            <button class="danger-btn" type="submit">Eliminar</button>
                        </form>
                    <?php else: ?>
                        <small class="panel-subtitle">Bloqueado</small>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>

            <?php if (!empty($notArrived)): ?>
                <div class="panel-head">
                    <div>
                        <p class="eyebrow">Pendientes</p>
                        <h2>Pre-registros pendientes</h2>
                    </div>
                </div>
                <?php foreach ($notArrived as $participante): $counter++; $full = trim((string)$participante['nombre'] . ' ' . (string)$participante['apellido']); ?>
                    <article class="participant-row pending">
                        <?php $avatarLabel = trim((string)mb_substr((string)$participante['nombre'], 0, 1, 'UTF-8') . (string)mb_substr((string)$participante['apellido'], 0, 1, 'UTF-8')); ?>
                        <?php $avatarText = $avatarLabel !== '' ? mb_strtoupper($avatarLabel, 'UTF-8') : '?'; ?>
                        <b><?= instructor_h($avatarText) ?></b>
                        <div>
                            <strong><?= instructor_h($full) ?></strong>
                            <small>Doc. <?= instructor_h($participante['id_documento']) ?> · <?= instructor_h($participante['correo']) ?> · Ficha <?= instructor_h($participante['id_ficha'] ?? 'N/A') ?><?= !empty($participante['nombre_jornada']) ? ' · ' . instructor_h($participante['nombre_jornada']) : '' ?></small>
                        </div>
                        <span class="status-pill pending"><?= instructor_h($participante['asistencia']) ?></span>
                        <?php if ($eventoOperable): ?>
                            <form method="post"
                                  data-confirm-kicker="Participantes"
                                  data-confirm-title="Eliminar participante"
                                  data-confirm-message="Este aprendiz saldra del listado del evento. Puedes volver a registrarlo si es necesario."
                                  data-confirm-text="Si, eliminar">
                                <input type="hidden" name="csrf" value="<?= instructor_h($_SESSION['csrf_participants']) ?>">
                                <input type="hidden" name="action" value="remove_participant">
                                <input type="hidden" name="id_preregistro" value="<?= instructor_h($participante['id_preregistro']) ?>">
                                <input type="hidden" name="id_evento" value="<?= instructor_h($evento['id_evento']) ?>">
                                <button class="danger-btn" type="submit">Eliminar</button>
                            </form>
                        <?php else: ?>
                            <small class="panel-subtitle">Bloqueado</small>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($vista === 'agregar'): ?>
            <div class="add-participants-panel">
                <?php if (!$evento): ?>
                    <div class="empty-state">Selecciona un evento para agregar aprendices.</div>
                <?php elseif (!$eventoOperable): ?>
                    <div class="empty-state">Solo puedes agregar aprendices a eventos activos y vigentes.</div>
                <?php else: ?>
                    <div class="panel-head">
                        <div>
                            <p class="eyebrow">Agregar aprendices</p>
                            <h2>Buscar por nombre, documento o ficha</h2>
                        </div>
                    </div>

                    <form class="search-toolbar" method="get" action="<?= instructor_h(app_url('instructor/participantes.php')) ?>">
                        <input type="hidden" name="evento" value="<?= instructor_h($selectedId) ?>">
                        <input type="hidden" name="vista" value="agregar">
                        <input type="search" name="buscar" value="<?= instructor_h($buscar) ?>" list="fichasInstructor" placeholder="Nombre, numero de documento o ficha..." autofocus>
                        <button class="primary-btn" type="submit">Buscar</button>
                        <?php if ($buscar !== ''): ?>
                            <a class="secondary-btn" href="<?= instructor_h(app_url('instructor/participantes.php?evento=' . (int)$selectedId . '&vista=agregar')) ?>">Limpiar</a>
                        <?php endif; ?>
                    </form>
                    <datalist id="fichasInstructor">
                        <?php foreach ($fichasDisponibles as $ficha): ?>
                            <option value="<?= instructor_h($ficha['id_ficha']) ?>">
                                <?= instructor_h($ficha['id_ficha']) ?> - <?= instructor_h($ficha['nombre_programa'] ?? 'Programa no asignado') ?><?= !empty($ficha['nombre_jornada']) ? ' / ' . instructor_h($ficha['nombre_jornada']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </datalist>

                    <section class="ficha-directory" aria-label="Fichas encontradas">
                        <div class="section-heading">
                            <h3>Lista desplegable de fichas</h3>
                            <p class="panel-subtitle">
                                Busca por numero de ficha, programa o jornada para desplegar sus aprendices.
                            </p>
                        </div>
                        <?php if ($buscar === ''): ?>
                            <div class="empty-state">Escribe una ficha, nombre o documento y presiona Buscar para ver la lista desplegable.</div>
                        <?php elseif (!$fichasBusqueda): ?>
                            <div class="empty-state">No hay fichas que coincidan con "<?= instructor_h($buscar) ?>". Si buscaste por documento o nombre, revisa los resultados individuales.</div>
                        <?php endif; ?>
                        <?php if ($buscar !== '' && $fichasBusqueda): ?>
                            <div class="ficha-dropdown-list">
                            <?php foreach ($fichasBusqueda as $ficha): ?>
                                <?php
                                $idFicha = (string)$ficha['id_ficha'];
                                $aprendicesFicha = $aprendicesPorFicha[$idFicha] ?? [];
                                $disponiblesFicha = (int)($ficha['disponibles'] ?? 0);
                                ?>
                                <details class="ficha-dropdown" open>
                                    <summary>
                                        <div>
                                            <span>Ficha</span>
                                            <strong><?= instructor_h($idFicha) ?></strong>
                                            <small>
                                                <?= instructor_h($ficha['nombre_programa'] ?? 'Programa no asignado') ?>
                                                <?= !empty($ficha['nombre_jornada']) ? ' · ' . instructor_h($ficha['nombre_jornada']) : '' ?>
                                            </small>
                                        </div>
                                        <div class="ficha-stats">
                                        <span><strong><?= instructor_h($ficha['total_aprendices'] ?? 0) ?></strong> aprendices</span>
                                        <span><strong><?= instructor_h($disponiblesFicha) ?></strong> disponibles</span>
                                        <span><strong><?= instructor_h($ficha['registrados'] ?? 0) ?></strong> ya registrados</span>
                                        </div>
                                    </summary>
                                    <div class="ficha-dropdown-body">
                                        <form class="ficha-add-form" method="post"
                                              data-confirm-kicker="Participantes"
                                              data-confirm-title="Agregar ficha <?= instructor_h($idFicha) ?>"
                                              data-confirm-message="Se agregaran los aprendices activos de la ficha <?= instructor_h($idFicha) ?> que aun no esten en el evento."
                                              data-confirm-text="Si, agregar ficha">
                                            <input type="hidden" name="csrf" value="<?= instructor_h($_SESSION['csrf_participants']) ?>">
                                            <input type="hidden" name="action" value="add_ficha">
                                            <input type="hidden" name="id_evento" value="<?= instructor_h($evento['id_evento']) ?>">
                                            <input type="hidden" name="id_ficha" value="<?= instructor_h($idFicha) ?>">
                                            <button class="primary-btn wide" type="submit" <?= $disponiblesFicha <= 0 ? 'disabled' : '' ?>>
                                                <?= $disponiblesFicha > 0 ? 'Agregar disponibles de esta ficha' : 'Sin aprendices disponibles' ?>
                                            </button>
                                        </form>
                                        <div class="ficha-learners">
                                            <?php if (!$aprendicesFicha): ?>
                                                <small class="panel-subtitle">Esta ficha aun no tiene aprendices activos asociados.</small>
                                            <?php endif; ?>
                                            <?php foreach ($aprendicesFicha as $aprendizFicha): ?>
                                                <?php
                                                $fullFicha = trim((string)$aprendizFicha['nombre'] . ' ' . (string)$aprendizFicha['apellido']);
                                                $yaRegistrado = (int)($aprendizFicha['ya_registrado'] ?? 0) === 1;
                                                ?>
                                                <div class="ficha-learner">
                                                    <div>
                                                        <strong><?= instructor_h($fullFicha !== '' ? $fullFicha : 'Aprendiz SICA') ?></strong>
                                                        <small><?= instructor_h($aprendizFicha['id_documento']) ?> · <?= instructor_h($aprendizFicha['correo']) ?></small>
                                                    </div>
                                                    <?php if ($yaRegistrado): ?>
                                                        <span class="status-pill ok">Ya registrado</span>
                                                    <?php else: ?>
                                                        <form method="post"
                                                              data-confirm-kicker="Participantes"
                                                              data-confirm-title="Agregar aprendiz"
                                                              data-confirm-message="Este aprendiz quedara registrado como participante pendiente del evento."
                                                              data-confirm-text="Si, agregar">
                                                            <input type="hidden" name="csrf" value="<?= instructor_h($_SESSION['csrf_participants']) ?>">
                                                            <input type="hidden" name="action" value="add_participant">
                                                            <input type="hidden" name="id_evento" value="<?= instructor_h($evento['id_evento']) ?>">
                                                            <input type="hidden" name="id_documento" value="<?= instructor_h($aprendizFicha['id_documento']) ?>">
                                                            <button class="secondary-btn" type="submit">Agregar</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <?php if ($buscar !== ''): ?>
                    <div class="available-learners">
                        <div class="section-heading">
                            <h3>Resultados individuales disponibles</h3>
                            <p class="panel-subtitle">Estos son los aprendices que aun no estan registrados en el evento y coinciden con la busqueda.</p>
                        </div>
                        <?php if (!$aprendicesDisponibles && $buscar !== ''): ?>
                            <div class="empty-state">
                                <strong>Sin resultados para "<?= instructor_h($buscar) ?>".</strong>
                                <span>Revisa la seccion de fichas: si aparece como "Ya registrado", el aprendiz ya esta dentro del evento.</span>
                            </div>
                        <?php elseif (!$aprendicesDisponibles): ?>
                            <div class="empty-state">Todos los aprendices visibles ya estan registrados en este evento.</div>
                        <?php endif; ?>
                        <?php foreach ($aprendicesDisponibles as $aprendiz): ?>
                            <?php
                            $fullName = trim((string)$aprendiz['nombre'] . ' ' . (string)$aprendiz['apellido']);
                            $avatarLabel = trim((string)mb_substr((string)$aprendiz['nombre'], 0, 1, 'UTF-8') . (string)mb_substr((string)$aprendiz['apellido'], 0, 1, 'UTF-8'));
                            $avatarText = $avatarLabel !== '' ? mb_strtoupper($avatarLabel, 'UTF-8') : '?';
                            ?>
                            <article class="available-learner-row">
                                <b><?= instructor_h($avatarText) ?></b>
                                <div>
                                    <strong><?= instructor_h($fullName !== '' ? $fullName : 'Aprendiz SICA') ?></strong>
                                    <small>
                                        <?= instructor_h($aprendiz['correo']) ?>
                                        · Ficha <?= instructor_h($aprendiz['id_ficha'] ?? 'N/A') ?>
                                        <?php if (!empty($aprendiz['nombre_programa'])): ?>
                                            · <?= instructor_h($aprendiz['nombre_programa']) ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <form method="post"
                                      data-confirm-kicker="Participantes"
                                      data-confirm-title="Agregar aprendiz"
                                      data-confirm-message="Este aprendiz quedara registrado como participante pendiente del evento."
                                      data-confirm-text="Si, agregar">
                                    <input type="hidden" name="csrf" value="<?= instructor_h($_SESSION['csrf_participants']) ?>">
                                    <input type="hidden" name="action" value="add_participant">
                                    <input type="hidden" name="id_evento" value="<?= instructor_h($evento['id_evento']) ?>">
                                    <input type="hidden" name="id_documento" value="<?= instructor_h($aprendiz['id_documento']) ?>">
                                    <button class="primary-btn" type="submit">Agregar</button>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
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

<?php instructor_layout_end(); ?>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
