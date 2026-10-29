-- ============================================================
-- BeeStation / ApiTechnology — Esquema consolidado
-- SENA - Centro Minero Ambiental - El Bagre, Antioquia
-- Motor: MySQL / MariaDB
-- Sistema sin roles: se elimina por completo la tabla rol y la columna id_rol.
-- ============================================================

-- Instalación no destructiva e idempotente.
--   Este archivo NO crea ni selecciona la base de datos: el nombre lo
--   decide quien instala, para que nunca se aplique por error a otra base
--   de la misma instancia. Uso:
--       mysql -u USUARIO -p NOMBRE_BASE < database/schema.sql
--   Las tablas usan CREATE TABLE IF NOT EXISTS, así que puede ejecutarse
--   sobre una base existente sin destruir datos (no altera tablas ya creadas).
--   Para pruebas aisladas: php tools/migrar-pruebas.php

-- ── USUARIOS ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS usuario (
    id_usuario INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    correo VARCHAR(150) NOT NULL UNIQUE,
    contrasena VARCHAR(255) NOT NULL,
    fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── APIARIOS Y COLMENAS ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS apiario (
    id_apiario INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    ubicacion VARCHAR(255),
    municipio VARCHAR(100),
    fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
    id_usuario INT NOT NULL,
    FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS colmena (
    id_colmena INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    especie VARCHAR(100) DEFAULT 'Apis mellifera',
    fecha_instalacion DATE,
    estado ENUM('activa','inactiva','en_revision') DEFAULT 'activa',
    token_vinculacion VARCHAR(64) NOT NULL,
    intentos_fallidos_token INT NOT NULL DEFAULT 0,
    token_revocado TINYINT(1) NOT NULL DEFAULT 0,
    nodo_mac VARCHAR(17) NULL,
    nodo_ultimo_contacto DATETIME NULL,
    id_apiario INT NOT NULL,
    UNIQUE KEY uq_token_vinculacion (token_vinculacion),
    FOREIGN KEY (id_apiario) REFERENCES apiario(id_apiario)
) ENGINE=InnoDB;

-- ── VARIABLES BIOCLIMÁTICAS ───────────────────────────────────
CREATE TABLE IF NOT EXISTS variable_bioclimatica (
    id_variable INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL,
    unidad_medida VARCHAR(20) NOT NULL,
    optimo_min FLOAT,
    optimo_max FLOAT,
    alerta_min FLOAT,
    alerta_max FLOAT,
    critico_min FLOAT,
    critico_max FLOAT
) ENGINE=InnoDB;

INSERT INTO variable_bioclimatica (
    nombre, unidad_medida, optimo_min, optimo_max, alerta_min, alerta_max, critico_min, critico_max
) VALUES
('temperatura_interna', '°C', 34, 36, 32, 38, 30, 40),
('temperatura_externa', '°C', NULL, NULL, NULL, NULL, NULL, NULL),
('humedad_relativa', '%HR', 50, 70, 45, 80, 35, 85),
('peso', 'kg', NULL, NULL, NULL, NULL, NULL, NULL),
('sonido', 'Hz', NULL, NULL, NULL, NULL, NULL, NULL),
('co2', 'ppm', 2000, 4000, 1000, 6000, 500, 10000),
('energia', 'V', 3.7, 4.2, 3.0, 4.2, 2.8, 4.2),
('presion', 'hPa', 980, 1040, 950, 1060, 900, 1080),
('luminosidad', 'lux', 50, 20000, 10, 60000, 0, 100000);

-- ── SENSORES ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sensor (
    id_sensor INT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(50) NOT NULL,
    modelo VARCHAR(50) NOT NULL,
    rango_min FLOAT,
    rango_max FLOAT,
    precision_valor FLOAT,
    estado ENUM('en_linea','advertencia','sin_senal') DEFAULT 'sin_senal',
    fecha_instalacion DATE,
    id_colmena INT NOT NULL,
    id_variable INT NOT NULL,
    UNIQUE KEY uq_sensor_colmena_tipo (id_colmena, tipo),
    FOREIGN KEY (id_colmena) REFERENCES colmena(id_colmena),
    FOREIGN KEY (id_variable) REFERENCES variable_bioclimatica(id_variable)
) ENGINE=InnoDB;

-- ── CALIBRACIÓN ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS calibracion (
    id_calibracion INT AUTO_INCREMENT PRIMARY KEY,
    fecha_calibracion DATETIME DEFAULT CURRENT_TIMESTAMP,
    valor_referencia FLOAT,
    valor_medido FLOAT,
    factor_correccion FLOAT DEFAULT 0,
    metodo VARCHAR(100),
    responsable VARCHAR(100),
    resultado VARCHAR(50),
    proxima_calibracion DATE,
    id_sensor INT NOT NULL,
    -- Indice compuesto: cubre la FK y las consultas de historico por sensor.
    INDEX idx_sensor_cal (id_sensor, id_calibracion),
    FOREIGN KEY (id_sensor) REFERENCES sensor(id_sensor)
) ENGINE=InnoDB;

-- ── LECTURAS ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS lectura (
    id_lectura BIGINT AUTO_INCREMENT PRIMARY KEY,
    valor_bruto FLOAT NOT NULL,
    valor_calibrado FLOAT NOT NULL,
    unidad VARCHAR(20),
    fecha_hora DATETIME DEFAULT CURRENT_TIMESTAMP,
    es_valida TINYINT(1) DEFAULT 1,
    seq BIGINT NULL,
    -- Identificador estable de la medicion (128 bits hex, minusculas). Lo
    -- genera el nodo al TOMAR la lectura y viaja con ella aunque se
    -- reenvie mas tarde desde la cola offline: permite deduplicar sin
    -- depender del par (sensor, seq).
    id_medicion CHAR(32) NULL,
    id_sensor INT NOT NULL,
    FOREIGN KEY (id_sensor) REFERENCES sensor(id_sensor),
    INDEX idx_sensor_fecha (id_sensor, fecha_hora),
    INDEX idx_fecha (fecha_hora),
    UNIQUE KEY uq_sensor_seq (id_sensor, seq),
    UNIQUE KEY uq_lectura_id_medicion (id_medicion)
) ENGINE=InnoDB;

-- ── INDICADORES CALCULADOS ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS indicador (
    id_indicador BIGINT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(30) NOT NULL,
    valor FLOAT NOT NULL,
    fecha_hora DATETIME DEFAULT CURRENT_TIMESTAMP,
    descripcion VARCHAR(255),
    estado_colonia VARCHAR(30),
    id_colmena INT NOT NULL,
    FOREIGN KEY (id_colmena) REFERENCES colmena(id_colmena),
    INDEX idx_colmena_tipo_fecha (id_colmena, tipo, fecha_hora)
) ENGINE=InnoDB;

-- ── ALERTAS ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS alerta (
    id_alerta INT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(50) NOT NULL,
    nivel TINYINT NOT NULL,
    mensaje VARCHAR(255) NOT NULL,
    fecha_hora DATETIME DEFAULT CURRENT_TIMESTAMP,
    estado ENUM('activa','atendida','descartada') DEFAULT 'activa',
    id_indicador BIGINT,
    id_usuario INT,
    INDEX idx_estado_fecha (estado, fecha_hora),
    FOREIGN KEY (id_indicador) REFERENCES indicador(id_indicador),
    FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS intento_vinculacion (
    id_intento BIGINT AUTO_INCREMENT PRIMARY KEY,
    token_recibido VARCHAR(128) NOT NULL,
    ip_origen VARCHAR(45) NULL,
    resultado VARCHAR(40) NOT NULL,
    fecha_hora DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_intento_resultado_fecha (resultado, fecha_hora)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS lote_ingesta (
    id_lote BIGINT AUTO_INCREMENT PRIMARY KEY,
    identificador VARCHAR(64) NOT NULL,
    lote VARCHAR(64) NULL,
    id_colmena INT NULL,
    filas_recibidas INT NOT NULL DEFAULT 0,
    recibido_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_lote_identificador (identificador),
    INDEX idx_lote_colmena (id_colmena),
    INDEX idx_lote_recibido (recibido_en),
    CONSTRAINT fk_lote_colmena FOREIGN KEY (id_colmena) REFERENCES colmena(id_colmena) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ── IDENTIDAD DE DISPOSITIVOS (ECDSA P-256) ────────────────────
-- Cada nodo genera su propia clave. La privada nunca sale del ESP32; aqui
-- solo se guarda la publica (SPKI PEM) y la huella SHA-256 de sus bytes.
CREATE TABLE IF NOT EXISTS dispositivo (
    id_dispositivo   INT AUTO_INCREMENT PRIMARY KEY,
    identificador     CHAR(32) NOT NULL,
    mac               VARCHAR(17) NOT NULL,
    nombre            VARCHAR(120) NULL,
    clave_publica_pem TEXT NOT NULL,
    huella_clave      CHAR(64) NOT NULL,
    estado            ENUM('pendiente','autorizado','revocado') NOT NULL DEFAULT 'pendiente',
    id_colmena        INT NULL,
    id_usuario        INT NULL,
    codigo_hash       CHAR(64) NULL,
    codigo_expira     DATETIME NULL,
    codigo_intentos   INT NOT NULL DEFAULT 0,
    codigo_max_intentos INT NOT NULL DEFAULT 5,
    codigo_usado      TINYINT(1) NOT NULL DEFAULT 0,
    creado_en         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aprobado_en       DATETIME NULL,
    revocado_en       DATETIME NULL,
    motivo_revocacion VARCHAR(255) NULL,
    ultimo_contacto   DATETIME NULL,
    UNIQUE KEY uq_dispositivo_identificador (identificador),
    UNIQUE KEY uq_dispositivo_huella (huella_clave),
    KEY idx_dispositivo_estado (estado),
    KEY idx_dispositivo_colmena (id_colmena),
    CONSTRAINT fk_dispositivo_colmena FOREIGN KEY (id_colmena)
        REFERENCES colmena (id_colmena) ON DELETE SET NULL,
    CONSTRAINT fk_dispositivo_usuario FOREIGN KEY (id_usuario)
        REFERENCES usuario (id_usuario) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Anti-replay del esquema ECDSA (separado del HMAC heredado).
CREATE TABLE IF NOT EXISTS nonce_dispositivo (
    identificador CHAR(32) NOT NULL,
    nonce         CHAR(32) NOT NULL,
    creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (identificador, nonce),
    KEY idx_nonce_dispositivo_fecha (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nonce_peticion (
    mac VARCHAR(17) NOT NULL,
    nonce CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (mac, nonce),
    KEY idx_nonce_creado (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── DATOS INICIALES MÍNIMOS ───────────────────────────────────
-- No se incluyen credenciales, usuarios, colmenas ni lecturas de demostración.
-- Provisiona la primera cuenta mediante un proceso administrativo seguro.
