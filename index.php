<?php
/**
 * index.php
 * ----------------------------------------------------------------
 * Punto de entrada del aplicativo.
 *
 * Antes este archivo cerraba la sesión (era un clon de logout.php),
 * de modo que entrar por la raíz expulsaba al usuario al login.
 * Ahora redirige al panel si hay sesión activa y a la landing pública
 * (landing.php) si no la hay.
 * ----------------------------------------------------------------
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

if (!empty($_SESSION['id_usuario'])) {
    header('Location: dashboard.php');
} else {
    header('Location: landing.php');
}
exit;
