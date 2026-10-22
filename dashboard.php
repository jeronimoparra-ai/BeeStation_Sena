<?php include 'includes/header.php'; ?>

<?php if (!$colmenaActiva): ?>

    <div class="card empty-state">
        <div class="empty-icon-circle"><i data-lucide="box" class="u-icon-lg"></i></div>
        <h2 class="empty-title">Todavía no tienes ninguna colmena registrada</h2>
        <p class="empty-desc">Crea un apiario y registra una colmena para empezar a ver datos reales.</p>
        <a href="dispositivos.php" class="btn btn-brand u-mt-2">
            <i data-lucide="plus"></i> Registrar colmena
        </a>
    </div>

<?php else:
    $idColmena = (int) $colmenaActiva['id_colmena'];

    $temp   = ultimaLectura($idColmena, 'temperatura_interna');
    $hum    = ultimaLectura($idColmena, 'humedad_relativa');
    $peso   = ultimaLectura($idColmena, 'peso');
    $sonido = ultimaLectura($idColmena, 'sonido');

    $flujoDiario = calcularFlujoDiarioPeso($idColmena);
    $alertas     = alertasActivas($idColmena, 5);
    $alertasTotal = contarAlertasActivas($idColmena);
    // Serie agregada cada 15 min: máximo ~96 puntos en 24 h, no 2.880.
    $serieTemp   = serieHistoricaAgregada($idColmena, 'temperatura_interna', 24, 15);

    $tempFresca   = lecturaReciente($temp, 5);
    $humFresca    = lecturaReciente($hum, 5);
    $pesoFresca   = lecturaReciente($peso, 5);
    $sonidoFresca = lecturaReciente($sonido, 5);
    $flujoFresco  = $pesoFresca && $flujoDiario !== null;

    $tempOk = $tempFresca && (float) $temp['valor_calibrado'] >= 34 && (float) $temp['valor_calibrado'] <= 36;
    $humOk  = $humFresca && (float) $hum['valor_calibrado'] >= 50 && (float) $hum['valor_calibrado'] <= 70;

    // La acústica es experimental: no se usa para diagnosticar enjambrazón.
    $sonidoActivo = $sonidoFresca && (float) $sonido['valor_calibrado'] > 0;
    $sonidoBanda  = $sonidoActivo
        && (float) $sonido['valor_calibrado'] >= 400
        && (float) $sonido['valor_calibrado'] <= 600;
    $sensoresFrescos = count(array_filter([$tempFresca, $humFresca, $pesoFresca, $sonidoFresca]));

    $ultimaFecha = ultimaConexion($idColmena);
    $ultimaTexto = $ultimaFecha ? tiempoRelativo($ultimaFecha) : 'sin lecturas';

    if ($espOnline) {
        $estadoNodo  = 'Nodo en línea';
        $estadoClase = 'online';
        $estadoSub   = 'Recibiendo telemetría. Última lectura: ' . $ultimaTexto . '.';
    } elseif ($espTieneHistorial) {
        $estadoNodo  = 'Nodo sin reportar';
        $estadoClase = 'warning';
        $estadoSub   = 'Última lectura: ' . $ultimaTexto . '. Revisa energía y WiFi.';
    } else {
        $estadoNodo  = 'Nodo sin configurar';
        $estadoClase = 'offline';
        $estadoSub   = 'Aún no llega telemetría de esta colmena.';
    }

    // “Qué revisar”: solo reglas que se pueden sostener con los sensores
    // realmente instalados. Nada de diagnósticos que el hardware no mide.
    $recomendaciones = [];

    if (!$espOnline) {
        $recomendaciones[] = [
            'nivel'  => $espTieneHistorial ? 'attention' : 'info',
            'icono'  => 'wifi-off',
            'titulo' => $espTieneHistorial ? 'El nodo dejó de reportar' : 'Nodo pendiente de configuración',
            'detalle'=> $espTieneHistorial
                ? 'Última lectura ' . $ultimaTexto . '. Revisa batería, cableado y cobertura WiFi antes de asumir un problema en la colmena.'
                : 'Enciende el nodo, configura el WiFi desde el portal y autorízalo con su código en Dispositivos.',
        ];
    }

    if ($tempFresca && !$tempOk) {
        $recomendaciones[] = [
            'nivel'  => 'attention',
            'icono'  => 'thermometer',
            'titulo' => 'Temperatura fuera del rango de cría',
            'detalle'=> 'Lectura actual: ' . number_format((float) $temp['valor_calibrado'], 1) . ' °C. El rango de referencia es 34–36 °C; confirma con una inspección antes de actuar.',
        ];
    }

    if ($humFresca && !$humOk) {
        $recomendaciones[] = [
            'nivel'  => 'attention',
            'icono'  => 'droplets',
            'titulo' => 'Humedad fuera del rango configurado',
            'detalle'=> 'Lectura actual: ' . number_format((float) $hum['valor_calibrado'], 0) . ' % HR. El rango configurado es 50–70 %; verifica ventilación y entrada de humedad.',
        ];
    }

    if ($flujoFresco && $flujoDiario <= -1.5) {
        $recomendaciones[] = [
            'nivel'  => 'attention',
            'icono'  => 'trending-down',
            'titulo' => 'Caída de peso mayor a 1,5 kg en 24 h',
            'detalle'=> 'Si la báscula está calibrada, puede ser una cosecha, una salida de enjambre o una pérdida de alimento. Confirma con la bitácora antes de concluir.',
        ];
    }

    if ($sonidoBanda) {
        $recomendaciones[] = [
            'nivel'  => 'info',
            'icono'  => 'activity',
            'titulo' => 'Banda acústica de 400–600 Hz detectada',
            'detalle'=> 'Es una señal experimental: el método actual no confirma enjambrazón. No abras la colmena solo por este dato; úsalo como contexto.',
        ];
    }

    if (!$recomendaciones) {
        $recomendaciones[] = [
            'nivel'  => 'info',
            'icono'  => 'check-circle-2',
            'titulo' => 'Sin anomalías en las lecturas disponibles',
            'detalle'=> 'No hay alertas con las variables que están reportando. La ausencia de datos no es un diagnóstico; no reemplaza una inspección presencial.',
        ];
    }
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Resumen</h1>
        <p class="page-subtitle"><?= htmlspecialchars($colmenaActiva['nombre']) ?> · <?= htmlspecialchars($colmenaActiva['estado']) ?></p>
    </div>
    <div class="page-header-actions">
        <span class="text-tertiary text-xs" id="lastUpdate">Actualizado <?= date('H:i') ?></span>
        <button class="btn btn-secondary btn-sm" onclick="location.reload()">
            <i data-lucide="refresh-cw"></i>
            Actualizar
        </button>
    </div>
