<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([4]);
require_once __DIR__ . '/../config/conexion.php';

$pageTitle = 'Certificados del Aprendiz - SICA';
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

function cert_e(string|int|null $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cert_asistencia_texto(?string $asistencia): string
{
    $valor = (string)$asistencia;
    if ($valor === 'Pendiente') {
        return 'Pendiente';
    }

    if (stripos($valor, 'No') !== false) {
        return 'No asistio';
    }

    return 'Asistio';
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
    error_log('SICA certificados: no se pudo cargar el perfil: ' . $exception->getMessage());
}

$certificados = [];
try {
    $stmt = $pdo->prepare(
        'SELECT pr.id_preregistro, pr.fecha_registro, pr.asistencia, pr.fecha_ingreso, pr.hora,
                e.nombre_evento, e.descripcion, e.fecha_evento, e.hora_inicio, e.hora_fin,
                a.nombre_auditorio, a.bloque, t.nombre_tipo,
                c.id_certificado, c.fecha_generado, c.ruta_certificado
         FROM preregistro pr
         INNER JOIN evento e ON e.id_evento = pr.id_evento
         INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
         INNER JOIN tipo_evento t ON t.id_tipo_evento = e.id_tipo_evento
         LEFT JOIN certificado c ON c.id_preregistro = pr.id_preregistro
         WHERE pr.id_documento = :id_documento
         ORDER BY e.fecha_evento DESC, e.hora_inicio DESC'
    );
    $stmt->execute([':id_documento' => $idDocumento]);
    $certificados = $stmt->fetchAll();
} catch (Throwable $exception) {
    error_log('SICA certificados: no se pudieron cargar certificados: ' . $exception->getMessage());
}

$listos = array_values(array_filter($certificados, static fn(array $item): bool => !empty($item['ruta_certificado'])));
$pendientesEmision = array_values(array_filter(
    $certificados,
    static fn(array $item): bool => empty($item['ruta_certificado']) && cert_asistencia_texto((string)$item['asistencia']) === 'Asistio'
));
$pendientesAsistencia = array_values(array_filter(
    $certificados,
    static fn(array $item): bool => empty($item['ruta_certificado']) && cert_asistencia_texto((string)$item['asistencia']) !== 'Asistio'
));
$ultimoCertificado = $listos[0] ?? null;
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>

<main class="apprentice-dashboard">
    <aside class="apprentice-sidebar" aria-label="Menu del aprendiz">
        <a class="apprentice-brand" href="<?= cert_e(app_url('aprendiz/index.php')) ?>">
            <span>
                <strong>SICA</strong>
                <small>Aprendiz</small>
            </span>
        </a>

        <section class="apprentice-person" aria-label="Aprendiz activo">
            <div class="apprentice-person-avatar">
                <?php if ($fotoPerfil !== ''): ?>
                    <img src="<?= cert_e(app_url($fotoPerfil)) ?>" alt="">
                <?php else: ?>
                    <?= cert_e($iniciales) ?>
                <?php endif; ?>
            </div>
            <div>
                <strong><?= cert_e($nombreCompleto) ?></strong>
                <small><?= cert_e((string)($usuario['correo'] ?? '')) ?></small>
            </div>
        </section>

        <nav class="apprentice-nav">
            <a href="<?= cert_e(app_url('aprendiz/index.php')) ?>">
                <span aria-hidden="true">IN</span>
                Dashboard
            </a>
            <a href="<?= cert_e(app_url('aprendiz/eventos.php')) ?>">
                <span aria-hidden="true">EV</span>
                Eventos
            </a>
            <a href="<?= cert_e(app_url('aprendiz/preregistro.php')) ?>">
                <span aria-hidden="true">PR</span>
                Pre-registro
            </a>
            <a class="active" href="<?= cert_e(app_url('aprendiz/certificados.php')) ?>">
                <span aria-hidden="true">CE</span>
                Certificados
            </a>
        </nav>

        <section class="learner-id-card" aria-label="Credencial del aprendiz">
            <span class="sidebar-label">Credencial del aprendiz</span>
            <div class="learner-photo-card">
                <div class="learner-photo" aria-hidden="true">
                    <?php if ($fotoPerfil !== ''): ?>
                        <img src="<?= cert_e(app_url($fotoPerfil)) ?>" alt="">
                    <?php else: ?>
                        <span><?= cert_e($iniciales) ?></span>
                    <?php endif; ?>
                </div>
                <div class="learner-scan" aria-hidden="true"></div>
            </div>
            <div class="learner-id-copy">
                <strong><?= cert_e($nombreCompleto) ?></strong>
                <small>Aprendiz activo</small>
            </div>
            <div class="learner-id-data">
                <div>
                    <span>Ficha</span>
                    <strong><?= cert_e($fichaAprendiz) ?></strong>
                </div>
                <div>
                    <span>Programa</span>
                    <strong><?= cert_e($programaAprendiz) ?></strong>
                </div>
            </div>
            <a class="learner-profile-link" href="<?= cert_e(app_url('aprendiz/perfil.php')) ?>">Ver perfil</a>
        </section>
    </aside>

    <section class="apprentice-main">
        <header class="apprentice-topbar">
            <div>
                <p class="eyebrow">Panel de certificados</p>
                <h1>Mis certificados</h1>
                <span>Descarga las constancias generadas despues de confirmar asistencia en el auditorio.</span>
            </div>

            <a class="top-logout" href="<?= cert_e(app_url('login/logout.php')) ?>">
                <span aria-hidden="true">SL</span>
                Cerrar sesion
            </a>
        </header>

        <section class="certificate-hero" aria-label="Resumen de certificados">
            <div>
                <p class="eyebrow">Boveda SICA</p>
                <h2><?= cert_e((string)count($listos)) ?> certificados listos</h2>
                <span>Tu historial queda organizado por evento, auditorio y fecha de emision.</span>
            </div>
            <div class="certificate-orbit" aria-hidden="true">
                <span>CE</span>
            </div>
        </section>

        <section class="certificate-stats" aria-label="Indicadores de certificados">
            <article>
                <span>Listos</span>
                <strong><?= cert_e((string)count($listos)) ?></strong>
                <small>Disponibles para descargar</small>
            </article>
            <article>
                <span>En emision</span>
                <strong><?= cert_e((string)count($pendientesEmision)) ?></strong>
                <small>Asistencia confirmada</small>
            </article>
            <article>
                <span>Por asistir</span>
                <strong><?= cert_e((string)count($pendientesAsistencia)) ?></strong>
                <small>Falta validar ingreso</small>
            </article>
        </section>

        <?php if ($ultimoCertificado): ?>
            <section class="certificate-highlight" aria-label="Ultimo certificado">
                <div>
                    <p class="eyebrow">Ultimo certificado</p>
                    <h2><?= cert_e($ultimoCertificado['nombre_evento']) ?></h2>
                    <span>Generado el <?= cert_e((new DateTime((string)$ultimoCertificado['fecha_generado']))->format('d/m/Y')) ?> para <?= cert_e($nombreCompleto) ?>.</span>
                </div>
                <a href="<?= cert_e(app_url('aprendiz/descargar_certificado.php?id=' . (int)$ultimoCertificado['id_certificado'])) ?>">Descargar</a>
            </section>
        <?php endif; ?>

        <section class="certificate-list-panel" aria-label="Listado de certificados">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Historial</p>
                    <h2>Eventos y certificados</h2>
                    <span>Cuando el instructor confirme la asistencia y el sistema emita el certificado, aparecera el boton de descarga.</span>
                </div>
            </div>

            <?php if (!$certificados): ?>
                <div class="event-empty">
                    <strong>No hay registros de certificados.</strong>
                    <span>Primero realiza un pre-registro y asiste al evento para que el certificado pueda generarse.</span>
                </div>
            <?php else: ?>
                <div class="certificate-list">
                    <?php foreach ($certificados as $item): ?>
                        <?php
                        $fechaEvento = new DateTime((string)$item['fecha_evento']);
                        $asistencia = cert_asistencia_texto((string)$item['asistencia']);
                        $tieneCertificado = !empty($item['ruta_certificado']);
                        $estadoClase = $tieneCertificado ? 'ready' : ($asistencia === 'Asistio' ? 'issuing' : 'waiting');
                        $estadoTexto = $tieneCertificado ? 'Listo para descargar' : ($asistencia === 'Asistio' ? 'En emision' : 'Pendiente de asistencia');
                        $estadoDetalle = $asistencia === 'Asistio'
                            ? 'Tu asistencia ya fue registrada. Estamos preparando tu certificado.'
                            : 'Asiste al evento y confirma tu ingreso en el auditorio.';
                        $accionTexto = $tieneCertificado ? 'Descargar certificado' : 'Ver pre-registro';
                        $accionUrl = $tieneCertificado
                            ? app_url('aprendiz/descargar_certificado.php?id=' . (int)$item['id_certificado'])
                            : app_url('aprendiz/preregistro.php');
                        ?>
                        <article class="certificate-card <?= cert_e($estadoClase) ?>">
                            <div class="certificate-date">
                                <strong><?= cert_e($fechaEvento->format('d')) ?></strong>
                                <span><?= cert_e(mb_strtoupper($fechaEvento->format('M'), 'UTF-8')) ?></span>
                            </div>
                            <div>
                                <div class="event-title-row">
                                    <span class="event-type"><?= cert_e($item['nombre_tipo']) ?></span>
                                </div>
                                <h3><?= cert_e($item['nombre_evento']) ?></h3>
                                <p><?= cert_e($item['descripcion'] ?? 'Evento registrado en SICA.') ?></p>
                                <div class="event-meta certificate-meta">
                                    <span><?= cert_e(substr((string)$item['hora_inicio'], 0, 5) . ' - ' . substr((string)$item['hora_fin'], 0, 5)) ?></span>
                                    <span><?= cert_e($item['nombre_auditorio'] . ' / Bloque ' . $item['bloque']) ?></span>
                                    <span>Asistencia: <?= cert_e($asistencia) ?></span>
                                </div>
                            </div>
                            <div class="certificate-action">
                                <div class="certificate-action-state <?= cert_e($estadoClase) ?>">
                                    <span><?= cert_e($estadoTexto) ?></span>
                                    <small><?= cert_e($estadoDetalle) ?></small>
                                </div>
                                <a class="<?= cert_e($tieneCertificado ? 'primary' : 'secondary') ?>" href="<?= cert_e($accionUrl) ?>"><?= cert_e($accionTexto) ?></a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </section>
</main>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
