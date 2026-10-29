<?php
/**
 * api/ingest.php
 * ----------------------------------------------------------------
 * Endpoint al que el ESP32 (u otro dispositivo) envía los datos
 * REALES de los sensores. No hay datos de ejemplo aquí: cada
 * lectura que llega se guarda tal cual en la tabla `lectura`.
 *
 * Headers requeridos (esquema actual, ECDSA):
 *   Content-Type: application/json
 *   X-BeeStation-Dispositivo: identificador del nodo (32 hex)
 *   X-BeeStation-Mac:         MAC del nodo (AA:BB:CC:DD:EE:FF)
 *   X-BeeStation-Timestamp:   epoch unix del nodo
 *   X-BeeStation-Nonce:       128 bits aleatorios en hex
 *   X-BeeStation-Firma:       firma ECDSA ASN.1 DER en hex
 *
 * La peticion se autentica con la FIRMA. La clave privada vive en el ESP32
 * y nunca viaja por la red. Ver includes/dispositivo.php y
 * docs/AUTENTICACION-Y-PORTAL.md.
 *
 * Se mantiene el esquema HMAC heredado (X-BeeStation-Signature) para los
 * nodos con firmware antiguo.
 *
 * Formato esperado del body (JSON):
 * {
 *   "lecturas": [
 *     { "tipo": "temperatura_interna", "valor": 35.2, "ts": 1792588800,
 *       "seq": 1792588800, "id_medicion": "a1b2...f0" }
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
require_once __DIR__ . '/../includes/firma.php';
require_once __DIR__ . '/../includes/dispositivo.php';

// ── 1. FILTRO DE PROTOCOLO: Validar método HTTP ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido, usa POST']);
    exit;
}

// ── 2. AUTENTICACIÓN DEL NODO ───────────────────────────────────────────────
//
//  Conviven dos esquemas durante la transición:
//
//    · ECDSA (nuevo): el nodo firma cada petición con su clave privada.
//      La identidad la determina el propio dispositivo y el servidor
//      comprueba que está autorizado y a qué colmena pertenece.
//
//    · HMAC (heredado): el nodo firma con el token de vinculación de la
//      colmena. Se conserva hasta que todos los nodos desplegados
//      estén migrados a ECDSA.
//
//  La presencia de la cabecera X-BeeStation-Dispositivo selecciona el
//  esquema; si no está, se usa el heredado.
// Mitigación de seguridad: limitar el tamaño del cuerpo antes de leerlo
// para evitar agotamiento de memoria con peticiones enormes.
$maxBytesCuerpo = 1024 * 1024; // 1 MiB
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > $maxBytesCuerpo) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'Cuerpo de peticion demasiado grande']);
    exit;
}
$cuerpoCrudo = file_get_contents('php://input', false, null, 0, $maxBytesCuerpo);
if ($cuerpoCrudo === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No se pudo leer el cuerpo de la peticion']);
    exit;
}
$rutaPeticion = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/';
$nodo = null;
$esquema = 'hmac';

if (trim((string) ($_SERVER['HTTP_X_BEESTATION_DISPOSITIVO'] ?? '')) !== '') {
    $esquema = 'ecdsa';
    $dispositivo = null;

    if (!dispositivoVerificarFirma('POST', $rutaPeticion, $cuerpoCrudo, false, $dispositivo)) {
        http_response_code(401);
        echo json_encode([
            'ok'      => false,
            'error'   => 'No autorizado. ' . dispositivoMotivoRechazo(),
            'motivo'  => (string) ($GLOBALS['dispositivo_motivo_rechazo'] ?? ''),
            'esquema' => 'ecdsa',
        ]);
        exit;
    }

    $nodo = [
        'id_colmena' => (int) $dispositivo['id_colmena'],
        'nombre'     => (string) ($dispositivo['nombre_colmena'] ?? ''),
        'mac'        => (string) $dispositivo['mac'],
    ];
    dispositivoRegistrarContacto((int) $dispositivo['id_dispositivo']);
} else {
    // El nodo no envía su token: firma la petición. El secreto nunca viaja
    // por la red y cada colmena se revoca por separado.
    if (!firmaVerificar('POST', $rutaPeticion, $cuerpoCrudo, $nodo)) {
        http_response_code(401);
        echo json_encode([
            'ok'    => false,
            'error' => 'No autorizado. ' . firmaMotivoRechazo(),
        ]);
        exit;
    }
    firmaRegistrarContacto((int) $nodo['id_colmena'], $nodo['mac']);
}

// La identidad de la colmena la determina la firma, no el cuerpo.
$id_colmena = (int) $nodo['id_colmena'];
$colmena    = ['nombre' => $nodo['nombre']];

// La identidad de la colmena la determina la firma, no el cuerpo.
$id_colmena = (int) $nodo['id_colmena'];
$colmena    = ['nombre' => $nodo['nombre']];

// ── 3. LECTURA Y DECODIFICACIÓN DEL PAYLOAD JSON ─────────────────────────────
$body = json_decode($cuerpoCrudo, true);

if (!$body || !isset($body['lecturas']) || !is_array($body['lecturas'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido. Se requiere lecturas[]']);
    exit;
}

$ip_origen = $_SERVER['REMOTE_ADDR'] ?? null;
$pdo = getPDO();

// Lote opcional: identifica un envío (útil para trazar y deduplicar)
$lote = isset($body['lote']) ? substr(trim((string) $body['lote']), 0, 64) : null;

// ── 4. REGISTRO DEL CONTACTO DEL NODO ────────────────────────────────────────
registrarIntentoVinculacion($nodo['mac'], $ip_origen, 'ok');

// Arrays para reportar el resultado de la transacción al ESP32
$insertados = [];
$errores = [];
$rechazadas = 0;

// Acuse POR LECTURA: el nodo necesita saber, una a una, qué lecturas se
// han guardado. Con ese dato puede borrar de la cola offline de la microSD
// exactamente lo que el servidor confirma, y conservar lo que no.
//   insertada          -> guardada y válida
//   insertada_no_valida-> guardada pero marcada es_valida = 0 (fuera de rango)
//   duplicada          -> ya existía (reenvío idempotente)
//   rechazada          -> NO se ha guardado
$resultados = [];
$indice = 0;

// ── 5. PROCESAMIENTO E INSERCIÓN INDIVIDUAL DE SENSORES ──────────────────────
// Tope de seguridad: un cliente con un bug (o malicioso) no puede volcar
// cientos de miles de lecturas en una sola petición.
$MAX_LECTURAS = 200;
if (count($body['lecturas']) > $MAX_LECTURAS) {
    http_response_code(413);
    echo json_encode([
        'ok'    => false,
        'error' => "Demasiadas lecturas en una peticion (maximo $MAX_LECTURAS)",
    ]);
    exit;
}

$stmtSensor = $pdo->prepare("SELECT id_sensor, precision_valor, rango_min, rango_max FROM sensor WHERE id_colmena = ? AND tipo = ? LIMIT 1");

foreach ($body['lecturas'] as $l) {
    $indiceActual = $indice++;

    // Identificador estable de la medición. Lo genera el nodo en el momento
    // de TOMAR la lectura y viaja con ella incluso cuando la lectura se
    // reenvía más tarde desde la cola offline de la microSD.
    $idMedicion = null;
    if (isset($l['id_medicion'])) {
        $candidato = strtolower(trim((string) $l['id_medicion']));
        if (preg_match('/^[0-9a-f]{32}$/', $candidato)) {
            $idMedicion = $candidato;
        }
    }

    if (!isset($l['tipo']) || !array_key_exists('valor', $l)) {
        $errores[] = 'Lectura incompleta: se requiere tipo y valor';
        $rechazadas++;
        $resultados[] = ['indice' => $indiceActual, 'id_medicion' => $idMedicion, 'estado' => 'rechazada'];
        continue;
    }

    $tipo = trim((string) $l['tipo']);
    $valorBruto = $l['valor'];

    // ── Validación estricta del valor ──
    // Un valor no numérico se convertía antes en 0.0 y se guardaba como
    // lectura VÁLIDA, falseando el IBB y disparando alertas críticas.
    if (is_bool($valorBruto) || !is_numeric($valorBruto)) {
        $errores[] = "Valor no numerico en '{$tipo}'";
        $rechazadas++;
        $resultados[] = ['indice' => $indiceActual, 'id_medicion' => $idMedicion, 'estado' => 'rechazada'];
        continue;
    }
    $valor = (float) $valorBruto;
    if (!is_finite($valor)) {
        $errores[] = "Valor no finito en '{$tipo}'";
        $rechazadas++;
        $resultados[] = ['indice' => $indiceActual, 'id_medicion' => $idMedicion, 'estado' => 'rechazada'];
        continue;
    }

    // Buscamos si existe un sensor de este tipo registrado para nuestra colmena
    $stmtSensor->execute([$id_colmena, $tipo]);
    $sensor = $stmtSensor->fetch();

    if (!$sensor) {
        $errores[] = "El sensor de tipo '{$tipo}' no se encuentra registrado para esta colmena en la plataforma";
        $rechazadas++;
        $resultados[] = ['indice' => $indiceActual, 'id_medicion' => $idMedicion, 'estado' => 'rechazada'];
        continue;
    }

    $id_sensor = (int) $sensor['id_sensor'];
    $precision = $sensor['precision_valor'];

    // ── Rango físico declarado del sensor ──
    // Una lectura fuera de rango se conserva pero marcada como inválida
    // (es_valida = 0): no alimenta los cálculos ni las gráficas, pero no
    // se pierde la evidencia.
    $es_valida = 1;
    $rango_min = $sensor['rango_min'];
    $rango_max = $sensor['rango_max'];
    if ($rango_min !== null && $rango_max !== null
        && ($valor < (float) $rango_min || $valor > (float) $rango_max)) {
        $es_valida = 0;
        $errores[] = "Valor fuera del rango declarado del sensor '{$tipo}' ("
            . rtrim(rtrim(number_format((float) $rango_min, 2, '.', ''), '0'), '.') . " a "
            . rtrim(rtrim(number_format((float) $rango_max, 2, '.', ''), '0'), '.') . "): $valor";
    }

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

    // Marca de tiempo y número de secuencia opcionales (habilitan el reenvío
    // offline con fecha real de medición e inserción idempotente).
    // Mitigación de seguridad: validar rangos para evitar fechas inválidas
    // (negativas o demasiado futuras) y secuencias no positivas.
    $ts  = isset($l['ts'])  ? (int) $l['ts']  : null;
    $seq = isset($l['seq']) ? (int) $l['seq'] : null;
    if ($ts !== null && ($ts <= 0 || $ts > time() + 86400)) {
        $ts = null;
    }
    if ($seq !== null && $seq <= 0) {
        $seq = null;
    }

    if ($ts !== null && $ts > 0) {
        $stmtIns = $pdo->prepare("
            INSERT INTO lectura (valor_bruto, valor_calibrado, es_valida, id_sensor, fecha_hora, seq, id_medicion)
            VALUES (?, ?, ?, ?, FROM_UNIXTIME(?), ?, ?)
            ON DUPLICATE KEY UPDATE id_lectura = id_lectura
        ");
        $stmtIns->execute([$valor, $valor_calibrado, $es_valida, $id_sensor, $ts, $seq, $idMedicion]);
    } else {
        $stmtIns = $pdo->prepare("
            INSERT INTO lectura (valor_bruto, valor_calibrado, es_valida, id_sensor, seq, id_medicion)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE id_lectura = id_lectura
        ");
        $stmtIns->execute([$valor, $valor_calibrado, $es_valida, $id_sensor, $seq, $idMedicion]);
    }

    // Duplicado: la lectura ya estaba (mismo id_medicion o mismo seq).
    // Se acusa como duplicada, NO como error: el reenvío offline es
    // legitimo y el nodo puede borrar esa fila de su cola.
    if ($stmtIns->rowCount() === 0) {
        $resultados[] = ['indice' => $indiceActual, 'id_medicion' => $idMedicion, 'estado' => 'duplicada'];
        continue;
    }

    // Actualizamos el estado del sensor físico en tiempo real a 'en_linea'
    $stmtUpd = $pdo->prepare("UPDATE sensor SET estado = 'en_linea' WHERE id_sensor = ?");
    $stmtUpd->execute([$id_sensor]);

    // La acústica es experimental y no se usa para diagnosticar enjambrazón.
    // Se conserva la lectura para análisis, pero no se generan alertas hasta
    // validar el método con grabaciones y una ventana temporal sostenida.

    $insertados[] = $tipo;
    $resultados[] = [
        'indice'      => $indiceActual,
        'id_medicion' => $idMedicion,
        'estado'      => $es_valida ? 'insertada' : 'insertada_no_valida',
    ];
}

// ── 5b. TRAZABILIDAD DEL LOTE ────────────────────────────────────────
// Cuando el envio corresponde a un lote de la microSD (reenvio offline
// o recogida fisica de la tarjeta), se registra que lote se ha
// recibido: asi se puede reconciliar lo que el nodo dice que ha
// subido con lo que el servidor ha recibido realmente.
if ($lote !== null && $lote !== '') {
    try {
        $pdo->prepare("
            INSERT INTO lote_ingesta (identificador, lote, id_colmena, filas_recibidas)
            VALUES (?, ?, ?, ?)
        ")->execute([$nodo['mac'], $lote, $id_colmena, count($insertados)]);
    } catch (Throwable $e) {
        // La trazabilidad nunca debe impedir la ingesta.
    }
}

// ── 6. RECALCULO Y PERSISTENCIA DE INDICADORES EN TIEMPO REAL ────────────────
// Solo recalcular si el lote aportó al menos una lectura nueva. Un reintento
// con todo duplicado no debe inflar `indicador` con filas idénticas.
$ibb = null;
$deltaT = null;
$ev = null;
$hMiel = null;

if (count($insertados) > 0) {
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
} // fin: solo si hubo lecturas nuevas

// ── 7. RESPUESTA EXITOSA AL CLIENTE (ESP32) ──────────────────────────────────
// `ok_total` distingue "todo se guardo" de "se guardo parcialmente":
// el firmware necesita esa señal para NO dar un envio por bueno cuando
// el backend ha rechazado parte de los datos.
echo json_encode([
    'ok'        => true,
    'ok_total'  => ($rechazadas === 0),
    'esquema'   => $esquema,
    'colmena'   => $colmena['nombre'],
    'insertados' => $insertados,
    'rechazadas' => $rechazadas,
    'errores'   => $errores,
    // Acuse por lectura: el nodo borra de su cola offline solo lo que el
    // servidor confirma (insertada, insertada_no_valida, duplicada).
    'resultados' => $resultados,
    'ibb_calculado' => $ibb,
    'delta_t'   => $deltaT,
    'ev'        => $ev,
    'h_miel'    => $hMiel
]);