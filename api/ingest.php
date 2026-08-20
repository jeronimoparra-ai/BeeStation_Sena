<?php
/**
 * api/ingest.php
 * ----------------------------------------------------------------
 * Endpoint al que el ESP32 (u otro dispositivo) envía los datos
 * REALES de los sensores. No hay datos de ejemplo aquí: cada
 * lectura que llega se guarda tal cual en la tabla `lectura`.
 *
 * Cómo lo usa el ESP32 (ejemplo de código Arduino más abajo en
 * README.txt): hace un POST con JSON a esta URL.
 *
 * Headers requeridos:
 *   Content-Type: application/json
 *   X-API-Key:    <BEESTATION_API_KEY definida en config/db.php>
 *
 * Formato esperado del body (JSON):
 * {
 *   "id_colmena": 1,
 *   "lecturas": [
 *     { "tipo": "temperatura_interna", "valor": 35.2 },
 *     { "tipo": "humedad_relativa",    "valor": 65.3 },
 *     { "tipo": "peso",                "valor": 28.52 },
 *     { "tipo": "sonido",              "valor": 280 },
 *     { "tipo": "co2",                 "valor": 2100 }
 *   ]
 * }
 * ----------------------------------------------------------------
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

// 1. Validar método HTTP (filtro de protocolo: descarta GET, PUT, DELETE, etc.)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido, usa POST']);
    exit;
}

// 2. Validar autenticación por API Key (filtro de seguridad)
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!hash_equals(BEESTATION_API_KEY, $apiKey)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);

if (!$body || !isset($body['id_colmena']) || !isset($body['lecturas']) || !is_array($body['lecturas'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido. Se requiere id_colmena y lecturas[]']);
    exit;
}

$id_colmena = (int) $body['id_colmena'];
$pdo = getPDO();

// Verificar que la colmena exista
$stmt = $pdo->prepare("SELECT id_colmena FROM colmena WHERE id_colmena = ?");
$stmt->execute([$id_colmena]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'La colmena indicada no existe']);
    exit;
}

$insertados = [];
$errores = [];

foreach ($body['lecturas'] as $l) {
    if (!isset($l['tipo'], $l['valor'])) {
        $errores[] = "Lectura incompleta: " . json_encode($l);
        continue;
    }

    $tipo  = trim($l['tipo']);
    $valor = (float) $l['valor'];

    // Buscar el sensor real de ese tipo, en esa colmena
    $stmtSensor = $pdo->prepare("
        SELECT s.id_sensor, s.rango_min, s.rango_max, v.unidad_medida,
            (SELECT c.factor_correccion FROM calibracion c
             WHERE c.id_sensor = s.id_sensor
             ORDER BY c.fecha_calibracion DESC LIMIT 1) AS factor_correccion
        FROM sensor s
        INNER JOIN variable_bioclimatica v ON s.id_variable = v.id_variable
        WHERE s.id_colmena = ? AND s.tipo = ?
        LIMIT 1
    ");
    $stmtSensor->execute([$id_colmena, $tipo]);
    $sensor = $stmtSensor->fetch();

    if (!$sensor) {
        $errores[] = "No existe un sensor tipo '$tipo' registrado para la colmena $id_colmena";
        continue;
    }

    $factor = $sensor['factor_correccion'] ?? 0;
    $valor_calibrado = $valor + (float) $factor;

    // Validar rango físico del sensor (si está definido)
    $es_valida = 1;
    if ($sensor['rango_min'] !== null && $sensor['rango_max'] !== null) {
        if ($valor_calibrado < $sensor['rango_min'] || $valor_calibrado > $sensor['rango_max']) {
            $es_valida = 0; // fuera de rango físico del sensor -> se guarda pero marcada inválida
        }
    }

    $stmtInsert = $pdo->prepare("
        INSERT INTO lectura (valor_bruto, valor_calibrado, unidad, fecha_hora, es_valida, id_sensor)
        VALUES (?, ?, ?, NOW(), ?, ?)
    ");
    $stmtInsert->execute([$valor, $valor_calibrado, $sensor['unidad_medida'], $es_valida, $sensor['id_sensor']]);

    // Marcar el sensor como en línea
    $pdo->prepare("UPDATE sensor SET estado = 'en_linea' WHERE id_sensor = ?")
        ->execute([$sensor['id_sensor']]);

    $insertados[] = ['tipo' => $tipo, 'valor_calibrado' => $valor_calibrado, 'valido' => (bool) $es_valida];

    // Evaluar alerta de enjambrazón acústica si la lectura es de tipo 'sonido' y está en rango crítico
    if ($tipo === 'sonido' && $es_valida && $valor_calibrado >= 400 && $valor_calibrado <= 600) {
        evaluarAlertaEnjambrazon($id_colmena, $valor_calibrado);
    }
}

// Después de insertar, recalcular el IBB real con los datos que ya existan
$ibb = calcularIBB($id_colmena);
if ($ibb !== null) {
    $pdo->prepare("
        INSERT INTO indicador (tipo, valor, fecha_hora, descripcion, estado_colonia, id_colmena)
        VALUES ('IBB', ?, NOW(), 'Índice de Bienestar Bioclimático calculado automáticamente', ?, ?)
    ")->execute([$ibb['valor'], $ibb['estado'], $id_colmena]);

    // Si el IBB es crítico, generar una alerta real
    if ($ibb['valor'] < 50) {
        $idIndicador = $pdo->lastInsertId();
        $nivel = $ibb['valor'] < 30 ? 3 : 2;
        $pdo->prepare("
            INSERT INTO alerta (tipo, nivel, mensaje, fecha_hora, estado, id_indicador)
            VALUES ('bienestar_bajo', ?, ?, NOW(), 'activa', ?)
        ")->execute([
            $nivel,
            "IBB en {$ibb['valor']} — estado {$ibb['estado']}. Revisar la colmena.",
            $idIndicador
        ]);
    }
}

$deltaT = calcularDeltaT($id_colmena);
if ($deltaT !== null) {
    $pdo->prepare("
        INSERT INTO indicador (tipo, valor, fecha_hora, descripcion, estado_colonia, id_colmena)
        VALUES ('DELTA_T', ?, NOW(), 'Diferencial de temperatura', ?, ?)
    ")->execute([$deltaT['valor'], $deltaT['estado'], $id_colmena]);
}

$ev = calcularEV($id_colmena);
if ($ev !== null) {
    $pdo->prepare("
        INSERT INTO indicador (tipo, valor, fecha_hora, descripcion, estado_colonia, id_colmena)
        VALUES ('EV', ?, NOW(), 'Eficiencia de Ventilación', ?, ?)
    ")->execute([$ev['valor'], $ev['estado'], $id_colmena]);
}

$hMiel = calcularHMiel($id_colmena);
if ($hMiel !== null) {
    $pdo->prepare("
        INSERT INTO indicador (tipo, valor, fecha_hora, descripcion, estado_colonia, id_colmena)
        VALUES ('H_MIEL', ?, NOW(), 'Humedad estimada de la miel', ?, ?)
    ")->execute([$hMiel['valor'], $hMiel['estado'], $id_colmena]);
}

echo json_encode([
    'ok' => true,
    'insertados' => $insertados,
    'errores' => $errores,
    'ibb_calculado' => $ibb,
    'delta_t' => $deltaT,
    'ev' => $ev,
    'h_miel' => $hMiel
]);
