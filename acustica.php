<?php include 'includes/header.php'; ?>

<?php if (!$colmenaActiva): ?>
    <div class="card empty-state">
        <div class="empty-icon-circle"><i data-lucide="activity" class="u-icon-lg"></i></div>
        <h2 class="empty-title">No hay colmena registrada</h2>
        <p class="empty-desc">Registra una colmena para ver su análisis acústico.</p>
    </div>
<?php else:
    $idColmena   = $colmenaActiva['id_colmena'];
    $sonido      = ultimaLectura($idColmena, 'sonido');
    $serieSonido = serieHistorica($idColmena, 'sonido', 6); // últimas 6 horas

    $enAlerta = $sonido && $sonido['valor_calibrado'] >= 400 && $sonido['valor_calibrado'] <= 600;
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Análisis Acústico</h1>
        <p class="page-subtitle"><?= htmlspecialchars($colmenaActiva['nombre']) ?> · Sensor MAX9814 · Datos reales</p>
    </div>
    <?php if ($enAlerta): ?>
    <div class="alert-banner critical">
        <div class="status-dot offline"></div>
        Posible enjambrazón detectada
    </div>
    <?php endif; ?>
</div>

<!-- Metric Cards -->
<div class="grid-2x2 two-column-metrics">
    <div class="card metric-card animate-fadeUp stagger-1 <?= $enAlerta ? 'critical' : ($sonido ? 'success' : '') ?>">
        <div class="metric-icon <?= $enAlerta ? 'critical' : 'success' ?>">
            <i data-lucide="waves"></i>
        </div>
        <div class="metric-header"><i data-lucide="waves"></i> Frecuencia Actual</div>
        <?php if ($sonido): ?>
            <div class="metric-value">
                <span data-countup="<?= number_format($sonido['valor_calibrado'], 0, '.', '') ?>" data-decimals="0"><?= number_format($sonido['valor_calibrado'], 0) ?></span>
                <span class="metric-unit">Hz</span>
            </div>
            <div class="metric-footer">
                <span class="badge <?= $enAlerta ? 'badge-critical' : 'badge-success' ?>"><?= $enAlerta ? 'Alerta' : 'Normal' ?></span>
                <span class="text-tertiary text-xs"><?= tiempoRelativo($sonido['fecha_hora']) ?></span>
            </div>
        <?php else: ?>
            <div class="metric-value metric-empty">—<span class="metric-unit">Hz</span></div>
            <div class="metric-footer"><span class="badge badge-neutral">Sin datos</span></div>
        <?php endif; ?>
    </div>

    <div class="card metric-card animate-fadeUp stagger-2 neutral">
        <div class="metric-icon neutral">
            <i data-lucide="bar-chart-3"></i>
        </div>
        <div class="metric-header"><i data-lucide="bar-chart-3"></i> Lecturas Registradas</div>
        <div class="metric-value">
            <span data-countup="<?= count($serieSonido) ?>" data-decimals="0"><?= count($serieSonido) ?></span>
        </div>
        <div class="metric-footer">
            <span class="badge badge-neutral">Últimas 6 horas</span>
        </div>
    </div>
</div>

<!-- Acoustic Chart -->
<div class="card animate-fadeUp stagger-3 u-mb-4">
    <div class="card-header">
        <div class="card-title">Frecuencia en el Tiempo — Últimas 6 horas</div>
    </div>
    <?php if (count($serieSonido) > 0): ?>
        <div class="chart-shell-sm"><canvas id="acousticChart"></canvas></div>
    <?php else: ?>
        <div class="empty-state compact">
            <div class="empty-icon-circle"><i data-lucide="mic-off" class="u-icon-lg"></i></div>
            <p class="empty-desc">Todavía no hay lecturas del sensor de sonido. Cuando el MAX9814 empiece a transmitir, este gráfico se llenará con datos reales.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Frequency Zones -->
<div class="grid-3-cols">
    <div class="card frequency-card zone-normal animate-fadeUp stagger-4">
        <div class="frequency-zone-head">
            <div class="zone-dot success"></div>
            <div class="zone-range">200–380 Hz</div>
        </div>
        <div class="zone-title success">Zumbido Normal</div>
        <div class="text-secondary text-sm">Actividad de vuelo y ventilación estándar de la colonia.</div>
    </div>
    <div class="card frequency-card animate-fadeUp stagger-5 <?= $enAlerta ? 'zone-alert-active' : 'zone-alert' ?>">
        <div class="frequency-zone-head">
            <div class="zone-dot critical <?= $enAlerta ? 'is-pulsing' : '' ?>"></div>
            <div class="zone-range <?= $enAlerta ? 'critical' : '' ?>">400–600 Hz</div>
            <?= $enAlerta ? '<span class="badge badge-critical">Activo</span>' : '' ?>
        </div>
        <div class="zone-title critical">Pre-Enjambrazón</div>
        <div class="text-secondary text-sm">Comunicación de enjambre <?= $enAlerta ? '· <strong>DETECTADO AHORA</strong>' : '' ?>.</div>
    </div>
    <div class="card frequency-card zone-queen animate-fadeUp stagger-6">
        <div class="frequency-zone-head">
            <div class="zone-dot brand"></div>
            <div class="zone-range">600–900 Hz</div>
        </div>
        <div class="zone-title brand">Canto de Reinas</div>
        <div class="text-secondary text-sm">Piping y quacking de celdas reales de la reina.</div>
    </div>
</div>

<button class="btn-floating" title="Ayuda"><i data-lucide="help-circle"></i></button>

<?php if (count($serieSonido) > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const ctx = document.getElementById('acousticChart').getContext('2d');
    const theme = window.BeeStationTheme.chartColors();

    // Datos REALES desde PHP
    const labels = <?= json_encode(array_map(fn($r) => date('H:i', strtotime($r['fecha_hora'])), $serieSonido)) ?>;
    const data   = <?= json_encode(array_map(fn($r) => (float) $r['valor_calibrado'], $serieSonido)) ?>;
    const colors = data.map(v => (v >= 400 && v <= 600) ? theme.critical : theme.success);

    new Chart(ctx, {
        type: 'line',
        data: { labels: labels, datasets: [{
            label: 'Frecuencia (Hz)', data: data,
            borderColor: theme.muted, pointBackgroundColor: colors, pointRadius: 3,
            borderWidth: 2, tension: 0.3
        }]},
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { color: theme.textSecondary, font: { family: 'IBM Plex Mono', size: 11 } } },
                y: { title: { display: true, text: 'Frecuencia (Hz)', color: theme.textSecondary, font: { family: 'Inter', size: 11 } }, ticks: { color: theme.textSecondary }, grid: { color: theme.grid } }
            }
        }
    });
});
</script>
<?php endif; ?>

<?php endif; ?>
<?php include 'includes/footer.php'; ?>
