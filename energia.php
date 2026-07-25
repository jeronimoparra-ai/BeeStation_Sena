<?php include 'includes/header.php'; ?>

<div class="page-header">
    <div>
        <h1 class="page-title">Gestión Energética</h1>
        <p class="page-subtitle">Sistema fotovoltaico autónomo · Datos reales del ESP32</p>
    </div>
</div>

<div class="card empty-state">
    <div class="empty-icon-circle"><i data-lucide="battery" class="u-icon-lg"></i></div>
    <h2 class="empty-title">Módulo de energía pendiente de integrar</h2>
    <p class="empty-desc">
        Esta vista quedará conectada a la base de datos igual que las demás en cuanto se agregue
        un sensor de tipo <code>energia</code> (voltaje de batería / potencia del panel solar) a la
        tabla <strong>sensor</strong> y el ESP32 empiece a reportarlo por <code>api/ingest.php</code>.
        No se muestran valores de ejemplo para evitar confundir datos reales con datos ficticios.
    </p>
</div>

<button class="btn-floating" title="Ayuda"><i data-lucide="help-circle"></i></button>

<?php include 'includes/footer.php'; ?>
