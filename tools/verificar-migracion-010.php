<?php
declare(strict_types=1);

/**
 * tools/verificar-migracion-010.php
 * ------------------------------------------------------------------
 * Bateria de pruebas REALES de la migracion 010.
 *
 * Cada caso usa una base desechable propia, creada por esta ejecucion
 * (nombre aleatorio + marcador de propiedad). Solo se eliminan bases
 * creadas aqui; si existiera una base con el nombre elegido, se aborta
 * en vez de borrarla.
 *
 * Se ejecuta el migrador real como subproceso con entorno minimo y
 * credenciales por entorno (nunca por argv).
 *
 * USO
 *   MIG010_HOST=127.0.0.1 MIG010_PORT=3310 \
 *   MIG010_USER=... MIG010_PASS=... \
 *   php tools/verificar-migracion-010.php
 *
 * GUARDA DE INSTANCIA
 *   Por defecto se niega a operar contra el puerto 3306 (instancia
 *   habitual de trabajo). Para permitirlo: MIG010_PERMITIR_INSTANCIA=1.
 *
 * CASOS (15)
 *   A  sin indice, sin duplicados      -> crea el indice
 *   B  segunda ejecucion               -> rc 0, sin cambios
 *   C  indice correcto existente       -> rc 0, sin cambios
 *   D  duplicados exactos              -> rc 3, aborta, conserva datos
 *   E  homonimo no unico               -> rc 2, no lo elimina
 *   E2 homonimo compuesto              -> rc 2
 *   E3 homonimo con prefijo            -> rc 2
 *   F  collation real                  -> coincide con el servidor
 *   G  importacion repetida de schema  -> filas estables
 *   H  integridad antes/despues        -> huella identica
 *   I  tabla inexistente               -> rc 1
 *   J  dry-run sin indice              -> rc 4, no crea nada
 *   K  el .sql aplicado dos veces      -> idempotente
 *   L  marcador de propiedad ajeno     -> rc 1
 *   M  base real sin flag              -> rc 1, no conecta
 * ------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Solo CLI.\n");
    exit(1);
}

set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, "ERROR inesperado: " . $e->getMessage() . "\n");
    exit(1);
});

$host    = getenv('MIG010_HOST') ?: '127.0.0.1';
$puerto  = getenv('MIG010_PORT') ?: '3306';
$usuario = getenv('MIG010_USER');
$clave   = getenv('MIG010_PASS');

if ($usuario === false || $usuario === '') {
    fwrite(STDERR, "ERROR: falta MIG010_USER.\n");
    exit(1);
}
if ($clave === false) {
    fwrite(STDERR, "ERROR: falta MIG010_PASS (puede ser vacia, pero debe declararse).\n");
    exit(1);
}
if (!ctype_digit((string) $puerto)) {
    fwrite(STDERR, "ERROR: MIG010_PORT no valido.\n");
    exit(1);
}
$puerto = (int) $puerto;
if ($puerto === 3306 && getenv('MIG010_PERMITIR_INSTANCIA') !== '1') {
    fwrite(STDERR,
        "ABORTADO: el puerto 3306 parece la instancia de trabajo.\n"
      . "Usa una instancia aislada o define MIG010_PERMITIR_INSTANCIA=1.\n");
    exit(1);
}

$raiz        = dirname(__DIR__);
$migrador    = $raiz . '/tools/migrar-010-verificacion-bioclimatica.php';
// El .sql independiente ya no existe: el indice forma parte de schema.sql.
// Se conserva el statement unico para los casos de prueba (K).
$sqlMigracion = "ALTER TABLE variable_bioclimatica\n    ADD UNIQUE INDEX uq_variable_bioclimatica_nombre (nombre);";
$runId       = 'mig010-' . bin2hex(random_bytes(6));
$prefijo     = 'mig010_' . bin2hex(random_bytes(4));
$esperados   = 15;

$registros = [];
$creadas   = [];
$secuencia = 0;

// ---------------------------------------------------------------
// Utilidades
// ---------------------------------------------------------------
function pdoServidor(): PDO
{
    global $host, $puerto, $usuario, $clave;
    return new PDO(
        "mysql:host=$host;port=$puerto;charset=utf8mb4",
        $usuario,
        $clave,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
}

function reporte(string $id, string $descripcion, bool $ok, array $datos = []): void
{
    global $registros;
    $registros[$id] = ['id' => $id, 'descripcion' => $descripcion, 'ok' => $ok, 'datos' => $datos];
    echo ($ok ? "  [OK]   " : "  [FAIL] ") . "$id — $descripcion\n";
    foreach ($datos as $k => $v) {
        $texto = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
        echo "          $k: $texto\n";
    }
}

/** Ejecuta un caso aislando excepciones: un fallo no detiene la bateria. */
function caso(string $id, string $descripcion, callable $fn): void
{
    echo "\n--- Caso $id: $descripcion ---\n";
    try {
        $fn();
    } catch (Throwable $e) {
        reporte($id, "$descripcion [excepcion]", false, ['error' => $e->getMessage()]);
    }
}

