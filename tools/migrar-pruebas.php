<?php
/**
 * tools/migrar-pruebas.php
 * ----------------------------------------------------------------
 * Instala y migra una base de datos de PRUEBAS AISLADA.
 *
 * Esta herramienta NUNCA debe apuntar a la base de datos real: exige
 * un nombre de base que contenga el sufijo "_prueba" o "_test" y lo
 * verifica antes de ejecutar nada. Si el nombre no es inequívocamente
 * de pruebas, aborta.
 *
 * Uso:
 *   DB_PRUEBA_HOST=127.0.0.1 DB_PRUEBA_PORT=3399 \
 *   DB_PRUEBA_NOMBRE=bs_phase2_prueba DB_PRUEBA_USUARIO=root \
 *   php tools/migrar-pruebas.php
 *
 * Qué hace:
 *   1. Crea la base si no existe (en la instancia a la que apunte).
 *   2. Carga database/schema.sql sustituyendo el nombre de la base por
 *      el de pruebas, de modo que el esquema consolidado se aplique
 *      siempre a la base correcta.
 *   3. Aplica las migraciones de database/migrations/ en orden.
 *   4. Informa del resultado de cada paso.
 *
 * Es idempotente: se puede ejecutar tantas veces como haga falta.
 * ----------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Esta herramienta solo se ejecuta por linea de comandos.\n");
    exit(1);
}

$raiz = dirname(__DIR__);

function param(string $nombre, string $defecto = ''): string {
    $v = getenv($nombre);
    if ($v === false || $v === '') {
        return $defecto;
    }
    return (string) $v;
}

$host     = param('DB_PRUEBA_HOST', '127.0.0.1');
$puerto   = (int) param('DB_PRUEBA_PORT', '3306');
$nombre   = param('DB_PRUEBA_NOMBRE');
$usuario  = param('DB_PRUEBA_USUARIO', 'root');
$clave    = param('DB_PRUEBA_CLAVE', '');
$recrear  = in_array('--recrear', $argv, true);

if ($nombre === '') {
    fwrite(STDERR, "Falta DB_PRUEBA_NOMBRE.\n");
    exit(1);
}

// --- Salvaguarda: el nombre debe ser inequívocamente de pruebas -----------
if (!preg_match('/(_prueba|_test)$/i', $nombre)) {
    fwrite(STDERR,
        "ABORTADO: el nombre de la base '$nombre' no termina en '_prueba' ni en '_test'.\n"
      . "Esta herramienta solo puede usarse contra bases de pruebas aisladas.\n");
    exit(1);
}

// --- Conexión ---------------------------------------------------------------
$opciones = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
];
try {
    $pdo = new PDO("mysql:host=$host;port=$puerto;charset=utf8mb4", $usuario, $clave, $opciones);
} catch (PDOException $e) {
    fwrite(STDERR, "No se pudo conectar a $host:$puerto -> " . $e->getMessage() . "\n");
    exit(1);
}

echo "Instancia: $host:$puerto  Base: $nombre\n";

// --- (Re)creación opcional --------------------------------------------------
if ($recrear) {
    $pdo->exec("DROP DATABASE IF EXISTS `$nombre`");
    echo "Base eliminada y reconstruida.\n";
}

$pdo->exec("CREATE DATABASE IF NOT EXISTS `$nombre` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$nombre`");
echo "Base '$nombre' disponible.\n";

/**
 * Divide un archivo .sql en sentencias ejecutables.
 * Ignora los comentarios de linea "--" y los comentarios de bloque.
 */
function sqlSentencias(string $sql): array {
    // Normalizar finales de linea: el repositorio usa CRLF en algunos
    // ficheros y LF en otros, y el separador debe funcionar en ambos.
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);
    // Comentarios de bloque.
    $sql = preg_replace('#/\*.*?\*/#s', '', $sql);
    // Comentarios de linea (el esquema no usa "#").
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);

    $sentencias = [];
    foreach (preg_split('/;\s*\n/', $sql) as $trozo) {
        $trozo = trim($trozo);
        // Quitar el ";" final de la ultima sentencia.
        $trozo = rtrim($trozo, ';');
        $trozo = trim($trozo);
        if ($trozo !== '') {
            $sentencias[] = $trozo;
        }
    }
    return $sentencias;
}

