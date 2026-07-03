<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([2]);
$pageTitle = 'Coordinador Académico - SICA';
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>
<?php include_once __DIR__ . '/../includes/menu.php'; ?>
<div class="container py-5">
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h1 class="card-title">Panel del Coordinador Académico</h1>
                    <p class="card-text">Revisa solicitudes, aprueba eventos y valida la disponibilidad del auditorio.</p>
                    <div class="alert alert-info" role="alert">
                        Bienvenido, <?= htmlspecialchars($_SESSION['usuario']['nombre'], ENT_QUOTES, 'UTF-8') ?>.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
