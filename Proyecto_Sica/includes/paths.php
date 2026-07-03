<?php
declare(strict_types=1);

function app_base_path(): string
{
    static $basePath = null;

    if ($basePath !== null) {
        return $basePath;
    }

    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $segments = array_values(array_filter(explode('/', trim($scriptName, '/')), 'strlen'));
    $firstSegment = $segments[0] ?? '';
    $rootEntries = [
        'admin',
        'aprendiz',
        'coordinador',
        'css',
        'img',
        'includes',
        'instructor',
        'js',
        'login',
        'index.php',
    ];

    $basePath = ($firstSegment !== '' && !in_array($firstSegment, $rootEntries, true))
        ? '/' . $firstSegment
        : '';

    return $basePath;
}

function app_url(string $path): string
{
    return app_base_path() . '/' . ltrim($path, '/');
}
