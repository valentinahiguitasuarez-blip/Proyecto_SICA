<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../includes/paths.php';
require_once __DIR__ . '/../includes/login_notifications.php';

const LOGIN_GENERIC_ERROR = 'Correo o contrasena incorrectos.';

function redirectLogin(): never
{
    header('Location: index.php');
    exit;
}

function failLogin(string $message = LOGIN_GENERIC_ERROR, ?string $correo = null): never
{
    $_SESSION['login_error'] = $message;

    if ($correo !== null) {
        $_SESSION['login_old_correo'] = $correo;
    }

    redirectLogin();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirectLogin();
}

$csrf = $_POST['csrf_login'] ?? '';
$sessionCsrf = $_SESSION['csrf_login'] ?? '';
if (!is_string($csrf) || !is_string($sessionCsrf) || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    unset($_SESSION['csrf_login']);
    failLogin('La sesion expiro. Recarga la pagina e intenta de nuevo.');
}

$correo = trim((string)filter_input(INPUT_POST, 'correo', FILTER_UNSAFE_RAW));
$contrasena = (string)filter_input(INPUT_POST, 'contrasena', FILTER_UNSAFE_RAW);
$correo = mb_strtolower($correo, 'UTF-8');

if ($correo === '' || $contrasena === '') {
    failLogin('Correo y contrasena son obligatorios.', $correo);
}

if (strlen($correo) > 60 || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    failLogin('Ingresa un correo valido.', $correo);
}

if (strlen($contrasena) < 6 || strlen($contrasena) > 72) {
    failLogin('La contrasena debe tener entre 6 y 72 caracteres.', $correo);
}

$sql = 'SELECT u.id_documento, u.nombre, u.apellido, u.correo, u.contrasena, u.id_rol, u.foto_perfil,
               r.nombre_rol, e.nombre_estado
        FROM usuario u
        INNER JOIN rol r ON r.id_rol = u.id_rol
        INNER JOIN estado e ON e.id_estado = u.id_estado
        WHERE u.correo = :correo
          AND e.nombre_estado = :activo
        LIMIT 1';

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':correo' => $correo,
    ':activo' => 'Activo',
]);

$usuario = $stmt->fetch();

if (!$usuario || !password_verify($contrasena, (string)$usuario['contrasena'])) {
    failLogin(LOGIN_GENERIC_ERROR, $correo);
}

session_regenerate_id(true);
unset(
    $_SESSION['csrf_login'],
    $_SESSION['login_old_correo']
);

$_SESSION['usuario'] = [
    'id_documento' => $usuario['id_documento'],
    'nombre' => $usuario['nombre'],
    'apellido' => $usuario['apellido'],
    'correo' => $usuario['correo'],
    'foto_perfil' => $usuario['foto_perfil'] ?? '',
    'id_rol' => (int)$usuario['id_rol'],
    'rol' => $usuario['nombre_rol'],
];

$_SESSION['id_rol'] = (int)$usuario['id_rol'];
$_SESSION['rol'] = (string)$usuario['nombre_rol'];
$_SESSION['ultimo_acceso'] = time();

sendLoginNotification($_SESSION['usuario'], $_SESSION['rol']);

$redirectByRole = [
    1 => 'admin/index.php',
    2 => 'coordinador/index.php',
    3 => 'instructor/index.php',
    4 => 'aprendiz/index.php',
];

$redirectPath = $redirectByRole[(int)$usuario['id_rol']] ?? null;

if ($redirectPath === null) {
    $_SESSION['login_error'] = 'Rol no reconocido.';
    header('Location: index.php');
    exit;
}

header('Location: ' . app_url($redirectPath));
exit;
