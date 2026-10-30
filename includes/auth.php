<?php
/**
 * includes/auth.php
 * Autenticación real contra la tabla `usuario` (con password_hash).
 * Sin sistema de roles: todos los usuarios tienen el mismo acceso.
 *
 * Las funciones is_active() e intentarLogin() viven en functions.php
 * para evitar redeclaraciones fatales cuando ambos archivos se incluyen.
 */
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function tokenCsrfSesion(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf'];
}

function validarCsrfSesion(mixed $token): bool {
    return isset($_SESSION['csrf']) && is_string($token) && hash_equals((string) $_SESSION['csrf'], $token);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/password.php';
require_once __DIR__ . '/functions.php';
