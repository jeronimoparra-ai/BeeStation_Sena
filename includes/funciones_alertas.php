<?php
/**
 * Diagnóstico de solo lectura para el Centro de Alertas.
 * Evalúa únicamente umbrales definidos en variable_bioclimatica.
 */

function obtenerEstadoSensor(int $id_colmena, int $id_sensor, string $tipo_sensor): array
{
    $pdo = getPDO();

    $stmt = $pdo->prepare("
        SELECT l.fecha_hora, l.valor_calibrado, l.es_valida
        FROM lectura l
        INNER JOIN sensor s ON s.id_sensor = l.id_sensor
        WHERE s.id_colmena = ? AND s.id_sensor = ? AND s.tipo = ?
        ORDER BY l.fecha_hora DESC
        LIMIT 1
    ");
    $stmt->execute([$id_colmena, $id_sensor, $tipo_sensor]);
    $lectura = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT * FROM variable_bioclimatica WHERE nombre = ? LIMIT 1");
    $stmt->execute([$tipo_sensor]);
    $umbral = $stmt->fetch() ?: null;

    $umbralConfigurado = false;
    if ($umbral !== null) {
        foreach (['alerta_min', 'alerta_max', 'critico_min', 'critico_max'] as $campo) {
            if ($umbral[$campo] !== null) {
                $umbralConfigurado = true;
                break;
            }
        }
    }

    if (!$lectura) {
        return [
            'estado' => 'Sin datos',
            'nivel' => 'neutral',
            'motivo' => 'El sensor no tiene lecturas registradas.',
            'fecha_origen' => null,
            'lectura' => null,
            'umbral_configurado' => $umbralConfigurado,
        ];
    }

    $fechaOrigen = (string) $lectura['fecha_hora'];
    if ((int) $lectura['es_valida'] !== 1) {
        return [
            'estado' => 'Lectura inválida',
            'nivel' => 'neutral',
            'motivo' => 'La última lectura fue marcada como inválida y no se evalúa frente a umbrales.',
            'fecha_origen' => $fechaOrigen,
            'lectura' => null,
            'umbral_configurado' => $umbralConfigurado,
        ];
    }

    $timestamp = strtotime($fechaOrigen);
    if ($timestamp === false || $timestamp > time()) {
        return [
            'estado' => 'Fecha inválida',
            'nivel' => 'neutral',
            'motivo' => 'La última lectura tiene una fecha que no permite determinar su antigüedad.',
            'fecha_origen' => $fechaOrigen,
            'lectura' => null,
            'umbral_configurado' => $umbralConfigurado,
        ];
    }

    $minutos = (int) floor((time() - $timestamp) / 60);
    if ($minutos > 30) {
        return [
            'estado' => 'Sin datos recientes',
            'nivel' => 'neutral',
            'motivo' => 'La última lectura válida tiene ' . $minutos . ' minutos de antigüedad; supera la ventana de 30 minutos.',
            'fecha_origen' => $fechaOrigen,
            'lectura' => $lectura,
            'umbral_configurado' => $umbralConfigurado,
        ];
    }

    if (!$umbralConfigurado) {
        return [
            'estado' => 'Sin verificar',
            'nivel' => 'neutral',
            'motivo' => 'Hay una lectura válida reciente, pero esta variable no tiene umbrales configurados para evaluarla.',
            'fecha_origen' => $fechaOrigen,
            'lectura' => $lectura,
            'umbral_configurado' => false,
        ];
    }

    $valor = (float) $lectura['valor_calibrado'];
    $unidad = (string) ($umbral['unidad_medida'] ?? '');
    $esCritico = ($umbral['critico_min'] !== null && $valor < (float) $umbral['critico_min'])
        || ($umbral['critico_max'] !== null && $valor > (float) $umbral['critico_max']);
    $esAdvertencia = !$esCritico && (
        ($umbral['alerta_min'] !== null && $valor < (float) $umbral['alerta_min'])
        || ($umbral['alerta_max'] !== null && $valor > (float) $umbral['alerta_max'])
    );

    if ($esCritico) {
        $estado = 'Crítico';
        $nivel = 'critico';
        $motivo = 'Valor ' . $valor . ' ' . $unidad . ' fuera de un umbral crítico configurado.';
    } elseif ($esAdvertencia) {
        $estado = 'Advertencia';
        $nivel = 'advertencia';
        $motivo = 'Valor ' . $valor . ' ' . $unidad . ' fuera de un umbral de alerta configurado.';
    } else {
        $estado = 'Normal';
        $nivel = 'normal';
        $motivo = 'Lectura válida reciente dentro de los umbrales configurados.';
    }

    return [
        'estado' => $estado,
        'nivel' => $nivel,
        'motivo' => $motivo,
        'fecha_origen' => $fechaOrigen,
        'lectura' => $lectura,
        'umbral_configurado' => true,
    ];
}
