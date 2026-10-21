<?php
/**
 * config/db.php
 * ----------------------------------------------------------------
 * Configuración de la aplicación. Este archivo NO contiene secretos.
 *
 * La API ya no usa una clave global: cada nodo ESP32 firma sus
 * peticiones con el secreto de SU colmena (ver includes/firma.php),
 * de modo que no existe ninguna API Key que compilar en el firmware
 * ni que rotear en bloque.
 *
 * Los valores reales (usuario y contraseña de MySQL) viven en
 *   config/db.local.php   → fuera del control de versiones
 * o en variables de entorno (DB_HOST, DB_NAME, DB_USER, DB_PASS).
 *
 * Instalación nueva:
 *   1) copia config/db.example.php  →  config/db.local.php
 *   2) ajusta los valores
 * ----------------------------------------------------------------
 */

// Zona horaria de la aplicación. Debe coincidir con la que fija la
// conexión PDO (SET time_zone = '-05:00') para que las comparaciones
// NOW() de SQL y las marcas de tiempo de PHP sean coherentes.
date_default_timezone_set('America/Bogota');

$configLocal = __DIR__ . '/db.local.php';
$config = is_file($configLocal) ? (array) require $configLocal : [];

define('DB_HOST', $config['DB_HOST'] ?? (getenv('DB_HOST') ?: '127.0.0.1'));
define('DB_NAME', $config['DB_NAME'] ?? (getenv('DB_NAME') ?: 'beestation_sena'));
define('DB_USER', $config['DB_USER'] ?? (getenv('DB_USER') ?: 'root'));
define('DB_PASS', $config['DB_PASS'] ?? (getenv('DB_PASS') ?: ''));

function getPDO(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

        // Modo estricto: un dato fuera de rango se rechaza con error en
        // lugar de truncarse en silencio (p. ej. un token de 60 chars
        // que antes se guardaba como de 50).
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE'");

        // Zona horaria fija de la sesión: independiente de la del SO del
        // servidor, para que las ventanas de 5 min / 24 h no se corran.
        $pdo->exec("SET time_zone = '-05:00'");
    }
    return $pdo;
}
