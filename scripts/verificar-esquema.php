<?php
/**
 * scripts/verificar-esquema.php
 * ----------------------------------------------------------------
 * Detecta si la base de datos real se ha separado de database/schema.sql.
 *
 * Que hace:
 *   1. Crea una base temporal (por defecto beestation_verif_prueba) y
 *      carga en ella database/schema.sql, que es la ruta de instalacion
 *      limpia documentada.
 *   2. Compara columnas, indices y claves foraneas de la base temporal
 *      contra la base real del sistema.
 *   3. Elimina la base temporal e informa de las diferencias.
 *
 * Codigo de salida:
 *   0 = la base real coincide con el esquema
 *   1 = hay diferencias (o fallo de ejecucion)
 *
 * Uso:
 *   php scripts/verificar-esquema.php
 *   DB_NAME=beestation_sena php scripts/verificar-esquema.php
 *
 * Variables de entorno:
 *   DB_HOST, DB_PORT, DB_USER, DB_PASS  -> instancia MySQL (por defecto
 *                                         127.0.0.1:3306, root sin clave)
 *   DB_NAME                             -> base real a verificar
 *   DB_VERIF_NOMBRE                     -> base temporal (debe terminar
 *                                         en _prueba o _test)
 * ----------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo se ejecuta por linea de comandos.\n");
    exit(1);
}

$raiz = dirname(__DIR__);

function env(string $nombre, string $defecto = ''): string {
    $valor = getenv($nombre);
    return ($valor === false || $valor === '') ? $defecto : (string) $valor;
}

$host    = env('DB_HOST', '127.0.0.1');
$puerto  = (int) env('DB_PORT', '3306');
$usuario = env('DB_USER', 'root');
$clave   = env('DB_PASS', '');
$real    = env('DB_NAME', 'beestation_sena');
$temporal = env('DB_VERIF_NOMBRE', 'beestation_verif_prueba');

// Salvaguarda: la base temporal nunca puede ser la base real ni una base
// sin sufijo inequivoco de pruebas.
if (!preg_match('/(_prueba|_test)$/i', $temporal)) {
    fwrite(STDERR, "ABORTADO: '$temporal' no termina en _prueba ni _test.\n");
    exit(1);
}
if (strcasecmp($temporal, $real) === 0) {
    fwrite(STDERR, "ABORTADO: la base temporal no puede ser la base real.\n");
    exit(1);
}

$rutaSchema = $raiz . '/database/schema.sql';
if (!is_file($rutaSchema)) {
    fwrite(STDERR, "No se encuentra database/schema.sql\n");
    exit(1);
}

$opciones = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    // Conexion sin base seleccionada: primero se comprueba que la base real
    // existe y despues se construye la temporal.
    $pdo = new PDO("mysql:host=$host;port=$puerto;charset=utf8mb4", $usuario, $clave, $opciones);

    $existe = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?"
    );
    $existe->execute([$real]);
    if ((int) $existe->fetchColumn() === 0) {
        fwrite(STDERR, "La base real '$real' no existe en $host:$puerto\n");
        exit(1);
    }

    // Reconstruccion limpia de la base temporal.
    $pdo->exec("DROP DATABASE IF EXISTS `$temporal`");
    $pdo->exec("CREATE DATABASE `$temporal` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$temporal`");

    // Carga de schema.sql sentencia a sentencia (sin comentarios).
    $sql = file_get_contents($rutaSchema);
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);
    $sql = preg_replace('#/\*.*?\*/#s', '', $sql);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);

    $sentencias = 0;
    foreach (preg_split('/;\s*\n/', $sql) as $trozo) {
        $trozo = trim(rtrim(trim($trozo), ';'));
        if ($trozo === '') {
            continue;
        }
        $pdo->exec($trozo);
        $sentencias++;
    }

    echo "Esquema de referencia cargado en '$temporal' ($sentencias sentencias).\n";

    /**
     * Devuelve una huella ordenada del esquema: columnas, indices y FK.
     */
    function huellaEsquema(PDO $pdo, string $base): array {
        $columnas = $pdo->prepare(
            "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
                    IFNULL(COLUMN_DEFAULT, '~') AS DEFECTO, EXTRA
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ?
              ORDER BY TABLE_NAME, COLUMN_NAME"
        );
        $columnas->execute([$base]);

        $indices = $pdo->prepare(
            "SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = ?
              ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"
        );
        $indices->execute([$base]);

        $foraneas = $pdo->prepare(
            "SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME,
                    DELETE_RULE, UPDATE_RULE
               FROM information_schema.REFERENTIAL_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = ?
              ORDER BY TABLE_NAME, CONSTRAINT_NAME"
        );
        $foraneas->execute([$base]);

        $lineas = [];
        foreach ($columnas->fetchAll() as $f) {
            $lineas[] = "COLUMNA\t{$f['TABLE_NAME']}\t{$f['COLUMN_NAME']}\t"
                . "{$f['COLUMN_TYPE']}\t{$f['IS_NULLABLE']}\t{$f['DEFECTO']}\t{$f['EXTRA']}";
        }
        foreach ($indices->fetchAll() as $f) {
            $lineas[] = "INDICE\t{$f['TABLE_NAME']}\t{$f['INDEX_NAME']}\t"
                . "{$f['SEQ_IN_INDEX']}\t{$f['COLUMN_NAME']}\t{$f['NON_UNIQUE']}";
        }
        foreach ($foraneas->fetchAll() as $f) {
            $lineas[] = "FK\t{$f['TABLE_NAME']}\t{$f['CONSTRAINT_NAME']}\t"
                . "{$f['REFERENCED_TABLE_NAME']}\t{$f['DELETE_RULE']}\t{$f['UPDATE_RULE']}";
        }
        sort($lineas);

        return $lineas;
    }

    $referencia = huellaEsquema($pdo, $temporal);
    $actual     = huellaEsquema($pdo, $real);

    $soloEsquema = array_values(array_diff($referencia, $actual));
    $soloReal    = array_values(array_diff($actual, $referencia));

    // Limpieza garantizada de la base temporal antes de informar.
    $pdo->exec("DROP DATABASE IF EXISTS `$temporal`");

    if (!$soloEsquema && !$soloReal) {
        echo "OK: '$real' coincide con database/schema.sql "
            . "(" . count($actual) . " definiciones).\n";
        exit(0);
    }

    echo "\nDIFERENCIAS DETECTADAS entre database/schema.sql y '$real':\n";
    foreach ($soloEsquema as $linea) {
        echo "  [falta en la base real] $linea\n";
    }
    foreach ($soloReal as $linea) {
        echo "  [sobra en la base real] $linea\n";
    }
    echo "\nCorrige la diferencia con una migracion en database/migrations/ "
        . "o actualizando database/schema.sql, y vuelve a ejecutar la verificacion.\n";
    exit(1);
} catch (PDOException $e) {
    // Nunca se imprime la contrasena: el mensaje de PDO no la incluye.
    fwrite(STDERR, "Error de base de datos: " . $e->getMessage() . "\n");
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            $pdo->exec("DROP DATABASE IF EXISTS `$temporal`");
        }
    } catch (PDOException $ignorado) {
        // La limpieza es best-effort: si falla, el error original manda.
    }
    exit(1);
}
