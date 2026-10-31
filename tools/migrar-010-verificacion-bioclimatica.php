<?php
declare(strict_types=1);

/**
 * tools/migrar-010-verificacion-bioclimatica.php
 * ------------------------------------------------------------------
 * MIGRACION 010: indice unico en variable_bioclimatica.nombre
 *
 * Migrador controlado por PHP + PDO. Sin DELIMITER, SIGNAL,
 * PREPARE/EXECUTE ni tablas inexistentes.
 *
 * GARANTIAS
 *   - Modo por defecto: SOLO LECTURA. El DDL exige --aplicar.
 *   - --info con --aplicar es contradictorio y se rechaza.
 *   - Nunca elimina, fusiona ni reescribe registros.
 *   - Nunca elimina un indice existente.
 *   - Duplicados detectados EN SQL, con la collation real, sin NULL.
 *   - Credenciales solo por entorno (nunca por argv).
 *   - Base real: exige --aplicar + --permitir-base-real.
 *   - Marcador de propiedad verificado solo al aplicar.
 *
 * USO
 *   MIG010_HOST=127.0.0.1 MIG010_PORT=3310 \
 *   MIG010_USER=... MIG010_PASS=... MIG010_DB=mi_base_prueba \
 *   php tools/migrar-010-verificacion-bioclimatica.php [--aplicar]
 *
 * OPCIONES
 *   --info                Solo lectura (por defecto).
 *   --aplicar             Ejecuta el DDL si todas las comprobaciones pasan.
 *   --permitir-base-real  Obligatorio para aplicar sobre una base cuyo
 *                         nombre no termina en _prueba o _test.
 *   --aceptar-nullable    Permite aplicar si la columna admite NULL.
 *
 * CODIGOS DE SALIDA
 *   0  correcto o sin cambios necesarios
 *   1  error de entorno, conexion, estructura o politica
 *   2  abortado: indice homonimo con definicion incorrecta
 *   3  abortado: existen duplicados
 *   4  dry-run: se requiere el indice pero no se aplico
 * ------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este migrador solo se ejecuta por linea de comandos.\n");
    exit(1);
}

set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, "ERROR inesperado: " . $e->getMessage() . "\n");
    exit(1);
});

// ---------------------------------------------------------------
// Opciones: lista blanca estricta
// ---------------------------------------------------------------
$permitidas = ['--info', '--aplicar', '--permitir-base-real', '--aceptar-nullable'];
foreach (array_slice($argv, 1) as $arg) {
    if (!in_array($arg, $permitidas, true)) {
        fwrite(STDERR, "ERROR: opcion desconocida '$arg'.\n");
        exit(1);
    }
}

$modoAplicar      = in_array('--aplicar', $argv, true);
$modoInfo         = in_array('--info', $argv, true);
$permitirReal     = in_array('--permitir-base-real', $argv, true);
$aceptarNullable  = in_array('--aceptar-nullable', $argv, true);

if ($modoAplicar && $modoInfo) {
    fwrite(STDERR, "ERROR: --info y --aplicar son contradictorios. Elige uno.\n");
    exit(1);
}

$host    = getenv('MIG010_HOST') ?: '127.0.0.1';
$puerto  = getenv('MIG010_PORT') ?: '3306';
$usuario = getenv('MIG010_USER');
$clave   = getenv('MIG010_PASS');
$base    = getenv('MIG010_DB');
$owner   = getenv('MIG010_OWNER');

if ($usuario === false || $usuario === '') {
    fwrite(STDERR, "ERROR: falta MIG010_USER.\n");
    exit(1);
}
if ($clave === false) {
    fwrite(STDERR, "ERROR: falta MIG010_PASS (puede ser vacia, pero debe declararse).\n");
    exit(1);
}
if ($base === false || $base === '') {
    fwrite(STDERR, "ERROR: falta MIG010_DB.\n");
    exit(1);
}
if (!preg_match('/^[A-Za-z0-9._-]+$/', $host) || str_contains($host, ';')) {
    fwrite(STDERR, "ERROR: MIG010_HOST no valido.\n");
    exit(1);
}
if (!ctype_digit((string) $puerto) || (int) $puerto < 1 || (int) $puerto > 65535) {
    fwrite(STDERR, "ERROR: MIG010_PORT no valido.\n");
    exit(1);
}
$puerto = (int) $puerto;

// El nombre de base se interpola entre backticks: se valida antes.
if (!preg_match('/^[A-Za-z0-9_]+$/', $base)) {
    fwrite(STDERR, "ERROR: nombre de base no valido.\n");
    exit(1);
}

$esBasePrueba = (bool) preg_match('/(_prueba|_test)$/i', $base);
if ($modoAplicar && !$esBasePrueba && !$permitirReal) {
    fwrite(STDERR,
        "ABORTADO: '$base' no parece base de pruebas.\n"
      . "Para aplicar sobre una base real se requiere ademas --permitir-base-real.\n");
    exit(1);
}

echo "=== Migracion 010: unicidad de variable_bioclimatica.nombre ===\n";
echo "Instancia: $host:$puerto   Base: $base\n";
echo "Modo: " . ($modoAplicar ? 'APLICAR (DDL habilitado)' : 'INFO (solo lectura)') . "\n";
if (!$esBasePrueba) {
    echo "AVISO: la base no termina en _prueba/_test.\n";
}
echo "\n";

// ---------------------------------------------------------------
// Conexion
// ---------------------------------------------------------------
$pdo = new PDO(
    "mysql:host=$host;port=$puerto;charset=utf8mb4",
    $usuario,
    $clave,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);

// ---------------------------------------------------------------
// 1. Base de destino
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT SCHEMA_NAME, DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
     FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?"
);
$stmt->execute([$base]);
$esquema = $stmt->fetch();
if (!$esquema) {
    fwrite(STDERR, "ERROR: la base '$base' no existe o no es visible.\n");
    exit(1);
}
echo "[OK] Base: {$esquema['SCHEMA_NAME']} "
   . "(charset={$esquema['DEFAULT_CHARACTER_SET_NAME']}, "
   . "collation={$esquema['DEFAULT_COLLATION_NAME']})\n";

$pdo->exec("USE `$base`");

// ---------------------------------------------------------------
// 2. Marcador de propiedad (solo al aplicar)
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = '_mig010_ownership'"
);
$stmt->execute([$base]);
if ((int) $stmt->fetchColumn() > 0) {
    $marcadores = $pdo->query("SELECT exec_id FROM _mig010_ownership")->fetchAll(PDO::FETCH_COLUMN);
    $propia = ($owner !== false && $owner !== '' && in_array($owner, $marcadores, true));
    if ($modoAplicar && !$propia) {
        fwrite(STDERR,
            "ABORTADO: la base tiene marcador de propiedad y no coincide con MIG010_OWNER.\n"
          . "No se aplica DDL sobre una base que pertenece a otra ejecucion.\n");
        exit(1);
    }
    echo $propia
        ? "[OK] Marcador de propiedad verificado.\n"
        : "[AVISO] Marcador de propiedad ajeno; solo se permite lectura.\n";
}

// ---------------------------------------------------------------
// 3. Tabla y columna
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'variable_bioclimatica'"
);
$stmt->execute([$base]);
if ((int) $stmt->fetchColumn() === 0) {
    fwrite(STDERR, "ERROR: no existe la tabla 'variable_bioclimatica' en '$base'.\n");
    exit(1);
}

$stmt = $pdo->prepare(
    "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLLATION_NAME, CHARACTER_SET_NAME
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'variable_bioclimatica' AND COLUMN_NAME = 'nombre'"
);
$stmt->execute([$base]);
$columna = $stmt->fetch();
if (!$columna) {
    fwrite(STDERR, "ERROR: la tabla existe pero no tiene la columna 'nombre'.\n");
    exit(1);
}
if ($columna['COLLATION_NAME'] === null) {
    fwrite(STDERR, "ERROR: 'nombre' no es una columna de texto (collation NULL).\n");
    exit(1);
}
$collation = (string) $columna['COLLATION_NAME'];
if (!preg_match('/^[A-Za-z0-9_]+$/', $collation)) {
    fwrite(STDERR, "ERROR: collation inesperada en 'nombre'.\n");
    exit(1);
}

echo "[OK] Columna 'nombre': tipo={$columna['COLUMN_TYPE']}, "
   . "nullable={$columna['IS_NULLABLE']}, collation=$collation\n";

if (str_ends_with($collation, '_bin') || str_ends_with($collation, '_cs')) {
    echo "[AVISO] Collation sensible a mayusculas/acentos: el UNIQUE no unificara variantes.\n";
}

$nullable = $columna['IS_NULLABLE'] === 'YES';
if ($nullable) {
    echo "[AVISO] 'nombre' admite NULL: un UNIQUE permite multiples NULL.\n";
    if ($modoAplicar && !$aceptarNullable) {
        fwrite(STDERR, "Se requiere --aceptar-nullable para aplicar el indice.\n");
        exit(1);
    }
}

// ---------------------------------------------------------------
// 4. Indices: definicion completa agrupada por nombre
//    (los nombres de indice no distinguen mayusculas)
// ---------------------------------------------------------------
$stmtIdx = $pdo->prepare(
    "SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART,
            INDEX_TYPE, INDEX_COMMENT
     FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'variable_bioclimatica'
     ORDER BY INDEX_NAME, SEQ_IN_INDEX"
);
$stmtIdx->execute([$base]);
$filas = $stmtIdx->fetchAll();

$indices = [];
$nombres = [];
foreach ($filas as $fila) {
    $clave = strtolower($fila['INDEX_NAME']);
    $indices[$clave][] = $fila;
    $nombres[$clave] = $fila['INDEX_NAME'];
}

echo "\n[INDICES EXISTENTES]\n";
foreach ($indices as $clave => $columnas) {
    echo "  - {$nombres[$clave]}: " . describirIndice($columnas) . "\n";
}

/** UNIQUE, exactamente una columna 'nombre', sin prefijo y BTREE. */
function indiceCorrecto(?array $definicion): bool
{
    if ($definicion === null || count($definicion) !== 1) {
        return false;
    }
    $c = $definicion[0];
    return (int) $c['NON_UNIQUE'] === 0
        && $c['COLUMN_NAME'] === 'nombre'
        && $c['SUB_PART'] === null
        && strtoupper((string) $c['INDEX_TYPE']) === 'BTREE';
}

