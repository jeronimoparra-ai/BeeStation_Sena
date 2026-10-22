<?php
/**
 * informe.php
 * ----------------------------------------------------------------
 * Informe imprimible de una colmena: KPIs bioclimaticos, lecturas
 * actuales, graficas de las variables medidas, estado de los sensores
 * y alertas activas.
 *
 * El boton "Descargar PDF" usa la impresion del navegador (A4), de
 * modo que no hace falta ninguna libreria PDF en el servidor:
 *   - css/print.css define la maquetacion de papel;
 *   - las graficas son canvas de Chart.js, que el navegador imprime
 *     como imagen sin perdida de nitidez.
 * ----------------------------------------------------------------
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$idColmena = 0;
$colmena    = null;
if (!empty($_SESSION['id_usuario'])) {
    $colmena = obtenerColmenaActiva((int) $_SESSION['id_usuario']);
    if ($colmena) {
        $idColmena = (int) $colmena['id_colmena'];
    }
}

/**
 * Unidades reales de cada variable, leidas del catalogo
 * `variable_bioclimatica`. No se escriben a mano: si el catalogo cambia
 * (por ejemplo, el sonido pasa de Hz a dBFS), el informe lo refleja.
 */
$unidades = [];
if ($idColmena) {
    $stmtU = getPDO()->prepare("
        SELECT v.nombre, v.unidad_medida
        FROM variable_bioclimatica v
        INNER JOIN sensor s ON s.id_variable = v.id_variable
        WHERE s.id_colmena = ?
        GROUP BY v.id_variable
    ");
    $stmtU->execute([$idColmena]);
    foreach ($stmtU->fetchAll() as $u) {
        $unidades[$u['nombre']] = $u['unidad_medida'];
    }
}
$unidadDe = fn(string $tipo, string $porDefecto = '') => $unidades[$tipo] ?? $porDefecto;

// ── Periodo del informe (parametro ?dias=) ─────────────────────────────
$dias = isset($_GET['dias']) ? max(1, min(90, (int) $_GET['dias'])) : 7;
$horas = $dias * 24;

// ── Datos ──────────────────────────────────────────────────────────────
$temp   = $idColmena ? ultimaLectura($idColmena, 'temperatura_interna') : null;
$hum    = $idColmena ? ultimaLectura($idColmena, 'humedad_relativa') : null;
$peso   = $idColmena ? ultimaLectura($idColmena, 'peso') : null;
$sonido = $idColmena ? ultimaLectura($idColmena, 'sonido') : null;
$pres   = $idColmena ? ultimaLectura($idColmena, 'presion') : null;
$luz    = $idColmena ? ultimaLectura($idColmena, 'luminosidad') : null;

$tempFresca   = lecturaReciente($temp, 5);
$humFresca    = lecturaReciente($hum, 5);
$pesoFresco   = lecturaReciente($peso, 5);
$sonidoFresco = lecturaReciente($sonido, 5);

$flujo = $idColmena ? calcularFlujoDiarioPeso($idColmena) : null;

$sensores = $idColmena
    ? array_values(array_filter(
        sensoresOperativos($idColmena),
        fn($s) => esTipoSensorNodoBasico((string) $s['tipo'])
    ))
    : [];
$alertas  = $idColmena ? alertasActivas($idColmena, 20) : [];

// Series agregadas (cubos de 15 min / 1 dia): manejables en memoria
$serieTemp  = $idColmena ? serieHistoricaAgregada($idColmena, 'temperatura_interna', min($horas, 48), 15) : [];
$seriePeso  = $idColmena ? serieHistoricaAgregada($idColmena, 'peso', $horas, $dias > 3 ? 1440 : 60) : [];
$serieSonid = $idColmena ? serieHistoricaAgregada($idColmena, 'sonido', min($horas, 12), 5) : [];
$serieHum   = $idColmena ? serieHistoricaAgregada($idColmena, 'humedad_relativa', min($horas, 48), 60) : [];

$online  = $idColmena ? dispositivoConectado($idColmena, 5) : false;
$ultimo  = $idColmena ? ultimaConexion($idColmena) : null;
$generado = date('d/m/Y H:i');

/** Formatea un valor con su unidad. */
function fmtVal(?array $lectura, string $unidad, int $dec = 2): string {
    if (!$lectura) {
        return '—';
    }
    return number_format((float) $lectura['valor_calibrado'], $dec) . ' ' . $unidad;
}

/** Etiqueta de una serie agregada. */
function fmtSerie(array $filas, string $formato): array {
    return array_map(fn($r) => date($formato, strtotime($r['fecha_hora'])), $filas);
}

// Valores de cada serie, listos para el JSON del navegador
$valoresTemp  = array_map(fn($r) => (float) $r['valor'], $serieTemp);
$valoresHum   = array_map(fn($r) => (float) $r['valor'], $serieHum);
$valoresPeso  = array_map(fn($r) => (float) $r['valor'], $seriePeso);
$valoresSonid = array_map(fn($r) => (float) $r['valor'], $serieSonid);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Informe BeeStation · <?= $colmena ? htmlspecialchars($colmena['nombre']) : 'Sin colmena' ?></title>
<link rel="stylesheet" href="css/style-premium.css">
<link rel="stylesheet" href="css/informe.css">
<link rel="stylesheet" href="css/print.css">
<script src="assets/vendor/lucide.min.js"></script>
<script src="assets/vendor/chart.umd.min.js"></script>
</head>
<body class="informe-body">

<div class="informe-toolbar no-print">
    <a href="dashboard.php" class="btn-informe-volver"><i data-lucide="arrow-left"></i> Volver al panel</a>
    <div class="informe-toolbar-acciones">
        <label class="informe-rango">
            Periodo:
            <select id="informeDias" onchange="location.href='informe.php?dias=' + this.value">
                <?php foreach ([1, 3, 7, 15, 30] as $d): ?>
                    <option value="<?= $d ?>" <?= $d === $dias ? 'selected' : '' ?>><?= $d ?> días</option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn-informe-pdf" onclick="window.print()">
            <i data-lucide="file-down"></i> Descargar PDF
        </button>
    </div>
</div>

<div class="informe-doc">

    <!-- ── Portada ─────────────────────────────────────────────────── -->
    <header class="informe-cabecera">
        <div class="informe-marca">
            <img src="assets/logo-beestation.png" alt="BeeStation">
            <div>
                <div class="informe-marca-nombre">BeeStation</div>
                <div class="informe-marca-sub">Sistema IoT apícola · informe de datos</div>
            </div>
        </div>
        <div class="informe-meta">
            <div><span>Colmena</span><strong><?= $colmena ? htmlspecialchars($colmena['nombre']) : '—' ?></strong></div>
            <div><span>Especie</span><strong><?= $colmena ? htmlspecialchars($colmena['especie']) : '—' ?></strong></div>
            <div><span>Periodo</span><strong>Últimos <?= $dias ?> días</strong></div>
            <div><span>Generado</span><strong><?= $generado ?></strong></div>
        </div>
    </header>

    <?php if (!$colmena): ?>
        <div class="informe-vacio">Selecciona una colmena en el panel para generar su informe.</div>
    <?php else: ?>

    <!-- ── 1. Resumen ejecutivo ───────────────────────────────────── -->
    <section class="informe-bloque">
        <h2 class="informe-titulo">1 · Resumen de la colmena</h2>
        <div class="informe-kpis">
            <div class="informe-kpi">
                <span class="informe-kpi-label">Temperatura interna</span>
                <strong class="informe-kpi-valor"><?= $temp ? number_format((float) $temp['valor_calibrado'], 1) . ' °C' : '—' ?></strong>
                <em class="informe-kpi-estado"><?= $temp ? ($tempFresca ? 'dato reciente' : 'dato antiguo') : 'sin datos' ?></em>
            </div>
            <div class="informe-kpi">
                <span class="informe-kpi-label">Humedad relativa</span>
                <strong class="informe-kpi-valor"><?= $hum ? number_format((float) $hum['valor_calibrado'], 0) . ' % HR' : '—' ?></strong>
                <em class="informe-kpi-estado"><?= $hum ? ($humFresca ? 'dato reciente' : 'dato antiguo') : 'sin datos' ?></em>
            </div>
            <div class="informe-kpi">
                <span class="informe-kpi-label">Peso de la colmena</span>
                <strong class="informe-kpi-valor"><?= $peso ? number_format((float) $peso['valor_calibrado'], 2) . ' kg' : '—' ?></strong>
                <em class="informe-kpi-estado"><?= $peso ? ($pesoFresco ? 'dato reciente' : 'dato antiguo') : 'sin datos' ?></em>
            </div>
            <div class="informe-kpi">
                <span class="informe-kpi-label">Actividad acústica <small>(experimental)</small></span>
                <strong class="informe-kpi-valor"><?= $sonido ? number_format((float) $sonido['valor_calibrado'], 0) . ' Hz' : '—' ?></strong>
                <em class="informe-kpi-estado"><?= $sonido ? ($sonidoFresco ? 'estimación, no diagnóstico' : 'dato antiguo') : 'sin datos' ?></em>
            </div>
            <div class="informe-kpi">
                <span class="informe-kpi-label">Flujo de peso (24 h)</span>
                <strong class="informe-kpi-valor"><?= $flujo !== null ? number_format($flujo, 2) . ' kg' : 'Sin datos' ?></strong>
                <em class="informe-kpi-estado"><?= $flujo !== null ? 'en 24 horas · HX711 sin calibrar' : 'sin datos recientes' ?></em>
            </div>
            <div class="informe-kpi">
                <span class="informe-kpi-label">Estado del nodo</span>
                <strong class="informe-kpi-valor"><?= $online ? 'En línea' : 'Sin señal' ?></strong>
                <em class="informe-kpi-estado"><?= $ultimo ? htmlspecialchars(tiempoRelativo($ultimo)) : 'sin datos' ?></em>
            </div>
        </div>
        <p class="informe-nota">IBB, EV, ΔT y H_miel no se incluyen: requieren CO₂, temperatura exterior o una medición directa de humedad de miel que este nodo no tiene. No se presentan como diagnóstico.</p>
    </section>

    <!-- ── 2. Lecturas actuales ───────────────────────────────────── -->
    <section class="informe-bloque">
        <h2 class="informe-titulo">2 · Última lectura de cada variable</h2>
        <p class="informe-nota">Solo se listan las seis variables del nodo básico. CO₂, temperatura exterior y energía no están instalados; si quedaron filas de pruebas anteriores en la base, no se presentan aquí. Un guion (—) significa que no hay lectura.</p>
        <div class="table-wrap">
        <table class="informe-tabla">
            <thead>
                <tr><th>Variable</th><th>Valor</th><th>Momento de medición</th></tr>
            </thead>
            <tbody>
                <?php
                $lecturas = [
                    'Temperatura interna'  => [$temp,   $unidadDe('temperatura_interna', '°C'),   2],
                    'Humedad relativa'     => [$hum,    $unidadDe('humedad_relativa', '%HR'),    1],
                    'Presión atmosférica' => [$pres,   $unidadDe('presion', 'hPa'),            1],
                    'Peso'                => [$peso,   $unidadDe('peso', 'kg'),                2],
                    'Frecuencia acústica' => [$sonido, $unidadDe('sonido', 'Hz'),              2],
                    'Luminosidad'         => [$luz,    $unidadDe('luminosidad', 'lux'),        1],
                ];
                foreach ($lecturas as $nombre => [$l, $unidadVar, $dec]):
                    ?>
                    <tr>
                        <td><?= $nombre ?></td>
                        <td class="informe-td-valor"><?= $l ? number_format((float) $l['valor_calibrado'], $dec) . ' ' . $unidadVar : '—' ?></td>
                        <td class="informe-td-fecha"><?= $l ? htmlspecialchars(date('d/m/Y H:i:s', strtotime($l['fecha_hora']))) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>

    <!-- ── 3. Gráficas ────────────────────────────────────────────── -->
    <section class="informe-bloque">
        <h2 class="informe-titulo">3 · Evolución de las variables medidas</h2>
        <div class="informe-graficas">
            <div class="informe-grafica">
                <div class="informe-grafica-titulo">Temperatura interna (°C) · últimas 48 h</div>
                <div class="informe-canvas"><canvas id="infTemp"></canvas></div>
            </div>
            <div class="informe-grafica">
                <div class="informe-grafica-titulo">Humedad relativa (%HR) · últimas 48 h</div>
                <div class="informe-canvas"><canvas id="infHum"></canvas></div>
            </div>
            <div class="informe-grafica">
                <div class="informe-grafica-titulo">Peso de la colmena (kg) · últimos <?= $dias ?> días</div>
                <div class="informe-canvas"><canvas id="infPeso"></canvas></div>
            </div>
            <div class="informe-grafica">
                <div class="informe-grafica-titulo">Frecuencia acústica estimada (<?= htmlspecialchars($unidadDe('sonido', 'Hz')) ?>) · últimas 12 h</div>
                <div class="informe-canvas"><canvas id="infSonido"></canvas></div>
            </div>
        </div>
    </section>

    <!-- ── 4. Sensores ────────────────────────────────────────────── -->
    <section class="informe-bloque">
        <h2 class="informe-titulo">4 · Inventario de sensores</h2>
        <div class="table-wrap">
        <table class="informe-tabla">
            <thead>
                <tr><th>Sensor</th><th>Modelo</th><th>Estado</th><th>Última lectura</th><th>Momento</th></tr>
            </thead>
            <tbody>
                <?php
                $etiquetasSensor = [
                    'temperatura_interna' => 'Temperatura interna',
                    'humedad_relativa'    => 'Humedad relativa',
                    'presion'             => 'Presión',
                    'peso'                => 'Peso',
                    'sonido'              => 'Frecuencia acústica',
                    'luminosidad'         => 'Luminosidad',
                ];
                foreach ($sensores as $s):
                    $estadoReal = (string) $s['estado_real'];
                    $nombreSensor = $etiquetasSensor[$s['tipo']] ?? ucfirst(str_replace('_', ' ', (string) $s['tipo']));
                    $modeloSensor = modeloFisicoNodoBasico((string) $s['tipo']) ?? $s['modelo'];
                    $estadoTexto = [
                        'en_linea'    => 'En línea',
                        'advertencia' => 'Advertencia',
                        'sin_senal'   => 'Sin datos recientes',
                    ][$estadoReal] ?? $estadoReal;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($nombreSensor) ?></td>
                        <td><?= htmlspecialchars($modeloSensor) ?></td>
                        <td><span class="informe-estado <?= htmlspecialchars($estadoReal) ?>"><?= htmlspecialchars($estadoTexto) ?></span></td>
                        <td><?= $s['ultima_lectura'] !== null ? number_format((float) $s['ultima_lectura'], 2) . ' ' . htmlspecialchars($s['unidad_medida'] ?? '') : '—' ?></td>
                        <td class="informe-td-fecha"><?= $s['ultima_fecha'] ? htmlspecialchars(date('d/m/Y H:i', strtotime($s['ultima_fecha']))) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>

    <!-- ── 5. Alertas ────────────────────────────────────────────── -->
    <section class="informe-bloque">
        <h2 class="informe-titulo">5 · Alertas activas</h2>
        <?php if (count($alertas) > 0): ?>
            <div class="table-wrap">
            <table class="informe-tabla">
                <thead><tr><th>Tipo</th><th>Nivel</th><th>Mensaje</th><th>Momento</th></tr></thead>
                <tbody>
                    <?php foreach ($alertas as $a): ?>
                        <tr>
                            <td><?= htmlspecialchars($a['tipo']) ?></td>
                            <td><?= (int) $a['nivel'] ?></td>
                            <td><?= htmlspecialchars($a['mensaje']) ?></td>
                            <td class="informe-td-fecha"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($a['fecha_hora']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php else: ?>
            <p class="informe-nota">No hay alertas activas en este periodo.</p>
        <?php endif; ?>
    </section>

    <footer class="informe-pie">
        <div><strong>BeeStation</strong> · Informe generado automáticamente el <?= $generado ?></div>
        <div>Datos medidos por el nodo. La actividad acústica es experimental y no confirma enjambrazón; IBB, EV, ΔT y H_miel no se calculan con el hardware actual.</div>
    </footer>

    <?php endif; ?>
</div>

<?php if ($colmena): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Colores propios del informe: legibles en papel, independientes del tema
    const TINTA = '#16120C', MARCA = '#B97712', MALLOR = '#2E63D3', VERDE = '#11835C';
    const GRID = 'rgba(22,18,12,.10)';
    const fmt = (arr, f) => arr.map(r => r[f]);

    function serie(id, labels, datos, color, titulo, formatoY) {
        const el = document.getElementById(id);
        if (!el) return null;
        return new Chart(el.getContext('2d'), {
            type: 'line',
            data: { labels: labels, datasets: [{
                label: titulo, data: datos, borderColor: color,
                borderWidth: 1.8, tension: .3, pointRadius: 0, pointHoverRadius: 3,
                fill: true, backgroundColor: 'rgba(185,119,18,.10)'
            }]},
            options: {
                responsive: true, maintainAspectRatio: false, animation: false,
                devicePixelRatio: 2,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { color: TINTA, maxTicksLimit: 8, autoSkip: true, font: { size: 9 } } },
                    y: { ticks: { color: TINTA, font: { size: 9 } }, grid: { color: GRID } }
                }
            }
        });
    }

    const lTemp  = <?= json_encode(fmtSerie($serieTemp, 'd/m H:i')) ?>;
    const lHum   = <?= json_encode(fmtSerie($serieHum, 'd/m H:i')) ?>;
    const lPeso  = <?= json_encode(fmtSerie($seriePeso, 'd/m')) ?>;
    const lSonid = <?= json_encode(fmtSerie($serieSonid, 'H:i')) ?>;

    serie('infTemp',   lTemp,  <?= json_encode($valoresTemp) ?>,  MARCA,  'Temperatura', '°C');
    serie('infHum',    lHum,   <?= json_encode($valoresHum) ?>,  VERDE,  'Humedad', '%HR');
    serie('infPeso',   lPeso,  <?= json_encode($valoresPeso) ?>,  MALLOR, 'Peso', 'kg');
    serie('infSonido', lSonid, <?= json_encode($valoresSonid) ?>, MARCA, 'Sonido', 'Hz');

    if (window.lucide) { window.lucide.createIcons(); }
});
</script>
<?php endif; ?>

</body>
</html>
