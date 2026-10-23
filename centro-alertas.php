<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/funciones_alertas.php';

$current_page = basename($_SERVER['PHP_SELF']);
$colmenaActiva = obtenerColmenaActiva($_SESSION['id_usuario']);

$sensores = $colmenaActiva ? sensoresOperativos((int)$colmenaActiva['id_colmena']) : [];

// Para cada sensor, calcular estado
$resultados = [];
foreach ($sensores as $s) {
    $tipo = $s['tipo'];
    $id_sen = $s['id_sensor'];
    $res = obtenerEstadoSensor((int)$colmenaActiva['id_colmena'], $id_sen, $tipo);
    $res['id_sensor'] = $s['id_sensor'];
    $res['tipo'] = $tipo;
    $res['modelo'] = $s['modelo'];
    $res['nombre_colmena'] = $colmenaActiva ? $colmenaActiva['nombre'] : '';
    $res['id_colmena'] = $colmenaActiva ? $colmenaActiva['id_colmena'] : 0;
    $resultados[] = $res;
}

// Cuenta alertas activas para el resumen
$total_alertas_activas = 0;
if ($colmenaActiva) {
    $total_alertas_activas = contarAlertasActivas((int)$colmenaActiva['id_colmena']);
}
?>
<?php include 'includes/header.php'; ?>

<div class="page-content">
    <h1>Centro de alertas</h1>
    <p class="u-muted">Estado operativo de los sensores y condiciones que requieren revisión.</p>

    <?php if ($colmenaActiva): ?>
    <section class="card">
        <div class="card-header">
            <h2><?= htmlspecialchars($colmenaActiva['nombre']) ?> (id <?= (int)$colmenaActiva['id_colmena'] ?>)</h2>
            <span class="badge">Colmena activa</span>
        </div>
        <div class="card-body">
            <p>Resumen: <strong><?= $total_alertas_activas ?></strong> alerta(s) activa(s) en esta colmena.</p>
            <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Sensor</th>
                        <th scope="col">Tipo</th>
                        <th scope="col">Estado</th>
                        <th scope="col">Motivo</th>
                        <th scope="col">Última lectura</th>
                        <th scope="col">Umbrales</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resultados as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['modelo']) ?> (<?= (int)$r['id_sensor'] ?>)</td>
                        <td><?= htmlspecialchars($r['tipo']) ?></td>
                        <td>
                            <span class="badge <?= match($r['nivel']) {
                                'normal' => 'badge-success',
                                'advertencia' => 'badge-warning',
                                'critico' => 'badge-critical',
                                default => 'badge-neutral'
                            } ?>"><?= htmlspecialchars($r['estado']) ?></span>
                        </td>
                        <td><?= htmlspecialchars($r['motivo']) ?></td>
                        <td><?= $r['fecha_origen'] ? htmlspecialchars($r['fecha_origen']) : '—' ?></td>
                        <td><?= $r['umbral_configurado'] ? 'Sí' : 'No' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </section>
    <?php else: ?>
    <div class="alert alert-info">No hay colmena activa asignada. Selecciona una para ver sus alertas.</div>
    <?php endif; ?>

    <section class="card">
        <div class="card-header">
            <h2>Notas sobre las reglas</h2>
        </div>
        <div class="card-body">
            <ul>
                <li><strong>Normal:</strong> Hay una lectura reciente válida dentro de los umbrales configurados.</li>
                <li><strong>Advertencia:</strong> Una lectura reciente válida está fuera de un umbral de alerta configurado.</li>
                <li><strong>Crítico:</strong> Existe una condición que cumple una regla crítica configurada explícitamente en <code>variable_bioclimatica</code>.</li>
                <li><strong>Sin datos:</strong> El sensor no tiene registros.</li>
                <li><strong>Sin datos recientes:</strong> La lectura válida más reciente supera la ventana de 30 minutos.</li>
                <li><strong>Lectura inválida:</strong> El registro más reciente fue marcado inválido y no se evalúa como medición.</li>
                <li><strong>Sin verificar:</strong> No hay umbrales configurados para evaluar la lectura.</li>
            </ul>
            <p class="u-muted">No se declara desconexión del ESP32 solo por falta de lecturas. La desconexión debe confirmarse con el mecanismo de conexión real (<code>dispositivoConectado</code> o histórico).</p>
        </div>
    </section>
</div>

<?php include 'includes/footer.php'; ?>
