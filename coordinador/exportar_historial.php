<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([2]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/coordinador_panel.php';

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

$decisiones = coord_rows(
    $pdo,
    'SELECT e.nombre_evento, e.codigo_evento, e.fecha_evento, e.hora_inicio, e.hora_fin,
            e.observacion, e.fecha_aprobacion, es.nombre_estado AS estado,
            a.nombre_auditorio, a.bloque, u.nombre, u.apellido, u.correo
     FROM evento e
     INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
     INNER JOIN estado es ON es.id_estado = e.id_estado
     LEFT JOIN usuario u ON u.id_documento = e.id_solicitante
     ' . $whereSql . '
     ORDER BY e.fecha_aprobacion DESC',
    $params
);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="historial-coordinacion-sica-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
fputcsv($out, ['Codigo', 'Evento', 'Estado', 'Auditorio', 'Bloque', 'Fecha evento', 'Hora inicio', 'Hora fin', 'Instructor', 'Correo instructor', 'Observacion', 'Fecha decision']);
foreach ($decisiones as $d) {
    fputcsv($out, [
        $d['codigo_evento'],
        $d['nombre_evento'],
        $d['estado'],
        $d['nombre_auditorio'],
        $d['bloque'],
        $d['fecha_evento'],
        substr((string)$d['hora_inicio'], 0, 5),
        substr((string)$d['hora_fin'], 0, 5),
        trim((string)$d['nombre'] . ' ' . (string)$d['apellido']),
        $d['correo'],
        $d['observacion'],
        $d['fecha_aprobacion'],
    ]);
}
fclose($out);
