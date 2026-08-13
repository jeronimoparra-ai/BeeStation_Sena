<?php include 'includes/header.php'; ?>

<?php if (!$colmenaActiva): ?>

    <div class="card empty-state">
        <div class="empty-icon-circle"><i data-lucide="box" class="u-icon-lg"></i></div>
        <h2 class="empty-title">Todavía no tienes ninguna colmena registrada</h2>
        <p class="empty-desc">Registra un apiario y una colmena en la base de datos para empezar a ver datos aquí.</p>
    </div>

<?php else:
    $idColmena = $colmenaActiva['id_colmena'];

    $temp   = ultimaLectura($idColmena, 'temperatura_interna');
    $tempEx = ultimaLectura($idColmena, 'temperatura_externa');
    $hum    = ultimaLectura($idColmena, 'humedad_relativa');
    $peso   = ultimaLectura($idColmena, 'peso');
    $sonido = ultimaLectura($idColmena, 'sonido');

    $flujoDiario = calcularFlujoDiarioPeso($idColmena);
    $ibb = calcularIBB($idColmena);
    $deltaT = calcularDeltaT($idColmena);
    $ev = calcularEV($idColmena);
    $hMiel = calcularHMiel($idColmena);
    $alertas = alertasActivas($idColmena, 5);

    $serieTemp = serieHistorica($idColmena, 'temperatura_interna', 24);
    $seriePeso = serieHistorica($idColmena, 'peso', 24);
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Resumen General</h1>
        <p class="page-subtitle"><?= htmlspecialchars($colmenaActiva['nombre']) ?> · Estado: <?= htmlspecialchars($colmenaActiva['estado']) ?></p>
    </div>
    <button class="btn btn-secondary btn-sm" onclick="location.reload()">
        <i data-lucide="refresh-cw"></i>
        Actualizar
    </button>
</div>

<div class="card dashboard-hero animate-fadeUp stagger-1">
    <div class="hero-copy">
        <span class="hero-eyebrow">Centro de monitoreo en tiempo real</span>
        <h2>BeeStation observa la colmena con precisión operativa.</h2>
        <p class="page-subtitle">Lecturas reales, jerarquía visual clara y estado consolidado de temperatura, humedad, peso, actividad acústica y alertas.</p>
        <div class="hero-chip-row">
            <span class="hero-chip">
                <i data-lucide="hexagon"></i>
                <?= htmlspecialchars($colmenaActiva['nombre']) ?>
            </span>
            <span class="hero-chip">
                <i data-lucide="wifi"></i>
                <?= $espOnline ? 'ESP32 sincronizado' : 'Sin datos recientes' ?>
            </span>
            <span class="hero-chip">
                <i data-lucide="alert-triangle"></i>
                <?= count($alertas) ?> alertas activas
            </span>
        </div>
    </div>
    <div class="hero-panel">
        <div class="hero-ring">
            <div class="hero-kpi">
                <div class="hero-kpi-value"><?= $ibb ? number_format($ibb['valor'], 1) : '—' ?></div>
                <div class="hero-kpi-label"><?= $ibb ? htmlspecialchars($ibb['estado']) : 'IBB pendiente' ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Metric Cards -->
