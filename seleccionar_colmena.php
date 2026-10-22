<?php
/**
 * seleccionar_colmena.php
 *
 * Fija en sesión la colmena activa elegida por el usuario.
 * - Solo acepta POST.
 * - Valida CSRF.
 * - Comprueba que la colmena pertenece al usuario (evita IDOR).
 * - Redirige únicamente a páginas conocidas.
 *
 * No modifica el contrato de la API de ingesta.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$id_usuario = (int) ($_SESSION['id_usuario'] ?? 0);
$id_colmena = (int) ($_POST['id_colmena'] ?? 0);

// Validación CSRF
$csrf = (string) ($_POST['csrf'] ?? '');
if (empty($_SESSION['csrf_colmena']) || !hash_equals((string) $_SESSION['csrf_colmena'], $csrf)) {
    http_response_code(403);
    exit('Solicitud no válida');
}

if ($id_usuario <= 0 || $id_colmena <= 0) {
    header('Location: dashboard.php');
    exit;
}

// Validar que la colmena pertenece al usuario (evita IDOR)
$pdo = getPDO();
$stmt = $pdo->prepare("
    SELECT c.id_colmena
    FROM colmena c
    INNER JOIN apiario a ON c.id_apiario = a.id_apiario
    WHERE c.id_colmena = ? AND a.id_usuario = ?
    LIMIT 1
");
$stmt->execute([$id_colmena, $id_usuario]);

if (!$stmt->fetch()) {
    http_response_code(403);
    exit('Colmena no autorizada');
}

$_SESSION['id_colmena_activa'] = $id_colmena;

// Redirección segura: solo páginas conocidas
$permitidas = [
    'dashboard.php',
    'sensores.php',
    'peso.php',
    'acustica.php',
    'dispositivos.php',
    'conectar_dispositivo.php',
];
$volver = (string) ($_POST['volver'] ?? 'dashboard.php');
if (!in_array($volver, $permitidas, true)) {
    $volver = 'dashboard.php';
}

header('Location: ' . $volver);
exit;