/** Texto legible de una definicion de indice. */
function describirIndice(?array $definicion): string
{
    if ($definicion === null) {
        return '(no existe)';
    }
    $partes = [];
    foreach ($definicion as $c) {
        $partes[] = $c['COLUMN_NAME'] . ($c['SUB_PART'] !== null ? "({$c['SUB_PART']})" : '');
    }
    $unico = ((int) $definicion[0]['NON_UNIQUE'] === 0) ? 'UNIQUE' : 'no unico';
    return "$unico (" . implode(', ', $partes) . "), tipo={$definicion[0]['INDEX_TYPE']}";
}

$objetivo = 'uq_variable_bioclimatica_nombre';
$existeObjetivo = isset($indices[$objetivo]);
$definicionObjetivo = $indices[$objetivo] ?? null;
$objetivoCorrecto = indiceCorrecto($definicionObjetivo);

// Indice equivalente con otro nombre: no se duplica.
$equivalente = null;
foreach ($indices as $clave => $def) {
    if ($clave !== $objetivo && indiceCorrecto($def)) {
        $equivalente = $nombres[$clave];
        break;
    }
}

// ---------------------------------------------------------------
// 5. Duplicados EN SQL (excluye NULL: no colisionan en UNIQUE)
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT nombre, COUNT(*) AS total
     FROM variable_bioclimatica
     WHERE nombre IS NOT NULL
     GROUP BY nombre
     HAVING COUNT(*) > 1
     ORDER BY nombre"
);
$stmt->execute();
$grupos = $stmt->fetchAll();

