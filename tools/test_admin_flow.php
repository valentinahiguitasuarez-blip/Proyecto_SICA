<?php
declare(strict_types=1);

/**
 * Prueba integral del flujo admin: dashboard, solicitudes y asignacion de coordinador.
 * Uso: php tools/test_admin_flow.php
 */

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
$failures = [];

function check(array &$checks, array &$failures, string $key, bool $ok, string $detail = ''): void
{
    $checks[$key] = $ok ? 'OK' : ('FAIL' . ($detail !== '' ? " ($detail)" : ''));
    if (!$ok) {
        $failures[] = $key;
    }
}

$admin = $pdo->query(
    "SELECT u.id_documento, u.correo, r.nombre_rol
     FROM usuario u
     INNER JOIN rol r ON r.id_rol = u.id_rol
     WHERE u.id_rol = 1
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
check($checks, $failures, 'admin_exists', (bool)$admin);

$coordinador = $pdo->query(
    "SELECT u.id_documento, u.nombre, u.apellido
     FROM usuario u
     INNER JOIN rol r ON r.id_rol = u.id_rol
     WHERE LOWER(r.nombre_rol) LIKE '%coordinador%'
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
check($checks, $failures, 'coordinador_exists', (bool)$coordinador);

$nuevasCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM evento e
     LEFT JOIN estado es ON es.id_estado = e.id_estado
     WHERE e.id_coordinador IS NULL
       AND (es.nombre_estado = 'Pendiente' OR e.id_estado = 5)"
)->fetchColumn();
$checks['db_nuevas_sin_coordinador'] = (string)$nuevasCount;

$_SERVER['SCRIPT_NAME'] = '/Proyecto_SICA/admin/index.php';
ob_start();
require __DIR__ . '/../admin/index.php';
$indexHtml = ob_get_clean();

check($checks, $failures, 'index_link_solicitudes', str_contains($indexHtml, 'admin/solicitudes.php'));
check($checks, $failures, 'no_banner_extra', !str_contains($indexHtml, 'admin-new-requests-banner'));

$_SERVER['SCRIPT_NAME'] = '/Proyecto_SICA/admin/solicitudes.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
require __DIR__ . '/../admin/solicitudes.php';
$solicitudesHtml = ob_get_clean();

foreach (['Por asignar', 'admin-request-tabs', 'junta colectiva', 'salud ocupacional'] as $needle) {
    check($checks, $failures, 'html_' . str_replace([' ', '-'], '_', $needle), str_contains($solicitudesHtml, $needle));
}

check($checks, $failures, 'no_paso_1_visual', !str_contains($solicitudesHtml, 'Paso 1'));
check($checks, $failures, 'no_tabla_amarilla', !str_contains($solicitudesHtml, 'admin-new-requests-table'));

$testEventId = 14;
$originalCoord = $pdo->query("SELECT id_coordinador FROM evento WHERE id_evento = {$testEventId}")->fetchColumn();

if ($coordinador && $nuevasCount > 0) {
    $coordId = (string)$coordinador['id_documento'];
    $update = $pdo->prepare('UPDATE evento SET id_coordinador = :coord WHERE id_evento = :id AND id_coordinador IS NULL');
    $update->execute([':coord' => $coordId, ':id' => $testEventId]);
    $assigned = (int)$pdo->query("SELECT id_coordinador FROM evento WHERE id_evento = {$testEventId}")->fetchColumn();
    check($checks, $failures, 'asignar_coordinador_db', $assigned === (int)$coordId);

    $enRevision = (int)$pdo->query(
        "SELECT COUNT(*) FROM evento e
         INNER JOIN estado es ON es.id_estado = e.id_estado
         WHERE e.id_evento = {$testEventId}
           AND e.id_coordinador IS NOT NULL
           AND es.nombre_estado = 'Pendiente'"
    )->fetchColumn();
    check($checks, $failures, 'evento_en_revision', $enRevision === 1);

    $pdo->prepare('UPDATE evento SET id_coordinador = NULL WHERE id_evento = :id')
        ->execute([':id' => $testEventId]);
    $reverted = $pdo->query("SELECT id_coordinador FROM evento WHERE id_evento = {$testEventId}")->fetchColumn();
    check($checks, $failures, 'revertir_asignacion', $reverted === null || $reverted === $originalCoord);
} else {
    $checks['asignar_coordinador_db'] = 'SKIP (sin datos)';
}

$httpUrl = 'http://localhost/Proyecto_SICA/admin/solicitudes.php';
$ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
$httpBody = @file_get_contents($httpUrl, false, $ctx);
if ($httpBody !== false) {
    check($checks, $failures, 'http_responde', strlen($httpBody) > 100);
    check($checks, $failures, 'http_redirige_login', str_contains($httpBody, 'login') || str_contains($httpBody, 'Inicio de sesi'));
} else {
    $checks['http_responde'] = 'SKIP (Apache no disponible)';
}

echo "=== PRUEBA INTEGRAL ADMIN SICA ===\n\n";
foreach ($checks as $key => $value) {
    echo str_pad($key, 32) . ': ' . $value . "\n";
}
echo "\n" . ($failures ? "RESULTADO: FALLO (" . count($failures) . ")\n" : "RESULTADO: OK\n");
exit($failures ? 1 : 0);
