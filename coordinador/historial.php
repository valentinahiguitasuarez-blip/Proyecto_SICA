<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([2]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/coordinador_panel.php';

$pageTitle = 'Historial de decisiones - Coordinador SICA';
$pageStyles = ['css/admin.css'];

$usuario = coord_user();
$coordinadorId = (int)($usuario['id_documento'] ?? 0);

$estadoFiltro = trim((string)($_GET['estado'] ?? ''));
$busqueda = trim((string)($_GET['q'] ?? ''));
$fechaDesde = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['desde'] ?? '')) ? (string)$_GET['desde'] : '';
$fechaHasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['hasta'] ?? '')) ? (string)$_GET['hasta'] : '';

$where = ['e.id_coordinador = :coordinador', "es.nombre_estado IN ('Activo', 'Cancelado', 'Finalizado')"];
$params = [':coordinador' => $coordinadorId];

if ($estadoFiltro !== '' && in_array($estadoFiltro, ['Activo', 'Cancelado', 'Finalizado'], true)) {
    $where[] = 'es.nombre_estado = :estado';
    $params[':estado'] = $estadoFiltro;
}
if ($busqueda !== '') {
    $where[] = '(e.nombre_evento LIKE :busqueda OR e.codigo_evento LIKE :busqueda OR u.nombre LIKE :busqueda OR u.apellido LIKE :busqueda)';
    $params[':busqueda'] = '%' . $busqueda . '%';
}
if ($fechaDesde !== '') {
    $where[] = 'DATE(e.fecha_aprobacion) >= :desde';
    $params[':desde'] = $fechaDesde;
}
if ($fechaHasta !== '') {
    $where[] = 'DATE(e.fecha_aprobacion) <= :hasta';
    $params[':hasta'] = $fechaHasta;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$stats = ['aprobadas' => 0, 'canceladas' => 0, 'finalizadas' => 0];
$decisiones = [];
$monthLabels = [1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'];
$decisionesPorMes = array_fill(1, 12, 0);

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
        "SELECT MONTH(fecha_aprobacion) mes, COUNT(*) total
         FROM evento e
         INNER JOIN estado es ON es.id_estado = e.id_estado
         WHERE e.id_coordinador = :coordinador AND es.nombre_estado IN ('Activo','Cancelado','Finalizado')
           AND fecha_aprobacion IS NOT NULL AND YEAR(fecha_aprobacion) = YEAR(CURDATE())
         GROUP BY MONTH(fecha_aprobacion)",
        [':coordinador' => $coordinadorId]
    ) as $row) {
        $decisionesPorMes[(int)$row['mes']] = (int)$row['total'];
    }

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
         ORDER BY e.fecha_aprobacion DESC
         LIMIT 100',
        $params
    );
} catch (Throwable $exception) {
    error_log('SICA coordinador historial: ' . $exception->getMessage());
}

