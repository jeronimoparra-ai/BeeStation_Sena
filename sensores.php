<?php include 'includes/header.php'; ?>

<?php if (!$colmenaActiva): ?>
    <div class="card empty-state">
        <div class="empty-icon-circle"><i data-lucide="cpu" class="u-icon-lg"></i></div>
        <h2 class="empty-title">No hay colmena registrada</h2>
        <p class="empty-desc">Crea un apiario y registra una colmena para ver sus sensores.</p>
        <a href="dispositivos.php" class="btn btn-brand u-mt-2">
            <i data-lucide="plus"></i> Registrar colmena
        </a>
    </div>
<?php else:
    $idColmena = (int) $colmenaActiva['id_colmena'];
    // Solo los seis tipos del nodo básico. Filas de CO₂, temperatura exterior
    // o energía de pruebas anteriores no se presentan como sensores activos.
    $sensores = array_values(array_filter(
        sensoresOperativos($idColmena),
        fn($s) => esTipoSensorNodoBasico((string) $s['tipo'])
    ));

    $activos     = count(array_filter($sensores, fn($s) => $s['estado_real'] === 'en_linea'));
    $advertencia = count(array_filter($sensores, fn($s) => $s['estado_real'] === 'advertencia'));
    $sinDatos    = count(array_filter($sensores, fn($s) => $s['estado_real'] === 'sin_senal'));
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Sensores del nodo</h1>
        <p class="page-subtitle"><?= htmlspecialchars($colmenaActiva['nombre']) ?> · estado calculado con la última lectura</p>
    </div>
</div>

<div class="card status-panel animate-fadeUp">
    <div class="status-main">
        <span class="status-dot <?= $activos > 0 ? 'online' : 'warning' ?>"></span>
        <div>
            <div class="status-title"><?= $activos > 0 ? 'Sensores reportando' : 'Sin lecturas recientes' ?></div>
            <div class="status-sub">El estado “sin datos” no siempre significa avería: puede ser un sensor que este nodo no tiene instalado.</div>
        </div>
    </div>
    <div class="status-item">
        <span>En línea</span>
        <strong><?= $activos ?></strong>
    </div>
    <div class="status-item">
        <span>Advertencia</span>
        <strong><?= $advertencia ?></strong>
    </div>
    <div class="status-item">
        <span>Sin datos recientes</span>
        <strong><?= $sinDatos ?></strong>
    </div>
</div>

<div class="card table-shell animate-fadeUp u-mb-4">
    <div class="card-header">
        <div class="card-title">Inventario de sensores</div>
        <span class="badge badge-neutral"><?= count($sensores) ?> registrados</span>
    </div>

    <?php if (count($sensores) === 0): ?>
        <p class="text-secondary text-sm u-py-2">Aún no hay sensores registrados para esta colmena.</p>
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
                $iconMap = [
                    'temperatura_interna' => 'thermometer',
                    'temperatura_externa' => 'sun',
                    'humedad_relativa'    => 'droplets',
                    'co2'                 => 'wind',
                    'peso'                => 'scale',
                    'sonido'              => 'activity',
                    'energia'             => 'battery-charging',
                    'presion'             => 'gauge',
                    'luminosidad'         => 'sun-medium',
                ];
                $labelMap = [
                    'temperatura_interna' => 'Temperatura interna',
                    'temperatura_externa' => 'Temperatura externa',
                    'humedad_relativa'    => 'Humedad relativa',
                    'presion'             => 'Presión',
                    'peso'                => 'Peso',
                    'sonido'              => 'Sonido',
                    'co2'                 => 'CO₂',
                    'energia'             => 'Energía',
                    'luminosidad'         => 'Luminosidad',
                ];
                foreach ($sensores as $s):
                    $icon = $iconMap[$s['tipo']] ?? 'cpu';
                    $label = $labelMap[$s['tipo']] ?? ucfirst(str_replace('_', ' ', $s['tipo']));
                    $unidad = $s['unidad_medida'] ?? '';
                ?>
                <tr>
                    <td>
                        <div class="sensor-type-cell">
                            <div class="sensor-icon-wrap"><i data-lucide="<?= htmlspecialchars($icon) ?>"></i></div>
                            <span><?= htmlspecialchars($label) ?></span>
                        </div>
                    </td>
                    <td class="text-mono text-sm u-muted"><?= htmlspecialchars($s['modelo']) ?></td>
                    <td>
                        <?php if ($s['estado_real'] === 'en_linea'): ?>
                            <span class="badge badge-success">En línea</span>
                        <?php elseif ($s['estado_real'] === 'advertencia'): ?>
                            <span class="badge badge-warning">Advertencia</span>
                        <?php else: ?>
                            <span class="badge badge-neutral">Sin datos recientes</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-mono text-sm">
                        <?= $s['ultima_lectura'] !== null
                            ? number_format((float) $s['ultima_lectura'], 2) . ' ' . htmlspecialchars($unidad)
                            : '—' ?>
                    </td>
                    <td class="text-sm u-muted-strong">
                        <?= $s['ultima_fecha'] ? horaSubida($s['ultima_fecha']) : '—' ?>
                        <?php if ($s['ultima_fecha']): ?>
                            <span class="text-tertiary">· <?= tiempoRelativo($s['ultima_fecha']) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="scope-note">
    <strong>Alcance del nodo básico:</strong> temperatura interna, humedad relativa, presión, peso, sonido y luminosidad.
    No incluye CO₂, temperatura exterior ni energía: esas filas no se muestran aunque existan datos de pruebas anteriores.
    La calibración real del HX711 y del sonido queda pendiente.
</div>

<div class="card premium-empty animate-fadeUp u-mt-4">
    <div class="card-header"><div class="card-title">Información de la colmena</div></div>
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

<?php endif; ?>
<?php include 'includes/footer.php'; ?>
