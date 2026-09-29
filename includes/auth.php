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
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';
