<?php include 'includes/header.php'; ?>

<div class="page-header">
    <div>
        <h1 class="page-title">Dispositivos</h1>
        <p class="page-subtitle">Gestión de dispositivos ESP32 · Envío de datos vía HTTP a la API</p>
    </div>
</div>

<?php if ($colmenaActiva): ?>
<?php 
$conectado = dispositivoConectado($colmenaActiva['id_colmena']);
$ultima_conexion = ultimaConexion($colmenaActiva['id_colmena']);
?>
<div class="card device-status-card">
    <div class="card-header"><div class="card-title">Estado de Conexión</div></div>
    <div class="device-status-row">
        <div class="status-dot device-status-dot <?= $conectado ? '' : 'offline' ?>"></div>
        <span class="device-status-label"><?= $conectado ? 'Dispositivo Conectado' : 'Sin conexión reciente' ?></span>
    </div>
    <p class="text-secondary u-mb-2">
        Última lectura recibida: <strong><?= tiempoRelativo($ultima_conexion) ?></strong>
    </p>
    <p class="text-secondary text-sm">
        La conexión es por red. El ESP32 no se empareja con el navegador, envía datos por WiFi al servidor.
        En cuanto llegan esos datos alimentan automáticamente las gráficas de tendencia de Resumen, Peso y Acústica.
    </p>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><div class="card-title">Endpoint de ingreso de datos</div></div>
    <p class="text-secondary u-mb-3">
        El ESP32 (o cualquier dispositivo) envía los datos reales mediante una petición
        <strong>POST</strong> en formato JSON a esta URL:
    </p>
    <div class="endpoint-box">
        POST http://TU_SERVIDOR/api/ingest.php
    </div>
    <p class="text-secondary text-sm">Consulta <strong>README.txt</strong> en la raíz del proyecto para ver el código Arduino de ejemplo que hace este envío.</p>
</div>

<button class="btn-floating" title="Ayuda"><i data-lucide="help-circle"></i></button>

<?php include 'includes/footer.php'; ?>
