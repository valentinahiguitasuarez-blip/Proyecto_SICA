<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([3]);
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/instructor_panel.php';

$idInstructor = (int)(instructor_user()['id_documento'] ?? 0);
$eventoRaw = trim((string)($_GET['evento'] ?? ''));
$idEvento = ctype_digit($eventoRaw) ? (int)$eventoRaw : 0;
$tipo = (string)($_GET['tipo'] ?? 'csv');

if (!in_array($tipo, ['csv', 'pdf'], true) || $idEvento <= 0) {
    http_response_code(400);
    exit('Solicitud de exportación inválida.');
}

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

function instructor_pdf_trim_raw(string $value, int $maxChars): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (mb_strlen($value, 'UTF-8') <= $maxChars) {
        return $value;
    }
    return mb_substr($value, 0, max(0, $maxChars - 1), 'UTF-8') . '…';
}

function instructor_export_filename(array $evento, string $extension): string
{
    $base = 'participantes-sica-' . (int)$evento['id_evento'] . '-' . (string)$evento['codigo_evento'];
    $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', $base) ?? $base;
    return trim($base, '-') . '.' . $extension;
}

if ($tipo === 'pdf') {
    // build pages with a nicer table layout: header bar, table header with background,
    // separator lines and footer with generation date.
    $rowsPerPage = 28;
    $chunks = array_chunk($participantes, $rowsPerPage);
    if (!$chunks) {
        $chunks = [[]];
    }
    $pageCount = count($chunks);
    $total = count($participantes);

    $contentStreams = [];
    foreach ($chunks as $pageIndex => $chunk) {
        $stream = '';

        // Page background and panel
        $stream .= "q 0.96 0.98 1 rg 0 0 595 842 re f Q\n"; // full subtle background
        $stream .= "q 1 1 1 rg 36 36 523 770 re f Q\n"; // white panel

        // header top bar
        $stream .= "q 0.09 0.36 1 rg 36 782 523 42 re f Q\n";
        $horarioEvento = instructor_hora12((string)$evento['hora_inicio']) . ' - ' . instructor_hora12((string)$evento['hora_fin']);
        $fechaEvento = date('d/m/Y', strtotime((string)$evento['fecha_evento']));

        // Title and meta (white text)
        $title = 'Participantes - ' . (string)$evento['nombre_evento'];
        $stream .= "1 1 1 rg BT /F2 18 Tf 52 810 Td (" . instructor_pdf_text('SICA - Participantes registrados') . ") Tj ET\n";
        $stream .= "1 1 1 rg BT /F1 11 Tf 52 792 Td (" . instructor_pdf_text($title) . ") Tj ET\n";
        $stream .= "1 1 1 rg BT /F1 10 Tf 440 806 Td (" . instructor_pdf_text('Pagina ' . ($pageIndex + 1) . ' de ' . $pageCount) . ") Tj ET\n";
        $stream .= "1 1 1 rg BT /F1 10 Tf 440 788 Td (" . instructor_pdf_text('Total: ' . $total) . ") Tj ET\n";

        $stream .= "0.04 0.09 0.20 rg BT /F1 10 Tf 52 764 Td (" . instructor_pdf_text('Auditorio: ' . (string)$evento['nombre_auditorio'] . ' / Bloque ' . (string)$evento['bloque']) . ") Tj ET\n";
        $stream .= "0.04 0.09 0.20 rg BT /F1 10 Tf 300 764 Td (" . instructor_pdf_text('Fecha: ' . $fechaEvento . ' - ' . $horarioEvento) . ") Tj ET\n";

        // Table header background
        $stream .= "0.94 0.95 0.98 rg 44 714 515 28 re f\n"; // light row background
        // Column titles (dark text)
        $stream .= "0 0 0 rg BT /F1 10 Tf 50 732 Td (" . instructor_pdf_text('N°') . ") Tj ET\n";
        $stream .= "0 0 0 rg BT /F1 10 Tf 110 732 Td (" . instructor_pdf_text('Documento') . ") Tj ET\n";
        $stream .= "0 0 0 rg BT /F1 10 Tf 220 732 Td (" . instructor_pdf_text('Nombre completo') . ") Tj ET\n";
        $stream .= "0 0 0 rg BT /F1 10 Tf 405 732 Td (" . instructor_pdf_text('Ficha') . ") Tj ET\n";
        $stream .= "0 0 0 rg BT /F1 10 Tf 485 732 Td (" . instructor_pdf_text('Asistencia') . ") Tj ET\n";

        // horizontal separator line under header
        $stream .= "0.8 G 44 710 m 559 710 l S\n";

        // Rows
        $y = 696;
        $indexBase = $pageIndex * $rowsPerPage;
        foreach ($chunk as $localIndex => $p) {
            $num = $indexBase + $localIndex + 1;
            $nombre = trim((string)$p['nombre'] . ' ' . (string)$p['apellido']);
            $ficha = !empty($p['id_ficha']) ? (string)$p['id_ficha'] : 'Sin ficha';

            // Truncate long fields to avoid overlap in PDF columns
            $nombreDisplay = instructor_pdf_trim_raw($nombre, 34);
            $fichaDisplay = instructor_pdf_trim_raw($ficha, 11);
            $asistenciaDisplay = instructor_pdf_trim_raw((string)$p['asistencia'], 12);

            // alternate row background
            if ($localIndex % 2 === 0) {
                $stream .= "0.99 0.995 1 rg 44 " . ($y - 6) . " 515 18 re f\n";
            }

            $stream .= "0 0 0 rg BT /F1 9 Tf 50 {$y} Td (" . instructor_pdf_text((string)$num) . ") Tj ET\n";
            $stream .= "0 0 0 rg BT /F1 9 Tf 110 {$y} Td (" . instructor_pdf_text((string)$p['id_documento']) . ") Tj ET\n";
            $stream .= "0 0 0 rg BT /F1 9 Tf 220 {$y} Td (" . instructor_pdf_text($nombreDisplay) . ") Tj ET\n";
            $stream .= "0 0 0 rg BT /F1 9 Tf 405 {$y} Td (" . instructor_pdf_text($fichaDisplay) . ") Tj ET\n";
            $stream .= "0 0 0 rg BT /F1 9 Tf 485 {$y} Td (" . instructor_pdf_text($asistenciaDisplay) . ") Tj ET\n";

            // small separator
            $stream .= "0.9 G 44 " . ($y - 8) . " m 559 " . ($y - 8) . " l S\n";

            $y -= 22;
        }

        // Footer with generation date
        $generated = date('Y-m-d H:i');
        $stream .= "0 0 0 rg BT /F1 9 Tf 52 36 Td (" . instructor_pdf_text('Generado: ' . $generated) . ") Tj ET\n";

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
    header('Content-Disposition: attachment; filename="' . instructor_export_filename($evento, 'pdf') . '"');
    echo $pdf;
    exit;
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . instructor_export_filename($evento, 'csv') . '"');
$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
fputcsv($out, ['SICA - Participantes registrados']);
fputcsv($out, ['Evento', $evento['nombre_evento']]);
fputcsv($out, ['Código', $evento['codigo_evento']]);
fputcsv($out, ['Auditorio', $evento['nombre_auditorio'] . ' / Bloque ' . $evento['bloque']]);
fputcsv($out, ['Fecha', date('d/m/Y', strtotime((string)$evento['fecha_evento']))]);
fputcsv($out, ['Horario', instructor_hora12((string)$evento['hora_inicio']) . ' - ' . instructor_hora12((string)$evento['hora_fin'])]);
fputcsv($out, ['Total participantes', count($participantes)]);
fputcsv($out, []);
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
        instructor_hora12((string)($p['hora'] ?? '')),
    ]);
}
fclose($out);
