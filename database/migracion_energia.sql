-- ============================================================
-- BeeStation / ApiTechnology -- Script de Migracion
-- Objetivo: Agregar variable bioclimatica 'energia' y su sensor
--           INA219 asociado a la colmena Alpha-01 (id_colmena = 1).
-- Motor: MySQL 5.7+ / MySQL 8.0+ / MariaDB 10.2+
-- Prerequisito: migration_remove_roles.sql ya aplicada.
-- ============================================================
--
-- INSTRUCCIONES DE APLICACION:
--   mysql -u root -p beestation_sena < database/migracion_energia.sql
--   (o ejecuta el contenido completo desde phpMyAdmin / DBeaver)
--
-- SEGURIDAD: El script es IDEMPOTENTE via guardas IF NOT EXISTS /
--   comprobaciones con SELECT COUNT(*), por lo que puede ejecutarse
--   mas de una vez sin duplicar registros.
-- ============================================================

USE beestation_sena;

-- ---------------------------------------------------------------
-- 1. VARIABLE BIOCLIMATICA: energia (voltaje de bateria LiPo)
--    Rangos segun documento de requisitos del proyecto:
--      Optimo:  3.7 V - 4.2 V
--      Alerta:  3.0 V - 4.2 V
--      Critico: 2.8 V - 4.2 V
--    El maximo nunca supera 4.2 V en todas las bandas (carga
--    completa de la celda LiPo); el minimo baja por nivel de
--    descarga tolerable.
-- ---------------------------------------------------------------
INSERT INTO variable_bioclimatica
    (nombre, unidad_medida, optimo_min, optimo_max,
     alerta_min, alerta_max, critico_min, critico_max)
SELECT
    'energia', 'V',
    3.7, 4.2,
    3.0, 4.2,
    2.8, 4.2
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM variable_bioclimatica WHERE nombre = 'energia'
);

-- Capturar el id de la fila recien insertada (o ya existente)
SET @id_var_energia = (
    SELECT id_variable
    FROM variable_bioclimatica
    WHERE nombre = 'energia'
    LIMIT 1
);

-- ---------------------------------------------------------------
-- 2. SENSOR: INA219 -- colmena Alpha-01 (id_colmena = 1)
--    rango_min/max: 0-5 V (margen fisico del modulo INA219
--                  operando con celda LiPo de 1S)
--    precision_valor: 0.01 V (resolucion efectiva del INA219)
--    estado inicial: 'sin_senal' -- el ESP32 lo pondra 'en_linea'
--    al reportar la primera lectura via api/ingest.php.
-- ---------------------------------------------------------------
INSERT INTO sensor
    (tipo, modelo, rango_min, rango_max, precision_valor,
     estado, fecha_instalacion, id_colmena, id_variable)
SELECT
    'energia', 'INA219',
    0, 5, 0.01,
    'sin_senal', CURDATE(),
    1,               -- id_colmena = 1 (Alpha-01)
    @id_var_energia
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM sensor
    WHERE tipo = 'energia' AND id_colmena = 1
);

-- ---------------------------------------------------------------
-- Confirmacion visual (util al ejecutar desde CLI / phpMyAdmin)
-- ---------------------------------------------------------------
SELECT
    s.id_sensor,
    s.tipo,
    s.modelo,
    s.estado,
    s.rango_min,
    s.rango_max,
    s.precision_valor,
    s.fecha_instalacion,
    v.nombre         AS variable,
    v.unidad_medida,
    v.optimo_min,
    v.optimo_max,
    v.alerta_min,
    v.alerta_max,
    v.critico_min,
    v.critico_max
FROM sensor s
INNER JOIN variable_bioclimatica v ON s.id_variable = v.id_variable
WHERE s.tipo = 'energia' AND s.id_colmena = 1;
