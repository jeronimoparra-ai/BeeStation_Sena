<?php
/**
 * BeeStation — Biblioteca de Funciones de Backend
 * Centro Minero Ambiental - SENA, El Bagre (Antioquia)
 * 
 * Este archivo contiene todas las consultas SQL seguras,
 * algoritmos bioclimáticos e indicadores de salud de la colmena.
 */

// ── CONTROL DE ACCESO DE SESIÓN ──────────────────────────────
// Las llamadas desde la API (ingest.php, validate_token.php) definen
// BEESTATION_API_CONTEXT antes de incluir este archivo, así evitamos
// redirigir a login.php cuando no hay sesión de navegador.
if (!defined('BEESTATION_API_CONTEXT')) {
    $current_page = basename($_SERVER['PHP_SELF']);
    if (!isset($_SESSION['id_usuario']) && $current_page !== 'login.php') {
        header("Location: login.php");
        exit;
    }
} else {
    $current_page = basename($_SERVER['PHP_SELF']);
}

/**
 * Devuelve la clase 'active' si la página coincide con la actual.
 */
function is_active(string $page_name): string {
    global $current_page;
    return $current_page === $page_name ? 'active' : '';
}

/**
 * Intenta autenticar a un usuario contra la base de datos.
 */
function intentarLogin(string $correo, string $clave): ?array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT * FROM usuario WHERE correo = ? LIMIT 1");
    $stmt->execute([$correo]);
    $user = $stmt->fetch();

    if ($user && password_verify($clave, $user['contrasena'])) {
        return $user;
    }
    return null;
}

/**
 * Devuelve la colmena activa por defecto (la primera del apiario del usuario).
 */
function obtenerColmenaActiva(int $id_usuario): ?array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT c.* 
        FROM colmena c 
        INNER JOIN apiario a ON c.id_apiario = a.id_apiario 
        WHERE a.id_usuario = ? 
        ORDER BY c.id_colmena ASC 
        LIMIT 1
    ");
    $stmt->execute([$id_usuario]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Genera un token de vinculación alfanumérico único para una nueva colmena.
 */
function generarTokenVinculacion(string $nombre = 'COL'): string {
    $sufijo = strtoupper(bin2hex(random_bytes(3)));
    $slug = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $nombre));
    $slug = substr($slug, 0, 8);
    if (empty($slug)) {
        $slug = 'COL';
    }
    return "BS-{$slug}-{$sufijo}";
}

/**
 * Resuelve de forma segura una colmena a partir de su token de vinculación.
 * Devuelve null si el token no existe o fue revocado.
 */
function resolverColmenaPorToken(string $token): ?array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT id_colmena, nombre, token_vinculacion, token_revocado 
        FROM colmena 
        WHERE token_vinculacion = ? 
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row || (int)$row['token_revocado'] === 1) {
        return null;
    }
    return $row;
}

/**
 * Registra en la tabla intento_vinculacion cada intento de autenticación
 * por token, exitoso o fallido, para diagnóstico y trazabilidad.
 */
function registrarIntentoVinculacion(?string $token, ?string $ip, string $resultado): void {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        INSERT INTO intento_vinculacion (token_recibido, ip_origen, resultado) 
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$token ?? '', $ip, $resultado]);
}

/**
 * Restablece el contador de intentos fallidos de un token cuando
 * la vinculación se valida correctamente.
 */
function reiniciarIntentosFallidosToken(int $id_colmena): void {
    $pdo = getPDO();
    $pdo->prepare("UPDATE colmena SET intentos_fallidos_token = 0 WHERE id_colmena = ?")
        ->execute([$id_colmena]);
}

/**
 * Incrementa el contador de intentos fallidos de un token asociado a una colmena.
 * Devuelve el nuevo total de intentos fallidos.
 */
function incrementarIntentosFallidosToken(int $id_colmena): int {
    $pdo = getPDO();
    $pdo->prepare("UPDATE colmena SET intentos_fallidos_token = intentos_fallidos_token + 1 WHERE id_colmena = ?")
        ->execute([$id_colmena]);

    $stmt = $pdo->prepare("SELECT intentos_fallidos_token FROM colmena WHERE id_colmena = ?");
    $stmt->execute([$id_colmena]);
    $row = $stmt->fetch();
    return $row ? (int)$row['intentos_fallidos_token'] : 0;
}

