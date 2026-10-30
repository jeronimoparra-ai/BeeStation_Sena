<?php
/**
 * scripts/verificar-esquema.php
 * ----------------------------------------------------------------
 * Detecta si la base de datos real se ha separado de database/schema.sql.
 *
 * Comportamiento seguro y sin privilegios destructivos innecesarios.
 * Que hace:
 *   1. Genera un identificador único e inseparable de la ejecución
 *      (DB_VERIF_ID, o auto-generado con aleatoria criptográfica).
 *   2. Calcula el nombre de la base temporal:
 *        - Si DB_VERIF_NOMBRE se da, debe terminar en "_prueba" o "_test"
 *          y NO debe existir previamente. En caso contrario se ABORTA.
 *        - Si no se da, se genera uno único por ejecución:
 *          beestation_verif_<random>_<pid>_<ts>_prueba.
 *   3. Comprueba que la base temporal NO exista antes de crearla.
 *      Si existe: ABORTA. No se borrará, se conserva para inspección.
 *   4. Crea la base mediante una sentencia SIN "IF NOT EXISTS", que falla
 *      si ya existiese (defensa adicional sobre el punto 3).
 *   5. Registra un marcador de propiedad EXCLUSIVO de esta ejecución,
 *      dentro de la propia base temporal (tabla verif_ownership).
 *   6. Carga database/schema.sql en la base temporal.
 *   7. Compara columnas, índices y claves foráneas de la temporal contra
 *      la base real mediante information_schema.
 *   8. Limpia SOLO si el marcador de propiedad coincide con la
 *      ejecución actual. Si no coincide: ABORTA y se conserva la base.
 *   9. Modo "--info" (DB_VERIF_MODE=info): SOLO LECTURA. Lee la estructura
 *      de la base real desde information_schema y la compara con la
 *      definición de schema.sql parseada en PHP. No crea ni elimina
 *      ninguna base.
 *
 * Variables de entorno:
 *   DB_HOST, DB_PORT, DB_USER, DB_PASS  -> instancia MySQL (default 127.0.0.1:3306)
 *   DB_NAME                             -> base real a verificar (default beestation_sena)
 *   DB_VERIF_NOMBRE                     -> nombre de la base temporal (opcional)
 *   DB_VERIF_MODE                       -> "full" (por defecto) o "info" (solo lectura)
 *   DB_VERIF_ID                         -> identificador de ejecución (opcional)
 *
 * Código de salida:
 *   0 = la base real coincide con database/schema.sql
 *   1 = hay diferencias o fallo de ejecución
 *
 * Seguridad:
 *   - Nunca se usa DROP DATABASE IF EXISTS.
 *   - La limpieza solo borra bases cuyo marcador de propiedad coincida
 *     con la identificación de esta ejecución.
 *   - Los identificadores SQL se validan estrictamente y se escapan con
 *     comillas invertidas, impidiendo inyección.
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

function validarNombreBase(string $nombre, string $tipo): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $nombre)) {
        fwrite(STDERR, "ABORTADO: '${tipo}' '${nombre}' contiene caracteres no permitidos.\n");
        return false;
    }
    return true;
}

function generarIdEjecucion(): string {
    $rand = bin2hex(random_bytes(8));
    $pid  = getmypid();
    // sprintf('%.0f') garantiza una cadena entera sin notación científica,
    // que no puede introducir caracteres no alfanuméricos.
    $ts   = sprintf('%.0f', microtime(true) * 1e6);
    return "{$rand}_{$pid}_{$ts}";
}

function crearNombreTemporal(string $execId, string $override = ''): string {
    if ($override !== '') {
        return $override;
    }
    return 'beestation_verif_' . $execId . '_prueba';
}

function sqlSentencias(string $sql): array {
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);
    $sql = preg_replace('#/\*.*?\*/#s', '', $sql);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $sentencias = [];
    foreach (preg_split('/;/', $sql) as $trozo) {
        $trozo = trim($trozo);
        if ($trozo !== '') {
            $sentencias[] = $trozo;
        }
    }
    return $sentencias;
}

