<?php
declare(strict_types=1);

require __DIR__ . '/../config/conexion.php';

$count = (int)$pdo->query('SELECT COUNT(*) FROM evento')->fetchColumn();
$host = '127.0.0.1';
if (is_file(__DIR__ . '/../config/database.local.php')) {
    $local = require __DIR__ . '/../config/database.local.php';
    $host = (string)($local['host'] ?? $host);
}

echo "Conexion OK\n";
echo 'Host: ' . $host . "\n";
echo 'Eventos en sica: ' . $count . "\n";
