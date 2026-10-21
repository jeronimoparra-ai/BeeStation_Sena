<?php
/**
 * api/enroll_estado.php
 * ----------------------------------------------------------------
 * Consulta periodica que hace el nodo para saber si ya ha sido
 * autorizado (y a que colmena pertenece).
 *
 * Se autentica con la MISMA firma ECDSA que usa la telemetria, pero se
 * admite tambien el estado "pendiente": el nodo necesita poder preguntar
 * por su propia situacion antes de tener permiso para hacer nada mas.
 *
 * Cabeceras:
 *   X-BeeStation-Dispositivo, X-BeeStation-Mac, X-BeeStation-Timestamp,
 *   X-BeeStation-Nonce, X-BeeStation-Firma
 * ----------------------------------------------------------------
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';

define('BEESTATION_API_CONTEXT', true);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/firma.php';
require_once __DIR__ . '/../includes/dispositivo.php';

// ── 1. Validar método HTTP ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido, usa POST']);
    exit;
}

// ── 2. Autenticar al nodo por firma ECDSA ───────────────────────
$cuerpoCrudo = file_get_contents('php://input');
$rutaPeticion = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/';
$dispositivo = null;

if (!dispositivoVerificarFirma('POST', $rutaPeticion, $cuerpoCrudo, true, $dispositivo)) {
    http_response_code(401);
    echo json_encode([
        'ok'     => false,
        'error'  => dispositivoMotivoRechazo(),
        'motivo' => $GLOBALS['dispositivo_motivo_rechazo'] ?? '',
    ]);
    exit;
}

dispositivoRegistrarContacto((int) $dispositivo['id_dispositivo']);

$autorizado = $dispositivo['estado'] === DISPOSITIVO_ESTADO_AUTORIZADO;

echo json_encode([
    'ok'             => true,
    'estado'         => $dispositivo['estado'],
    'autorizado'     => $autorizado,
    'id_colmena'     => $dispositivo['id_colmena'] !== null ? (int) $dispositivo['id_colmena'] : null,
    'nombre_colmena' => $dispositivo['nombre_colmena'] ?? null,
]);