function obtenerDefiniciones(PDO $pdo, string $base): array {
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
        $lineas[] = "COLUMNA\t{$f['TABLE_NAME']}\t{$f['COLUMN_NAME']}\t".
            "{$f['COLUMN_TYPE']}\t{$f['IS_NULLABLE']}\t{$f['DEFECTO']}\t{$f['EXTRA']}";
    }
    foreach ($indices->fetchAll() as $f) {
        $lineas[] = "INDICE\t{$f['TABLE_NAME']}\t{$f['INDEX_NAME']}\t".
            "{$f['SEQ_IN_INDEX']}\t{$f['COLUMN_NAME']}\t{$f['NON_UNIQUE']}";
    }
    foreach ($foraneas->fetchAll() as $f) {
        $lineas[] = "FK\t{$f['TABLE_NAME']}\t{$f['CONSTRAINT_NAME']}\t".
            "{$f['REFERENCED_TABLE_NAME']}\t{$f['DELETE_RULE']}\t{$f['UPDATE_RULE']}";
    }
    sort($lineas);
    return $lineas;
}

/**
 * Convierte la definición textual de schema.sql en la misma forma que
 * information_schema devuelve las definiciones (solo lectura). Se usa en
 * el modo info para poder comparar la base real contra schema.sql sin
 * crear/destruir bases.
 */
function schemaSqlDefiniciones(string $sql): array {
    $lineas = [];
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);
    $sql = preg_replace('#/\*.*?\*/#s', '', $sql);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);

    $sentencias = [];
    foreach (preg_split('/;/', $sql) as $trozo) {
        $trozo = trim($trozo);
        if ($trozo !== '') {
            $sentencias[] = $trozo;
        }
    }

    foreach ($sentencias as $sent) {
        if (!preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $sent, $m)) {
            continue;
        }
        $nombre = $m[1];
        $nombrePos = strpos($sent, $nombre);
        $open = strpos($sent, '(', $nombrePos) + 1;
        $depth = 1;
        $j = $open;
        $inQuote = false;
        $quoteCh = '';
        while ($j < strlen($sent) && $depth > 0) {
            $ch = $sent[$j];
            if ($inQuote) {
                if ($ch === $quoteCh) {
                    $inQuote = false;
                }
            } else {
                if ($ch === '\'' || $ch === '"') {
                    $inQuote = true;
                    $quoteCh = $ch;
                } elseif ($ch === '(') {
                    $depth++;
                } elseif ($ch === ')') {
                    $depth--;
                }
            }
            $j++;
        }
        $body = substr($sent, $open, $j - $open);
        $defs = schemaSqlParseBody($nombre, $body);
        foreach ($defs as $d) {
            $lineas[] = $d;
        }
    }
    sort($lineas);
    return $lineas;
}

function schemaSqlParseBody(string $nombre, string $body): array {
    $defs = [];
    $filtros = schemaSqlFiltrarNivel($body);
    foreach ($filtros as $filtro) {
        $filtro = trim($filtro);
        if ($filtro === '') {
            continue;
        }
        if (preg_match('/FOREIGN\s+KEY\s*\(([^)]+)\)\s+REFERENCES\s+([A-Za-z0-9_]+)\s*\(([^)]+)\)(?:\s+ON\s+DELETE\s+(\w+))?/i', $filtro, $m)) {
            $fkName = 'fk_'.strtolower($m[2]).'_'.strtolower($m[1]);
            $deleteRule = isset($m[4]) ? strtoupper($m[4]) : 'CASCADE';
            $defs[] = "FK\t{$nombre}\t{$fkName}\t{$m[2]}\t{$deleteRule}\tCASCADE";
            continue;
        }
        if (preg_match('/^(?:UNIQUE\s+)?(?:KEY|INDEX)\s+([A-Za-z0-9_]+)?\s*\(([^)]+)\)$/i', $filtro, $m)) {
            $isUnique = stripos($filtro, 'UNIQUE') !== false;
            $columnas = $m[2];
            $nonUnique = $isUnique ? '0' : '1';
            $defs[] = "INDICE\t{$nombre}\t{$m[1]}\t1\t{$columnas}\t{$nonUnique}";
            continue;
        }
        if (preg_match('/^PRIMARY\s+KEY\s*\(([^)]+)\)$/i', $filtro, $m)) {
            $defs[] = "INDICE\t{$nombre}\tPRIMARY KEY\t1\t{$m[1]}\t0";
            continue;
        }
        // PRIMARY KEY declarado inline al final de una definición de columna
        // (ej. id_variable INT AUTO_INCREMENT PRIMARY KEY, o PRIMARY KEY (id)).
        if (preg_match('/^([A-Za-z0-9_]+)\s+([A-Z][A-Z0-9_]+.*?)\bPRIMARY\s+KEY(?:\s*\(.*\))?$/i', $filtro, $m)) {
            $defs[] = "INDICE\t{$nombre}\tPRIMARY KEY\t1\t{$m[1]}\t0";
            continue;
        }
        // Columnas (excluye únicamente definiciones que no sean de columna).
        if (preg_match('/^([A-Za-z0-9_]+)\s+([A-Z][A-Z0-9_]+)(.*)$/m', $filtro, $m)) {
            // UNIQUE declarado inline en la cabecera de una columna
            // (ej. correo VARCHAR(150) NOT NULL UNIQUE).
            if (stripos($filtro, 'UNIQUE') !== false) {
                $defs[] = "INDICE\t{$nombre}\tUQ_{$m[1]}\t1\t{$m[1]}\t0";
                continue;
            }
            $isNull = stripos($filtro, 'NOT NULL') !== false ? 'NO' : 'YES';
            $default = '';
            if (preg_match('/DEFAULT\s+(.*)$/i', $filtro, $mDef)) {
                $default = trim($mDef[1]);
                $default = str_replace("'", '', $default);
            }
            $extra = '';
            foreach (['AUTO_INCREMENT', 'UNSIGNED', 'CURRENT_TIMESTAMP'] as $tok) {
                if (stripos($filtro, $tok) !== false) {
                    $extra .= ($extra !== '' ? ' ' : '').$tok;
                }
            }
            $defs[] = "COLUMNA\t{$nombre}\t{$m[1]}\t{$m[2]}\t{$isNull}\t{$default}\t{$extra}";
            continue;
        }
    }
    return $defs;
}

