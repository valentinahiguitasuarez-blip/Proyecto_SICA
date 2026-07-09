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

if (!in_array($tipo, ['csv', 'excel', 'pdf'], true) || $idEvento <= 0) {
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
    'SELECT p.fecha_registro, p.asistencia, p.hora, u.id_documento, u.nombre, u.apellido, u.correo, f.id_ficha, pr.nombre_programa, j.nombre_jornada
     FROM preregistro p
     INNER JOIN usuario u ON u.id_documento = p.id_documento
     LEFT JOIN ficha f ON f.id_ficha = u.id_ficha
     LEFT JOIN programa pr ON pr.id_programa = f.id_programa
     LEFT JOIN jornada j ON j.id_jornada = pr.id_jornada
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
        $stream .= "0.94 0.95 0.98 rg 44 714 515 28 re f\n";
        // Column titles — N° | Documento | Nombre | Ficha | Jornada | Asistencia
        $stream .= "0 0 0 rg BT /F1 9 Tf 50 732 Td (" . instructor_pdf_text('N') . ") Tj ET\n";
        $stream .= "0 0 0 rg BT /F1 9 Tf 90 732 Td (" . instructor_pdf_text('Documento') . ") Tj ET\n";
        $stream .= "0 0 0 rg BT /F1 9 Tf 185 732 Td (" . instructor_pdf_text('Nombre completo') . ") Tj ET\n";
        $stream .= "0 0 0 rg BT /F1 9 Tf 330 732 Td (" . instructor_pdf_text('Ficha') . ") Tj ET\n";
        $stream .= "0 0 0 rg BT /F1 9 Tf 390 732 Td (" . instructor_pdf_text('Jornada') . ") Tj ET\n";
        $stream .= "0 0 0 rg BT /F1 9 Tf 460 732 Td (" . instructor_pdf_text('Asistencia') . ") Tj ET\n";

        // horizontal separator line under header
        $stream .= "0.8 G 44 710 m 559 710 l S\n";

        // Rows
        $y = 696;
        $indexBase = $pageIndex * $rowsPerPage;
        foreach ($chunk as $localIndex => $p) {
            $num = $indexBase + $localIndex + 1;
            $nombre = trim((string)$p['nombre'] . ' ' . (string)$p['apellido']);
            $ficha = !empty($p['id_ficha']) ? (string)$p['id_ficha'] : '-';
            $jornada = !empty($p['nombre_jornada']) ? (string)$p['nombre_jornada'] : '-';

            $nombreDisplay = instructor_pdf_trim_raw($nombre, 26);
            $fichaDisplay = instructor_pdf_trim_raw($ficha, 10);
            $jornadaDisplay = instructor_pdf_trim_raw($jornada, 10);
            $asistenciaDisplay = instructor_pdf_trim_raw((string)$p['asistencia'], 10);

            // alternate row background
            if ($localIndex % 2 === 0) {
                $stream .= "0.99 0.995 1 rg 44 " . ($y - 6) . " 515 18 re f\n";
            }

            $stream .= "0 0 0 rg BT /F1 9 Tf 50 {$y} Td (" . instructor_pdf_text((string)$num) . ") Tj ET\n";
            $stream .= "0 0 0 rg BT /F1 9 Tf 90 {$y} Td (" . instructor_pdf_text((string)$p['id_documento']) . ") Tj ET\n";
            $stream .= "0 0 0 rg BT /F1 9 Tf 185 {$y} Td (" . instructor_pdf_text($nombreDisplay) . ") Tj ET\n";
            $stream .= "0 0 0 rg BT /F1 9 Tf 330 {$y} Td (" . instructor_pdf_text($fichaDisplay) . ") Tj ET\n";
            $stream .= "0 0 0 rg BT /F1 9 Tf 390 {$y} Td (" . instructor_pdf_text($jornadaDisplay) . ") Tj ET\n";
            $stream .= "0 0 0 rg BT /F1 9 Tf 460 {$y} Td (" . instructor_pdf_text($asistenciaDisplay) . ") Tj ET\n";

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

if ($tipo === 'excel') {
    $fechaEvento = date('d/m/Y', strtotime((string)$evento['fecha_evento']));
    $horario = instructor_hora12((string)$evento['hora_inicio']) . ' - ' . instructor_hora12((string)$evento['hora_fin']);

    function xlsx_escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    function xlsx_col(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26);
        }
        return $name;
    }

    function xlsx_cell(int $column, int $row, string $value, int $style = 0): string
    {
        $reference = xlsx_col($column) . $row;
        $styleAttr = $style > 0 ? ' s="' . $style . '"' : '';
        return '<c r="' . $reference . '" t="inlineStr"' . $styleAttr . '><is><t>' . xlsx_escape($value) . '</t></is></c>';
    }

    function xlsx_row(int $row, array $values, int $style = 0): string
    {
        $cells = '';
        foreach (array_values($values) as $index => $value) {
            $cells .= xlsx_cell($index + 1, $row, (string)$value, $style);
        }
        return '<row r="' . $row . '">' . $cells . '</row>';
    }

    $sheetRows = [];
    $rowNumber = 1;
    $sheetRows[] = xlsx_row($rowNumber++, ['SICA - Participantes Registrados'], 1);
    $sheetRows[] = xlsx_row($rowNumber++, ['Exportado el ' . date('d/m/Y H:i')], 2);
    $sheetRows[] = xlsx_row($rowNumber++, ['Evento', (string)$evento['nombre_evento'], 'Codigo', (string)$evento['codigo_evento']]);
    $sheetRows[] = xlsx_row($rowNumber++, ['Auditorio', (string)$evento['nombre_auditorio'] . ' / Bloque ' . (string)$evento['bloque'], 'Fecha', $fechaEvento]);
    $sheetRows[] = xlsx_row($rowNumber++, ['Horario', $horario, 'Total', count($participantes) . ' participante(s)']);
    $rowNumber++;
    $sheetRows[] = xlsx_row($rowNumber++, ['N', 'Documento', 'Nombre completo', 'Correo', 'Ficha', 'Programa', 'Jornada', 'Asistencia', 'Fecha registro', 'Hora ingreso'], 3);

    if (!$participantes) {
        $sheetRows[] = xlsx_row($rowNumber++, ['Sin participantes registrados.'], 2);
    }

    foreach ($participantes as $i => $p) {
        $nombre = trim((string)$p['nombre'] . ' ' . (string)$p['apellido']);
        $sheetRows[] = xlsx_row($rowNumber++, [
            (string)($i + 1),
            (string)$p['id_documento'],
            $nombre,
            (string)($p['correo'] ?? '-'),
            !empty($p['id_ficha']) ? (string)$p['id_ficha'] : '-',
            !empty($p['nombre_programa']) ? (string)$p['nombre_programa'] : '-',
            !empty($p['nombre_jornada']) ? (string)$p['nombre_jornada'] : '-',
            !empty($p['asistencia']) ? (string)$p['asistencia'] : '-',
            !empty($p['fecha_registro']) ? date('d/m/Y', strtotime((string)$p['fecha_registro'])) : '-',
            instructor_hora12((string)($p['hora'] ?? '')),
        ]);
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<cols>
<col min="1" max="1" width="8" customWidth="1"/>
<col min="2" max="2" width="18" customWidth="1"/>
<col min="3" max="3" width="30" customWidth="1"/>
<col min="4" max="4" width="34" customWidth="1"/>
<col min="5" max="5" width="14" customWidth="1"/>
<col min="6" max="6" width="36" customWidth="1"/>
<col min="7" max="10" width="16" customWidth="1"/>
</cols>
<sheetData>' . implode('', $sheetRows) . '</sheetData>
</worksheet>';

    $xlsxFiles = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets><sheet name="Participantes" sheetId="1" r:id="rId1"/></sheets>
</workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="16"/><color rgb="FF1A3A6B"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>
<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1A3A6B"/><bgColor indexed="64"/></patternFill></fill></fills>
<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFD9E2EF"/></left><right style="thin"><color rgb="FFD9E2EF"/></right><top style="thin"><color rgb="FFD9E2EF"/></top><bottom style="thin"><color rgb="FFD9E2EF"/></bottom><diagonal/></border></borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0"/></cellXfs>
</styleSheet>',
        'xl/worksheets/sheet1.xml' => $sheetXml,
    ];

    $tmpFile = tempnam(sys_get_temp_dir(), 'sica_xlsx_');
    if ($tmpFile === false) {
        http_response_code(500);
        exit('No fue posible preparar el archivo Excel.');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmpFile);
        http_response_code(500);
        exit('No fue posible generar el archivo Excel.');
    }

    foreach ($xlsxFiles as $path => $content) {
        $zip->addFromString($path, $content);
    }
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . instructor_export_filename($evento, 'xlsx') . '"');
    header('Content-Length: ' . filesize($tmpFile));
    header('Cache-Control: no-cache');
    readfile($tmpFile);
    @unlink($tmpFile);
    exit;
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . instructor_export_filename($evento, 'csv') . '"');
$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
fputcsv($out, ['N°', 'Documento', 'Nombre completo', 'Correo', 'Ficha', 'Programa', 'Asistencia', 'Fecha registro', 'Hora ingreso']);
foreach ($participantes as $i => $p) {
    fputcsv($out, [
        $i + 1,
        $p['id_documento'],
        trim((string)$p['nombre'] . ' ' . (string)$p['apellido']),
        $p['correo'],
        !empty($p['id_ficha']) ? $p['id_ficha'] : '-',
        !empty($p['nombre_programa']) ? $p['nombre_programa'] : '-',
        !empty($p['asistencia']) ? $p['asistencia'] : '-',
        !empty($p['fecha_registro']) ? date('d/m/Y', strtotime((string)$p['fecha_registro'])) : '-',
        instructor_hora12((string)($p['hora'] ?? '')),
    ]);
}
fclose($out);
