<?php include 'includes/header.php'; ?>

<div class="page-header">
    <div>
        <h1 class="page-title">Gestión energética</h1>
        <p class="page-subtitle">No disponible en el nodo actual · sin INA219, batería ni panel solar</p>
    </div>
</div>

<div class="card page-hero animate-fadeUp stagger-1">
    <div class="page-hero-copy">
        <span class="hero-eyebrow">Energía del sistema</span>
        <h2>Este módulo todavía no mide nada.</h2>
        <p class="page-subtitle">La página queda fuera del menú hasta que exista un sensor de energía real. No se muestran voltajes, batería ni potencia inventados.</p>
        <div class="page-hero-grid">
            <div class="page-kpi-card">
                <span>Estado</span>
                <strong>No instalado</strong>
            </div>
            <div class="page-kpi-card">
                <span>Requisito</span>
                <strong>INA219 + batería + panel</strong>
            </div>
        </div>
    </div>
    <div class="page-hero-aside">
        <div class="premium-banner">
            <div>
                <div class="subtle-note u-mb-1">Lectura futura</div>
                <div class="card-title">Voltaje, batería y potencia</div>
            </div>
            <span class="badge badge-neutral">Blueprint</span>
        </div>
        <div class="hero-chip"><i data-lucide="battery"></i><span>Sin lectura de batería</span></div>
        <div class="hero-chip"><i data-lucide="sun-medium"></i><span>Sin lectura solar</span></div>
        <div class="hero-chip"><i data-lucide="plug-zap"></i><span>No conectado a ingest</span></div>
    </div>
</div>

<div class="card premium-empty animate-fadeUp stagger-2">
    <div class="empty-icon-circle"><i data-lucide="battery" class="u-icon-lg"></i></div>
    <h2 class="empty-title">Módulo de energía no instalado</h2>
    <p class="empty-desc">
        Esta vista solo se activará cuando se agregue un sensor de energía real (INA219, batería y panel solar)
        y el nodo empiece a reportarlo por <code>api/ingest.php</code>. Hasta entonces no se muestran valores de ejemplo.
    </p>
</div>

<?php include 'includes/footer.php'; ?>