function schemaSqlFiltrarNivel(string $body): array {
    $fragments = [];
    $current = '';
    $depth = 0;
    $inQuote = false;
    $quoteCh = '';
    $len = strlen($body);
    for ($i = 0; $i < $len; $i++) {
        $ch = $body[$i];
        if ($inQuote) {
            $current .= $ch;
            if ($ch === $quoteCh) {
                $inQuote = false;
            }
            continue;
        }
        if ($ch === '\'' || $ch === '"') {
            $inQuote = true;
            $quoteCh = $ch;
            $current .= $ch;
            continue;
        }
        if ($ch === '(') {
            $depth++;
            $current .= $ch;
            continue;
        }
        if ($ch === ')') {
            $depth--;
            $current .= $ch;
            continue;
        }
        if ($ch === ',' && $depth === 0) {
            $fragments[] = $current;
            $current = '';
            continue;
        }
        $current .= $ch;
    }
    if ($current !== '') {
        $fragments[] = $current;
    }
    return $fragments;
}

function leerMarcaPropiedad(PDO $pdo, string $base, string $execId): ?string {
    try {
        $tabla = 'verif_ownership';
        $stmt = $pdo->prepare(
            "SELECT exec_id FROM `$base`.`{$tabla}` WHERE exec_id = ? LIMIT 1"
        );
        $stmt->execute([$execId]);
        $fila = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $fila['exec_id'] ?? null;
    } catch (PDOException $e) {
        return null;
    }
}

$modo = 'full';
$argvRaw = $argv;
array_shift($argvRaw);
foreach ($argvRaw as $arg) {
    if (strtolower($arg) === '--info' || strtolower($arg) === '-i') {
        $modo = 'info';
    }
}
if (env('DB_VERIF_MODE') !== '') {
    $modo = strtolower(trim((string) env('DB_VERIF_MODE')));
    if ($modo !== 'full' && $modo !== 'info') {
        fwrite(STDERR, "ABORTADO: DB_VERIF_MODE debe ser 'full' o 'info'.\n");
        exit(1);
    }
}

$host    = env('DB_HOST', '127.0.0.1');
$puerto  = (int) env('DB_PORT', '3306');
$usuario = env('DB_USER', 'root');
$clave   = env('DB_PASS', '');
$real    = env('DB_NAME', 'beestation_sena');
$rutaSchema = $raiz . '/database/schema.sql';

if (!validarNombreBase($real, 'base real')) {
    exit(1);
}

$temporal = '';
$execId   = env('DB_VERIF_ID', '');

if ($modo === 'full') {
    if ($execId === '') {
        $execId = generarIdEjecucion();
    }
    $temporal = crearNombreTemporal($execId, env('DB_VERIF_NOMBRE', ''));
    if (!validarNombreBase($temporal, 'base temporal')) {
        exit(1);
    }
    if (!preg_match('/(_prueba|_test)$/i', $temporal)) {
        fwrite(STDERR, "ABORTADO: la base temporal '${temporal}' no termina en _prueba ni _test.\n");
        exit(1);
    }
    if (strcasecmp($temporal, $real) === 0) {
        fwrite(STDERR, "ABORTADO: la base temporal no puede ser la base real.\n");
        exit(1);
    }
}

