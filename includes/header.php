<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

// Colmena activa real del usuario (puede ser null si aún no tiene ninguna)
$colmenaActiva = obtenerColmenaActiva($_SESSION['id_usuario']);

// Estado de conexión real: revisa si algún sensor de la colmena activa
// ha reportado datos en los últimos 5 minutos
$espOnline = false;
if ($colmenaActiva) {
    $espOnline = dispositivoConectado($colmenaActiva['id_colmena']);
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

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=Inter:wght@400;500;600;700&family=Sora:wght@600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="css/style.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php if (basename($_SERVER['PHP_SELF']) !== 'login.php'): ?>
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

                <label class="topbar-search" title="Buscar">
                    <i data-lucide="search"></i>
                    <input type="search" placeholder="Buscar sensores, lecturas o estados" autocomplete="off">
                </label>

                <div class="topbar-right">
                    <div class="esp-status-pill <?= $espOnline ? 'online' : 'offline' ?>">
                        <div class="status-dot <?= $espOnline ? '' : 'offline' ?>"></div>
                        <span class="esp-label"><?= $espOnline ? 'ESP32 online' : 'Sin datos recientes' ?></span>
                    </div>

                    <div class="topbar-divider"></div>

                    <div class="clock" id="topbarClock">--:--</div>

                    <button class="topbar-btn" id="darkModeToggle" title="Modo oscuro">
                        <i data-lucide="moon"></i>
                    </button>

                    <button class="notifications-btn topbar-btn" title="Notificaciones">
                        <i data-lucide="bell"></i>
                    </button>
                </div>
            </header>

            <div class="page-content">
    <?php endif; ?>
