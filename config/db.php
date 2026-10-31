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

// ── Errores en producción ────────────────────────────────────────────────────
// En producción ningún detalle técnico debe llegar al navegador: los errores
// se registran en el log del servidor y el visitante recibe un mensaje
// genérico. En desarrollo se respeta lo que diga php.ini.
if (!defined('BEE_PRODUCCION')) {
    $appEnv = strtolower(trim((string) (getenv('APP_ENV') ?: '')));
    define('BEE_PRODUCCION', $appEnv === 'production');
    if (BEE_PRODUCCION) {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('log_errors', '1');
    }
}

$configLocal = __DIR__ . '/db.local.php';
$config = is_file($configLocal) ? (array) require $configLocal : [];

// ── Modo de base de datos AISLADA ───────────────────────────────────────────
//
//  Con BEE_DB_AISLADA=1 la configuración se toma EXCLUSIVAMENTE de las
//  variables de entorno y se ignora config/db.local.php (que contiene las
//  credenciales de la base de datos real).
//
//  Es lo que usa la batería de pruebas para garantizar que NINGUNA prueba
//  escribe en la base de datos de producción. Además, en este modo se
//  exige que el nombre de la base termine en "_prueba" o "_test": si no,
//  la aplicación se niega a arrancar en lugar de arriesgar la base real.
$beeDbAislada = in_array(
    strtolower(trim((string) (getenv('BEE_DB_AISLADA') ?: ''))),
    ['1', 'true', 'si', 'yes', 'on'],
    true
);

$resolver = function (string $clave, string $defecto) use ($config, $beeDbAislada): string {
    if (!$beeDbAislada && isset($config[$clave]) && (string) $config[$clave] !== '') {
        return (string) $config[$clave];
    }
    $entorno = getenv($clave);
    return ($entorno === false || $entorno === '') ? $defecto : (string) $entorno;
};

define('DB_HOST', $resolver('DB_HOST', '127.0.0.1'));
define('DB_NAME', $resolver('DB_NAME', 'beestation_sena'));
define('DB_USER', $resolver('DB_USER', ''));
define('DB_PASS', $resolver('DB_PASS', ''));
// Puerto: 3306 por defecto (MySQL/MariaDB estandar). Se puede cambiar por
// entorno o en db.local.php, lo que permite trabajar contra una instancia
// de pruebas AISLADA (p. ej. 3399) sin tocar la base de datos real.
define('DB_PORT', (int) $resolver('DB_PORT', '3306'));

if ($beeDbAislada && !preg_match('/(_prueba|_test)$/i', DB_NAME)) {
    throw new RuntimeException(
        'BEE_DB_AISLADA exige que el nombre de la base termine en "_prueba" '
        . 'o "_test". Se ha recibido "' . DB_NAME . '": no se permite operar '
        . 'en modo aislado sobre una base de datos real.'
    );
}

function getPDO(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (DB_USER === '') {
            throw new RuntimeException('Database credentials are not configured.');
        }
        $production = BEE_PRODUCCION;
        if ($production && (strtolower(DB_USER) === 'root' || DB_PASS === '')) {
            throw new RuntimeException('Production database configuration is unsafe.');
        }
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

        // Modo estricto: un dato fuera de rango se rechaza con error en
        // lugar de truncarse en silencio (p. ej. un token de 60 chars
        // que antes se guardaba como de 50).
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE'");

        // Zona horaria fija de la sesión: independiente de la del SO del
        // servidor, para que las ventanas de 5 min / 24 h no se corran.
        $pdo->exec("SET time_zone = '-05:00'");
        } catch (PDOException $e) {
            error_log('BeeStation: database connection/setup failed.');
            throw new RuntimeException('Database is temporarily unavailable.');
        }
    }
    return $pdo;
}
