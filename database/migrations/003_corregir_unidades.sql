-- ============================================================
--  Migración 003 · Corregir las unidades de temperatura
--  BeeStation · 2026-10-21
--
--  Al insertar el catálogo inicial, el símbolo de grado (°) se
--  grabó como dos signos de interrogación ("??"), de modo que el
--  panel y los informes mostraban "34.80 ??C" en lugar de "34.80 °C".
--
--  La corrección se hace con la secuencia de bytes UTF-8 explícita
--  (C2 B0 = U+00B0) para que no dependa del charset del cliente
--  que ejecute la sentencia.
-- ============================================================

UPDATE variable_bioclimatica
   SET unidad_medida = CONVERT(_utf8mb4 0xC2B043 USING utf8mb4)
 WHERE id_variable IN (1, 2);

-- Comprobación: el resultado debe mostrar C2B043 (°C) y no 3F3F43 (??C)
SELECT id_variable, nombre, unidad_medida, HEX(unidad_medida) AS hex
  FROM variable_bioclimatica
 WHERE id_variable IN (1, 2);
