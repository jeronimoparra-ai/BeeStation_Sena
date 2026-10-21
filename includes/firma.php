<?php
/**
 * includes/firma.php
 * ----------------------------------------------------------------
 * Verificacion de peticiones firmadas por el nodo ESP32.
 *
 * DISENO (sustituye a la antigua API Key global):
 *   - Cada colmena tiene su propio secreto (token de vinculacion).
 *   - El nodo NO envia el secreto: firma la peticion con HMAC-SHA256.
 *   - El secreto nunca viaja por la red, asi que no puede snatcharse
 *     con un sniffer pasivo en la LAN.
 *   - No existe una clave global en el firmware: cada nodo se revoca
 *     por separado, sin reflashear el resto.
 *   - La marca de tiempo dentro de la firma impide reutilizar una
 *     peticion capturada (anti-replay).
 *
 * Cabeceras requeridas en cada peticion del nodo:
 *   X-BeeStation-Mac        MAC del ESP32  (AA:BB:CC:DD:EE:FF)
 *   X-BeeStation-Timestamp  epoch unix sincronizado del nodo
 *   X-BeeStation-Nonce      128 bits aleatorios, hexadecimal
 *   X-BeeStation-Signature  HMAC-SHA256 en hexadecimal
 *
 * Cadena canonica firmada (debe ser IDENTICA en el nodo):
 *   METODO \n RUTA \n TIMESTAMP \n MAC \n NONCE \n sha256_hex(cuerpo)
 *
 *   - METODO en mayusculas
 *   - RUTA  = ruta de la peticion SIN query string
 *             (p.ej. /BeeStation_Sena/api/ingest.php)
 *   - TIMESTAMP = el epoch tal cual viaja en la cabecera
 *   - cuerpo = cuerpo crudo de la peticion (vacio si no hay)
 * ----------------------------------------------------------------
 */

if (!defined('BEESTATION_API_CONTEXT')) {
    define('BEESTATION_API_CONTEXT', true);
}

/** Ventana de validez de la marca de tiempo, en segundos. */
const FIRMA_VENTANA_SEGUNDOS = 300;
const FIRMA_TIMESTAMP_MINIMO = 1577836800;

function firmaTimestampValido(string $timestamp, ?int $ahora = null): bool {
    if (!preg_match('/^\d{10,12}$/', $timestamp) || (int) $timestamp < FIRMA_TIMESTAMP_MINIMO) {
        return false;
    }
    $ahora ??= time();
    return abs($ahora - (int) $timestamp) <= FIRMA_VENTANA_SEGUNDOS;
}

function firmaNonceValido(string $nonce): bool {
    return (bool) preg_match('/^[0-9a-f]{32}$/i', $nonce);
}

/**
 * Construye la cadena canonica que se firma.
 */
function firmaCanonica(string $metodo, string $ruta, string $timestamp, string $mac, string $nonce, string $cuerpo): string {
    return strtoupper($metodo) . "\n"
         . $ruta . "\n"
         . $timestamp . "\n"
         . $mac . "\n"
         . strtolower($nonce) . "\n"
         . hash('sha256', $cuerpo);
}

/**
 * Calcula la firma de una cadena canonica con el secreto de la colmena.
 */
function firmaCalcular(string $secreto, string $canonica): string {
    return hash_hmac('sha256', $canonica, $secreto);
}

/**
 * Normaliza y valida una direccion MAC. Devuelve null si no es valida.
 */
function firmaMacNormalizada(?string $mac): ?string {
    if ($mac === null) {
        return null;
    }
    $mac = strtoupper(trim($mac));
    if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
        return null;
    }
    return $mac;
}

/**
 * Verifica la firma de la peticion actual.
 *
 * @param string      $metodo  Metodo HTTP de la peticion
 * @param string      $ruta    Ruta sin query string
 * @param string      $cuerpo  Cuerpo crudo de la peticion
 * @param array|null  $nodo    Salida: fila de colmena autenticada
 *
 * @return bool  true si la peticion es genuina
 */
