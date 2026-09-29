<?php
/**
 * api/ingest.php
 * ----------------------------------------------------------------
 * Endpoint al que el ESP32 (u otro dispositivo) envía los datos
 * REALES de los sensores. No hay datos de ejemplo aquí: cada
 * lectura que llega se guarda tal cual en la tabla `lectura`.
 *
 * Headers requeridos:
 *   Content-Type: application/json
 *   X-API-Key:    <BEESTATION_API_KEY definida en config/db.php>
 *
 * Formato esperado del body (JSON):
 * {
 *   "token": "BS-ALPHA01-1A2B3C",
 *   "lecturas": [
 *     { "tipo": "temperatura_interna", "valor": 35.2 },
 *     { "tipo": "humedad_relativa",    "valor": 65.3 },
 *     { "tipo": "peso",                "valor": 28.52 },
 *     { "tipo": "sonido",              "valor": 280 },
 *     { "tipo": "co2",                 "valor": 2100 }
 *   ]
 * }
 * ----------------------------------------------------------------
 *
 * BeeStation — API de Ingesta de Telemetría IoT
 * Centro Minero Ambiental - SENA, El Bagre (Antioquia)
 *
 * Este archivo recibe de forma asíncrona las lecturas del ESP32 en JSON,
 * valida el Token de Vinculación contra la tabla `colmena`, registra
 * cada intento (exitoso o fallido) en `intento_vinculacion` para
 * diagnóstico en conectar_dispositivo.php, aplica calibraciones de
 * sensores, almacena las lecturas en MySQL y recalcula los índices
 * bioclimáticos.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';

// Definir contexto API antes de cargar functions.php para evitar
// la redirección a login.php (que solo aplica a páginas web)
define('BEESTATION_API_CONTEXT', true);
require_once __DIR__ . '/../includes/functions.php';

// ── 1. FILTRO DE PROTOCOLO: Validar método HTTP ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido, usa POST']);
    exit;
}

// ── 2. FILTRO DE SEGURIDAD: Validar API Key global de BeeStation ─────────────
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!hash_equals(BEESTATION_API_KEY, $apiKey)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado. API Key inválida o ausente']);
    exit;
}

// ── 3. LECTURA Y DECODIFICACIÓN DEL PAYLOAD JSON ─────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);

if (!$body || !isset($body['token']) || !isset($body['lecturas']) || !is_array($body['lecturas'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido. Se requiere token alfanumérico y lecturas[]']);
    exit;
}

$token = trim($body['token']);
$ip_origen = $_SERVER['REMOTE_ADDR'] ?? null;
$pdo = getPDO();

// ── 4. RESOLUCIÓN SEGURA DEL ID DE COLMENA EN BACKEND ────────────────────────
$colmena = resolverColmenaPorToken($token);

// Si el token no existe o fue revocado, se registra el intento y se rechaza
if (!$colmena) {
    registrarIntentoVinculacion($token, $ip_origen, 'token_invalido');
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'El token de vinculación proporcionado no está registrado o fue revocado']);
    exit;
}

$id_colmena = (int)$colmena['id_colmena'];

// Registramos el intento exitoso y reiniciamos el contador de fallos del token
registrarIntentoVinculacion($token, $ip_origen, 'ok');
reiniciarIntentosFallidosToken($id_colmena);

// Arrays para reportar el resultado de la transacción al ESP32
$insertados = [];
$errores = [];

// ── 5. PROCESAMIENTO E INSERCIÓN INDIVIDUAL DE SENSORES ──────────────────────
foreach ($body['lecturas'] as $l) {
    if (!isset($l['tipo'], $l['valor'])) {
        $errores[] = "Lectura incompleta o mal formateada: " . json_encode($l);
        continue;
    }

    $tipo = trim($l['tipo']);
    $valor = (float)$l['valor'];

    // Buscamos si existe un sensor de este tipo registrado para nuestra colmena
    $stmtSensor = $pdo->prepare("SELECT id_sensor, precision_valor FROM sensor WHERE id_colmena = ? AND tipo = ? LIMIT 1");
    $stmtSensor->execute([$id_colmena, $tipo]);
    $sensor = $stmtSensor->fetch();

    if (!$sensor) {
        $errores[] = "El sensor de tipo '{$tipo}' no se encuentra registrado para esta colmena en la plataforma";
        continue;
    }

    $id_sensor = (int)$sensor['id_sensor'];
    $precision = $sensor['precision_valor'];

    // Buscamos la última calibración realizada a este sensor para corregir el valor
    $stmtCal = $pdo->prepare("SELECT factor_correccion FROM calibracion WHERE id_sensor = ? ORDER BY id_calibracion DESC LIMIT 1");
    $stmtCal->execute([$id_sensor]);
    $cal = $stmtCal->fetch();
    $factor = $cal ? (float)$cal['factor_correccion'] : 0.0;

    // Aplicamos el factor de corrección bioclimático al valor bruto del ESP32
    $valor_calibrado = $valor + $factor;

    // Si el sensor tiene una precisión definida en la base de datos, redondeamos el valor
    if ($precision !== null) {
        $valor_calibrado = round($valor_calibrado, 2);
    }

    // Insertamos la lectura validada y calibrada en el histórico
    $stmtIns = $pdo->prepare("INSERT INTO lectura (valor_bruto, valor_calibrado, es_valida, id_sensor) VALUES (?, ?, 1, ?)");
    $stmtIns->execute([$valor, $valor_calibrado, $id_sensor]);

    // Actualizamos el estado del sensor físico en tiempo real a 'en_linea'
    $stmtUpd = $pdo->prepare("UPDATE sensor SET estado = 'en_linea' WHERE id_sensor = ?");
    $stmtUpd->execute([$id_sensor]);

    // ALERTA TEMPRANA DE ENJAMBRAZÓN: Evaluamos acústica (MAX9814) en la banda de 400 a 600 Hz
    if ($tipo === 'sonido') {
        evaluarAlertaEnjambrazon($id_colmena, $valor_calibrado);
    }

    $insertados[] = $tipo;
}

// ── 6. RECALCULO Y PERSISTENCIA DE INDICADORES EN TIEMPO REAL ────────────────
$ibb = calcularIBB($id_colmena);
if ($ibb !== null) {
    $stmtIbb = $pdo->prepare("
        INSERT INTO indicador (tipo, valor, fecha_hora, descripcion, estado_colonia, id_colmena)
        VALUES ('IBB', ?, NOW(), 'Índice de Bienestar Bioclimático calculado automáticamente', ?, ?)
    ");
    $stmtIbb->execute([$ibb['valor'], $ibb['estado'], $id_colmena]);

    // DISPARADOR DE ALERTAS CRÍTICAS: Si el IBB general cae por debajo de 50%, creamos una alerta
    if ($ibb['valor'] < 50) {
        $id_indicador = $pdo->lastInsertId();

        // Evitamos saturar al apicultor de alertas duplicadas en las últimas 4 horas
        $stmtCheck = $pdo->prepare("
            SELECT COUNT(*) AS total 
            FROM alerta al
            INNER JOIN indicador i ON al.id_indicador = i.id_indicador
            WHERE i.id_colmena = ? 
              AND al.tipo = 'IBB_BAJO' 
              AND al.estado = 'activa' 
              AND al.fecha_hora >= (NOW() - INTERVAL 4 HOUR)
        ");
        $stmtCheck->execute([$id_colmena]);
        if ($stmtCheck->fetch()['total'] == 0) {
            $stmtAlerta = $pdo->prepare("
                INSERT INTO alerta (tipo, nivel, mensaje, id_indicador) 
                VALUES ('IBB_BAJO', 2, ?, ?)
            ");
            $mensajeAlerta = "¡Atención! El Índice de Bienestar Bioclimático general ha bajado a un nivel crítico ({$ibb['valor']}% - Estado: {$ibb['estado']}). Revisa las condiciones internas de la colmena.";
            $stmtAlerta->execute([$mensajeAlerta, $id_indicador]);
        }
    }
}

// Diferencial de temperatura interior/exterior (Delta T)
$deltaT = calcularDeltaT($id_colmena);
if ($deltaT !== null) {
    $pdo->prepare("
        INSERT INTO indicador (tipo, valor, fecha_hora, descripcion, estado_colonia, id_colmena)
        VALUES ('DELTA_T', ?, NOW(), 'Diferencial de temperatura', ?, ?)
    ")->execute([$deltaT['valor'], $deltaT['estado'], $id_colmena]);
}

// Eficiencia de Ventilación (EV)
$ev = calcularEV($id_colmena);
if ($ev !== null) {
    $pdo->prepare("
        INSERT INTO indicador (tipo, valor, fecha_hora, descripcion, estado_colonia, id_colmena)
        VALUES ('EV', ?, NOW(), 'Eficiencia de Ventilación', ?, ?)
    ")->execute([$ev['valor'], $ev['estado'], $id_colmena]);
}

// Humedad estimada de la miel (H_miel)
$hMiel = calcularHMiel($id_colmena);
if ($hMiel !== null) {
    $pdo->prepare("
        INSERT INTO indicador (tipo, valor, fecha_hora, descripcion, estado_colonia, id_colmena)
        VALUES ('H_MIEL', ?, NOW(), 'Humedad estimada de la miel', ?, ?)
    ")->execute([$hMiel['valor'], $hMiel['estado'], $id_colmena]);
}

// ── 7. RESPUESTA EXITOSA AL CLIENTE (ESP32) ──────────────────────────────────
echo json_encode([
    'ok' => true,
    'colmena' => $colmena['nombre'],
    'insertados' => $insertados,
    'errores' => $errores,
    'ibb_calculado' => $ibb,
    'delta_t' => $deltaT,
    'ev' => $ev,
    'h_miel' => $hMiel
]);