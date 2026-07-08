<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([2]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/coordinador_panel.php';

$usuario = coord_user();
$coordinadorId = (int)($usuario['id_documento'] ?? 0);
$filters = coord_historial_filters($_GET, $coordinadorId);
$whereSql = $filters['whereSql'];
$params = $filters['params'];
$exportLimit = 5000;

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
     ORDER BY COALESCE(e.fecha_aprobacion, e.fecha_evento) DESC, e.id_evento DESC
     LIMIT ' . (int)$exportLimit,
    $params
);

$truncated = count($decisiones) >= $exportLimit;

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="historial-coordinacion-sica-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
if ($truncated) {
    fputcsv($out, ['Aviso: exportación limitada a ' . $exportLimit . ' registros. Ajusta los filtros para obtener un archivo más específico.']);
}
fputcsv($out, ['Código', 'Evento', 'Estado', 'Auditorio', 'Bloque', 'Fecha evento', 'Horario', 'Instructor', 'Correo instructor', 'Observación', 'Fecha decisión']);
foreach ($decisiones as $d) {
    fputcsv($out, [
        $d['codigo_evento'],
        $d['nombre_evento'],
        $d['estado'],
        $d['nombre_auditorio'],
        $d['bloque'],
        $d['fecha_evento'],
        coord_hora12((string)$d['hora_inicio']) . ' - ' . coord_hora12((string)$d['hora_fin']),
        trim((string)$d['nombre'] . ' ' . (string)$d['apellido']),
        $d['correo'],
        $d['observacion'],
        $d['fecha_aprobacion'],
    ]);
}
fclose($out);
