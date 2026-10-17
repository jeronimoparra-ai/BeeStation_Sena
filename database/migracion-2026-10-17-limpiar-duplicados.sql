-- ============================================================
-- BeeStation — Migración / limpieza 2026-10-17
-- SENA - Centro Minero Ambiental - El Bagre (Antioquia)
--
-- Detectado al revisar el volcado `beestation_sena.sql`:
--   * sensor:          ids 17 y 18 duplican a 15 y 16 (misma colmena+tipo)
--   * variable_bioclimatica: ids 10 y 11 duplican a 8 y 9 (mismo nombre)
--
-- Estas filas duplicadas NO provocan errores (api/ingest.php usa LIMIT 1
-- y toma el id menor), pero ensucian el inventario y pueden confundir.
-- Este script conserva el id menor y borra el duplicado SOLO si no tiene
-- lecturas asociadas (respeta la llave foránea de `lectura`).
--
-- Seguridad: haz copia de la base antes de ejecutar.
--   mysqldump -u root -p beestation_sena > backup_antes_limpieza.sql
-- ============================================================

USE beestation_sena;

-- ── 1. Sensores duplicados (misma colmena y tipo) ─────────────
DELETE s1 FROM sensor s1
JOIN sensor s2
  ON s1.id_colmena = s2.id_colmena
 AND s1.tipo      = s2.tipo
 AND s1.id_sensor > s2.id_sensor
WHERE NOT EXISTS (
  SELECT 1 FROM lectura l WHERE l.id_sensor = s1.id_sensor
);

-- ── 2. Variables bioclimáticas duplicadas (mismo nombre) ──────
-- Solo borra la duplicada si ningún sensor la referencia.
DELETE v1 FROM variable_bioclimatica v1
JOIN variable_bioclimatica v2
  ON v1.nombre = v2.nombre
 AND v1.id_variable > v2.id_variable
WHERE NOT EXISTS (
  SELECT 1 FROM sensor s WHERE s.id_variable = v1.id_variable
);

-- ── Verificación ──────────────────────────────────────────────
-- Debe devolver 0 filas duplicadas en cada consulta:
--   SELECT id_colmena, tipo, COUNT(*) c FROM sensor
--     GROUP BY id_colmena, tipo HAVING c > 1;
--   SELECT nombre, COUNT(*) c FROM variable_bioclimatica
--     GROUP BY nombre HAVING c > 1;
