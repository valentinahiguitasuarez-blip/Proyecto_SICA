<?php
declare(strict_types=1);

require_once __DIR__ . '/login_notifications.php';

function sica_get_admin_notification_emails(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT DISTINCT u.correo
         FROM usuario u
         INNER JOIN rol r ON r.id_rol = u.id_rol
         INNER JOIN estado e ON e.id_estado = u.id_estado
         WHERE (r.id_rol = 1 OR LOWER(r.nombre_rol) LIKE '%administrador%')
           AND e.nombre_estado = 'Activo'
           AND u.correo IS NOT NULL
           AND TRIM(u.correo) <> ''"
    );

    $emails = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $correo) {
        $correo = trim((string)$correo);
        if ($correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $correo;
        }
    }

    return array_values(array_unique($emails));
}

function sica_notify_admins_new_reservation(PDO $pdo, int $idEvento): void
{
    if ($idEvento <= 0) {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT e.nombre_evento, e.descripcion, e.fecha_evento, e.hora_inicio, e.hora_fin, e.codigo_evento,
                    a.nombre_auditorio, a.bloque,
                    te.nombre_tipo,
                    ins.nombre AS ins_nombre, ins.apellido AS ins_apellido, ins.correo AS ins_correo
             FROM evento e
             INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
             INNER JOIN tipo_evento te ON te.id_tipo_evento = e.id_tipo_evento
             LEFT JOIN usuario ins ON ins.id_documento = e.id_solicitante
             WHERE e.id_evento = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $idEvento]);
        $evento = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$evento) {
            return;
        }

        $admins = sica_get_admin_notification_emails($pdo);
        if (!$admins) {
            error_log('SICA: no hay administradores con correo para notificar solicitud ' . $idEvento);
            return;
        }

        $instructorName = trim((string)($evento['ins_nombre'] ?? '') . ' ' . (string)($evento['ins_apellido'] ?? ''));
        if ($instructorName === '') {
            $instructorName = 'Instructor SICA';
        }

        $instructorCorreo = trim((string)($evento['ins_correo'] ?? ''));
        $descripcion = trim((string)($evento['descripcion'] ?? ''));
        $solicitudesUrl = app_absolute_url('admin/solicitudes.php');
        $horaInicio = substr((string)$evento['hora_inicio'], 0, 5);
        $horaFin = substr((string)$evento['hora_fin'], 0, 5);

        $subject = 'Nueva solicitud de reserva de auditorio - SICA';
        $body = "Hola,\n\n"
            . "Un instructor envio una nueva solicitud de reserva en SICA.\n\n"
            . "Evento: {$evento['nombre_evento']}\n"
            . "Tipo: {$evento['nombre_tipo']}\n"
            . "Instructor: {$instructorName}\n";

        if ($instructorCorreo !== '') {
            $body .= "Correo instructor: {$instructorCorreo}\n";
        }

        $body .= "Auditorio: {$evento['nombre_auditorio']} / Bloque {$evento['bloque']}\n"
            . "Fecha: {$evento['fecha_evento']}\n"
            . "Hora: {$horaInicio} - {$horaFin}\n"
            . "Codigo: {$evento['codigo_evento']}\n";

        if ($descripcion !== '') {
            $body .= "Descripcion: {$descripcion}\n";
        }

        $body .= "\nRevisa la solicitud en Solicitudes de Reserva > Por asignar:\n{$solicitudesUrl}\n\nEquipo SICA";

        foreach ($admins as $correo) {
            if (!sica_send_mail($correo, $subject, $body)) {
                error_log('SICA: no se pudo notificar al administrador ' . $correo . ' por solicitud ' . $idEvento);
            }
        }
    } catch (Throwable $exception) {
        error_log('SICA: error notificando administradores por solicitud ' . $idEvento . ': ' . $exception->getMessage());
    }
}
