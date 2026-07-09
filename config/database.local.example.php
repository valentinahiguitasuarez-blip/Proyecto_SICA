<?php
declare(strict_types=1);

/**
 * Conexion remota opcional (database.local.php).
 *
 * - Misma WiFi: database.local.example.php (IP del PC servidor)
 * - Cualquier lugar: database.cloud.example.php (db4free.net u otro hosting)
 *
 * Copia una plantilla como database.local.php (no se sube a GitHub).
 */
return [
    'host' => '192.168.10.89',
    'port' => 3306,
    'name' => 'sica',
    'user' => 'sica_equipo',
    'pass' => 'SicaEquipo2026',
];
