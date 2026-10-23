<?php include 'includes/header.php'; ?>

<?php if (!$colmenaActiva): ?>
    <div class="card empty-state">
        <div class="empty-icon-circle"><i data-lucide="scale" class="u-icon-lg"></i></div>
        <h2 class="empty-title">No hay colmena registrada</h2>
        <p class="empty-desc">Registra una colmena para ver su histórico de peso.</p>
    </div>
<?php else:
    $idColmena = (int) $colmenaActiva['id_colmena'];
    $pesoActual = ultimaLectura($idColmena, 'peso');
    $pesoFresco = lecturaReciente($pesoActual, 5);
    // Agregado diario: 30 puntos en lugar de decenas de miles de filas crudas.
    $serie30d = serieHistoricaAgregada($idColmena, 'peso', 30 * 24, 1440);
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Histórico de Peso</h1>
        <p class="page-subtitle"><?= htmlspecialchars($colmenaActiva['nombre']) ?> · serie del HX711 · calibración pendiente en campo</p>
    </div>
</div>

<div class="scope-note u-mb-4">
    <strong>Calibración pendiente.</strong> El HX711 registra una señal de peso, pero la báscula todavía no
    está calibrada ni validada con una colmena real. Usa esta página como tendencia; una variación no confirma
    por sí sola entrada de néctar, cosecha o enjambrazón.
</div>

<div class="card page-hero animate-fadeUp stagger-1">
    <div class="page-hero-copy">
        <span class="hero-eyebrow">Tendencia de masa</span>
        <h2>El peso como tendencia; no como diagnóstico automático.</h2>
        <p class="page-subtitle">La serie se agrega por día para mostrar la evolución del HX711. La calibración todavía no está validada y la interpretación requiere bitácora de campo.</p>
        <div class="page-hero-grid">
            <div class="page-kpi-card">
                <span>Peso actual</span>
                <strong><?= $pesoActual ? number_format((float) $pesoActual['valor_calibrado'], 2) . ' kg' : 'Sin datos' ?></strong>
                <small class="text-tertiary text-xs"><?= $pesoActual ? ($pesoFresco ? 'dato reciente' : 'dato antiguo') : 'sin datos' ?></small>
            </div>
            <div class="page-kpi-card">
                <span>Puntos diarios</span>
                <strong><?= count($serie30d) ?> <?= count($serie30d) === 1 ? 'agregado' : 'agregados' ?></strong>
            </div>
        </div>
    </div>
    <div class="page-hero-aside">
        <div class="premium-banner">
            <div>
                <div class="subtle-note u-mb-1">Serie disponible</div>
                <div class="card-title">30 días de histórico</div>
            </div>
            <span class="badge badge-brand">Serie diaria</span>
        </div>
        <div class="hero-chip"><i data-lucide="scale"></i><span>HX711 sin calibrar</span></div>
        <div class="hero-chip"><i data-lucide="trending-up"></i><span>Variación de peso, no flujo confirmado</span></div>
        <div class="hero-chip"><i data-lucide="database"></i><span>Lecturas registradas</span></div>
    </div>
</div>

<div class="grid-2x2 single-column-grid">
    <div class="card chart-frame">
        <div class="card-header">
            <div>
                <div class="card-title" id="weightChartTitle">Peso · últimos 30 días</div>
                <div class="page-subtitle chart-note" id="weightChartNote"><?= htmlspecialchars($colmenaActiva['nombre']) ?> · promedio diario de lecturas válidas; cada punto muestra la última hora de muestra del día.</div>
            </div>
        </div>
        <div class="chart-shell" id="weightChartShell"<?= count($serie30d) === 0 ? ' style="display:none"' : '' ?>><canvas id="weightAreaChart" aria-label="Promedio diario del peso de los últimos 30 días"></canvas></div>
        <div class="empty-state compact" id="weightChartEmpty" role="status" aria-live="polite"<?= count($serie30d) > 0 ? ' style="display:none"' : '' ?>>
            <div class="empty-icon-circle"><i data-lucide="line-chart" class="u-icon-lg"></i></div>
            <p class="empty-desc">No hay lecturas válidas de peso en los últimos 30 días.</p>
        </div>
        <details class="chart-table" id="weightChartTable"<?= count($serie30d) === 0 ? ' style="display:none"' : '' ?>>
            <summary>Ver tabla de promedios diarios</summary>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Fecha</th><th>Hora de última muestra</th><th>Promedio diario (kg)</th><th>Muestras</th></tr></thead>
                    <tbody><?php foreach ($serie30d as $p): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($p['fecha_hora'])) ?></td>
                            <td><?= date('H:i:s', strtotime($p['ultima_muestra'])) ?></td>
                            <td><?= number_format((float) $p['valor'], 2) ?></td>
                            <td><?= (int) $p['muestras'] ?></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table>
            </div>
        </details>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('weightAreaChart');
    if (!canvas) return;
    const ctxArea = canvas.getContext('2d');
    const colors = window.BeeStationTheme.chartColors();
    const gradArea = ctxArea.createLinearGradient(0, 0, 0, 300);
    gradArea.addColorStop(0, colors.brandAreaMedium);
    gradArea.addColorStop(1, colors.brandAreaSoft);

    // Datos REALES desde PHP
    const labels = <?= json_encode(array_map(fn($r) => date('d/m/Y', strtotime($r['fecha_hora'])), $serie30d)) ?>;
    const ultimaMuestra = <?= json_encode(array_map(fn($r) => date('H:i:s', strtotime($r['ultima_muestra'])), $serie30d)) ?>;
    const data    = <?= json_encode(array_map(fn($r) => (float) $r['valor'], $serie30d)) ?>;

    window.weightChart = new Chart(ctxArea, {
        type: 'line',
        data: { labels: labels, datasets: [{
            label: 'Promedio diario de peso', data: data, ultimaMuestra: ultimaMuestra,
            borderColor: colors.brand, backgroundColor: gradArea,
            borderWidth: 2, fill: true, tension: 0.3,
            // Con un solo cubo diario no hay línea: muestra el punto.
            pointRadius: 2,
            pointBackgroundColor: colors.brand, pointHoverRadius: 6
        }]},
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            hover: { mode: 'index', intersect: false },
            plugins: { legend: { display: false }, tooltip: { enabled: true, callbacks: {
                title: (items) => {
                    const item = items[0];
                    const hora = item?.dataset.ultimaMuestra?.[item.dataIndex];
                    return item ? item.label + (hora ? ' · última muestra ' + hora : '') : '';
                },
                label: (item) => 'Promedio diario: ' + item.parsed.y.toFixed(2) + ' kg'
            } } },
            scales: {
                x: { offset: data.length === 1, grid: { display: false }, ticks: { color: colors.textSecondary, maxTicksLimit: 31, autoSkip: true } },
                y: { ticks: { color: colors.textSecondary }, grid: { color: colors.grid } }
            }
        }
    });
});
</script>
<script src="js/historico-graficas.js"></script>

<?php endif; ?>
<?php include 'includes/footer.php'; ?>
