-- ============================================================
--  Migración 006 · Identificador estable de medición y acuse por lectura
--  BeeStation — 2026-10-21
--
--  El nodo genera un identificador único POR MEDICIÓN en el momento en
--  que la toma (no en el momento en que la envía). Ese identificador
--  viaja con la lectura y se conserva cuando la lectura se guarda en la
--  cola offline de la microSD.
--
--  Consecuencias:
--    · Un reenvío de la misma medición se reconoce como duplicado con
--      certeza, sin depender del par (sensor, seq) que puede colapsar.
--    · El servidor puede ACUSAR CADA lectura por separado, de modo que
--      el nodo solo borra de la cola lo que el servidor ha confirmado.
--    · Se pueden detectar Measurements lostos (huecos en la secuencia).
--
--  NO DESTRUCTIVA: solo añade una columna y un índice.
-- ============================================================

-- Identificador de la medición: 128 bits aleatorios en hexadecimal en
-- minúsculas, generado por el nodo al tomar la lectura.
ALTER TABLE lectura
    ADD COLUMN IF NOT EXISTS id_medicion CHAR(32) NULL AFTER seq;

-- Unicidad global: dos colmenas distintas no pueden generar el mismo
-- identificador por casualidad, y un mismo identificador con distinto
-- sensor es un error que debe verse, no ignorarse.
ALTER TABLE lectura
    ADD UNIQUE KEY IF NOT EXISTS uq_lectura_id_medicion (id_medicion);

-- Índice de apoyo para localizar la serie por identificador.
ALTER TABLE lectura
    ADD INDEX IF NOT EXISTS idx_lectura_id_medicion (id_medicion);
