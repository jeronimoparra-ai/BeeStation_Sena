<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

// Colmena activa real del usuario (puede ser null si aún no tiene ninguna)
$colmenaActiva = obtenerColmenaActiva($_SESSION['id_usuario']);

// Estado de conexión real: revisa si algún sensor de la colmena activa
// ha reportado datos en los últimos 5 minutos
$espOnline = false;
$espTieneHistorial = false;
$ultimaConexionTexto = '';
if ($colmenaActiva) {
    $espOnline = dispositivoConectado($colmenaActiva['id_colmena']);
    if (!$espOnline) {
        $espTieneHistorial = dispositivoTieneHistorial($colmenaActiva['id_colmena']);
        if ($espTieneHistorial) {
            $fechaUltima = ultimaConexion($colmenaActiva['id_colmena']);
            $ultimaConexionTexto = $fechaUltima ? tiempoRelativo($fechaUltima) : '';
        }
    }
}

// Saludo contextual según hora del día
$hora = (int) date('H');
if ($hora >= 5 && $hora < 12) $saludo = 'Buenos días';
elseif ($hora >= 12 && $hora < 19) $saludo = 'Buenas tardes';
else $saludo = 'Buenas noches';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BeeStation</title>
    <script>
        if (localStorage.getItem('beestation-dark') === 'true') {
            document.documentElement.classList.add('dark');
        }
    </script>

    <link rel="preload" href="assets/fonts/manrope-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="assets/fonts/bricolage-grotesque-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="css/fonts.css?v=<?= filemtime(__DIR__ . '/../css/fonts.css') ?>">
    <link rel="stylesheet" href="css/style-premium.css?v=<?= filemtime(__DIR__ . '/../css/style-premium.css') ?>">
    <link rel="stylesheet" href="css/redesign.css?v=<?= filemtime(__DIR__ . '/../css/redesign.css') ?>">
    <!-- Recursos locales: el panel debe funcionar en la LAN del apiario, sin internet. -->
    <script src="assets/vendor/lucide.min.js"></script>
    <script src="assets/vendor/chart.umd.min.js"></script>
</head>
<body>
    <?php if (!in_array(basename($_SERVER['PHP_SELF']), ['login.php', 'registro.php'], true)): ?>
    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <main class="main-content">
            <header class="topbar">
                <div class="topbar-left">
                    <button class="mobile-menu-btn" id="mobileMenuBtn" title="Abrir navegación">
                        <i data-lucide="menu"></i>
                    </button>

                    <div class="topbar-greeting">
                        <span class="topbar-eyebrow"><?= $saludo ?>, <?= htmlspecialchars(explode(' ', trim($_SESSION['nombre'] ?? 'usuario'))[0] ?: 'usuario') ?></span>
                        <div class="topbar-title-row">
                            <span class="topbar-section" id="currentSectionName">Sección</span>
                            <span class="hive-chip" title="Colmena activa">
                                <i data-lucide="hexagon"></i>
                                <span><?= $colmenaActiva ? htmlspecialchars($colmenaActiva['nombre']) : 'Sin colmena activa' ?></span>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="topbar-right">
                    <?php if ($espOnline): ?>
                        <a href="dashboard.php" class="esp-status-pill online" style="text-decoration: none; cursor: pointer;" title="Dispositivo transmitiendo en tiempo real">
                            <div class="status-dot"></div>
                            <span class="esp-label">ESP32 en línea</span>
                        </a>
                    <?php elseif ($espTieneHistorial): ?>
                        <a href="conectar_dispositivo.php" class="esp-status-pill warning" style="text-decoration: none; cursor: pointer;" title="Última conexión: <?= htmlspecialchars($ultimaConexionTexto) ?>. El ESP32 tiene la configuración guardada y debería reconectarse automáticamente.">
                            <div class="status-dot warning"></div>
                            <span class="esp-label">Sin reportar (<?= htmlspecialchars($ultimaConexionTexto) ?>)</span>
                        </a>
                    <?php else: ?>
                        <a href="conectar_dispositivo.php" class="esp-status-pill offline" style="text-decoration: none; cursor: pointer;" title="El ESP32 nunca se ha conectado. Configúralo con el Portal Cautivo.">
                            <div class="status-dot offline"></div>
                            <span class="esp-label">Sin configurar</span>
                        </a>
                    <?php endif; ?>

                    <div class="topbar-divider"></div>

                    <div class="clock" id="topbarClock">--:--</div>

                    <button class="topbar-btn" id="darkModeToggle" title="Modo claro / oscuro">
                        <i data-lucide="moon"></i>
                    </button>
                </div>
            </header>

            <div class="page-content">
    <?php endif; ?>
