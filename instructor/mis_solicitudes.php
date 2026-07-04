<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([3]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/instructor_panel.php';

$pageTitle = 'Mis solicitudes - Instructor SICA';
$pageStyles = ['css/instructor.css'];
$idInstructor = (int)(instructor_user()['id_documento'] ?? 0);
$estadoFiltro = trim((string)($_GET['estado'] ?? ''));
$params = [':id' => $idInstructor];
$where = ' WHERE e.id_solicitante = :id';
if ($estadoFiltro !== '') {
    $where .= ' AND es.nombre_estado = :estado';
    $params[':estado'] = $estadoFiltro;
}

// 1) counts by estado (aggregated query)
$counts = ['Pendiente' => 0, 'Activo' => 0, 'Cancelado' => 0, 'Finalizado' => 0];
$countStmt = $pdo->prepare('SELECT es.nombre_estado, COUNT(*) AS cnt FROM evento e INNER JOIN estado es ON es.id_estado = e.id_estado' . $where . ' GROUP BY es.nombre_estado');
$countStmt->execute($params);
foreach ($countStmt->fetchAll() as $row) {
    $name = (string)($row['nombre_estado'] ?? '');
    $cnt = (int)($row['cnt'] ?? 0);
    if (array_key_exists($name, $counts)) {
        $counts[$name] = $cnt;
    } else {
        $counts[$name] = $cnt;
    }
}

// 2) separate upcoming and historical. Upcoming: fecha_evento >= CURDATE()
$upcoming = instructor_rows($pdo, instructor_event_query() . $where . ' AND DATE(e.fecha_evento) >= CURDATE() ORDER BY e.fecha_evento ASC, e.hora_inicio ASC', $params);

// 3) pagination for historical (fecha_evento < CURDATE())
$perPage = 15;
$pagina = max(1, (int)($_GET['pagina'] ?? 1));
$countHistoricalSql = 'SELECT COUNT(*) FROM evento e INNER JOIN estado es ON es.id_estado = e.id_estado' . $where . ' AND DATE(e.fecha_evento) < CURDATE()';
$totalHistorical = (int)instructor_scalar($pdo, $countHistoricalSql, $params);
$totalPages = (int)max(1, ceil($totalHistorical / $perPage));
$offset = ($pagina - 1) * $perPage;
$paramsPag = $params;
$paramsPag[':limit'] = $perPage;
$paramsPag[':offset'] = $offset;
$historical = instructor_rows($pdo, instructor_event_query() . $where . ' AND DATE(e.fecha_evento) < CURDATE() ORDER BY e.fecha_evento DESC, e.hora_inicio DESC LIMIT :limit OFFSET :offset', $paramsPag);

// combine for rendering in sections
$solicitudes = array_merge($upcoming, $historical);
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>
<?php instructor_layout_start('solicitudes'); ?>

<header class="instructor-topbar">
    <div>
        <p class="eyebrow">Mis solicitudes</p>
        <h1>Historial de reservas</h1>
        <span>Consulta estados, observaciones y detalles de tus solicitudes de auditorio.</span>
    </div>
    <div class="topbar-actions">
        <a class="top-action" href="<?= instructor_h(app_url('instructor/disponibilidad.php')) ?>">Nueva solicitud</a>
    </div>
</header>

<section class="panel">
    <div class="panel-head">
        <div>
            <p class="eyebrow">Solicitudes</p>
            <h2>Eventos solicitados</h2>
        </div>
        <form class="calendar-toolbar" method="get">
            <select name="estado" onchange="this.form.submit()">
                <option value="">Todos los estados</option>
                <?php foreach (['Pendiente','Activo','Cancelado','Finalizado'] as $estado): ?>
                    <option value="<?= instructor_h($estado) ?>" <?= $estadoFiltro === $estado ? 'selected' : '' ?>><?= instructor_h($estado) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <!-- Metrics summary -->
    <div class="metric-grid" style="margin:12px 0;">
        <article class="metric-tile amber"><span>Pendientes</span><strong><?= instructor_h($counts['Pendiente'] ?? 0) ?></strong><small>En revision</small></article>
        <article class="metric-tile navy"><span>Activos</span><strong><?= instructor_h($counts['Activo'] ?? 0) ?></strong><small>Aprobados</small></article>
        <article class="metric-tile red"><span>Cancelados</span><strong><?= instructor_h($counts['Cancelado'] ?? 0) ?></strong><small>Cancelados</small></article>
        <article class="metric-tile green"><span>Finalizados</span><strong><?= instructor_h($counts['Finalizado'] ?? 0) ?></strong><small>Completados</small></article>
    </div>

    <!-- Upcoming events -->
    <div class="request-list">
        <h3 style="margin:8px 0;">Próximas / Activas</h3>
        <?php if (!$upcoming): ?><div class="empty-state">No hay próximas solicitudes.</div><?php endif; ?>
        <?php foreach ($upcoming as $evento): ?>
            <?php $fecha = new DateTime((string)$evento['fecha_evento']); ?>
            <article class="request-row">
                <div class="request-date"><?= instructor_h($fecha->format('d M')) ?></div>
                <div>
                    <h3><?= instructor_h($evento['nombre_evento']) ?></h3>
                    <small><?= instructor_h($evento['nombre_auditorio']) ?> / <?= instructor_h($evento['nombre_tipo']) ?> - <?= instructor_h(substr((string)$evento['hora_inicio'], 0, 5)) ?> a <?= instructor_h(substr((string)$evento['hora_fin'], 0, 5)) ?></small>
                </div>
                <a class="status-pill <?= instructor_h(instructor_status_class((string)$evento['estado'])) ?>" href="<?= instructor_h(app_url('instructor/detalle_solicitud.php?id=' . (int)$evento['id_evento'])) ?>"><?= instructor_h($evento['estado']) ?></a>
            </article>
        <?php endforeach; ?>
    </div>

    <!-- Historical (paginated) -->
    <div class="request-list" style="margin-top:18px;">
        <h3 style="margin:8px 0;">Historial</h3>
        <?php if (!$historical): ?><div class="empty-state">No hay solicitudes en el historial para este filtro.</div><?php endif; ?>
        <?php foreach ($historical as $evento): ?>
            <?php $fecha = new DateTime((string)$evento['fecha_evento']); ?>
            <article class="request-row">
                <div class="request-date"><?= instructor_h($fecha->format('d M')) ?></div>
                <div>
                    <h3><?= instructor_h($evento['nombre_evento']) ?></h3>
                    <small><?= instructor_h($evento['nombre_auditorio']) ?> / <?= instructor_h($evento['nombre_tipo']) ?> - <?= instructor_h(substr((string)$evento['hora_inicio'], 0, 5)) ?> a <?= instructor_h(substr((string)$evento['hora_fin'], 0, 5)) ?></small>
                </div>
                <a class="status-pill <?= instructor_h(instructor_status_class((string)$evento['estado'])) ?>" href="<?= instructor_h(app_url('instructor/detalle_solicitud.php?id=' . (int)$evento['id_evento'])) ?>"><?= instructor_h($evento['estado']) ?></a>
            </article>
        <?php endforeach; ?>

        <!-- Pagination controls -->
        <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
            <?php
            $baseQuery = [];
            if ($estadoFiltro !== '') $baseQuery['estado'] = $estadoFiltro;
            for ($p = 1; $p <= $totalPages; $p++):
                $q = $baseQuery;
                $q['pagina'] = $p;
                $href = app_url('instructor/mis_solicitudes.php') . '?' . http_build_query($q);
            ?>
                <a class="<?= $p === $pagina ? 'primary-btn' : 'top-action' ?>" href="<?= instructor_h($href) ?>"><?= instructor_h($p) ?></a>
            <?php endfor; ?>
        </div>
    </div>
</section>

<?php instructor_layout_end(); ?>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
