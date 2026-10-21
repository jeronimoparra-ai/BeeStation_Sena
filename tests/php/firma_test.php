<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/firma.php';

function comprobar(bool $condicion, string $mensaje): void {
    if (!$condicion) {
        fwrite(STDERR, "FALLO: {$mensaje}\n");
        exit(1);
    }
}

$ahora = 1792588800;
comprobar(firmaTimestampValido((string) $ahora, $ahora), 'acepta hora sincronizada');
comprobar(firmaTimestampValido((string) ($ahora - 300), $ahora), 'acepta límite de ventana');
comprobar(!firmaTimestampValido('0', $ahora), 'rechaza timestamp cero');
comprobar(!firmaTimestampValido('1577836799', $ahora), 'rechaza hora previa al mínimo');
comprobar(!firmaTimestampValido((string) ($ahora - 301), $ahora), 'rechaza solicitud expirada');
comprobar(!firmaTimestampValido('abc', $ahora), 'rechaza timestamp no numérico');

$nonce = '0123456789abcdef0123456789abcdef';
comprobar(firmaNonceValido($nonce), 'acepta nonce hexadecimal de 128 bits');
comprobar(!firmaNonceValido('0123456789abcdef'), 'rechaza nonce corto');
comprobar(!firmaNonceValido(str_repeat('z', 32)), 'rechaza nonce no hexadecimal');

$canonica = firmaCanonica('post', '/api/ingest.php', (string) $ahora, 'AA:BB:CC:DD:EE:FF', $nonce, '{}');
$esperada = "POST\n/api/ingest.php\n1792588800\nAA:BB:CC:DD:EE:FF\n{$nonce}\n" . hash('sha256', '{}');
comprobar(hash_equals($esperada, $canonica), 'cadena canónica incluye MAC, nonce y hash del cuerpo');

echo "OK: pruebas de firma, marca de tiempo, nonce y canonicalización\n";
