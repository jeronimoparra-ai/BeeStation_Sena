<?php
/**
 * scripts/diagnostico-series-temporales.php
 * Herramienta de diagnóstico de solo lectura para BeeStation.
 * Se ejecuta exclusivamente desde CLI. Rechaza peticiones HTTP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../config/db.php';

try {
    $pdo = getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    fwrite(STDERR, "Fallo de conexión: La base de datos no está disponible (" . get_class($e) . ").\n");
    exit(2);
}

// Relojes
$phpNow = new DateTime('now', new DateTimeZone(date_default_timezone_get()));
$stmt = $pdo->query("SELECT NOW() AS mysql_now, @@session.time_zone AS tz, @@global.time_zone AS global_tz");
$dbTime = $stmt->fetch(PDO::FETCH_ASSOC);

$mysqlNow = new DateTime($dbTime['mysql_now'], new DateTimeZone($dbTime['tz'] === 'SYSTEM' ? date_default_timezone_get() : $dbTime['tz']));

$diffSec = abs($phpNow->getTimestamp() - $mysqlNow->getTimestamp());
$tzPhp = date_default_timezone_get();

// Esquema
$stmt = $pdo->query("SELECT id_colmena, nombre FROM colmena ORDER BY id_colmena");
$colmenas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->query("SELECT id_sensor, tipo, modelo, id_colmena FROM sensor ORDER BY id_colmena, id_sensor");
$sensores = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== DIAGNÓSTICO DE SERIES TEMPORALES — BEE STATION ===\n";
echo "Hora PHP:        " . $phpNow->format('Y-m-d H:i:s') . " (" . $tzPhp . ")\n";
echo "Hora MySQL:      " . $mysqlNow->format('Y-m-d H:i:s') . " (" . ($dbTime['tz'] ?? 'N/A') . ")\n";
echo "Diferencia reloj: " . $diffSec . " segundos\n";
if ($diffSec > 60) echo "ADVERTENCIA: Diferencia significativa entre relojes.\n";
echo "\n";

foreach ($colmenas as $col) {
    echo "--- COLMENA id=" . $col['id_colmena'] . " (" . $col['nombre'] . ") ---\n";
    $filtrosSensores = array_filter($sensores, fn($s) => $s['id_colmena'] == $col['id_colmena']);
    if (empty($filtrosSensores)) {
        echo "  Sin sensores registrados.\n";
        continue;
    }
    foreach ($filtrosSensores as $sen) {
        echo "  SENSOR id=" . $sen['id_sensor'] . " tipo=" . $sen['tipo'] . ":\n";
        // Totales y válidas/inválidas
        $st = $pdo->prepare("SELECT COUNT(*) AS total FROM lectura WHERE id_sensor = ?");
        $st->execute([$sen['id_sensor']]);
        $total = $st->fetch(PDO::FETCH_ASSOC)['total'];

        $st = $pdo->prepare("SELECT COUNT(*) AS validas FROM lectura WHERE id_sensor = ? AND es_valida = 1");
        $st->execute([$sen['id_sensor']]);
        $validas = $st->fetch(PDO::FETCH_ASSOC)['validas'];

        $st = $pdo->prepare("SELECT MAX(fecha_hora) AS ultima FROM lectura WHERE id_sensor = ?");
        $st->execute([$sen['id_sensor']]);
        $ultima = $st->fetch(PDO::FETCH_ASSOC)['ultima'];

        $st = $pdo->prepare("SELECT COUNT(*) AS c FROM lectura WHERE id_sensor = ? AND es_valida = 1 AND fecha_hora >= DATE_SUB(NOW(), INTERVAL 6 HOUR)");
        $st->execute([$sen['id_sensor']]);
        $v6h = $st->fetch(PDO::FETCH_ASSOC)['c'];

        $st = $pdo->prepare("SELECT COUNT(*) AS c FROM lectura WHERE id_sensor = ? AND es_valida = 1 AND fecha_hora >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $st->execute([$sen['id_sensor']]);
        $v24h = $st->fetch(PDO::FETCH_ASSOC)['c'];

        $st = $pdo->prepare("SELECT COUNT(*) AS c FROM lectura WHERE id_sensor = ? AND es_valida = 1 AND fecha_hora >= DATE_SUB(NOW(), INTERVAL 48 HOUR)");
        $st->execute([$sen['id_sensor']]);
        $v48h = $st->fetch(PDO::FETCH_ASSOC)['c'];

        $st = $pdo->prepare("SELECT COUNT(*) AS c FROM lectura WHERE id_sensor = ? AND es_valida = 1 AND fecha_hora >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $st->execute([$sen['id_sensor']]);
        $v7d = $st->fetch(PDO::FETCH_ASSOC)['c'];

        $st = $pdo->prepare("SELECT COUNT(*) AS c FROM lectura WHERE id_sensor = ? AND es_valida = 1 AND fecha_hora >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $st->execute([$sen['id_sensor']]);
        $v30d = $st->fetch(PDO::FETCH_ASSOC)['c'];

        echo "    Total lecturas: " . $total . " | Válidas: " . $validas . " | Inválidas: " . ($total - $validas) . "\n";
        echo "    Última lectura: " . ($ultima ? $ultima : "N/A") . "\n";
        if ($ultima) {
            $antig = $phpNow->diff(new DateTime($ultima))->format('%a días %h horas %i minutos');
            echo "    Antigüedad: " . $antig . "\n";
        }
        echo "    Válidas 6h: " . $v6h . " | 24h: " . $v24h . " | 48h: " . $v48h . " | 7d: " . $v7d . " | 30d: " . $v30d . "\n";

        // Interpretación
        if ($total == 0) echo "    DIAGNÓSTICO: Sin lecturas.\n";
        elseif ($validas == 0) echo "    DIAGNÓSTICO: Lecturas inválidas (ninguna cumple es_valida=1).\n";
        elseif ($v6h == 0 && $v24h == 0 && $v48h == 0) echo "    DIAGNÓSTICO: Sin datos recientes (última lectura anterior al período consultado).\n";
        elseif ($v30d > 0) echo "    DIAGNÓSTICO: Histórico disponible dentro de los últimos 30 días.\n";
        else echo "    DIAGNÓSTICO: Registros presentes pero fuera de ventanas recientes.\n";
        echo "\n";
    }
}

echo "=== RESUMEN DE RELOJES ===\n";
echo "PHP time_zone: " . $tzPhp . "\n";
echo "MySQL time_zone: " . ($dbTime['tz'] ?? 'N/A') . " / global: " . ($dbTime['global_tz'] ?? 'N/A') . "\n";
echo "Recomendación: No se aplican correcciones automáticas; solo se reportan diferencias.\n";
