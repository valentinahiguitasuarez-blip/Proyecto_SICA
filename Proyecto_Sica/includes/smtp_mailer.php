<?php
declare(strict_types=1);

function sica_mail_config(): array
{
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . '/../config/mail.php';
    }

    return $config;
}

function sica_encode_header(string $value): string
{
    if (preg_match('/[^\x20-\x7E]/', $value)) {
        return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
    }

    return $value;
}

function sica_format_address(string $email, string $name = ''): string
{
    $email = trim($email);
    $name = trim($name);

    if ($name === '') {
        return '<' . $email . '>';
    }

    return '"' . addcslashes(sica_encode_header($name), '"\\') . '" <' . $email . '>';
}

function sica_smtp_read($socket): string
{
    $response = '';

    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }

        $response .= $line;
        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }

    return $response;
}

function sica_smtp_command($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");
    $response = sica_smtp_read($socket);
    $code = (int)substr($response, 0, 3);

    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('Respuesta SMTP inesperada: ' . trim($response));
    }

    return $response;
}

function sica_send_mail(string $to, string $subject, string $body, array $options = []): bool
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $config = sica_mail_config();
    $host = (string)$config['host'];
    $port = (int)$config['port'];
    $timeout = (int)($config['timeout'] ?? 20);
    $username = (string)$config['username'];
    $password = (string)$config['password'];
    $fromEmail = (string)$config['from_email'];
    $fromName = (string)$config['from_name'];
    $replyTo = (string)($options['reply_to'] ?? $fromEmail);
    $replyToName = (string)($options['reply_to_name'] ?? $fromName);

    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . sica_format_address($fromEmail, $fromName),
        'Reply-To: ' . sica_format_address($replyTo, $replyToName),
        'To: ' . sica_format_address($to),
        'Subject: ' . sica_encode_header($subject),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: SICA SMTP',
    ];

    $message = implode("\r\n", $headers)
        . "\r\n\r\n"
        . str_replace(["\r\n", "\r"], "\n", $body);
    $message = str_replace("\n", "\r\n", $message);
    $message = preg_replace('/^\./m', '..', $message);

    $socket = @stream_socket_client(
        'tcp://' . $host . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if (!is_resource($socket)) {
        error_log('SICA SMTP: no se pudo conectar: ' . $errstr . ' (' . $errno . ')');
        return false;
    }

    stream_set_timeout($socket, $timeout);

    try {
        $response = sica_smtp_read($socket);
        if ((int)substr($response, 0, 3) !== 220) {
            throw new RuntimeException('Servidor SMTP no disponible: ' . trim($response));
        }

        $serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';
        sica_smtp_command($socket, 'EHLO ' . $serverName, [250]);
        sica_smtp_command($socket, 'STARTTLS', [220]);

        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('No se pudo activar TLS para SMTP.');
        }

        sica_smtp_command($socket, 'EHLO ' . $serverName, [250]);
        sica_smtp_command($socket, 'AUTH LOGIN', [334]);
        sica_smtp_command($socket, base64_encode($username), [334]);
        sica_smtp_command($socket, base64_encode($password), [235]);
        sica_smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        sica_smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        sica_smtp_command($socket, 'DATA', [354]);
        sica_smtp_command($socket, $message . "\r\n.", [250]);
        sica_smtp_command($socket, 'QUIT', [221]);

        fclose($socket);
        return true;
    } catch (Throwable $exception) {
        error_log('SICA SMTP: ' . $exception->getMessage());
        fclose($socket);
        return false;
    }
}
