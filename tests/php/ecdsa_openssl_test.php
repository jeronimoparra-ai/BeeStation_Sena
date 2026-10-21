<?php
declare(strict_types=1);

function comprobarEcdsa(bool $condicion, string $mensaje): void {
    if (!$condicion) {
        fwrite(STDERR, "FALLO: {$mensaje}\n");
        exit(1);
    }
}

$private = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
]);
if ($private === false) {
    while ($opensslError = openssl_error_string()) {
        fwrite(STDERR, "OpenSSL: {$opensslError}\n");
    }
}
comprobarEcdsa($private !== false, 'OpenSSL genera clave P-256');

$details = openssl_pkey_get_details($private);
$public = openssl_pkey_get_public($details['key'] ?? '');
$message = "BeeStation request signature test";
$signature = '';
comprobarEcdsa($public !== false, 'OpenSSL lee clave pública PEM');
comprobarEcdsa(openssl_sign($message, $signature, $private, OPENSSL_ALGO_SHA256), 'OpenSSL firma con SHA-256');
comprobarEcdsa(openssl_verify($message, $signature, $public, OPENSSL_ALGO_SHA256) === 1, 'OpenSSL verifica firma ECDSA P-256');
comprobarEcdsa(openssl_verify($message . 'x', $signature, $public, OPENSSL_ALGO_SHA256) === 0, 'OpenSSL rechaza mensaje alterado');

echo "OK: OpenSSL ECDSA P-256/SHA-256 firma y verificación\n";
