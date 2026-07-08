<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([3]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/instructor_panel.php';

$pageTitle = 'Asistencia - Instructor SICA';
$pageStyles = ['css/instructor.css'];
$idInstructor = (int)(instructor_user()['id_documento'] ?? 0);

$eventos = instructor_rows($pdo, instructor_event_query() . " WHERE e.id_solicitante = :id ORDER BY e.fecha_evento DESC, e.hora_inicio DESC", [':id' => $idInstructor]);
$eventoRaw = trim((string)($_GET['evento'] ?? ''));
$selectedId = $eventoRaw !== '' && ctype_digit($eventoRaw) ? (int)$eventoRaw : (int)($eventos[0]['id_evento'] ?? 0);
$evento = null;
foreach ($eventos as $item) {
    if ((int)$item['id_evento'] === $selectedId) {
        $evento = $item;
        break;
    }
}
$participantes = $evento ? (int)instructor_scalar($pdo, 'SELECT COUNT(*) FROM preregistro WHERE id_evento = :id', [':id' => (int)$evento['id_evento']]) : 0;
$qrPayload = $evento ? instructor_event_qr_payload($evento) : '';
$qrImageUrl = $evento ? instructor_qr_image_url($qrPayload, 240) : '';

// El QR actual abre el pre-registro; los ingresos reales se cuentan solo si ya existe marca de asistencia/hora.
$preTotal = 0;
$ingresosRegistrados = 0;
if ($evento) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN asistencia = 'Asistio' OR hora IS NOT NULL THEN 1 ELSE 0 END) AS ingresos FROM preregistro WHERE id_evento = :id");
    $stmt->execute([':id' => (int)$evento['id_evento']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $preTotal = (int)($row['total'] ?? 0);
    $ingresosRegistrados = (int)($row['ingresos'] ?? 0);
}
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>
<?php instructor_layout_start('asistencia'); ?>

<header class="instructor-topbar">
    <div>
        <p class="eyebrow">Código / pre-registro</p>
        <h1>Código del evento</h1>
        <span>Comparte el QR cuando coordinación apruebe la solicitud. El código abre el pre-registro del aprendiz para este evento.</span>
    </div>
    <a class="top-action" href="<?= instructor_h(app_url('instructor/participantes.php')) ?>">Participantes</a>
</header>

<section class="participants-layout">
    <article class="panel">
        <div class="panel-head">
            <div><p class="eyebrow">Eventos solicitados</p><h2>Selecciona evento</h2></div>
        </div>
        <form class="calendar-toolbar" method="get">
            <select name="evento" onchange="this.form.submit()">
                <?php foreach ($eventos as $item): ?>
                    <option value="<?= instructor_h($item['id_evento']) ?>" <?= (int)$item['id_evento'] === $selectedId ? 'selected' : '' ?>><?= instructor_h($item['nombre_evento']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if (!$evento): ?>
            <div class="empty-state">No tienes eventos solicitados todavía. Crea una solicitud desde disponibilidad para generar el código del evento.</div>
        <?php else: ?>
            <div class="detail-grid">
                <div class="detail-box"><span>Fecha</span><strong><?= instructor_h((new DateTime((string)$evento['fecha_evento']))->format('d/m/Y')) ?></strong></div>
                <div class="detail-box"><span>Hora</span><strong><?= instructor_h(instructor_hora12((string)$evento['hora_inicio'])) ?> a <?= instructor_h(instructor_hora12((string)$evento['hora_fin'])) ?></strong></div>
                <div class="detail-box"><span>Pre-registros</span><strong><?= instructor_h($participantes) ?></strong></div>
                <div class="detail-box"><span>Estado</span><strong><?= instructor_h($evento['estado']) ?></strong></div>
                <div class="detail-box"><span>Auditorio</span><strong><?= instructor_h($evento['nombre_auditorio']) ?></strong></div>
                <div class="detail-box"><span>Código</span><strong><?= instructor_h($evento['codigo_evento']) ?></strong></div>
            </div>
        <?php endif; ?>
    </article>

    <aside class="panel">
        <?php if ($evento): ?>
            <?php
                $estado = (string)($evento['estado'] ?? '');
                $fechaEvento = new DateTime((string)$evento['fecha_evento']);
                $hoy = new DateTime('today');
                $isFutureOrToday = $fechaEvento >= $hoy;
                $isPast = $fechaEvento < $hoy;
            ?>

            <?php if ($estado === 'Pendiente'): ?>
                <div class="qr-card locked">
                    <span class="status-pill pending">Pendiente de aprobación</span>
                    <small>El QR de pre-registro se activará cuando coordinación apruebe el evento.</small>
                </div>

            <?php elseif ($estado === 'Activo' && $isFutureOrToday): ?>
                <div class="qr-card">
                    <span class="qr-mode-pill">Pre-registro activo</span>
                    <img src="<?= instructor_h($qrImageUrl) ?>" alt="Código QR del evento <?= instructor_h($evento['nombre_evento']) ?>">

                    <div class="qr-validity">
                        <strong>Disponible para el evento del <?= instructor_h($fechaEvento->format('d/m/Y')) ?> de <?= instructor_h(instructor_hora12((string)$evento['hora_inicio'])) ?> a <?= instructor_h(instructor_hora12((string)$evento['hora_fin'])) ?></strong>
                    </div>

                    <?php $code = strtoupper((string)$evento['codigo_evento']); $backup = substr($code, -6); $backupSpaced = implode(' ', str_split($backup)); ?>
                    <div class="qr-backup-code"><?= instructor_h($backupSpaced) ?></div>

                    <span class="panel-subtitle"><?= instructor_h($evento['nombre_evento']) ?></span>
                    <small>Al escanearlo, el aprendiz ingresa al pre-registro de este evento. El control de asistencia se revisa desde participantes.</small>

                    <div class="attendance-summary">
                        <div class="attendance-stats">
                            <div><small>Pre-registros</small><strong><?= instructor_h($preTotal) ?></strong></div>
                            <div><small>Ingresos registrados</small><strong><?= instructor_h($ingresosRegistrados) ?></strong></div>
                        </div>
                        <?php $pct = $preTotal > 0 ? (int)round(($ingresosRegistrados / $preTotal) * 100) : 0; ?>
                        <progress class="attendance-progress" value="<?= instructor_h($pct) ?>" max="100"><?= instructor_h($pct) ?>%</progress>
                    </div>

                    <div class="qr-actions">
                        <a class="primary-btn" href="<?= instructor_h(app_url('instructor/descargar_codigo.php?evento=' . (int)$evento['id_evento'])) ?>">Descargar QR</a>
                        <a class="secondary-btn" href="<?= instructor_h(app_url('instructor/participantes.php?evento=' . (int)$evento['id_evento'])) ?>">Ver participantes</a>
                    </div>
                </div>

            <?php elseif (($estado === 'Activo' || $estado === 'Finalizado') && $isPast): ?>
                <div class="qr-card">
                    <?php $finalText = sprintf('Evento finalizado - %d de %d ingresos registrados', $ingresosRegistrados, $preTotal); ?>
                    <h3><?= instructor_h($finalText) ?></h3>
                    <small><?= instructor_h($evento['nombre_evento']) ?> — <?= instructor_h($evento['nombre_auditorio']) ?> (<?= instructor_h($fechaEvento->format('d/m/Y')) ?>)</small>
                    <div class="qr-actions"><a class="primary-btn" href="<?= instructor_h(app_url('instructor/exportar_participantes.php?evento=' . (int)$evento['id_evento'])) ?>">Exportar participantes</a></div>
                </div>

            <?php else: ?>
                <div class="qr-card locked">
                    <span class="status-pill pending">Estado: <?= instructor_h($evento['estado']) ?></span>
                    <small>Información de asistencia no disponible en este estado.</small>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state">Código pendiente.</div>
        <?php endif; ?>
    </aside>
</section>

<?php instructor_layout_end(); ?>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
