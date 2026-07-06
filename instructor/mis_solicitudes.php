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

// 2) separate: "en proceso" (Pendiente OR Activo con fecha futura) and historical
// Upcoming / in-process: Pendiente (any date) OR Activo with fecha_evento >= CURDATE()
$perPage = 15;
$pagina = max(1, (int)($_GET['pagina'] ?? 1));

$upcomingSql = instructor_event_query() . $where . " AND (es.nombre_estado = 'Pendiente' OR (es.nombre_estado = 'Activo' AND DATE(e.fecha_evento) >= CURDATE())) ORDER BY e.fecha_evento ASC, e.hora_inicio ASC";
$upcoming = instructor_rows($pdo, $upcomingSql, $params);

// Historical: Cancelado, Finalizado, or Activo with fecha_evento < CURDATE()
$countHistoricalSql = 'SELECT COUNT(*) FROM evento e INNER JOIN estado es ON es.id_estado = e.id_estado' . $where . " AND (es.nombre_estado IN ('Cancelado','Finalizado') OR (es.nombre_estado = 'Activo' AND DATE(e.fecha_evento) < CURDATE()))";
$totalHistorical = (int)instructor_scalar($pdo, $countHistoricalSql, $params);
$totalPages = (int)max(1, ceil($totalHistorical / $perPage));
$offset = ($pagina - 1) * $perPage;
$paramsPag = $params;
$paramsPag[':limit'] = $perPage;
$paramsPag[':offset'] = $offset;
$historicalSql = instructor_event_query() . $where . " AND (es.nombre_estado IN ('Cancelado','Finalizado') OR (es.nombre_estado = 'Activo' AND DATE(e.fecha_evento) < CURDATE())) ORDER BY e.fecha_evento DESC, e.hora_inicio DESC LIMIT :limit OFFSET :offset";
$historical = instructor_rows($pdo, $historicalSql, $paramsPag);

// combine for rendering when needed
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

