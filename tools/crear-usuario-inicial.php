<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/password.php';

$nombre = trim((string) getenv('BEESTATION_BOOTSTRAP_NAME'));
$correo = strtolower(trim((string) getenv('BEESTATION_BOOTSTRAP_EMAIL')));
$clave = (string) getenv('BEESTATION_BOOTSTRAP_PASSWORD');

if (mb_strlen($nombre, 'UTF-8') < 2 || mb_strlen($nombre, 'UTF-8') > 100 || !filter_var($correo, FILTER_VALIDATE_EMAIL) || strlen($correo) > 150) {
    fwrite(STDERR, "Configura BEESTATION_BOOTSTRAP_NAME (2-100 caracteres) y BEESTATION_BOOTSTRAP_EMAIL válido.\n");
    exit(2);
}

$valClave = validarPasswordSegura($clave);
if (!$valClave['valida']) {
    fwrite(STDERR, "Error en contraseña: " . $valClave['error'] . "\n");
    exit(2);
}

try {
    $pdo = getPDO();
    $check = $pdo->prepare('SELECT 1 FROM usuario WHERE correo = ? LIMIT 1');
    $check->execute([$correo]);
    if ($check->fetchColumn()) {
        fwrite(STDERR, "Ya existe una cuenta con ese correo. No se modificó ninguna cuenta.\n");
        exit(3);
    }

    $insert = $pdo->prepare('INSERT INTO usuario (nombre, correo, contrasena) VALUES (?, ?, ?)');
    $insert->execute([$nombre, $correo, hashearPasswordSegura($clave)]);
    fwrite(STDOUT, "Cuenta inicial creada. Elimina las variables de entorno de este proceso.\n");
} catch (Throwable $e) {
    error_log('BeeStation: initial account provisioning failed.');
    fwrite(STDERR, "No se pudo crear la cuenta inicial. Comprueba el esquema y la conexión.\n");
    exit(1);
} finally {
    unset($clave);
}
