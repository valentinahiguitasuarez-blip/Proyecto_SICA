<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([4]);
require_once __DIR__ . '/../config/conexion.php';

$pageTitle = 'Eventos del Aprendiz - SICA';
$pageStyles = ['css/aprendiz.css'];

$usuario = $_SESSION['usuario'] ?? [];
$idDocumento = (int)($usuario['id_documento'] ?? 0);
$nombre = trim((string)($usuario['nombre'] ?? 'Aprendiz'));
$apellido = trim((string)($usuario['apellido'] ?? ''));
$nombreCompleto = trim($nombre . ' ' . $apellido);
$nombreCompleto = $nombreCompleto !== '' ? $nombreCompleto : 'Aprendiz SICA';
$iniciales = mb_strtoupper(mb_substr($nombre, 0, 1, 'UTF-8') . mb_substr($apellido, 0, 1, 'UTF-8'), 'UTF-8');
$iniciales = $iniciales !== '' ? $iniciales : 'A';
$fichaAprendiz = 'Sin ficha';
$programaAprendiz = 'Programa no asignado';
$fotoPerfil = (string)($usuario['foto_perfil'] ?? '');
$eventMessage = $_SESSION['event_message'] ?? '';
$eventMessageType = $_SESSION['event_message_type'] ?? 'success';
unset($_SESSION['event_message'], $_SESSION['event_message_type']);

if (empty($_SESSION['csrf_eventos'])) {
    $_SESSION['csrf_eventos'] = bin2hex(random_bytes(32));
}

try {
    $perfilStmt = $pdo->prepare(
        'SELECT u.id_ficha, u.foto_perfil, p.nombre_programa
         FROM usuario u
         LEFT JOIN ficha f ON f.id_ficha = u.id_ficha
         LEFT JOIN programa p ON p.id_programa = f.id_programa
         WHERE u.id_documento = :id_documento
         LIMIT 1'
    );
    $perfilStmt->execute([':id_documento' => $idDocumento]);
    $perfilAprendiz = $perfilStmt->fetch();

    if ($perfilAprendiz) {
        $fichaAprendiz = !empty($perfilAprendiz['id_ficha']) ? (string)$perfilAprendiz['id_ficha'] : $fichaAprendiz;
        $programaAprendiz = !empty($perfilAprendiz['nombre_programa']) ? (string)$perfilAprendiz['nombre_programa'] : $programaAprendiz;
        $fotoPerfil = !empty($perfilAprendiz['foto_perfil']) ? (string)$perfilAprendiz['foto_perfil'] : $fotoPerfil;
    }
} catch (Throwable $exception) {
    error_log('SICA: no se pudo cargar el perfil del aprendiz: ' . $exception->getMessage());
}