<section class="panel mis-solicitudes">
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

    <?php if (!empty($_GET['preview'])): ?>
    <div class="panel" style="margin-bottom:12px;">
        <div class="panel-head"><div><p class="eyebrow">Vista previa</p><h2>Ejemplos de estado</h2></div></div>
        <div style="padding:12px;">
            <p>Esta vista previa muestra una tarjeta <strong>Cancelado</strong> y otra <strong>Finalizado</strong> para ver el estilo.</p>
            <article class="request-row" style="background:#fff;">
                <div class="request-date"><strong>12</strong><span>Jul</span></div>
                <div style="flex:1;">
                    <h3 style="margin:0">Seminario Taller - Cancelado</h3>
                    <small>Auditorio Demo / Taller - 09:00 a 11:00</small>
                    <div class="stepper" aria-hidden="true" style="margin-top:8px;">
                        <div class="step complete"><div class="dot"></div><div class="label">Solicitado</div></div>
                        <div class="step complete"><div class="dot"></div><div class="label">En revisión</div></div>
                        <div class="step complete step-decision cancel"><div class="dot"></div><div class="label">Cancelado</div></div>
                        <div class="step complete"><div class="dot"></div><div class="label">Notificado</div></div>
                    </div>
                </div>
                <a class="status-pill <?= instructor_h(instructor_status_class('Cancelado')) ?>">Cancelado</a>
            </article>

            <article class="request-row" style="background:#fff; margin-top:10px;">
                <div class="request-date"><strong>20</strong><span>Jul</span></div>
                <div style="flex:1;">
                    <h3 style="margin:0">Feria de Proyectos SENA - Finalizado</h3>
                    <small>Auditorio Demo / Conferencia - 14:00 a 16:00</small>
                    <div class="stepper" aria-hidden="true" style="margin-top:8px;">
                        <div class="step complete"><div class="dot"></div><div class="label">Solicitado</div></div>
                        <div class="step complete"><div class="dot"></div><div class="label">En revisión</div></div>
                        <div class="step complete step-decision"><div class="dot"></div><div class="label">Aprobado</div></div>
                        <div class="step complete"><div class="dot"></div><div class="label">Notificado</div></div>
                    </div>
                </div>
                <a class="status-pill <?= instructor_h(instructor_status_class('Finalizado')) ?>">Finalizado</a>
            </article>
        </div>
    </div>
    <?php endif; ?>

    <style>
    /* Alerts and small utilities */
    .obs-banner { background: var(--ins-red-soft, #fdecea); color: var(--ins-red, #c0392b); padding:8px 12px; border-radius:6px; margin-bottom:8px; }
    /* Scoped palette for Mis Solicitudes page */
    .mis-solicitudes .badge-new { background:#6b46c1; color:#fff; padding:4px 10px; border-radius:12px; font-size:12px; margin-left:8px; font-weight:900; }

    .stepper { display:flex; gap:12px; align-items:center; margin-top:8px; }
    .step { display:flex; align-items:center; gap:8px; font-size:13px; color:#666; }
    .step .dot { width:12px; height:12px; border-radius:50%; background:#ddd; box-shadow:0 0 0 4px transparent; }
    /* Use purple for primary steps on this page */
    .step.complete .dot { background:var(--ins-blue, #0ea5e9); box-shadow:0 0 0 4px rgba(14,165,233,0.12); }
    .mis-solicitudes .step.complete .dot { background:#6b46c1; box-shadow:0 0 0 4px rgba(107,70,193,0.12); }
    .step.complete.step-decision .dot { background:var(--ins-green, #10b981); box-shadow:0 0 0 4px rgba(16,185,129,0.12); }
    .step.complete.step-decision.cancel .dot { background:var(--ins-red, #ef4444); box-shadow:0 0 0 4px rgba(239,68,68,0.12); }
    .step .label { white-space:nowrap; }
    .stepper::before { content:''; position:relative; left:0; }

    /* Card layout tweaks */
    .mis-solicitudes .request-row { padding:16px; border:1px solid rgba(14,21,40,0.04); border-radius:10px; margin-bottom:12px; display:flex; gap:16px; align-items:flex-start; background:#ffffff; box-shadow:0 6px 20px rgba(22,93,255,0.03); }
    .mis-solicitudes .request-date { width:72px; text-align:center; font-weight:900; color:#3b0f6b; background: linear-gradient(180deg,#f3ecff,#efe8ff); border-radius:8px; padding:10px 6px; display:flex; flex-direction:column; justify-content:center; align-items:center; }
    .mis-solicitudes .request-date strong { font-size:20px; color:#3b0f6b; display:block; }
    .mis-solicitudes .request-date span { font-size:11px; color:#6b46c1; text-transform:uppercase; }
    .mis-solicitudes h3 { color:#0e1a2f; margin:0; }
    .mis-solicitudes small { color: #65748b; display:block; margin-top:6px; }

    /* Status pill overrides - keep Pendientes amber, style Activos as purple */
    .mis-solicitudes .status-pill { padding:8px 12px; border-radius:999px; font-weight:900; text-decoration:none; }
    .mis-solicitudes .status-pill.pending { background: var(--ins-amber-soft); color: var(--ins-amber); }
    .mis-solicitudes .status-pill.ok { background: #f4f3ff; color: #5b21b6; }
    .mis-solicitudes .status-pill.danger { background: var(--ins-red-soft); color: var(--ins-red); }

    /* Metric tile override: keep Pendientes amber, set Activos (navy) to purple on this page */
    .mis-solicitudes .metric-tile.amber strong,
    .mis-solicitudes .metric-tile.amber em { color: var(--ins-amber); }

    .mis-solicitudes .metric-tile.navy strong,
    .mis-solicitudes .metric-tile.navy em { color: #6b46c1; }
    .mis-solicitudes .metric-tile.navy::before { background: #6b46c1; }
    .mis-solicitudes .metric-tile.navy::after,
    .mis-solicitudes .metric-tile.navy em { background: #efe8ff; }

    /* Keep default request-date for other pages */
    .request-date { width:72px; text-align:center; font-weight:700; color:#333; }
    </style>

    <!-- Upcoming events -->
    <div class="request-list">
        <h3 style="margin:8px 0;">En proceso</h3>
        <?php if (!$upcoming): ?><div class="empty-state">No hay solicitudes en proceso.</div><?php endif; ?>
        <?php foreach ($upcoming as $evento): ?>
            <?php $fecha = new DateTime((string)$evento['fecha_evento']);
                  $estado = (string)($evento['estado'] ?? '');
                  $hasCoord = !empty($evento['id_coordinador']);
                  $hasDecision = $hasCoord && !empty($evento['fecha_aprobacion']);
                  if (in_array($estado, ['Finalizado','Cancelado'], true)) {
                      $hasDecision = true;
                  }
                  $isActivo = $estado === 'Activo';
                  $isCancelado = $estado === 'Cancelado';
                  $aprobDate = null;
                  try { if (!empty($evento['fecha_aprobacion'])) $aprobDate = new DateTime((string)$evento['fecha_aprobacion']); } catch (Exception $e) { $aprobDate = null; }
                  $isNuevo = $aprobDate ? ($aprobDate >= new DateTime('-3 days')) : false;
            ?>
            <?php if ($isCancelado && trim((string)($evento['observacion'] ?? '')) !== ''): ?>
                <div class="obs-banner"><?= instructor_h($evento['observacion']) ?></div>
            <?php endif; ?>
            <article class="request-row">
                <div class="request-date"><?= instructor_h($fecha->format('d M')) ?></div>
                <div style="flex:1;">
                    <div style="display:flex; align-items:center; gap:8px;">
                      <h3 style="margin:0"><?= instructor_h($evento['nombre_evento']) ?></h3>
                      <?php if ($isNuevo): ?><span class="badge-new">Nuevo</span><?php endif; ?>
                    </div>
                    <small><?= instructor_h($evento['nombre_auditorio']) ?> / <?= instructor_h($evento['nombre_tipo']) ?> - <?= instructor_h(substr((string)$evento['hora_inicio'], 0, 5)) ?> a <?= instructor_h(substr((string)$evento['hora_fin'], 0, 5)) ?></small>

                    <div class="stepper" aria-hidden="true">
                        <div class="step complete"><div class="dot"></div><div class="label">Solicitado</div></div>
                        <div class="step <?= $hasCoord ? 'complete' : '' ?>"><div class="dot"></div><div class="label">En revisión</div></div>
                        <?php $decClass = 'step' . ($hasDecision ? ' complete step-decision' : ''); $decClass .= ($hasDecision && $isCancelado) ? ' cancel' : ''; ?>
                        <div class="<?= $decClass ?>"><div class="dot"></div><div class="label">Decisión<?php if ($hasDecision && $aprobDate) echo ' - ' . instructor_h($aprobDate->format('Y-m-d')); ?></div></div>
                        <div class="step <?= $hasDecision ? 'complete' : '' ?>"><div class="dot"></div><div class="label">Notificado</div></div>
                    </div>
                </div>
                <a class="status-pill <?= instructor_h(instructor_status_class((string)$evento['estado'])) ?>" href="<?= instructor_h(app_url('instructor/detalle_solicitud.php?id=' . (int)$evento['id_evento'])) ?>"><?= instructor_h($evento['estado']) ?></a>
            </article>
        <?php endforeach; ?>
    </div>

    <!-- Historical (paginated) -->
    <div class="request-list" style="margin-top:18px;">
        <h3 style="margin:8px 0;">Historial</h3>
        <?php if (!$historical): ?>
            <?php if ($estadoFiltro === 'Cancelado'): ?>
                <article class="request-row">
                    <div class="request-date"><strong>12</strong><span>Jul</span></div>
                    <div style="flex:1;">
                        <h3 style="margin:0">Evento ejemplo - Cancelado</h3>
                        <small>Auditorio Demo / Taller - 09:00 a 11:00</small>
                        <div class="stepper" aria-hidden="true" style="margin-top:8px;">
                            <div class="step complete"><div class="dot"></div><div class="label">Solicitado</div></div>
                            <div class="step complete"><div class="dot"></div><div class="label">En revisión</div></div>
                            <div class="step complete step-decision cancel"><div class="dot"></div><div class="label">Cancelado</div></div>
                            <div class="step complete"><div class="dot"></div><div class="label">Notificado</div></div>
                        </div>
                    </div>
                    <a class="status-pill <?= instructor_h(instructor_status_class('Cancelado')) ?>">Cancelado</a>
                </article>
            <?php elseif ($estadoFiltro === 'Finalizado'): ?>
                <article class="request-row">
                    <div class="request-date"><strong>20</strong><span>Jul</span></div>
                    <div style="flex:1;">
                        <h3 style="margin:0">Evento ejemplo - Finalizado</h3>
                        <small>Auditorio Demo / Conferencia - 14:00 a 16:00</small>
                        <div class="stepper" aria-hidden="true" style="margin-top:8px;">
                            <div class="step complete"><div class="dot"></div><div class="label">Solicitado</div></div>
                            <div class="step complete"><div class="dot"></div><div class="label">En revisión</div></div>
                            <div class="step complete step-decision"><div class="dot"></div><div class="label">Aprobado</div></div>
                            <div class="step complete"><div class="dot"></div><div class="label">Notificado</div></div>
                        </div>
                    </div>
                    <a class="status-pill <?= instructor_h(instructor_status_class('Finalizado')) ?>">Finalizado</a>
                </article>
            <?php else: ?>
                <div class="empty-state">No hay solicitudes en el historial para este filtro.</div>
            <?php endif; ?>
        <?php endif; ?>
        <?php foreach ($historical as $evento): ?>
            <?php $fecha = new DateTime((string)$evento['fecha_evento']);
                  $estado = (string)($evento['estado'] ?? '');
                  $hasCoord = !empty($evento['id_coordinador']);
                  $hasDecision = $hasCoord && !empty($evento['fecha_aprobacion']);
                  if (in_array($estado, ['Finalizado','Cancelado'], true)) {
                      $hasDecision = true;
                  }
                  $isActivo = $estado === 'Activo';
                  $isCancelado = $estado === 'Cancelado';
                  $aprobDate = null;
                  try { if (!empty($evento['fecha_aprobacion'])) $aprobDate = new DateTime((string)$evento['fecha_aprobacion']); } catch (Exception $e) { $aprobDate = null; }
                  $isNuevo = $aprobDate ? ($aprobDate >= new DateTime('-3 days')) : false;
            ?>
            <?php if ($isCancelado && trim((string)($evento['observacion'] ?? '')) !== ''): ?>
                <div class="obs-banner"><?= instructor_h($evento['observacion']) ?></div>
            <?php endif; ?>
            <article class="request-row">
                <div class="request-date"><?= instructor_h($fecha->format('d M')) ?></div>
                <div style="flex:1;">
                    <div style="display:flex; align-items:center; gap:8px;">
                      <h3 style="margin:0"><?= instructor_h($evento['nombre_evento']) ?></h3>
                      <?php if ($isNuevo): ?><span class="badge-new">Nuevo</span><?php endif; ?>
                    </div>
                    <small><?= instructor_h($evento['nombre_auditorio']) ?> / <?= instructor_h($evento['nombre_tipo']) ?> - <?= instructor_h(substr((string)$evento['hora_inicio'], 0, 5)) ?> a <?= instructor_h(substr((string)$evento['hora_fin'], 0, 5)) ?></small>

                    <div class="stepper" aria-hidden="true">
                        <div class="step complete"><div class="dot"></div><div class="label">Solicitado</div></div>
                        <div class="step <?= $hasCoord ? 'complete' : '' ?>"><div class="dot"></div><div class="label">En revisión</div></div>
                        <?php $decClass = 'step' . ($hasDecision ? ' complete step-decision' : ''); $decClass .= ($hasDecision && $isCancelado) ? ' cancel' : ''; ?>
                        <div class="<?= $decClass ?>"><div class="dot"></div><div class="label">Decisión<?php if ($hasDecision && $aprobDate) echo ' - ' . instructor_h($aprobDate->format('Y-m-d')); ?></div></div>
                        <div class="step <?= $hasDecision ? 'complete' : '' ?>"><div class="dot"></div><div class="label">Notificado</div></div>
                    </div>
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
