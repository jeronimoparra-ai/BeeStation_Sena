-- ============================================================
--  Migración 008 · Unidad real de la variable `sonido`
--  BeeStation — 2026-10-21
--
--  Desde el firmware 3.2.0 el nodo estima frecuencia dominante
--  por cruces por cero y envía el valor en Hz; el panel y el
--  informe ya muestran "Hz" en pantalla. Este UPDATE alinea el
--  metadato `variable_bioclimatica.unidad_medida` con esa
--  unidad (y con `variables` de `schema.sql` línea 68).
--
--  NO DESTRUCTIVA: solo corrige metadatos del catálogo.
--  Idempotente: si ya está en 'Hz' no modifica ninguna fila.
-- ============================================================

UPDATE variable_bioclimatica
   SET unidad_medida = 'Hz'
 WHERE nombre = 'sonido'
   AND unidad_medida = 'V';