$eventosAprendiz = [];
try {
    $eventosStmt = $pdo->prepare(
        'SELECT e.id_evento, e.nombre_evento, e.descripcion, e.fecha_evento, e.hora_inicio, e.hora_fin,
                es.nombre_estado AS estado, a.nombre_auditorio, a.bloque, a.capacidad, t.nombre_tipo,
                pr.id_preregistro, pr.asistencia, c.id_certificado, c.ruta_certificado
         FROM evento e
         INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
         INNER JOIN tipo_evento t ON t.id_tipo_evento = e.id_tipo_evento
         INNER JOIN estado es ON es.id_estado = e.id_estado
         LEFT JOIN preregistro pr ON pr.id_evento = e.id_evento AND pr.id_documento = :id_documento
         LEFT JOIN certificado c ON c.id_preregistro = pr.id_preregistro
         WHERE es.nombre_estado IN (\'Activo\', \'Finalizado\')
         ORDER BY e.fecha_evento DESC, e.hora_inicio DESC'
    );
    $eventosStmt->execute([':id_documento' => $idDocumento]);
    $eventosAprendiz = $eventosStmt->fetchAll();
} catch (Throwable $exception) {
    error_log('SICA: no se pudieron cargar los eventos del aprendiz: ' . $exception->getMessage());
}
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>

<main class="apprentice-dashboard">
    <aside class="apprentice-sidebar" aria-label="Menu del aprendiz">
        <a class="apprentice-brand" href="<?= htmlspecialchars(app_url('aprendiz/index.php'), ENT_QUOTES, 'UTF-8') ?>">
            <span>
                <strong>SICA</strong>
                <small>Registro de asistencia</small>
            </span>
        </a>

        <a class="apprentice-person" href="<?= htmlspecialchars(app_url('aprendiz/perfil.php'), ENT_QUOTES, 'UTF-8') ?>" aria-label="Ver perfil del aprendiz">
            <div class="apprentice-person-avatar">
                <?php if ($fotoPerfil !== ''): ?>
                    <img src="<?= htmlspecialchars(app_url($fotoPerfil), ENT_QUOTES, 'UTF-8') ?>" alt="">
                <?php else: ?>
                    <?= htmlspecialchars($iniciales, ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
            </div>
            <div>
                <strong><?= htmlspecialchars($nombreCompleto, ENT_QUOTES, 'UTF-8') ?></strong>
                <small><?= htmlspecialchars((string)($usuario['correo'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
            </div>
        </a>

        <nav class="apprentice-nav">
            <a href="<?= htmlspecialchars(app_url('aprendiz/index.php'), ENT_QUOTES, 'UTF-8') ?>">
                <span aria-hidden="true">IN</span>
                Dashboard
            </a>
            <a class="active" href="<?= htmlspecialchars(app_url('aprendiz/eventos.php'), ENT_QUOTES, 'UTF-8') ?>">
                <span aria-hidden="true">EV</span>
                Eventos
            </a>
            <a href="<?= htmlspecialchars(app_url('aprendiz/preregistro.php'), ENT_QUOTES, 'UTF-8') ?>">
                <span aria-hidden="true">PR</span>
                Pre-registro
            </a>
            <a href="<?= htmlspecialchars(app_url('aprendiz/certificados.php'), ENT_QUOTES, 'UTF-8') ?>">
                <span aria-hidden="true">CE</span>
                Certificados
            </a>
        </nav>
    </aside>

    <section class="apprentice-main">
        <header class="apprentice-topbar">
            <div>
                <p class="eyebrow">Panel de eventos</p>
                <h1>Eventos disponibles</h1>
                <span>Revisa el auditorio y realiza tu pre-registro desde esta pantalla.</span>
            </div>

            <a class="top-logout" href="<?= htmlspecialchars(app_url('login/logout.php'), ENT_QUOTES, 'UTF-8') ?>">
                <span aria-hidden="true">SL</span>
                Cerrar sesion
            </a>
        </header>

        <section class="event-gallery" aria-label="Galeria de auditorios">
            <div id="eventAuditoriumCarousel" class="carousel slide event-carousel" data-bs-ride="carousel" data-bs-interval="5200">
                <div class="carousel-indicators">
                    <button type="button" data-bs-target="#eventAuditoriumCarousel" data-bs-slide-to="0" class="active" aria-current="true" aria-label="Sala de juntas"></button>
                    <button type="button" data-bs-target="#eventAuditoriumCarousel" data-bs-slide-to="1" aria-label="Auditorio principal"></button>
                    <button type="button" data-bs-target="#eventAuditoriumCarousel" data-bs-slide-to="2" aria-label="Auditorio en capacitacion"></button>
                    <button type="button" data-bs-target="#eventAuditoriumCarousel" data-bs-slide-to="3" aria-label="Auditorio en evento"></button>
                </div>

                <div class="carousel-inner">
                    <article class="carousel-item active">
                        <img src="<?= htmlspecialchars(app_url('img/eventos/sala-juntas.jpeg'), ENT_QUOTES, 'UTF-8') ?>" alt="Sala de juntas SICA">
                        <div class="event-carousel-caption">
                            <span>Sala de juntas</span>
                            <strong>Espacios para coordinacion y preparacion de eventos.</strong>
                        </div>
                    </article>
                    <article class="carousel-item">
                        <img src="<?= htmlspecialchars(app_url('img/eventos/auditorio-3.jpeg'), ENT_QUOTES, 'UTF-8') ?>" alt="Auditorio principal con aprendices">
                        <div class="event-carousel-caption">
                            <span>Auditorio principal</span>
                            <strong>Eventos academicos con asistencia masiva.</strong>
                        </div>
                    </article>
                    <article class="carousel-item">
                        <img src="<?= htmlspecialchars(app_url('img/eventos/auditorio-2.jpeg'), ENT_QUOTES, 'UTF-8') ?>" alt="Auditorio durante una capacitacion">
                        <div class="event-carousel-caption">
                            <span>Capacitaciones</span>
                            <strong>Encuentros formativos y charlas institucionales.</strong>
                        </div>
                    </article>
                    <article class="carousel-item">
                        <img src="<?= htmlspecialchars(app_url('img/eventos/auditorio-1.jpeg'), ENT_QUOTES, 'UTF-8') ?>" alt="Auditorio durante evento institucional">
                        <div class="event-carousel-caption">
                            <span>Eventos SICA</span>
                            <strong>Pre-registro, asistencia y certificados en un solo flujo.</strong>
                        </div>
                    </article>
                </div>

                <button class="carousel-control-prev" type="button" data-bs-target="#eventAuditoriumCarousel" data-bs-slide="prev" aria-label="Imagen anterior">
                    <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                </button>
                <button class="carousel-control-next" type="button" data-bs-target="#eventAuditoriumCarousel" data-bs-slide="next" aria-label="Imagen siguiente">
                    <span class="carousel-control-next-icon" aria-hidden="true"></span>
                </button>
            </div>
        </section>

        <section class="events-panel standalone-events" aria-label="Eventos disponibles">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Eventos</p>
                    <h2>Auditorios programados</h2>
                    <span>El responsable del evento genera el codigo de ingreso; tu solo haces el pre-registro.</span>
                </div>
            </div>

            <?php if ($eventMessage !== ''): ?>
                <div class="event-alert <?= htmlspecialchars($eventMessageType, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($eventMessage, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <div class="event-list">
                <?php if (!$eventosAprendiz): ?>
                    <article class="event-empty">
                        <strong>No hay eventos disponibles.</strong>
                        <span>Cuando coordinacion programe eventos, apareceran aqui.</span>
                    </article>
                <?php endif; ?>

                <?php foreach ($eventosAprendiz as $evento): ?>
                    <?php
                    $fechaEvento = new DateTime((string)$evento['fecha_evento']);
                    $estaRegistrado = !empty($evento['id_preregistro']);
                    $esRealizado = (string)$evento['estado'] === 'Finalizado';
                    $asistencia = (string)($evento['asistencia'] ?? 'Pendiente');
                    $estadoClase = $estaRegistrado ? 'registered' : 'open';
                    if ($esRealizado) {
                        $estadoClase = 'closed';
                    }
                    ?>
                    <article class="event-card <?= htmlspecialchars($estadoClase, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="event-date">
                            <strong><?= htmlspecialchars($fechaEvento->format('d'), ENT_QUOTES, 'UTF-8') ?></strong>
                            <span><?= htmlspecialchars($fechaEvento->format('M'), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="event-body">
                            <div class="event-title-row">
                                <div>
                                    <span class="event-type"><?= htmlspecialchars((string)$evento['nombre_tipo'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <h3><?= htmlspecialchars((string)$evento['nombre_evento'], ENT_QUOTES, 'UTF-8') ?></h3>
                                </div>
                            </div>
                            <p><?= htmlspecialchars((string)($evento['descripcion'] ?? 'Evento programado por SICA.'), ENT_QUOTES, 'UTF-8') ?></p>
                            <div class="event-meta">
                                <span><?= htmlspecialchars(substr((string)$evento['hora_inicio'], 0, 5) . ' - ' . substr((string)$evento['hora_fin'], 0, 5), ENT_QUOTES, 'UTF-8') ?></span>
                                <span><?= htmlspecialchars((string)$evento['nombre_auditorio'] . ' / Bloque ' . (string)$evento['bloque'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span><?= htmlspecialchars('Capacidad ' . (string)$evento['capacidad'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        </div>
                        <div class="event-action">
                            <div class="event-action-state <?= htmlspecialchars($estadoClase, ENT_QUOTES, 'UTF-8') ?>">
                                <small>Estado</small>
                                <strong><?= htmlspecialchars((string)$evento['estado'], ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                            <?php if ($estaRegistrado): ?>
                                <span class="registered-pill">Pre-registrado</span>
                                <?php if (!empty($evento['id_certificado'])): ?>
                                    <a href="<?= htmlspecialchars(app_url('aprendiz/descargar_certificado.php?id=' . (int)$evento['id_certificado']), ENT_QUOTES, 'UTF-8') ?>">Certificado</a>
                                <?php endif; ?>
                            <?php elseif (!$esRealizado): ?>
                                <a href="<?= htmlspecialchars(app_url('aprendiz/preregistro.php?evento=' . (string)$evento['id_evento']), ENT_QUOTES, 'UTF-8') ?>">Pre-registrarme</a>
                            <?php else: ?>
                                <span class="registered-pill muted">Finalizado</span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </section>
</main>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
