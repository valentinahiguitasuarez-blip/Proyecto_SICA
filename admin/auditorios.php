<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([1]);
require_once __DIR__ . '/../config/conexion.php';

$pageTitle = 'Auditorios - Administrador SICA';
$pageStyles = ['css/admin.css'];

$usuario = $_SESSION['usuario'] ?? [];
$adminName = trim((string)($usuario['nombre'] ?? 'Administrador'));
$adminMail = (string)($usuario['correo'] ?? 'admin@sica.edu.co');

function admin_a_h(string|int|null $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function admin_a_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function admin_a_scalar(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

$auditorios = [];
$stats = ['activos' => 0, 'capacidad' => 0, 'eventos' => 0, 'ocupados' => 0];
$busqueda = trim((string)($_GET['q'] ?? ''));
$params = [];
$where = '';

if ($busqueda !== '') {
    $where = ' WHERE a.nombre_auditorio LIKE :q OR a.bloque LIKE :q OR es.nombre_estado LIKE :q';
    $params[':q'] = '%' . $busqueda . '%';
}

try {
    $stats['activos'] = admin_a_scalar(
        $pdo,
        "SELECT COUNT(*)
         FROM auditorio a
         INNER JOIN estado es ON es.id_estado = a.id_estado
         WHERE es.nombre_estado = 'Activo'"
    );
    $stats['capacidad'] = admin_a_scalar($pdo, 'SELECT COALESCE(SUM(capacidad), 0) FROM auditorio');
    $stats['eventos'] = admin_a_scalar($pdo, 'SELECT COUNT(*) FROM evento');
    $stats['ocupados'] = admin_a_scalar(
        $pdo,
        "SELECT COUNT(DISTINCT e.id_auditorio)
         FROM evento e
         INNER JOIN estado es ON es.id_estado = e.id_estado
         WHERE es.nombre_estado IN ('Activo', 'Pendiente')
           AND DATE(e.fecha_evento) >= CURDATE()"
    );

    $auditorios = admin_a_rows(
        $pdo,
        'SELECT a.id_auditorio, a.nombre_auditorio, a.bloque, a.capacidad, es.nombre_estado AS estado,
                COUNT(e.id_evento) AS eventos_total,
                SUM(CASE WHEN evs.nombre_estado IN (\'Activo\', \'Pendiente\') AND DATE(e.fecha_evento) >= CURDATE() THEN 1 ELSE 0 END) AS eventos_proximos
         FROM auditorio a
         INNER JOIN estado es ON es.id_estado = a.id_estado
         LEFT JOIN evento e ON e.id_auditorio = a.id_auditorio
         LEFT JOIN estado evs ON evs.id_estado = e.id_estado' .
            $where .
        ' GROUP BY a.id_auditorio, a.nombre_auditorio, a.bloque, a.capacidad, es.nombre_estado
          ORDER BY a.nombre_auditorio ASC',
        $params
    );
} catch (Throwable $exception) {
    error_log('SICA admin auditorios: ' . $exception->getMessage());
}
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>

<main class="admin-dashboard">
    <aside class="admin-sidebar" aria-label="Menu del administrador">
        <a class="admin-brand" href="<?= admin_a_h(app_url('admin/index.php')) ?>">
            <span><strong>SICA</strong><small>Sistema Inteligente de Control de Asistencia</small></span>
        </a>
        <section class="admin-profile" aria-label="Administrador activo">
            <div class="admin-avatar">AD</div>
            <div><strong><?= admin_a_h($adminName) ?></strong><small><?= admin_a_h($adminMail) ?></small><span>En linea</span></div>
        </section>
        <nav class="admin-nav">
            <a href="<?= admin_a_h(app_url('admin/index.php')) ?>"><span>PC</span>Panel de Control</a>
            <a href="<?= admin_a_h(app_url('admin/usuarios.php')) ?>"><span>US</span>Usuarios</a>
            <a href="<?= admin_a_h(app_url('admin/solicitudes.php')) ?>"><span>SR</span>Solicitudes de Reserva</a>
            <a href="<?= admin_a_h(app_url('admin/correos.php')) ?>"><span>CN</span>Correos y Notificaciones</a>
            <a class="active" href="<?= admin_a_h(app_url('admin/auditorios.php')) ?>"><span>AU</span>Auditorios</a>
            <a href="<?= admin_a_h(app_url('admin/reportes.php')) ?>"><span>RP</span>Reportes</a>
        </nav>
    </aside>

    <section class="admin-main">
        <header class="admin-topbar">
            <div>
                <p class="admin-eyebrow">Espacios</p>
                <h1>Auditorios</h1>
                <span>Consulta capacidad, estado y uso de los espacios disponibles para eventos.</span>
            </div>
            <div class="admin-top-actions">
                <a href="<?= admin_a_h(app_url('admin/solicitudes.php')) ?>">Reservas <strong>SR</strong></a>
                <a class="admin-logout" href="<?= admin_a_h(app_url('login/logout.php')) ?>">Cerrar sesion</a>
            </div>
        </header>

        <section class="admin-metrics reservation-metrics" aria-label="Resumen de auditorios">
            <article class="admin-metric"><span>Activos</span><strong><?= admin_a_h($stats['activos']) ?></strong><small>Disponibles para solicitud</small></article>
            <article class="admin-metric"><span>Capacidad total</span><strong><?= admin_a_h($stats['capacidad']) ?></strong><small>Cupos combinados</small></article>
            <article class="admin-metric"><span>Eventos</span><strong><?= admin_a_h($stats['eventos']) ?></strong><small>Programados historicos</small></article>
            <article class="admin-metric"><span>Con movimiento</span><strong><?= admin_a_h($stats['ocupados']) ?></strong><small>Activos o pendientes</small></article>
        </section>

        <section class="admin-panel reservations-panel">
            <div class="admin-panel-head">
                <div><p class="admin-eyebrow">Inventario</p><h2>Espacios registrados</h2></div>
            </div>

            <form class="admin-user-filters admin-mail-filters" method="get" action="<?= admin_a_h(app_url('admin/auditorios.php')) ?>">
                <label><span>Busqueda rapida</span><input type="search" name="q" value="<?= admin_a_h($busqueda) ?>" placeholder="Auditorio, bloque o estado"></label>
                <button type="submit">Buscar</button>
                <a href="<?= admin_a_h(app_url('admin/auditorios.php')) ?>">Limpiar</a>
            </form>

            <div class="admin-auditorium-grid">
                <?php if (!$auditorios): ?>
                    <article class="admin-empty-state"><strong>No hay auditorios para mostrar.</strong><span>Cuando registres espacios, apareceran aqui.</span></article>
                <?php endif; ?>
                <?php foreach ($auditorios as $auditorio): ?>
                    <article class="admin-auditorium-card">
                        <div>
                            <p class="admin-eyebrow">Bloque <?= admin_a_h($auditorio['bloque']) ?></p>
                            <h3><?= admin_a_h($auditorio['nombre_auditorio']) ?></h3>
                            <span><?= admin_a_h($auditorio['estado']) ?></span>
                        </div>
                        <div class="admin-reservation-meta">
                            <span>Capacidad <?= admin_a_h($auditorio['capacidad']) ?></span>
                            <span><?= admin_a_h($auditorio['eventos_total'] ?? 0) ?> eventos</span>
                            <span><?= admin_a_h($auditorio['eventos_proximos'] ?? 0) ?> proximos</span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </section>
</main>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