<div class="grid-4-cols dashboard-metrics">

    <!-- Temperatura -->
    <div class="card metric-card animate-fadeUp stagger-1 <?= ($temp && $temp['valor_calibrado'] >= 34 && $temp['valor_calibrado'] <= 36) ? 'success' : ($temp ? 'warning' : '') ?>">
        <div class="metric-icon <?= ($temp && $temp['valor_calibrado'] >= 34 && $temp['valor_calibrado'] <= 36) ? 'success' : 'warning' ?>">
            <i data-lucide="thermometer"></i>
        </div>
        <div class="metric-header"><i data-lucide="thermometer"></i> Temperatura Interna</div>
        <?php if ($temp): ?>
            <div class="metric-value">
                <span data-countup="<?= number_format($temp['valor_calibrado'], 1, '.', '') ?>" data-decimals="1"><?= number_format($temp['valor_calibrado'], 1) ?></span>
                <span class="metric-unit">°C</span>
            </div>
            <div class="metric-footer">
                <span class="badge <?= ($temp['valor_calibrado'] >= 34 && $temp['valor_calibrado'] <= 36) ? 'badge-success' : 'badge-warning' ?>">
                    <?= ($temp['valor_calibrado'] >= 34 && $temp['valor_calibrado'] <= 36) ? 'Rango óptimo' : 'Fuera de rango' ?>
                </span>
                <span class="text-tertiary text-xs"><?= tiempoRelativo($temp['fecha_hora']) ?></span>
            </div>
        <?php else: ?>
            <div class="metric-value metric-empty">—<span class="metric-unit">°C</span></div>
            <div class="metric-footer"><span class="badge badge-neutral">Sin datos</span></div>
        <?php endif; ?>
    </div>

    <!-- Humedad -->
    <div class="card metric-card animate-fadeUp stagger-2 <?= ($hum && $hum['valor_calibrado'] >= 50 && $hum['valor_calibrado'] <= 70) ? 'success' : ($hum ? 'warning' : '') ?>">
        <div class="metric-icon <?= ($hum && $hum['valor_calibrado'] >= 50 && $hum['valor_calibrado'] <= 70) ? 'success' : 'warning' ?>">
            <i data-lucide="droplets"></i>
        </div>
        <div class="metric-header"><i data-lucide="droplets"></i> Humedad Relativa</div>
        <?php if ($hum): ?>
            <div class="metric-value">
                <span data-countup="<?= number_format($hum['valor_calibrado'], 0, '.', '') ?>" data-decimals="0"><?= number_format($hum['valor_calibrado'], 0) ?></span>
                <span class="metric-unit">% HR</span>
            </div>
            <div class="metric-footer">
                <span class="badge <?= ($hum['valor_calibrado'] >= 50 && $hum['valor_calibrado'] <= 70) ? 'badge-success' : 'badge-warning' ?>">
                    <?= ($hum['valor_calibrado'] >= 50 && $hum['valor_calibrado'] <= 70) ? 'Normal' : 'Atención' ?>
                </span>
                <span class="text-tertiary text-xs"><?= tiempoRelativo($hum['fecha_hora']) ?></span>
            </div>
        <?php else: ?>
            <div class="metric-value metric-empty">—<span class="metric-unit">%</span></div>
            <div class="metric-footer"><span class="badge badge-neutral">Sin datos</span></div>
        <?php endif; ?>
    </div>

    <!-- Peso -->
    <div class="card metric-card animate-fadeUp stagger-3 brand">
        <div class="metric-icon brand">
            <i data-lucide="scale"></i>
        </div>
        <div class="metric-header"><i data-lucide="scale"></i> Peso de la Colmena</div>
        <?php if ($peso): ?>
            <div class="metric-value">
                <span data-countup="<?= number_format($peso['valor_calibrado'], 1, '.', '') ?>" data-decimals="1"><?= number_format($peso['valor_calibrado'], 1) ?></span>
                <span class="metric-unit">kg</span>
            </div>
            <div class="metric-footer">
                <span class="badge badge-brand">Flujo néctar</span>
                <span class="text-secondary text-xs">
                    <?= $flujoDiario !== null ? (($flujoDiario >= 0 ? '+' : '') . $flujoDiario . ' kg/día') : 'Calculando…' ?>
                </span>
            </div>
        <?php else: ?>
            <div class="metric-value metric-empty">—<span class="metric-unit">kg</span></div>
            <div class="metric-footer"><span class="badge badge-neutral">Sin datos</span></div>
        <?php endif; ?>
    </div>

    <!-- Acústica -->
    <div class="card metric-card animate-fadeUp stagger-4 <?= ($sonido && $sonido['valor_calibrado'] >= 400 && $sonido['valor_calibrado'] <= 600) ? 'critical' : 'success' ?>">
        <div class="metric-icon <?= ($sonido && $sonido['valor_calibrado'] >= 400 && $sonido['valor_calibrado'] <= 600) ? 'critical' : 'success' ?>">
            <i data-lucide="activity"></i>
        </div>
        <div class="metric-header"><i data-lucide="activity"></i> Actividad Acústica</div>
        <?php if ($sonido): ?>
            <div class="metric-value">
                <span data-countup="<?= number_format($sonido['valor_calibrado'], 0, '.', '') ?>" data-decimals="0"><?= number_format($sonido['valor_calibrado'], 0) ?></span>
                <span class="metric-unit">Hz</span>
            </div>
            <div class="metric-footer">
                <span class="badge <?= ($sonido['valor_calibrado'] >= 400 && $sonido['valor_calibrado'] <= 600) ? 'badge-critical' : 'badge-success' ?>">
                    <?= ($sonido['valor_calibrado'] >= 400 && $sonido['valor_calibrado'] <= 600) ? 'Zona de alerta' : 'Normal' ?>
                </span>
                <span class="text-tertiary text-xs"><?= tiempoRelativo($sonido['fecha_hora']) ?></span>
            </div>
        <?php else: ?>
            <div class="metric-value metric-empty">—<span class="metric-unit">Hz</span></div>
            <div class="metric-footer"><span class="badge badge-neutral">Sin datos</span></div>
        <?php endif; ?>
    </div>