/**
 * Obtiene el intento de vinculación fallido más reciente dentro de una
 * ventana de tiempo, útil para mostrar diagnóstico en conectar_dispositivo.php.
 */
function ultimoIntentoFallido(int $minutos = 10): ?array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT token_recibido, fecha_hora 
        FROM intento_vinculacion 
        WHERE resultado = 'token_invalido' 
          AND fecha_hora >= (NOW() - INTERVAL ? MINUTE) 
        ORDER BY fecha_hora DESC 
        LIMIT 1
    ");
    $stmt->bindValue(1, $minutos, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Obtiene la última lectura calibrada y válida de un tipo de sensor específico.
 */
function ultimaLectura(int $id_colmena, string $tipo_sensor): ?array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT l.*, s.modelo, s.estado AS estado_sensor 
        FROM lectura l 
        INNER JOIN sensor s ON l.id_sensor = s.id_sensor 
        WHERE s.id_colmena = ? 
          AND s.tipo = ? 
          AND l.es_valida = 1 
        ORDER BY l.fecha_hora DESC 
        LIMIT 1
    ");
    $stmt->execute([$id_colmena, $tipo_sensor]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Obtiene el histórico de datos en serie temporal de un sensor en las últimas N horas.
 */
function serieHistorica(int $id_colmena, string $tipo_sensor, int $horas = 24): array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT l.valor_calibrado, l.fecha_hora 
        FROM lectura l 
        INNER JOIN sensor s ON l.id_sensor = s.id_sensor 
        WHERE s.id_colmena = ? 
          AND s.tipo = ? 
          AND l.es_valida = 1 
          AND l.fecha_hora >= (NOW() - INTERVAL ? HOUR) 
        ORDER BY l.fecha_hora ASC
    ");
    $stmt->execute([$id_colmena, $tipo_sensor, $horas]);
    return $stmt->fetchAll();
}

/**
 * Obtiene el histórico bioclimático filtrado por días (ej: para el control de peso).
 */
function serieHistoricaDias(int $id_colmena, string $tipo_sensor, int $dias = 30): array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT l.valor_calibrado, l.fecha_hora 
        FROM lectura l 
        INNER JOIN sensor s ON l.id_sensor = s.id_sensor 
        WHERE s.id_colmena = ? 
          AND s.tipo = ? 
          AND l.es_valida = 1 
          AND l.fecha_hora >= (NOW() - INTERVAL ? DAY) 
        ORDER BY l.fecha_hora ASC
    ");
    $stmt->execute([$id_colmena, $tipo_sensor, $dias]);
    return $stmt->fetchAll();
}

/**
 * Obtiene la lista completa de sensores de una colmena junto con su último reporte.
 */
function sensoresConEstado(int $id_colmena): array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT s.*, 
            (SELECT l.valor_calibrado FROM lectura l WHERE l.id_sensor = s.id_sensor AND l.es_valida = 1 ORDER BY l.fecha_hora DESC LIMIT 1) AS ultima_lectura,
            (SELECT l.fecha_hora FROM lectura l WHERE l.id_sensor = s.id_sensor AND l.es_valida = 1 ORDER BY l.fecha_hora DESC LIMIT 1) AS ultima_fecha
        FROM sensor s 
        WHERE s.id_colmena = ? 
        ORDER BY s.id_sensor ASC
    ");
    $stmt->execute([$id_colmena]);
    return $stmt->fetchAll();
}

/**
 * Obtiene la lista completa de sensores de una colmena, incluyendo unidad de medida
 * y separando los que tienen datos (operativos) de los fantasma.
 */
