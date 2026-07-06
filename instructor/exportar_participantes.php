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
    $mapa = [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u',
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N','Ü'=>'U',
    ];
    $value = strtr($value, $mapa);
    $value = preg_replace('/[^\x20-\x7E]/', '', $value) ?? $value;
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
}

if ($tipo === 'pdf') {
    $chunks = array_chunk($participantes, 24);
    $pageCount = count($chunks);
    $total = count($participantes);

    $contentStreams = [];
    foreach ($chunks as $pageIndex => $chunk) {
        $lines = '';
        $y = 670;
        $indexBase = $pageIndex * 24;

        // Header row in fixed columns
        $lines .= "BT /F1 10 Tf 52 700 Td (" . instructor_pdf_text('N°') . ") Tj ET\n";
        $lines .= "BT /F1 10 Tf 110 700 Td (" . instructor_pdf_text('Documento') . ") Tj ET\n";
        $lines .= "BT /F1 10 Tf 260 700 Td (" . instructor_pdf_text('Nombre completo') . ") Tj ET\n";
        $lines .= "BT /F1 10 Tf 500 700 Td (" . instructor_pdf_text('Asistencia') . ") Tj ET\n";
        $y -= 20;

        foreach ($chunk as $localIndex => $p) {
            $nombre = trim((string)$p['nombre'] . ' ' . (string)$p['apellido']);
            $num = $indexBase + $localIndex + 1;
            $lines .= "BT /F1 9 Tf 52 {$y} Td (" . instructor_pdf_text((string)$num) . ") Tj ET\n";
            $lines .= "BT /F1 9 Tf 110 {$y} Td (" . instructor_pdf_text((string)$p['id_documento']) . ") Tj ET\n";
            $lines .= "BT /F1 9 Tf 260 {$y} Td (" . instructor_pdf_text($nombre) . ") Tj ET\n";
            $lines .= "BT /F1 9 Tf 500 {$y} Td (" . instructor_pdf_text((string)$p['asistencia']) . ") Tj ET\n";
            $y -= 18;
        }

        $title = 'Participantes - ' . (string)$evento['nombre_evento'];
        $stream = "q 0.96 0.98 1 rg 0 0 595 842 re f Q\n";
        $stream .= "q 1 1 1 rg 36 36 523 770 re f Q\n";
        $stream .= "q 0.09 0.36 1 rg 36 782 523 24 re f Q\n";
        $stream .= "1 1 1 rg BT /F2 20 Tf 52 742 Td (" . instructor_pdf_text('SICA - Participantes registrados') . ") Tj ET\n";
        $stream .= "1 1 1 rg BT /F1 11 Tf 52 720 Td (" . instructor_pdf_text($title) . ") Tj ET\n";
        $stream .= "1 1 1 rg BT /F1 10 Tf 440 742 Td (" . instructor_pdf_text('Pagina ' . ($pageIndex + 1) . ' de ' . $pageCount) . ") Tj ET\n";
        $stream .= "1 1 1 rg BT /F1 10 Tf 52 704 Td (" . instructor_pdf_text('Total: ' . $total) . ") Tj ET\n";
        $stream .= "0 0 0 rg\n";
        $stream .= $lines;

        $contentStreams[] = $stream;
    }

    $objects = [];
    // Catalog and Pages
    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    // build Kids array referencing page objects that will start at obj 3
    $kids = [];
    for ($i = 0; $i < $pageCount; $i++) {
        $kids[] = (3 + $i) . ' 0 R';
    }
    $objects[] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $pageCount . ' >>';

    // Page objects (they will reference content objects placed after fonts)
    for ($i = 0; $i < $pageCount; $i++) {
        // content object index = 2 (Pages) + pageCount + 2 (fonts) + (i+1)
        $contentIndex = 2 + $pageCount + 2 + ($i + 1);
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents ' . $contentIndex . ' 0 R >>';
    }

    // Fonts
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

    // Content streams
    foreach ($contentStreams as $stream) {
        $objects[] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
    }

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
