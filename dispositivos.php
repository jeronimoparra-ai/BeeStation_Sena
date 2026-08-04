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
<div class="card page-hero animate-fadeUp stagger-1">
    <div class="page-hero-copy">
        <span class="hero-eyebrow">Conectividad de campo</span>
        <h2>Estado operativo del ESP32 y flujo de datos hacia BeeStation.</h2>
        <p class="page-subtitle">La conexión es por red. El ESP32 no se empareja con el navegador, sino que envía datos reales por WiFi al servidor para alimentar el panel en tiempo real.</p>
        <div class="page-hero-grid">
            <div class="page-kpi-card">
                <span>Última lectura</span>
                <strong><?= tiempoRelativo($ultima_conexion) ?></strong>
            </div>
            <div class="page-kpi-card">
                <span>Estado</span>
                <strong><?= $conectado ? 'Online' : 'Sin señal' ?></strong>
            </div>
        </div>
    </div>
    <div class="page-hero-aside">
        <div class="premium-banner">
            <div>
                <div class="subtle-note u-mb-1">Endpoint de ingestión</div>
                <div class="card-title">api/ingest.php</div>
            </div>
            <span class="badge <?= $conectado ? 'badge-success' : 'badge-critical' ?>"><?= $conectado ? 'Activo' : 'Pendiente' ?></span>
        </div>
        <div class="hero-chip">
            <i data-lucide="wifi"></i>
            <span><?= $conectado ? 'Dispositivo conectado' : 'Sin conexión reciente' ?></span>
        </div>
        <div class="hero-chip">
            <i data-lucide="database"></i>
            <span>Lecturas reales hacia la base de datos</span>
        </div>
        <div class="hero-chip">
            <i data-lucide="activity"></i>
            <span>Actualiza Resumen, Peso y Acústica</span>
        </div>
    </div>
</div>

<div class="card premium-empty device-status-card animate-fadeUp stagger-2">
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

<div class="card premium-empty animate-fadeUp stagger-3">
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
