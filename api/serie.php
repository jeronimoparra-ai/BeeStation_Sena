<?php
/**
 * api/serie.php
 * Historical series for the periods permitted by the UI.
 */
define('BEESTATION_API_CONTEXT', true);
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function responderSerie(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!isset($_SESSION['id_usuario'])) {
    responderSerie(401, ['ok' => false, 'error' => 'no_autenticado']);
}

$periodos = [
    'temperatura_interna' => [24, 48, 168, 720],
    'sonido' => [6, 24, 48, 168, 720],
    'peso' => [720],
];
$tipo = isset($_GET['tipo']) && is_string($_GET['tipo']) ? $_GET['tipo'] : '';
$horas = isset($_GET['horas']) && is_string($_GET['horas'])
    ? filter_var($_GET['horas'], FILTER_VALIDATE_INT)
    : false;

if (!isset($periodos[$tipo]) || $horas === false || !in_array($horas, $periodos[$tipo], true)) {
    responderSerie(400, ['ok' => false, 'error' => 'parametros_invalidos']);
}

$colmenaActiva = obtenerColmenaActiva((int) $_SESSION['id_usuario']);
if (!$colmenaActiva) {
    $bucket = $tipo === 'peso' ? 1440 : ($tipo === 'temperatura_interna'
        ? ($horas <= 48 ? 15 : ($horas <= 168 ? 60 : 180))
        : ($horas <= 48 ? 5 : ($horas <= 168 ? 30 : 60)));
    responderSerie(200, [
        'ok' => true, 'error' => 'sin_colmena', 'tipo' => $tipo, 'horas' => $horas,
        'bucket_min' => $bucket, 'labels' => [], 'data' => [], 'muestras' => [],
        'ultima_muestra' => [], 'ultima_lectura' => null, 'ultima_invalida' => null,
    ]);
}

$idColmena = (int) $colmenaActiva['id_colmena'];
$minutosCubo = match ($tipo) {
    'peso' => 1440,
    'temperatura_interna' => $horas <= 48 ? 15 : ($horas <= 168 ? 60 : 180),
    'sonido' => $horas <= 48 ? 5 : ($horas <= 168 ? 30 : 60),
};
$serie = serieHistoricaAgregada($idColmena, $tipo, $horas, $minutosCubo);
$ultima = ultimaLectura($idColmena, $tipo);
$ultimaFecha = $ultima['fecha_hora'] ?? null;
$ultimaTimestamp = $ultimaFecha ? strtotime((string) $ultimaFecha) : false;
$stmtInvalida = getPDO()->prepare("
    SELECT l.fecha_hora
    FROM lectura l
    INNER JOIN sensor s ON l.id_sensor = s.id_sensor
    WHERE s.id_colmena = ? AND s.tipo = ? AND l.es_valida = 0
    ORDER BY l.fecha_hora DESC
    LIMIT 1
");
$stmtInvalida->execute([$idColmena, $tipo]);
$invalida = $stmtInvalida->fetch();
$invalidaTimestamp = $invalida ? strtotime((string) $invalida['fecha_hora']) : false;

responderSerie(200, [
    'ok' => true,
    'tipo' => $tipo,
    'horas' => $horas,
    'bucket_min' => $minutosCubo,
    'colmena_id' => $idColmena,
    'labels' => array_map(static fn(array $p): string => $tipo === 'peso'
        ? date('d/m/Y', strtotime((string) $p['fecha_hora']))
        : date('d/m/Y H:i', strtotime((string) $p['fecha_hora'])), $serie),
    'data' => array_map(static fn(array $p): float => (float) $p['valor'], $serie),
    'muestras' => array_map(static fn(array $p): int => (int) $p['muestras'], $serie),
    'ultima_muestra' => array_map(static fn(array $p): string => date('H:i:s', strtotime((string) $p['ultima_muestra'])), $serie),
    'ultima_lectura' => $ultimaTimestamp === false ? null : [
        'fecha' => date('d/m/Y H:i', $ultimaTimestamp),
        'fuera_periodo' => $ultimaTimestamp < time() - ($horas * 3600),
    ],
    'ultima_invalida' => $invalidaTimestamp === false ? null : [
        'fecha' => date('d/m/Y H:i', $invalidaTimestamp),
        'fuera_periodo' => $invalidaTimestamp < time() - ($horas * 3600),
    ],
]);
