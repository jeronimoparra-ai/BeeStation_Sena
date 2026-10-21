<?php
/**
 * tests/php/ecdsa_flujo_test.php
 * ----------------------------------------------------------------
 * Prueba END-TO-END del esquema de autenticacion sin token.
 *
 * Levanta el servidor HTTP real (php -S) contra la base de datos de
 * PRUEBAS AISLADA y ejercita el flujo completo tal y como lo haria el
 * ESP32:
 *
 *   1. El nodo se da de alta con su clave publica y una prueba de
 *      posesion (firma con su clave privada).
 *   2. El servidor lo deja PENDIENTE: todavia no puede enviar datos.
 *   3. Un usuario autenticado lo autoriza y lo asocia a una colmena.
 *   4. El nodo ya puede enviar telemetria firmada con ECDSA.
 *   5. Se comprueba el anti-replay, la deteccion de manipulacion y la
 *      revocacion individual.
 *
 * El nodo se SIMULA en PHP con OpenSSL. Eso NO sustituye a la prueba
 * cruzada con Mbed TLS (tests/php/ecdsa_interop_test.php): aqui se
 * comprueba el flujo del SERVIDOR; la interoperabilidad real de las
 * dos bibliotecas se comprueba aparte.
 *
 * Uso:
 *   DB_PRUEBA_HOST=127.0.0.1 DB_PRUEBA_PORT=3399 \
 *   DB_PRUEBA_NOMBRE=bs_phase2_prueba DB_PRUEBA_USUARIO=root \
 *   php tests/php/ecdsa_flujo_test.php
 * ----------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Solo por linea de comandos.\n");
    exit(1);
}

$raiz = dirname(__DIR__, 2);

// ── Configuración ──────────────────────────────────────────────────────────
function env(string $n, string $d = ''): string {
    $v = getenv($n);
    return ($v === false || $v === '') ? $d : (string) $v;
}

$dbHost    = env('DB_PRUEBA_HOST', '127.0.0.1');
$dbPuerto  = env('DB_PRUEBA_PORT', '3399');
$dbNombre  = env('DB_PRUEBA_NOMBRE', 'bs_phase2_prueba');
$dbUsuario = env('DB_PRUEBA_USUARIO', 'root');
$dbClave   = env('DB_PRUEBA_CLAVE', '');
$puertoHttp = (int) env('BEE_TEST_HTTP_PORT', '8099');
$baseUrl    = 'http://127.0.0.1:' . $puertoHttp;

if (!preg_match('/(_prueba|_test)$/i', $dbNombre)) {
    fwrite(STDERR, "ABORTADO: la base '$dbNombre' no es de pruebas.\n");
    exit(1);
}

// ── Utilidades de prueba ───────────────────────────────────────────────────
$fallos = 0;
$total  = 0;

function ok(string $titulo, bool $condicion, string $detalle = ''): void {
    global $fallos, $total;
    $total++;
    if ($condicion) {
        echo "  [OK]   $titulo\n";
    } else {
        $fallos++;
        echo "  [FALLA] $titulo" . ($detalle !== '' ? " -- $detalle" : '') . "\n";
    }
}

function titulo(string $t): void {
    echo "\n== $t ==\n";
}

/**
 * Petición HTTP mínima sin dependencias externas.
 *
 * @return array{estado:int, cuerpo:string, json:mixed}
 */
function http(string $metodo, string $url, array $cabeceras = [], ?string $cuerpo = null): array {
    // OJO: la opción "header" del envoltorio http:// de PHP NO admite un
    // array asociativo nombre=>valor: concatena los VALORES y descarta los
    // nombres. Debe ser una LISTA de líneas "Nombre: valor".
    $lineas = [];
    foreach ($cabeceras as $nombre => $valor) {
        $lineas[] = $nombre . ': ' . $valor;
    }

    $opciones = [
        'http' => [
            'method'        => $metodo,
            'header'        => $lineas,
            'ignore_errors' => true,
            'timeout'       => 20,
            'protocol_version' => 1.1,
        ],
    ];
    if ($cuerpo !== null) {
        $opciones['http']['content'] = $cuerpo;
    }
    $contexto = stream_context_create($opciones);
    $respuesta = @file_get_contents($url, false, $contexto);
    $estado = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $linea) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linea, $m)) {
                $estado = (int) $m[1];
            }
        }
    }
    $texto = $respuesta === false ? '' : $respuesta;
    return ['estado' => $estado, 'cuerpo' => $texto, 'json' => json_decode($texto, true)];
}

