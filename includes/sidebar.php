<!-- includes/sidebar.php -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-brand">
            <div class="sidebar-logo-frame">
                <img src="assets/logo-beestation.png" alt="BeeStation Logo" class="sidebar-logo">
            </div>
            <div class="sidebar-brand-copy">
                <span class="sidebar-kicker">Monitoreo apícola</span>
                <span class="sidebar-title">BeeStation</span>
            </div>
        </div>
    </div>

    <div class="sidebar-system-pill">
        <span>Monitoreo IoT</span>
        <span><?= $espOnline ? 'En línea' : 'Sin señal' ?></span>
    </div>

    <div class="sidebar-summary">
        <div class="sidebar-summary-label">Colmena activa</div>

        <?php
        $colmenasUsuario = function_exists('obtenerColmenasDelUsuario')
            ? obtenerColmenasDelUsuario((int) ($_SESSION['id_usuario'] ?? 0))
            : [];
        $paginaActual = basename($_SERVER['PHP_SELF']);
        ?>

        <?php if (count($colmenasUsuario) > 1): ?>
            <form method="POST" action="seleccionar_colmena.php" class="sidebar-hive-form">
                <input type="hidden" name="volver" value="<?= htmlspecialchars($paginaActual) ?>">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_colmena'] ??= bin2hex(random_bytes(16))) ?>">
                <select name="id_colmena" class="sidebar-hive-select"
                        onchange="this.form.submit()" aria-label="Seleccionar colmena activa">
                    <?php foreach ($colmenasUsuario as $c): ?>
                        <option value="<?= (int) $c['id_colmena'] ?>"
                            <?= ($colmenaActiva && (int) $colmenaActiva['id_colmena'] === (int) $c['id_colmena']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript>
                    <button type="submit" class="sidebar-hive-btn">Cambiar</button>
                </noscript>
            </form>
        <?php else: ?>
            <div class="sidebar-summary-name">
                <?= $colmenaActiva ? htmlspecialchars($colmenaActiva['nombre']) : 'Sin colmena asignada' ?>
            </div>
        <?php endif; ?>

        <div class="sidebar-summary-status">
            <a href="conectar_dispositivo.php" style="text-decoration: none;" title="Abrir radar de conexión">
                <span class="sidebar-status-chip <?= $espOnline ? 'online' : 'offline' ?> hover-effect">
                    <span class="status-dot <?= $espOnline ? '' : 'offline' ?>"></span>
                    <?= $espOnline ? 'ESP32 en línea' : 'ESP32 sin reportar' ?>
                </span>
            </a>
            <span class="sidebar-summary-time"><?= $colmenaActiva ? htmlspecialchars($colmenaActiva['estado']) : 'N/A' ?></span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-group">
            <div class="nav-group-title">Panel</div>
            <a href="dispositivos.php" class="nav-item <?= is_active('dispositivos.php') ?>" data-name="Dispositivos">
                <i data-lucide="link-2"></i>
                Dispositivos
            </a>
            <a href="dashboard.php" class="nav-item <?= is_active('dashboard.php') ?>" data-name="Resumen">
                <i data-lucide="layout-dashboard"></i>
                Resumen
            </a>
        </div>

        <div class="nav-group">
            <div class="nav-group-title">Análisis</div>
            <a href="acustica.php" class="nav-item <?= is_active('acustica.php') ?>" data-name="Actividad acústica">
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
            <a href="sensores.php" class="nav-item <?= is_active('sensores.php') ?>" data-name="Sensores">
                <i data-lucide="settings-2"></i>
                Sensores
            </a>
            <a href="informe.php" class="nav-item <?= is_active('informe.php') ?>" data-name="Informe">
                <i data-lucide="file-text"></i>
                Informe
            </a>
            <a href="centro-alertas.php" class="nav-item <?= is_active('centro-alertas.php') ?>" data-name="Centro de alertas">
                <i data-lucide="bell"></i>
                Alertas
            </a>
        </div>
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="avatar"><?= strtoupper(substr($_SESSION['nombre'] ?? 'U', 0, 1)) ?></div>
            <div class="user-info">
                <span class="user-name"><?= htmlspecialchars($_SESSION['nombre'] ?? '') ?></span>
                <span class="user-email"><?= htmlspecialchars($_SESSION['correo'] ?? '') ?></span>
            </div>
            <a href="logout.php" title="Cerrar sesión" class="logout-btn">
                <i data-lucide="log-out"></i>
            </a>
        </div>
        <div class="sidebar-footer-note text-xs u-muted-strong u-mt-2">Sesión iniciada</div>
    </div>
</aside>
