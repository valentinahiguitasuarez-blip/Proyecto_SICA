<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([1]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/smtp_mailer.php';

$pageTitle = 'Solicitudes de Reserva - Administrador SICA';
$pageStyles = ['css/admin.css'];

$usuario = $_SESSION['usuario'] ?? [];
$adminName = trim((string)($usuario['nombre'] ?? 'Administrador'));
$adminMail = (string)($usuario['correo'] ?? 'admin@sica.edu.co');
$adminDocument = (int)($usuario['id_documento'] ?? 0);

function admin_s_h(string|int|null $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function admin_s_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function admin_s_scalar(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function admin_s_estado_id(PDO $pdo, string $estado): int
{
    $stmt = $pdo->prepare('SELECT id_estado FROM estado WHERE nombre_estado = :estado LIMIT 1');
    $stmt->execute([':estado' => $estado]);
    return (int)$stmt->fetchColumn();
}

function admin_s_status_class(string $estado): string
{
    return match ($estado) {
        'Activo' => 'approved',
        'Pendiente' => 'pending',
        'Cancelado' => 'rejected',
        'Finalizado' => 'finished',
        default => 'neutral',
    };
}

if (empty($_SESSION['csrf_admin_requests'])) {
    $_SESSION['csrf_admin_requests'] = bin2hex(random_bytes(32));
}

$message = $_SESSION['admin_requests_message'] ?? '';
$messageType = $_SESSION['admin_requests_message_type'] ?? 'success';
unset($_SESSION['admin_requests_message'], $_SESSION['admin_requests_message_type']);

$auditorios = [];
$estados = [];
$coordinadores = [];
try {
    $auditorios = admin_s_rows($pdo, 'SELECT id_auditorio, nombre_auditorio, bloque FROM auditorio ORDER BY nombre_auditorio ASC');
    $estados = admin_s_rows($pdo, 'SELECT id_estado, nombre_estado FROM estado ORDER BY id_estado ASC');
    $coordinadores = admin_s_rows(
        $pdo,
        "SELECT u.id_documento, u.nombre, u.apellido, u.correo
         FROM usuario u
         INNER JOIN rol r ON r.id_rol = u.id_rol
         WHERE LOWER(r.nombre_rol) LIKE '%coordinador%'
         ORDER BY u.nombre ASC, u.apellido ASC"
    );
} catch (Throwable $exception) {
    error_log('SICA admin solicitudes catalogos: ' . $exception->getMessage());
}

$estadoIds = [
    'Pendiente' => admin_s_estado_id($pdo, 'Pendiente'),
    'Activo' => admin_s_estado_id($pdo, 'Activo'),
    'Cancelado' => admin_s_estado_id($pdo, 'Cancelado'),
    'Finalizado' => admin_s_estado_id($pdo, 'Finalizado'),
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = (string)($_POST['csrf_admin_requests'] ?? '');
    $idEvento = (int)($_POST['id_evento'] ?? 0);
    $accion = (string)($_POST['accion'] ?? '');
    $observacion = trim((string)($_POST['observacion'] ?? ''));
    $idCoordinador = (int)($_POST['id_coordinador'] ?? 0);

    if (!hash_equals((string)$_SESSION['csrf_admin_requests'], $csrf)) {
        $_SESSION['admin_requests_message'] = 'La sesion expiro. Intenta de nuevo.';
        $_SESSION['admin_requests_message_type'] = 'danger';
    } elseif ($idEvento <= 0 || !in_array($accion, ['enviar_coordinador', 'notificar_instructor'], true)) {
        $_SESSION['admin_requests_message'] = 'Selecciona una accion valida.';
        $_SESSION['admin_requests_message_type'] = 'danger';
    } elseif (strlen($observacion) > 180) {
        $_SESSION['admin_requests_message'] = 'La observacion no puede superar 180 caracteres.';
        $_SESSION['admin_requests_message_type'] = 'danger';
    } else {
        try {
            $eventoStmt = $pdo->prepare(
                'SELECT e.id_evento, e.nombre_evento, e.fecha_evento, e.hora_inicio, e.hora_fin,
                        e.id_auditorio, e.descripcion, e.codigo_evento, e.id_coordinador,
                        e.fecha_aprobacion, es.nombre_estado, a.nombre_auditorio, a.bloque,
                        te.nombre_tipo, u.nombre, u.apellido, u.correo
                 FROM evento e
                 INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
                 INNER JOIN tipo_evento te ON te.id_tipo_evento = e.id_tipo_evento
                 INNER JOIN estado es ON es.id_estado = e.id_estado
                 LEFT JOIN usuario u ON u.id_documento = e.id_solicitante
                 WHERE e.id_evento = :id_evento
                 LIMIT 1'
            );
            $eventoStmt->execute([':id_evento' => $idEvento]);
            $evento = $eventoStmt->fetch();

            if (!$evento) {
                throw new RuntimeException('Solicitud no encontrada.');
            }

            if ($accion === 'enviar_coordinador') {
                if ((string)$evento['nombre_estado'] !== 'Pendiente') {
                    throw new RuntimeException('Solo puedes enviar a coordinacion solicitudes pendientes.');
                }
                if ($idCoordinador <= 0) {
                    throw new RuntimeException('Selecciona el coordinador que revisara la solicitud.');
                }

                $coordStmt = $pdo->prepare(
                    "SELECT u.id_documento, u.nombre, u.apellido, u.correo
                     FROM usuario u
                     INNER JOIN rol r ON r.id_rol = u.id_rol
                     WHERE u.id_documento = :id
                       AND LOWER(r.nombre_rol) LIKE '%coordinador%'
                     LIMIT 1"
                );
                $coordStmt->execute([':id' => $idCoordinador]);
                $coordinador = $coordStmt->fetch();
                if (!$coordinador || !filter_var((string)$coordinador['correo'], FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('El coordinador seleccionado no tiene un correo valido.');
                }

                $coordName = trim((string)$coordinador['nombre'] . ' ' . (string)$coordinador['apellido']);
                $instructorName = trim((string)$evento['nombre'] . ' ' . (string)$evento['apellido']);
                $body = "Hola {$coordName},\n\n"
                    . "El administrador {$adminName} te envia una solicitud de reserva de auditorio para revision.\n\n"
                    . "Evento: {$evento['nombre_evento']}\n"
                    . "Tipo: {$evento['nombre_tipo']}\n"
                    . "Instructor: {$instructorName}\n"
                    . "Auditorio: {$evento['nombre_auditorio']} / Bloque {$evento['bloque']}\n"
                    . "Fecha: {$evento['fecha_evento']}\n"
                    . "Hora: " . substr((string)$evento['hora_inicio'], 0, 5) . " - " . substr((string)$evento['hora_fin'], 0, 5) . "\n"
                    . "Codigo: {$evento['codigo_evento']}\n"
                    . "Descripcion: {$evento['descripcion']}\n";
                if ($observacion !== '') {
                    $body .= "Nota del administrador: {$observacion}\n";
                }
                $body .= "\nIngresa a SICA como coordinador para aprobar o cancelar la solicitud.\n\nEquipo SICA";

                if (!sica_send_mail((string)$coordinador['correo'], 'Solicitud de reserva de auditorio - SICA', $body)) {
                    throw new RuntimeException('No se pudo enviar el correo al coordinador. Revisa la configuracion SMTP.');
                }

                $update = $pdo->prepare(
                    'UPDATE evento
                     SET id_coordinador = :coordinador,
                         observacion = :observacion
                     WHERE id_evento = :id_evento'
                );
                $update->execute([
                    ':coordinador' => $idCoordinador,
                    ':observacion' => $observacion !== '' ? $observacion : null,
                    ':id_evento' => $idEvento,
                ]);

                $_SESSION['admin_requests_message'] = 'Solicitud enviada al coordinador correctamente.';
            } else {
                if (!in_array((string)$evento['nombre_estado'], ['Activo', 'Cancelado', 'Finalizado'], true)
                    || empty($evento['fecha_aprobacion'])) {
                    throw new RuntimeException('Aun no hay una decision de coordinacion para notificar.');
                }
                if (!filter_var((string)$evento['correo'], FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('El instructor no tiene un correo valido registrado.');
                }

                $instructorName = trim((string)$evento['nombre'] . ' ' . (string)$evento['apellido']);
                $estadoTexto = (string)$evento['nombre_estado'];
                $body = "Hola {$instructorName},\n\n"
                    . "Coordinacion ya reviso tu solicitud de reserva en SICA.\n\n"
                    . "Evento: {$evento['nombre_evento']}\n"
                    . "Estado actual: {$estadoTexto}\n"
                    . "Auditorio: {$evento['nombre_auditorio']} / Bloque {$evento['bloque']}\n"
                    . "Fecha: {$evento['fecha_evento']}\n"
                    . "Hora: " . substr((string)$evento['hora_inicio'], 0, 5) . " - " . substr((string)$evento['hora_fin'], 0, 5) . "\n";
                if (trim((string)$evento['observacion']) !== '') {
                    $body .= "Observacion: {$evento['observacion']}\n";
                }
                $body .= "\nGracias por usar SICA.\n\nEquipo SICA";

                if (!sica_send_mail((string)$evento['correo'], 'Respuesta a tu solicitud de auditorio - SICA', $body)) {
                    throw new RuntimeException('No se pudo enviar el correo al instructor. Revisa la configuracion SMTP.');
                }

                $_SESSION['admin_requests_message'] = 'Respuesta enviada al instructor correctamente.';
            }
            $_SESSION['admin_requests_message_type'] = 'success';
        } catch (Throwable $exception) {
            $_SESSION['admin_requests_message'] = $exception->getMessage() !== ''
                ? $exception->getMessage()
                : 'No fue posible actualizar la solicitud.';
            $_SESSION['admin_requests_message_type'] = 'danger';
            error_log('SICA admin solicitudes accion: ' . $exception->getMessage());
        }
    }

    header('Location: ' . app_url('admin/solicitudes.php'));
    exit;
}

$search = trim((string)($_GET['q'] ?? ''));
$estadoFiltro = trim((string)($_GET['estado'] ?? ''));
$auditorioFiltro = (int)($_GET['auditorio'] ?? 0);
$params = [];
$where = [];

if ($search !== '') {
    $searchColumns = [
        'e.nombre_evento',
        'e.codigo_evento',
        'u.nombre',
        'u.apellido',
        "CONCAT(u.nombre, ' ', u.apellido)",
        "CONCAT(u.apellido, ' ', u.nombre)",
        'u.correo',
    ];
    $searchParts = [];
    foreach ($searchColumns as $index => $column) {
        $param = ':search_' . $index;
        $searchParts[] = $column . ' LIKE ' . $param;
        $params[$param] = '%' . $search . '%';
    }
    $where[] = '(' . implode(' OR ', $searchParts) . ')';
}

if ($estadoFiltro !== '') {
    $where[] = 'es.nombre_estado = :estado';
    $params[':estado'] = $estadoFiltro;
}

if ($auditorioFiltro > 0) {
    $where[] = 'e.id_auditorio = :auditorio';
    $params[':auditorio'] = $auditorioFiltro;
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$solicitudes = [];
$counts = ['Pendiente' => 0, 'Activo' => 0, 'Cancelado' => 0, 'Finalizado' => 0];

try {
    foreach (admin_s_rows(
        $pdo,
        'SELECT es.nombre_estado, COUNT(*) total
         FROM evento e
         INNER JOIN estado es ON es.id_estado = e.id_estado
         GROUP BY es.nombre_estado'
    ) as $row) {
        $counts[(string)$row['nombre_estado']] = (int)$row['total'];
    }

    $solicitudes = admin_s_rows(
        $pdo,
        'SELECT e.id_evento, e.nombre_evento, e.descripcion, e.fecha_evento, e.hora_inicio, e.hora_fin,
                e.codigo_evento, e.observacion, e.fecha_aprobacion, es.nombre_estado AS estado,
                a.nombre_auditorio, a.bloque, a.capacidad, te.nombre_tipo,
                u.id_documento, u.nombre, u.apellido, u.correo,
                c.id_documento AS coord_documento, c.nombre AS coord_nombre, c.apellido AS coord_apellido, c.correo AS coord_correo
         FROM evento e
         INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
         INNER JOIN estado es ON es.id_estado = e.id_estado
         INNER JOIN tipo_evento te ON te.id_tipo_evento = e.id_tipo_evento
         LEFT JOIN usuario u ON u.id_documento = e.id_solicitante
         LEFT JOIN usuario c ON c.id_documento = e.id_coordinador' .
            $whereSql .
        ' ORDER BY
            CASE es.nombre_estado
                WHEN \'Pendiente\' THEN 1
                WHEN \'Activo\' THEN 2
                WHEN \'Cancelado\' THEN 3
                ELSE 4
            END,
            e.fecha_evento ASC,
            e.hora_inicio ASC
          LIMIT 100',
        $params
    );
} catch (Throwable $exception) {
    error_log('SICA admin solicitudes: ' . $exception->getMessage());
}

$monthLabels = [1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'];
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>

<main class="admin-dashboard">
    <aside class="admin-sidebar" aria-label="Menu del administrador">
        <a class="admin-brand" href="<?= admin_s_h(app_url('admin/index.php')) ?>">
            <span>
                <strong>SICA</strong>
                <small>Sistema Inteligente de Control de Asistencia</small>
            </span>
        </a>

        <section class="admin-profile" aria-label="Administrador activo">
            <div class="admin-avatar">AD</div>
            <div>
                <strong><?= admin_s_h($adminName) ?></strong>
                <small><?= admin_s_h($adminMail) ?></small>
                <span>En linea</span>
            </div>
        </section>

        <nav class="admin-nav">
            <a href="<?= admin_s_h(app_url('admin/index.php')) ?>"><span>PC</span>Panel de Control</a>
            <a href="<?= admin_s_h(app_url('admin/usuarios.php')) ?>"><span>US</span>Usuarios</a>
            <a class="active" href="<?= admin_s_h(app_url('admin/solicitudes.php')) ?>"><span>SR</span>Solicitudes de Reserva</a>
            <a href="<?= admin_s_h(app_url('admin/correos.php')) ?>"><span>CN</span>Correos y Notificaciones</a>
            <a href="<?= admin_s_h(app_url('admin/auditorios.php')) ?>"><span>AU</span>Auditorios</a>
            <a href="<?= admin_s_h(app_url('admin/reportes.php')) ?>"><span>RP</span>Reportes</a>
        </nav>
    </aside>

    <section class="admin-main">
        <header class="admin-topbar">
            <div>
                <p class="admin-eyebrow">Reservas de auditorio</p>
                <h1>Solicitudes de reserva</h1>
                <span>Bandeja de revision para asignar coordinacion y notificar respuestas.</span>
            </div>
            <div class="admin-top-actions">
                <a href="<?= admin_s_h(app_url('admin/index.php')) ?>">Panel <strong>IN</strong></a>
                <a class="admin-logout" href="<?= admin_s_h(app_url('login/logout.php')) ?>">Cerrar sesion</a>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <div class="admin-alert <?= admin_s_h($messageType) ?>">
                <?= admin_s_h($message) ?>
            </div>
        <?php endif; ?>

        <section class="admin-metrics reservation-metrics" aria-label="Resumen de solicitudes">
            <article class="admin-metric">
                <span>Pendientes</span>
                <strong><?= admin_s_h($counts['Pendiente'] ?? 0) ?></strong>
                <small>Esperando revision</small>
            </article>
            <article class="admin-metric">
                <span>Aprobadas</span>
                <strong><?= admin_s_h($counts['Activo'] ?? 0) ?></strong>
                <small>Reservas activas</small>
            </article>
            <article class="admin-metric">
                <span>Finalizadas</span>
                <strong><?= admin_s_h($counts['Finalizado'] ?? 0) ?></strong>
                <small>Eventos cerrados</small>
            </article>
        </section>

        <section class="admin-panel reservations-panel">
            <div class="admin-panel-head">
                <div>
                    <p class="admin-eyebrow">Revision</p>
                    <h2>Solicitudes registradas</h2>
                </div>
            </div>

            <form class="admin-user-filters" method="get" action="<?= admin_s_h(app_url('admin/solicitudes.php')) ?>">
                <label>
                    <span>Busqueda rapida</span>
                    <input type="search" name="q" value="<?= admin_s_h($search) ?>" placeholder="Evento, codigo, instructor o correo">
                </label>
                <label>
                    <span>Estado</span>
                    <select name="estado">
                        <option value="">Todos</option>
                        <?php foreach (['Pendiente', 'Activo', 'Cancelado', 'Finalizado'] as $estado): ?>
                            <option value="<?= admin_s_h($estado) ?>" <?= $estadoFiltro === $estado ? 'selected' : '' ?>>
                                <?= admin_s_h($estado) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Auditorio</span>
                    <select name="auditorio">
                        <option value="0">Todos</option>
                        <?php foreach ($auditorios as $auditorio): ?>
                            <option value="<?= admin_s_h($auditorio['id_auditorio']) ?>" <?= $auditorioFiltro === (int)$auditorio['id_auditorio'] ? 'selected' : '' ?>>
                                <?= admin_s_h($auditorio['nombre_auditorio']) ?> / Bloque <?= admin_s_h($auditorio['bloque']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit">Filtrar</button>
                <a href="<?= admin_s_h(app_url('admin/solicitudes.php')) ?>">Limpiar</a>
            </form>

            <div class="admin-reservation-list">
                <?php if (!$solicitudes): ?>
                    <article class="admin-empty-state">
                        <strong>No hay solicitudes para mostrar.</strong>
                        <span>Cuando los instructores separen auditorios, apareceran aqui.</span>
                    </article>
                <?php endif; ?>

                <?php foreach ($solicitudes as $solicitud): ?>
                    <?php
                    $fecha = new DateTime((string)$solicitud['fecha_evento']);
                    $estado = (string)$solicitud['estado'];
                    $statusClass = admin_s_status_class($estado);
                    $solicitante = trim((string)$solicitud['nombre'] . ' ' . (string)$solicitud['apellido']);
                    $solicitante = $solicitante !== '' ? $solicitante : 'Solicitante SICA';
                    $coordinadorAsignado = trim((string)($solicitud['coord_nombre'] ?? '') . ' ' . (string)($solicitud['coord_apellido'] ?? ''));
                    $coordinadorAsignado = $coordinadorAsignado !== '' ? $coordinadorAsignado : 'Sin coordinador asignado';
                    $enviadoCoordinacion = !empty($solicitud['coord_documento']);
                    $tieneDecision = $enviadoCoordinacion && !empty($solicitud['fecha_aprobacion']);
                    ?>
                    <article class="admin-reservation-card <?= admin_s_h($statusClass) ?>">
                        <time>
                            <strong><?= admin_s_h($fecha->format('d')) ?></strong>
                            <span><?= admin_s_h($monthLabels[(int)$fecha->format('n')]) ?></span>
                        </time>

                        <div class="admin-reservation-main">
                            <div class="admin-reservation-title">
                                <div>
                                    <span class="admin-reservation-type"><?= admin_s_h($solicitud['nombre_tipo']) ?></span>
                                    <h3><?= admin_s_h($solicitud['nombre_evento']) ?></h3>
                                </div>
                                <em><?= admin_s_h($estado) ?></em>
                            </div>
                    <p><?= admin_s_h($solicitud['descripcion'] ?? 'Solicitud de reserva de auditorio.') ?></p>
                    <div class="admin-reservation-meta">
                        <span><?= admin_s_h(substr((string)$solicitud['hora_inicio'], 0, 5) . ' - ' . substr((string)$solicitud['hora_fin'], 0, 5)) ?></span>
                        <span><?= admin_s_h($solicitud['nombre_auditorio'] . ' / Bloque ' . $solicitud['bloque']) ?></span>
                                <span><?= admin_s_h($solicitante) ?></span>
                            </div>
                        </div>

                        <details class="admin-reservation-review">
                            <summary>Revisar</summary>
                            <div class="admin-reservation-review-body">
                                <div class="admin-reservation-extra">
                                    <span>Capacidad <strong><?= admin_s_h($solicitud['capacidad']) ?></strong></span>
                                    <span>Codigo <strong><?= admin_s_h($solicitud['codigo_evento']) ?></strong></span>
                                </div>
                            <div class="admin-requester">
                                <strong><?= admin_s_h($solicitante) ?></strong>
                                <small><?= admin_s_h($solicitud['correo'] ?? 'Correo no registrado') ?></small>
                            </div>
                            <div class="admin-observation">
                                <strong>Coordinacion:</strong>
                                <?= admin_s_h($coordinadorAsignado) ?>
                                <?php if (!empty($solicitud['coord_correo'])): ?>
                                    <small><?= admin_s_h($solicitud['coord_correo']) ?></small>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($solicitud['observacion'])): ?>
                                <div class="admin-observation">
                                    <?= admin_s_h($solicitud['observacion']) ?>
                                </div>
                            <?php endif; ?>

                        <form class="admin-reservation-actions" method="post" action="<?= admin_s_h(app_url('admin/solicitudes.php')) ?>">
                            <div class="admin-reservation-actions-head">
                                <strong>Gestion de la solicitud</strong>
                                <button type="button" class="admin-review-close" data-close-review>Cerrar</button>
                            </div>
                            <input type="hidden" name="csrf_admin_requests" value="<?= admin_s_h($_SESSION['csrf_admin_requests']) ?>">
                            <input type="hidden" name="id_evento" value="<?= admin_s_h($solicitud['id_evento']) ?>">
                            <?php if ($estado === 'Pendiente' && !$tieneDecision): ?>
                                <label>
                                    <span>Coordinador</span>
                                    <select name="id_coordinador" <?= !$coordinadores ? 'disabled' : '' ?>>
                                        <option value="0">Selecciona coordinador</option>
                                        <?php foreach ($coordinadores as $coordinador): ?>
                                            <option value="<?= admin_s_h($coordinador['id_documento']) ?>" <?= (int)$solicitud['coord_documento'] === (int)$coordinador['id_documento'] ? 'selected' : '' ?>>
                                                <?= admin_s_h(trim((string)$coordinador['nombre'] . ' ' . (string)$coordinador['apellido'])) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span>Indicaciones</span>
                                    <textarea name="observacion" maxlength="180"><?= admin_s_h($solicitud['observacion'] ?? '') ?></textarea>
                                </label>
                            <?php else: ?>
                                <input type="hidden" name="observacion" value="<?= admin_s_h($solicitud['observacion'] ?? '') ?>">
                            <?php endif; ?>
                            <div>
                                <?php if ($estado === 'Pendiente'): ?>
                                    <small class="admin-flow-note">
                                        <?= $enviadoCoordinacion ? 'Enviado a coordinacion. Esperando respuesta.' : 'Pendiente por enviar a coordinacion.' ?>
                                    </small>
                                    <button type="submit" name="accion" value="enviar_coordinador"
                                            data-confirm-kicker="Solicitud de reserva"
                                            data-confirm-title="<?= $enviadoCoordinacion ? 'Reenviar a coordinacion' : 'Enviar a coordinacion' ?>"
                                            data-confirm-message="Se enviara la solicitud al coordinador seleccionado para que registre su decision."
                                            data-confirm-text="<?= $enviadoCoordinacion ? 'Si, reenviar' : 'Si, enviar' ?>">
                                        <?= $enviadoCoordinacion ? 'Reenviar correo' : 'Enviar a coordinador' ?>
                                    </button>
                                <?php elseif ($tieneDecision): ?>
                                    <small class="admin-flow-note">Coordinacion ya respondio. Informa al instructor.</small>
                                    <button type="submit" name="accion" value="notificar_instructor"
                                            data-confirm-kicker="Notificacion"
                                            data-confirm-title="Notificar instructor"
                                            data-confirm-message="El instructor recibira por correo la decision registrada por coordinacion."
                                            data-confirm-text="Si, notificar">Notificar instructor</button>
                                <?php else: ?>
                                    <small class="admin-flow-note">Solicitud cerrada.</small>
                                <?php endif; ?>
                            </div>
                        </form>
                            </div>
                        </details>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </section>
</main>

<script>
document.addEventListener('click', function (event) {
    const closeButton = event.target.closest('[data-close-review]');
    if (!closeButton) return;
    const review = closeButton.closest('.admin-reservation-review');
    if (review) review.open = false;
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