</div>

<!-- Estado operativo: lo primero que debe ver el apicultor -->
<div class="card status-panel animate-fadeUp">
    <div class="status-main">
        <span class="status-dot <?= htmlspecialchars($estadoClase) ?>" id="statusDot"></span>
        <div>
            <div class="status-title" id="statusTitle"><?= htmlspecialchars($estadoNodo) ?></div>
            <div class="status-sub" id="statusSub"><?= htmlspecialchars($estadoSub) ?></div>
        </div>
    </div>
    <div class="status-item">
        <span>Última lectura</span>
        <strong id="statusLast"><?= $ultimaFecha ? htmlspecialchars(date('d/m H:i', strtotime($ultimaFecha))) : 'Sin datos' ?></strong>
    </div>
    <div class="status-item">
        <span>Alertas activas</span>
        <strong id="statusAlerts"><?= $alertasTotal ?></strong>
    </div>
    <div class="status-item">
        <span>Sensores con datos recientes</span>
        <strong id="statusSensors"><?= $sensoresFrescos ?> de 4</strong>
    </div>
</div>

<!-- Métricas núcleo: solo lo que el nodo básico mide de verdad -->
<div class="grid-4-cols dashboard-metrics">

    <div data-metric="temp" class="card metric-card animate-fadeUp <?= $temp ? ($tempFresca ? ($tempOk ? 'success' : 'warning') : 'stale') : '' ?>">
        <div class="metric-icon <?= $temp ? ($tempFresca ? ($tempOk ? 'success' : 'warning') : 'neutral') : 'neutral' ?>">
            <i data-lucide="thermometer"></i>
        </div>
        <div class="metric-header"><i data-lucide="thermometer"></i> Temperatura interna</div>
        <?php if ($temp): ?>
            <div class="metric-value">
                <span data-countup="<?= number_format((float) $temp['valor_calibrado'], 1, '.', '') ?>" data-decimals="1"><?= number_format((float) $temp['valor_calibrado'], 1) ?></span>
                <span class="metric-unit">°C</span>
            </div>
            <div class="metric-footer">
                <span class="badge <?= !$tempFresca ? 'badge-neutral' : ($tempOk ? 'badge-success' : 'badge-warning') ?>"><?= !$tempFresca ? 'Dato antiguo' : ($tempOk ? 'Rango de cría' : 'Fuera de rango') ?></span>
                <span class="metric-time text-tertiary text-xs"><?= horaSubida($temp['fecha_hora']) ?> · <?= tiempoRelativo($temp['fecha_hora']) ?></span>
            </div>
        <?php else: ?>
            <div class="metric-value metric-empty">—<span class="metric-unit">°C</span></div>
            <div class="metric-footer"><span class="badge badge-neutral">Sin datos</span></div>
        <?php endif; ?>
    </div>

    <div data-metric="hum" class="card metric-card animate-fadeUp <?= $hum ? ($humFresca ? ($humOk ? 'success' : 'warning') : 'stale') : '' ?>">
        <div class="metric-icon <?= $hum ? ($humFresca ? ($humOk ? 'success' : 'warning') : 'neutral') : 'neutral' ?>">
            <i data-lucide="droplets"></i>
        </div>
        <div class="metric-header"><i data-lucide="droplets"></i> Humedad relativa</div>
        <?php if ($hum): ?>
            <div class="metric-value">
                <span data-countup="<?= number_format((float) $hum['valor_calibrado'], 0, '.', '') ?>" data-decimals="0"><?= number_format((float) $hum['valor_calibrado'], 0) ?></span>
                <span class="metric-unit">% HR</span>
            </div>
            <div class="metric-footer">
                <span class="badge <?= !$humFresca ? 'badge-neutral' : ($humOk ? 'badge-success' : 'badge-warning') ?>"><?= !$humFresca ? 'Dato antiguo' : ($humOk ? 'Normal' : 'Atención') ?></span>
                <span class="metric-time text-tertiary text-xs"><?= horaSubida($hum['fecha_hora']) ?> · <?= tiempoRelativo($hum['fecha_hora']) ?></span>
            </div>
        <?php else: ?>
            <div class="metric-value metric-empty">—<span class="metric-unit">%</span></div>
            <div class="metric-footer"><span class="badge badge-neutral">Sin datos</span></div>
        <?php endif; ?>
    </div>

    <div data-metric="peso" class="card metric-card animate-fadeUp <?= $peso ? ($pesoFresca ? 'brand' : 'stale') : '' ?>">
        <div class="metric-icon <?= $pesoFresca ? 'brand' : 'neutral' ?>"><i data-lucide="scale"></i></div>
        <div class="metric-header"><i data-lucide="scale"></i> Peso de la colmena</div>
        <?php if ($peso): ?>
            <div class="metric-value">
                <span data-countup="<?= number_format((float) $peso['valor_calibrado'], 1, '.', '') ?>" data-decimals="1"><?= number_format((float) $peso['valor_calibrado'], 1) ?></span>
                <span class="metric-unit">kg</span>
            </div>
            <div class="metric-footer">
                <span class="badge <?= $pesoFresca ? 'badge-brand' : 'badge-neutral' ?>"><?= $pesoFresca ? 'Balance 24 h' : 'Dato antiguo' ?></span>
                <span class="text-secondary text-xs" id="f-flujo">
                    <?= $flujoFresco ? (($flujoDiario >= 0 ? '+' : '') . $flujoDiario . ' kg') : 'Sin datos recientes' ?>
                </span>
                <span class="metric-time text-tertiary text-xs"><?= horaSubida($peso['fecha_hora']) ?> · <?= tiempoRelativo($peso['fecha_hora']) ?></span>
            </div>
        <?php else: ?>
            <div class="metric-value metric-empty">—<span class="metric-unit">kg</span></div>
            <div class="metric-footer"><span class="badge badge-neutral">Sin datos</span></div>
        <?php endif; ?>
    </div>

    <div data-metric="sonido" class="card metric-card animate-fadeUp <?= $sonido ? (!$sonidoFresca ? 'stale' : ($sonidoBanda ? 'warning' : '')) : '' ?>">
        <div class="metric-icon <?= $sonidoBanda ? 'warning' : 'neutral' ?>">
            <i data-lucide="activity"></i>
        </div>
        <div class="metric-header">
            <i data-lucide="activity"></i> Actividad acústica
            <span class="badge badge-experimental u-ml-1">experimental</span>
        </div>
        <?php if ($sonido): ?>
            <div class="metric-value">
                <span data-countup="<?= number_format((float) $sonido['valor_calibrado'], 0, '.', '') ?>" data-decimals="0"><?= number_format((float) $sonido['valor_calibrado'], 0) ?></span>
                <span class="metric-unit">Hz</span>
            </div>
            <div class="metric-footer">
                <?php if (!$sonidoFresca): ?>
                    <span class="badge badge-neutral">Dato antiguo</span>
                <?php elseif (!$sonidoActivo): ?>
                    <span class="badge badge-neutral">Sin actividad</span>
                <?php elseif ($sonidoBanda): ?>
                    <span class="badge badge-warning">Banda 400–600 Hz</span>
                <?php else: ?>
                    <span class="badge badge-neutral">Fuera de banda</span>
                <?php endif; ?>
                <span class="metric-time text-tertiary text-xs"><?= horaSubida($sonido['fecha_hora']) ?> · <?= tiempoRelativo($sonido['fecha_hora']) ?></span>
            </div>
        <?php else: ?>
            <div class="metric-value metric-empty">—<span class="metric-unit">Hz</span></div>
            <div class="metric-footer"><span class="badge badge-neutral">Sin datos</span></div>
        <?php endif; ?>
    </div>

