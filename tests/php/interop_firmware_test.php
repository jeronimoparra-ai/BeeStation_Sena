<?php
/**
 * Comprobacion de interoperabilidad FIRMWARE <-> SERVIDOR.
 *
 * Replica en PHP, byte a byte, las cadenas canonicas que construye el
 * firmware ESP32 y las compara con las que construye el servidor. Si
 * difieren, la firma nunca validara.
 *
 * Se cubren los DOS esquemas que el backend acepta hoy:
 *
 *   1. HMAC-SHA256 (heredado, solo en el backend): nodos ya desplegados.
 *      La cadena usa hexadecimal en MINUSCULAS. El firmware antiguo usaba
 *      mayusculas por un bug: esas firmas NUNCA validaron. El firmware
 *      actual ya no firma con HMAC.
 *
 *   2. ECDSA P-256 (actual): la cadena lleva el prefijo "BEE2" y el
 *      identificador del dispositivo; la prueba de posesion ("BEE2-POP")
 *      incluye la huella de la clave publica.
 *
 * La interoperabilidad REAL de Mbed TLS con OpenSSL se comprueba aparte
 * (tests/php/ecdsa_interop_test.php + tests/host/ecdsa_interop.c): este
 * test solo valida la construccion de la cadena.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/firma.php';
require_once __DIR__ . '/../../includes/dispositivo.php';

// ── Replica del firmware ESP32 ────────────────────────────────────────────
//  util_hex.h: los hexadecimales van SIEMPRE en minusculas.
function firmware_hexMinusculas(string $binario): string {
    return strtolower(bin2hex($binario));
}

function firmware_sha256Hex(string $texto): string {
    return firmware_hexMinusculas(hash('sha256', $texto, true));
}

// identidad_ecdsa.h · identCadenaCanonica()
function firmware_cadenaBEE2(string $metodo, string $ruta, string $ts, string $mac,
                              string $nonce, string $id, string $cuerpo): string {
    return "BEE2\n"
         . $metodo . "\n"
         . $ruta . "\n"
         . $ts . "\n"
         . $mac . "\n"
         . $nonce . "\n"
         . $id . "\n"
         . firmware_sha256Hex($cuerpo);
}

// identidad_ecdsa.h · identCadenaPop()
function firmware_cadenaPop(string $id, string $mac, string $ts, string $nonce, string $huella): string {
    return "BEE2-POP\n"
         . $id . "\n"
         . $mac . "\n"
         . $ts . "\n"
         . $nonce . "\n"
         . $huella;
}

$fallos = 0;

function comparar(string $titulo, string $fw, string $sv): void {
    global $fallos;

    echo "--- $titulo ---\n";
    echo "FIRMWARE : " . str_replace("\n", " | ", $fw) . "\n";
    echo "SERVIDOR : " . str_replace("\n", " | ", $sv) . "\n";

    if ($fw === $sv) {
        echo "RESULTADO: COINCIDEN\n\n";
        return;
    }

    $fallos++;
    echo "RESULTADO: DIFIEREN  <-- la firma NUNCA validara\n";
    $fwPartes = explode("\n", $fw);
    $svPartes = explode("\n", $sv);
    foreach ($fwPartes as $i => $p) {
        if (($svPartes[$i] ?? null) !== $p) {
            echo "  campo " . ($i + 1) . ": fw='" . $p . "' vs sv='" . ($svPartes[$i] ?? '') . "'\n";
        }
    }
    echo "\n";
}

// ── 1. Esquema HMAC heredado (solo backend) ───────────────────────────────
//  El firmware ya no firma asi; se conserva la comparacion porque el
//  backend sigue aceptando esta ruta para los nodos ya desplegados.
$casosHmac = [
    ['POST', '/api/ingest.php', '1792588800', 'AA:BB:CC:DD:EE:FF', '0123456789ABCDEF0123456789ABCDEF', '{"lecturas":[]}'],
    ['POST', '/api/validate_token.php', '1792588801', '00:11:22:33:44:55', 'FEDCBA9876543210FEDCBA9876543210', '{}'],
];

foreach ($casosHmac as [$metodo, $ruta, $ts, $mac, $nonce, $cuerpo]) {
    // El firmware normaliza el nonce a minusculas antes de firmar.
    $fw = $metodo . "\n" . $ruta . "\n" . $ts . "\n" . $mac . "\n"
        . strtolower($nonce) . "\n" . firmware_sha256Hex($cuerpo);
    comparar("HMAC $metodo $ruta", $fw, firmaCanonica($metodo, $ruta, $ts, $mac, $nonce, $cuerpo));
}

// ── 2. Esquema ECDSA P-256 (actual) ───────────────────────────────────────
$casosEcdsa = [
    ['POST', '/BeeStation_Sena/api/ingest.php', '1792588800', 'AA:BB:CC:DD:EE:FF',
     '0123456789abcdef0123456789abcdef', 'a1b2c3d4e5f60718293a4b5c6d7e8f90', '{"lecturas":[]}'],
    ['POST', '/BeeStation_Sena/api/enroll_estado.php', '1792588801', '00:11:22:33:44:55',
     'fedcba9876543210fedcba9876543210', '00112233445566778899aabbccddeeff', '{}'],
];

foreach ($casosEcdsa as [$metodo, $ruta, $ts, $mac, $nonce, $id, $cuerpo]) {
    comparar(
        "ECDSA $metodo $ruta",
        firmware_cadenaBEE2($metodo, $ruta, $ts, $mac, $nonce, $id, $cuerpo),
        dispositivoCadenaCanonica($metodo, $ruta, $ts, $mac, $nonce, $id, $cuerpo)
    );
}

// ── 3. Prueba de posesion (alta) ──────────────────────────────────────────
$huella = hash('sha256', "-----BEGIN PUBLIC KEY-----\nDEMO\n-----END PUBLIC KEY-----\n");
comparar(
    'ECDSA PoP /api/enroll.php',
    firmware_cadenaPop('a1b2c3d4e5f60718293a4b5c6d7e8f90', 'AA:BB:CC:DD:EE:FF',
                       '1792588800', '0123456789abcdef0123456789abcdef', $huella),
    dispositivoCadenaPop('a1b2c3d4e5f60718293a4b5c6d7e8f90', 'AA:BB:CC:DD:EE:FF',
                         '1792588800', '0123456789abcdef0123456789abcdef', $huella)
);

if ($fallos > 0) {
    fwrite(STDERR, "FALLO: $fallos cadena(s) canonica(s) divergente(s)\n");
    exit(1);
}
echo "OK: firmware y servidor construyen las mismas cadenas canonicas (HMAC y ECDSA)\n";