</div>

<!-- Indicadores Analíticos -->
<h2 class="page-subtitle u-mb-3 u-mt-4">Indicadores Analíticos</h2>
<div class="grid-3-cols dashboard-metrics">

    <!-- Delta T -->
    <div class="card metric-card animate-fadeUp stagger-5 <?= ($deltaT && $deltaT['estado'] === 'Normal') ? 'success' : ($deltaT ? 'critical' : '') ?>">
        <div class="metric-icon <?= ($deltaT && $deltaT['estado'] === 'Normal') ? 'success' : 'critical' ?>">
            <i data-lucide="thermometer"></i>
        </div>
        <div class="metric-header"><i data-lucide="thermometer"></i> Delta T (Interior - Exterior)</div>
        <?php if ($deltaT): ?>
            <div class="metric-value">
                <span data-countup="<?= number_format($deltaT['valor'], 1, '.', '') ?>" data-decimals="1"><?= number_format($deltaT['valor'], 1) ?></span>
                <span class="metric-unit">°C</span>
            </div>
            <div class="metric-footer">
                <span class="badge <?= ($deltaT['estado'] === 'Normal') ? 'badge-success' : 'badge-critical' ?>">
                    <?= htmlspecialchars($deltaT['estado']) ?>
                </span>
                <span class="text-tertiary text-xs">Actual</span>
            </div>
        <?php else: ?>
            <div class="metric-value metric-empty">—<span class="metric-unit">°C</span></div>
            <div class="metric-footer"><span class="badge badge-neutral">Sin datos</span></div>
        <?php endif; ?>
    </div>

    <!-- Humedad Miel -->
    <div class="card metric-card animate-fadeUp stagger-6 <?= ($hMiel && $hMiel['estado'] === 'Lista para cosecha') ? 'success' : ($hMiel ? 'warning' : '') ?>">
        <div class="metric-icon <?= ($hMiel && $hMiel['estado'] === 'Lista para cosecha') ? 'success' : 'warning' ?>">
            <i data-lucide="droplet"></i>
        </div>
        <div class="metric-header"><i data-lucide="droplet"></i> Humedad de Miel</div>
        <?php if ($hMiel): ?>
            <div class="metric-value">
                <span data-countup="<?= number_format($hMiel['valor'], 1, '.', '') ?>" data-decimals="1"><?= number_format($hMiel['valor'], 1) ?></span>
                <span class="metric-unit">%</span>
            </div>
            <div class="metric-footer">
                <span class="badge <?= ($hMiel['estado'] === 'Lista para cosecha') ? 'badge-success' : 'badge-warning' ?>">
                    <?= htmlspecialchars($hMiel['estado']) ?>
                </span>
                <span class="text-tertiary text-xs">Actual</span>
            </div>
        <?php else: ?>
            <div class="metric-value metric-empty">—<span class="metric-unit">%</span></div>
            <div class="metric-footer"><span class="badge badge-neutral">Sin datos</span></div>
        <?php endif; ?>
    </div>

    <!-- Eficiencia de Ventilación (EV) -->
    <div class="card animate-fadeUp stagger-6">
        <div class="card-header card-header-compact">
            <div class="card-title">Eficiencia Ventilación</div>
            <?php if ($ev): ?>
                <span class="badge <?= $ev['valor'] >= 50 ? 'badge-success' : 'badge-warning' ?>">
                    <?= htmlspecialchars($ev['estado']) ?>
                </span>
            <?php endif; ?>
        </div>
        <?php if ($ev): ?>
            <div class="ibb-gauge">
                <div class="ibb-bar">
                    <div class="ibb-fill" data-progress="<?= $ev['valor'] ?>"></div>
                </div>
                <div class="ibb-value"><?= $ev['valor'] ?></div>
            </div>
            <p class="text-xs text-secondary ibb-note">Porcentaje EV (basado en CO₂)</p>
        <?php else: ?>
            <p class="text-secondary text-sm">Sin datos de CO₂.</p>
        <?php endif; ?>
    </div>

