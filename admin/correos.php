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
        $_SESSION['admin_mail_message'] = 'La sesion expiro. Intenta de nuevo.';
        $_SESSION['admin_mail_message_type'] = 'danger';
    } elseif ($idEvento <= 0 || !in_array($accion, ['reenviar_coordinador', 'notificar_instructor'], true)) {
        $_SESSION['admin_mail_message'] = 'Selecciona una notificacion valida.';
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
                throw new RuntimeException('No se encontro la solicitud.');
            }

            if ($accion === 'reenviar_coordinador') {
                if (empty($evento['coord_correo']) || !filter_var((string)$evento['coord_correo'], FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('La solicitud no tiene coordinador con correo valido.');
                }
                $payload = admin_c_mail_payload($evento, 'coordinador', $adminName);
            } else {
                if (empty($evento['fecha_aprobacion'])) {
                    throw new RuntimeException('Aun no hay decision de coordinacion para enviar al instructor.');
                }
                if (empty($evento['instructor_correo']) || !filter_var((string)$evento['instructor_correo'], FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('El instructor no tiene correo valido registrado.');
                }
                $payload = admin_c_mail_payload($evento, 'instructor', $adminName);
            }

            if (!sica_send_mail($payload['to'], $payload['subject'], $payload['body'])) {
                throw new RuntimeException('No se pudo enviar el correo. Revisa la configuracion SMTP.');
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
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>

<main class="admin-dashboard">
    <aside class="admin-sidebar" aria-label="Menu del administrador">
        <a class="admin-brand" href="<?= admin_c_h(app_url('admin/index.php')) ?>">
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
                <span>En linea</span>
            </div>
        </section>

        <nav class="admin-nav">
            <a href="<?= admin_c_h(app_url('admin/index.php')) ?>"><span>PC</span>Panel de Control</a>
            <a href="<?= admin_c_h(app_url('admin/usuarios.php')) ?>"><span>US</span>Usuarios</a>
            <a href="<?= admin_c_h(app_url('admin/solicitudes.php')) ?>"><span>SR</span>Solicitudes de Reserva</a>
            <a class="active" href="<?= admin_c_h(app_url('admin/correos.php')) ?>"><span>CN</span>Correos y Notificaciones</a>
            <a href="<?= admin_c_h(app_url('admin/auditorios.php')) ?>"><span>AU</span>Auditorios</a>
            <a href="<?= admin_c_h(app_url('admin/reportes.php')) ?>"><span>RP</span>Reportes</a>
        </nav>
    </aside>

    <section class="admin-main">
        <header class="admin-topbar">
            <div>
                <p class="admin-eyebrow">Comunicaciones</p>
                <h1>Correos y notificaciones</h1>
                <span>Gestiona la ruta completa: solicitud del instructor, envio a coordinacion y respuesta final.</span>
            </div>
            <div class="admin-top-actions">
                <a href="<?= admin_c_h(app_url('admin/solicitudes.php')) ?>">Reservas <strong>SR</strong></a>
                <a class="admin-logout" href="<?= admin_c_h(app_url('login/logout.php')) ?>">Cerrar sesion</a>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <div class="admin-alert <?= admin_c_h($messageType) ?>">
                <?= admin_c_h($message) ?>
            </div>
        <?php endif; ?>

        <section class="admin-metrics reservation-metrics" aria-label="Resumen de correos">
            <article class="admin-metric">
                <span>Por enviar</span>
                <strong><?= admin_c_h($stats['sin_enviar']) ?></strong>
                <small>Pendientes de coordinador</small>
            </article>
            <article class="admin-metric">
                <span>En coordinacion</span>
                <strong><?= admin_c_h($stats['pendientes']) ?></strong>
                <small>Esperando decision</small>
            </article>
            <article class="admin-metric">
                <span>Por notificar</span>
                <strong><?= admin_c_h($stats['decisiones']) ?></strong>
                <small>Con respuesta final</small>
            </article>
            <article class="admin-metric">
                <span>Canceladas</span>
                <strong><?= admin_c_h($stats['cancelados']) ?></strong>
                <small>Respuestas negativas</small>
            </article>
        </section>

        <section class="admin-panel reservations-panel">
            <div class="admin-panel-head">
                <div>
                    <p class="admin-eyebrow">Bandeja SICA</p>
                    <h2>Ruta de correos</h2>
                </div>
            </div>

            <form class="admin-user-filters admin-mail-filters" method="get" action="<?= admin_c_h(app_url('admin/correos.php')) ?>">
                <label>
                    <span>Busqueda rapida</span>
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

            <div class="admin-mail-board">
                <?php if (!$notificaciones): ?>
                    <article class="admin-empty-state">
                        <strong>No hay correos para mostrar.</strong>
                        <span>Cuando una solicitud se envie a coordinacion, aparecera aqui.</span>
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
                    $cardClass = $sinEnviar ? 'draft' : ($decidida ? 'ready' : 'waiting');
                    ?>
                    <article class="admin-mail-card <?= admin_c_h($cardClass) ?>">
                        <div class="admin-mail-flow complete">
                            <span>1</span>
                            <div>
                                <strong>Solicitud recibida</strong>
                                <small><?= admin_c_h($instructor !== '' ? $instructor : 'Instructor') ?> - <?= admin_c_h($notificacion['instructor_correo'] ?? 'Sin correo') ?></small>
                            </div>
                        </div>
                        <div class="admin-mail-flow <?= $tieneCoordinador ? 'complete' : '' ?>">
                            <span>2</span>
                            <div>
                                <strong><?= $tieneCoordinador ? 'Enviada a coordinacion' : 'Falta enviar a coordinacion' ?></strong>
                                <small><?= $tieneCoordinador ? admin_c_h(($coordinador !== '' ? $coordinador : 'Coordinador') . ' - ' . (string)$notificacion['coord_correo']) : 'Asigna coordinador desde solicitudes' ?></small>
                            </div>
                        </div>
                        <div class="admin-mail-flow <?= $decidida ? 'complete' : '' ?>">
                            <span>3</span>
                            <div>
                                <strong><?= $decidida ? 'Decision lista' : 'Esperando decision' ?></strong>
                                <small><?= admin_c_h($estado) ?><?= $decidida ? ' - ' . admin_c_h((string)$notificacion['fecha_aprobacion']) : '' ?></small>
                            </div>
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
                        <?php if ($sinEnviar): ?>
                            <div class="admin-mail-actions">
                                <a href="<?= admin_c_h(app_url('admin/solicitudes.php?q=' . urlencode((string)$notificacion['nombre_evento']))) ?>">Asignar coordinador</a>
                                <small>Primero se envia a coordinacion.</small>
                            </div>
                        <?php else: ?>
                            <form class="admin-mail-actions" method="post" action="<?= admin_c_h(app_url('admin/correos.php')) ?>">
                                <input type="hidden" name="csrf_admin_mail" value="<?= admin_c_h($_SESSION['csrf_admin_mail']) ?>">
                                <input type="hidden" name="id_evento" value="<?= admin_c_h($notificacion['id_evento']) ?>">
                                <button type="submit" name="accion" value="reenviar_coordinador"
                                        data-confirm-kicker="Correo de coordinacion"
                                        data-confirm-title="Reenviar a coordinador"
                                        data-confirm-message="Se enviara nuevamente la solicitud al coordinador asignado."
                                        data-confirm-text="Si, reenviar">Reenviar a coordinador</button>
                                <button type="submit" name="accion" value="notificar_instructor" <?= !$decidida ? 'disabled' : '' ?>
                                        data-confirm-kicker="Correo al instructor"
                                        data-confirm-title="Notificar instructor"
                                        data-confirm-message="El instructor recibira la decision final de la reserva por correo."
                                        data-confirm-text="Si, notificar">
                                    Notificar instructor
                                </button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </section>
</main>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
