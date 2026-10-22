-- ============================================================
--  Migración 007 · Modelo real del micrófono
--  BeeStation — 2026-10-21
--
--  El nodo usa un MAX4466 (micrófono analógico con salida de
--  amplitud), no un MAX9814. El nombre del modelo aparecía mal en
--  el panel y en los documentos de entrega.
--
--  NO DESTRUCTIVA: solo corrige metadatos de la tabla `sensor`.
--  No toca lecturas ni variables.
-- ============================================================

UPDATE sensor
   SET modelo = 'MAX4466'
 WHERE tipo = 'sonido'
   AND modelo IN ('MAX9814', 'MAX4466');