// Proyeccion segura para listar IDs.
$tieneId = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'variable_bioclimatica'
       AND COLUMN_NAME = 'id_variable'"
)->fetchColumn() > 0;
$detalle = $pdo->prepare(
    "SELECT " . ($tieneId ? 'id_variable, nombre' : 'nombre')
    . " FROM variable_bioclimatica WHERE nombre = ? ORDER BY 1"
);

echo "\n[DUPLICADOS] (collation real: $collation; NULL excluidos)\n";
if (!$grupos) {
    echo "  Ninguno.\n";
} else {
    foreach ($grupos as $grupo) {
        $detalle->execute([$grupo['nombre']]);
        $filasGrupo = $detalle->fetchAll();
        $ids = [];
        foreach ($filasGrupo as $fila) {
            $ids[] = $fila['id_variable'] ?? '(sin id_variable)';
        }
        echo "  NOMBRE: '{$grupo['nombre']}' -> total {$grupo['total']} -> IDs: "
           . implode(', ', $ids) . "\n";
    }
}
$hayDuplicados = $grupos !== [];

// ---------------------------------------------------------------
// 6. Decision previa al DDL
// ---------------------------------------------------------------
echo "\n=== DECISION ===\n";

if ($existeObjetivo && !$objetivoCorrecto) {
    echo "  Accion: ABORTAR\n";
    echo "  El indice '{$nombres[$objetivo]}' existe con definicion INCORRECTA: "
       . describirIndice($definicionObjetivo) . ".\n";
    echo "  No se elimina ni se reemplaza automaticamente. Requiere intervencion manual.\n";
    exit(2);
}