function sensoresOperativos(int $id_colmena): array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT s.*, v.unidad_medida,
            (SELECT l.valor_calibrado FROM lectura l WHERE l.id_sensor = s.id_sensor AND l.es_valida = 1 ORDER BY l.fecha_hora DESC LIMIT 1) AS ultima_lectura,
            (SELECT l.fecha_hora FROM lectura l WHERE l.id_sensor = s.id_sensor AND l.es_valida = 1 ORDER BY l.fecha_hora DESC LIMIT 1) AS ultima_fecha
        FROM sensor s 
        INNER JOIN variable_bioclimatica v ON s.id_variable = v.id_variable
        WHERE s.id_colmena = ? 
        ORDER BY s.id_sensor ASC
    ");
    $stmt->execute([$id_colmena]);
    $sensores = $stmt->fetchAll();
    
    // Calcular estado dinámico
    foreach ($sensores as &$s) {
        if ($s['ultima_fecha']) {
            $s['tiene_datos'] = true;
            $diff = time() - strtotime($s['ultima_fecha']);
            if ($diff < 300) { // 5 min
                $s['estado_real'] = 'en_linea';
            } elseif ($diff < 1800) { // 30 min
                $s['estado_real'] = 'advertencia';
            } else {
                $s['estado_real'] = 'sin_senal';
            }
        } else {
            $s['tiene_datos'] = false;
            $s['estado_real'] = 'sin_senal';
        }
    }
    
    return $sensores;
}
/**
 * Recupera las alertas de salud actualmente activas para una colmena.
 */
