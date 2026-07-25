<?php include 'includes/header.php'; ?>

<?php if (!$colmenaActiva): ?>
    <div class="card empty-state">
        <div class="empty-icon-circle"><i data-lucide="cpu" class="u-icon-lg"></i></div>
        <h2 class="empty-title">No hay colmena registrada</h2>
        <p class="empty-desc">Registra una colmena y sus sensores en la base de datos para verlos aquí.</p>
    </div>
<?php else:
    $idColmena = $colmenaActiva['id_colmena'];
    $sensores = sensoresConEstado($idColmena);

    $activos    = count(array_filter($sensores, fn($s) => $s['estado'] === 'en_linea'));
    $advertencia= count(array_filter($sensores, fn($s) => $s['estado'] === 'advertencia'));
    $sinSenal   = count(array_filter($sensores, fn($s) => $s['estado'] === 'sin_senal'));
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Configuración de Sensores</h1>
        <p class="page-subtitle"><?= htmlspecialchars($colmenaActiva['nombre']) ?> · Datos reales desde la base de datos</p>
    </div>
</div>

<!-- Stat Pills -->
<div class="grid-3-cols">
    <div class="stat-pill animate-fadeUp stagger-1">
        <div class="stat-pill-value text-success"><?= $activos ?></div>
        <div class="stat-pill-label">En línea</div>
    </div>
    <div class="stat-pill animate-fadeUp stagger-2">
        <div class="stat-pill-value text-warning"><?= $advertencia ?></div>
        <div class="stat-pill-label">Advertencia</div>
    </div>
    <div class="stat-pill animate-fadeUp stagger-3">
        <div class="stat-pill-value u-muted-strong"><?= $sinSenal ?></div>
        <div class="stat-pill-label">Sin señal</div>
    </div>
</div>

<!-- Sensors Table -->
<div class="card animate-fadeUp stagger-4 u-mb-4">
    <div class="card-header">
        <div class="card-title">Módulos de Sensado</div>
        <span class="badge badge-neutral"><?= count($sensores) ?> sensores</span>
    </div>

    <?php if (count($sensores) === 0): ?>
        <p class="text-secondary text-sm u-py-2">Aún no hay sensores registrados para esta colmena en la base de datos.</p>
    <?php else: ?>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Sensor</th>
                    <th>Modelo</th>
                    <th>Estado</th>
                    <th>Última lectura</th>
                    <th>Tiempo</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Map sensor types to Lucide icons
                $iconMap = [
                    'temperatura_interna' => 'thermometer',
                    'temperatura_externa' => 'sun',
                    'humedad_relativa'    => 'droplets',
                    'co2'                 => 'wind',
                    'peso'                => 'scale',
                    'sonido'              => 'activity',
                    'energia'             => 'battery-charging',
                ];
                foreach ($sensores as $s):
                    $icon = $iconMap[$s['tipo']] ?? 'cpu';
                    $label = ucfirst(str_replace('_', ' ', $s['tipo']));
                ?>
                <tr>
                    <td>
                        <div class="sensor-type-cell">
                            <div class="sensor-icon-wrap"><i data-lucide="<?= $icon ?>"></i></div>
                            <span><?= htmlspecialchars($label) ?></span>
                        </div>
                    </td>
                    <td class="text-mono text-sm u-muted"><?= htmlspecialchars($s['modelo']) ?></td>
                    <td>
                        <?php if ($s['estado'] === 'en_linea'): ?>
                            <span class="badge badge-success">En línea</span>
                        <?php elseif ($s['estado'] === 'advertencia'): ?>
                            <span class="badge badge-warning">Advertencia</span>
                        <?php else: ?>
                            <span class="badge badge-neutral">Sin señal</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-mono text-sm"><?= $s['ultima_lectura'] !== null ? number_format($s['ultima_lectura'], 2) : '— —' ?></td>
                    <td class="text-sm u-muted-strong"><?= tiempoRelativo($s['ultima_fecha']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Hive Info -->
<div class="card animate-fadeUp stagger-5">
    <div class="card-header"><div class="card-title">Información de la Colmena</div></div>
    <div class="info-grid">
        <div class="info-grid-item">
            <div class="label">Nombre</div>
            <div class="value"><?= htmlspecialchars($colmenaActiva['nombre']) ?></div>
        </div>
        <div class="info-grid-item">
            <div class="label">Especie</div>
            <div class="value"><?= htmlspecialchars($colmenaActiva['especie']) ?></div>
        </div>
        <div class="info-grid-item">
            <div class="label">Estado</div>
            <div class="value"><?= htmlspecialchars($colmenaActiva['estado']) ?></div>
        </div>
        <div class="info-grid-item">
            <div class="label">Instalación</div>
            <div class="value"><?= htmlspecialchars($colmenaActiva['fecha_instalacion']) ?></div>
        </div>
    </div>
</div>

<button class="btn-floating" title="Ayuda"><i data-lucide="help-circle"></i></button>

<?php endif; ?>
<?php include 'includes/footer.php'; ?>
