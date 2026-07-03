<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([3]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/instructor_panel.php';

$idInstructor = (int)(instructor_user()['id_documento'] ?? 0);
$idEvento = (int)($_GET['evento'] ?? 0);
$tipo = (string)($_GET['tipo'] ?? 'csv');

$stmt = $pdo->prepare(instructor_event_query() . ' WHERE e.id_evento = :evento AND e.id_solicitante = :instructor LIMIT 1');
$stmt->execute([':evento' => $idEvento, ':instructor' => $idInstructor]);
$evento = $stmt->fetch();

if (!$evento) {
    http_response_code(404);
    exit('Evento no encontrado.');
}

$participantes = instructor_rows(
    $pdo,
    'SELECT p.fecha_registro, p.asistencia, p.hora, u.id_documento, u.nombre, u.apellido, u.correo, f.id_ficha, pr.nombre_programa
     FROM preregistro p
     INNER JOIN usuario u ON u.id_documento = p.id_documento
     LEFT JOIN ficha f ON f.id_ficha = u.id_ficha
     LEFT JOIN programa pr ON pr.id_programa = f.id_programa
     WHERE p.id_evento = :evento
     ORDER BY u.nombre ASC, u.apellido ASC',
    [':evento' => (int)$evento['id_evento']]
);

function instructor_pdf_text(string $value): string
{
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = preg_replace('/[^\x20-\x7E]/', '', $value) ?? $value;
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
}

if ($tipo === 'pdf') {
    $lines = '';
    $y = 690;
    foreach (array_slice($participantes, 0, 24) as $index => $p) {
        $nombre = trim((string)$p['nombre'] . ' ' . (string)$p['apellido']);
        $text = sprintf('%02d  %s  %s  %s', $index + 1, $p['id_documento'], $nombre, $p['asistencia']);
        $lines .= "BT /F1 9 Tf 52 {$y} Td (" . instructor_pdf_text($text) . ") Tj ET\n";
        $y -= 22;
    }

    $title = 'Participantes - ' . (string)$evento['nombre_evento'];
    $stream = "q 0.96 0.98 1 rg 0 0 595 842 re f Q\n";
    $stream .= "q 1 1 1 rg 36 36 523 770 re f Q\n";
    $stream .= "q 0.09 0.36 1 rg 36 782 523 24 re f Q\n";
    $stream .= "BT /F2 20 Tf 52 742 Td (" . instructor_pdf_text('SICA - Participantes registrados') . ") Tj ET\n";
    $stream .= "BT /F1 11 Tf 52 720 Td (" . instructor_pdf_text($title) . ") Tj ET\n";
    $stream .= "BT /F1 10 Tf 52 704 Td (" . instructor_pdf_text('Total: ' . count($participantes)) . ") Tj ET\n";
    $stream .= $lines;

    $objects = [];
    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
    $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>';
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
    $objects[] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= str_pad((string)$offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="participantes-sica-' . (int)$evento['id_evento'] . '.pdf"');
    echo $pdf;
    exit;
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="participantes-sica-' . (int)$evento['id_evento'] . '.csv"');
$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
fputcsv($out, ['Documento', 'Nombre completo', 'Correo', 'Ficha', 'Programa', 'Asistencia', 'Fecha registro', 'Hora ingreso']);
foreach ($participantes as $p) {
    fputcsv($out, [
        $p['id_documento'],
        trim((string)$p['nombre'] . ' ' . (string)$p['apellido']),
        $p['correo'],
        $p['id_ficha'],
        $p['nombre_programa'],
        $p['asistencia'],
        $p['fecha_registro'],
        $p['hora'],
    ]);
}
fclose($out);