// ── Arranque del servidor de pruebas ───────────────────────────────────────
echo "Levantando servidor PHP de pruebas en $baseUrl ...\n";

// La salida del servidor (err_log incluido) se guarda en un fichero para
// poder diagnosticar los fallos en lugar de perderlos.
$logServidor = sys_get_temp_dir() . '/beestation-pruebas-servidor.log';
@unlink($logServidor);
$descriptores = [
    1 => ['file', $logServidor, 'a'],
    2 => ['file', $logServidor, 'a'],
];
$proceso = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $puertoHttp, '-t', $raiz],
    $descriptores,
    $pipes,
    $raiz,
    [
        'PATH'               => getenv('PATH'),
        'SystemRoot'         => getenv('SystemRoot'),
        'COMSPEC'            => getenv('COMSPEC'),
        'TEMP'               => getenv('TEMP'),
        'TMP'                => getenv('TMP'),
        'DB_HOST'            => $dbHost,
        'DB_PORT'            => $dbPuerto,
        'DB_NAME'            => $dbNombre,
        'DB_USER'            => $dbUsuario,
        'DB_PASS'            => $dbClave,
        // Obliga a que el servidor use SOLO el entorno y NUNCA
        // config/db.local.php (que apunta a la base de datos real).
        'BEE_DB_AISLADA'     => '1',
        'APP_ENV'            => 'pruebas',
    ]
);

$listo = false;
for ($i = 0; $i < 60; $i++) {
    usleep(250000);
    $sondeo = @fsockopen('127.0.0.1', $puertoHttp, $errno, $errstr, 0.4);
    if ($sondeo) {
        fclose($sondeo);
        $listo = true;
        break;
    }
}
if (!$listo) {
    fwrite(STDERR, "El servidor de pruebas no arranco.\n");
    if (is_resource($proceso)) {
        proc_terminate($proceso);
    }
    exit(1);
}
echo "Servidor listo.\n";

register_shutdown_function(function () use ($proceso) {
    if (is_resource($proceso)) {
        proc_terminate($proceso);
        proc_close($proceso);
    }
});

