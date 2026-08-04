<!-- includes/sidebar.php -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-brand">
            <div class="sidebar-logo-frame">
                <img src="assets/logo-beestation.png" alt="BeeStation Logo" class="sidebar-logo">
            </div>
            <div class="sidebar-brand-copy">
                <span class="sidebar-kicker">Hive OS</span>
                <span class="sidebar-title">BeeStation</span>
            </div>
        </div>
    </div>

    <div class="sidebar-system-pill">
        <span>Monitoreo IoT</span>
        <span>Live</span>
    </div>

    <div class="sidebar-summary">
        <div class="sidebar-summary-label">Colmena activa</div>
        <div class="sidebar-summary-name">
            <?= $colmenaActiva ? htmlspecialchars($colmenaActiva['nombre']) : 'Sin colmena asignada' ?>
        </div>
        <div class="sidebar-summary-status">
            <span class="sidebar-status-chip <?= $espOnline ? 'online' : 'offline' ?>">
                <span class="status-dot <?= $espOnline ? '' : 'offline' ?>"></span>
                <?= $espOnline ? 'ESP32 sincronizado' : 'Sincronización detenida' ?>
            </span>
            <span class="sidebar-summary-time"><?= $colmenaActiva ? htmlspecialchars($colmenaActiva['estado']) : 'N/A' ?></span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-group">
            <div class="nav-group-title">Panel</div>
            <a href="dashboard.php" class="nav-item <?= is_active('dashboard.php') ?>" data-name="Resumen">
                <i data-lucide="layout-dashboard"></i>
                Resumen
            </a>
            <a href="dispositivos.php" class="nav-item <?= is_active('dispositivos.php') ?>" data-name="Dispositivos">
                <i data-lucide="link-2"></i>
                Dispositivos
            </a>
        </div>

        <div class="nav-group">
            <div class="nav-group-title">Análisis</div>
            <a href="acustica.php" class="nav-item <?= is_active('acustica.php') ?>" data-name="Análisis Acústico">
                <i data-lucide="activity"></i>
                Acústica
            </a>
            <a href="peso.php" class="nav-item <?= is_active('peso.php') ?>" data-name="Peso">
                <i data-lucide="scale"></i>
                Peso
            </a>
        </div>

        <div class="nav-group">
            <div class="nav-group-title">Sistema</div>
            <a href="energia.php" class="nav-item <?= is_active('energia.php') ?>" data-name="Energía">
                <i data-lucide="battery-charging"></i>
                Energía
            </a>
            <a href="sensores.php" class="nav-item <?= is_active('sensores.php') ?>" data-name="Sensores">
                <i data-lucide="settings-2"></i>
                Sensores
            </a>
        </div>
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="avatar"><?= strtoupper(substr($_SESSION['nombre'] ?? 'U', 0, 1)) ?></div>
            <div class="user-info">
                <span class="user-name"><?= htmlspecialchars($_SESSION['nombre'] ?? '') ?></span>
                <span class="user-email"><?= htmlspecialchars($_SESSION['correo'] ?? '') ?></span>
                <span class="user-role"><?= htmlspecialchars($_SESSION['rol'] ?? 'Operador') ?></span>
            </div>
            <a href="logout.php" title="Cerrar sesión" class="logout-btn">
                <i data-lucide="log-out"></i>
            </a>
        </div>
        <div class="sidebar-footer-note text-xs u-muted-strong u-mt-2">Sesión segura · interfaz premium</div>
    </div>
</aside>
