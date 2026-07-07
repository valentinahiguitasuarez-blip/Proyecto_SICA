<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([1]);
require_once __DIR__ . '/../config/conexion.php';

$pageTitle = 'Reportes - Administrador SICA';
$pageStyles = ['css/admin.css'];

$usuario = $_SESSION['usuario'] ?? [];
$adminName = trim((string)($usuario['nombre'] ?? 'Administrador'));
$adminMail = (string)($usuario['correo'] ?? 'admin@sica.edu.co');

function admin_r_h(string|int|null $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function admin_r_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function admin_r_scalar(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

$stats = ['usuarios' => 0, 'eventos' => 0, 'pre' => 0, 'asistencias' => 0];
$porEstado = [];
$porRol = [];
$eventos = [];

try {
    $stats['usuarios'] = admin_r_scalar($pdo, 'SELECT COUNT(*) FROM usuario');
    $stats['eventos'] = admin_r_scalar($pdo, 'SELECT COUNT(*) FROM evento');
    $stats['pre'] = admin_r_scalar($pdo, 'SELECT COUNT(*) FROM preregistro');
    $stats['asistencias'] = admin_r_scalar($pdo, "SELECT COUNT(*) FROM preregistro WHERE asistencia <> 'Pendiente'");

    $porEstado = admin_r_rows(
        $pdo,
        'SELECT es.nombre_estado AS estado, COUNT(*) total
         FROM evento e
         INNER JOIN estado es ON es.id_estado = e.id_estado
         GROUP BY es.nombre_estado
         ORDER BY total DESC'
    );
    $porRol = admin_r_rows(
        $pdo,
        'SELECT r.nombre_rol AS rol, COUNT(*) total
         FROM usuario u
         INNER JOIN rol r ON r.id_rol = u.id_rol
         GROUP BY r.nombre_rol
         ORDER BY total DESC'
    );
    $eventos = admin_r_rows(
        $pdo,
        'SELECT e.nombre_evento, e.fecha_evento, es.nombre_estado AS estado,
                a.nombre_auditorio,
                COUNT(pr.id_preregistro) AS preregistros,
                SUM(CASE WHEN pr.asistencia <> \'Pendiente\' THEN 1 ELSE 0 END) AS asistencias
         FROM evento e
         INNER JOIN estado es ON es.id_estado = e.id_estado
         INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
         LEFT JOIN preregistro pr ON pr.id_evento = e.id_evento
         GROUP BY e.id_evento, e.nombre_evento, e.fecha_evento, es.nombre_estado, a.nombre_auditorio
         ORDER BY e.fecha_evento DESC
         LIMIT 12'
    );
} catch (Throwable $exception) {
    error_log('SICA admin reportes: ' . $exception->getMessage());
}
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>

<main class="admin-dashboard">
    <aside class="admin-sidebar" aria-label="Menu del administrador">
        <a class="admin-brand" href="<?= admin_r_h(app_url('admin/index.php')) ?>">
            <span><strong>SICA</strong><small>Sistema Inteligente de Control de Asistencia</small></span>
        </a>
        <section class="admin-profile" aria-label="Administrador activo">
            <div class="admin-avatar">AD</div>
            <div><strong><?= admin_r_h($adminName) ?></strong><small><?= admin_r_h($adminMail) ?></small><span>En linea</span></div>
        </section>
        <nav class="admin-nav">
            <a href="<?= admin_r_h(app_url('admin/index.php')) ?>"><span>PC</span>Panel de Control</a>
            <a href="<?= admin_r_h(app_url('admin/usuarios.php')) ?>"><span>US</span>Usuarios</a>
            <a href="<?= admin_r_h(app_url('admin/solicitudes.php')) ?>"><span>SR</span>Solicitudes de Reserva</a>
            <a href="<?= admin_r_h(app_url('admin/correos.php')) ?>"><span>CN</span>Correos y Notificaciones</a>
            <a href="<?= admin_r_h(app_url('admin/auditorios.php')) ?>"><span>AU</span>Auditorios</a>
            <a class="active" href="<?= admin_r_h(app_url('admin/reportes.php')) ?>"><span>RP</span>Reportes</a>
        </nav>
    </aside>

    <section class="admin-main">
        <header class="admin-topbar">
            <div>
                <p class="admin-eyebrow">Analitica</p>
                <h1>Reportes</h1>
                <span>Resumen administrativo de usuarios, reservas, pre-registros y asistencias.</span>
            </div>
            <div class="admin-top-actions">
                <a href="<?= admin_r_h(app_url('admin/index.php')) ?>">Panel <strong>PC</strong></a>
                <a class="admin-logout" href="<?= admin_r_h(app_url('login/logout.php')) ?>">Cerrar sesion</a>
            </div>
        </header>

        <section class="admin-metrics reservation-metrics" aria-label="Resumen general">
            <article class="admin-metric"><span>Usuarios</span><strong><?= admin_r_h($stats['usuarios']) ?></strong><small>Cuentas registradas</small></article>
            <article class="admin-metric"><span>Eventos</span><strong><?= admin_r_h($stats['eventos']) ?></strong><small>Solicitudes y reservas</small></article>
            <article class="admin-metric"><span>Pre-registros</span><strong><?= admin_r_h($stats['pre']) ?></strong><small>Aprendices inscritos</small></article>
            <article class="admin-metric"><span>Asistencias</span><strong><?= admin_r_h($stats['asistencias']) ?></strong><small>Ingresos confirmados</small></article>
        </section>

        <section class="admin-grid">
            <article class="admin-panel">
                <div class="admin-panel-head"><div><p class="admin-eyebrow">Reservas</p><h2>Eventos por estado</h2></div></div>
                <div class="reservation-summary">
                    <?php foreach ($porEstado as $row): ?>
                        <div><span><?= admin_r_h($row['estado']) ?></span><strong><?= admin_r_h($row['total']) ?></strong></div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="admin-panel">
                <div class="admin-panel-head"><div><p class="admin-eyebrow">Usuarios</p><h2>Cuentas por rol</h2></div></div>
                <div class="reservation-summary">
                    <?php foreach ($porRol as $row): ?>
                        <div><span><?= admin_r_h($row['rol']) ?></span><strong><?= admin_r_h($row['total']) ?></strong></div>
                    <?php endforeach; ?>
                </div>
            </article>
        </section>

        <section class="admin-panel reservations-panel">
            <div class="admin-panel-head"><div><p class="admin-eyebrow">Eventos</p><h2>Reporte reciente</h2></div></div>
            <div class="admin-report-table">
                <div class="admin-report-row head"><span>Evento</span><span>Auditorio</span><span>Estado</span><span>Pre-registros</span><span>Asistencias</span></div>
                <?php foreach ($eventos as $evento): ?>
                    <div class="admin-report-row">
                        <span><?= admin_r_h($evento['nombre_evento']) ?><small><?= admin_r_h($evento['fecha_evento']) ?></small></span>
                        <span><?= admin_r_h($evento['nombre_auditorio']) ?></span>
                        <span><?= admin_r_h($evento['estado']) ?></span>
                        <span><?= admin_r_h($evento['preregistros'] ?? 0) ?></span>
                        <span><?= admin_r_h($evento['asistencias'] ?? 0) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </section>
</main>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
