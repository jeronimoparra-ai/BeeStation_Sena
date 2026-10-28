<?php
/**
 * BeeStation — Centro de Dispositivos y Aprovisionamiento IoT
 * Centro Minero Ambiental - SENA, El Bagre (Antioquia)
 * 
 * Este archivo gestiona apiarios, colmenas y nodos IoT. Cada nodo tiene
 * su propia identidad ECDSA P-256: el alta se autoriza con un código de
 * 8 caracteres y no hay ningún token compartido que copiar. Las colmenas
 * nuevas siguen generando un token heredado para los nodos con firmware
 * anterior (ruta HMAC), pero el panel no lo presenta como credencial.
 */

// Iniciamos la sesión de forma segura
require_once __DIR__ . '/includes/auth.php';

// Módulo de identidad de dispositivos (ECDSA P-256 por nodo, sin token).
require_once __DIR__ . '/includes/dispositivo.php';

// Definimos la página activa para el sidebar
$current_page = 'dispositivos.php';

$id_usuario = (int) ($_SESSION['id_usuario'] ?? 0);
if ($id_usuario <= 0) {
    header('Location: login.php');
    exit;
}
$pdo = getPDO();

$mensaje_exito = "";
$mensaje_error = "";

// ── PROCESS POST: CREAR APIARIO ─────────────────────────────────────────────
// Un apiario es un dato real del apicultor: se crea solo cuando él lo pide.
// Antes se insertaba un "Apiario Principal" con ubicación de demo como efecto
// secundario de abrir esta página (un GET no debe escribir en la base).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_apiario'])) {
    if (!validarCsrfSesion($_POST['csrf'] ?? null)) {
        http_response_code(403);
        $mensaje_error = 'La sesión del formulario expiró. Recarga e inténtalo de nuevo.';
    } else {
        $nombreApiario = is_string($_POST['nombre_apiario'] ?? null) ? trim($_POST['nombre_apiario']) : '';
        $ubicacion     = is_string($_POST['ubicacion'] ?? null) ? trim($_POST['ubicacion']) : '';
        $municipio     = is_string($_POST['municipio'] ?? null) ? trim($_POST['municipio']) : '';

        if ($nombreApiario === '' || mb_strlen($nombreApiario) > 100) {
            $mensaje_error = 'El nombre del apiario es obligatorio (máximo 100 caracteres).';
        } elseif (mb_strlen($ubicacion) > 150 || mb_strlen($municipio) > 100) {
            $mensaje_error = 'La ubicación o el municipio son demasiado largos.';
        } else {
            try {
                $pdo->prepare("
                    INSERT INTO apiario (nombre, ubicacion, municipio, id_usuario)
                    VALUES (?, ?, ?, ?)
                ")->execute([
                    $nombreApiario,
                    $ubicacion !== '' ? $ubicacion : null,
                    $municipio !== '' ? $municipio : null,
                    $id_usuario,
                ]);
                $mensaje_exito = 'Apiario creado. Ya puedes registrar colmenas en él.';
            } catch (Throwable $e) {
                error_log('BeeStation: fallo al crear el apiario.');
                $mensaje_error = 'No fue posible crear el apiario. Inténtalo de nuevo.';
            }
        }
    }
}

