<?php
/**
 * api/validate_token.php
 * ----------------------------------------------------------------
 * Endpoint de pre-validación que el ESP32 llama antes de enviar
 * datos completos. Verifica que el token de vinculación sea válido
 * y no haya sido revocado.
 *
 * Headers requeridos:
 *   Content-Type: application/json
 *   X-API-Key:    <BEESTATION_API_KEY definida en config/db.php>
 *
 * Body JSON esperado:
 *   { "token": "BS-ALPHA01-1A2B3C" }
 * ----------------------------------------------------------------
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';

// ── Carga segura de funciones sin activar redirección de sesión ──
// functions.php redirige a login.php si no hay sesión activa,
// lo cual rompe las llamadas API. Definimos la variable que lo evita.
define('BEESTATION_API_CONTEXT', true);
require_once __DIR__ . '/../includes/functions.php';

// ── 1. Validar método HTTP ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido, usa POST']);
    exit;
}

// ── 2. Validar API Key ──────────────────────────────────────────
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!hash_equals(BEESTATION_API_KEY, $apiKey)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado. API Key inválida o ausente']);
    exit;
}

// ── 3. Leer y validar token ─────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
$token = trim($body['token'] ?? '');
$ip = $_SERVER['REMOTE_ADDR'] ?? null;

if ($token === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Token requerido']);
    exit;
}

// ── 4. Resolver colmena por token ───────────────────────────────
$colmena = resolverColmenaPorToken($token);

if (!$colmena) {
    registrarIntentoVinculacion($token, $ip, 'token_invalido');
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Token no registrado o revocado']);
    exit;
}

// ── 5. Token válido: registrar éxito ────────────────────────────
registrarIntentoVinculacion($token, $ip, 'ok');
reiniciarIntentosFallidosToken((int)$colmena['id_colmena']);

echo json_encode([
    'ok' => true,
    'nombre_colmena' => $colmena['nombre']
]);