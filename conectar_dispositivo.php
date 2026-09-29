<?php
// conectar_dispositivo.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['id_usuario'])) {
    header("Location: login.php");
    exit;
}

$id_usuario = $_SESSION['id_usuario'];
$colmenaActiva = obtenerColmenaActiva($id_usuario);
$id_colmena = $colmenaActiva ? (int)$colmenaActiva['id_colmena'] : 0;
$pdo = getPDO();

if ($id_colmena === 0) {
    header("Location: dispositivos.php");
    exit;
}

if (isset($_GET['check'])) {
    header('Content-Type: application/json; charset=utf-8');
    $conectado = false;
    $ultima_fecha = null;
    $nombre_colmena = "";
    $intento_fallido = null;

    if ($id_colmena > 0) {
        $conectado = dispositivoConectado($id_colmena, 10);
        $ultima_fecha = ultimaConexion($id_colmena);
        $nombre_colmena = $colmenaActiva['nombre'];

        if (!$conectado) {
            $stmtFallo = $pdo->prepare("
                SELECT token_recibido, fecha_hora
                FROM intento_vinculacion
                WHERE resultado = 'token_invalido'
                  AND fecha_hora >= (NOW() - INTERVAL 10 MINUTE)
                ORDER BY fecha_hora DESC
                LIMIT 1
            ");
            $stmtFallo->execute();
            $fallo = $stmtFallo->fetch();

            if ($fallo) {
                $tk = $fallo['token_recibido'];
                $tokenParcial = strlen($tk) > 6
                    ? substr($tk, 0, 3) . str_repeat('•', max(0, strlen($tk) - 6)) . substr($tk, -3)
                    : $tk;

                $intento_fallido = [
                    'token_parcial' => htmlspecialchars($tokenParcial),
                    'coincide' => hash_equals((string)$colmenaActiva['token_vinculacion'], (string)$tk),
                    'hace' => tiempoRelativo($fallo['fecha_hora'])
                ];
            }
        }
    }

    echo json_encode([
        'conectado' => $conectado,
        'ultima_conexion' => $ultima_fecha ? tiempoRelativo($ultima_fecha) : 'sin conexión',
        'nombre_colmena' => htmlspecialchars($nombre_colmena),
        'intento_fallido' => $intento_fallido
    ]);
    exit;
}

$conectado = false;
if ($id_colmena > 0) {
    $conectado = dispositivoConectado($id_colmena, 10);
}

if ($conectado) {
    header("Location: dashboard.php");
    exit;
}

// Si el dispositivo ya envió datos antes, no hay razón para bloquear
// al usuario en la pantalla de espera. Ir directo al dashboard donde
// verá el indicador "Sincronización detenida" en la topbar si el ESP32
// no está transmitiendo activamente.
if ($id_colmena > 0 && dispositivoTieneHistorial($id_colmena)) {
    header("Location: dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BeeStation — Sincronizando Colmena</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/style-premium.css">
    <style>
        @keyframes radar-pulse {
            0% { transform: scale(0.95); opacity: 0.8; }
            50% { transform: scale(1.1); opacity: 0.4; }
            100% { transform: scale(0.95); opacity: 0.8; }
        }
        .radar-glow { animation: radar-pulse 2.5s infinite ease-in-out; }
        .loader-dots span {
            animation: blink 1.4s infinite both;
            display: inline-block;
            width: 6px; height: 6px;
            border-radius: 50%;
            background-color: currentColor;
            margin: 0 2px;
        }
        .loader-dots span:nth-child(2) { animation-delay: .2s; }
        .loader-dots span:nth-child(3) { animation-delay: .4s; }
        @keyframes blink { 0% { opacity: .2; } 20% { opacity: 1; } 100% { opacity: .2; } }

        .diag-box {
            display: none;
            text-align: left;
            gap: 10px;
            margin: 18px 0 6px;
            padding: 14px 16px;
            border-radius: 16px;
            border: 1px solid rgba(220, 38, 38, 0.3);
            background: rgba(220, 38, 38, 0.1);
            color: #FFD9D9;
        }
        .diag-box.show { display: flex; align-items: flex-start; }
        .diag-box svg { width: 18px; height: 18px; margin-top: 2px; flex-shrink: 0; color: #FF6D6D; }
        .diag-box code {
            background: rgba(0,0,0,0.3);
            color: #FFD166;
            padding: 1px 6px;
            border-radius: 6px;
        }
    </style>
</head>
<body class="connect-body">

    <div class="connect-shell">
        <div class="connect-card animate-fadeUp">

            <div id="connect-status-icon" class="connect-icon offline radar-glow">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M5 12.55a11 11 0 0 1 14.08 0"></path>
                    <path d="M1.42 9a16 16 0 0 1 21.16 0"></path>
                    <path d="M8.53 16.11a6 6 0 0 1 6.95 0"></path>
                    <line x1="12" y1="20" x2="12.01" y2="20" stroke-width="3"></line>
                </svg>
            </div>

            <h1 id="connect-title" class="page-title u-mt-3">Esperando Señal del Nodo</h1>

            <p id="connect-copy-text" class="connect-copy">
                El sistema está buscando transmisiones de tu colmena
                <strong><?= $colmenaActiva ? htmlspecialchars($colmenaActiva['nombre']) : 'sin nombre'; ?></strong>
                con el token:
                <code><?= $colmenaActiva ? htmlspecialchars($colmenaActiva['token_vinculacion']) : 'N/A'; ?></code>.
            </p>

            <div id="connect-loader" class="loader-dots text-brand u-mb-3" style="font-size: 1.5rem; display: flex; justify-content: center; align-items: center; gap: 4px;">
                <span style="width: 8px; height: 8px;"></span>
                <span style="width: 8px; height: 8px;"></span>
                <span style="width: 8px; height: 8px;"></span>
            </div>

            <div id="diag-box" class="diag-box">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                    <line x1="12" y1="9" x2="12" y2="13"></line>
                    <line x1="12" y1="17" x2="12.01" y2="17"></line>
                </svg>
                <div>
                    <strong style="display:block; margin-bottom:2px;">Diagnóstico automático</strong>
                    <span id="diag-text"></span>
                </div>
            </div>

            <div class="warning-alert animate-fadeIn stagger-2">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="16" x2="12" y2="12"></line>
                    <line x1="12" y1="8" x2="12.01" y2="8"></line>
                </svg>
                <div>
                    <strong style="display: block; margin-bottom: 2px;">¿Cómo iniciar la conexión?</strong>
                    Enciende tu ESP32, conéctate desde tu celular al WiFi <strong>`BeeStation_Config`</strong> y completa el formulario del portal cautivo local para inyectar este token único. Si el token quedó mal escrito, el ESP32 reabrirá esta red automáticamente y mostrará el motivo del fallo en el propio portal.
                </div>
            </div>

            <div class="action-buttons u-mt-4">
                <a href="dispositivos.php" class="btn btn-secondary btn-full">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="19" y1="12" x2="5" y2="12"></line>
                        <polyline points="12 19 5 12 12 5"></polyline>
                    </svg>
                    <span>Ver mis Colmenas</span>
                </a>
            </div>

            <div class="connect-footer">
                ApiTechnology SENA — Monitoreo Bioclimático
            </div>
        </div>
    </div>

    <script>
        const checkUrl = 'conectar_dispositivo.php?check=1';
        const iconContainer = document.getElementById('connect-status-icon');
        const titleText = document.getElementById('connect-title');
        const copyText = document.getElementById('connect-copy-text');
        const dotsLoader = document.getElementById('connect-loader');
        const diagBox = document.getElementById('diag-box');
        const diagText = document.getElementById('diag-text');

        function mostrarDiagnostico(info) {
            if (!info) {
                diagBox.classList.remove('show');
                return;
            }
            diagText.innerHTML = info.coincide
                ? `Tu ESP32 está enviando el token correcto (<code>${info.token_parcial}</code>) pero el servidor lo rechazó hace ${info.hace}. Es posible que el token haya sido revocado.`
                : `Detectamos un intento de vinculación reciente con un token distinto (<code>${info.token_parcial}</code>) hace ${info.hace}. Revisa que copiaste el token exacto de esta colmena en el portal del ESP32.`;
            diagBox.classList.add('show');
        }

        function verificarEstadoConexion() {
            fetch(checkUrl)
                .then(response => {
                    if (!response.ok) throw new Error('La respuesta del servidor no fue exitosa');
                    return response.json();
                })
                .then(data => {
                    if (data.conectado) {
                        dotsLoader.style.display = 'none';
                        diagBox.classList.remove('show');
                        iconContainer.classList.remove('offline', 'radar-glow');
                        iconContainer.classList.add('online');

                        iconContainer.innerHTML = `
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                        `;

                        titleText.innerText = "¡Colmena Conectada!";
                        titleText.style.color = "var(--color-success)";
                        copyText.innerHTML = `¡Estupendo! Detectamos telemetría en tiempo real desde el nodo <strong>${data.nombre_colmena}</strong>.<br><span style='color: var(--color-success); font-weight:600;'>Sincronizando Dashboard...</span>`;

                        setTimeout(() => { window.location.href = 'dashboard.php'; }, 1500);
                    } else {
                        mostrarDiagnostico(data.intento_fallido);
                        setTimeout(verificarEstadoConexion, 2000);
                    }
                })
                .catch(error => {
                    console.warn("Fallo temporal de consulta de red:", error);
                    setTimeout(verificarEstadoConexion, 3000);
                });
        }

        document.addEventListener('DOMContentLoaded', verificarEstadoConexion);
    </script>
</body>
</html>