function firmaVerificar(string $metodo, string $ruta, string $cuerpo, ?array &$nodo = null): bool {
    $nodo = null;
    $GLOBALS['firma_motivo_rechazo'] = '';

    $mac = firmaMacNormalizada($_SERVER['HTTP_X_BEESTATION_MAC'] ?? null);
    $ts  = trim((string) ($_SERVER['HTTP_X_BEESTATION_TIMESTAMP'] ?? ''));
    $nonce = strtolower(trim((string) ($_SERVER['HTTP_X_BEESTATION_NONCE'] ?? '')));
    $sig = strtolower(trim((string) ($_SERVER['HTTP_X_BEESTATION_SIGNATURE'] ?? '')));

    if ($mac === null || $ts === '' || $nonce === '' || $sig === '') {
        return false;
    }
    if (!preg_match('/^\d{10,12}$/', $ts) || (int) $ts < FIRMA_TIMESTAMP_MINIMO) {
        $GLOBALS['firma_motivo_rechazo'] = 'hora_invalida';
        return false;
    }
    if (!firmaNonceValido($nonce)) {
        $GLOBALS['firma_motivo_rechazo'] = 'nonce_invalido';
        return false;
    }
    if (!preg_match('/^[0-9a-f]{64}$/', $sig)) {
        return false;
    }

    // Ventana temporal: evita reutilizar una peticion capturada.
    if (!firmaTimestampValido($ts)) {
        $GLOBALS['firma_motivo_rechazo'] = 'timestamp_expirado';
        return false;
    }

    $canonica = firmaCanonica($metodo, $ruta, $ts, $mac, $nonce, $cuerpo);

    // Se prueban los secretos de las colmenas activas hasta que una firma
    // coincide. El numero de colmenas es reducido y cada HMAC es de
    // microsegundos, por lo que el coste es irrelevante frente al resto
    // del proceso. Con muchos nodos, este es el punto a cachear.
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT id_colmena, nombre, token_vinculacion
        FROM colmena
        WHERE token_revocado = 0
          AND token_vinculacion IS NOT NULL
          AND token_vinculacion <> ''
    ");
    $stmt->execute();

    foreach ($stmt->fetchAll() as $c) {
        // El secreto se normaliza a mayusculas: asi da igual como se
        // haya escrito o pegado el token (el ESP32 tambien lo hace).
        $esperada = firmaCalcular(strtoupper((string) $c['token_vinculacion']), $canonica);
        if (hash_equals($esperada, $sig)) {
            // UNIQUE(mac, nonce) hace atómica la comprobación anti-replay.
            // Solo los nodos con firma válida pueden ocupar un nonce.
            try {
                // Un nonce de más de 10 minutos ya no puede acompañar una
                // solicitud dentro de la ventana de firma de 5 minutos.
                $pdo->exec("DELETE FROM nonce_peticion WHERE creado_en < (NOW() - INTERVAL 10 MINUTE)");
                $pdo->prepare('INSERT INTO nonce_peticion (mac, nonce, creado_en) VALUES (?, ?, NOW())')
                    ->execute([$mac, $nonce]);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    $GLOBALS['firma_motivo_rechazo'] = 'nonce_reutilizado';
                    return false;
                }
                throw $e;
            }
            $nodo = [
                'id_colmena' => (int) $c['id_colmena'],
                'nombre'     => $c['nombre'],
                'mac'        => $mac,
            ];
            return true;
        }
    }

    return false;
}

/**
 * Registra el contacto del nodo (MAC y ultimo contacto) para poder
 * mostrarlo en el panel y detectar un token usado desde otro equipo.
 */
function firmaRegistrarContacto(int $id_colmena, string $mac): void {
    try {
        $pdo = getPDO();
        $pdo->prepare("
            UPDATE colmena
            SET nodo_mac = ?, nodo_ultimo_contacto = NOW()
            WHERE id_colmena = ?
        ")->execute([$mac, $id_colmena]);
    } catch (Throwable $e) {
        // El registro de contacto es informativo: nunca debe tumbar la ingesta.
    }
}

/**
 * Explica al nodo por que se rechazo su peticion.
 */
function firmaMotivoRechazo(): string {
    $motivo = $GLOBALS['firma_motivo_rechazo'] ?? '';
    if ($motivo === 'timestamp_expirado') {
        return 'La marca de tiempo del nodo está fuera de la ventana permitida. Sincroniza la hora y reintenta.';
    }
    if ($motivo === 'hora_invalida') {
        return 'El nodo debe sincronizar la hora antes de enviar solicitudes.';
    }
    if ($motivo === 'nonce_reutilizado') {
        return 'Solicitud repetida. Genera una prueba nueva para reintentar.';
    }
    if ($motivo === 'nonce_invalido') {
        return 'Solicitud no válida.';
    }
    $mac = firmaMacNormalizada($_SERVER['HTTP_X_BEESTATION_MAC'] ?? null);
    if ($mac === null) {
        return 'Faltan las cabeceras de autenticación requeridas.';
    }
    $ts = (int) ($_SERVER['HTTP_X_BEESTATION_TIMESTAMP'] ?? 0);
    if ($ts > 0 && abs(time() - $ts) > FIRMA_VENTANA_SEGUNDOS) {
        return 'La marca de tiempo del nodo esta fuera de la ventana permitida. Revisa la hora del ESP32 (NTP).';
    }
    return 'Firma no valida: el token de vinculacion no corresponde o el nodo esta revocado';
}
