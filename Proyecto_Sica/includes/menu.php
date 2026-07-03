<?php
declare(strict_types=1);
require_once __DIR__ . '/paths.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userName = $_SESSION['usuario']['nombre'] ?? 'Usuario';
$userRole = $_SESSION['rol'] ?? 'Invitado';
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-success">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">SICA</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar"
                aria-controls="mainNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link active" aria-current="page" href="<?= htmlspecialchars(app_url('index.php'), ENT_QUOTES, 'UTF-8') ?>">Inicio</a>
                </li>
            </ul>
            <div class="d-flex align-items-center text-white">
                <div class="me-3 text-end">
                    <small class="d-block">Bienvenido</small>
                    <strong><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></strong>
                    <div class="small">Rol: <?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <a href="<?= htmlspecialchars(app_url('login/logout.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-light">Cerrar sesión</a>
            </div>
        </div>
    </div>
</nav>
