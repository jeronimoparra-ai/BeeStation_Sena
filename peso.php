<?php include 'includes/header.php'; ?>

<?php if (!$colmenaActiva): ?>
    <div class="card empty-state">
        <div class="empty-icon-circle"><i data-lucide="scale" class="u-icon-lg"></i></div>
        <h2 class="empty-title">No hay colmena registrada</h2>
        <p class="empty-desc">Registra una colmena para ver su histórico de peso.</p>
    </div>
<?php else:
    $idColmena = $colmenaActiva['id_colmena'];
    $pesoActual = ultimaLectura($idColmena, 'peso');
    $serie30d = serieHistoricaDias($idColmena, 'peso', 30);
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Histórico de Peso</h1>
        <p class="page-subtitle"><?= htmlspecialchars($colmenaActiva['nombre']) ?> · Datos reales de sensor gravimétrico (HX711)</p>
    </div>
</div>

<div class="card page-hero animate-fadeUp stagger-1">
    <div class="page-hero-copy">
        <span class="hero-eyebrow">Tendencia de masa</span>
        <h2>El peso de la colmena como señal de flujo y acumulación.</h2>
        <p class="page-subtitle">Una lectura limpia, con foco en la evolución real del HX711 y una visualización más amplia para interpretar variaciones de manera inmediata.</p>
        <div class="page-hero-grid">
            <div class="page-kpi-card">
                <span>Peso actual</span>
                <strong><?= $pesoActual ? number_format($pesoActual['valor_calibrado'], 2) . ' kg' : 'Sin datos' ?></strong>
            </div>
            <div class="page-kpi-card">
                <span>Registros</span>
                <strong><?= count($serie30d) ?> lecturas</strong>
            </div>
        </div>
    </div>
    <div class="page-hero-aside">
        <div class="premium-banner">
            <div>
                <div class="subtle-note u-mb-1">Último ciclo</div>
                <div class="card-title">30 días de histórico</div>
            </div>
            <span class="badge badge-brand">Peso vivo</span>
        </div>
        <div class="hero-chip"><i data-lucide="scale"></i><span>Señal gravimétrica continua</span></div>
        <div class="hero-chip"><i data-lucide="trending-up"></i><span>Variación útil para flujo de néctar</span></div>
        <div class="hero-chip"><i data-lucide="database"></i><span>Datos reales desde la base</span></div>
    </div>
</div>

<div class="metric-compact-grid u-mb-4">
    <div class="card metric-mini metric-card brand">
        <div class="metric-icon brand">
            <i data-lucide="scale"></i>
        </div>
        <div class="metric-header"><i data-lucide="scale"></i> Peso Actual</div>
        <div class="metric-value"><?= $pesoActual ? number_format($pesoActual['valor_calibrado'], 2) . ' <span class="metric-unit">kg</span>' : '<span class="text-tertiary">Sin datos</span>' ?></div>
    </div>
    <div class="card metric-mini metric-card neutral">
        <div class="metric-icon neutral">
            <i data-lucide="database"></i>
        </div>
        <div class="metric-header"><i data-lucide="database"></i> Registros (30 días)</div>
        <div class="metric-value"><?= count($serie30d) ?></div>
        <div class="metric-footer"><span class="badge badge-neutral">Lecturas reales</span></div>
    </div>
</div>

<div class="grid-2x2 single-column-grid">
    <div class="card chart-frame">
        <div class="card-header">
            <div>
                <div class="card-title">Evolución de Peso — Últimos 30 días</div>
                <div class="page-subtitle chart-note"><?= htmlspecialchars($colmenaActiva['nombre']) ?> · Datos reales registrados en la base de datos</div>
            </div>
        </div>
        <?php if (count($serie30d) > 0): ?>
            <div class="chart-shell"><canvas id="weightAreaChart"></canvas></div>
        <?php else: ?>
            <div class="premium-empty compact">
                <div class="empty-icon-circle"><i data-lucide="line-chart" class="u-icon-lg"></i></div>
                <p class="empty-desc">Todavía no hay lecturas de peso registradas para esta colmena. En cuanto el sensor HX711 empiece a enviar datos, aparecerán aquí automáticamente.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<button class="btn-floating" title="Ayuda"><i data-lucide="help-circle"></i></button>

<?php if (count($serie30d) > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const ctxArea = document.getElementById('weightAreaChart').getContext('2d');
    const colors = window.BeeStationTheme.chartColors();
    const gradArea = ctxArea.createLinearGradient(0, 0, 0, 300);
    gradArea.addColorStop(0, colors.brandAreaMedium);
    gradArea.addColorStop(1, colors.brandAreaSoft);

    // Datos REALES desde PHP
    const labels = <?= json_encode(array_map(fn($r) => date('d/m H:i', strtotime($r['fecha_hora'])), $serie30d)) ?>;
    const data    = <?= json_encode(array_map(fn($r) => (float) $r['valor_calibrado'], $serie30d)) ?>;

    new Chart(ctxArea, {
        type: 'line',
        data: { labels: labels, datasets: [{
            label: 'Peso (kg)', data: data,
            borderColor: colors.brand, backgroundColor: gradArea,
            borderWidth: 2, fill: true, tension: 0.3, pointRadius: 0, pointHoverRadius: 4
        }]},
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { color: colors.textSecondary, maxTicksLimit: 10 } },
                y: { ticks: { color: colors.textSecondary }, grid: { color: colors.grid } }
            }
        }
    });
});
</script>
<?php endif; ?>

<?php endif; ?>
<?php include 'includes/footer.php'; ?>
