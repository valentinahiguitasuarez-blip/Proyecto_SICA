<?php
declare(strict_types=1);

require_once __DIR__ . '/smtp_mailer.php';

function app_absolute_url(string $path): string
{
    $appConfigPath = __DIR__ . '/../config/app.php';
    if (is_file($appConfigPath)) {
        $appConfig = require $appConfigPath;
        $baseUrl = rtrim((string)($appConfig['base_url'] ?? ''), '/');

        if ($baseUrl !== '') {
            return $baseUrl . '/' . ltrim($path, '/');
        }
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', $host) ?: 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    return $scheme . '://' . $host . app_url($path);
}

function sendLoginNotification(array $usuario, string $rol): void
{
    $to = (string)($usuario['correo'] ?? '');
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $nombre = trim((string)($usuario['nombre'] ?? '') . ' ' . (string)($usuario['apellido'] ?? ''));
    $nombre = $nombre !== '' ? $nombre : 'usuario';
    $fecha = date('d/m/Y H:i');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'IP no disponible';
    $changePasswordUrl = app_absolute_url('login/recuperar.php');

    $subject = 'Notificacion de inicio de sesion - SICA';
    $message = "Hola {$nombre},\n\n"
        . "Te informamos que se inicio sesion en tu cuenta SICA.\n\n"
        . "Rol: {$rol}\n"
        . "Fecha y hora: {$fecha}\n"
        . "Direccion IP: {$ip}\n\n"
        . "Si fuiste tu, no necesitas hacer nada.\n"
        . "Si no reconoces este acceso, cambia tu contrasena o comunicate con el administrador.\n\n"
        . "Cambiar o recuperar contrasena: {$changePasswordUrl}\n\n"
        . "Equipo SICA";

    try {
        $sent = sica_send_mail($to, $subject, $message);
        if (!$sent) {
            error_log('SICA: no se pudo enviar la notificacion de inicio de sesion a ' . $to);
        }
    } catch (Throwable $exception) {
        error_log('SICA: error enviando notificacion de login: ' . $exception->getMessage());
    }
}
