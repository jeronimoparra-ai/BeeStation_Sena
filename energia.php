<?php include 'includes/header.php'; ?>

<div class="page-header">
    <div>
        <h1 class="page-title">Gestión Energética</h1>
        <p class="page-subtitle">Sistema fotovoltaico autónomo · Datos reales del ESP32</p>
    </div>
</div>

<div class="card page-hero animate-fadeUp stagger-1">
    <div class="page-hero-copy">
        <span class="hero-eyebrow">Energía del sistema</span>
        <h2>Una vista preparada para el módulo fotovoltaico y la salud eléctrica del campo.</h2>
        <p class="page-subtitle">El panel de energía queda presentado como una superficie de estado técnico lista para integrarse con lectura real, sin inventar datos ni alterar el flujo del sistema.</p>
        <div class="page-hero-grid">
            <div class="page-kpi-card">
                <span>Estado</span>
                <strong>Pendiente de integrar</strong>
            </div>
            <div class="page-kpi-card">
                <span>Fuente</span>
                <strong>ESP32 + panel solar</strong>
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
        <div class="hero-chip"><i data-lucide="battery"></i><span>Panel energético reservado</span></div>
        <div class="hero-chip"><i data-lucide="sun-medium"></i><span>Preparado para potencia solar</span></div>
        <div class="hero-chip"><i data-lucide="plug-zap"></i><span>Conector lógico con api/ingest.php</span></div>
    </div>
</div>

<div class="card premium-empty animate-fadeUp stagger-2">
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