function alertasActivas(int $id_colmena, int $limite = 10): array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT al.* 
        FROM alerta al 
        INNER JOIN indicador i ON al.id_indicador = i.id_indicador 
        WHERE i.id_colmena = ? 
          AND al.estado = 'activa' 
        ORDER BY al.fecha_hora DESC 
        LIMIT ?
    ");
    $stmt->bindValue(1, $id_colmena, PDO::PARAM_INT);
    $stmt->bindValue(2, $limite, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Obtiene el último registro de un indicador bioclimático calculado (como el IBB).
 */
function ultimoIndicador(int $id_colmena, string $tipo): ?array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT * 
        FROM indicador 
        WHERE id_colmena = ? 
          AND tipo = ? 
        ORDER BY fecha_hora DESC 
        LIMIT 1
    ");
    $stmt->execute([$id_colmena, $tipo]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Calcula el flujo diario de néctar (peso acumulado ganado/perdido hoy).
 */
function calcularFlujoDiarioPeso(int $id_colmena): ?float {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT l.valor_calibrado, l.fecha_hora 
        FROM lectura l 
        INNER JOIN sensor s ON l.id_sensor = s.id_sensor 
        WHERE s.id_colmena = ? 
          AND s.tipo = 'peso' 
          AND l.es_valida = 1 
          AND l.fecha_hora >= (NOW() - INTERVAL 24 HOUR) 
        ORDER BY l.fecha_hora ASC
    ");
    $stmt->execute([$id_colmena]);
    $rows = $stmt->fetchAll();

    if (count($rows) < 2) return null;
    $primero = (float)$rows[0]['valor_calibrado'];
    $ultimo  = (float)end($rows)['valor_calibrado'];
    return round($ultimo - $primero, 2);
}

/**
 * ALGORITMO BIOCLIMÁTICO: Índice de Bienestar Bioclimático (IBB)
 * Ponderación: Temperatura Interna 45% + Humedad Relativa 35% + CO2 20%
 * NO MODIFICAR — fórmula validada del proyecto.
 */
function calcularIBB(int $id_colmena): ?array {
    $t = ultimaLectura($id_colmena, 'temperatura_interna');
    $h = ultimaLectura($id_colmena, 'humedad_relativa');
    $c = ultimaLectura($id_colmena, 'co2');

    if (!$t || !$h || !$c) return null;

    $ft = abs(((float)$t['valor_calibrado'] - 35) / 3) * 100;
    $fh = abs(((float)$h['valor_calibrado'] - 60) / 20) * 100;
    $fc = abs(((float)$c['valor_calibrado'] - 3000) / 3000) * 100;

    $ibb = 100 - (0.45 * $ft + 0.35 * $fh + 0.20 * $fc);
    $ibb = max(0, min(100, round($ibb, 1)));

    if ($ibb >= 85)       $estado = 'Óptimo';
    elseif ($ibb >= 70)   $estado = 'Bueno';
    elseif ($ibb >= 50)   $estado = 'Regular';
    elseif ($ibb >= 30)   $estado = 'Deficiente';
    else                  $estado = 'Crítico';

    return ['valor' => $ibb, 'estado' => $estado];
}

/**
 * ALGORITMO BIOCLIMÁTICO: Delta T (Temperatura Interna vs Temperatura Externa)
 * NO MODIFICAR — fórmula validada del proyecto.
 */
function calcularDeltaT(int $id_colmena): ?array {
    $t_int = ultimaLectura($id_colmena, 'temperatura_interna');
    $t_ext = ultimaLectura($id_colmena, 'temperatura_externa');

    if (!$t_int || !$t_ext) return null;

    $delta = (float)$t_int['valor_calibrado'] - (float)$t_ext['valor_calibrado'];
    if ($delta < 2) {
        $estado = 'Posible colonia muerta o muy débil';
    } else {
        $estado = 'Normal';
    }
    return ['valor' => round($delta, 1), 'estado' => $estado];
}

/**
 * ALGORITMO BIOCLIMÁTICO: Eficiencia de Ventilación (EV)
 * Basado en el nivel de CO2 respecto al rango óptimo definido en
 * variable_bioclimatica (óptimo 2000–4000 ppm). A menor desviación
 * respecto al centro del rango óptimo, mayor eficiencia de ventilación.
 */
function calcularEV(int $id_colmena): ?array {
    $c = ultimaLectura($id_colmena, 'co2');
    if (!$c) return null;

    $co2 = (float)$c['valor_calibrado'];
    $centro_optimo = 3000;
    $desviacion = abs($co2 - $centro_optimo) / $centro_optimo * 100;

    $ev = 100 - $desviacion;
    $ev = max(0, min(100, round($ev, 1)));

    if ($ev >= 80)       $estado = 'Excelente';
    elseif ($ev >= 60)   $estado = 'Buena';
    elseif ($ev >= 40)   $estado = 'Regular';
    else                 $estado = 'Deficiente';

    return ['valor' => $ev, 'estado' => $estado];
}

/**
 * ALGORITMO BIOCLIMÁTICO: Humedad estimada de la miel (H_miel)
 * Estimación basada en la correlación entre humedad relativa interna
 * y el punto de referencia de maduración de miel (rango óptimo 50–70% HR
 * definido en variable_bioclimatica). Se usa como aproximación indirecta
 * mientras no exista un sensor de humedad de miel dedicado.
 */
function calcularHMiel(int $id_colmena): ?array {
    $h = ultimaLectura($id_colmena, 'humedad_relativa');
    if (!$h) return null;

    $hr = (float)$h['valor_calibrado'];
    $h_miel_estimada = 12 + (($hr - 40) * 0.35);
    $h_miel_estimada = max(12, min(25, round($h_miel_estimada, 1)));

    if ($h_miel_estimada <= 18)      $estado = 'Lista para cosecha';
    elseif ($h_miel_estimada <= 21)  $estado = 'Cerca de madurar';
    else                             $estado = 'Inmadura (riesgo de fermentación)';

    return ['valor' => $h_miel_estimada, 'estado' => $estado];
}

/**
 * ALERTA TEMPRANA DE ENJAMBRAZÓN: evalúa la banda acústica de 400–600 Hz
 * y registra una alerta activa si el valor calibrado cae en ese rango,
 * evitando duplicados en las últimas 2 horas.
 */
function evaluarAlertaEnjambrazon(int $id_colmena, float $valor_calibrado): void {
    if ($valor_calibrado < 400 || $valor_calibrado > 600) {
        return;
    }

    $pdo = getPDO();

    $stmtCheck = $pdo->prepare("
        SELECT COUNT(*) AS total 
        FROM alerta al
        INNER JOIN indicador i ON al.id_indicador = i.id_indicador
        WHERE i.id_colmena = ? 
          AND al.tipo = 'ENJAMBRAZON' 
          AND al.estado = 'activa' 
          AND al.fecha_hora >= (NOW() - INTERVAL 2 HOUR)
    ");
    $stmtCheck->execute([$id_colmena]);
    if ((int)$stmtCheck->fetch()['total'] > 0) {
        return;
    }

    $stmtInd = $pdo->prepare("
        INSERT INTO indicador (tipo, valor, fecha_hora, descripcion, estado_colonia, id_colmena)
        VALUES ('FRECUENCIA_ACUSTICA', ?, NOW(), 'Frecuencia acústica en banda de pre-enjambrazón', 'Alerta', ?)
    ");
    $stmtInd->execute([$valor_calibrado, $id_colmena]);
    $id_indicador = $pdo->lastInsertId();

    $stmtAlerta = $pdo->prepare("
        INSERT INTO alerta (tipo, nivel, mensaje, id_indicador) 
        VALUES ('ENJAMBRAZON', 2, ?, ?)
    ");
    $mensaje = "Posible enjambrazón detectada: frecuencia acústica de {$valor_calibrado} Hz dentro del rango crítico (400-600 Hz).";
    $stmtAlerta->execute([$mensaje, $id_indicador]);
}

/**
 * Transforma un timestamp en una cadena relativa amigable (ej: "hace 5 min").
 */
function tiempoRelativo(?string $fecha_hora): string {
    if (!$fecha_hora) return 'sin datos';
    $diff = time() - strtotime($fecha_hora);
    if ($diff < 60) return 'hace ' . $diff . ' s';
    if ($diff < 3600) return 'hace ' . floor($diff / 60) . ' min';
    if ($diff < 86400) return 'hace ' . floor($diff / 3600) . ' h';
    return 'hace ' . floor($diff / 86400) . ' días';
}

/**
 * Hora exacta (HH:MM:SS) en la que el ESP32 subió la lectura.
 * `fecha_hora` se rellena con CURRENT_TIMESTAMP al insertar, es decir,
 * en el momento en que llega el dato al servidor.
 */
function horaSubida(?string $fecha_hora): string {
    if (!$fecha_hora) return '—';
    return date('H:i:s', strtotime($fecha_hora));
}

/**
 * Revisa si el hardware del apiario ha transmitido telemetría reciente (5 min por defecto).
 */
function dispositivoConectado(int $id_colmena, int $minutos = 5): bool {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS c 
        FROM lectura l 
        INNER JOIN sensor s ON l.id_sensor = s.id_sensor 
        WHERE s.id_colmena = ? 
          AND l.fecha_hora >= (NOW() - INTERVAL ? MINUTE)
    ");
    $stmt->bindValue(1, $id_colmena, PDO::PARAM_INT);
    $stmt->bindValue(2, $minutos, PDO::PARAM_INT);
    $stmt->execute();
    return ((int)$stmt->fetch()['c']) > 0;
}

/**
 * Verifica si una colmena ha recibido al menos una lectura histórica.
 * Útil para distinguir "nunca se ha configurado" de "está offline temporalmente".
 * Si el dispositivo ya envió datos alguna vez, no tiene sentido bloquear
 * al usuario en la pantalla de espera de primera conexión.
 */
function dispositivoTieneHistorial(int $id_colmena): bool {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT 1 
        FROM lectura l 
        INNER JOIN sensor s ON l.id_sensor = s.id_sensor 
        WHERE s.id_colmena = ?
        LIMIT 1
    ");
    $stmt->execute([$id_colmena]);
    return $stmt->fetch() !== false;
}

/**
 * Obtiene la fecha y hora de la lectura más reciente de la colmena.
 */
function ultimaConexion(int $id_colmena): ?string {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT l.fecha_hora 
        FROM lectura l 
        INNER JOIN sensor s ON l.id_sensor = s.id_sensor 
        WHERE s.id_colmena = ? 
        ORDER BY l.fecha_hora DESC 
        LIMIT 1
    ");
    $stmt->execute([$id_colmena]);
    $row = $stmt->fetch();
    return $row ? $row['fecha_hora'] : null;
}