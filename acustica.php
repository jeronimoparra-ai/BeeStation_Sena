<?php include 'includes/header.php'; ?>

<?php if (!$colmenaActiva): ?>
    <div class="card empty-state">
        <div class="empty-icon-circle"><i data-lucide="activity" class="u-icon-lg"></i></div>
        <h2 class="empty-title">No hay colmena registrada</h2>
        <p class="empty-desc">Registra una colmena para ver su actividad acústica.</p>
    </div>
<?php else:
    $idColmena   = (int) $colmenaActiva['id_colmena'];
    $sonido      = ultimaLectura($idColmena, 'sonido');
    $serieSonido = serieHistoricaAgregada($idColmena, 'sonido', 6, 5);

    $sonidoFresco = lecturaReciente($sonido, 5);
    $activo       = $sonidoFresco && (float) $sonido['valor_calibrado'] > 0;
    $enBanda      = $activo
        && (float) $sonido['valor_calibrado'] >= 400
        && (float) $sonido['valor_calibrado'] <= 600;
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Actividad acústica</h1>
        <p class="page-subtitle"><?= htmlspecialchars($colmenaActiva['nombre']) ?> · MAX4466 · frecuencia estimada</p>
    </div>
</div>

<div class="scope-note u-mb-4">
    <strong>Experimental.</strong> La frecuencia se estima por cruces por cero y no distingue enjambrazón,
    canto de reina ni estados de ánimo. Sirve para observar tendencia, silencio o saturación.
    <strong>No abras la colmena solo por este dato.</strong>
</div>

<div class="grid-2x2 two-column-metrics">
    <div class="card metric-card animate-fadeUp stagger-1 <?= $sonido ? (!$sonidoFresco ? 'stale' : ($enBanda ? 'warning' : ($activo ? 'success' : ''))) : '' ?>">
        <div class="metric-icon <?= $enBanda ? 'warning' : ($activo ? 'success' : 'neutral') ?>">
            <i data-lucide="waves"></i>
        </div>
        <div class="metric-header">
            <i data-lucide="waves"></i> Frecuencia actual
            <span class="badge badge-experimental u-ml-1">experimental</span>
        </div>
        <?php if ($sonido): ?>
            <div class="metric-value">
                <span data-countup="<?= number_format((float) $sonido['valor_calibrado'], 0, '.', '') ?>" data-decimals="0"><?= number_format((float) $sonido['valor_calibrado'], 0) ?></span>
                <span class="metric-unit">Hz</span>
            </div>
            <div class="metric-footer">
                <?php if (!$sonidoFresco): ?>
                    <span class="badge badge-neutral">Dato antiguo</span>
                <?php elseif (!$activo): ?>
                    <span class="badge badge-neutral">Sin actividad</span>
                <?php elseif ($enBanda): ?>
                    <span class="badge badge-warning">Banda de referencia 400–600 Hz</span>
                <?php else: ?>
                    <span class="badge badge-neutral">Fuera de la banda de referencia</span>
                <?php endif; ?>
                <span class="text-tertiary text-xs"><?= horaSubida($sonido['fecha_hora']) ?> · <?= tiempoRelativo($sonido['fecha_hora']) ?></span>
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
        <div class="metric-header"><i data-lucide="bar-chart-3"></i> Muestras agregadas</div>
        <div class="metric-value">
            <span data-countup="<?= count($serieSonido) ?>" data-decimals="0"><?= count($serieSonido) ?></span>
        </div>
        <div class="metric-footer">
            <span class="badge badge-neutral">Últimas 6 horas · promedio cada 5 min</span>
        </div>
    </div>
</div>

<div class="card chart-frame animate-fadeUp stagger-3 u-mb-4">
    <div class="card-header">
        <div>
            <div class="card-title">Frecuencia estimada · 6 h</div>
            <div class="page-subtitle chart-note">Serie real agregada · la banda 400–600 Hz es solo una referencia</div>
        </div>
    </div>
    <?php if (count($serieSonido) > 0): ?>
        <div class="chart-shell-sm"><canvas id="acousticChart" aria-label="Serie de frecuencia acústica de las últimas 6 horas"></canvas></div>
        <details class="chart-table">
            <summary>Ver tabla de datos</summary>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Hora</th><th>Frecuencia (Hz)</th><th>Muestras</th></tr></thead>
                    <tbody>
                    <?php foreach ($serieSonido as $p): ?>
                        <tr>
                            <td><?= date('d/m H:i', strtotime($p['fecha_hora'])) ?></td>
                            <td><?= number_format((float) $p['valor'], 0) ?></td>
                            <td><?= (int) $p['muestras'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    <?php else: ?>
        <div class="empty-state compact">
            <div class="empty-icon-circle"><i data-lucide="mic-off" class="u-icon-lg"></i></div>
            <p class="empty-desc">No hay lecturas de sonido en las últimas 6 horas. El gráfico aparecerá cuando el nodo envíe datos.</p>
        </div>
    <?php endif; ?>
</div>

<?php if (count($serieSonido) > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('acousticChart');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const theme = window.BeeStationTheme.chartColors();

    const labels = <?= json_encode(array_map(fn($r) => date('H:i', strtotime($r['fecha_hora'])), $serieSonido)) ?>;
    const data   = <?= json_encode(array_map(fn($r) => (float) $r['valor'], $serieSonido)) ?>;

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Frecuencia (Hz)',
                data: data,
                borderColor: theme.brand,
                backgroundColor: theme.brandAreaSoft,
                borderWidth: 2,
                tension: 0.3,
                fill: true,
                pointRadius: data.length === 1 ? 5 : 0,
                pointBackgroundColor: theme.brand,
                pointHoverRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: (item) => item.parsed.y.toFixed(0) + ' Hz' } }
            },
            scales: {
                x: {
                    offset: data.length === 1,
                    grid: { display: false },
                    ticks: { color: theme.textSecondary, font: { family: 'Inter', size: 11 }, maxTicksLimit: 8 }
                },
                y: {
                    title: { display: true, text: 'Hz', color: theme.textSecondary, font: { family: 'Inter', size: 11 } },
                    ticks: { color: theme.textSecondary },
                    grid: { color: theme.grid }
                }
            }
        }
    });
});
</script>
<?php endif; ?>

<?php endif; ?>
<?php include 'includes/footer.php'; ?>
