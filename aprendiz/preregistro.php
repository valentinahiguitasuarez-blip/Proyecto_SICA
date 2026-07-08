<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([4]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/aprendiz_helpers.php';

$pageTitle = 'Pre-registro del Aprendiz - SICA';
$pageStyles = ['css/aprendiz.css'];

$usuario = $_SESSION['usuario'] ?? [];
$idDocumento = (int)($usuario['id_documento'] ?? 0);
$nombre = trim((string)($usuario['nombre'] ?? 'Aprendiz'));
$apellido = trim((string)($usuario['apellido'] ?? ''));
$nombreCompleto = trim($nombre . ' ' . $apellido);
$nombreCompleto = $nombreCompleto !== '' ? $nombreCompleto : 'Aprendiz SICA';
$correoAprendiz = (string)($usuario['correo'] ?? 'Correo no registrado');
$iniciales = mb_strtoupper(mb_substr($nombre, 0, 1, 'UTF-8') . mb_substr($apellido, 0, 1, 'UTF-8'), 'UTF-8');
$iniciales = $iniciales !== '' ? $iniciales : 'A';
$fichaAprendiz = 'Sin ficha';
$programaAprendiz = 'Programa no asignado';
$jornadaAprendiz = 'Jornada no asignada';
$fotoPerfil = (string)($usuario['foto_perfil'] ?? '');
$formMessage = $_SESSION['preregister_message'] ?? '';
$formMessageType = $_SESSION['preregister_message_type'] ?? 'success';
unset($_SESSION['preregister_message'], $_SESSION['preregister_message_type']);

if (empty($_SESSION['csrf_preregistro'])) {
    $_SESSION['csrf_preregistro'] = bin2hex(random_bytes(32));
}

$eventoSeleccionado = (int)($_GET['evento'] ?? 0);

function asistenciaTexto(?string $asistencia): string
{
    $valor = (string)$asistencia;
    if ($valor === 'Pendiente') {
        return 'Pendiente';
    }

    if (stripos($valor, 'No') !== false) {
        return 'No asistió';
    }

    return 'Asistió';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'create_preregistro') {
    $csrf = (string)($_POST['csrf_preregistro'] ?? '');
    $eventId = (int)($_POST['id_evento'] ?? 0);

    if (!hash_equals((string)$_SESSION['csrf_preregistro'], $csrf)) {
        $_SESSION['preregister_message'] = 'La sesión expiró. Intenta de nuevo.';
        $_SESSION['preregister_message_type'] = 'danger';
    } elseif ($idDocumento <= 0 || $eventId <= 0) {
        $_SESSION['preregister_message'] = 'Selecciona un evento disponible.';
        $_SESSION['preregister_message_type'] = 'danger';
    } else {
        try {
            $existsStmt = $pdo->prepare(
                'SELECT id_preregistro
                 FROM preregistro
                 WHERE id_documento = :id_documento
                   AND id_evento = :id_evento
                 LIMIT 1'
            );
            $existsStmt->execute([
                ':id_documento' => $idDocumento,
                ':id_evento' => $eventId,
            ]);

            if ($existsStmt->fetch()) {
                $_SESSION['preregister_message'] = 'Ya tienes un pre-registro para ese evento.';
                $_SESSION['preregister_message_type'] = 'info';
            } else {
                $capacityStmt = $pdo->prepare(
                    'SELECT e.id_evento, a.capacidad, COUNT(pr.id_preregistro) AS registrados
                     FROM evento e
                     INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
                     INNER JOIN estado es ON es.id_estado = e.id_estado
                     LEFT JOIN preregistro pr ON pr.id_evento = e.id_evento
                     WHERE e.id_evento = :id_evento
                       AND es.nombre_estado = \'Activo\'
                     GROUP BY e.id_evento, a.capacidad
                     LIMIT 1'
                );
                $capacityStmt->execute([
                    ':id_evento' => $eventId,
                ]);
                $capacityInfo = $capacityStmt->fetch();

                if (!$capacityInfo) {
                    $_SESSION['preregister_message'] = 'Este evento no está disponible para pre-registro.';
                    $_SESSION['preregister_message_type'] = 'danger';
                } elseif ((int)$capacityInfo['registrados'] >= (int)$capacityInfo['capacidad']) {
                    $_SESSION['preregister_message'] = 'El auditorio ya no tiene cupos disponibles para este evento.';
                    $_SESSION['preregister_message_type'] = 'danger';
                } else {
                    $insertStmt = $pdo->prepare(
                        'INSERT INTO preregistro (id_documento, id_evento, fecha_registro, asistencia)
                         VALUES (:id_documento, :id_evento, CURDATE(), :asistencia)'
                    );
                    $insertStmt->execute([
                        ':id_documento' => $idDocumento,
                        ':id_evento' => $eventId,
                        ':asistencia' => 'Pendiente',
                    ]);

                    $_SESSION['preregister_message'] = 'Pre-registro realizado. Tu cupo quedó reservado para el evento.';
                    $_SESSION['preregister_message_type'] = 'success';
                }
            }
        } catch (Throwable $exception) {
            error_log('SICA: error creando pre-registro: ' . $exception->getMessage());
            $_SESSION['preregister_message'] = 'No fue posible completar el pre-registro.';
            $_SESSION['preregister_message_type'] = 'danger';
        }
    }

    $redirectUrl = app_url('aprendiz/preregistro.php' . ($eventId > 0 ? '?evento=' . $eventId : ''));
    header('Location: ' . $redirectUrl);
    exit;
}

try {
    $perfilStmt = $pdo->prepare(
        'SELECT u.id_ficha, u.foto_perfil, p.nombre_programa, j.nombre_jornada
         FROM usuario u
         LEFT JOIN ficha f ON f.id_ficha = u.id_ficha
         LEFT JOIN programa p ON p.id_programa = f.id_programa
         LEFT JOIN jornada j ON j.id_jornada = p.id_jornada
         WHERE u.id_documento = :id_documento
         LIMIT 1'
    );
    $perfilStmt->execute([':id_documento' => $idDocumento]);
    $perfilAprendiz = $perfilStmt->fetch();

    if ($perfilAprendiz) {
        $fichaAprendiz = !empty($perfilAprendiz['id_ficha']) ? (string)$perfilAprendiz['id_ficha'] : $fichaAprendiz;
        $programaAprendiz = !empty($perfilAprendiz['nombre_programa']) ? (string)$perfilAprendiz['nombre_programa'] : $programaAprendiz;
        $jornadaAprendiz = !empty($perfilAprendiz['nombre_jornada']) ? (string)$perfilAprendiz['nombre_jornada'] : $jornadaAprendiz;
        $fotoPerfil = !empty($perfilAprendiz['foto_perfil']) ? (string)$perfilAprendiz['foto_perfil'] : $fotoPerfil;
    }
} catch (Throwable $exception) {
    error_log('SICA: no se pudo cargar el perfil del aprendiz: ' . $exception->getMessage());
}

$preregistros = [];
try {
    $stmt = $pdo->prepare(
        'SELECT pr.id_preregistro, pr.fecha_registro, pr.asistencia, pr.fecha_ingreso, pr.hora,
                e.nombre_evento, e.descripcion, e.fecha_evento, e.hora_inicio, e.hora_fin, es.nombre_estado AS estado,
                a.nombre_auditorio, a.bloque, t.nombre_tipo, c.id_certificado, c.ruta_certificado
         FROM preregistro pr
         INNER JOIN evento e ON e.id_evento = pr.id_evento
         INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
         INNER JOIN tipo_evento t ON t.id_tipo_evento = e.id_tipo_evento
         INNER JOIN estado es ON es.id_estado = e.id_estado
         LEFT JOIN certificado c ON c.id_preregistro = pr.id_preregistro
         WHERE pr.id_documento = :id_documento
         ORDER BY e.fecha_evento DESC, e.hora_inicio DESC'
    );
    $stmt->execute([':id_documento' => $idDocumento]);
    $preregistros = $stmt->fetchAll();
} catch (Throwable $exception) {
    error_log('SICA: no se pudieron cargar los pre-registros: ' . $exception->getMessage());
}

$eventosFormulario = [];
try {
    $availableStmt = $pdo->prepare(
        'SELECT e.id_evento, e.nombre_evento, e.fecha_evento, e.hora_inicio, e.hora_fin,
                a.nombre_auditorio, a.bloque, a.capacidad, t.nombre_tipo, pr.id_preregistro,
                COUNT(pr_all.id_preregistro) AS registros_actuales
         FROM evento e
         INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
         INNER JOIN tipo_evento t ON t.id_tipo_evento = e.id_tipo_evento
         INNER JOIN estado es ON es.id_estado = e.id_estado
         LEFT JOIN preregistro pr ON pr.id_evento = e.id_evento AND pr.id_documento = :id_documento
         LEFT JOIN preregistro pr_all ON pr_all.id_evento = e.id_evento
         WHERE es.nombre_estado = \'Activo\'
         GROUP BY e.id_evento, e.nombre_evento, e.fecha_evento, e.hora_inicio, e.hora_fin,
                  a.nombre_auditorio, a.bloque, a.capacidad, t.nombre_tipo, pr.id_preregistro
         ORDER BY e.fecha_evento ASC, e.hora_inicio ASC'
    );
    $availableStmt->execute([':id_documento' => $idDocumento]);
    $eventosFormulario = $availableStmt->fetchAll();
} catch (Throwable $exception) {
    error_log('SICA: no se pudieron cargar eventos disponibles para pre-registro: ' . $exception->getMessage());
}

$totalPreregistros = count($preregistros);
$pendientes = count(array_filter($preregistros, static fn(array $item): bool => (string)$item['asistencia'] === 'Pendiente'));
$asistidos = count(array_filter($preregistros, static fn(array $item): bool => asistenciaTexto((string)$item['asistencia']) === 'Asistió'));
$certificados = count(array_filter($preregistros, static fn(array $item): bool => !empty($item['ruta_certificado'])));
$eventoFijo = null;
if ($eventoSeleccionado > 0) {
    foreach ($eventosFormulario as $evento) {
        if ((int)$evento['id_evento'] === $eventoSeleccionado) {
            $eventoFijo = $evento;
            break;
        }
    }
}
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>

<main class="apprentice-dashboard">
    <?php apprentice_sidebar('preregistro', $nombreCompleto, $iniciales, $fotoPerfil, $correoAprendiz); ?>

    <section class="apprentice-main">
        <header class="apprentice-topbar">
            <div>
                <p class="eyebrow">Panel de pre-registro</p>
                <h1>Mis pre-registros</h1>
                <span>Consulta tus cupos reservados y el estado de ingreso al auditorio.</span>
            </div>

            <a class="top-logout" href="<?= htmlspecialchars(app_url('login/logout.php'), ENT_QUOTES, 'UTF-8') ?>">
                <span aria-hidden="true">SL</span>
                Cerrar sesión
            </a>
        </header>

        <section class="preregister-hero" aria-label="Resumen de pre-registro">
            <div>
                <p class="eyebrow">Control de ingreso</p>
                <h2><?= htmlspecialchars((string)$pendientes, ENT_QUOTES, 'UTF-8') ?> eventos pendientes</h2>
                <span>Cuando llegues al auditorio, el responsable del evento validará tu asistencia.</span>
            </div>
            <a href="<?= htmlspecialchars(app_url('aprendiz/eventos.php'), ENT_QUOTES, 'UTF-8') ?>">Buscar más eventos</a>
        </section>

        <section class="preregister-form-panel" aria-label="Formulario de pre-registro">
            <details class="preregister-drawer" <?= ($formMessage !== '' || $eventoSeleccionado > 0) ? 'open' : '' ?>>
                <summary>
                    <span>
                        <small>Nuevo pre-registro</small>
                        <strong>Registrarme a un evento</strong>
                        <em>Confirma el evento y reserva tu cupo sin modificar tus datos personales.</em>
                    </span>
                    <b>Crear pre-registro</b>
                </summary>

                <?php if ($formMessage !== ''): ?>
                    <div class="event-alert <?= htmlspecialchars($formMessageType, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($formMessage, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <form class="preregister-form" method="post" action="<?= htmlspecialchars(app_url('aprendiz/preregistro.php'), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="create_preregistro">
                    <input type="hidden" name="csrf_preregistro" value="<?= htmlspecialchars($_SESSION['csrf_preregistro'], ENT_QUOTES, 'UTF-8') ?>">

                    <div class="form-readonly">
                        <span>Nombre completo</span>
                        <strong><?= htmlspecialchars($nombreCompleto, ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="form-readonly">
                        <span>Documento</span>
                        <strong><?= htmlspecialchars((string)$idDocumento, ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="form-readonly">
                        <span>Correo personal</span>
                        <strong><?= htmlspecialchars($correoAprendiz, ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="form-readonly">
                        <span>Ficha</span>
                        <strong><?= htmlspecialchars($fichaAprendiz, ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="form-readonly program-field">
                        <span>Programa</span>
                        <strong><?= htmlspecialchars($programaAprendiz, ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="form-readonly">
                        <span>Jornada</span>
                        <strong><?= htmlspecialchars($jornadaAprendiz, ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <?php if ($eventoFijo): ?>
                        <?php
                        $fechaEventoFijo = new DateTime((string)$eventoFijo['fecha_evento']);
                        $cuposDisponiblesFijo = max(0, (int)$eventoFijo['capacidad'] - (int)$eventoFijo['registros_actuales']);
                        $eventoFijoTexto = $eventoFijo['nombre_evento'] . ' - ' . $fechaEventoFijo->format('d/m') . ' ' . substr((string)$eventoFijo['hora_inicio'], 0, 5);
                        ?>
                        <label class="event-select-field fixed-event-field">
                            <span>Evento seleccionado</span>
                            <input type="hidden" name="id_evento" value="<?= htmlspecialchars((string)$eventoFijo['id_evento'], ENT_QUOTES, 'UTF-8') ?>">
                            <strong><?= htmlspecialchars($eventoFijoTexto, ENT_QUOTES, 'UTF-8') ?></strong>
                            <small><?= htmlspecialchars('Cupos disponibles: ' . (string)$cuposDisponiblesFijo, ENT_QUOTES, 'UTF-8') ?></small>
                        </label>
                    <?php else: ?>
                        <label class="event-select-field">
                            <span>Evento disponible</span>
                            <select name="id_evento" required <?= !$eventosFormulario ? 'disabled' : '' ?>>
                                <option value="">Selecciona un evento</option>
                                <?php foreach ($eventosFormulario as $evento): ?>
                                    <?php
                                    $fechaEvento = new DateTime((string)$evento['fecha_evento']);
                                    $cuposDisponibles = max(0, (int)$evento['capacidad'] - (int)$evento['registros_actuales']);
                                    $optionText = $evento['nombre_evento'] . ' - ' . $fechaEvento->format('d/m') . ' ' . substr((string)$evento['hora_inicio'], 0, 5) . ' - cupos ' . $cuposDisponibles;
                                    if (!empty($evento['id_preregistro'])) {
                                        $optionText .= ' - registrado';
                                    }
                                    ?>
                                    <option value="<?= htmlspecialchars((string)$evento['id_evento'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($optionText, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                    <button type="submit" <?= !$eventosFormulario ? 'disabled' : '' ?>>
                        Enviar pre-registro
                    </button>
                </form>

                <?php if (!$eventosFormulario): ?>
                    <p class="form-note">No hay eventos abiertos para pre-registro en este momento.</p>
                <?php else: ?>
                    <p class="form-note">Si eliges un evento donde ya estás registrado, el sistema te avisará y no duplicará el cupo.</p>
                <?php endif; ?>
            </details>
        </section>

        <section class="preregister-stats" aria-label="Indicadores de pre-registro">
            <article>
                <span>Total</span>
                <strong><?= htmlspecialchars((string)$totalPreregistros, ENT_QUOTES, 'UTF-8') ?></strong>
            </article>
            <article>
                <span>Pendientes</span>
                <strong><?= htmlspecialchars((string)$pendientes, ENT_QUOTES, 'UTF-8') ?></strong>
            </article>
            <article>
                        <span>Asistidos</span>
                <strong><?= htmlspecialchars((string)$asistidos, ENT_QUOTES, 'UTF-8') ?></strong>
            </article>
            <article>
                <span>Certificados</span>
                <strong><?= htmlspecialchars((string)$certificados, ENT_QUOTES, 'UTF-8') ?></strong>
            </article>
        </section>

        <section class="preregister-panel" aria-label="Listado de pre-registros">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Seguimiento</p>
                    <h2>Eventos reservados</h2>
                    <span>Estos son los eventos donde ya separaste cupo.</span>
                </div>
            </div>

            <div class="preregister-list">
                <?php if (!$preregistros): ?>
                    <article class="event-empty">
                        <strong>Aún no tienes pre-registros.</strong>
                        <span>Explora eventos disponibles y separa tu cupo.</span>
                    </article>
                <?php endif; ?>

                <?php foreach ($preregistros as $item): ?>
                    <?php
                    $fechaEvento = new DateTime((string)$item['fecha_evento']);
                    $asistencia = asistenciaTexto((string)$item['asistencia']);
                    $statusClass = $asistencia === 'Pendiente' ? 'pending' : ($asistencia === 'Asistió' ? 'ok' : 'missed');
                    ?>
                    <article class="preregister-card <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="preregister-date">
                            <strong><?= htmlspecialchars($fechaEvento->format('d'), ENT_QUOTES, 'UTF-8') ?></strong>
                            <span><?= htmlspecialchars(apprentice_month($fechaEvento), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="preregister-body">
                            <span class="event-type"><?= htmlspecialchars((string)$item['nombre_tipo'], ENT_QUOTES, 'UTF-8') ?></span>
                            <h3><?= htmlspecialchars((string)$item['nombre_evento'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <p><?= htmlspecialchars((string)($item['descripcion'] ?? 'Evento programado por SICA.'), ENT_QUOTES, 'UTF-8') ?></p>
                            <div class="event-meta">
                                <span><?= htmlspecialchars(substr((string)$item['hora_inicio'], 0, 5) . ' - ' . substr((string)$item['hora_fin'], 0, 5), ENT_QUOTES, 'UTF-8') ?></span>
                                <span><?= htmlspecialchars((string)$item['nombre_auditorio'] . ' / Bloque ' . (string)$item['bloque'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span><?= htmlspecialchars('Registro ' . (string)$item['fecha_registro'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        </div>
                        <div class="preregister-status">
                            <div class="preregister-status-card">
                                <span><?= htmlspecialchars($asistencia, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if (empty($item['id_certificado'])): ?>
                                    <small><?= $asistencia === 'Pendiente' ? 'Esperando ingreso' : 'Certificado pendiente' ?></small>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($item['id_certificado'])): ?>
                                <a href="<?= htmlspecialchars(app_url('aprendiz/descargar_certificado.php?id=' . (int)$item['id_certificado']), ENT_QUOTES, 'UTF-8') ?>">Certificado</a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </section>
</main>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
