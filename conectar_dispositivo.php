<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$colmenaActiva = obtenerColmenaActiva($_SESSION['id_usuario']);
$id_colmena = $colmenaActiva ? $colmenaActiva['id_colmena'] : 0;

$conectado = false;
$ultima_conexion = null;

if ($id_colmena > 0) {
    $conectado = dispositivoConectado($id_colmena);
    $ultima_conexion = ultimaConexion($id_colmena);
}

// Si es un POST (Verificar conexión) y está conectado, redirigir
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verificar'])) {
    if ($conectado) {
        header("Location: dashboard.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conectar Dispositivo - BeeStation</title>
    <script>
        if (localStorage.getItem('beestation-dark') === 'true') {
            document.documentElement.classList.add('dark');
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=Inter:wght@400;500;600;700&family=Sora:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style-premium.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="connect-body">
    <div class="connect-shell">
        <div class="connect-card">
            <?php if ($conectado): ?>
                <div class="connect-icon online">
                    <i data-lucide="wifi"></i>
                </div>
                <span class="connect-eyebrow"><i data-lucide="radio"></i> Señal recibida</span>
                <h1>Dispositivo Conectado</h1>
                <p class="connect-copy">
                    El sistema está recibiendo datos correctamente.<br>
                    Última recepción: <strong><?= tiempoRelativo($ultima_conexion) ?></strong>
                </p>
                <div class="action-buttons">
                    <a href="dashboard.php" class="btn btn-brand btn-full">
                        <i data-lucide="layout-dashboard"></i>
                        Ir al Dashboard
                    </a>
                </div>
            <?php else: ?>
                <div class="connect-icon offline">
                    <i data-lucide="wifi-off"></i>
                </div>
                <span class="connect-eyebrow"><i data-lucide="scan-line"></i> Verificación ESP32</span>
                <h1>Buscando dispositivo...</h1>
                <p class="connect-copy">
                    Enciende el ESP32 y conéctalo a la red WiFi configurada. El sistema lo detectará automáticamente al recibir datos en <code>api/ingest.php</code>.
                </p>
                
                <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verificar'])): ?>
                    <div class="warning-alert">
                        <i data-lucide="triangle-alert"></i>
                        <span>Todavía no se detectan datos recientes. Asegúrate de que el dispositivo tenga acceso a internet y esté enviando información a la API.</span>
                    </div>
                <?php endif; ?>

                <div class="action-buttons">
                    <form action="conectar_dispositivo.php" method="POST" class="form-reset">
                        <input type="hidden" name="verificar" value="1">
                        <button type="submit" class="btn btn-brand btn-full">
                            <i data-lucide="refresh-cw"></i>
                            Verificar conexión
                        </button>
                    </form>
                    <a href="dashboard.php" class="btn btn-secondary btn-full">
                        <i data-lucide="arrow-right"></i>
                        Continuar sin verificar (modo prueba)
                    </a>
                </div>
            <?php endif; ?>
            <div class="connect-footer">BeeStation · Monitoreo IoT de colmenas</div>
        </div>
    </div>
    <script>
        if (window.lucide) lucide.createIcons();
    </script>
</body>
</html>