/** Crea una base nueva (nunca reutiliza una existente) y su marcador. */
function nuevaBase(PDO $srv, string $sufijo): string
{
    global $prefijo, $runId, $creadas, $secuencia, $raiz;

    if (!preg_match('/^[a-z0-9]+$/', $sufijo)) {
        throw new RuntimeException("Sufijo de base invalido: $sufijo");
    }
    $secuencia++;
    $db = $prefijo . '_' . $secuencia . '_' . $sufijo . '_prueba';

    $existe = (int) $srv->query(
        "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = " . $srv->quote($db)
    )->fetchColumn();
    if ($existe > 0) {
        throw new RuntimeException("La base '$db' ya existe: no se reutiliza ni se borra.");
    }

    $srv->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $creadas[$db] = true;

    // Marcador inmediato: si falla la carga del esquema, la limpieza aun
    // puede demostrar propiedad.
    $srv->exec("USE `$db`");
    $srv->exec("CREATE TABLE `_mig010_ownership` (exec_id VARCHAR(64) NOT NULL, creada DATETIME NOT NULL) ENGINE=InnoDB");
    $srv->prepare("INSERT INTO `_mig010_ownership` (exec_id, creada) VALUES (?, NOW())")->execute([$runId]);

    cargarSchema($srv, $db);
    return $db;
}

function cargarSchema(PDO $pdo, string $db): void
{
    global $raiz;
    $sql = file_get_contents($raiz . '/database/schema.sql');
    if ($sql === false) {
        throw new RuntimeException('No se puede leer database/schema.sql');
    }
    // Solo se permiten sentencias de definicion/carga: nunca DROP ni SELECT.
    foreach (sentenciasSql($sql) as $sentencia) {
        if (!preg_match('/^\s*(CREATE|INSERT|ALTER|SET)\b/i', $sentencia)) {
            throw new RuntimeException('Sentencia no permitida en schema.sql: ' . substr($sentencia, 0, 60));
        }
        $pdo->exec($sentencia);
    }
    $pdo->exec("USE `$db`");
}

/** Mismo criterio de division que tools/migrar-pruebas.php. */
function sentenciasSql(string $sql): array
{
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);
    $sql = preg_replace('#/\*.*?\*/#s', '', $sql);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $out = [];
    foreach (preg_split('/;\s*\n/', (string) $sql) as $trozo) {
        $t = trim(rtrim(trim($trozo), ';'));
        if ($t !== '') {
            $out[] = $t;
        }
    }
    return $out;
}

function indices(PDO $pdo, string $db): array
{
    $stmt = $pdo->prepare(
        "SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART,
                INDEX_TYPE, INDEX_COMMENT
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'variable_bioclimatica'
         ORDER BY INDEX_NAME, SEQ_IN_INDEX"
    );
    $stmt->execute([$db]);
    $out = [];
    foreach ($stmt->fetchAll() as $f) {
        $out[strtolower($f['INDEX_NAME'])][] = $f;
    }
    return $out;
}

