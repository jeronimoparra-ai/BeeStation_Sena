<?php
/**
 * api/estado.php
 *
 * Devuelve en JSON el estado FRESCO de la colmena activa: últimas
 * lecturas con la hora exacta de subida (HH:MM:SS), alertas,
 * indicadores bioclimáticos y series de 24 h.
 *
 * Lo consume js/actualizar-dashboard.js cada 10 s para que la
 * dashboard se refresque sola sin recargar la página.
 */
require_once __DIR__ . '/../includes/auth.php';

// XAMPP trae serialize_precision=100 y json_encode imprime 0.47 como
// 0.46999999999999997… ; con -1 sale el número limpio.
ini_set('serialize_precision', '-1');

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id_usuario'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'no_autenticado']);
    exit;
}

$colmenaActiva = obtenerColmenaActiva((int) $_SESSION['id_usuario']);
if (!$colmenaActiva) {
    echo json_encode(['ok' => false, 'error' => 'sin_colmena']);
    exit;
}

$idColmena = (int) $colmenaActiva['id_colmena'];

/**
 * Empaqueta una lectura con su valor, su fecha y la hora exacta en
 * la que el nodo la subió.
 */
function empaquetarLectura(?array $lectura): ?array
{
    if (!$lectura) {
        return null;
    }
    return [
        'valor'    => round((float) $lectura['valor_calibrado'], 3),
        'fecha'    => $lectura['fecha_hora'],
        'subida'   => horaSubida($lectura['fecha_hora']),
        'relativo' => tiempoRelativo($lectura['fecha_hora']),
    ];
}

$alertas = alertasActivas($idColmena, 10);

$serieTemp = serieHistorica($idColmena, 'temperatura_interna', 24);
$seriePeso = serieHistorica($idColmena, 'peso', 24);

$respuesta = [
    'ok'     => true,
    'hora'   => date('H:i:s'),
    'online' => dispositivoConectado($idColmena),
    'lecturas' => [
        'temp'   => empaquetarLectura(ultimaLectura($idColmena, 'temperatura_interna')),
        'hum'    => empaquetarLectura(ultimaLectura($idColmena, 'humedad_relativa')),
        'peso'   => empaquetarLectura(ultimaLectura($idColmena, 'peso')),
        'sonido' => empaquetarLectura(ultimaLectura($idColmena, 'sonido')),
    ],
    'flujo'       => calcularFlujoDiarioPeso($idColmena),
    'ibb'         => calcularIBB($idColmena),
    'delta_t'     => calcularDeltaT($idColmena),
    'h_miel'      => calcularHMiel($idColmena),
    'ev'          => calcularEV($idColmena),
    'alertas_total' => count($alertas),
    'alertas'     => array_map(static function (array $a): array {
        $nivel = (int) $a['nivel'];
        return [
            'tipo'     => $a['tipo'],
            'mensaje'  => $a['mensaje'],
            'nivel'    => $nivel,
            'subida'   => horaSubida($a['fecha_hora']),
            'relativo' => tiempoRelativo($a['fecha_hora']),
            'punto'    => $nivel >= 3 ? 'bg-critical' : ($nivel == 2 ? 'bg-warning' : 'brand-dot'),
        ];
    }, $alertas),
    'serie_temp' => [
        'labels' => array_map(static fn($r) => date('H:i', strtotime($r['fecha_hora'])), $serieTemp),
        'data'   => array_map(static fn($r) => (float) $r['valor_calibrado'], $serieTemp),
    ],
    'serie_peso' => [
        'labels' => array_map(static fn($r) => date('H:i', strtotime($r['fecha_hora'])), $seriePeso),
        'data'   => array_map(static fn($r) => (float) $r['valor_calibrado'], $seriePeso),
    ],
];

echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);
