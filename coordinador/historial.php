<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([2]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/coordinador_panel.php';

$pageTitle = 'Historial de decisiones - Coordinador SICA';
$pageStyles = ['css/instructor.css'];

$usuario = coord_user();
$coordinadorId = (int)($usuario['id_documento'] ?? 0);

$filters = coord_historial_filters($_GET, $coordinadorId);
$estadoFiltro = $filters['estadoFiltro'];
$busqueda = $filters['busqueda'];
$fechaDesde = $filters['fechaDesde'];
$fechaHasta = $filters['fechaHasta'];
$whereSql = $filters['whereSql'];
$params = $filters['params'];
$estadosHistorial = $filters['estadosHistorial'];
$hayFiltroActivo = $filters['hayFiltroActivo'];
$filtroResumen = $filters['filtroResumen'];

$perPage = 15;
$pagina = min(200, max(1, (int)($_GET['pagina'] ?? 1)));

$stats = ['aprobadas' => 0, 'canceladas' => 0, 'finalizadas' => 0];
$decisiones = [];
$monthLabels = [1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'];
$decisionesPorMes = array_fill(1, 12, 0);
$totalFiltrado = 0;
$totalPages = 1;

try {
    foreach (coord_rows(
        $pdo,
        "SELECT es.nombre_estado, COUNT(*) total
         FROM evento e
         INNER JOIN estado es ON es.id_estado = e.id_estado
         WHERE e.id_coordinador = :coordinador AND es.nombre_estado IN ('Activo','Cancelado','Finalizado')
         GROUP BY es.nombre_estado",
        [':coordinador' => $coordinadorId]
    ) as $row) {
        $key = match ((string)$row['nombre_estado']) {
            'Activo' => 'aprobadas',
            'Cancelado' => 'canceladas',
            'Finalizado' => 'finalizadas',
            default => null,
        };
        if ($key !== null) {
            $stats[$key] = (int)$row['total'];
        }
    }

    foreach (coord_rows(
        $pdo,
        "SELECT MONTH(COALESCE(fecha_aprobacion, fecha_evento)) mes, COUNT(*) total
         FROM evento e
         INNER JOIN estado es ON es.id_estado = e.id_estado
         WHERE e.id_coordinador = :coordinador AND es.nombre_estado IN ('Activo','Cancelado','Finalizado')
           AND YEAR(COALESCE(fecha_aprobacion, fecha_evento)) = YEAR(CURDATE())
         GROUP BY MONTH(COALESCE(fecha_aprobacion, fecha_evento))",
        [':coordinador' => $coordinadorId]
    ) as $row) {
        $decisionesPorMes[(int)$row['mes']] = (int)$row['total'];
    }

    $totalFiltrado = coord_scalar(
        $pdo,
        'SELECT COUNT(*)
         FROM evento e
         INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
         INNER JOIN estado es ON es.id_estado = e.id_estado
         LEFT JOIN usuario u ON u.id_documento = e.id_solicitante
         ' . $whereSql,
        $params
    );
    $totalPages = (int)max(1, ceil($totalFiltrado / $perPage));
    $pagina = min($pagina, $totalPages);
    $offset = ($pagina - 1) * $perPage;

    $decisiones = coord_rows(
        $pdo,
        'SELECT e.id_evento, e.nombre_evento, e.codigo_evento, e.fecha_evento, e.hora_inicio, e.hora_fin,
                e.observacion, e.fecha_aprobacion, es.nombre_estado AS estado,
                a.nombre_auditorio, a.bloque, u.nombre, u.apellido, u.correo
         FROM evento e
         INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
         INNER JOIN estado es ON es.id_estado = e.id_estado
         LEFT JOIN usuario u ON u.id_documento = e.id_solicitante
         ' . $whereSql . '
         ORDER BY COALESCE(e.fecha_aprobacion, e.fecha_evento) DESC, e.id_evento DESC
         LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset,
        $params
    );
} catch (Throwable $exception) {
    error_log('SICA coordinador historial: ' . $exception->getMessage());
}

$totalDecisionesSinFiltro = $stats['aprobadas'] + $stats['canceladas'] + $stats['finalizadas'];
$maxMonth = max(1, max($decisionesPorMes));
$exportQuery = http_build_query([
    'estado' => $estadoFiltro,
    'q' => $busqueda,
    'desde' => $fechaDesde,
    'hasta' => $fechaHasta,
]);
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>
<?php coord_layout_start('historial'); ?>

<header class="instructor-topbar">
    <div>
        <p class="eyebrow">Historial</p>
        <h1>Decisiones registradas</h1>
        <span>Consulta las reservas que ya aprobaste, cancelaste o finalizaste.</span>
    </div>
    <div class="topbar-actions">
        <a class="top-action" href="<?= coord_h(app_url('coordinador/solicitudes.php')) ?>">Solicitudes</a>
        <a class="top-action" href="<?= coord_h(app_url('coordinador/exportar_historial.php?' . $exportQuery)) ?>">Exportar CSV</a>
    </div>
</header>

<section class="metric-grid coord-historial-metrics" aria-label="Resumen de decisiones">
    <article class="metric-tile navy"><span>Aprobadas</span><strong><?= coord_h($stats['aprobadas']) ?></strong><small>Reservas activas</small><em>Historial</em></article>
    <article class="metric-tile amber"><span>Canceladas</span><strong><?= coord_h($stats['canceladas']) ?></strong><small>No autorizadas</small><em>Historial</em></article>
    <article class="metric-tile green"><span>Finalizadas</span><strong><?= coord_h($stats['finalizadas']) ?></strong><small>Eventos realizados</small><em>Historial</em></article>
    <article class="metric-tile blue"><span>Total</span><strong><?= coord_h($totalDecisionesSinFiltro) ?></strong><small>Decisiones registradas</small><em>Bandeja</em></article>
</section>

<section class="panel">
    <div class="panel-head">
        <div>
            <p class="eyebrow">Este año</p>
            <h2>Decisiones por mes</h2>
            <span class="panel-subtitle">Cuántas solicitudes gestionaste cada mes de <?= coord_h(date('Y')) ?>.</span>
        </div>
    </div>
    <div class="coord-bars" aria-label="Gráfica de decisiones por mes">
        <?php foreach ($decisionesPorMes as $mes => $total): ?>
            <div class="coord-bar">
                <progress value="<?= coord_h($total) ?>" max="<?= coord_h($maxMonth) ?>"><?= coord_h($total) ?></progress>
                <small><?= coord_h($monthLabels[$mes]) ?></small>
                <strong><?= coord_h($total) ?></strong>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="panel coord-historial">
    <div class="panel-head">
        <div>
            <p class="eyebrow">Detalle</p>
            <h2>Historial de solicitudes</h2>
            <span class="panel-subtitle">Filtra por estado, fecha de decisión o texto para ubicar registros específicos.</span>
        </div>
    </div>

    <form class="calendar-toolbar coord-historial-filters" method="get" action="<?= coord_h(app_url('coordinador/historial.php')) ?>">
        <label>
            <span>Búsqueda rápida</span>
            <input type="search" name="q" value="<?= coord_h($busqueda) ?>" maxlength="80" placeholder="Evento, código o instructor">
        </label>
        <label>
            <span>Estado</span>
            <select name="estado">
                <option value="">Todos</option>
                <?php foreach ($estadosHistorial as $estado): ?>
                    <option value="<?= coord_h($estado) ?>" <?= $estadoFiltro === $estado ? 'selected' : '' ?>><?= coord_h($estado) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Decisión desde</span>
            <input type="date" name="desde" value="<?= coord_h($fechaDesde) ?>">
        </label>
        <label>
            <span>Decisión hasta</span>
            <input type="date" name="hasta" value="<?= coord_h($fechaHasta) ?>">
        </label>
        <button class="primary-btn" type="submit">Filtrar</button>
        <a class="top-action" href="<?= coord_h(app_url('coordinador/historial.php')) ?>">Limpiar</a>
    </form>

    <div class="request-list request-list-spaced">
        <div class="section-heading">
            <p class="eyebrow">Resultados</p>
            <h3><?= coord_h($totalFiltrado) ?> decisión(es)<?= $hayFiltroActivo ? ' con filtro activo' : '' ?></h3>
        </div>

        <?php if (!$decisiones && !$hayFiltroActivo): ?>
            <div class="empty-state">Aún no tienes decisiones registradas. Cuando apruebes o canceles una solicitud, aparecerá aquí.</div>
        <?php elseif (!$decisiones && $hayFiltroActivo): ?>
            <div class="empty-state">
                No hay resultados para <?= coord_h($filtroResumen) ?>.
                Tienes <?= coord_h($totalDecisionesSinFiltro) ?> decisión(es) en total.
            </div>
        <?php endif; ?>

        <?php foreach ($decisiones as $decision): ?>
            <?php
            $fecha = new DateTime((string)$decision['fecha_evento']);
            $estado = (string)$decision['estado'];
            $instructor = trim((string)$decision['nombre'] . ' ' . (string)$decision['apellido']);
            $instructor = $instructor !== '' ? $instructor : 'Instructor SICA';
            $steps = coord_detail_steps($decision);
            $observacionTexto = trim((string)($decision['observacion'] ?? ''));
            $decisionDate = '';
            try {
                if (!empty($decision['fecha_aprobacion'])) {
                    $decisionDate = (new DateTime((string)$decision['fecha_aprobacion']))->format('d/m/Y');
                }
            } catch (Throwable) {
                $decisionDate = '';
            }
            ?>
            <article class="request-row <?= coord_h(coord_status_class($estado)) ?>">
                <div class="request-date"><?= coord_h($fecha->format('d')) ?> <?= coord_h($monthLabels[(int)$fecha->format('n')]) ?></div>
                <div class="request-content">
                    <div class="request-title">
                        <h3><?= coord_h($decision['nombre_evento']) ?></h3>
                    </div>
                    <small><?= coord_h($decision['nombre_auditorio']) ?> / Bloque <?= coord_h($decision['bloque']) ?> · <?= coord_h(coord_hora12((string)$decision['hora_inicio'])) ?> a <?= coord_h(coord_hora12((string)$decision['hora_fin'])) ?></small>
                    <small>Instructor: <?= coord_h($instructor) ?> · Código <?= coord_h($decision['codigo_evento']) ?><?= $decisionDate !== '' ? ' · Decisión ' . coord_h($decisionDate) : '' ?></small>
                    <?php if ($estado === 'Cancelado' && $observacionTexto !== ''): ?>
                        <div class="obs-banner"><?= coord_h($observacionTexto) ?></div>
                    <?php endif; ?>
                    <div class="stepper" aria-hidden="true">
                        <?php foreach ($steps as $step): ?>
                            <div class="<?= coord_h(coord_detail_step_class($step, $estado)) ?>"><div class="dot"></div><div class="label"><?= coord_h($step['label'] . $step['extra']) ?></div></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <a class="status-pill <?= coord_h(coord_pill_class($estado)) ?>" href="<?= coord_h(app_url('coordinador/detalle_solicitud.php?id=' . (int)$decision['id_evento'])) ?>"><?= coord_h($estado) ?></a>
            </article>
        <?php endforeach; ?>

        <?php if ($totalPages > 1): ?>
            <div class="pagination-actions" aria-label="Paginación del historial">
                <?php
                $baseQuery = array_filter([
                    'estado' => $estadoFiltro,
                    'q' => $busqueda,
                    'desde' => $fechaDesde,
                    'hasta' => $fechaHasta,
                ], static fn($value): bool => $value !== '' && $value !== null);
                for ($p = 1; $p <= $totalPages; $p++):
                    $q = $baseQuery;
                    $q['pagina'] = $p;
                    $href = app_url('coordinador/historial.php') . '?' . http_build_query($q);
                ?>
                    <a class="<?= $p === $pagina ? 'primary-btn' : 'top-action' ?>" href="<?= coord_h($href) ?>"><?= coord_h($p) ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php coord_layout_end(); ?>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