</div>

<div class="dashboard-insights-grid">

    <div class="card animate-fadeUp">
        <div class="card-header">
            <div>
                <div class="card-title">Temperatura interna · 24 h</div>
                <div class="page-subtitle chart-note">Promedio cada 15 minutos · datos reales</div>
            </div>
        </div>
        <?php if (count($serieTemp) > 0): ?>
            <div class="chart-shell-sm"><canvas id="tempChart" aria-label="Serie de temperatura interna de las últimas 24 horas"></canvas></div>
            <details class="chart-table">
                <summary>Ver tabla de datos</summary>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Hora</th><th>Temperatura (°C)</th></tr></thead>
                        <tbody>
                        <?php foreach ($serieTemp as $p): ?>
                            <tr><td><?= date('d/m H:i', strtotime($p['fecha_hora'])) ?></td><td><?= number_format((float) $p['valor'], 1) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        <?php else: ?>
            <div class="empty-state compact">
                <div class="empty-icon-circle"><i data-lucide="line-chart" class="u-icon-lg"></i></div>
                <p class="empty-desc">Aún no hay lecturas en las últimas 24 horas. El gráfico aparecerá cuando el nodo empiece a enviar datos.</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="stack">

        <div class="card animate-fadeUp stack-fill">
            <div class="card-header">
                <div class="card-title alert-title-row">
                    Alertas
                    <span id="alertCount" class="badge <?= $alertasTotal > 0 ? 'badge-critical' : 'badge-neutral' ?>"><?= $alertasTotal ?> activas</span>
                </div>
            </div>
            <ul class="alert-list" id="alertList"<?= count($alertas) === 0 ? ' style="display:none"' : '' ?>>
                <?php foreach ($alertas as $al): ?>
                <li class="alert-item">
                    <div class="alert-dot <?= $al['nivel'] >= 3 ? 'bg-critical' : ($al['nivel'] == 2 ? 'bg-warning' : 'brand-dot') ?>"></div>
                    <div class="alert-content">
                        <div class="alert-title"><?= htmlspecialchars($al['tipo']) ?></div>
                        <div class="alert-desc"><?= htmlspecialchars($al['mensaje']) ?></div>
                    </div>
                    <div class="alert-time"><?= horaSubida($al['fecha_hora']) ?> · <?= tiempoRelativo($al['fecha_hora']) ?></div>
                </li>
                <?php endforeach; ?>
            </ul>
            <p class="text-secondary text-sm alert-zero" id="alertVacia"<?= count($alertas) > 0 ? ' style="display:none"' : '' ?>>
                <i data-lucide="check-circle-2" class="u-icon-md text-success"></i>
                Sin alertas activas.
            </p>
        </div>

        <div class="card animate-fadeUp">
            <div class="card-header card-header-compact">
                <div class="card-title">Qué revisar</div>
            </div>
            <ul class="ops-list">
                <?php foreach ($recomendaciones as $r): ?>
                <li class="ops-item <?= htmlspecialchars($r['nivel']) ?>">
                    <i data-lucide="<?= htmlspecialchars($r['icono']) ?>"></i>
                    <div>
                        <strong><?= htmlspecialchars($r['titulo']) ?></strong>
                        <span><?= htmlspecialchars($r['detalle']) ?></span>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

    </div>