function describir(?array $def): string
{
    if ($def === null) {
        return '(no existe)';
    }
    $partes = [];
    foreach ($def as $c) {
        $partes[] = $c['COLUMN_NAME'] . ($c['SUB_PART'] !== null ? "({$c['SUB_PART']})" : '');
    }
    $unico = ((int) $def[0]['NON_UNIQUE'] === 0) ? 'UNIQUE' : 'no unico';
    return "$unico (" . implode(', ', $partes) . "), comentario="
        . ($def[0]['INDEX_COMMENT'] !== '' ? $def[0]['INDEX_COMMENT'] : '(vacio)');
}

function esCorrecto(?array $def): bool
{
    return $def !== null
        && count($def) === 1
        && (int) $def[0]['NON_UNIQUE'] === 0
        && $def[0]['COLUMN_NAME'] === 'nombre'
        && $def[0]['SUB_PART'] === null
        && strtoupper((string) $def[0]['INDEX_TYPE']) === 'BTREE';
}

function contar(PDO $pdo, string $db, string $tabla): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM `$db`.`$tabla`")->fetchColumn();
}

function huella(PDO $pdo, string $db, string $tabla): string
{
    $filas = $pdo->query("SELECT * FROM `$db`.`$tabla` ORDER BY 1")->fetchAll();
    $json = json_encode($filas, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('No se pudo serializar la tabla para la huella.');
    }
    return hash('sha256', $json);
}

function soltarIndice(PDO $pdo, string $db): void
{
    $def = indices($pdo, $db)['uq_variable_bioclimatica_nombre'] ?? null;
    if ($def !== null) {
        $pdo->exec("DROP INDEX uq_variable_bioclimatica_nombre ON `$db`.variable_bioclimatica");
    }
}

/**
 * Ejecuta el migrador real como subproceso con entorno minimo.
 * @return array{0:int,1:string,2:string} [rc, salida, error]
 */