$totalDecisionesSinFiltro = $stats['aprobadas'] + $stats['canceladas'] + $stats['finalizadas'];
$hayFiltroActivo = $estadoFiltro !== '' || $busqueda !== '' || $fechaDesde !== '' || $fechaHasta !== '';
$maxMonth = max(1, max($decisionesPorMes));
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>
<?php coord_layout_start('historial'); ?>

        <header class="admin-topbar">
            <div>
                <p class="admin-eyebrow">Historial</p>
                <h1>Decisiones registradas</h1>
                <span>Consulta las reservas que ya aprobaste o cancelaste.</span>
            </div>
        </header>

        <section class="admin-metrics reservation-metrics" aria-label="Resumen de decisiones">
            <article class="admin-metric">
                <span>Aprobadas</span>
                <strong><?= coord_h($stats['aprobadas']) ?></strong>
                <small>Reservas activas</small>
            </article>
            <article class="admin-metric">
                <span>Canceladas</span>
                <strong><?= coord_h($stats['canceladas']) ?></strong>
                <small>No autorizadas</small>
            </article>
            <article class="admin-metric">
                <span>Finalizadas</span>
                <strong><?= coord_h($stats['finalizadas']) ?></strong>
                <small>Eventos ya realizados</small>
            </article>
        </section>

        <section class="admin-panel chart-panel">
            <div class="admin-panel-head">
                <div>
                    <p class="admin-eyebrow">Este año</p>
                    <h2>Decisiones por mes</h2>
                </div>
            </div>
            <p class="admin-panel-note">Cuantas solicitudes aprobaste o cancelaste cada mes de <?= coord_h(date('Y')) ?>. Te sirve para ver en que meses tienes mas carga de revision.</p>
            <div class="admin-bars" aria-label="Grafica de decisiones por mes">
                <?php foreach ($decisionesPorMes as $mes => $total): ?>
                    <div class="admin-bar">
                        <span style="height: <?= coord_h(max(8, (int)round(($total / $maxMonth) * 100))) ?>%"></span>
                        <small><?= coord_h($monthLabels[$mes]) ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="admin-panel reservations-panel">
            <div class="admin-panel-head">
                <div>
                    <p class="admin-eyebrow">Detalle</p>
                    <h2>Historial de solicitudes</h2>
                </div>
            </div>

            <form class="admin-user-filters" method="get" action="<?= coord_h(app_url('coordinador/historial.php')) ?>">
                <label>
                    <span>Busqueda rapida</span>
                    <input type="search" name="q" value="<?= coord_h($busqueda) ?>" placeholder="Evento, codigo o instructor">
                </label>
                <label>
                    <span>Estado</span>
                    <select name="estado">
                        <option value="">Todos</option>
                        <?php foreach (['Activo', 'Cancelado', 'Finalizado'] as $estado): ?>
                            <option value="<?= coord_h($estado) ?>" <?= $estadoFiltro === $estado ? 'selected' : '' ?>><?= coord_h($estado) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Desde</span>
                    <input type="date" name="desde" value="<?= coord_h($fechaDesde) ?>">
                </label>
                <label>
                    <span>Hasta</span>
                    <input type="date" name="hasta" value="<?= coord_h($fechaHasta) ?>">
                </label>
                <button type="submit">Filtrar</button>
                <a href="<?= coord_h(app_url('coordinador/historial.php')) ?>">Limpiar</a>
            </form>

            <div class="admin-export-bar">
                <a class="admin-export-link" href="<?= coord_h(app_url('coordinador/exportar_historial.php?' . http_build_query(['estado' => $estadoFiltro, 'q' => $busqueda, 'desde' => $fechaDesde, 'hasta' => $fechaHasta]))) ?>">
                    Exportar este historial a CSV
                </a>
            </div>

            <div class="admin-reservation-list">
                <?php if (!$decisiones && !$hayFiltroActivo): ?>
                    <article class="admin-empty-state">
                        <strong>Aun no tienes decisiones registradas.</strong>
                        <span>Cuando apruebes o canceles una solicitud, aparecera en este historial.</span>
                    </article>
                <?php elseif (!$decisiones && $hayFiltroActivo): ?>
                    <article class="admin-empty-state">
                        <strong>No hay resultados para ese filtro.</strong>
                        <span>
                            Tienes <?= coord_h($totalDecisionesSinFiltro) ?> decision(es) registradas en total, pero ninguna coincide con
                            "<?= coord_h($busqueda !== '' ? $busqueda : $estadoFiltro) ?>". Revisa que el codigo, evento o nombre esten escritos igual,
                            o dale a "Limpiar" para ver todo tu historial.
                        </span>
                    </article>
                <?php endif; ?>

                <?php foreach ($decisiones as $decision): ?>
                    <?php
                    $fecha = new DateTime((string)$decision['fecha_evento']);
                    $estado = (string)$decision['estado'];
                    $statusClass = coord_status_class($estado);
                    $instructor = trim((string)$decision['nombre'] . ' ' . (string)$decision['apellido']);
                    $instructor = $instructor !== '' ? $instructor : 'Instructor SICA';
                    ?>
                    <article class="admin-reservation-card <?= coord_h($statusClass) ?>">
                        <time>
                            <strong><?= coord_h($fecha->format('d')) ?></strong>
                            <span><?= coord_h($fecha->format('M')) ?></span>
                        </time>
                        <div class="admin-reservation-main">
                            <div class="admin-reservation-title">
                                <div>
                                    <h3><?= coord_h($decision['nombre_evento']) ?></h3>
                                </div>
                                <em><?= coord_h($estado) ?></em>
                            </div>
                            <div class="admin-reservation-meta">
                                <span><?= coord_h(coord_hora12((string)$decision['hora_inicio']) . ' - ' . coord_hora12((string)$decision['hora_fin'])) ?></span>
                                <span><?= coord_h($decision['nombre_auditorio'] . ' / Bloque ' . $decision['bloque']) ?></span>
                                <span>Codigo <?= coord_h($decision['codigo_evento']) ?></span>
                                <?php if (!empty($decision['fecha_aprobacion'])): ?>
                                    <span>Decidido el <?= coord_h(substr((string)$decision['fecha_aprobacion'], 0, 10)) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="admin-requester">
                                <strong><?= coord_h($instructor) ?></strong>
                                <small><?= coord_h($decision['correo'] ?? 'Correo no registrado') ?></small>
                            </div>
                            <?php if (!empty($decision['observacion'])): ?>
                                <div class="admin-observation"><?= coord_h($decision['observacion']) ?></div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
<?php coord_layout_end(); ?>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
