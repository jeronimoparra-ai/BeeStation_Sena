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
                <div class="card-title">Peso · últimos 30 días</div>
                <div class="page-subtitle chart-note"><?= htmlspecialchars($colmenaActiva['nombre']) ?> · promedio diario · HX711 sin calibración validada</div>
            </div>
        </div>
        <?php if (count($serie30d) > 0): ?>
            <div class="chart-shell"><canvas id="weightAreaChart"></canvas></div>
        <?php else: ?>
            <div class="premium-empty compact">
                <div class="empty-icon-circle"><i data-lucide="line-chart" class="u-icon-lg"></i></div>
                <p class="empty-desc">Aún no hay lecturas para esta colmena (MySQL 127.0.0.1 no disponible). Cuando el nodo envíe datos aparecerán automáticamente.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (count($serie30d) > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const ctxArea = document.getElementById('weightAreaChart').getContext('2d');
    const colors = window.BeeStationTheme.chartColors();
    const gradArea = ctxArea.createLinearGradient(0, 0, 0, 300);
    gradArea.addColorStop(0, colors.brandAreaMedium);
    gradArea.addColorStop(1, colors.brandAreaSoft);

    // Datos REALES desde PHP
    const labels = <?= json_encode(array_map(fn($r) => date('d/m', strtotime($r['fecha_hora'])), $serie30d)) ?>;
    const data    = <?= json_encode(array_map(fn($r) => (float) $r['valor'], $serie30d)) ?>;

    new Chart(ctxArea, {
        type: 'line',
        data: { labels: labels, datasets: [{
            label: 'Peso (kg)', data: data,
            borderColor: colors.brand, backgroundColor: gradArea,
            borderWidth: 2, fill: true, tension: 0.3,
            // Con un solo cubo diario no hay línea: muestra el punto.
            pointRadius: data.length === 1 ? 5 : 0,
            pointBackgroundColor: colors.brand, pointHoverRadius: 4
        }]},
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { offset: data.length === 1, grid: { display: false }, ticks: { color: colors.textSecondary, maxTicksLimit: 31, autoSkip: true } },
                y: { ticks: { color: colors.textSecondary }, grid: { color: colors.grid } }
            }
        }
    });
});
</script>
<?php endif; ?>

<?php endif; ?>
<?php include 'includes/footer.php'; ?>
