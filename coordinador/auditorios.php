<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([2]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/coordinador_panel.php';

$pageTitle = 'Auditorios - Coordinador SICA';
$pageStyles = ['css/admin.css'];

$auditorios = [];
$stats = ['total' => 0, 'activos' => 0, 'capacidadTotal' => 0];
try {
    $auditorios = coord_rows(
        $pdo,
        'SELECT a.id_auditorio, a.nombre_auditorio, a.bloque, a.capacidad, aes.nombre_estado AS estado,
                SUM(CASE WHEN es.nombre_estado = \'Pendiente\' THEN 1 ELSE 0 END) AS pendientes,
                SUM(CASE WHEN es.nombre_estado = \'Activo\' AND e.fecha_evento >= CURDATE() THEN 1 ELSE 0 END) AS proximos
         FROM auditorio a
         INNER JOIN estado aes ON aes.id_estado = a.id_estado
         LEFT JOIN evento e ON e.id_auditorio = a.id_auditorio
         LEFT JOIN estado es ON es.id_estado = e.id_estado
         GROUP BY a.id_auditorio, a.nombre_auditorio, a.bloque, a.capacidad, aes.nombre_estado
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

        <header class="admin-topbar">
            <div>
                <p class="admin-eyebrow">Espacios</p>
                <h1>Auditorios disponibles</h1>
                <span>Consulta capacidad y ocupacion antes de aprobar una solicitud. Solo el administrador puede crear o editar espacios.</span>
            </div>
        </header>

        <section class="admin-metrics" aria-label="Resumen de auditorios">
            <article class="admin-metric">
                <span>Total auditorios</span>
                <strong><?= coord_h($stats['total']) ?></strong>
                <small>Espacios registrados</small>
            </article>
            <article class="admin-metric">
                <span>Activos</span>
                <strong><?= coord_h($stats['activos']) ?></strong>
                <small>Disponibles para reservar</small>
            </article>
            <article class="admin-metric">
                <span>Capacidad total</span>
                <strong><?= coord_h($stats['capacidadTotal']) ?></strong>
                <small>Personas en todos los espacios</small>
            </article>
        </section>

        <section class="admin-panel">
            <div class="admin-panel-head">
                <div>
                    <p class="admin-eyebrow">Directorio</p>
                    <h2>Espacios registrados</h2>
                </div>
            </div>

            <div class="admin-auditorios-list">
                <?php if (!$auditorios): ?>
                    <article class="admin-empty-state">
                        <strong>No hay auditorios registrados.</strong>
                        <span>El administrador aun no ha creado espacios en el sistema.</span>
                    </article>
                <?php endif; ?>

                <?php foreach ($auditorios as $auditorio): ?>
                    <article class="admin-auditorio-card <?= (string)$auditorio['estado'] === 'Activo' ? 'ok' : 'off' ?>">
                        <div>
                            <h3><?= coord_h($auditorio['nombre_auditorio']) ?></h3>
                            <span>Bloque <?= coord_h($auditorio['bloque']) ?></span>
                        </div>
                        <div class="admin-auditorio-meta">
                            <span>Capacidad <strong><?= coord_h($auditorio['capacidad']) ?></strong></span>
                            <span>Pendientes <strong><?= coord_h((int)$auditorio['pendientes']) ?></strong></span>
                            <span>Proximas activas <strong><?= coord_h((int)$auditorio['proximos']) ?></strong></span>
                        </div>
                        <em><?= coord_h($auditorio['estado']) ?></em>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
<?php coord_layout_end(); ?>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
