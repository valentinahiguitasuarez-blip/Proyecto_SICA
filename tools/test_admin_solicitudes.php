<?php
declare(strict_types=1);

$_SERVER['SCRIPT_NAME'] = '/Proyecto_SICA/admin/solicitudes.php';
$_SERVER['REQUEST_METHOD'] = 'GET';

session_start();
$_SESSION['usuario'] = [
    'id_documento' => '1001001001',
    'nombre' => 'Kevin',
    'apellido' => 'Admin',
    'correo' => 'kevinandres212004@gmail.com',
    'id_rol' => 1,
    'rol' => 'Administrador',
];
$_SESSION['id_rol'] = 1;
$_SESSION['rol'] = 'Administrador';

require __DIR__ . '/../config/conexion.php';

$checks = [];
$pendientesNuevas = $pdo->query(
    "SELECT nombre_evento FROM evento e
     LEFT JOIN estado es ON es.id_estado = e.id_estado
     WHERE e.id_coordinador IS NULL AND (es.nombre_estado = 'Pendiente' OR e.id_estado = 5)
     ORDER BY e.id_evento DESC"
)->fetchAll(PDO::FETCH_COLUMN);
$checks['db_nuevas_count'] = count($pendientesNuevas);
$checks['db_nuevas_eventos'] = implode(', ', $pendientesNuevas);

ob_start();
require __DIR__ . '/../admin/solicitudes.php';
$html = ob_get_clean();

foreach (['junta colectiva', 'salud ocupacional', 'Por asignar', 'admin-request-tabs'] as $needle) {
    $checks['html_' . str_replace(' ', '_', $needle)] = str_contains($html, $needle) ? 'OK' : 'FAIL';
}

$failed = array_filter($checks, static fn($v) => $v === 'FAIL' || $v === 0);

echo "=== PRUEBA ADMIN SOLICITUDES ===\n";
foreach ($checks as $key => $value) {
    echo str_pad($key, 28) . ': ' . $value . "\n";
}
echo $failed ? "\nRESULTADO: FALLO\n" : "\nRESULTADO: OK\n";
exit($failed ? 1 : 0);
