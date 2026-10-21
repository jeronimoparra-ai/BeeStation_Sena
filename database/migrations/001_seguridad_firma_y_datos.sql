-- ============================================================
--  Migración 001 · Seguridad, firma por nodo y datos del sensor
--  BeeStation · 2026-10-20
--
--  Motivo: sustituir la API Key global por una firma HMAC por
--  colmena, habilitar el reenvío idempotente de lecturas offline
--  y registrar los dos sensores que el firmware envie y la
--  plataforma rechazaba (presion y luminosidad).
--
--  Idempotente: se puede ejecutar más de una vez sin efecto
--  (sintaxis de MariaDB 10.4).
-- ============================================================

-- ── 1. Identidad del nodo: qué ESP32 está detrás de cada colmena ──
ALTER TABLE colmena
  ADD COLUMN IF NOT EXISTS nodo_mac VARCHAR(17) NULL AFTER token_revocado;
ALTER TABLE colmena
  ADD COLUMN IF NOT EXISTS nodo_ultimo_contacto DATETIME NULL AFTER nodo_mac;

-- ── 2. Idempotencia de la ingesta: reenviar un lote nunca duplica ──
-- seq es el número de secuencia que envía el nodo (monótono).
-- Con NULL se comporta como una lectura normal (sin restricción).
ALTER TABLE lectura
  ADD COLUMN IF NOT EXISTS seq INT NULL AFTER es_valida;
ALTER TABLE lectura
  ADD UNIQUE KEY IF NOT EXISTS uq_sensor_seq (id_sensor, seq);

-- ── 3. El token de vinculación: único y obligatorio ──
-- Antes dos colmenas podían compartir token y el dispositivo
-- escribía en la colmena equivocada.
UPDATE colmena SET token_vinculacion = CONCAT('BS-PENDIENTE-', LPAD(id_colmena, 4, '0'))
  WHERE token_vinculacion IS NULL OR token_vinculacion = '';
ALTER TABLE colmena MODIFY token_vinculacion VARCHAR(64) NOT NULL;
ALTER TABLE colmena ADD UNIQUE KEY IF NOT EXISTS uq_token_vinculacion (token_vinculacion);

-- ── 4. Rangos físicos coherentes con las unidades reales ──
-- CO2 se mide en ppm, no en mg/m3: el rango 10-300 era de otra variable.
UPDATE sensor SET rango_min = 400,  rango_max = 6000  WHERE tipo = 'co2';
-- Sonido: el firmware entrega el nivel del microfono (0-3,3 V) hasta que
-- se implemente la conversion a Hz. Rango laxo para no invalidar el dato.
UPDATE sensor SET rango_min = 0,    rango_max = 20000 WHERE tipo = 'sonido';
-- Peso: el HX711 entrega el conteo del conversor hasta que se calibre
-- la escala. Rango laxo para no invalidar el dato.
UPDATE sensor SET rango_min = 0,    rango_max = 1000000000 WHERE tipo = 'peso';

-- ── 5. Variables que faltaban: presion (BME280) y luminosidad (BH1750) ──
INSERT INTO variable_bioclimatica
  (id_variable, nombre, unidad_medida, optimo_min, optimo_max, alerta_min, alerta_max, critico_min, critico_max)
VALUES
  (8, 'presion',      'hPa', 980, 1040, 950, 1060, 900, 1080),
  (9, 'luminosidad',  'lux',  50, 20000, 10, 60000, 0, 100000)
ON DUPLICATE KEY UPDATE
  nombre = VALUES(nombre), unidad_medida = VALUES(unidad_medida),
  optimo_min = VALUES(optimo_min), optimo_max = VALUES(optimo_max),
  alerta_min = VALUES(alerta_min), alerta_max = VALUES(alerta_max),
  critico_min = VALUES(critico_min), critico_max = VALUES(critico_max);

-- ── 6. Sensores de presion y luminosidad en cada colmena ──
-- El firmware los mide y los enviaba, pero la plataforma los rechazaba.
INSERT INTO sensor (tipo, modelo, rango_min, rango_max, precision_valor, estado, fecha_instalacion, id_colmena, id_variable)
SELECT 'presion', 'BME280', 300, 1100, 0.1, 'sin_senal', CURDATE(), c.id_colmena, 8
  FROM colmena c
 WHERE NOT EXISTS (SELECT 1 FROM sensor s WHERE s.id_colmena = c.id_colmena AND s.tipo = 'presion');

INSERT INTO sensor (tipo, modelo, rango_min, rango_max, precision_valor, estado, fecha_instalacion, id_colmena, id_variable)
SELECT 'luminosidad', 'BH1750', 0, 200000, 0.1, 'sin_senal', CURDATE(), c.id_colmena, 9
  FROM colmena c
 WHERE NOT EXISTS (SELECT 1 FROM sensor s WHERE s.id_colmena = c.id_colmena AND s.tipo = 'luminosidad');

-- ── 7. Un solo sensor de cada tipo por colmena (dato no duplicado) ──
-- Antes la búsqueda de sensor por tipo era arbitraria; ahora es única.
-- No eliminar sensores duplicados automáticamente: pueden tener
-- lecturas o calibraciones dependientes. Inventario para reconciliación:
SELECT id_colmena, tipo, COUNT(*) AS sensores, GROUP_CONCAT(id_sensor ORDER BY id_sensor) AS ids_sensor
  FROM sensor
 GROUP BY id_colmena, tipo
HAVING COUNT(*) > 1;

-- ── 8. Índices de apoyo para las consultas del panel ──
ALTER TABLE lectura      ADD INDEX IF NOT EXISTS idx_fecha (fecha_hora);
ALTER TABLE alerta       ADD INDEX IF NOT EXISTS idx_estado_fecha (estado, fecha_hora);
ALTER TABLE intento_vinculacion ADD INDEX IF NOT EXISTS idx_resultado_fecha (resultado, fecha_hora);
ALTER TABLE intento_vinculacion ADD INDEX IF NOT EXISTS idx_fecha (fecha_hora);
ALTER TABLE calibracion ADD INDEX IF NOT EXISTS idx_sensor_cal (id_sensor, id_calibracion);
