<?php
declare(strict_types=1);

require __DIR__ . '/../config/conexion.php';

$correo = 'kevinandres212004@gmail.com';
$newPassword = 'Andres21#';
$hash = password_hash($newPassword, PASSWORD_DEFAULT);

$update = $pdo->prepare('UPDATE usuario SET contrasena = :hash WHERE correo = :correo');
$update->execute([
    ':hash' => $hash,
    ':correo' => $correo,
]);

$verify = password_verify($newPassword, $hash) ? 'OK' : 'FAIL';
echo "Contrasena actualizada para {$correo}: {$verify}\n";