if ($hayDuplicados) {
    echo "  Accion: ABORTAR\n";
    echo "  Existen nombres duplicados segun la collation $collation.\n";
    echo "  No se crea el indice. No se elimina ni fusiona ningun registro.\n";
    echo "  Resolucion manual: revisar cada grupo, decidir el nombre canonico y\n";
    echo "  corregir con UPDATE/DELETE explicitos y auditados.\n";
    exit(3);
}

if ($objetivoCorrecto) {
    echo "  Accion: SIN CAMBIOS\n";
    echo "  El indice '{$nombres[$objetivo]}' ya existe con definicion correcta.\n";
    exit(0);
}

if ($equivalente !== null) {
    echo "  Accion: SIN CAMBIOS\n";
    echo "  Ya existe un UNIQUE equivalente sobre 'nombre' con otro nombre: '$equivalente'.\n";
    echo "  No se crea un indice redundante. Revisar si se desea el nombre canonico.\n";
    exit(0);
}

echo "  Accion: CREAR INDICE\n";
echo "  No existe '$objetivo' ni un equivalente, y no hay duplicados.\n";

if (!$modoAplicar) {
    echo "\n[INFO] No se aplico nada. Repetir con --aplicar para crear el indice.\n";
    exit(4);
}

// ---------------------------------------------------------------
// 7. Aplicar el DDL y medir el efecto sobre los datos
// ---------------------------------------------------------------
$contar = static fn (PDO $p): string =>
    (string) $p->query("SELECT COUNT(*) FROM variable_bioclimatica")->fetchColumn();
$huella = static function (PDO $p): string {
    $filas = $p->query("SELECT * FROM variable_bioclimatica ORDER BY 1")->fetchAll();
    $json = json_encode($filas, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('No se pudo serializar la tabla para la huella.');
    }
    return hash('sha256', $json);
};

$filasAntes = $contar($pdo);
$huellaAntes = $huella($pdo);

echo "\n[DDL] ALTER TABLE variable_bioclimatica ADD UNIQUE INDEX $objetivo (nombre)\n";
$pdo->exec("SET SESSION lock_wait_timeout = 30");

try {
    $pdo->exec("ALTER TABLE variable_bioclimatica ADD UNIQUE INDEX $objetivo (nombre)");
} catch (PDOException $e) {
    fwrite(STDERR, "ERROR al crear el indice: " . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------
// 8. Reverificar estructura y datos
// ---------------------------------------------------------------
$stmtIdx->execute([$base]);
$final = [];
foreach ($stmtIdx->fetchAll() as $fila) {
    $final[strtolower($fila['INDEX_NAME'])][] = $fila;
}

if (!indiceCorrecto($final[$objetivo] ?? null)) {
    fwrite(STDERR, "ERROR: el indice creado no tiene la definicion esperada.\n");
    exit(1);
}
echo "[OK] Indice verificado: UNIQUE, una columna 'nombre', sin prefijo, BTREE.\n";

$stmtDup = $pdo->prepare(
    "SELECT COUNT(*) FROM (
        SELECT nombre FROM variable_bioclimatica
        WHERE nombre IS NOT NULL GROUP BY nombre HAVING COUNT(*) > 1
     ) AS d"
);
$stmtDup->execute();
if ((int) $stmtDup->fetchColumn() > 0) {
    fwrite(STDERR, "ERROR: persisten duplicados tras la migracion.\n");
    exit(1);
}

$filasDespues = $contar($pdo);
$huellaDespues = $huella($pdo);
if ($filasAntes !== $filasDespues || $huellaAntes !== $huellaDespues) {
    fwrite(STDERR,
        "ERROR: los datos cambiaron durante la migracion "
      . "($filasAntes -> $filasDespues).\n");
    exit(1);
}

echo "[OK] Datos intactos: $filasDespues filas, huella identica.\n";
echo "[OK] Migracion 010 completada sin modificar registros.\n";
exit(0);
