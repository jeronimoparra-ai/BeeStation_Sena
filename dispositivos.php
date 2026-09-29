<?php
/**
 * BeeStation — Centro de Dispositivos y Aprovisionamiento IoT
 * Centro Minero Ambiental - SENA, El Bagre (Antioquia)
 * 
 * Este archivo gestiona el registro de colmenas del usuario, genera
 * automáticamente sus tokens alfanuméricos de vinculación para el ESP32
 * y autoprovisiona sus respectivos sensores en la base de datos.
 */

// Iniciamos la sesión de forma segura
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Control de acceso y carga de librerías oficiales
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

// Definimos la página activa para el sidebar
$current_page = 'dispositivos.php';

$id_usuario = $_SESSION['id_usuario'] ?? 1; // ID de sesión del apicultor
$pdo = getPDO();

// MEJORA DE USABILIDAD: Si el apicultor no tiene apiarios, le creamos uno por defecto automáticamente
$stmtCheckApiario = $pdo->prepare("SELECT COUNT(*) AS total FROM apiario WHERE id_usuario = ?");
$stmtCheckApiario->execute([$id_usuario]);
$tiene_apiarios = (int)$stmtCheckApiario->fetch()['total'] > 0;

if (!$tiene_apiarios) {
    // Insertamos un apiario principal inicial para que el selector nunca aparezca vacío
    $stmtAutoApiario = $pdo->prepare("
        INSERT INTO apiario (nombre, ubicacion, municipio, id_usuario) 
        VALUES ('Apiario Principal', 'API_DEMO_SENA', 'El Bagre, Antioquia', ?)
    ");
    $stmtAutoApiario->execute([$id_usuario]);
}

$mensaje_exito = "";
$mensaje_error = "";

// ── PROCESS POST: REGISTRO DE NUEVA COLMENA ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_colmena'])) {
    $nombre = trim($_POST['nombre'] ?? '');
    $especie = trim($_POST['especie'] ?? 'Apis mellifera');
    $id_apiario = (int)($_POST['id_apiario'] ?? 0);

    if (empty($nombre)) {
        $mensaje_error = "El nombre de la colmena es obligatorio.";
    } elseif ($id_apiario === 0) {
        $mensaje_error = "Debes seleccionar un apiario válido.";
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Generamos algorítmicamente un token seguro de vinculación
            $token_seguro = generarTokenVinculacion($nombre);

            // 2. Insertamos la nueva colmena con su respectivo token
            $stmtCol = $pdo->prepare("
                INSERT INTO colmena (nombre, especie, fecha_instalacion, estado, token_vinculacion, id_apiario) 
                VALUES (?, ?, CURDATE(), 'activa', ?, ?)
            ");
            $stmtCol->execute([$nombre, $especie, $token_seguro, $id_apiario]);
            $id_nueva_colmena = $pdo->lastInsertId();

            // 3. Autoprovisionamiento: Registramos automáticamente sus 7 sensores oficiales
            // Esto evita errores de bases de datos vacías para el usuario final.
            $sensores_por_defecto = [
                ['tipo' => 'temperatura_interna', 'modelo' => 'DHT22', 'min' => -40, 'max' => 80, 'prec' => 0.5, 'var' => 1],
                ['tipo' => 'temperatura_externa', 'modelo' => 'DHT22', 'min' => -40, 'max' => 80, 'prec' => 0.5, 'var' => 2],
                ['tipo' => 'humedad_relativa',   'modelo' => 'DHT22', 'min' => 0,   'max' => 100, 'prec' => 3.0, 'var' => 3],
                ['tipo' => 'peso',               'modelo' => 'HX711', 'min' => 0,   'max' => 50,  'prec' => 0.01, 'var' => 4],
                ['tipo' => 'sonido',             'modelo' => 'MAX9814','min' => 20,  'max' => 20000,'prec' => null, 'var' => 5],
                ['tipo' => 'co2',                'modelo' => 'MQ-135', 'min' => 10,  'max' => 300,  'prec' => null, 'var' => 6],
                ['tipo' => 'energia',            'modelo' => 'INA219', 'min' => 0,   'max' => 5,   'prec' => 0.01, 'var' => 7]
            ];

            $stmtSens = $pdo->prepare("
                INSERT INTO sensor (tipo, modelo, rango_min, rango_max, precision_valor, estado, fecha_instalacion, id_colmena, id_variable) 
                VALUES (?, ?, ?, ?, ?, 'sin_senal', CURDATE(), ?, ?)
            ");

            foreach ($sensores_por_defecto as $s) {
                $stmtSens->execute([
                    $s['tipo'], $s['modelo'], $s['min'], $s['max'], $s['prec'], $id_nueva_colmena, $s['var']
                ]);
            }

            $pdo->commit();
            $mensaje_exito = "¡Colmena '<strong>{$nombre}</strong>' registrada con éxito! Token generado: <code>{$token_seguro}</code>.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $mensaje_error = "Error al registrar la colmena: " . $e->getMessage();
        }
    }
}

// ── CONSULTAS DE DATOS: Apiarios y Colmenas del Usuario ──────────────────────
// Obtenemos los apiarios del usuario para el selector (select)
$stmtApiarios = $pdo->prepare("SELECT id_apiario, nombre FROM apiario WHERE id_usuario = ? ORDER BY nombre ASC");
$stmtApiarios->execute([$id_usuario]);
$apiarios = $stmtApiarios->fetchAll();

// Obtenemos la lista completa de colmenas del usuario con su respectivo Token
$stmtColmenas = $pdo->prepare("
    SELECT c.*, a.nombre AS nombre_apiario 
    FROM colmena c
    INNER JOIN apiario a ON c.id_apiario = a.id_apiario
    WHERE a.id_usuario = ?
    ORDER BY c.fecha_instalacion DESC, c.id_colmena DESC
");
$stmtColmenas->execute([$id_usuario]);
$colmenas = $stmtColmenas->fetchAll();

// Incluimos la cabecera premium del sistema
require_once __DIR__ . '/includes/header.php';
?>

<div class="app-container">
    <!-- El Sidebar oficial del sistema con Jero_Dev theme -->
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <!-- Barra de navegación superior -->
        <?php require_once __DIR__ . '/includes/header.php'; // Recargará la topbar superior ?>

        <div class="page-content">
            
            <!-- Encabezado de Página -->
            <div class="page-header animate-fadeUp">
                <div>
                    <h1 class="page-title">Dispositivos y Nodos</h1>
                    <p class="page-subtitle">Aprovisiona hardware de colmenas, copia tokens de emparejamiento y monitorea la conexión.</p>
                </div>
            </div>

            <!-- Banners de Notificaciones -->
            <?php if (!empty($mensaje_exito)): ?>
                <div class="alert-banner success animate-fadeIn u-mb-4" style="width: 100%; border-radius: 16px; display: flex; align-items: center; gap: 10px; padding: 14px 20px;">
                    <i data-lucide="check-circle" class="text-success" style="width: 20px; height: 20px;"></i>
                    <div><?= $mensaje_exito; ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($mensaje_error)): ?>
                <div class="alert-banner critical animate-fadeIn u-mb-4" style="width: 100%; border-radius: 16px; display: flex; align-items: center; gap: 10px; padding: 14px 20px;">
                    <i data-lucide="alert-triangle" class="text-critical" style="width: 20px; height: 20px;"></i>
                    <div><?= $mensaje_error; ?></div>
                </div>
            <?php endif; ?>

            <!-- Cuadrícula Principal (Formulario + Instrucciones) -->
            <div class="grid-2x2 animate-fadeUp stagger-1">
                
                <!-- Columna Izquierda: Formulario de Registro -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i data-lucide="plus-circle" class="text-brand"></i>
                            Registrar Nueva Colmena
                        </h3>
                    </div>
                    <form action="dispositivos.php" method="POST" class="stack">
                        <input type="hidden" name="crear_colmena" value="1">
                        
                        <div class="form-group">
                            <label class="ls-label" for="nombre">Nombre de la Colmena</label>
                            <input type="text" name="nombre" id="nombre" class="form-control" placeholder="Ej: Beta-02, Colmena Sur, etc." required>
                        </div>

                        <div class="form-group">
                            <label class="ls-label" for="especie">Especie de Abeja</label>
                            <select name="especie" id="especie" class="form-control">
                                <option value="Apis mellifera" selected>Apis mellifera (Común)</option>
                                <option value="Melipona beecheii">Melipona beecheii (Sin aguijón)</option>
                                <option value="Trigona angustula">Trigona angustula (Angelita)</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="ls-label" for="id_apiario">Apiario Geográfico</label>
                            <select name="id_apiario" id="id_apiario" class="form-control" required>
                                <option value="" disabled selected>Selecciona un apiario destino...</option>
                                <?php foreach ($apiarios as $apiario): ?>
                                    <option value="<?= $apiario['id_apiario']; ?>"><?= htmlspecialchars($apiario['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-brand btn-full u-mt-2">
                            <i data-lucide="plus"></i>
                            <span>Registrar y Generar Token</span>
                        </button>
                    </form>
                </div>

                <!-- Columna Derecha: Guía del Portal Cautivo -->
                <div class="card" style="background: linear-gradient(180deg, rgba(26, 26, 26, 0.4) 0%, rgba(10, 10, 10, 0.6) 100%);">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i data-lucide="help-circle" class="text-brand"></i>
                            Guía Rápida de Vinculación
                        </h3>
                    </div>
                    <div style="font-size: 0.9rem; line-height: 1.6; color: var(--color-text-secondary);">
                        <ol style="padding-left: 20px; margin-bottom: 16px;" class="stack">
                            <li style="margin-bottom: 8px;">
                                <strong>Copia el Token:</strong> Copia el Token Único de la colmena que deseas configurar usando el botón <i data-lucide="copy" style="width:14px; height:14px; display:inline-block; vertical-align:middle;"></i> en la tabla inferior.
                            </li>
                            <li style="margin-bottom: 8px;">
                                <strong>Conéctate al AP:</strong> Enciende tu ESP32 física en el apiario. Desde tu celular, conéctate a la red Wi-Fi libre llamada <strong>`BeeStation_Config`</strong>.
                            </li>
                            <li style="margin-bottom: 8px;">
                                <strong>Configura la Red:</strong> Al abrirse el Portal Cautivo, selecciona la red Wi-Fi de tu finca, escribe la contraseña y pega el <strong>Token Único</strong> que copiaste.
                            </li>
                            <li>
                                <strong>Listo:</strong> Haz clic en Vincular. El ESP32 se enlazará automáticamente y verás encenderse el estado <span class="badge badge-success">En línea</span> en pocos segundos.
                            </li>
                        </ol>
                        <div class="endpoint-box u-mt-2" style="font-size: 0.78rem;">
                            <strong>Endpoint del Hosting:</strong><br>
                            <code>https://<?= $_SERVER['HTTP_HOST']; ?>/api/ingest.php</code>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Tabla de Colmenas Registradas -->
            <div class="card u-mt-4 animate-fadeUp stagger-2">
                <div class="card-header">
                    <h3 class="card-title">
                        <i data-lucide="cpu" class="text-brand"></i>
                        Colmenas y Credenciales de Hardware
                    </h3>
                </div>
                
                <div class="table-container">
                    <?php if (empty($colmenas)): ?>
                        <div class="empty-state">
                            <div class="empty-icon-circle">
                                <i data-lucide="info"></i>
                            </div>
                            <h4 class="empty-title">Sin colmenas registradas</h4>
                            <p class="empty-desc">Aún no has agregado ninguna colmena a tus apiarios. Usa el formulario superior para registrar la primera.</p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Colmena</th>
                                    <th>Apiario</th>
                                    <th>Especie</th>
                                    <th>Fecha Registro</th>
                                    <th>Token de Vinculación Seguro</th>
                                    <th style="text-align: center;">Estado Conexión</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($colmenas as $colmena): 
                                    $online = dispositivoConectado($colmena['id_colmena'], 5);
                                ?>
                                    <tr>
                                        <td class="font-bold"><?= htmlspecialchars($colmena['nombre']); ?></td>
                                        <td><?= htmlspecialchars($colmena['nombre_apiario']); ?></td>
                                        <td style="font-style: italic; font-size: 0.82rem; color: var(--color-text-secondary);"><?= htmlspecialchars($colmena['especie']); ?></td>
                                        <td class="text-mono text-sm"><?= $colmena['fecha_instalacion']; ?></td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <code style="font-size: 0.88rem; background: var(--color-bg-muted); color: var(--color-brand-dark); font-weight: 700; border-radius: 8px; padding: 4px 10px; border: 1px solid var(--color-border);"><?= htmlspecialchars($colmena['token_vinculacion']); ?></code>
                                                <button class="btn btn-secondary btn-sm" onclick="copyToken('<?= htmlspecialchars($colmena['token_vinculacion']); ?>', this)" title="Copiar Token al portapapeles" style="min-height:32px; padding: 4px 10px;">
                                                    <i data-lucide="copy" style="width: 14px; height: 14px;"></i>
                                                </button>
                                            </div>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if ($online): ?>
                                                <span class="badge badge-success">
                                                    <span class="status-dot" style="width:6px; height:6px; background-color: var(--color-success); border-radius:50%; display:inline-block; margin-right:4px;"></span>
                                                    En línea
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-neutral" style="color: var(--color-text-tertiary);">
                                                    Sin señal
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

        </div>
        
        <!-- El Footer oficial con licencia del proyecto -->
        <?php require_once __DIR__ . '/includes/footer.php'; ?>
    </main>
</div>

<!-- LÓGICA JAVASCRIPT DINÁMICA DE COPIADO COMODO -->
<script>
function copyToken(text, buttonElement) {
    navigator.clipboard.writeText(text).then(() => {
        // Obtenemos el icono interno para dar feedback visual de éxito
        const icon = buttonElement.querySelector('i');
        const originalHtml = icon.outerHTML;
        
        // Cambiamos temporalmente el icono por un check de éxito
        buttonElement.classList.add('btn-primary');
        buttonElement.classList.remove('btn-secondary');
        icon.setAttribute('data-lucide', 'check');
        if (window.lucide) lucide.createIcons();
        
        // Restauramos el botón a su estado original después de 1.5 segundos
        setTimeout(() => {
            buttonElement.classList.remove('btn-primary');
            buttonElement.classList.add('btn-secondary');
            icon.setAttribute('data-lucide', 'copy');
            if (window.lucide) lucide.createIcons();
        }, 1500);
    }).catch(err => {
        alert('No se pudo copiar el token automáticamente: ', err);
    });
}
</script>