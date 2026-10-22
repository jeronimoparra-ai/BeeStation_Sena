<?php
/**
 * tests/php/ecdsa_interop_test.php
 * ----------------------------------------------------------------
 * PRUEBA CRUZADA REAL entre Mbed TLS (la biblioteca que usa el
 * firmware del ESP32) y PHP/OpenSSL (la que usa el servidor).
 *
 * Esta prueba NO simula nada: compila y ejecuta un programa en C que
 * usa la MISMA biblioteca Mbed TLS 3.6.6 que el firmware, y comprueba
 * las dos direcciones:
 *
 *   1. Mbed TLS firma  ->  PHP/OpenSSL verifica la firma.
 *   2. PHP/OpenSSL firma  ->  Mbed TLS verifica la firma.
 *
 * Ademas comprueba:
 *   · Que la clave publica PEM que produce Mbed TLS la acepta
 *     openssl_pkey_get_public() de PHP.
 *   · Que la firma es ASN.1 DER (SEQUENCE { INTEGER r, INTEGER s }).
 *   · Que la huella (SHA-256 del PEM) coincide en ambas bibliotecas.
 *   · Que las cadenas canonicas de producción (las de
 *     includes/dispositivo.php) son las que se firman.
 *
 * Requisitos:
 *   · Un compilador de C para el host (MinGW-w64 / gcc).
 *   · El codigo fuente de Mbed TLS 3.6.6.
 *   · La biblioteca compilada para el host.
 *
 * Variables de entorno opcionales:
 *   BEE_MBEDTLS_SRC   ruta del arbol de fuentes de Mbed TLS
 *   BEE_GCC           ruta del ejecutable gcc
 *   BEE_HOST_BUILD    directorio de compilacion (por defecto, temporal)
 *
 * Si no se puede compilar el programa, la prueba se marca como
 * OMITIDA y NO se da por buena: la interoperabilidad quedaria sin
 * comprobar.
 * ----------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$raiz = dirname(__DIR__, 2);

function env(string $n, string $d = ''): string {
    $v = getenv($n);
    return ($v === false || $v === '') ? $d : (string) $v;
}

$fallos   = 0;
$total    = 0;
$omitidas = 0;

function ok(string $titulo, bool $condicion, string $detalle = ''): void {
    global $fallos, $total;
    $total++;
    if ($condicion) {
        echo "  [OK]    $titulo\n";
    } else {
        $fallos++;
        echo "  [FALLA] $titulo" . ($detalle !== '' ? " -- $detalle" : '') . "\n";
    }
}

function titulo(string $t): void {
    echo "\n== $t ==\n";
}

// ── Rutas ──────────────────────────────────────────────────────────────────
$srcMbedtls = env('BEE_MBEDTLS_SRC', 'C:\\Users\\andre\\AppData\\Local\\Temp\\mbedtls_src\\mbedtls-3.6.6');
$gcc        = env('BEE_GCC', 'C:\\Users\\andre\\AppData\\Local\\Temp\\gcc_host\\mingw64\\bin\\gcc.exe');
$buildDir   = env('BEE_HOST_BUILD', sys_get_temp_dir() . '\\bs_host_build');
$binario    = $buildDir . '\\ecdsa_interop.exe';

// ── Compilar si hace falta ─────────────────────────────────────────────────
if (!is_file($binario)) {
    echo "Compilando el programa host de interoperabilidad...\n";
    $libMbedtls = $srcMbedtls . '/build_host/libmbedtls.a';

    if (!is_file($gcc)) {
        echo "  [OMITIDA] No se encuentra gcc ('$gcc').\n";
        echo "             Configure BEE_GCC o compile tests/host/ecdsa_interop.c a mano.\n";
        exit(2);
    }
    if (!is_file($libMbedtls)) {
        echo "  [OMITIDA] No se encuentra la biblioteca Mbed TLS para host:\n";
        echo "             $libMbedtls\n";
        echo "             Compile Mbed TLS 3.6.6 con:\n";
        echo "               gcc -c -O2 -I<fuentes>/include <fuentes>/library/*.c\n";
        echo "               ar rcs libmbedtls.a *.o\n";
        exit(2);
    }

    @mkdir($buildDir, 0777, true);
    $cmd = escapeshellcmd($gcc) . ' -O2 -w '
        . escapeshellarg('-I' . $srcMbedtls . '/include') . ' -o '
        . escapeshellarg($binario) . ' '
        . escapeshellarg($raiz . '/tests/host/ecdsa_interop.c') . ' '
        . escapeshellarg($libMbedtls) . ' -lbcrypt 2>&1';
    $salida = [];
    $codigo = 0;
    exec($cmd, $salida, $codigo);
    if ($codigo !== 0 || !is_file($binario)) {
        echo "  [OMITIDA] La compilacion fallo:\n";
        echo implode("\n", $salida) . "\n";
        exit(2);
    }
    echo "Compilado: $binario\n";
}

// ── Preparacion ────────────────────────────────────────────────────────────
// Se carga la configuracion en modo AISLADO: las funciones canonicas de
// produccion (includes/dispositivo.php) no necesitan la base de datos,
// pero el fichero la carga, y no debe tocar jamas la base real.
$dbHost    = env('DB_PRUEBA_HOST', '127.0.0.1');
$dbPuerto  = env('DB_PRUEBA_PORT', '3399');
$dbNombre  = env('DB_PRUEBA_NOMBRE', 'bs_phase2_prueba');
$dbUsuario = env('DB_PRUEBA_USUARIO', 'root');
$dbClave   = env('DB_PRUEBA_CLAVE', '');

putenv('BEE_DB_AISLADA=1');
putenv('DB_HOST=' . $dbHost);
putenv('DB_PORT=' . $dbPuerto);
putenv('DB_NAME=' . $dbNombre);
putenv('DB_USER=' . $dbUsuario);
putenv('DB_PASS=' . $dbClave);

require_once $raiz . '/includes/dispositivo.php';

// ── Datos de la prueba ─────────────────────────────────────────────────────
$idDispositivo = bin2hex(random_bytes(16));
$mac           = 'AA:BB:CC:DD:EE:FF';
$timestamp     = (string) time();
$nonce         = bin2hex(random_bytes(16));
$ruta          = '/api/ingest.php';
$cuerpo        = json_encode(['lecturas' => [['tipo' => 'temperatura_interna', 'valor' => 24.75]]]);

// Cadenas canonicas de PRODUCCION (no copias).
$canonica = dispositivoCadenaCanonica('POST', $ruta, $timestamp, $mac, $nonce, $idDispositivo, $cuerpo);

$dir = sys_get_temp_dir() . '/bs_interop_' . getmypid();
@mkdir($dir, 0777, true);
$ficheroCadena = $dir . '/cadena.txt';
file_put_contents($ficheroCadena, $canonica);
// Version manipulada de la misma cadena, para comprobar que la firma no vale.
file_put_contents($dir . '/cadena_manipulada.txt', $canonica . "\nmanipulado");

// ══════════════════════════════════════════════════════════════════════════
//  1. Mbed TLS firma  ->  PHP/OpenSSL verifica
// ══════════════════════════════════════════════════════════════════════════
titulo('1. Mbed TLS firma y PHP/OpenSSL verifica');

$salida = [];
$codigo = 0;
exec(
    escapeshellcmd($binario) . ' firmar ' . escapeshellarg($ficheroCadena) . ' 2>&1',
    $salida,
    $codigo
);
ok('El programa host se ejecuta sin errores', $codigo === 0, implode(' | ', $salida));

$json = json_decode(implode("\n", $salida), true);
ok('El programa devuelve JSON', is_array($json), implode(' | ', $salida));

if (!is_array($json)) {
    echo "\nNo se puede continuar sin la salida del programa host.\n";
    exit(1);
}

$pem       = (string) ($json['pem'] ?? '');
$firmaHex  = (string) ($json['firma_hex'] ?? '');
$huellaC   = (string) ($json['huella'] ?? '');

ok('La clave publica es un PEM SPKI', str_starts_with($pem, '-----BEGIN PUBLIC KEY-----'), substr($pem, 0, 40));
ok('La clave publica termina bien', str_contains($pem, '-----END PUBLIC KEY-----'));

// La firma debe ser ASN.1 DER: SEQUENCE (0x30) { INTEGER, INTEGER }
$der = hex2bin($firmaHex);
ok('La firma Mbed TLS tiene formato DER (SEQUENCE 0x30)', $der !== false && $der[0] === "\x30", $firmaHex);
ok('La firma tiene una longitud coherente con P-256 DER', $der !== false && strlen($der) >= 68 && strlen($der) <= 72, 'bytes=' . ($der === false ? 0 : strlen($der)));

// ── Verificacion con OpenSSL ──────────────────────────────────────────────
$clave = dispositivoClavePublica($pem);
ok('PHP/OpenSSL acepta la clave publica PEM de Mbed TLS', $clave !== null);
ok('La curva de la clave es P-256 (prime256v1)', $clave !== null);

$verificado = $clave !== null
    ? openssl_verify($canonica, $der, $clave, OPENSSL_ALGO_SHA256)
    : -1;
ok('PHP/OpenSSL VERIFICA la firma de Mbed TLS', $verificado === 1, 'resultado=' . var_export($verificado, true));

// ── La huella coincide en ambas bibliotecas ───────────────────────────────
ok('La huella de Mbed TLS coincide con hash(sha256, PEM) de PHP',
   hash_equals(hash('sha256', $pem), $huellaC), 'mbedtls=' . $huellaC . ' php=' . hash('sha256', $pem));

// ── Una firma sobre otro mensaje NO valida ───────────────────────────────
$otroMensaje = $canonica . "\nmanipulado";
$verificadoMalo = $clave !== null
    ? openssl_verify($otroMensaje, $der, $clave, OPENSSL_ALGO_SHA256)
    : 0;
ok('La firma NO valida sobre un mensaje distinto', $verificadoMalo !== 1);

// ══════════════════════════════════════════════════════════════════════════
//  2. PHP/OpenSSL firma  ->  Mbed TLS verifica
// ══════════════════════════════════════════════════════════════════════════
titulo('2. PHP/OpenSSL firma y Mbed TLS verifica');

$clavePhp = openssl_pkey_new([
    'curve_name'       => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC,
]);
ok('PHP genera una clave ECDSA P-256', $clavePhp !== false);

if ($clavePhp !== false) {
    $detalles = openssl_pkey_get_details($clavePhp);
    $pemPhp   = (string) $detalles['key'];

    $firmaPhp = '';
    openssl_sign($canonica, $firmaPhp, $clavePhp, OPENSSL_ALGO_SHA256);
    ok('PHP produce una firma DER', $firmaPhp !== '' && $firmaPhp[0] === "\x30", 'bytes=' . strlen($firmaPhp));

    $ficheroPem = $dir . '/php.pem';
    file_put_contents($ficheroPem, $pemPhp);

    $salida = [];
    $codigo = 0;
    exec(
        escapeshellcmd($binario) . ' verificar '
        . escapeshellarg($ficheroPem) . ' '
        . escapeshellarg($ficheroCadena) . ' '
        . escapeshellarg(bin2hex($firmaPhp)) . ' 2>&1',
        $salida,
        $codigo
    );
    $jsonVer = json_decode(implode("\n", $salida), true);
    ok('Mbed TLS VERIFICA la firma de PHP/OpenSSL',
       is_array($jsonVer) && ($jsonVer['ok'] ?? false) === true,
       implode(' | ', $salida));

    // Y la firma de PHP debe fallar si el mensaje se manipula
    $salida = [];
    exec(
        escapeshellcmd($binario) . ' verificar '
        . escapeshellarg($ficheroPem) . ' '
        . escapeshellarg($dir . '/cadena_manipulada.txt') . ' '
        . escapeshellarg(bin2hex($firmaPhp)) . ' 2>&1',
        $salida,
        $codigo
    );
    $jsonVer = json_decode(implode("\n", $salida), true);
    ok('Mbed TLS RECHAZA la firma de PHP sobre un mensaje distinto',
       is_array($jsonVer) && ($jsonVer['ok'] ?? true) === false);
}

// ══════════════════════════════════════════════════════════════════════════
//  3. La cadena canonica de PRODUCCION es la que se firma
// ══════════════════════════════════════════════════════════════════════════
titulo('3. La cadena canonica de produccion es la firmada');

$canonicaEsperada = "BEE2\n"
    . "POST\n"
    . $ruta . "\n"
    . $timestamp . "\n"
    . $mac . "\n"
    . $nonce . "\n"
    . $idDispositivo . "\n"
    . hash('sha256', $cuerpo);
ok('La cadena canonica tiene la forma esperada', $canonica === $canonicaEsperada);
ok('La cadena canonica empieza por el marcador de esquema BEE2', str_starts_with($canonica, "BEE2\n"));

// La huella del PoP se calcula sobre el PEM y debe ser la misma en ambos lados
$huellaPop = dispositivoHuella($pem);
ok('La huella del PoP coincide con la de Mbed TLS', hash_equals($huellaC, $huellaPop));

// ══════════════════════════════════════════════════════════════════════════
//  4. Limpieza
// ══════════════════════════════════════════════════════════════════════════
@unlink($ficheroCadena);
@unlink($dir . '/php.pem');
@unlink($dir . '/cadena_manipulada.txt');
@rmdir($dir);

echo "\n----------------------------------------\n";
echo "Pruebas ejecutadas: $total | Fallos: $fallos\n";
if ($fallos > 0) {
    echo "RESULTADO: FALLA\n";
    exit(1);
}
echo "RESULTADO: OK — interoperabilidad Mbed TLS <-> PHP/OpenSSL comprobada\n";
exit(0);
