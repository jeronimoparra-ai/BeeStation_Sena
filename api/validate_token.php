<?php
/**
 * api/validate_token.php
 * ----------------------------------------------------------------
 * Endpoint que el nodo ESP32 llama para comprobar que su token de
 * vinculación es correcto tras aprovisionarlo en el portal cautivo.
 *
 * NO se envía el token: el nodo firma la petición con HMAC-SHA256 y
 * el servidor verifica la firma contra el secreto de cada colmena.
 * Ver includes/firma.php para el detalle del esquema de firma.
 *
 * Cabeceras requeridas:
 *   X-BeeStation-Mac, X-BeeStation-Timestamp, X-BeeStation-Signature
 *
 * Cuerpo: opcional ({}). El campo "token" se acepta por compatibilidad
 * pero se IGNORA: el secreto no debe viajar por la red.
 * ----------------------------------------------------------------
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';

// ── Carga segura de funciones sin activar redirección de sesión ──
// functions.php redirige a login.php si no hay sesión activa,
// lo cual rompe las llamadas API. Definimos la variable que lo evita.
define('BEESTATION_API_CONTEXT', true);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/firma.php';

// ── 1. Validar método HTTP ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido, usa POST']);
    exit;
}

// ── 2. Autenticar el nodo por firma ─────────────────────────────
$cuerpoCrudo = file_get_contents('php://input');
$rutaPeticion = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/';
$nodo = null;

if (!firmaVerificar('POST', $rutaPeticion, $cuerpoCrudo, $nodo)) {
    registrarIntentoVinculacion(
        firmaMacNormalizada($_SERVER['HTTP_X_BEESTATION_MAC'] ?? null),
        $_SERVER['REMOTE_ADDR'] ?? null,
        'token_invalido'
    );
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => firmaMotivoRechazo()]);
    exit;
}

// ── 3. Nodo autenticado ─────────────────────────────────────────
firmaRegistrarContacto((int) $nodo['id_colmena'], $nodo['mac']);

echo json_encode([
    'ok'             => true,
    'nombre_colmena' => $nodo['nombre'],
    'mac'            => $nodo['mac'],
]);