</div>

<div class="scope-note u-mt-4">
    <strong>Alcance de esta versión:</strong> el panel muestra temperatura, humedad, peso y actividad acústica.
    El IBB, la eficiencia de ventilación y el ΔT requieren sensores de CO₂ y temperatura exterior que este nodo no tiene;
    no se muestran para no inventar un diagnóstico. El peso es una tendencia del HX711 sin calibración validada, y la
    actividad acústica es experimental: no confirma enjambrazón. <a href="sensores.php">Ver inventario de sensores</a>.
</div>

<?php if (count($serieTemp) > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('tempChart');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const colors = window.BeeStationTheme.chartColors();
    const gradientBrand = ctx.createLinearGradient(0, 0, 0, 280);
    gradientBrand.addColorStop(0, colors.brandAreaStrong);
    gradientBrand.addColorStop(1, colors.brandAreaSoft);

    const labels = <?= json_encode(array_map(fn($r) => date('H:i', strtotime($r['fecha_hora'])), $serieTemp)) ?>;
    const data   = <?= json_encode(array_map(fn($r) => (float) $r['valor'], $serieTemp)) ?>;

    window.tempChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Temperatura (°C)',
                data: data,
                borderColor: colors.brand,
                backgroundColor: gradientBrand,
                borderWidth: 2.5,
                tension: 0.35,
                fill: true,
                pointRadius: data.length === 1 ? 5 : 0,
                pointBackgroundColor: colors.brand,
                pointHoverRadius: 5,
                pointHoverBackgroundColor: colors.brand
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (item) => item.parsed.y.toFixed(1) + ' °C'
                    }
                }
            },
            scales: {
                x: {
                    offset: data.length === 1,
                    grid: { display: false },
                    ticks: {
                        color: colors.textSecondary,
                        font: { family: 'Inter', size: 11 },
                        maxTicksLimit: 8
                    }
                },
                y: {
                    suggestedMin: 25,
                    suggestedMax: 40,
                    title: {
                        display: true,
                        text: '°C',
                        color: colors.textSecondary,
                        font: { family: 'Inter', size: 11 }
                    },
                    ticks: { color: colors.textSecondary },
                    grid: { color: colors.grid }
                }
            }
        }
    });
});
</script>
<?php endif; ?>

<script src="js/actualizar-dashboard.js"></script>

<?php endif; ?>

<?php include 'includes/footer.php'; ?>
