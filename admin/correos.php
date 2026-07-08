<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([1]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/smtp_mailer.php';

$pageTitle = 'Correos y Notificaciones - Administrador SICA';
$pageStyles = ['css/admin.css'];

$usuario = $_SESSION['usuario'] ?? [];
$adminName = trim((string)($usuario['nombre'] ?? 'Administrador'));
$adminMail = (string)($usuario['correo'] ?? 'admin@sica.edu.co');

function admin_c_h(string|int|null $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function admin_c_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function admin_c_scalar(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function admin_c_mail_payload(array $evento, string $tipo, string $adminName): array
{
    $instructor = trim((string)$evento['instructor_nombre'] . ' ' . (string)$evento['instructor_apellido']);
    $coordinador = trim((string)$evento['coord_nombre'] . ' ' . (string)$evento['coord_apellido']);
    $hora = substr((string)$evento['hora_inicio'], 0, 5) . ' - ' . substr((string)$evento['hora_fin'], 0, 5);

    if ($tipo === 'coordinador') {
        return [
            'to' => (string)$evento['coord_correo'],
            'subject' => 'Solicitud de reserva de auditorio - SICA',
            'body' => "Hola {$coordinador},\n\n"
                . "El administrador {$adminName} te envia una solicitud de reserva de auditorio para revision.\n\n"
                . "Evento: {$evento['nombre_evento']}\n"
                . "Instructor: {$instructor}\n"
                . "Auditorio: {$evento['nombre_auditorio']} / Bloque {$evento['bloque']}\n"
                . "Fecha: {$evento['fecha_evento']}\n"
                . "Hora: {$hora}\n"
                . "Estado actual: {$evento['estado']}\n"
                . "Observacion: " . ((string)($evento['observacion'] ?? '') !== '' ? (string)$evento['observacion'] : 'Sin observaciones') . "\n\n"
                . "Ingresa a SICA como coordinador para aprobar o cancelar la solicitud.\n\nEquipo SICA",
        ];
    }

    return [
        'to' => (string)$evento['instructor_correo'],
        'subject' => 'Respuesta a tu solicitud de auditorio - SICA',
        'body' => "Hola {$instructor},\n\n"
            . "Coordinacion ya reviso tu solicitud de reserva en SICA.\n\n"
            . "Evento: {$evento['nombre_evento']}\n"
            . "Estado actual: {$evento['estado']}\n"
            . "Auditorio: {$evento['nombre_auditorio']} / Bloque {$evento['bloque']}\n"
            . "Fecha: {$evento['fecha_evento']}\n"
            . "Hora: {$hora}\n"
            . "Observacion: " . ((string)($evento['observacion'] ?? '') !== '' ? (string)$evento['observacion'] : 'Sin observaciones') . "\n\n"
            . "Gracias por usar SICA.\n\nEquipo SICA",
    ];
}

if (empty($_SESSION['csrf_admin_mail'])) {
    $_SESSION['csrf_admin_mail'] = bin2hex(random_bytes(32));
}

$message = $_SESSION['admin_mail_message'] ?? '';
$messageType = $_SESSION['admin_mail_message_type'] ?? 'success';
unset($_SESSION['admin_mail_message'], $_SESSION['admin_mail_message_type']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = (string)($_POST['csrf_admin_mail'] ?? '');
    $idEvento = (int)($_POST['id_evento'] ?? 0);
    $accion = (string)($_POST['accion'] ?? '');

    if (!hash_equals((string)$_SESSION['csrf_admin_mail'], $csrf)) {
        $_SESSION['admin_mail_message'] = 'La sesión expiró. Intenta de nuevo.';
        $_SESSION['admin_mail_message_type'] = 'danger';
    } elseif ($idEvento <= 0 || !in_array($accion, ['reenviar_coordinador', 'notificar_instructor'], true)) {
        $_SESSION['admin_mail_message'] = 'Selecciona una notificación válida.';
        $_SESSION['admin_mail_message_type'] = 'danger';
    } else {
        try {
            $stmt = $pdo->prepare(
                'SELECT e.id_evento, e.nombre_evento, e.fecha_evento, e.hora_inicio, e.hora_fin,
                        e.observacion, e.fecha_aprobacion, es.nombre_estado AS estado,
                        a.nombre_auditorio, a.bloque,
                        ins.nombre AS instructor_nombre, ins.apellido AS instructor_apellido, ins.correo AS instructor_correo,
                        coord.nombre AS coord_nombre, coord.apellido AS coord_apellido, coord.correo AS coord_correo
                 FROM evento e
                 INNER JOIN estado es ON es.id_estado = e.id_estado
                 INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
                 LEFT JOIN usuario ins ON ins.id_documento = e.id_solicitante
                 LEFT JOIN usuario coord ON coord.id_documento = e.id_coordinador
                 WHERE e.id_evento = :id_evento
                 LIMIT 1'
            );
            $stmt->execute([':id_evento' => $idEvento]);
            $evento = $stmt->fetch();

            if (!$evento) {
                throw new RuntimeException('No se encontró la solicitud.');
            }

            if ($accion === 'reenviar_coordinador') {
                if (empty($evento['coord_correo']) || !filter_var((string)$evento['coord_correo'], FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('La solicitud no tiene coordinador con correo válido.');
                }
                $payload = admin_c_mail_payload($evento, 'coordinador', $adminName);
            } else {
                if (empty($evento['fecha_aprobacion'])) {
                    throw new RuntimeException('Aún no hay decisión de coordinación para enviar al instructor.');
                }
                if (empty($evento['instructor_correo']) || !filter_var((string)$evento['instructor_correo'], FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('El instructor no tiene correo válido registrado.');
                }
                $payload = admin_c_mail_payload($evento, 'instructor', $adminName);
            }

            if (!sica_send_mail($payload['to'], $payload['subject'], $payload['body'])) {
                throw new RuntimeException('No se pudo enviar el correo. Revisa la configuración SMTP.');
            }

            $_SESSION['admin_mail_message'] = 'Correo enviado correctamente.';
            $_SESSION['admin_mail_message_type'] = 'success';
        } catch (Throwable $exception) {
            $_SESSION['admin_mail_message'] = $exception->getMessage() !== ''
                ? $exception->getMessage()
                : 'No fue posible enviar el correo.';
            $_SESSION['admin_mail_message_type'] = 'danger';
            error_log('SICA admin correos: ' . $exception->getMessage());
        }
    }

    header('Location: ' . app_url('admin/correos.php'));
    exit;
}

$filtro = trim((string)($_GET['estado'] ?? ''));
$busqueda = trim((string)($_GET['q'] ?? ''));
$params = [];
$where = ['1 = 1'];

if ($filtro !== '') {
    $where[] = 'es.nombre_estado = :estado';
    $params[':estado'] = $filtro;
}

if ($busqueda !== '') {
    $where[] = '(e.nombre_evento LIKE :busqueda OR ins.correo LIKE :busqueda OR coord.correo LIKE :busqueda OR ins.nombre LIKE :busqueda OR coord.nombre LIKE :busqueda)';
    $params[':busqueda'] = '%' . $busqueda . '%';
}

$whereSql = ' WHERE ' . implode(' AND ', $where);
$stats = [
    'sin_enviar' => 0,
    'coordinacion' => 0,
    'decisiones' => 0,
    'pendientes' => 0,
    'cancelados' => 0,
];
$notificaciones = [];

try {
    $stats['sin_enviar'] = admin_c_scalar(
        $pdo,
        "SELECT COUNT(*)
         FROM evento e
         INNER JOIN estado es ON es.id_estado = e.id_estado
         WHERE e.id_coordinador IS NULL
           AND es.nombre_estado = 'Pendiente'"
    );
    $stats['coordinacion'] = admin_c_scalar($pdo, 'SELECT COUNT(*) FROM evento WHERE id_coordinador IS NOT NULL');
    $stats['decisiones'] = admin_c_scalar($pdo, 'SELECT COUNT(*) FROM evento WHERE id_coordinador IS NOT NULL AND fecha_aprobacion IS NOT NULL');
    $stats['pendientes'] = admin_c_scalar(
        $pdo,
        "SELECT COUNT(*)
         FROM evento e
         INNER JOIN estado es ON es.id_estado = e.id_estado
         WHERE e.id_coordinador IS NOT NULL
           AND es.nombre_estado = 'Pendiente'"
    );
    $stats['cancelados'] = admin_c_scalar(
        $pdo,
        "SELECT COUNT(*)
         FROM evento e
         INNER JOIN estado es ON es.id_estado = e.id_estado
         WHERE e.id_coordinador IS NOT NULL
           AND es.nombre_estado = 'Cancelado'"
    );

    $notificaciones = admin_c_rows(
        $pdo,
        'SELECT e.id_evento, e.nombre_evento, e.fecha_evento, e.hora_inicio, e.hora_fin,
                e.observacion, e.fecha_aprobacion, es.nombre_estado AS estado,
                a.nombre_auditorio, a.bloque,
                ins.nombre AS instructor_nombre, ins.apellido AS instructor_apellido, ins.correo AS instructor_correo,
                coord.nombre AS coord_nombre, coord.apellido AS coord_apellido, coord.correo AS coord_correo
         FROM evento e
         INNER JOIN estado es ON es.id_estado = e.id_estado
         INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
         LEFT JOIN usuario ins ON ins.id_documento = e.id_solicitante
         LEFT JOIN usuario coord ON coord.id_documento = e.id_coordinador' .
            $whereSql .
        ' ORDER BY
            CASE
                WHEN e.id_coordinador IS NULL AND es.nombre_estado = \'Pendiente\' THEN 1
                WHEN e.id_coordinador IS NOT NULL AND e.fecha_aprobacion IS NULL THEN 2
                WHEN e.id_coordinador IS NOT NULL AND e.fecha_aprobacion IS NOT NULL THEN 3
                ELSE 4
            END,
            COALESCE(e.fecha_aprobacion, e.fecha_evento) DESC,
            e.hora_inicio DESC
          LIMIT 100',
        $params
    );
} catch (Throwable $exception) {
    error_log('SICA admin correos listado: ' . $exception->getMessage());
}

$seccionesCorreo = [
    'falta_coordinador' => [
        'titulo' => 'Falta coordinador',
        'descripcion' => 'Primero asigna quien revisa la reserva.',
        'class' => 'pending',
    ],
    'en_coordinacion' => [
        'titulo' => 'En coordinacion',
        'descripcion' => 'Ya se puede enviar o reenviar al coordinador.',
        'class' => 'waiting',
    ],
    'por_notificar' => [
        'titulo' => 'Notificar instructor',
        'descripcion' => 'La respuesta final se envia al instructor.',
        'class' => 'ready',
    ],
    'canceladas' => [
        'titulo' => 'Canceladas',
        'descripcion' => 'Reservas con respuesta negativa o cierre.',
        'class' => 'rejected',
    ],
];
$correosPorSeccion = array_fill_keys(array_keys($seccionesCorreo), []);

foreach ($notificaciones as $notificacion) {
    $estadoCorreo = (string)$notificacion['estado'];
    $decididaCorreo = !empty($notificacion['fecha_aprobacion']);
    $tieneCoordinadorCorreo = !empty($notificacion['coord_correo']);
    $seccionCorreo = 'en_coordinacion';

    if (!$tieneCoordinadorCorreo && $estadoCorreo === 'Pendiente') {
        $seccionCorreo = 'falta_coordinador';
    } elseif ($estadoCorreo === 'Cancelado') {
        $seccionCorreo = 'canceladas';
    } elseif ($decididaCorreo) {
        $seccionCorreo = 'por_notificar';
    }

    $correosPorSeccion[$seccionCorreo][] = $notificacion;
}

$panelCorreoActivo = (string)($_GET['panel'] ?? '');
if (!isset($seccionesCorreo[$panelCorreoActivo])) {
    $panelCorreoActivo = 'falta_coordinador';
    foreach ($correosPorSeccion as $claveCorreo => $itemsCorreo) {
        if ($itemsCorreo) {
            $panelCorreoActivo = (string)$claveCorreo;
            break;
        }
    }
}
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>

<main class="admin-dashboard">
    <aside class="admin-sidebar" aria-label="Menu del administrador">
        <a class="admin-brand admin-brand--with-mark" href="<?= admin_c_h(app_url('admin/index.php')) ?>">
            <span>
                <strong>SICA</strong>
                <small>Sistema Inteligente de Control de Asistencia</small>
            </span>
        </a>

        <section class="admin-profile" aria-label="Administrador activo">
            <div class="admin-avatar">AD</div>
            <div>
                <strong><?= admin_c_h($adminName) ?></strong>
                <small><?= admin_c_h($adminMail) ?></small>
                <span>En línea</span>
            </div>
        </section>

        <nav class="admin-nav">
            <a href="<?= admin_c_h(app_url('admin/index.php')) ?>"><span class="nav-symbol nav-symbol-dashboard" aria-hidden="true"></span>Panel de Control</a>
            <a href="<?= admin_c_h(app_url('admin/usuarios.php')) ?>"><span class="nav-symbol nav-symbol-users" aria-hidden="true"></span>Usuarios</a>
            <a href="<?= admin_c_h(app_url('admin/solicitudes.php')) ?>"><span class="nav-symbol nav-symbol-reservations" aria-hidden="true"></span>Solicitudes de Reserva</a>
            <a class="active" href="<?= admin_c_h(app_url('admin/correos.php')) ?>"><span class="nav-symbol nav-symbol-mail" aria-hidden="true"></span>Correos y Notificaciones</a>
            <a href="<?= admin_c_h(app_url('admin/auditorios.php')) ?>"><span class="nav-symbol nav-symbol-auditoriums" aria-hidden="true"></span>Auditorios</a>
            <a href="<?= admin_c_h(app_url('admin/reportes.php')) ?>"><span class="nav-symbol nav-symbol-reports" aria-hidden="true"></span>Reportes</a>
        </nav>
    </aside>

    <section class="admin-main">
        <header class="admin-topbar">
            <div>
                <p class="admin-eyebrow">Comunicaciones</p>
                <h1>Correos y notificaciones</h1>
                <span>Controla qué solicitudes deben enviarse a coordinación y cuáles ya pueden notificarse al instructor.</span>
            </div>
            <div class="admin-top-actions">
                <a href="<?= admin_c_h(app_url('admin/solicitudes.php')) ?>">Reservas <strong>SR</strong></a>
                <a class="admin-logout" href="<?= admin_c_h(app_url('login/logout.php')) ?>">Cerrar sesión</a>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <div class="admin-alert <?= admin_c_h($messageType) ?>">
                <?= admin_c_h($message) ?>
            </div>
        <?php endif; ?>

        <section class="admin-mail-guide admin-mail-guide-compact" aria-label="Ruta de envio de correos">
            <div>
                <p class="admin-eyebrow">Ruta de envio</p>
                <h2>Correos claros para cada paso</h2>
                <span>Elige el panel y envia el correo correcto segun el estado de la reserva.</span>
            </div>
            <ol>
                <li><strong class="mail-step-icon mail-step-assign" aria-hidden="true"></strong><span>Asignar coordinador</span></li>
                <li><strong class="mail-step-icon mail-step-send" aria-hidden="true"></strong><span>Enviar a coordinacion</span></li>
                <li><strong class="mail-step-icon mail-step-notify" aria-hidden="true"></strong><span>Notificar instructor</span></li>
            </ol>
        </section>

        <section class="admin-metrics reservation-metrics admin-mail-metrics-hidden" aria-label="Resumen de correos">
            <a class="admin-metric" href="<?= admin_c_h(app_url('admin/correos.php?estado=Pendiente')) ?>">
                <span>Por enviar</span>
                <strong><?= admin_c_h($stats['sin_enviar']) ?></strong>
                <small>Sin coordinador asignado</small>
            </a>
            <a class="admin-metric" href="<?= admin_c_h(app_url('admin/correos.php?estado=Pendiente')) ?>">
                <span>En coordinación</span>
                <strong><?= admin_c_h($stats['pendientes']) ?></strong>
                <small>Esperando decisión</small>
            </a>
            <a class="admin-metric" href="<?= admin_c_h(app_url('admin/correos.php?estado=Activo')) ?>">
                <span>Listas para notificar</span>
                <strong><?= admin_c_h($stats['decisiones']) ?></strong>
                <small>Con respuesta final</small>
            </a>
            <a class="admin-metric" href="<?= admin_c_h(app_url('admin/correos.php?estado=Cancelado')) ?>">
                <span>Canceladas</span>
                <strong><?= admin_c_h($stats['cancelados']) ?></strong>
                <small>Respuestas negativas</small>
            </a>
        </section>

        <section class="admin-panel reservations-panel">
            <div class="admin-panel-head">
                <div>
                    <p class="admin-eyebrow">Bandeja de trabajo</p>
                    <h2>Solicitudes y siguiente acción</h2>
                    <span class="admin-panel-note">Revisa cada fila de izquierda a derecha: estado actual, datos del evento y acción disponible.</span>
                </div>
            </div>

            <form class="admin-user-filters admin-mail-filters" method="get" action="<?= admin_c_h(app_url('admin/correos.php')) ?>">
                <label>
                    <span>Búsqueda rápida</span>
                    <input type="search" name="q" value="<?= admin_c_h($busqueda) ?>" placeholder="Evento, instructor, coordinador o correo">
                </label>
                <label>
                    <span>Estado</span>
                    <select name="estado">
                        <option value="">Todos</option>
                        <?php foreach (['Pendiente', 'Activo', 'Cancelado', 'Finalizado'] as $estado): ?>
                            <option value="<?= admin_c_h($estado) ?>" <?= $filtro === $estado ? 'selected' : '' ?>>
                                <?= admin_c_h($estado) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit">Filtrar</button>
                <a href="<?= admin_c_h(app_url('admin/correos.php')) ?>">Limpiar</a>
            </form>

            <?php if ($notificaciones): ?>
                <div class="admin-request-tabs admin-mail-tabs" role="tablist" aria-label="Filtrar correos por paso">
                    <?php foreach ($seccionesCorreo as $claveSeccion => $seccionCorreo): ?>
                        <?php $tabActivo = $claveSeccion === $panelCorreoActivo; ?>
                        <button
                            type="button"
                            class="<?= $tabActivo ? 'active' : '' ?>"
                            role="tab"
                            aria-selected="<?= $tabActivo ? 'true' : 'false' ?>"
                            data-mail-tab="<?= admin_c_h($claveSeccion) ?>"
                        >
                            <span><?= admin_c_h($seccionCorreo['titulo']) ?></span>
                            <strong><?= admin_c_h((string)count($correosPorSeccion[$claveSeccion] ?? [])) ?></strong>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="admin-mail-board">
                <?php if (!$notificaciones): ?>
                    <article class="admin-empty-state">
                        <strong>No hay correos para mostrar.</strong>
                        <span>Cuando una solicitud necesite coordinación o respuesta final, aparecerá aquí.</span>
                    </article>
                <?php endif; ?>

                <?php foreach ($notificaciones as $notificacion): ?>
                    <?php
                    $estado = (string)$notificacion['estado'];
                    $decidida = !empty($notificacion['fecha_aprobacion']);
                    $tieneCoordinador = !empty($notificacion['coord_correo']);
                    $sinEnviar = !$tieneCoordinador && $estado === 'Pendiente';
                    $instructor = trim((string)$notificacion['instructor_nombre'] . ' ' . (string)$notificacion['instructor_apellido']);
                    $coordinador = trim((string)$notificacion['coord_nombre'] . ' ' . (string)$notificacion['coord_apellido']);
                    $fecha = new DateTime((string)$notificacion['fecha_evento']);
                    $cardSection = 'en_coordinacion';
                    if ($sinEnviar) {
                        $cardSection = 'falta_coordinador';
                    } elseif ($estado === 'Cancelado') {
                        $cardSection = 'canceladas';
                    } elseif ($decidida) {
                        $cardSection = 'por_notificar';
                    }
                    $cardClass = $sinEnviar ? 'draft' : ($decidida ? 'ready' : 'waiting');
                    if ($estado === 'Cancelado') {
                        $cardClass = 'rejected';
                    }
                    $stageTitle = (string)$seccionesCorreo[$cardSection]['titulo'];
                    $stageHelp = $sinEnviar
                        ? 'Asigna un coordinador desde Solicitudes para poder iniciar la revisión.'
                        : ($decidida ? 'Ya puedes enviar la respuesta final al instructor.' : 'La solicitud ya está en coordinación; espera la decisión o reenvía el correo si hace falta.');
                    ?>
                    <article
                        id="mail-panel-<?= admin_c_h($cardSection) ?>-<?= admin_c_h($notificacion['id_evento']) ?>"
                        class="admin-mail-card <?= admin_c_h($cardClass) ?>"
                        data-mail-panel="<?= admin_c_h($cardSection) ?>"
                        <?= $cardSection === $panelCorreoActivo ? '' : 'hidden' ?>
                    >
                        <div class="admin-mail-status">
                            <span><?= admin_c_h($stageTitle) ?></span>
                            <strong><?= admin_c_h($estado) ?></strong>
                            <small><?= admin_c_h($stageHelp) ?></small>
                        </div>

                        <div class="admin-mail-content">
                            <p class="admin-eyebrow">Evento</p>
                            <h3><?= admin_c_h($notificacion['nombre_evento']) ?></h3>
                            <p><?= admin_c_h($fecha->format('d/m/Y')) ?> - <?= admin_c_h(substr((string)$notificacion['hora_inicio'], 0, 5) . ' a ' . substr((string)$notificacion['hora_fin'], 0, 5)) ?></p>
                            <div class="admin-reservation-meta">
                                <span><?= admin_c_h($notificacion['nombre_auditorio'] . ' / Bloque ' . $notificacion['bloque']) ?></span>
                                <span>Instructor: <?= admin_c_h($instructor !== '' ? $instructor : 'Sin nombre') ?></span>
                                <span><?= admin_c_h($notificacion['instructor_correo'] ?? 'Sin correo') ?></span>
                            </div>
                        </div>

                        <div class="admin-mail-people">
                            <div>
                                <span>Instructor</span>
                                <strong><?= admin_c_h($instructor !== '' ? $instructor : 'Sin nombre') ?></strong>
                                <small><?= admin_c_h($notificacion['instructor_correo'] ?? 'Sin correo') ?></small>
                            </div>
                            <div>
                                <span>Coordinador</span>
                                <strong><?= admin_c_h($coordinador !== '' ? $coordinador : 'Sin asignar') ?></strong>
                                <small><?= admin_c_h($notificacion['coord_correo'] ?? 'Pendiente') ?></small>
                            </div>
                        </div>

                        <?php if ($sinEnviar): ?>
                            <div class="admin-mail-actions">
                                <strong>Siguiente paso</strong>
                                <a href="<?= admin_c_h(app_url('admin/solicitudes.php?q=' . urlencode((string)$notificacion['nombre_evento']))) ?>">Ir a asignar coordinador</a>
                                <small>Primero asigna coordinador; después podrás enviar o reenviar la solicitud.</small>
                            </div>
                        <?php else: ?>
                            <form class="admin-mail-actions" method="post" action="<?= admin_c_h(app_url('admin/correos.php')) ?>">
                                <strong><?= $decidida ? 'Enviar respuesta al instructor' : 'Enviar solicitud al coordinador' ?></strong>
                                <input type="hidden" name="csrf_admin_mail" value="<?= admin_c_h($_SESSION['csrf_admin_mail']) ?>">
                                <input type="hidden" name="id_evento" value="<?= admin_c_h($notificacion['id_evento']) ?>">
                                <?php if ($decidida): ?>
                                    <button type="submit" name="accion" value="notificar_instructor"
                                            data-confirm-kicker="Correo al instructor"
                                            data-confirm-title="Enviar respuesta al instructor"
                                            data-confirm-message="El instructor recibirá la decisión final de la reserva por correo."
                                            data-confirm-text="Sí, notificar">
                                        Enviar al instructor
                                    </button>
                                    <small>Destinatario: <?= admin_c_h($instructor !== '' ? $instructor : 'Instructor') ?>.</small>
                                <?php else: ?>
                                    <button type="submit" name="accion" value="reenviar_coordinador"
                                            data-confirm-kicker="Correo de coordinación"
                                            data-confirm-title="Enviar al coordinador"
                                            data-confirm-message="Se enviará la solicitud al coordinador asignado."
                                            data-confirm-text="Sí, enviar">Enviar al coordinador</button>
                                    <small>Destinatario: <?= admin_c_h($coordinador !== '' ? $coordinador : 'Coordinador asignado') ?>.</small>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </section>
</main>

<script>
document.addEventListener('click', function (event) {
    const tab = event.target.closest('[data-mail-tab]');
    if (!tab) return;

    const target = tab.getAttribute('data-mail-tab');
    document.querySelectorAll('[data-mail-tab]').forEach((button) => {
        const active = button === tab;
        button.classList.toggle('active', active);
        button.setAttribute('aria-selected', active ? 'true' : 'false');
    });

    document.querySelectorAll('[data-mail-panel]').forEach((panel) => {
        panel.hidden = panel.getAttribute('data-mail-panel') !== target;
    });
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
