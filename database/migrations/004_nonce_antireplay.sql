-- Nonces de un solo uso para impedir la repetición de solicitudes firmadas.
-- MariaDB 10.4 / MySQL 8. No se eliminan datos existentes.
CREATE TABLE IF NOT EXISTS nonce_peticion (
  mac VARCHAR(17) NOT NULL,
  nonce CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (mac, nonce),
  KEY idx_nonce_creado (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
