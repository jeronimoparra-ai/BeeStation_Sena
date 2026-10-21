-- ============================================================
--  Migración 002 · Trazabilidad de lotes (recogida offline)
--  BeeStation · 2026-10-21
--
--  Cuando el nodo reenvía lecturas acumuladas en la microSD (o
--  cuando se recoge la tarjeta físicamente), cada envío lleva un
--  identificador de lote. Esta tabla permite reconciliar lo que el
--  nodo dice que ha subido con lo que el servidor ha recibido, y
--  saber qué lote corresponde a qué fichero de la tarjeta.
-- ============================================================

CREATE TABLE IF NOT EXISTS lote_ingesta (
  id_lote         INT(11)      NOT NULL AUTO_INCREMENT,
  identificador  VARCHAR(64)  NOT NULL,
  lote            VARCHAR(64)  NULL,
  id_colmena      INT(11)      NULL,
  filas_recibidas INT(11)      NOT NULL DEFAULT 0,
  recibido_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_lote),
  KEY idx_lote_identificador (identificador),
  KEY idx_lote_colmena (id_colmena),
  KEY idx_lote_recibido (recibido_en),
  CONSTRAINT lote_ingesta_ibfk_1 FOREIGN KEY (id_colmena)
    REFERENCES colmena (id_colmena) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