/**
 * Ejecuta una lista de sentencias tolerando las que ya existen.
 *
 * @return array{ok: int, ya_existia: int, fallaron: array}
 */
function sqlEjecutar(PDO $pdo, array $sentencias, string $etiqueta, bool $mostrarDetalle = false): array {
    $ok = 0;
    $existia = 0;
    $fallos = [];

    foreach ($sentencias as $sentencia) {
        try {
            // Una sentencia SELECT deja el cursor abierto y bloquearía
            // cualquier instrucción posterior ("Cannot execute queries
            // while other unbuffered queries are active"), así que se
            // consume por completo.
            if (preg_match('/^\s*(SELECT|SHOW)\b/i', $sentencia)) {
                $pdo->query($sentencia)->fetchAll();
            } else {
                $pdo->exec($sentencia);
            }
            $ok++;
        } catch (PDOException $e) {
            $mensaje = $e->getMessage();
            // "already exists" / "duplicate key" son idempotencia, no error.
            if (stripos($mensaje, 'already exists') !== false
                || stripos($mensaje, 'duplicate key name') !== false
                || stripos($mensaje, "Duplicate key name") !== false) {
                $existia++;
                if ($mostrarDetalle) {
                    echo "  [YA EXISTE] $etiqueta\n";
                }
                continue;
            }
            $fallos[] = $etiqueta . ': ' . trim($mensaje);
        }
    }

    return ['ok' => $ok, 'ya_existia' => $existia, 'fallaron' => $fallos];
}

// --- Carga del esquema consolidado -----------------------------------------
$rutaSchema = $raiz . '/database/schema.sql';
if (!is_file($rutaSchema)) {
    fwrite(STDERR, "No se encuentra database/schema.sql\n");
    exit(1);
}

$sql = file_get_contents($rutaSchema);
// Se fuerza el nombre de la base de pruebas, tanto en el CREATE DATABASE
// como en el USE, para que el esquema consolidado nunca se aplique por
// error a otra base de la misma instancia.
$sql = preg_replace(
    '/CREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS\s+`?([A-Za-z0-9_]+)`?/i',
    "CREATE DATABASE IF NOT EXISTS `$nombre`",
    $sql
);
$sql = preg_replace('/USE\s+`?[A-Za-z0-9_]+`?/i', "USE `$nombre`", $sql);

$resumenEsquema = sqlEjecutar($pdo, sqlSentencias($sql), 'schema.sql');
echo "Esquema consolidado aplicado (database/schema.sql): "
   . "{$resumenEsquema['ok']} sentencias ok, "
   . "{$resumenEsquema['ya_existia']} ya existian.\n";
foreach ($resumenEsquema['fallaron'] as $fallo) {
    echo "  [ERROR] $fallo\n";
}

// --- Migraciones ------------------------------------------------------------
$migraciones = glob($raiz . '/database/migrations/*.sql');
sort($migraciones);

foreach ($migraciones as $ruta) {
    $nombreMig = basename($ruta);
    $sentencias = sqlSentencias(file_get_contents($ruta));
    $resumen = sqlEjecutar($pdo, $sentencias, $nombreMig);
    $estado = $resumen['fallaron'] ? '[ERROR] ' : '[OK]    ';
    echo "  $estado $nombreMig ({$resumen['ok']} ok, {$resumen['ya_existia']} ya existian)\n";
    foreach ($resumen['fallaron'] as $fallo) {
        echo "           -> $fallo\n";
    }
}

// --- Resumen ----------------------------------------------------------------
echo "\nTablas en '$nombre':\n";
$tablas = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach ($tablas as $tabla) {
    echo '  - ' . $tabla . "\n";
}

echo "\nMigración de pruebas completada.\n";
