<?php
/**
 * includes/functions.php
 * Todas las funciones aquí consultan la base de datos real.
 * Si no hay datos todavía, devuelven null o arreglos vacíos —
 * nunca se generan datos ficticios.
 */
require_once __DIR__ . '/../config/db.php';

/** Devuelve la colmena activa por defecto (la primera del apiario del usuario) */
function obtenerColmenaActiva(int $id_usuario): ?array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT c.* FROM colmena c
        INNER JOIN apiario a ON c.id_apiario = a.id_apiario
        WHERE a.id_usuario = ?
        ORDER BY c.id_colmena ASC
        LIMIT 1
    ");
    $stmt->execute([$id_usuario]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Última lectura calibrada de un sensor por tipo, para una colmena */
function ultimaLectura(int $id_colmena, string $tipo_sensor): ?array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT l.*, s.modelo, s.estado AS estado_sensor
        FROM lectura l
        INNER JOIN sensor s ON l.id_sensor = s.id_sensor
        WHERE s.id_colmena = ? AND s.tipo = ? AND l.es_valida = 1
        ORDER BY l.fecha_hora DESC
        LIMIT 1
    ");
    $stmt->execute([$id_colmena, $tipo_sensor]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Serie histórica de un tipo de sensor en las últimas N horas */
function serieHistorica(int $id_colmena, string $tipo_sensor, int $horas = 24): array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT l.valor_calibrado, l.fecha_hora
        FROM lectura l
        INNER JOIN sensor s ON l.id_sensor = s.id_sensor
        WHERE s.id_colmena = ? AND s.tipo = ? AND l.es_valida = 1
          AND l.fecha_hora >= (NOW() - INTERVAL ? HOUR)
        ORDER BY l.fecha_hora ASC
    ");
    $stmt->execute([$id_colmena, $tipo_sensor, $horas]);
    return $stmt->fetchAll();
}

/** Serie histórica de peso en los últimos N días (para la vista Peso) */
function serieHistoricaDias(int $id_colmena, string $tipo_sensor, int $dias = 30): array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT l.valor_calibrado, l.fecha_hora
        FROM lectura l
        INNER JOIN sensor s ON l.id_sensor = s.id_sensor
        WHERE s.id_colmena = ? AND s.tipo = ? AND l.es_valida = 1
          AND l.fecha_hora >= (NOW() - INTERVAL ? DAY)
        ORDER BY l.fecha_hora ASC
    ");
    $stmt->execute([$id_colmena, $tipo_sensor, $dias]);
    return $stmt->fetchAll();
}

/** Todos los sensores registrados para una colmena, con su última lectura */
function sensoresConEstado(int $id_colmena): array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT s.*,
            (SELECT l.valor_calibrado FROM lectura l
             WHERE l.id_sensor = s.id_sensor AND l.es_valida = 1
             ORDER BY l.fecha_hora DESC LIMIT 1) AS ultima_lectura,
            (SELECT l.fecha_hora FROM lectura l
             WHERE l.id_sensor = s.id_sensor AND l.es_valida = 1
             ORDER BY l.fecha_hora DESC LIMIT 1) AS ultima_fecha
        FROM sensor s
        WHERE s.id_colmena = ?
        ORDER BY s.id_sensor ASC
    ");
    $stmt->execute([$id_colmena]);
    return $stmt->fetchAll();
}

/** Alertas activas de una colmena (a través de sus indicadores) */
function alertasActivas(int $id_colmena, int $limite = 10): array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT al.*
        FROM alerta al
        INNER JOIN indicador i ON al.id_indicador = i.id_indicador
        WHERE i.id_colmena = ? AND al.estado = 'activa'
        ORDER BY al.fecha_hora DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $id_colmena, PDO::PARAM_INT);
    $stmt->bindValue(2, $limite, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Último valor de un indicador calculado (IBB, IRE, etc.) para una colmena */
function ultimoIndicador(int $id_colmena, string $tipo): ?array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT * FROM indicador
        WHERE id_colmena = ? AND tipo = ?
        ORDER BY fecha_hora DESC
        LIMIT 1
    ");
    $stmt->execute([$id_colmena, $tipo]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Calcula el flujo diario de peso real (kg ganados/perdidos hoy)
 * a partir de las lecturas reales de la tabla `lectura`.
 * Devuelve null si no hay suficientes datos.
 */
function calcularFlujoDiarioPeso(int $id_colmena): ?float {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT l.valor_calibrado, l.fecha_hora
        FROM lectura l
        INNER JOIN sensor s ON l.id_sensor = s.id_sensor
        WHERE s.id_colmena = ? AND s.tipo = 'peso' AND l.es_valida = 1
          AND l.fecha_hora >= (NOW() - INTERVAL 24 HOUR)
        ORDER BY l.fecha_hora ASC
    ");
    $stmt->execute([$id_colmena]);
    $rows = $stmt->fetchAll();
    if (count($rows) < 2) return null;
    $primero = (float) $rows[0]['valor_calibrado'];
    $ultimo  = (float) end($rows)['valor_calibrado'];
    return round($ultimo - $primero, 2);
}

/**
 * Calcula el Índice de Bienestar Bioclimático (IBB) real
 * a partir de las últimas lecturas de temperatura, humedad y CO2.
 * Devuelve null si falta alguna variable (no se inventan valores).
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

    if ($ibb >= 85)      $estado = 'Óptimo';
    elseif ($ibb >= 70)  $estado = 'Bueno';
    elseif ($ibb >= 50)  $estado = 'Regular';
    elseif ($ibb >= 30)  $estado = 'Deficiente';
    else                 $estado = 'Crítico';

    return ['valor' => $ibb, 'estado' => $estado];
}

/** Formatea "hace X min/h/días" a partir de un datetime real */
function tiempoRelativo(?string $fecha_hora): string {
    if (!$fecha_hora) return 'sin datos';
    $diff = time() - strtotime($fecha_hora);
    if ($diff < 60) return 'hace ' . $diff . ' s';
    if ($diff < 3600) return 'hace ' . floor($diff / 60) . ' min';
    if ($diff < 86400) return 'hace ' . floor($diff / 3600) . ' h';
    return 'hace ' . floor($diff / 86400) . ' días';
}

/**
 * Verifica si el dispositivo (ESP32) está conectado 
 * enviando datos recientes a la colmena activa.
 */
function dispositivoConectado(int $id_colmena, int $minutos = 5): bool {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS c FROM lectura l
        INNER JOIN sensor s ON l.id_sensor = s.id_sensor
        WHERE s.id_colmena = ? AND l.fecha_hora >= (NOW() - INTERVAL ? MINUTE)
    ");
    $stmt->bindValue(1, $id_colmena, PDO::PARAM_INT);
    $stmt->bindValue(2, $minutos, PDO::PARAM_INT);
    $stmt->execute();
    return ((int) $stmt->fetch()['c']) > 0;
}

/**
 * Devuelve la fecha_hora de la lectura más reciente de una colmena,
 * o null si no hay ninguna.
 */
function ultimaConexion(int $id_colmena): ?string {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT l.fecha_hora FROM lectura l
        INNER JOIN sensor s ON l.id_sensor = s.id_sensor
        WHERE s.id_colmena = ?
        ORDER BY l.fecha_hora DESC
        LIMIT 1
    ");
    $stmt->execute([$id_colmena]);
    $row = $stmt->fetch();
    return $row ? $row['fecha_hora'] : null;
}
