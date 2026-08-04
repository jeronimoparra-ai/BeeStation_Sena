<?php
/**
 * includes/auth.php
 * Autenticación real contra la tabla `usuario` (con password_hash).
 * Sin sistema de roles: todos los usuarios tienen el mismo acceso.
 */
session_start();
require_once __DIR__ . '/../config/db.php';

$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['id_usuario']) && $current_page !== 'login.php') {
    header("Location: login.php");
    exit;
}

function is_active(string $page_name): string {
    global $current_page;
    return $current_page === $page_name ? 'active' : '';
}

/** Intenta autenticar contra la base de datos real */
function intentarLogin(string $correo, string $clave): ?array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT usuario.*, rol.nombre_rol, rol.nivel_acceso
        FROM usuario
        JOIN rol ON usuario.id_rol = rol.id_rol
        WHERE usuario.correo = ?
        LIMIT 1
    ");
    $stmt->execute([$correo]);
    $user = $stmt->fetch();

    if ($user && password_verify($clave, $user['contrasena'])) {
        return $user;
    }
    return null;
}
