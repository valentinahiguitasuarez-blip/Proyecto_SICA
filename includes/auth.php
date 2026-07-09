<?php
declare(strict_types=1);
require_once __DIR__ . '/paths.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function password_meets_policy(string $password): bool
{
    return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{6,72}$/', $password) === 1;
}

function password_policy_message(): string
{
    return 'La contraseña debe tener entre 6 y 72 caracteres, incluir una mayúscula, una minúscula, un número y un carácter especial.';
}

function iniciarSesionSegura(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['CREATED'])) {
        $_SESSION['CREATED'] = time();
    } elseif (time() - $_SESSION['CREATED'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['CREATED'] = time();
    }
}

function estaAutenticado(): bool
{
    return !empty($_SESSION['usuario']) && (!empty($_SESSION['rol']) || !empty($_SESSION['id_rol']));
}

function requireLogin(): void
{
    if (!estaAutenticado()) {
        header('Location: ' . app_url('login/index.php'));
        exit;
    }
}

function requireRole(array $roles): void
{
    requireLogin();

    $sessionRoleId = (int)($_SESSION['id_rol'] ?? ($_SESSION['usuario']['id_rol'] ?? 0));
    $sessionRoleName = (string)($_SESSION['rol'] ?? '');

    if ($sessionRoleId === 0 && $sessionRoleName !== '') {
        $normalizedRole = mb_strtolower($sessionRoleName, 'UTF-8');
        if (str_contains($normalizedRole, 'administrador')) {
            $sessionRoleId = 1;
        } elseif (str_contains($normalizedRole, 'coordinador')) {
            $sessionRoleId = 2;
        } elseif (str_contains($normalizedRole, 'instructor')) {
            $sessionRoleId = 3;
        } elseif (str_contains($normalizedRole, 'aprendiz')) {
            $sessionRoleId = 4;
        }
    }

    foreach ($roles as $role) {
        if (is_int($role) && $sessionRoleId === $role) {
            return;
        }

        if (is_string($role) && $sessionRoleName === $role) {
            return;
        }
    }

    header('HTTP/1.1 403 Forbidden');
    exit('Acceso denegado.');
}