</div>

<!-- Chart + Alerts -->
<div class="dashboard-insights-grid">

    <!-- Chart Card -->
    <div class="card animate-fadeUp stagger-5">
        <div class="card-header">
            <div>
                <div class="card-title">Temperatura vs Peso · 24 h</div>
                <div class="page-subtitle chart-note"><?= htmlspecialchars($colmenaActiva['nombre']) ?> · Datos reales del sistema</div>
            </div>
        </div>
        <?php if (count($serieTemp) > 0 || count($seriePeso) > 0): ?>
            <div class="chart-shell-sm"><canvas id="tempWeightChart"></canvas></div>
        <?php else: ?>
            <div class="empty-state compact">
                <div class="empty-icon-circle"><i data-lucide="line-chart" class="u-icon-lg"></i></div>
                <p class="empty-desc">Aún no hay lecturas registradas en las últimas 24 horas. El gráfico se llenará automáticamente cuando el ESP32 empiece a enviar datos.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Alerts + IBB -->
    <div class="stack">

        <!-- Alerts -->
        <div class="card animate-fadeUp stagger-6 stack-fill">
            <div class="card-header">
                <div class="card-title alert-title-row">
                    Alertas
                    <span class="badge <?= count($alertas) > 0 ? 'badge-critical' : 'badge-neutral' ?>"><?= count($alertas) ?> activas</span>
                </div>
            </div>

            <?php if (count($alertas) > 0): ?>
            <ul class="alert-list">
                <?php foreach ($alertas as $al): ?>
                <li class="alert-item">
                    <div class="alert-dot <?= $al['nivel'] >= 3 ? 'bg-critical' : ($al['nivel'] == 2 ? 'bg-warning' : 'brand-dot') ?>"></div>
                    <div class="alert-content">
                        <div class="alert-title"><?= htmlspecialchars($al['tipo']) ?></div>
                        <div class="alert-desc"><?= htmlspecialchars($al['mensaje']) ?></div>
                    </div>
                    <div class="alert-time"><?= tiempoRelativo($al['fecha_hora']) ?></div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
                <p class="text-secondary text-sm alert-zero">
                    <i data-lucide="check-circle-2" class="u-icon-md text-success"></i>
                    Sin alertas activas.
                </p>
            <?php endif; ?>
        </div>

        <!-- IBB -->
        <div class="card animate-fadeUp stagger-6">
            <div class="card-header card-header-compact">
                <div class="card-title">Bienestar (IBB)</div>
                <?php if ($ibb): ?>
                    <span class="badge <?= $ibb['valor'] >= 85 ? 'badge-success' : ($ibb['valor'] >= 50 ? 'badge-warning' : 'badge-critical') ?>">
                        <?= htmlspecialchars($ibb['estado']) ?>
                    </span>
                <?php endif; ?>
            </div>
            <?php if ($ibb): ?>
                <div class="ibb-gauge">
                    <div class="ibb-bar">
                        <div class="ibb-fill" data-progress="<?= $ibb['valor'] ?>"></div>
                    </div>
                    <div class="ibb-value"><?= $ibb['valor'] ?></div>
                </div>
                <p class="text-xs text-secondary ibb-note">Índice 0–100 basado en T°, humedad y CO₂</p>
            <?php else: ?>
                <p class="text-secondary text-sm">Sin datos suficientes para calcular el IBB.</p>
            <?php endif; ?>
        </div>

    </div>