$opciones = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$pdo = new PDO("mysql:host=$host;port=$puerto;charset=utf8mb4", $usuario, $clave, $opciones);

$existeReal = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?"
);
$existeReal->execute([$real]);
if ((int) $existeReal->fetchColumn() === 0) {
    fwrite(STDERR, "La base real '${real}' no existe en $host:$puerto\n");
    exit(1);
}

$temporalCreada = false;
$referencia = [];
$actual     = [];

if ($modo === 'full') {
    $existeTemporal = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?"
    );
    $existeTemporal->execute([$temporal]);
    if ((int) $existeTemporal->fetchColumn() !== 0) {
        fwrite(STDERR,
            "ABORTADO: la base temporal '${temporal}' ya existe. ".
            "No está autorizada la eliminación de una base ajena. ".
            "Consérvala para inspección o elimina el nombre 'DB_VERIF_NOMBRE'.\n"
        );
        exit(1);
    }

    try {
        $pdo->exec("CREATE DATABASE `$temporal` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $temporalCreada = true;
        echo "Base temporal creada: '${temporal}'.\n";
    } catch (PDOException $e) {
        fwrite(STDERR,
            "ABORTADO: no se pudo crear '${temporal}'. Ya existe o no hay permisos.\n"
        );
        exit(1);
    }

    $pdo->exec("CREATE TABLE `$temporal`.`verif_ownership` (exec_id VARCHAR(255) NOT NULL, UNIQUE KEY uq_exec (exec_id)) ENGINE=InnoDB");
    // $pdo->quote() ya incluye las comillas; no anidarlas.
    $pdo->exec("INSERT INTO `$temporal`.`verif_ownership` (exec_id) VALUES (" . $pdo->quote($execId) . ")");

    $rutaSchema = $raiz . '/database/schema.sql';
    if (!is_file($rutaSchema)) {
        fwrite(STDERR, "No se encuentra database/schema.sql\n");
        exit(1);
    }
    $sql = file_get_contents($rutaSchema);
    $sentencias = sqlSentencias($sql);
    foreach ($sentencias as $sentencia) {
        $pdo->exec("USE `$temporal`; " . $sentencia);
    }
    echo "Esquema de referencia cargado en '${temporal}' (" . count($sentencias) . " definiciones).\n";

    $referencia = obtenerDefiniciones($pdo, $temporal);
    // Excluir la tabla verif_ownership: artifact propio del verificador
    // (marcador de propiedad), no parte del esquema SQL.
    $referencia = array_values(
        array_filter(
            $referencia,
            static function (string $linea): bool {
                $campos = explode("\t", $linea);
                return ($campos[1] ?? '') !== 'verif_ownership';
            }
        )
    );
    $actual     = obtenerDefiniciones($pdo, $real);

    $propietaria = leerMarcaPropiedad($pdo, $temporal, $execId);
    if ($propietaria === $execId) {
        $pdo->exec("DROP DATABASE `$temporal`");
        echo "Base temporal '${temporal}' eliminada (propiedad confirmada).\n";
    } else {
        fwrite(STDERR,
            "ABORTADO: no se pudo confirmar la propiedad de '${temporal}'. ".
            "La base se conserva para inspección. Propietario: ".
            ($propietaria === null ? 'indeterminada' : $propietaria) . "\n"
        );
        exit(1);
    }
} else {
    $pdo->exec("USE `$real`");
    $referencia = schemaSqlDefiniciones(file_get_contents($rutaSchema));
    $actual     = obtenerDefiniciones($pdo, $real);
    echo "Modo 'info': comparando '${real}' contra database/schema.sql (sin tocar bases).\n";
}

$soloEsquema = array_values(array_diff($referencia, $actual));
$soloReal    = array_values(array_diff($actual, $referencia));

if (!$soloEsquema && !$soloReal) {
    $total = count($actual);
    echo "OK: '${real}' coincide con database/schema.sql (" . $total . " definiciones).\n";
    exit(0);
}

echo "\nDIFERENCIAS DETECTADAS entre database/schema.sql y '${real}':\n";
foreach ($soloEsquema as $linea) {
    echo "  [falta en la base real] $linea\n";
}
foreach ($soloReal as $linea) {
    echo "  [sobra en la base real] $linea\n";
}
echo "\nCorrige la diferencia con una migracion en database/migrations/ ".
     "o actualizando database/schema.sql, y vuelve a ejecutar la verificacion.\n";
exit(1);