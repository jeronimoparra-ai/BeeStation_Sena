<?php
/**
 * config/db.php
 * Conexión real a MySQL/MariaDB mediante PDO.
 * Ajusta estas 4 constantes según tu entorno (XAMPP, servidor SENA, etc.)
 */

// Zona horaria de la aplicación. DEBE coincidir con la del servidor MySQL
// (America/Bogota, UTC-5) para que las comparaciones NOW() de SQL y las
// marcas de tiempo de PHP (time(), strtotime(), date()) sean coherentes.
date_default_timezone_set('America/Bogota');

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'beestation_sena');
define('DB_USER', 'root');
define('DB_PASS', '');   // pon aquí la clave real de tu MySQL/MariaDB

// ── SEGURIDAD API (ESP32 -> api/ingest.php) ──────────────────
// Clave precompartida requerida en el header 'X-API-Key' de cada POST.
// Cámbiala por una cadena segura y configúrala idéntica en el firmware del ESP32.
define('BEESTATION_API_KEY', 'ba20858ed12a853bbcaafec2d9c753db0ad9f7e60022d6e1a3f94f978743808a');

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
    }
    return $pdo;
}
