<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([3]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/instructor_panel.php';

$idInstructor = (int)(instructor_user()['id_documento'] ?? 0);
$idEvento = (int)($_GET['evento'] ?? 0);
$stmt = $pdo->prepare(instructor_event_query() . " WHERE e.id_evento = :id AND e.id_solicitante = :instructor LIMIT 1");
$stmt->execute([':id' => $idEvento, ':instructor' => $idInstructor]);
$evento = $stmt->fetch();

if (!$evento) {
    http_response_code(404);
    exit('Codigo no disponible.');
}

$svg = instructor_download_qr_svg(
    (string)$evento['codigo_evento'],
    (string)$evento['nombre_evento'],
    instructor_event_qr_payload($evento)
);
header('Content-Type: image/svg+xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="codigo-sica-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$evento['codigo_evento']) . '.svg"');
echo $svg;
