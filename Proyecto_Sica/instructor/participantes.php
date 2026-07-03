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
$eventos = instructor_rows($pdo, instructor_event_query() . ' WHERE e.id_solicitante = :id ORDER BY e.fecha_evento DESC', [':id' => $idInstructor]);
$selectedId = (int)($_GET['evento'] ?? ($eventos[0]['id_evento'] ?? 0));
$evento = null;
foreach ($eventos as $item) {
    if ((int)$item['id_evento'] === $selectedId) {
        $evento = $item;
        break;
    }
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
        <div class="request-list">
            <?php if (!$participantes): ?><div class="empty-state">Todavia no hay aprendices pre-registrados para este evento.</div><?php endif; ?>
            <?php foreach ($participantes as $index => $participante): ?>
                <?php $full = trim((string)$participante['nombre'] . ' ' . (string)$participante['apellido']); ?>
                <article class="participant-row">
                    <b><?= instructor_h($index + 1) ?></b>
                    <div>
                        <strong><?= instructor_h($full) ?></strong>
                        <small><?= instructor_h($participante['correo']) ?> · Ficha <?= instructor_h($participante['id_ficha'] ?? 'N/A') ?></small>
                    </div>
                    <span class="status-pill <?= (string)$participante['asistencia'] === 'Pendiente' ? 'pending' : 'ok' ?>"><?= instructor_h($participante['asistencia']) ?></span>
                </article>
            <?php endforeach; ?>
        </div>
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