// ── PROCESS POST: REGISTRO DE NUEVA COLMENA ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_colmena'])) {
    if (!validarCsrfSesion($_POST['csrf'] ?? null)) {
        http_response_code(403);
        $mensaje_error = 'La sesión del formulario expiró. Recarga e inténtalo de nuevo.';
    } else {
    $nombreEntrada = $_POST['nombre'] ?? null;
    $especieEntrada = $_POST['especie'] ?? 'Apis mellifera';
    $nombre = is_string($nombreEntrada) ? trim($nombreEntrada) : '';
    $especie = is_string($especieEntrada) ? trim($especieEntrada) : '';
    $id_apiario = filter_var($_POST['id_apiario'] ?? null, FILTER_VALIDATE_INT) ?: 0;

    if ($nombre === '' || mb_strlen($nombre) > 100) {
        $mensaje_error = "El nombre de la colmena es obligatorio.";
    } elseif ($especie === '' || mb_strlen($especie) > 100) {
        $mensaje_error = 'La especie debe tener entre 1 y 100 caracteres.';
    } elseif ($id_apiario <= 0) {
        $mensaje_error = "Debes seleccionar un apiario válido.";
    } else {
        try {
            $propietario = $pdo->prepare('SELECT 1 FROM apiario WHERE id_apiario = ? AND id_usuario = ?');
            $propietario->execute([$id_apiario, $id_usuario]);
            if (!$propietario->fetchColumn()) {
                throw new DomainException('Apiario no autorizado');
            }
            $pdo->beginTransaction();

            // 1. Token heredado: solo para nodos con firmware HMAC anterior.
            //    El firmware 3.2.0 usa identidad ECDSA y no lo necesita.
            $token_seguro = generarTokenVinculacion($nombre);

            // 2. Insertamos la nueva colmena con su respectivo token
            $stmtCol = $pdo->prepare("
                INSERT INTO colmena (nombre, especie, fecha_instalacion, estado, token_vinculacion, id_apiario) 
                VALUES (?, ?, CURDATE(), 'activa', ?, ?)
            ");
            $stmtCol->execute([$nombre, $especie, $token_seguro, $id_apiario]);
            $id_nueva_colmena = $pdo->lastInsertId();

            // 3. Autoprovisionamiento: Registramos automáticamente sus 9 sensores oficiales
            // Esto evita errores de bases de datos vacías para el usuario final.
            // Los 9 tipos son los que el firmware ESP32 puede enviar: 6 los mide
            // hoy (temperatura, humedad, presión, peso, sonido, luminosidad) y 3
            // quedan listos para cuando se integren (CO2, temp. externa, energía).
            $sensores_por_defecto = [
                ['tipo' => 'temperatura_interna', 'modelo' => 'DHT22',   'min' => -40, 'max' => 80,    'prec' => 0.5,  'var' => 1],
                ['tipo' => 'temperatura_externa', 'modelo' => 'DHT22',   'min' => -40, 'max' => 80,    'prec' => 0.5,  'var' => 2],
                ['tipo' => 'humedad_relativa',   'modelo' => 'DHT22',   'min' => 0,   'max' => 100,   'prec' => 3.0,  'var' => 3],
                ['tipo' => 'peso',               'modelo' => 'HX711',   'min' => 0,   'max' => 50,    'prec' => 0.01, 'var' => 4],
                ['tipo' => 'sonido',             'modelo' => 'MAX4466', 'min' => 0,   'max' => 20000, 'prec' => null, 'var' => 5],
                ['tipo' => 'co2',                'modelo' => 'MQ-135',  'min' => 400, 'max' => 6000,  'prec' => null, 'var' => 6],
                ['tipo' => 'energia',            'modelo' => 'INA219',  'min' => 0,   'max' => 5,    'prec' => 0.01, 'var' => 7],
                ['tipo' => 'presion',            'modelo' => 'BME280',  'min' => 300, 'max' => 1100,  'prec' => 0.1,  'var' => 8],
                ['tipo' => 'luminosidad',        'modelo' => 'BH1750',  'min' => 0,   'max' => 200000,'prec' => 0.1,  'var' => 9]
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
            $mensaje_exito = 'Colmena registrada con éxito.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('BeeStation: fallo al registrar colmena.');
            $mensaje_error = $e instanceof DomainException
                ? 'El apiario seleccionado no pertenece a tu cuenta.'
                : 'No fue posible registrar la colmena. Revisa la configuración e inténtalo de nuevo.';
        }
    }
    }
}

// ── PROCESS POST: VINCULAR UN NODO (identidad propia, sin token) ─────────────
//
// El nodo genera su par de claves ECDSA P-256 y se da de alta con una
// prueba de posesion. Queda en estado "pendiente" hasta que el usuario
// introduce el codigo corto que muestra el dispositivo (XXXX-XXXX) y lo
// asocia a una de sus colmenas. A partir de ahi el nodo firma cada
// peticion con su clave privada: no existe ningun token compartido.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vincular_nodo'])) {
    if (!validarCsrfSesion($_POST['csrf'] ?? null)) {
        http_response_code(403);
        $mensaje_error = 'La sesión del formulario expiró. Recarga e inténtalo de nuevo.';
    } else {
        $codigoEntrada = $_POST['codigo_nodo'] ?? null;
        $codigo = is_string($codigoEntrada) ? strtoupper(trim($codigoEntrada)) : '';
        $id_colmena_nodo = filter_var($_POST['id_colmena_nodo'] ?? null, FILTER_VALIDATE_INT) ?: 0;

        if ($codigo === '' || $id_colmena_nodo <= 0) {
            $mensaje_error = 'Introduce el código que muestra el nodo y elige la colmena a la que pertenece.';
        } else {
            try {
                $nodo = dispositivoBuscarPorCodigo($codigo);

                if ($nodo === null) {
                    // Codigo no valido, caducado o con intentos agotados.
                    // La respuesta es siempre la misma: no se revela si el
                    // codigo existe. Si el nodo existe, se cuenta el intento
                    // para limitar los ataques por fuerza bruta.
                    $localizado = dispositivoLocalizarParaIntento($codigo);
                    if ($localizado !== null && (int) $localizado['codigo_usado'] === 0) {
                        dispositivoRegistrarIntentoCodigo((int) $localizado['id_dispositivo']);
                    }
                    $mensaje_error = 'El código no es válido o ha caducado. Comprueba el código que muestra el nodo.';
                } else {
                    $resultado = dispositivoAprobar(
                        (int) $nodo['id_dispositivo'],
                        $id_colmena_nodo,
                        $id_usuario
                    );

                    if (!empty($resultado['ok'])) {
                        $mensaje_exito = 'Nodo vinculado correctamente. Ya puede enviar telemetría.';
                    } elseif (($resultado['error'] ?? '') === 'colmena_no_pertenece') {
                        $mensaje_error = 'La colmena seleccionada no pertenece a tu cuenta.';
                    } else {
                        $mensaje_error = 'No fue posible vincular el nodo. Inténtalo de nuevo.';
                    }
                }
            } catch (Throwable $e) {
                error_log('BeeStation: fallo al vincular el nodo.');
                $mensaje_error = 'No fue posible vincular el nodo. Inténtalo de nuevo.';
            }
        }
    }
}