// ── Preparación de la base de pruebas ──────────────────────────────────────
$pdo = new PDO(
    "mysql:host=$dbHost;port=$dbPuerto;dbname=$dbNombre;charset=utf8mb4",
    $dbUsuario,
    $dbClave,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Se carga el codigo de PRODUCCION para probar las mismas funciones que
// usa el panel de dispositivos (includes/dispositivo.php), no copias.
// El modo aislado garantiza que getPDO() nunca apunte a la base real.
putenv('BEE_DB_AISLADA=1');
putenv('DB_HOST=' . $dbHost);
putenv('DB_PORT=' . $dbPuerto);
putenv('DB_NAME=' . $dbNombre);
putenv('DB_USER=' . $dbUsuario);
putenv('DB_PASS=' . $dbClave);
require_once $raiz . '/config/db.php';
require_once $raiz . '/includes/dispositivo.php';

$sufijo = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

// Usuario
$pdo->prepare("INSERT INTO usuario (nombre, correo, contrasena) VALUES (?, ?, ?)")
    ->execute(['Usuario de pruebas', "pruebas+$sufijo@ejemplo.local", password_hash('prueba', PASSWORD_DEFAULT)]);
$idUsuario = (int) $pdo->lastInsertId();

// Apiario y colmena
$pdo->prepare("INSERT INTO apiario (nombre, ubicacion, id_usuario) VALUES (?, ?, ?)")
    ->execute(['Apiario de pruebas', 'SENA El Bagre', $idUsuario]);
$idApiario = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO colmena (nombre, id_apiario, token_vinculacion) VALUES (?, ?, ?)")
    ->execute(['Colmena de pruebas', $idApiario, 'BS-PRUEBA-' . $sufijo]);
$idColmena = (int) $pdo->lastInsertId();

// Sensores (los necesita la ingesta).
// DISTINCT es obligatorio: variable_bioclimatica define cada tipo dos
// veces (una por colmena de referencia) y el sensor tiene clave única
// (id_colmena, tipo).
$variables = $pdo->query("SELECT DISTINCT nombre, MIN(id_variable) AS id_variable FROM variable_bioclimatica GROUP BY nombre")->fetchAll();
foreach ($variables as $variable) {
    $pdo->prepare("
        INSERT INTO sensor (tipo, modelo, rango_min, rango_max, precision_valor, estado, fecha_instalacion, id_colmena, id_variable)
        VALUES (?, 'PRUEBA', -1000000, 1000000, 2, 'sin_senal', CURDATE(), ?, ?)
    ")->execute([$variable['nombre'], $idColmena, $variable['id_variable']]);
}

// ── Utilidades que replican el firmware ────────────────────────────────────
/**
 * Cadena canonica de peticion (identica a beestation_node/identidad_ecdsa.h).
 */
function canonica(string $metodo, string $ruta, string $ts, string $mac, string $nonce,
                  string $idDispositivo, string $cuerpo): string {
    return "BEE2\n" . strtoupper($metodo) . "\n" . $ruta . "\n" . $ts . "\n" . $mac . "\n"
         . strtolower($nonce) . "\n" . strtolower($idDispositivo) . "\n" . hash('sha256', $cuerpo);
}

/**
 * Cadena canonica de la prueba de posesion.
 */
function canonicaPop(string $idDispositivo, string $mac, string $ts, string $nonce, string $huella): string {
    return "BEE2-POP\n" . strtolower($idDispositivo) . "\n" . $mac . "\n" . $ts . "\n"
         . strtolower($nonce) . "\n" . $huella;
}

/**
 * Simula al nodo: clave ECDSA P-256, identificador y codigo de alta.
 */
function nodoSimulado(): array {
    $clave = openssl_pkey_new([
        'curve_name'       => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);
    if ($clave === false) {
        throw new RuntimeException('No se pudo generar la clave de prueba: ' . openssl_error_string());
    }
    $detalles = openssl_pkey_get_details($clave);
    return [
        'clave'        => $clave,
        'publica_pem'  => $detalles['key'],
        'identificador'=> bin2hex(random_bytes(16)),
        'mac'          => sprintf('%02X:%02X:%02X:%02X:%02X:%02X', ...array_map('ord', str_split(random_bytes(6)))),
        'codigo'       => 'PRUEBA',
    ];
}

function firmar($clavePrivada, string $mensaje): string {
    $firma = '';
    if (!openssl_sign($mensaje, $firma, $clavePrivada, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Fallo la firma de prueba');
    }
    return bin2hex($firma);
}

// ══════════════════════════════════════════════════════════════════════════
//  1. ALTA CON PRUEBA DE POSESIÓN
// ══════════════════════════════════════════════════════════════════════════
titulo('1. Alta del nodo con prueba de posesion');

$nodo = nodoSimulado();
$nodo['codigo'] = 'ABCD-' . strtoupper(bin2hex(random_bytes(2)));
$huella = hash('sha256', $nodo['publica_pem']);

$ts    = (string) time();
$nonce = bin2hex(random_bytes(16));

$alta = [
    'identificador'  => $nodo['identificador'],
    'mac'            => $nodo['mac'],
    'nombre'         => 'Nodo de pruebas',
    'clave_publica'  => $nodo['publica_pem'],
    'codigo'         => $nodo['codigo'],
    'timestamp'      => $ts,
    'nonce'          => $nonce,
    'firma'          => firmar($nodo['clave'], canonicaPop($nodo['identificador'], $nodo['mac'], $ts, $nonce, $huella)),
];

$r = http('POST', $baseUrl . '/api/enroll.php', ['Content-Type: application/json'], json_encode($alta));
ok('El alta devuelve HTTP 200', $r['estado'] === 200, 'estado=' . $r['estado'] . ' cuerpo=' . $r['cuerpo']);
ok('El alta responde ok=true', ($r['json']['ok'] ?? false) === true, $r['cuerpo']);
ok('El nodo queda PENDIENTE', ($r['json']['estado'] ?? '') === 'pendiente', $r['cuerpo']);
ok('El nodo NO esta autorizado todavia', ($r['json']['autorizado'] ?? true) === false, $r['cuerpo']);

// El servidor guardo el dispositivo
$stmt = $pdo->prepare("SELECT * FROM dispositivo WHERE identificador = ?");
$stmt->execute([$nodo['identificador']]);
$fila = $stmt->fetch();
ok('El dispositivo queda registrado en la BD', (bool) $fila);
ok('La huella guardada coincide con la calculada', ($fila['huella_clave'] ?? '') === $huella);
ok('El codigo de alta se guarda solo como hash', ($fila['codigo_hash'] ?? '') === hash('sha256', $nodo['codigo']));
ok('La clave privada NO se guarda en el servidor', !str_contains(json_encode($fila), 'PRIVATE KEY'));

// Alta con firma invalida: debe rechazarse
$altaMala = $alta;
$altaMala['identificador'] = bin2hex(random_bytes(16));
$altaMala['firma'] = str_repeat('ab', 72);
$rMala = http('POST', $baseUrl . '/api/enroll.php', ['Content-Type: application/json'], json_encode($altaMala));
ok('Un alta con firma invalida se rechaza', $rMala['estado'] === 401, 'estado=' . $rMala['estado']);

// Reutilizar el nonce del alta: debe rechazarse (anti-replay)
$altaRepetida = $alta;
$rRepetida = http('POST', $baseUrl . '/api/enroll.php', ['Content-Type: application/json'], json_encode($altaRepetida));
ok('Reutilizar el nonce del alta se rechaza', $rRepetida['estado'] === 401, 'estado=' . $rRepetida['estado']);

// ══════════════════════════════════════════════════════════════════════════
//  2. TELEMETRIA ANTES DE AUTORIZAR: DEBE RECHAZARSE
// ══════════════════════════════════════════════════════════════════════════
titulo('2. Un nodo pendiente NO puede enviar telemetria');

$cuerpo = json_encode(['lecturas' => [['tipo' => 'temperatura_interna', 'valor' => 25.5]]]);
$ruta   = '/api/ingest.php';
$ts     = (string) time();
$nonce  = bin2hex(random_bytes(16));
$firma  = firmar($nodo['clave'], canonica('POST', $ruta, $ts, $nodo['mac'], $nonce, $nodo['identificador'], $cuerpo));

$r = http('POST', $baseUrl . $ruta, [
    'Content-Type'                => 'application/json',
    'X-BeeStation-Dispositivo'    => $nodo['identificador'],
    'X-BeeStation-Mac'            => $nodo['mac'],
    'X-BeeStation-Timestamp'      => $ts,
    'X-BeeStation-Nonce'          => $nonce,
    'X-BeeStation-Firma'          => $firma,
], $cuerpo);
ok('La ingesta de un nodo pendiente se rechaza', $r['estado'] === 401, 'estado=' . $r['estado'] . ' ' . $r['cuerpo']);
ok('El motivo es "dispositivo_pendiente"', ($r['json']['motivo'] ?? '') === 'dispositivo_pendiente', $r['cuerpo']);

// ══════════════════════════════════════════════════════════════════════════
//  3. AUTORIZACION POR UN USUARIO AUTENTICADO
// ══════════════════════════════════════════════════════════════════════════
titulo('3. Autorizacion del nodo por el usuario (panel de dispositivos)');

// ── El panel de dispositivos usa estas mismas funciones ───────────────────
// No se toca la tabla a mano: se ejercita el camino real que ejecuta
// dispositivos.php cuando el usuario introduce el codigo y elige colmena.

// Antes de autorizar, no se puede vincular a una colmena ajena.
$ajena = dispositivoAprobar((int) $fila['id_dispositivo'], $idColmena, $idUsuario + 9999);
ok('No se puede vincular el nodo a una colmena ajena',
   empty($ajena['ok']) && ($ajena['error'] ?? '') === 'colmena_no_pertenece',
   json_encode($ajena));

// El usuario introduce el codigo corto que muestra el nodo.
$nodoPorCodigo = dispositivoBuscarPorCodigo($nodo['codigo']);
ok('El nodo se localiza por su codigo corto (XXXX-XXXX)', $nodoPorCodigo !== null);
ok('El nodo localizado sigue PENDIENTE', ($nodoPorCodigo['estado'] ?? '') === 'pendiente');

$alta = dispositivoAprobar((int) $fila['id_dispositivo'], $idColmena, $idUsuario);
ok('La autorizacion del panel se acepta', !empty($alta['ok']), json_encode($alta));

$stmt = $pdo->prepare("SELECT estado, id_colmena FROM dispositivo WHERE id_dispositivo = ?");
$stmt->execute([$fila['id_dispositivo']]);
$f = $stmt->fetch();
ok('El nodo pasa a AUTORIZADO', $f['estado'] === 'autorizado');
ok('El nodo queda asociado a la colmena', (int) $f['id_colmena'] === $idColmena);

// El codigo queda consumido: no se puede reutilizar para otro nodo.
ok('El codigo de alta ya no sirve tras autorizar', dispositivoBuscarPorCodigo($nodo['codigo']) === null);

// ── Control de intentos del codigo (fuerza bruta) ─────────────────────────
titulo('3b. Control de intentos del codigo de alta');

$codigoLimite = 'TEST-' . strtoupper(bin2hex(random_bytes(2)));
$idLimite = 'f' . bin2hex(random_bytes(15));
$pdo->prepare("
    INSERT INTO dispositivo (identificador, mac, nombre, clave_publica_pem, huella_clave,
                             codigo_hash, codigo_expira, codigo_intentos, codigo_max_intentos)
    VALUES (?, 'FF:FF:FF:FF:FF:FF', 'Nodo de limite', 'PEM-DE-PRUEBA', ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), 0, 3)
")->execute([
    $idLimite,
    hash('sha256', 'huella-' . $codigoLimite),
    hash('sha256', $codigoLimite),
]);
$idDispLimite = (int) $pdo->lastInsertId();

ok('Un codigo inexistente no localiza ningun nodo',
   dispositivoLocalizarParaIntento('ZZZZ-ZZZZ') === null);
ok('Un codigo con formato invalido no localiza ningun nodo',
   dispositivoLocalizarParaIntento('NO-VALE') === null);
ok('El nodo de limite se localiza para contar intentos',
   dispositivoLocalizarParaIntento($codigoLimite) !== null);
ok('Con intentos disponibles el codigo es valido',
   dispositivoBuscarPorCodigo($codigoLimite) !== null);

dispositivoRegistrarIntentoCodigo($idDispLimite);
dispositivoRegistrarIntentoCodigo($idDispLimite);
ok('Tras 2 intentos fallidos el codigo AUN se acepta',
   dispositivoBuscarPorCodigo($codigoLimite) !== null);

dispositivoRegistrarIntentoCodigo($idDispLimite);
ok('Tras agotar los 3 intentos el codigo se RECHAZA',
   dispositivoBuscarPorCodigo($codigoLimite) === null);
ok('El nodo sigue localizable para seguir contando intentos',
   dispositivoLocalizarParaIntento($codigoLimite) !== null);

$pdo->prepare("DELETE FROM dispositivo WHERE id_dispositivo = ?")->execute([$idDispLimite]);

// ══════════════════════════════════════════════════════════════════════════
//  4. TELEMETRIA AUTORIZADA
// ══════════════════════════════════════════════════════════════════════════
titulo('4. Telemetria firmada con ECDSA por un nodo autorizado');

$idMedicion = bin2hex(random_bytes(16));
$cuerpo = json_encode([
    'lote'     => 'PRUEBA-' . $sufijo,
    'lecturas' => [
        ['tipo' => 'temperatura_interna', 'valor' => 25.5, 'ts' => time(), 'id_medicion' => $idMedicion],
    ],
]);
$ts    = (string) time();
$nonce = bin2hex(random_bytes(16));
$firma = firmar($nodo['clave'], canonica('POST', $ruta, $ts, $nodo['mac'], $nonce, $nodo['identificador'], $cuerpo));

$r = http('POST', $baseUrl . $ruta, [
    'Content-Type'             => 'application/json',
    'X-BeeStation-Dispositivo' => $nodo['identificador'],
    'X-BeeStation-Mac'         => $nodo['mac'],
    'X-BeeStation-Timestamp'   => $ts,
    'X-BeeStation-Nonce'       => $nonce,
    'X-BeeStation-Firma'       => $firma,
], $cuerpo);
ok('La ingesta autenticada devuelve HTTP 200', $r['estado'] === 200, 'estado=' . $r['estado'] . ' ' . $r['cuerpo']);
ok('La ingesta responde ok=true', ($r['json']['ok'] ?? false) === true, $r['cuerpo']);
ok('La ingesta indica el esquema ECDSA', ($r['json']['esquema'] ?? '') === 'ecdsa', $r['cuerpo']);
ok('Se han insertado las lecturas', count($r['json']['insertados'] ?? []) === 1, $r['cuerpo']);
ok('El acuse por lectura marca "insertada"', ($r['json']['resultados'][0]['estado'] ?? '') === 'insertada', $r['cuerpo']);
ok('El acuse devuelve el id_medicion', ($r['json']['resultados'][0]['id_medicion'] ?? '') === $idMedicion, $r['cuerpo']);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM lectura WHERE id_medicion = ?");
$stmt->execute([$idMedicion]);
ok('La lectura queda en la base de datos con su id_medicion', (int) $stmt->fetchColumn() === 1);

// La colmena de la lectura debe ser la asignada al nodo
$stmt = $pdo->prepare("
    SELECT l.id_sensor, s.id_colmena FROM lectura l
    JOIN sensor s ON s.id_sensor = l.id_sensor
    WHERE l.id_medicion = ?
");
$stmt->execute([$idMedicion]);
$filaLectura = $stmt->fetch();
ok('La lectura se guardo en la colmena del nodo', (int) $filaLectura['id_colmena'] === $idColmena);

// ══════════════════════════════════════════════════════════════════════════
//  5. IDEMPOTENCIA, ANTI-REPLAY Y MANIPULACION
// ══════════════════════════════════════════════════════════════════════════
titulo('5. Idempotencia, anti-replay y deteccion de manipulacion');

// Reenvio con el MISMO id_medicion y un nonce nuevo: debe ser "duplicada"
$ts2   = (string) time();
$nonce2 = bin2hex(random_bytes(16));
$firma2 = firmar($nodo['clave'], canonica('POST', $ruta, $ts2, $nodo['mac'], $nonce2, $nodo['identificador'], $cuerpo));
$r = http('POST', $baseUrl . $ruta, [
    'Content-Type'             => 'application/json',
    'X-BeeStation-Dispositivo' => $nodo['identificador'],
    'X-BeeStation-Mac'         => $nodo['mac'],
    'X-BeeStation-Timestamp'   => $ts2,
    'X-BeeStation-Nonce'       => $nonce2,
    'X-BeeStation-Firma'       => $firma2,
], $cuerpo);
ok('El reenvio de la misma medicion se acepta', $r['estado'] === 200, 'estado=' . $r['estado']);
ok('El reenvio se acusa como "duplicada"', ($r['json']['resultados'][0]['estado'] ?? '') === 'duplicada', $r['cuerpo']);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM lectura WHERE id_medicion = ?");
$stmt->execute([$idMedicion]);
ok('El reenvio NO duplica la fila', (int) $stmt->fetchColumn() === 1);

// Reutilizar un nonce ya usado: debe rechazarse
$r = http('POST', $baseUrl . $ruta, [
    'Content-Type'             => 'application/json',
    'X-BeeStation-Dispositivo' => $nodo['identificador'],
    'X-BeeStation-Mac'         => $nodo['mac'],
    'X-BeeStation-Timestamp'   => $ts2,
    'X-BeeStation-Nonce'       => $nonce2,
    'X-BeeStation-Firma'       => $firma2,
], $cuerpo);
ok('Reutilizar un nonce se rechaza (anti-replay)', $r['estado'] === 401, 'estado=' . $r['estado']);
ok('El motivo es "nonce_reutilizado"', ($r['json']['motivo'] ?? '') === 'nonce_reutilizado', $r['cuerpo']);

// Firmar un cuerpo y enviar otro: debe rechazarse
$cuerpoBueno = json_encode(['lecturas' => [['tipo' => 'temperatura_interna', 'valor' => 30.0, 'id_medicion' => bin2hex(random_bytes(16))]]]);
$cuerpoMalo  = json_encode(['lecturas' => [['tipo' => 'temperatura_interna', 'valor' => 99.0, 'id_medicion' => bin2hex(random_bytes(16))]]]);
$ts3   = (string) time();
$nonce3 = bin2hex(random_bytes(16));
$firma3 = firmar($nodo['clave'], canonica('POST', $ruta, $ts3, $nodo['mac'], $nonce3, $nodo['identificador'], $cuerpoBueno));
$r = http('POST', $baseUrl . $ruta, [
    'Content-Type'             => 'application/json',
    'X-BeeStation-Dispositivo' => $nodo['identificador'],
    'X-BeeStation-Mac'         => $nodo['mac'],
    'X-BeeStation-Timestamp'   => $ts3,
    'X-BeeStation-Nonce'       => $nonce3,
    'X-BeeStation-Firma'       => $firma3,
], $cuerpoMalo);
ok('Un cuerpo manipulado se rechaza', $r['estado'] === 401, 'estado=' . $r['estado']);
ok('El motivo es "firma_no_valida"', ($r['json']['motivo'] ?? '') === 'firma_no_valida', $r['cuerpo']);

// Timestamp fuera de la ventana
$ts4   = (string) (time() - 4000);
$nonce4 = bin2hex(random_bytes(16));
$firma4 = firmar($nodo['clave'], canonica('POST', $ruta, $ts4, $nodo['mac'], $nonce4, $nodo['identificador'], $cuerpo));
$r = http('POST', $baseUrl . $ruta, [
    'Content-Type'             => 'application/json',
    'X-BeeStation-Dispositivo' => $nodo['identificador'],
    'X-BeeStation-Mac'         => $nodo['mac'],
    'X-BeeStation-Timestamp'   => $ts4,
    'X-BeeStation-Nonce'       => $nonce4,
    'X-BeeStation-Firma'       => $firma4,
], $cuerpo);
ok('Un timestamp fuera de ventana se rechaza', $r['estado'] === 401, 'estado=' . $r['estado']);

// ══════════════════════════════════════════════════════════════════════════
//  6. CONSULTA DE ESTADO Y REVOCACION
// ══════════════════════════════════════════════════════════════════════════
titulo('6. Consulta de estado y revocacion individual');

$ts5   = (string) time();
$nonce5 = bin2hex(random_bytes(16));
$rutaEstado = '/api/enroll_estado.php';
$cuerpo5 = '{}';
$firma5 = firmar($nodo['clave'], canonica('POST', $rutaEstado, $ts5, $nodo['mac'], $nonce5, $nodo['identificador'], $cuerpo5));
$r = http('POST', $baseUrl . $rutaEstado, [
    'Content-Type'             => 'application/json',
    'X-BeeStation-Dispositivo' => $nodo['identificador'],
    'X-BeeStation-Mac'         => $nodo['mac'],
    'X-BeeStation-Timestamp'   => $ts5,
    'X-BeeStation-Nonce'       => $nonce5,
    'X-BeeStation-Firma'       => $firma5,
], $cuerpo5);
ok('La consulta de estado devuelve HTTP 200', $r['estado'] === 200, 'estado=' . $r['estado'] . ' ' . $r['cuerpo']);
ok('La consulta de estado indica autorizacion', ($r['json']['autorizado'] ?? false) === true, $r['cuerpo']);
ok('La consulta de estado devuelve la colmena', (int) ($r['json']['id_colmena'] ?? 0) === $idColmena, $r['cuerpo']);

// Revocacion
$pdo->prepare("UPDATE dispositivo SET estado = 'revocado', revocado_en = NOW() WHERE id_dispositivo = ?")
    ->execute([$fila['id_dispositivo']]);

$ts6   = (string) time();
$nonce6 = bin2hex(random_bytes(16));
$firma6 = firmar($nodo['clave'], canonica('POST', $ruta, $ts6, $nodo['mac'], $nonce6, $nodo['identificador'], $cuerpo));
$r = http('POST', $baseUrl . $ruta, [
    'Content-Type'             => 'application/json',
    'X-BeeStation-Dispositivo' => $nodo['identificador'],
    'X-BeeStation-Mac'         => $nodo['mac'],
    'X-BeeStation-Timestamp'   => $ts6,
    'X-BeeStation-Nonce'       => $nonce6,
    'X-BeeStation-Firma'       => $firma6,
], $cuerpo);
ok('Tras revocar, la ingesta se rechaza', $r['estado'] === 401, 'estado=' . $r['estado']);
ok('El motivo es "dispositivo_revocado"', ($r['json']['motivo'] ?? '') === 'dispositivo_revocado', $r['cuerpo']);

// ══════════════════════════════════════════════════════════════════════════
//  7. EL CAMINO HMAC HEREDADO SIGUE FUNCIONANDO (TRANSICION)
// ══════════════════════════════════════════════════════════════════════════
titulo('7. El esquema HMAC heredado sigue operativo (transicion)');

$token = 'BS-PRUEBA-' . $sufijo;
$cuerpoHmac = json_encode(['lecturas' => [['tipo' => 'temperatura_interna', 'valor' => 22.0]]]);
$tsH   = (string) time();
$nonceH = bin2hex(random_bytes(16));
$canonicaHmac = strtoupper('POST') . "\n" . $ruta . "\n" . $tsH . "\n" . $nodo['mac'] . "\n"
              . strtolower($nonceH) . "\n" . hash('sha256', $cuerpoHmac);
$firmaHmac = hash_hmac('sha256', $canonicaHmac, strtoupper($token));

$r = http('POST', $baseUrl . $ruta, [
    'Content-Type'           => 'application/json',
    'X-BeeStation-Mac'       => $nodo['mac'],
    'X-BeeStation-Timestamp' => $tsH,
    'X-BeeStation-Nonce'     => $nonceH,
    'X-BeeStation-Signature' => $firmaHmac,
], $cuerpoHmac);
ok('El camino HMAC heredado sigue funcionando', $r['estado'] === 200, 'estado=' . $r['estado'] . ' ' . $r['cuerpo']);
ok('El camino HMAC se identifica como esquema hmac', ($r['json']['esquema'] ?? '') === 'hmac', $r['cuerpo']);

// ── Limpieza ───────────────────────────────────────────────────────────────
// El orden respeta las claves foraneas: primero las tablas hijas.
$pdo->prepare("DELETE FROM lectura WHERE id_sensor IN (SELECT id_sensor FROM sensor WHERE id_colmena = ?)")->execute([$idColmena]);
$pdo->prepare("DELETE FROM lote_ingesta WHERE id_colmena = ?")->execute([$idColmena]);
$pdo->prepare("DELETE FROM sensor WHERE id_colmena = ?")->execute([$idColmena]);
$pdo->prepare("DELETE FROM nonce_dispositivo WHERE identificador = ?")->execute([$nodo['identificador']]);
$pdo->prepare("DELETE FROM dispositivo WHERE identificador = ?")->execute([$nodo['identificador']]);
$pdo->prepare("DELETE FROM colmena WHERE id_colmena = ?")->execute([$idColmena]);
$pdo->prepare("DELETE FROM apiario WHERE id_apiario = ?")->execute([$idApiario]);
$pdo->prepare("DELETE FROM usuario WHERE id_usuario = ?")->execute([$idUsuario]);

// ── Resultado ──────────────────────────────────────────────────────────────
echo "\n----------------------------------------\n";
echo "Pruebas ejecutadas: $total | Fallos: $fallos\n";
if ($fallos > 0) {
    echo "RESULTADO: FALLA\n";
    exit(1);
}
echo "RESULTADO: OK\n";
exit(0);
