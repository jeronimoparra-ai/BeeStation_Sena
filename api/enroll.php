<?php
/**
 * api/enroll.php
 * ----------------------------------------------------------------
 * Alta de un nodo ESP32 con identidad criptografica propia (ECDSA P-256).
 *
 * Sustituye al "pegar el token de vinculacion en el portal". El nodo:
 *
 *   1. Genera su par de claves en el propio ESP32 (la clave privada
 *      nunca sale del dispositivo).
 *   2. Genera un codigo corto de un solo uso para que el usuario pueda
 *      identificarlo en el panel (el panel lo pide, no lo acepta).
 *   3. Envia su clave PUBLICA y una prueba de posesion: firma con su
 *      clave privada una cadena que incluye la huella de su clave
 *      publica, su identificador, su MAC, la hora y un nonce.
 *
 * El servidor verifica esa firma con la clave publica recibida: eso
 * demuestra que quien llama posee la clave privada. No hace falta ningun
 * secreto compartido ni ninguna intervencion del usuario en este paso.
 *
 * El nodo queda PENDIENTE hasta que un usuario autenticado lo autoriza
 * y lo asocia a una colmena (ver dispositivos.php). Hasta entonces el
 * nodo no puede enviar telemetria.
 *
 * Cabeceras: ninguna (el alta es el unico punto sin autenticar, y la
 * prueba de posesion lo protege).
 *
 * Cuerpo JSON:
 *   {
 *     "identificador": "32 hex",
 *     "mac":          "AA:BB:CC:DD:EE:FF",
 *     "nombre":       "Nodo colmena Norte",
 *     "clave_publica": "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----",
 *     "codigo":       "ABCD-EFGH",
 *     "timestamp":    1730000000,
 *     "nonce":        "32 hex",
 *     "firma":        "firma ECDSA DER en hexadecimal"
 *   }
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

// ── 2. Leer y validar el cuerpo ─────────────────────────────────
$cuerpoCrudo = file_get_contents('php://input');
$body = json_decode((string) $cuerpoCrudo, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Cuerpo JSON inválido']);
    exit;
}

$identificador = strtolower(trim((string) ($body['identificador'] ?? '')));
$mac          = firmaMacNormalizada((string) ($body['mac'] ?? ''));
$nombre       = trim((string) ($body['nombre'] ?? ''));
$clavePublica = (string) ($body['clave_publica'] ?? '');
$codigo       = strtoupper(trim((string) ($body['codigo'] ?? '')));
$timestamp    = trim((string) ($body['timestamp'] ?? ''));
$nonce        = strtolower(trim((string) ($body['nonce'] ?? '')));
$firma        = strtolower(trim((string) ($body['firma'] ?? '')));
$ip           = $_SERVER['REMOTE_ADDR'] ?? null;

if ($mac === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'MAC inválida']);
    exit;
}

// ── 3. Límite de frecuencia ─────────────────────────────────────
if (dispositivoExcesoDeIntentos($mac, $ip)) {
    http_response_code(429);
    echo json_encode([
        'ok'    => false,
        'error' => 'Demasiados intentos de alta. Espera unos minutos y reintenta.',
    ]);
    exit;
}

// ── 4. Registrar el alta con prueba de posesión ──────────────────
$resultado = dispositivoRegistrarAlta(
    $identificador,
    $mac,
    $clavePublica,
    $codigo,
    $nombre,
    $timestamp,
    $nonce,
    $firma
);

if (!$resultado['ok']) {
    registrarIntentoVinculacion($mac, $ip, 'alta_fallida');

    $mensajes = [
        'identificador_invalido'    => 'Identificador de dispositivo inválido.',
        'timestamp_expirado'        => 'La marca de tiempo está fuera de la ventana permitida. Sincroniza la hora del ESP32.',
        'nonce_invalido'            => 'Nonce inválido.',
        'firma_invalida'            => 'Firma con formato inválido.',
        'codigo_invalido'           => 'Código de alta inválido.',
        'clave_publica_invalida'    => 'La clave pública no es una clave ECDSA P-256 válida.',
        'prueba_posesion_invalida'  => 'La prueba de posesión no valida: el nodo no demuestra poseer su clave privada.',
        'nonce_reutilizado'         => 'Solicitud repetida. Genera una prueba nueva para reintentar.',
    ];
    http_response_code(401);
    echo json_encode([
        'ok'    => false,
        'error' => $mensajes[$resultado['error']] ?? 'No se pudo registrar el nodo.',
        'motivo' => $resultado['error'],
    ]);
    exit;
}

$dispositivo = $resultado['dispositivo'];
dispositivoRegistrarContacto((int) $dispositivo['id_dispositivo']);
registrarIntentoVinculacion($mac, $ip, 'alta_ok');

// ── 5. Responder con el estado de autorización ───────────────────
$autorizado = $dispositivo['estado'] === DISPOSITIVO_ESTADO_AUTORIZADO;

echo json_encode([
    'ok'             => true,
    'estado'         => $dispositivo['estado'],
    'autorizado'     => $autorizado,
    'id_colmena'     => $dispositivo['id_colmena'] !== null ? (int) $dispositivo['id_colmena'] : null,
    'nombre_colmena' => $dispositivo['nombre_colmena'] ?? null,
    'mensaje'        => $autorizado
        ? 'Nodo autorizado.'
        : 'Nodo registrado. Espera a que un usuario lo autorice en el panel.',
]);
