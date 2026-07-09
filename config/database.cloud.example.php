<?php
declare(strict_types=1);

/**
 * BASE EN LA NUBE (recomendado si no estan en la misma WiFi).
 *
 * 1. Una sola vez: crear cuenta en https://www.db4free.net
 * 2. Crear base de datos "sica" (o el nombre que den).
 * 3. Importar sica_equipo.sql en phpMyAdmin de db4free.
 * 4. Copiar este archivo como database.local.php (Kevin y Valentina).
 * 5. Pegar host, usuario, clave y nombre reales de db4free.
 *
 * Asi las dos ven los mismos eventos desde cualquier lugar con internet.
 */
return [
    'host' => 'db4free.net',
    'port' => 3306,
    'name' => 'sica',
    'user' => 'TU_USUARIO_DB4FREE',
    'pass' => 'TU_CLAVE_DB4FREE',
];
