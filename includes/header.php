<?php
declare(strict_types=1);
require_once __DIR__ . '/paths.php';
$loginCssVersion = (string)filemtime(__DIR__ . '/../css/login.css');
$pageStyles = $pageStyles ?? [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'SICA', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('css/login.css') . '?v=' . $loginCssVersion, ENT_QUOTES, 'UTF-8') ?>">
    <?php foreach ($pageStyles as $stylePath): ?>
        <?php $styleVersion = (string)filemtime(__DIR__ . '/../' . ltrim((string)$stylePath, '/')); ?>
        <link rel="stylesheet" href="<?= htmlspecialchars(app_url((string)$stylePath) . '?v=' . $styleVersion, ENT_QUOTES, 'UTF-8') ?>">
    <?php endforeach; ?>
</head>
<body class="bg-light">
