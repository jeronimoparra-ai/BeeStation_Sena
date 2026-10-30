<?php
/**
 * tools/restablecer-clave.php
 * ----------------------------------------------------------------
 * Restablece la contraseña de una cuenta existente desde la CLI.
 *
 * No hay forma de "leer" una contraseña: en la base solo vive el
 * hash bcrypt. Este script genera un hash nuevo con password_hash()
 * y lo guarda en la cuenta indicada.
 *
 * Uso (PowerShell):
 *   $env:BEESTATION_RESET_EMAIL    = "admin@beestation.io"
 *   $env:BEESTATION_RESET_PASSWORD = "una-clave-de-12-o-mas"
 *   php tools/restablecer-clave.php
 *
 * Solo funciona por CLI: nunca por HTTP.
 * ----------------------------------------------------------------
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/password.php';

$correo = strtolower(trim((string) getenv('BEESTATION_RESET_EMAIL')));
$clave  = (string) getenv('BEESTATION_RESET_PASSWORD');

if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL) || strlen($correo) > 150) {
    fwrite(STDERR, "Define BEESTATION_RESET_EMAIL con un correo electrónico válido.\n");
    exit(2);
}

$valClave = validarPasswordSegura($clave);
if (!$valClave['valida']) {
    fwrite(STDERR, "Error en contraseña: " . $valClave['error'] . "\n");
    exit(2);
}

try {
    $pdo = getPDO();
    $stmt = $pdo->prepare('UPDATE usuario SET contrasena = ? WHERE correo = ?');
    $stmt->execute([hashearPasswordSegura($clave), $correo]);

    if ($stmt->rowCount() !== 1) {
        fwrite(STDERR, "No existe una cuenta con ese correo. No se modificó nada.\n");
        exit(3);
    }

    echo "Clave restablecida para {$correo}.\n";
} catch (Throwable $e) {
    error_log('BeeStation: fallo al restablecer la clave.');
    fwrite(STDERR, "No se pudo restablecer la clave.\n");
    exit(1);
}
