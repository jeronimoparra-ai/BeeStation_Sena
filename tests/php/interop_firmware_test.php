<?php
/**
 * Comprobacion de interoperabilidad FIRMWARE <-> SERVIDOR.
 *
 * Replica en PHP exactamente la construccion de la cadena canonica que
 * hace el firmware ESP32 (hex en MAYUSCULAS) y la compara con la que
 * construye el servidor. Si difieren, la firma nunca validara.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/firma.php';

// --- Replica del firmware ESP32 (beestation_node.ino) ---
function firmware_hexMayusculas(string $binario): string {
    return strtoupper(bin2hex($binario));
}
function firmware_sha256Hex(string $texto): string {
    return firmware_hexMayusculas(hash('sha256', $texto, true));
}
function firmware_cadenaFirma(string $metodo, string $ruta, string $ts, string $mac, string $nonce, string $cuerpo): string {
    return $metodo . "\n" . $ruta . "\n" . $ts . "\n" . $mac . "\n" . $nonce . "\n" . firmware_sha256Hex($cuerpo);
}

$casos = [
    ['POST', '/api/ingest.php', '1792588800', 'AA:BB:CC:DD:EE:FF', '0123456789ABCDEF0123456789ABCDEF', '{"lecturas":[]}'],
    ['POST', '/api/validate_token.php', '1792588801', '00:11:22:33:44:55', 'FEDCBA9876543210FEDCBA9876543210', '{}'],
];

$fallos = 0;
foreach ($casos as [$metodo, $ruta, $ts, $mac, $nonce, $cuerpo]) {
    $fw = firmware_cadenaFirma($metodo, $ruta, $ts, $mac, $nonce, $cuerpo);
    $sv = firmaCanonica($metodo, $ruta, $ts, $mac, $nonce, $cuerpo);

    echo "--- Caso: $metodo $ruta ---\n";
    echo "FIRMWARE : " . str_replace("\n", " | ", $fw) . "\n";
    echo "SERVIDOR : " . str_replace("\n", " | ", $sv) . "\n";

    if ($fw === $sv) {
        echo "RESULTADO: COINCIDEN\n\n";
        continue;
    }

    $fallos++;
    echo "RESULTADO: DIFIEREN  <-- la firma NUNCA validara\n";
    // Mostrar en que campo difieren
    $fwPartes = explode("\n", $fw);
    $svPartes = explode("\n", $sv);
    foreach ($fwPartes as $i => $p) {
        if (($svPartes[$i] ?? null) !== $p) {
            echo "  campo " . ($i + 1) . ": fw='" . $p . "' vs sv='" . ($svPartes[$i] ?? '') . "'\n";
        }
    }
    echo "\n";
}

if ($fallos > 0) {
    fwrite(STDERR, "FALLO: $fallos caso(s) con cadena canonica divergente\n");
    exit(1);
}
echo "OK: firmware y servidor construyen la misma cadena canonica\n";