// ── PROCESS POST: REVOCAR UN NODO ───────────────────────────────────────────
//
// La revocacion es inmediata y afecta SOLO a ese nodo: el resto de los
// dispositivos siguen funcionando sin reflashear nada.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revocar_nodo'])) {
    if (!validarCsrfSesion($_POST['csrf'] ?? null)) {
        http_response_code(403);
        $mensaje_error = 'La sesión del formulario expiró. Recarga e inténtalo de nuevo.';
    } else {
        $id_dispositivo = filter_var($_POST['id_dispositivo'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $motivoEntrada = $_POST['motivo_revocation'] ?? null;
        $motivo = is_string($motivoEntrada) ? trim($motivoEntrada) : '';

        if ($id_dispositivo <= 0) {
            $mensaje_error = 'No se ha indicado qué nodo revocar.';
        } else {
            try {
                $resultado = dispositivoRevocar($id_dispositivo, $id_usuario, $motivo);
                if (!empty($resultado['ok'])) {
                    $mensaje_exito = 'Nodo revocado. Ya no puede enviar telemetría.';
                } elseif (($resultado['error'] ?? '') === 'dispositivo_no_pertenece') {
                    $mensaje_error = 'Ese nodo no está asociado a ninguna de tus colmenas.';
                } else {
                    $mensaje_error = 'No fue posible revocar el nodo. Inténtalo de nuevo.';
                }
            } catch (Throwable $e) {
                error_log('BeeStation: fallo al revocar el nodo.');
                $mensaje_error = 'No fue posible revocar el nodo. Inténtalo de nuevo.';
            }
        }
    }
}

// ── CONSULTAS DE DATOS: Apiarios y Colmenas del Usuario ──────────────────────
// Obtenemos los apiarios del usuario para el selector (select)
$stmtApiarios = $pdo->prepare("SELECT id_apiario, nombre FROM apiario WHERE id_usuario = ? ORDER BY nombre ASC");
$stmtApiarios->execute([$id_usuario]);
$apiarios = $stmtApiarios->fetchAll();

// Obtenemos la lista completa de colmenas del usuario
$stmtColmenas = $pdo->prepare("
    SELECT c.*, a.nombre AS nombre_apiario 
    FROM colmena c
    INNER JOIN apiario a ON c.id_apiario = a.id_apiario
    WHERE a.id_usuario = ?
    ORDER BY c.fecha_instalacion DESC, c.id_colmena DESC
");
$stmtColmenas->execute([$id_usuario]);
$colmenas = $stmtColmenas->fetchAll();

// Nodos IoT del usuario (identidad ECDSA propia, sin token compartido).
// Solo se listan los nodos que ya le pertenecen: un nodo pendiente sin
// propietario no se muestra a nadie, se vincula mediante su codigo.
$stmtDispositivos = $pdo->prepare("
    SELECT d.id_dispositivo, d.identificador, d.mac, d.nombre, d.estado,
           d.creado_en, d.aprobado_en, d.revocado_en, d.motivo_revocacion,
           d.ultimo_contacto, d.id_colmena,
           c.nombre AS nombre_colmena
    FROM dispositivo d
    LEFT JOIN colmena c ON c.id_colmena = d.id_colmena
    LEFT JOIN apiario a ON a.id_apiario = c.id_apiario
    WHERE d.id_usuario = ? OR a.id_usuario = ?
    ORDER BY FIELD(d.estado, 'pendiente', 'autorizado', 'revocado'), d.creado_en DESC
");
$stmtDispositivos->execute([$id_usuario, $id_usuario]);
$dispositivos = $stmtDispositivos->fetchAll();

// Mapa colmena -> nodo(s) para mostrar en la tabla de colmenas sin exponer
// ningun secreto: la credencial de un nodo es su clave, no un token.
$nodosPorColmena = [];
foreach ($dispositivos as $d) {
    $idc = (int) ($d['id_colmena'] ?? 0);
    if ($idc > 0) {
        $nodosPorColmena[$idc][] = $d;
    }
}

// Incluimos la cabecera premium del sistema
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-content">
            
            <!-- Encabezado de Página -->
            <div class="page-header animate-fadeUp">
                <div>
                    <h1 class="page-title">Dispositivos y Nodos</h1>
                    <p class="page-subtitle">Aprovisiona hardware de colmenas, autoriza nodos y monitorea la conexión.</p>
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

            <?php if (empty($apiarios)): ?>
            <!-- Primer apiario: sin él no se puede registrar una colmena -->
            <div class="card animate-fadeUp u-mb-4">
                <div class="card-header">
                    <h3 class="card-title">
                        <i data-lucide="map-pin" class="text-brand"></i>
                        Crea tu primer apiario
                    </h3>
                </div>
                <form action="dispositivos.php" method="POST" class="stack">
                    <input type="hidden" name="crear_apiario" value="1">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(tokenCsrfSesion(), ENT_QUOTES, 'UTF-8') ?>">

                    <div class="form-group">
                        <label class="ls-label" for="nombre_apiario">Nombre del apiario</label>
                        <input type="text" name="nombre_apiario" id="nombre_apiario" class="form-control"
                               placeholder="Ej: Apiario Norte" maxlength="100" required>
                    </div>

                    <div class="form-group">
                        <label class="ls-label" for="ubicacion">Ubicación (opcional)</label>
                        <input type="text" name="ubicacion" id="ubicacion" class="form-control"
                               placeholder="Vereda, finca o referencia" maxlength="150">
                    </div>

                    <div class="form-group">
                        <label class="ls-label" for="municipio">Municipio (opcional)</label>
                        <input type="text" name="municipio" id="municipio" class="form-control"
                               placeholder="Ej: El Bagre, Antioquia" maxlength="100">
                    </div>

                    <button type="submit" class="btn btn-brand btn-full u-mt-2">
                        <i data-lucide="plus"></i>
                        <span>Crear apiario</span>
                    </button>
                </form>
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
                    <?php if (empty($apiarios)): ?>
                        <p class="text-secondary text-sm">
                            Crea un apiario arriba para poder registrar tu primera colmena.
                        </p>
                    <?php else: ?>
                    <form action="dispositivos.php" method="POST" class="stack">
                        <input type="hidden" name="crear_colmena" value="1">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(tokenCsrfSesion(), ENT_QUOTES, 'UTF-8') ?>">
                        
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
                            <span>Registrar colmena</span>
                        </button>
                    </form>
                    <?php endif; ?>
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
                                <strong>Enciende el nodo:</strong> El ESP32 crea la red Wi-Fi <strong>`BeeStation_Config`</strong>. Conéctate desde tu celular.
                            </li>
                            <li style="margin-bottom: 8px;">
                                <strong>Configura la red:</strong> En el portal cautivo elige la Wi-Fi de tu finca y escribe la contraseña. No ingreses API Key ni token: el nodo genera su propia clave ECDSA y busca el servidor automáticamente. También puedes indicar la IP del servidor en el portal.
                            </li>
                            <li style="margin-bottom: 8px;">
                                <strong>Anota el código:</strong> El monitor Serie (115200) muestra un identificador y un <strong>código de alta de 8 caracteres</strong>.
                            </li>
                            <li style="margin-bottom: 8px;">
                                <strong>Autoriza el nodo:</strong> En <em>Nodos IoT</em>, escribe el código, elige la colmena y pulsa Vincular.
                            </li>
                            <li>
                                <strong>Listo:</strong> El nodo pasa a <span class="badge badge-success">En línea</span> en pocos segundos y empieza a enviar telemetría firmada.
                            </li>
                        </ol>
                        <?php
                        $proto    = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
                        $esquema  = ($proto === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) ? 'https' : 'http';
                        $host     = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'];
                        $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
                        $endpoint = $esquema . '://' . $host . $basePath . '/api/ingest.php';
                        ?>
                        <div class="endpoint-box u-mt-2" style="font-size: 0.78rem;">
                            <strong>Endpoint del Hosting:</strong><br>
                            <code><?= htmlspecialchars($endpoint) ?></code>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Tabla de Colmenas Registradas -->
            <div class="card device-collection-card u-mt-4 animate-fadeUp stagger-2">
                <div class="card-header">
                    <h3 class="card-title">
                        <i data-lucide="cpu" class="text-brand"></i>
                        Colmenas y Nodos Asignados
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
                                    <th>Nodo asignado</th>
                                    <th style="text-align: center;">Estado Conexión</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($colmenas as $colmena): 
                                    $online = dispositivoConectado($colmena['id_colmena'], 5);
                                    $nodosColmena = $nodosPorColmena[(int) $colmena['id_colmena']] ?? [];
                                ?>
                                    <tr>
                                        <td class="font-bold"><?= htmlspecialchars($colmena['nombre']); ?></td>
                                        <td><?= htmlspecialchars($colmena['nombre_apiario']); ?></td>
                                        <td style="font-style: italic; font-size: 0.82rem; color: var(--color-text-secondary);"><?= htmlspecialchars($colmena['especie']); ?></td>
                                        <td class="text-mono text-sm"><?= $colmena['fecha_instalacion']; ?></td>
                                        <td>
                                            <?php if (empty($nodosColmena)): ?>
                                                <div style="font-size: 0.76rem; color: var(--color-text-tertiary);">
                                                    Sin nodo autorizado. Enciende el nodo y autorízalo con su código en la sección inferior.
                                                </div>
                                            <?php else: ?>
                                                <?php foreach ($nodosColmena as $nodo):
                                                    $estadoNodo = (string) $nodo['estado'];
                                                    $claseNodo = $estadoNodo === 'autorizado' ? 'badge-success'
                                                               : ($estadoNodo === 'revocado' ? 'badge-critical' : 'badge-warning');
                                                ?>
                                                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 4px;">
                                                        <span class="badge <?= $claseNodo; ?>" style="font-size: 0.7rem;"><?= htmlspecialchars(ucfirst($estadoNodo)); ?></span>
                                                        <span class="text-mono" style="font-size: 0.78rem;"><?= htmlspecialchars(substr((string) $nodo['identificador'], 0, 8)); ?>&hellip;</span>
                                                        <span class="text-mono" style="font-size: 0.72rem; color: var(--color-text-tertiary);"><?= htmlspecialchars((string) $nodo['mac']); ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                                <div style="font-size: 0.72rem; color: var(--color-text-tertiary); margin-top: 4px;">
                                                    Credencial: clave ECDSA propia del nodo (no hay token que copiar).
                                                </div>
                                            <?php endif; ?>
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

            <!-- Nodos IoT: identidad propia por dispositivo (sin token) -->
            <div class="card device-nodes-card u-mt-4 animate-fadeUp stagger-3">
                <div class="card-header">
                    <h3 class="card-title">
                        <i data-lucide="shield-check" class="text-brand"></i>
                        Nodos IoT y Autorización de Dispositivos
                    </h3>
                </div>

                <div class="card-body">
                    <p style="font-size: 0.86rem; color: var(--color-text-secondary); margin-bottom: 18px;">
                        Cada nodo genera su propia clave criptográfica al arrancar y se registra en el
                        servidor con una <strong>prueba de posesión</strong>. Hasta que lo autorices aquí,
                        el nodo queda <span class="badge badge-warning">pendiente</span> y no puede enviar
                        datos. No hay ningún token que pegar ni que repartir: la clave privada nunca sale
                        del dispositivo.
                    </p>

                    <form action="dispositivos.php" method="POST" class="stack" style="max-width: 560px;">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(tokenCsrfSesion(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="vincular_nodo" value="1">

                        <div class="form-group">
                            <label for="codigo_nodo">Código del nodo</label>
                            <input type="text" name="codigo_nodo" id="codigo_nodo" class="form-control"
                                   placeholder="XXXX-XXXX" maxlength="9" required
                                   pattern="[A-Za-z0-9]{4}-[A-Za-z0-9]{4}"
                                   autocomplete="off" spellcheck="false"
                                   style="text-transform: uppercase; letter-spacing: 1px;">
                            <small style="color: var(--color-text-tertiary);">El nodo lo muestra en su portal cautivo y en el monitor por puerto serie.</small>
                        </div>

                        <div class="form-group">
                            <label for="id_colmena_nodo">Colmena a la que pertenece</label>
                            <select name="id_colmena_nodo" id="id_colmena_nodo" class="form-control" required>
                                <option value="">— Selecciona una colmena —</option>
                                <?php foreach ($colmenas as $colmena): ?>
                                    <option value="<?= (int) $colmena['id_colmena']; ?>">
                                        <?= htmlspecialchars($colmena['nombre']); ?> (<?= htmlspecialchars($colmena['nombre_apiario']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i data-lucide="link" style="width:16px; height:16px;"></i>
                            Autorizar nodo
                        </button>
                    </form>
                </div>

                <div class="table-container">
                    <?php if (empty($dispositivos)): ?>
                        <div class="empty-state">
                            <div class="empty-icon-circle">
                                <i data-lucide="cpu"></i>
                            </div>
                            <h4 class="empty-title">Sin nodos vinculados</h4>
                            <p class="empty-desc">Cuando un nodo se registre, aparecerá aquí para que puedas autorizarlo o revocarlo.</p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Nodo</th>
                                    <th>Identificador</th>
                                    <th>Colmena</th>
                                    <th style="text-align: center;">Estado</th>
                                    <th>Alta</th>
                                    <th>Último contacto</th>
                                    <th style="text-align: center;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dispositivos as $disp):
                                    $estado = (string) $disp['estado'];
                                    if ($estado === 'autorizado') {
                                        $claseEstado = 'badge-success';
                                        $textoEstado = 'Autorizado';
                                    } elseif ($estado === 'revocado') {
                                        $claseEstado = 'badge-critical';
                                        $textoEstado = 'Revocado';
                                    } else {
                                        $claseEstado = 'badge-warning';
                                        $textoEstado = 'Pendiente';
                                    }
                                    $idDisp = (int) $disp['id_dispositivo'];
                                    $corto = substr((string) $disp['identificador'], 0, 8);
                                ?>
                                    <tr>
                                        <td>
                                            <div class="font-bold"><?= htmlspecialchars((string) ($disp['nombre'] ?: 'Nodo ' . $corto)); ?></div>
                                            <div class="text-mono" style="font-size: 0.72rem; color: var(--color-text-tertiary);">
                                                <?= htmlspecialchars((string) $disp['mac']); ?>
                                            </div>
                                        </td>
                                        <td class="text-mono text-sm" title="<?= htmlspecialchars((string) $disp['identificador']); ?>">
                                            <?= htmlspecialchars($corto); ?>…
                                        </td>
                                        <td><?= htmlspecialchars((string) ($disp['nombre_colmena'] ?: '—')); ?></td>
                                        <td style="text-align: center;">
                                            <span class="badge <?= $claseEstado; ?>"><?= $textoEstado; ?></span>
                                            <?php if ($estado === 'revocado' && !empty($disp['motivo_revocacion'])): ?>
                                                <div style="font-size: 0.72rem; color: var(--color-text-tertiary); margin-top: 4px;">
                                                    <?= htmlspecialchars((string) $disp['motivo_revocacion']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-mono text-sm"><?= htmlspecialchars((string) $disp['creado_en']); ?></td>
                                        <td class="text-mono text-sm">
                                            <?= !empty($disp['ultimo_contacto']) ? htmlspecialchars(tiempoRelativo((string) $disp['ultimo_contacto'])) : '—'; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if ($estado === 'autorizado'): ?>
                                                <form action="dispositivos.php" method="POST" onsubmit="return confirm('¿Revocar este nodo? Dejará de enviar telemetría de inmediato.');">
                                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(tokenCsrfSesion(), ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="revocar_nodo" value="1">
                                                    <input type="hidden" name="id_dispositivo" value="<?= $idDisp; ?>">
                                                    <input type="hidden" name="motivo_revocation" value="Revocado por el usuario">
                                                    <button type="submit" class="btn btn-secondary btn-sm" style="min-height:32px; padding: 4px 10px;">
                                                        <i data-lucide="shield-off" style="width:14px; height:14px;"></i>
                                                        Revocar
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span style="font-size: 0.72rem; color: var(--color-text-tertiary);">—</span>
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
    
    <!-- El footer cierra los contenedores abiertos por header.php. -->
    <?php require_once __DIR__ . '/includes/footer.php'; ?>