</div>

<button class="btn-floating" title="Ayuda"><i data-lucide="help-circle"></i></button>

<?php if (count($serieTemp) > 0 || count($seriePeso) > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const ctx = document.getElementById('tempWeightChart').getContext('2d');
    const colors = window.BeeStationTheme.chartColors();
    const gradientBrand = ctx.createLinearGradient(0, 0, 0, 280);
    gradientBrand.addColorStop(0, colors.brandAreaStrong);
    gradientBrand.addColorStop(1, colors.brandAreaSoft);

    // Datos REALES provenientes de PHP (no se inventa nada aquí)
    const tempLabels = <?= json_encode(array_map(fn($r) => date('H:i', strtotime($r['fecha_hora'])), $serieTemp)) ?>;
    const tempData    = <?= json_encode(array_map(fn($r) => (float) $r['valor_calibrado'], $serieTemp)) ?>;
    const pesoLabels  = <?= json_encode(array_map(fn($r) => date('H:i', strtotime($r['fecha_hora'])), $seriePeso)) ?>;
    const pesoData    = <?= json_encode(array_map(fn($r) => (float) $r['valor_calibrado'], $seriePeso)) ?>;

    const labels = tempLabels.length ? tempLabels : pesoLabels;

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Temperatura (°C)',
                    data: tempData,
                    borderColor: colors.brand,
                    backgroundColor: gradientBrand,
                    borderWidth: 2, yAxisID: 'y', tension: 0.4, fill: true,
                    pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: colors.brand
                },
                {
                    label: 'Peso (kg)',
                    data: pesoData,
                    borderColor: colors.success,
                    borderWidth: 2, borderDash: [4,4], yAxisID: 'y1', tension: 0.4, fill: false,
                    pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: colors.success
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { usePointStyle: true, padding: 16, color: colors.textSecondary, font: { family: 'Inter', size: 12 } }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: colors.textSecondary, font: { family: 'IBM Plex Mono', size: 11 } } },
                y:  { type: 'linear', position: 'left',  title: { display: true, text: '°C', color: colors.textSecondary, font: { family: 'Inter', size: 11 } }, ticks: { color: colors.textSecondary }, grid: { color: colors.grid } },
                y1: { type: 'linear', position: 'right', title: { display: true, text: 'kg', color: colors.textSecondary, font: { family: 'Inter', size: 11 } }, ticks: { color: colors.textSecondary }, grid: { drawOnChartArea: false } }
            }
        }
    });
});
</script>
<?php endif; ?>

<?php endif; ?>

<?php include 'includes/footer.php'; ?>
