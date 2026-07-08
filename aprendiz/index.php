<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([4]);
require_once __DIR__ . '/../config/conexion.php';

$pageTitle = 'Panel del Aprendiz - SICA';
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
try {
    $perfilStmt = $pdo->prepare(
        'SELECT u.id_ficha, u.foto_perfil, p.nombre_programa
         FROM usuario u
         LEFT JOIN ficha f ON f.id_ficha = u.id_ficha
         LEFT JOIN programa p ON p.id_programa = f.id_programa
         WHERE u.id_documento = :id_documento
         LIMIT 1'
    );
    $perfilStmt->execute([
        ':id_documento' => $usuario['id_documento'] ?? 0,
    ]);
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

$eventosDisponibles = count(array_filter($eventosAprendiz, static fn(array $evento): bool => empty($evento['id_preregistro']) && (string)$evento['estado'] !== 'Finalizado'));
$asistenciasConfirmadas = count(array_filter($eventosAprendiz, static fn(array $evento): bool => !empty($evento['id_preregistro']) && (string)($evento['asistencia'] ?? 'Pendiente') !== 'Pendiente'));
$certificadosListos = count(array_filter($eventosAprendiz, static fn(array $evento): bool => !empty($evento['ruta_certificado'])));
$preregistrosPendientes = count(array_filter($eventosAprendiz, static fn(array $evento): bool => !empty($evento['id_preregistro']) && (string)($evento['asistencia'] ?? 'Pendiente') === 'Pendiente'));
$eventosDestacados = array_values(array_filter(
    $eventosAprendiz,
    static fn(array $evento): bool => (string)$evento['estado'] !== 'Finalizado'
));
usort(
    $eventosDestacados,
    static fn(array $a, array $b): int => strcmp((string)$a['fecha_evento'] . (string)$a['hora_inicio'], (string)$b['fecha_evento'] . (string)$b['hora_inicio'])
);
$eventosDestacados = array_slice($eventosDestacados, 0, 3);
$certificadosDestacados = array_values(array_filter(
    $eventosAprendiz,
    static fn(array $evento): bool => !empty($evento['id_certificado'])
));
$certificadosDestacados = array_slice($certificadosDestacados, 0, 2);
$eventosCercanos = array_slice($eventosDestacados, 0, 3);
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
            <a class="active" href="#dashboard">
                <span aria-hidden="true">IN</span>
                Dashboard
            </a>
            <a href="<?= htmlspecialchars(app_url('aprendiz/eventos.php'), ENT_QUOTES, 'UTF-8') ?>">
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

    <section class="apprentice-main" id="dashboard">
        <header class="apprentice-topbar">
            <div>
                <p class="eyebrow">Panel personal</p>
                <h1>Hola, <?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?></h1>
                <span>Consulta eventos, pre-registrate y descarga tus certificados.</span>
            </div>

            <a class="top-logout" href="<?= htmlspecialchars(app_url('login/logout.php'), ENT_QUOTES, 'UTF-8') ?>">
                <span aria-hidden="true">SL</span>
                Cerrar sesion
            </a>
        </header>

        <section class="focus-band" aria-label="Resumen principal">
            <div class="focus-copy">
                <p class="eyebrow">Acceso al auditorio</p>
                <h2>Tu proximo evento ya tiene ruta de ingreso</h2>
                <p>Pre-registrate desde SICA, llega al auditorio y confirma tu asistencia escaneando el codigo del evento.</p>
                <div class="focus-actions">
                    <a href="<?= htmlspecialchars(app_url('aprendiz/eventos.php'), ENT_QUOTES, 'UTF-8') ?>" class="primary-action">Ver eventos</a>
                </div>
            </div>

            <div class="learning-orbit" aria-label="Ruta de asistencia al evento">
                <span class="orbit-line"></span>
                <span class="orbit-node node-one">Pre-registro</span>
                <span class="orbit-node node-two">Ingreso</span>
                <span class="orbit-node node-three">Certificado</span>
                <div class="orbit-core">
                    <strong>IN</strong>
                    <small>Evento</small>
                </div>
            </div>
        </section>

        <section class="metric-grid" aria-label="Indicadores del aprendiz">
            <article class="metric-card blue">
                <span>Eventos disponibles</span>
                <strong><?= htmlspecialchars((string)$eventosDisponibles, ENT_QUOTES, 'UTF-8') ?></strong>
                <small>Abiertos para pre-registro</small>
                <em>Explorar</em>
            </article>
            <article class="metric-card green">
                <span>Asistencias confirmadas</span>
                <strong><?= htmlspecialchars((string)$asistenciasConfirmadas, ENT_QUOTES, 'UTF-8') ?></strong>
                <small>Confirmadas en el auditorio</small>
                <em>Historial</em>
            </article>
            <article class="metric-card violet">
                <span>Certificados</span>
                <strong><?= htmlspecialchars((string)$certificadosListos, ENT_QUOTES, 'UTF-8') ?></strong>
                <small><?= htmlspecialchars($certificadosListos === 1 ? 'Listo para descargar' : 'Listos para descargar', ENT_QUOTES, 'UTF-8') ?></small>
                <em>Descargar</em>
            </article>
            <article class="metric-card amber">
                <span>Pre-registros</span>
                <strong><?= htmlspecialchars((string)$preregistrosPendientes, ENT_QUOTES, 'UTF-8') ?></strong>
                <small><?= htmlspecialchars($preregistrosPendientes === 1 ? 'Evento pendiente por asistir' : 'Eventos pendientes por asistir', ENT_QUOTES, 'UTF-8') ?></small>
                <em>Ver cupo</em>
            </article>
        </section>

        <section class="events-panel dashboard-events" aria-label="Eventos para el aprendiz">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Eventos</p>
                    <h2>Eventos abiertos para ti</h2>
                    <span>Elige un evento, revisa el auditorio y completa tu pre-registro.</span>
                </div>
                <a class="section-link" href="<?= htmlspecialchars(app_url('aprendiz/eventos.php'), ENT_QUOTES, 'UTF-8') ?>">Ver todos</a>
            </div>

            <div class="event-list compact-events">
                <?php if (!$eventosDestacados): ?>
                    <article class="event-empty">
                        <strong>No hay eventos abiertos en este momento.</strong>
                        <span>Cuando se apruebe un evento para auditorio, lo veras aqui.</span>
                    </article>
                <?php endif; ?>

                <?php foreach ($eventosDestacados as $evento): ?>
                    <?php
                    $fechaEvento = new DateTime((string)$evento['fecha_evento']);
                    $estaRegistrado = !empty($evento['id_preregistro']);
                    $estadoClase = $estaRegistrado ? 'registered' : 'open';
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
                                <span class="event-state"><?= htmlspecialchars($estaRegistrado ? 'Pre-registrado' : (string)$evento['estado'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <p><?= htmlspecialchars((string)($evento['descripcion'] ?? 'Evento programado por SICA.'), ENT_QUOTES, 'UTF-8') ?></p>
                            <div class="event-meta">
                                <span><?= htmlspecialchars(substr((string)$evento['hora_inicio'], 0, 5) . ' - ' . substr((string)$evento['hora_fin'], 0, 5), ENT_QUOTES, 'UTF-8') ?></span>
                                <span><?= htmlspecialchars((string)$evento['nombre_auditorio'] . ' / Bloque ' . (string)$evento['bloque'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span><?= htmlspecialchars('Cupo ' . (string)$evento['capacidad'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        </div>
                        <div class="event-action">
                            <?php if ($estaRegistrado): ?>
                                <a href="<?= htmlspecialchars(app_url('aprendiz/eventos.php'), ENT_QUOTES, 'UTF-8') ?>">Ver detalle</a>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars(app_url('aprendiz/eventos.php'), ENT_QUOTES, 'UTF-8') ?>">Pre-registrarme</a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="dashboard-grid">
            <article class="panel attendance-panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Asistencia</p>
                        <h2>Ingresos confirmados por mes</h2>
                    </div>
                    <span class="status-pill">Confirmado</span>
                </div>
                <div class="spark-chart" aria-hidden="true">
                    <span style="height: 42%"></span>
                    <span style="height: 64%"></span>
                    <span style="height: 58%"></span>
                    <span style="height: 72%"></span>
                    <span style="height: 69%"></span>
                    <span style="height: 86%"></span>
                    <span style="height: 76%"></span>
                    <span style="height: 92%"></span>
                    <span style="height: 84%"></span>
                    <span style="height: 95%"></span>
                </div>
                <div class="chart-footer">
                    <span>Semana 1</span>
                    <span>Semana 5</span>
                </div>
            </article>

            <article class="panel next-panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Auditorio</p>
                        <h2>Eventos cercanos</h2>
                    </div>
                </div>
                <ul class="agenda-list">
                    <?php if (!$eventosCercanos): ?>
                        <li>
                            <span class="agenda-date">--</span>
                            <div>
                                <strong>Sin eventos cercanos</strong>
                                <em>Auditorio pendiente</em>
                                <small>Cuando haya eventos activos apareceran aqui.</small>
                            </div>
                        </li>
                    <?php endif; ?>
                    <?php foreach ($eventosCercanos as $evento): ?>
                        <?php $fechaAgenda = new DateTime((string)$evento['fecha_evento']); ?>
                        <li>
                            <span class="agenda-date"><?= htmlspecialchars($fechaAgenda->format('d M'), ENT_QUOTES, 'UTF-8') ?></span>
                            <div>
                                <strong><?= htmlspecialchars((string)$evento['nombre_evento'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <em><?= htmlspecialchars((string)$evento['nombre_auditorio'], ENT_QUOTES, 'UTF-8') ?></em>
                                <small><?= htmlspecialchars(substr((string)$evento['hora_inicio'], 0, 5) . ' - ' . substr((string)$evento['hora_fin'], 0, 5), ENT_QUOTES, 'UTF-8') ?></small>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </article>

            <article class="panel path-panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Flujo SICA</p>
                        <h2>Tu ruta para certificar asistencia</h2>
                    </div>
                </div>
                <div class="smart-step active">
                        <span>1</span>
                        <div>
                        <strong>Haz el pre-registro al evento</strong>
                        <small>Reserva tu cupo antes de llegar al auditorio.</small>
                        </div>
                    </div>
                    <div class="smart-step">
                        <span>2</span>
                        <div>
                        <strong>Escanea el codigo del evento al ingresar</strong>
                        <small>El codigo lo genera el responsable del evento.</small>
                        </div>
                    </div>
                    <div class="smart-step">
                        <span>3</span>
                        <div>
                        <strong>Descarga el certificado generado</strong>
                        <small>Disponible cuando el evento quede validado.</small>
                        </div>
                    </div>
            </article>

            <article class="panel certificate-panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Certificados</p>
                        <h2>Emitidos por asistencia</h2>
                    </div>
                    <a class="section-link" href="<?= htmlspecialchars(app_url('aprendiz/certificados.php'), ENT_QUOTES, 'UTF-8') ?>">Ver todos</a>
                </div>
                <?php if (!$certificadosDestacados): ?>
                    <div class="certificate-preview muted">
                        <div>
                            <strong>No hay certificados listos</strong>
                            <small>Cuando se confirme tu asistencia, apareceran aqui.</small>
                        </div>
                        <a href="<?= htmlspecialchars(app_url('aprendiz/certificados.php'), ENT_QUOTES, 'UTF-8') ?>">Ver</a>
                    </div>
                <?php endif; ?>
                <?php foreach ($certificadosDestacados as $certificado): ?>
                    <div class="certificate-preview">
                        <div>
                            <strong><?= htmlspecialchars((string)$certificado['nombre_evento'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <small><?= htmlspecialchars((string)$certificado['nombre_auditorio'] . ' / ' . substr((string)$certificado['hora_inicio'], 0, 5), ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                        <a href="<?= htmlspecialchars(app_url('aprendiz/descargar_certificado.php?id=' . (int)$certificado['id_certificado']), ENT_QUOTES, 'UTF-8') ?>">Descargar</a>
                    </div>
                <?php endforeach; ?>
            </article>
        </section>
    </section>
</main>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
