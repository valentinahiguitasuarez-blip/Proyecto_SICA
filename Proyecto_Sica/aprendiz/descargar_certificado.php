<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([4]);
require_once __DIR__ . '/../config/conexion.php';

$usuario = $_SESSION['usuario'] ?? [];
$idDocumento = (int)($usuario['id_documento'] ?? 0);
$idCertificado = (int)($_GET['id'] ?? 0);

if ($idDocumento <= 0 || $idCertificado <= 0) {
    http_response_code(404);
    exit('Certificado no encontrado.');
}

function pdf_text(string $value): string
{
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = preg_replace('/[^\x20-\x7E]/', '', $value) ?? $value;
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
}

function send_pdf(string $content, string $filename): void
{
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $content;
    exit;
}

function build_certificate_pdf(array $certificado): string
{
    $nombreAprendiz = trim((string)$certificado['nombre'] . ' ' . (string)$certificado['apellido']);
    $fechaEvento = new DateTime((string)$certificado['fecha_evento']);
    $inicio = substr((string)($certificado['hora_inicio'] ?? ''), 0, 5);
    $fin = substr((string)($certificado['hora_fin'] ?? ''), 0, 5);
    $duracion = 'Evento SICA';
    if ($inicio !== '' && $fin !== '') {
        try {
            $inicioDt = new DateTime((string)$certificado['fecha_evento'] . ' ' . $inicio);
            $finDt = new DateTime((string)$certificado['fecha_evento'] . ' ' . $fin);
            $diff = $inicioDt->diff($finDt);
            $duracion = trim(($diff->h > 0 ? $diff->h . ' horas ' : '') . ($diff->i > 0 ? $diff->i . ' min' : ''));
            $duracion = $duracion !== '' ? $duracion : 'Evento SICA';
        } catch (Throwable) {
            $duracion = 'Evento SICA';
        }
    }

    $drawText = static function (string $text, int $x, int $y, int $size, string $font = 'F2', string $color = '0.04 0.09 0.20'): string {
        return "q\n{$color} rg\nBT /{$font} {$size} Tf {$x} {$y} Td (" . pdf_text($text) . ") Tj ET\nQ\n";
    };

    $stream = '';
    $stream .= "q\n0.98 0.99 1 rg\n0 0 900 560 re f\nQ\n";
    $stream .= "q\n0.02 0.19 0.95 rg\n0 0 210 210 re f\nQ\n";
    $stream .= "q\n0.02 0.19 0.95 rg\n730 430 170 130 re f\nQ\n";
    $stream .= "q\n0.90 0.95 1 rg\n42 42 816 476 re f\nQ\n";
    $stream .= "q\n1 1 1 rg\n60 58 780 440 re f\nQ\n";
    $stream .= "q\n0.55 0.70 1 RG\n1.1 w\n70 70 760 416 re S\nQ\n";
    $stream .= "q\n0.91 0.95 1 rg\n635 405 148 54 re f\nQ\n";
    $stream .= "q\n0.02 0.20 0.92 rg\n654 429 24 8 re f\nQ\n";
    $stream .= "q\n0.02 0.20 0.92 RG\n1.4 w\n654 416 24 26 re S\n660 447 0 -8 l S\n672 447 0 -8 l S\n659 424 4 0 l S\n668 424 4 0 l S\n659 420 4 0 l S\n668 420 4 0 l S\nQ\n";
    $stream .= "q\n0.91 0.95 1 rg\n160 40 520 52 re f\nQ\n";
    $stream .= "q\n0.02 0.19 0.95 RG\n1.2 w\n235 294 430 0 l S\nQ\n";
    $stream .= "q\n0.02 0.19 0.95 RG\n1 w\n230 346 58 0 l 574 346 58 0 l S\nQ\n";

    for ($x = 785; $x <= 840; $x += 14) {
        for ($y = 280; $y <= 340; $y += 14) {
            $stream .= "q\n0.60 0.72 1 rg\n{$x} {$y} 2 2 re f\nQ\n";
        }
    }
    for ($x = 58; $x <= 114; $x += 14) {
        for ($y = 165; $y <= 220; $y += 14) {
            $stream .= "q\n0.60 0.72 1 rg\n{$x} {$y} 2 2 re f\nQ\n";
        }
    }

    $stream .= $drawText('SICA', 210, 460, 42, 'F1', '0.02 0.10 0.26');
    $stream .= $drawText('Sistema Inteligente de', 212, 436, 12);
    $stream .= $drawText('Control de Asistencia', 212, 420, 12);
    $stream .= $drawText('Fecha del evento', 690, 438, 10);
    $stream .= $drawText($fechaEvento->format('d / m / Y'), 690, 420, 11, 'F1');
    $stream .= $drawText('CERTIFICADO DE ASISTENCIA', 250, 368, 28, 'F1', '0.04 0.09 0.20');
    $stream .= $drawText('Se otorga el presente certificado a:', 305, 342, 13);
    $stream .= $drawText($nombreAprendiz, 240, 305, 30, 'F1', '0.02 0.20 0.92');
    $stream .= $drawText('Documento: ' . (string)$certificado['id_documento'], 350, 274, 13);
    $stream .= $drawText('Por asistir al evento:', 368, 238, 13);
    $stream .= $drawText((string)$certificado['nombre_evento'], 295, 214, 19, 'F1');
    $stream .= $drawText('Auditorio', 205, 160, 9);
    $stream .= $drawText((string)$certificado['nombre_auditorio'] . ' / Bloque ' . (string)$certificado['bloque'], 205, 145, 10, 'F1');
    $stream .= $drawText('Duracion', 455, 160, 9);
    $stream .= $drawText($duracion, 455, 145, 10, 'F1');
    $stream .= $drawText('Organizado por', 615, 160, 9);
    $stream .= $drawText('SENA Apartado', 615, 145, 10, 'F1');
    $stream .= $drawText('Documento generado por SICA despues de validar la asistencia.', 302, 26, 9, 'F2', '0.30 0.38 0.52');

    $objects = [];
    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 900 560] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $objects[] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $number = $index + 1;
        $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1, $total = count($offsets); $i < $total; $i++) {
        $pdf .= str_pad((string)$offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n{$xrefOffset}\n%%EOF";

    return $pdf;
}

try {
    $stmt = $pdo->prepare(
        'SELECT c.id_certificado, c.fecha_generado, c.ruta_certificado,
                pr.id_documento, pr.asistencia,
                u.nombre, u.apellido,
                e.nombre_evento, e.fecha_evento, e.hora_inicio, e.hora_fin,
                a.nombre_auditorio, a.bloque
         FROM certificado c
         INNER JOIN preregistro pr ON pr.id_preregistro = c.id_preregistro
         INNER JOIN usuario u ON u.id_documento = pr.id_documento
         INNER JOIN evento e ON e.id_evento = pr.id_evento
         INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
         WHERE c.id_certificado = :id_certificado
           AND pr.id_documento = :id_documento
         LIMIT 1'
    );
    $stmt->execute([
        ':id_certificado' => $idCertificado,
        ':id_documento' => $idDocumento,
    ]);
    $certificado = $stmt->fetch();
} catch (Throwable $exception) {
    error_log('SICA descarga certificado: ' . $exception->getMessage());
    http_response_code(500);
    exit('No fue posible descargar el certificado.');
}

if (!$certificado) {
    http_response_code(404);
    exit('Certificado no encontrado.');
}

$filename = 'certificado_sica_' . $idCertificado . '_' . $idDocumento . '.pdf';
$ruta = trim((string)$certificado['ruta_certificado']);
$fullPath = $ruta !== '' ? realpath(__DIR__ . '/../' . ltrim($ruta, '/\\')) : false;
$basePath = realpath(__DIR__ . '/..');

if ($fullPath && $basePath && str_starts_with($fullPath, $basePath) && is_file($fullPath)) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($fullPath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($fullPath);
    exit;
}

send_pdf(build_certificate_pdf($certificado), $filename);
