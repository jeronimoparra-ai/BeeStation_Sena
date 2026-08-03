<?php
/**
 * config/db.php
 * Conexión real a MySQL/MariaDB mediante PDO.
 * Ajusta estas 4 constantes según tu entorno (XAMPP, servidor SENA, etc.)
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'beestation_sena');
define('DB_USER', 'root');
define('DB_PASS', '');   // pon aquí la clave real de tu MySQL/MariaDB

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
