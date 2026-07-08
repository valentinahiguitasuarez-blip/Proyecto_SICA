<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([2]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/coordinador_panel.php';

$pageTitle = 'Auditorios - Coordinador SICA';
$pageStyles = ['css/instructor.css'];

$auditorios = [];
$stats = ['total' => 0, 'activos' => 0, 'capacidadTotal' => 0];

try {
    $auditorios = coord_rows(
        $pdo,
        'SELECT a.id_auditorio, a.nombre_auditorio, a.bloque, a.capacidad,
                a.cantidad_computadores, a.tiene_aire_acondicionado, a.tiene_ventilador, a.tiene_tablero, a.tiene_televisor,
                aes.nombre_estado AS estado,
                SUM(CASE WHEN es.nombre_estado = \'Pendiente\' THEN 1 ELSE 0 END) AS pendientes,
                SUM(CASE WHEN es.nombre_estado = \'Activo\' AND e.fecha_evento >= CURDATE() THEN 1 ELSE 0 END) AS proximos
         FROM auditorio a
         INNER JOIN estado aes ON aes.id_estado = a.id_estado
         LEFT JOIN evento e ON e.id_auditorio = a.id_auditorio
         LEFT JOIN estado es ON es.id_estado = e.id_estado
         GROUP BY a.id_auditorio, a.nombre_auditorio, a.bloque, a.capacidad,
                  a.cantidad_computadores, a.tiene_aire_acondicionado, a.tiene_ventilador, a.tiene_tablero, a.tiene_televisor,
                  aes.nombre_estado
         ORDER BY a.nombre_auditorio ASC'
    );

    $stats['total'] = count($auditorios);
    foreach ($auditorios as $auditorio) {
        if ((string)$auditorio['estado'] === 'Activo') {
            $stats['activos']++;
        }
        $stats['capacidadTotal'] += (int)$auditorio['capacidad'];
    }
} catch (Throwable $exception) {
    error_log('SICA coordinador auditorios: ' . $exception->getMessage());
}
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>
<?php coord_layout_start('auditorios'); ?>

<header class="instructor-topbar">
    <div>
        <p class="eyebrow">Espacios</p>
        <h1>Auditorios disponibles</h1>
        <span>Consulta capacidad y dotación antes de aprobar una solicitud. Solo el administrador puede crear o editar espacios.</span>
    </div>
    <div class="topbar-actions">
        <a class="top-action" href="<?= coord_h(app_url('coordinador/calendario.php')) ?>">Calendario</a>
        <a class="top-action" href="<?= coord_h(app_url('coordinador/solicitudes.php')) ?>">Solicitudes</a>
    </div>
</header>

<section class="metric-grid" aria-label="Resumen de auditorios">
    <article class="metric-tile blue"><span>Total auditorios</span><strong><?= coord_h($stats['total']) ?></strong><small>Espacios registrados</small><em>Directorio</em></article>
    <article class="metric-tile green"><span>Activos</span><strong><?= coord_h($stats['activos']) ?></strong><small>Disponibles para reservar</small><em>Estado</em></article>
    <article class="metric-tile navy"><span>Capacidad total</span><strong><?= coord_h($stats['capacidadTotal']) ?></strong><small>Personas en todos los espacios</small><em>Cupos</em></article>
</section>

<section class="panel">
    <div class="panel-head">
        <div>
            <p class="eyebrow">Directorio</p>
            <h2>Espacios registrados</h2>
            <span class="panel-subtitle">Misma ficha de dotación que ve el instructor. Los campos sin dato aparecen como Por registrar.</span>
        </div>
    </div>

    <div class="coord-auditorium-directory">
        <?php if (!$auditorios): ?>
            <div class="empty-state">No hay auditorios registrados. El administrador aún no ha creado espacios en el sistema.</div>
        <?php endif; ?>

        <?php foreach ($auditorios as $auditorio): ?>
            <article class="coord-auditorium-card <?= (string)$auditorio['estado'] === 'Activo' ? 'ok' : 'off' ?>">
                <div class="coord-auditorium-card-head">
                    <div>
                        <p class="eyebrow">Auditorio</p>
                        <h3><?= coord_h($auditorio['nombre_auditorio']) ?></h3>
                        <span class="panel-subtitle">Bloque <?= coord_h($auditorio['bloque']) ?></span>
                    </div>
                    <span class="status-pill <?= (string)$auditorio['estado'] === 'Activo' ? 'ok' : 'muted' ?>"><?= coord_h($auditorio['estado']) ?></span>
                </div>

                <article class="auditorium-feature-card" aria-label="Dotación de <?= coord_h($auditorio['nombre_auditorio']) ?>">
                    <div>
                        <p class="eyebrow">Características del auditorio</p>
                        <span>Información disponible para decidir antes de aprobar una solicitud.</span>
                    </div>
                    <div class="auditorium-feature-grid">
                        <span><strong><?= coord_h($auditorio['bloque']) ?></strong><small class="feature-label">Bloque</small></span>
                        <span><strong><?= coord_h($auditorio['capacidad']) ?></strong><small class="feature-label">Cupos máximos</small></span>
                        <span><strong class="<?= coord_h(coord_dotacion_value_class($auditorio['cantidad_computadores'] ?? null)) ?>"><?= coord_h(coord_computadores_label($auditorio['cantidad_computadores'] ?? null)) ?></strong><small class="feature-label">Computadores</small></span>
                        <span><strong class="<?= coord_h(coord_dotacion_value_class($auditorio['tiene_aire_acondicionado'] ?? null)) ?>"><?= coord_h(coord_dotacion_label($auditorio['tiene_aire_acondicionado'] ?? null)) ?></strong><small class="feature-label">Aire acondicionado</small></span>
                        <span><strong class="<?= coord_h(coord_dotacion_value_class($auditorio['tiene_ventilador'] ?? null)) ?>"><?= coord_h(coord_dotacion_label($auditorio['tiene_ventilador'] ?? null)) ?></strong><small class="feature-label">Ventilador</small></span>
                        <span><strong class="<?= coord_h(coord_dotacion_value_class($auditorio['tiene_tablero'] ?? null)) ?>"><?= coord_h(coord_dotacion_label($auditorio['tiene_tablero'] ?? null)) ?></strong><small class="feature-label">Tablero / pizarra</small></span>
                        <span><strong class="<?= coord_h(coord_dotacion_value_class($auditorio['tiene_televisor'] ?? null)) ?>"><?= coord_h(coord_dotacion_label($auditorio['tiene_televisor'] ?? null)) ?></strong><small class="feature-label">Televisor</small></span>
                        <span><strong><?= coord_h((int)$auditorio['pendientes']) ?></strong><small class="feature-label">Solicitudes pendientes</small></span>
                        <span><strong><?= coord_h((int)$auditorio['proximos']) ?></strong><small class="feature-label">Próximas reservas</small></span>
                    </div>
                </article>

                <div class="hero-actions">
                    <a class="primary-btn" href="<?= coord_h(app_url('coordinador/calendario.php?auditorio=' . (int)$auditorio['id_auditorio'])) ?>">Ver calendario</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<?php coord_layout_end(); ?>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