function correrMigrador(string $db, array $args = [], ?string $owner = null): array
{
    global $migrador, $host, $puerto, $usuario, $clave, $runId;

    $cmd = array_merge([PHP_BINARY, $migrador], $args);
    $entorno = [
        'PATH'       => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        'HOME'       => getenv('HOME') ?: '',
        'MIG010_HOST'  => $host,
        'MIG010_PORT'  => (string) $puerto,
        'MIG010_USER'  => $usuario,
        'MIG010_PASS'  => $clave,
        'MIG010_DB'    => $db,
        'MIG010_OWNER' => $owner ?? $runId,
    ];

    $proceso = proc_open($cmd, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, $entorno);

    if (!is_resource($proceso)) {
        return [255, '', 'No se pudo iniciar el migrador.'];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $salida = '';
    $error  = '';
    $inicio = microtime(true);
    while (true) {
        $leer = [$pipes[1], $pipes[2]];
        $escribir = null;
        $excepciones = null;
        $listos = @stream_select($leer, $escribir, $excepciones, 1);
        if ($listos > 0) {
            foreach ($leer as $stream) {
                $dato = fread($stream, 8192);
                if ($dato !== false && $dato !== '') {
                    if ($stream === $pipes[1]) {
                        $salida .= $dato;
                    } else {
                        $error .= $dato;
                    }
                }
            }
        }
        if (feof($pipes[1]) && feof($pipes[2])) {
            break;
        }
        if (microtime(true) - $inicio > 90) {
            proc_terminate($proceso, 9);
            $error .= "\n[timeout de 90s]";
            break;
        }
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($proceso);

    return [$rc, $salida, $error];
}

// ---------------------------------------------------------------
// Preparacion
// ---------------------------------------------------------------
echo "=== Verificacion migracion 010 ===\n";
echo "Instancia: $host:$puerto\n";
echo "Ejecucion: $runId\n\n";

$srv = pdoServidor();
$version = $srv->query("SELECT VERSION() AS v, @@version_comment AS c")->fetch();
echo "Servidor: {$version['v']} ({$version['c']})\n";

$db = null;

// ---------------------------------------------------------------
// Casos
// ---------------------------------------------------------------
caso('A', 'sin indice y sin duplicados -> crea el indice', function () use ($srv, &$db): void {
    $db = nuevaBase($srv, 'a');
    soltarIndice($srv, $db);
    $filas = contar($srv, $db, 'variable_bioclimatica');
    $huellaAntes = huella($srv, $db, 'variable_bioclimatica');

    [$rc] = correrMigrador($db, ['--aplicar']);
    $idx = indices($srv, $db)['uq_variable_bioclimatica_nombre'] ?? null;

    reporte('A', 'crea el indice UNIQUE correcto', $rc === 0 && esCorrecto($idx), [
        'rc' => $rc,
        'indice' => describir($idx),
        'filas' => contar($srv, $db, 'variable_bioclimatica') . " (antes $filas)",
        'huella' => huella($srv, $db, 'variable_bioclimatica') === $huellaAntes ? 'identica' : 'CAMBIADA',
    ]);
});

caso('B', 'segunda ejecucion -> rc 0 y esquema sin cambios', function () use ($srv, &$db): void {
    $antes = $srv->query("SHOW CREATE TABLE `$db`.variable_bioclimatica")->fetch();
    [$rc] = correrMigrador($db, ['--aplicar']);
    $despues = $srv->query("SHOW CREATE TABLE `$db`.variable_bioclimatica")->fetch();
    $igual = $antes['Create Table'] === $despues['Create Table'];

    reporte('B', 'rc 0 y esquema sin cambios', $rc === 0 && $igual, [
        'rc' => $rc,
        'show_create_identico' => $igual ? 'si' : 'NO',
    ]);
});

caso('C', 'indice correcto existente -> rc 0 y sin cambios', function () use ($srv): void {
    $db = nuevaBase($srv, 'c');
    $antes = $srv->query("SHOW CREATE TABLE `$db`.variable_bioclimatica")->fetch();
    [$rc] = correrMigrador($db, ['--aplicar']);
    $despues = $srv->query("SHOW CREATE TABLE `$db`.variable_bioclimatica")->fetch();

    reporte('C', 'reconoce el indice y no lo modifica', $rc === 0 && $antes['Create Table'] === $despues['Create Table'], [
        'rc' => $rc,
        'show_create_identico' => $antes['Create Table'] === $despues['Create Table'] ? 'si' : 'NO',
    ]);
});

caso('D', 'duplicados exactos -> rc 3, conserva datos y lista IDs', function () use ($srv): void {
    $db = nuevaBase($srv, 'd');
    soltarIndice($srv, $db);
    $srv->exec("INSERT INTO `$db`.variable_bioclimatica (nombre, unidad_medida) VALUES ('dup_exacto','u1'),('dup_exacto','u2')");

    $ids = $srv->query("SELECT id_variable FROM `$db`.variable_bioclimatica WHERE nombre='dup_exacto' ORDER BY id_variable")
        ->fetchAll(PDO::FETCH_COLUMN);
    $filasAntes = contar($srv, $db, 'variable_bioclimatica');
    $huellaAntes = huella($srv, $db, 'variable_bioclimatica');

    [$rc, $out, $err] = correrMigrador($db, ['--aplicar']);
    $filasDespues = contar($srv, $db, 'variable_bioclimatica');
    $huellaDespues = huella($srv, $db, 'variable_bioclimatica');
    $idx = indices($srv, $db)['uq_variable_bioclimatica_nombre'] ?? null;

    $lineaIds = '';
    if (preg_match('/NOMBRE:\s*\'dup_exacto\'.*?IDs:\s*(.+)$/m', $out . $err, $m)) {
        $lineaIds = trim($m[1]);
    }
    $idsListados = count($ids) > 0;
    foreach ($ids as $id) {
        if (!preg_match('/\b' . preg_quote((string) $id, '/') . '\b/', $lineaIds)) {
            $idsListados = false;
        }
    }

    reporte(
        'D',
        'aborta (rc 3), conserva filas y lista los IDs',
        $rc === 3 && !esCorrecto($idx) && $filasAntes === $filasDespues
            && $huellaAntes === $huellaDespues && $idsListados,
        [
            'rc' => $rc,
            'filas' => "$filasAntes -> $filasDespues",
            'huella' => $huellaAntes === $huellaDespues ? 'identica' : 'CAMBIADA',
            'ids_esperados' => implode(',', $ids),
            'linea_ids_salida' => $lineaIds !== '' ? $lineaIds : '(no encontrada)',
        ]
    );
});

caso('E', 'homonimo no unico -> rc 2 y no lo elimina', function () use ($srv): void {
    $db = nuevaBase($srv, 'e');
    soltarIndice($srv, $db);
    $srv->exec("CREATE INDEX uq_variable_bioclimatica_nombre ON `$db`.variable_bioclimatica (nombre) COMMENT 'pre'");
    $antes = describir(indices($srv, $db)['uq_variable_bioclimatica_nombre'] ?? null);

    [$rc] = correrMigrador($db, ['--aplicar']);
    $despues = describir(indices($srv, $db)['uq_variable_bioclimatica_nombre'] ?? null);

    reporte('E', 'aborta (rc 2) y conserva el indice original', $rc === 2 && $antes === $despues && str_contains($despues, 'pre'), [
        'rc' => $rc,
        'indice_antes' => $antes,
        'indice_despues' => $despues,
    ]);
});

caso('E2', 'homonimo compuesto (nombre, id_variable) -> rc 2', function () use ($srv): void {
    $db = nuevaBase($srv, 'e2');
    soltarIndice($srv, $db);
    $srv->exec("CREATE UNIQUE INDEX uq_variable_bioclimatica_nombre ON `$db`.variable_bioclimatica (nombre, id_variable) COMMENT 'pre'");
    $antes = describir(indices($srv, $db)['uq_variable_bioclimatica_nombre'] ?? null);

    [$rc] = correrMigrador($db, ['--aplicar']);
    $despues = describir(indices($srv, $db)['uq_variable_bioclimatica_nombre'] ?? null);

    reporte('E2', 'rechaza el indice compuesto que empieza por nombre', $rc === 2 && $antes === $despues && str_contains($despues, 'pre'), [
        'rc' => $rc,
        'indice_antes' => $antes,
        'indice_despues' => $despues,
    ]);
});

caso('E3', 'homonimo con prefijo -> rc 2', function () use ($srv): void {
    $db = nuevaBase($srv, 'e3');
    soltarIndice($srv, $db);
    $srv->exec("DELETE FROM `$db`.variable_bioclimatica");
    $srv->exec("CREATE UNIQUE INDEX uq_variable_bioclimatica_nombre ON `$db`.variable_bioclimatica (nombre(10)) COMMENT 'pre'");
    $antes = describir(indices($srv, $db)['uq_variable_bioclimatica_nombre'] ?? null);

    [$rc] = correrMigrador($db, ['--aplicar']);
    $despues = describir(indices($srv, $db)['uq_variable_bioclimatica_nombre'] ?? null);

    reporte('E3', 'rechaza el UNIQUE por prefijo', $rc === 2 && $antes === $despues && str_contains($despues, 'pre'), [
        'rc' => $rc,
        'indice_antes' => $antes,
        'indice_despues' => $despues,
    ]);
});

caso('F', 'collation real: acentos y mayusculas', function () use ($srv): void {
    $db = nuevaBase($srv, 'f');
    $coll = (string) $srv->query(
        "SELECT COLLATION_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = " . $srv->quote($db) . " AND TABLE_NAME='variable_bioclimatica'
           AND COLUMN_NAME='nombre'"
    )->fetchColumn();

    if (!preg_match('/^[A-Za-z0-9_]+$/', $coll)) {
        throw new RuntimeException("Collation invalida: '$coll'");
    }

    $stmt = $srv->prepare(
        "SELECT (_utf8mb4'Presión' COLLATE `$coll` = _utf8mb4'presion' COLLATE `$coll`) AS acentos,
                (_utf8mb4'CO2' COLLATE `$coll` = _utf8mb4'co2' COLLATE `$coll`) AS mayusculas"
    );
    $stmt->execute();
    $cmp = $stmt->fetch();
    $acentos = (int) $cmp['acentos'] === 1;
    $mayusculas = (int) $cmp['mayusculas'] === 1;

    soltarIndice($srv, $db);
    $srv->exec("INSERT INTO `$db`.variable_bioclimatica (nombre, unidad_medida) VALUES ('Presión','x'),('presion','y')");
    [$rc] = correrMigrador($db, ['--aplicar']);
    $idx = indices($srv, $db)['uq_variable_bioclimatica_nombre'] ?? null;

    $rcEsperado = $acentos ? 3 : 0;
    $indiceEsperado = !$acentos;
    // Para utf8mb4_unicode_ci la expectativa es conocida, no solo derivada.
    $coincideConocido = $coll !== 'utf8mb4_unicode_ci' || ($acentos && $mayusculas);

    reporte(
        'F',
        'coincide con la collation del servidor',
        $rc === $rcEsperado && esCorrecto($idx) === $indiceEsperado && $coincideConocido,
        [
            'collation' => $coll,
            'Presión=presion' => $acentos ? 'si' : 'no',
            'CO2=co2' => $mayusculas ? 'si' : 'no',
            'rc_obtenido' => $rc,
            'rc_esperado' => $rcEsperado,
            'indice_creado' => esCorrecto($idx) ? 'si' : 'no',
        ]
    );
});

caso('G', 'importacion repetida de schema.sql -> filas estables', function () use ($srv): void {
    $db = nuevaBase($srv, 'g');
    $base = contar($srv, $db, 'variable_bioclimatica');
    cargarSchema($srv, $db);
    $tras1 = contar($srv, $db, 'variable_bioclimatica');
    cargarSchema($srv, $db);
    $tras2 = contar($srv, $db, 'variable_bioclimatica');
    $idx = indices($srv, $db)['uq_variable_bioclimatica_nombre'] ?? null;

    reporte('G', 'tres importaciones mantienen el catalogo', $base === $tras1 && $tras1 === $tras2 && esCorrecto($idx), [
        'filas' => "$base / $tras1 / $tras2",
        'indice' => describir($idx),
    ]);
});

caso('H', 'integridad antes/despues con datos adicionales', function () use ($srv): void {
    $db = nuevaBase($srv, 'h');
    soltarIndice($srv, $db);
    $srv->exec("INSERT INTO `$db`.variable_bioclimatica (nombre, unidad_medida) VALUES ('variable_extra_h','u')");
    $filas = contar($srv, $db, 'variable_bioclimatica');
    $huellaAntes = huella($srv, $db, 'variable_bioclimatica');

    [$rc] = correrMigrador($db, ['--aplicar']);
    $huellaDespues = huella($srv, $db, 'variable_bioclimatica');

    reporte('H', 'huella identica y filas conservadas', $rc === 0 && $huellaAntes === $huellaDespues
        && contar($srv, $db, 'variable_bioclimatica') === $filas, [
        'rc' => $rc,
        'huella_antes' => substr($huellaAntes, 0, 16) . '...',
        'huella_despues' => substr($huellaDespues, 0, 16) . '...',
        'filas' => $filas,
    ]);
});

caso('I', 'tabla inexistente -> rc 1', function () use ($srv): void {
    $db = nuevaBase($srv, 'i');
    $srv->exec("SET FOREIGN_KEY_CHECKS=0");
    $srv->exec("DROP TABLE `$db`.variable_bioclimatica");
    $srv->exec("SET FOREIGN_KEY_CHECKS=1");

    [$rc, $out, $err] = correrMigrador($db, ['--aplicar']);
    $mensaje = $err . $out;

    reporte('I', 'aborta con mensaje exacto', $rc === 1
        && str_contains($mensaje, "no existe la tabla 'variable_bioclimatica'"), [
        'rc' => $rc,
        'mensaje' => trim(str_replace("\n", ' ', $err)),
    ]);
});

caso('J', 'dry-run sin indice -> rc 4 y no crea nada', function () use ($srv): void {
    $db = nuevaBase($srv, 'j');
    soltarIndice($srv, $db);

    [$rc] = correrMigrador($db, ['--info']);
    $idx = indices($srv, $db)['uq_variable_bioclimatica_nombre'] ?? null;

    reporte('J', 'rc 4 y sin indice creado', $rc === 4 && $idx === null, [
        'rc' => $rc,
        'indice_creado' => $idx === null ? 'no' : 'SI (incorrecto)',
    ]);
});

caso('K', 'el .sql aplicado dos veces -> idempotente', function () use ($srv, $sqlMigracion): void {
    $db = nuevaBase($srv, 'k');
    soltarIndice($srv, $db);

    $sql = $sqlMigracion;
    $sentencias = sentenciasSql($sql);
    if (count($sentencias) !== 1) {
        throw new RuntimeException('Se esperaba exactamente un statement en el .sql, hay ' . count($sentencias));
    }
    $unica = $sentencias[0];

    $srv->exec($unica);
    $idx1 = indices($srv, $db)['uq_variable_bioclimatica_nombre'] ?? null;

    $segundaVez = 'sin ejecutar';
    try {
        $srv->exec($unica);
        $segundaVez = 'sin error';
    } catch (PDOException $e) {
        $segundaVez = str_contains($e->getMessage(), 'Duplicate key name')
            || str_contains($e->getMessage(), '1061')
            ? 'Duplicate key name (idempotente)'
            : $e->getMessage();
    }
    $idx2 = indices($srv, $db)['uq_variable_bioclimatica_nombre'] ?? null;

    reporte('K', 'un statement y segunda ejecucion sin cambios', esCorrecto($idx1)
        && str_contains($segundaVez, 'Duplicate key name') && describir($idx1) === describir($idx2), [
        'statements' => count($sentencias),
        'primera_ejecucion' => describir($idx1),
        'segunda_ejecucion' => $segundaVez,
    ]);
});

caso('L', 'marcador de propiedad ajeno -> rc 1', function () use ($srv): void {
    $db = nuevaBase($srv, 'l');
    soltarIndice($srv, $db);

    [$rc] = correrMigrador($db, ['--aplicar'], 'otra-ejecucion');
    $idx = indices($srv, $db)['uq_variable_bioclimatica_nombre'] ?? null;

    reporte('L', 'aborta sin aplicar DDL', $rc === 1 && $idx === null, [
        'rc' => $rc,
        'indice_creado' => $idx === null ? 'no' : 'SI (incorrecto)',
    ]);
});

caso('M', 'base real sin flag -> rc 1 antes de conectar', function (): void {
    [$rc, $out, $err] = correrMigrador('mig010_guard_real', ['--aplicar']);

    reporte('M', 'aborta antes de conectar', $rc === 1
        && str_contains($err . $out, 'no parece base de pruebas'), [
        'rc' => $rc,
        'mensaje' => trim(str_replace("\n", ' ', $err)),
    ]);
});

// ---------------------------------------------------------------
// Limpieza: solo bases creadas en esta ejecucion
// ---------------------------------------------------------------
echo "\n=== Limpieza ===\n";
foreach (array_keys($creadas) as $baseCreada) {
    try {
        $existe = (int) $srv->query(
            "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = " . $srv->quote($baseCreada)
        )->fetchColumn();
        if ($existe === 0) {
            continue;
        }
        $srv->exec("USE `$baseCreada`");
        $tieneMarcador = (int) $srv->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = " . $srv->quote($baseCreada) . " AND TABLE_NAME='_mig010_ownership'"
        )->fetchColumn() > 0;

        if ($tieneMarcador) {
            $marcadores = $srv->query("SELECT exec_id FROM _mig010_ownership")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array($runId, $marcadores, true)) {
                echo "  [CONSERVADA] $baseCreada (marcador ajeno)\n";
                continue;
            }
        }
        // Si no hay marcador pero la base la creo esta ejecucion (se comprobo
        // que no existia antes), sigue siendo nuestra.
        $srv->exec("DROP DATABASE `$baseCreada`");
        echo "  [ELIMINADA] $baseCreada\n";
    } catch (Throwable $e) {
        echo "  [NO ELIMINADA] $baseCreada: " . $e->getMessage() . "\n";
    }
}

// ---------------------------------------------------------------
// Resumen
// ---------------------------------------------------------------
$ok = count(array_filter($registros, static fn (array $r): bool => $r['ok']));
$total = count($registros);
echo "\n=== Resumen: $ok / $esperados casos OK ($total ejecutados) ===\n";
foreach ($registros as $r) {
    if (!$r['ok']) {
        echo "  FALLIDO: {$r['id']} — {$r['descripcion']}\n";
    }
}
exit(($total === $esperados && $ok === $esperados) ? 0 : 1);
