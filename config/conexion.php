<?php
declare(strict_types=1);

$dbDefaults = require __DIR__ . '/database.php';
$dbConfig = is_file(__DIR__ . '/database.local.php')
    ? array_merge($dbDefaults, require __DIR__ . '/database.local.php')
    : $dbDefaults;

$host = (string)($dbConfig['host'] ?? '127.0.0.1');
$port = (int)($dbConfig['port'] ?? 3306);
$name = (string)($dbConfig['name'] ?? 'sica');
$dbUser = (string)($dbConfig['user'] ?? 'root');
$dbPass = (string)($dbConfig['pass'] ?? '');

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    exit('Error de conexión a la base de datos.');
}
