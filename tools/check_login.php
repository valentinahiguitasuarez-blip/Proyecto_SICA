<?php
declare(strict_types=1);

require __DIR__ . '/../config/conexion.php';

$correo = 'kevinandres212004@gmail.com';
$candidates = ['Andres21#', 'Andres21', 'andres21#', 'Admin123!', 'Kevin2026!'];

$stmt = $pdo->prepare(
    'SELECT u.id_documento, u.nombre, u.apellido, u.correo, u.contrasena, e.nombre_estado, r.nombre_rol
     FROM usuario u
     INNER JOIN estado e ON e.id_estado = u.id_estado
     INNER JOIN rol r ON r.id_rol = u.id_rol
     WHERE u.correo = :correo
     LIMIT 1'
);
$stmt->execute([':correo' => $correo]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "USUARIO: no encontrado\n";
    exit(1);
}

echo 'USUARIO: ' . $user['nombre'] . ' ' . $user['apellido'] . "\n";
echo 'ROL: ' . $user['nombre_rol'] . "\n";
echo 'ESTADO: ' . $user['nombre_estado'] . "\n";
echo 'HASH_VALIDO: ' . (password_get_info((string)$user['contrasena'])['algo'] !== 0 ? 'si' : 'no') . "\n\n";

foreach ($candidates as $password) {
    $match = password_verify($password, (string)$user['contrasena']);
    echo $password . ' => ' . ($match ? 'COINCIDE' : 'no') . "\n";
}
