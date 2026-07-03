<?php
declare(strict_types=1);

require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/login_notifications.php';
require_once __DIR__ . '/smtp_mailer.php';

function sendPasswordResetMail(array $usuario, string $code): bool
{
    $to = (string)($usuario['correo'] ?? '');
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $nombre = trim((string)($usuario['nombre'] ?? '') . ' ' . (string)($usuario['apellido'] ?? ''));
    $nombre = $nombre !== '' ? $nombre : 'usuario';

    $subject = 'Tu codigo SICA es ' . $code;
    $message = "Hola {$nombre},\n\n"
        . "Recibimos una solicitud para restablecer la contrasena de tu cuenta SICA.\n\n"
        . "CODIGO DE SEGURIDAD\n"
        . "{$code}\n\n"
        . "Usa este codigo en la pantalla de recuperacion para crear tu nueva contrasena.\n"
        . "Vence en 15 minutos.\n\n"
        . "Importante: si solicitaste varios codigos, usa solo el mas reciente. Los codigos anteriores quedan desactivados.\n\n"
        . "Si no solicitaste este cambio, puedes ignorar este mensaje.\n\n"
        . "Equipo SICA";

    try {
        return sica_send_mail($to, $subject, $message);
    } catch (Throwable $exception) {
        error_log('SICA: error enviando recuperacion de contrasena: ' . $exception->getMessage());
        return false;
    }
}
