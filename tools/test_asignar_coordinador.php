<?php
declare(strict_types=1);

require __DIR__ . '/../config/conexion.php';

$eventId = 14;
$coordId = '18188181881';

$update = $pdo->prepare('UPDATE evento SET id_coordinador = :coord WHERE id_evento = :id AND id_coordinador IS NULL');
$update->execute([':coord' => $coordId, ':id' => $eventId]);

$assigned = trim((string)$pdo->query("SELECT id_coordinador FROM evento WHERE id_evento = {$eventId}")->fetchColumn());
echo 'asignado=' . ($assigned === $coordId ? 'OK' : 'FAIL (' . $assigned . ')') . PHP_EOL;

$pdo->prepare('UPDATE evento SET id_coordinador = NULL WHERE id_evento = :id')->execute([':id' => $eventId]);
echo 'revertido=OK' . PHP_EOL;
