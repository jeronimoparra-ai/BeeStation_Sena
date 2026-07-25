-- ============================================================
-- BeeStation / ApiTechnology — Esquema de Base de Datos
-- SENA - Centro de Formación Ambiental - Caucasia, Antioquia
-- Motor: MySQL / MariaDB
-- ============================================================

CREATE DATABASE IF NOT EXISTS beestation CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE beestation;

-- ── ROLES Y USUARIOS ──────────────────────────────────────────
CREATE TABLE rol (
    id_rol INT AUTO_INCREMENT PRIMARY KEY,
    nombre_rol VARCHAR(50) NOT NULL UNIQUE,
    descripcion VARCHAR(255),
    nivel_acceso INT NOT NULL DEFAULT 1
) ENGINE=InnoDB;

INSERT INTO rol (nombre_rol, descripcion, nivel_acceso) VALUES
('Apicultor', 'Consulta datos de sus colmenas y recibe alertas', 1),
('Administrativo', 'Acceso a reportes y gestión de apiarios', 2),
('Desarrollador', 'Acceso total al sistema, incluida la configuración técnica', 3);

CREATE TABLE usuario (
    id_usuario INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    correo VARCHAR(150) NOT NULL UNIQUE,
    contrasena VARCHAR(255) NOT NULL,   -- se guarda con password_hash()
    fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
    id_rol INT NOT NULL,
    FOREIGN KEY (id_rol) REFERENCES rol(id_rol)
) ENGINE=InnoDB;

-- ── APIARIOS Y COLMENAS ───────────────────────────────────────
CREATE TABLE apiario (
    id_apiario INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    ubicacion VARCHAR(255),
    municipio VARCHAR(100),
    fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
    id_usuario INT NOT NULL,
    FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario)
) ENGINE=InnoDB;

CREATE TABLE colmena (
    id_colmena INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,        -- ej: "Alpha-01"
    especie VARCHAR(100) DEFAULT 'Apis mellifera',
    fecha_instalacion DATE,
    estado ENUM('activa','inactiva','en_revision') DEFAULT 'activa',
    id_apiario INT NOT NULL,
    FOREIGN KEY (id_apiario) REFERENCES apiario(id_apiario)
) ENGINE=InnoDB;

-- ── VARIABLES BIOCLIMÁTICAS (umbrales de referencia) ─────────
CREATE TABLE variable_bioclimatica (
    id_variable INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL,          -- temperatura_interna, humedad, peso, sonido, co2
    unidad_medida VARCHAR(20) NOT NULL,
    optimo_min FLOAT, optimo_max FLOAT,
    alerta_min FLOAT, alerta_max FLOAT,
    critico_min FLOAT, critico_max FLOAT
) ENGINE=InnoDB;

INSERT INTO variable_bioclimatica (nombre, unidad_medida, optimo_min, optimo_max, alerta_min, alerta_max, critico_min, critico_max) VALUES
('temperatura_interna', '°C', 34, 36, 32, 38, 30, 40),
('temperatura_externa', '°C', NULL, NULL, NULL, NULL, NULL, NULL),
('humedad_relativa',    '%HR', 50, 70, 45, 80, 35, 85),
('peso',                'kg', NULL, NULL, NULL, NULL, NULL, NULL),
('sonido',              'Hz', 200, 380, 400, 600, 600, 900),
('co2',                 'ppm', 2000, 4000, 1000, 6000, 500, 10000);

-- ── SENSORES ──────────────────────────────────────────────────
CREATE TABLE sensor (
    id_sensor INT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(50) NOT NULL,            -- ej: 'temperatura_interna'
    modelo VARCHAR(50) NOT NULL,          -- DHT22, HX711, MAX9814, MQ-135
    rango_min FLOAT, rango_max FLOAT,
    precision_valor FLOAT,
    estado ENUM('en_linea','advertencia','sin_senal') DEFAULT 'sin_senal',
    fecha_instalacion DATE,
    id_colmena INT NOT NULL,
    id_variable INT NOT NULL,
    FOREIGN KEY (id_colmena) REFERENCES colmena(id_colmena),
    FOREIGN KEY (id_variable) REFERENCES variable_bioclimatica(id_variable)
) ENGINE=InnoDB;

-- ── CALIBRACIÓN ───────────────────────────────────────────────
CREATE TABLE calibracion (
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
    FOREIGN KEY (id_sensor) REFERENCES sensor(id_sensor)
) ENGINE=InnoDB;

-- ── LECTURAS (datos crudos que envía el ESP32) ───────────────
CREATE TABLE lectura (
    id_lectura BIGINT AUTO_INCREMENT PRIMARY KEY,
    valor_bruto FLOAT NOT NULL,
    valor_calibrado FLOAT NOT NULL,
    unidad VARCHAR(20),
    fecha_hora DATETIME DEFAULT CURRENT_TIMESTAMP,
    es_valida TINYINT(1) DEFAULT 1,
    id_sensor INT NOT NULL,
    FOREIGN KEY (id_sensor) REFERENCES sensor(id_sensor),
    INDEX idx_sensor_fecha (id_sensor, fecha_hora)
) ENGINE=InnoDB;

-- ── INDICADORES CALCULADOS (IBB, IRE, EV, etc.) ──────────────
CREATE TABLE indicador (
    id_indicador BIGINT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(30) NOT NULL,            -- IBB, IRE, EV, flujo_nectar, delta_t
    valor FLOAT NOT NULL,
    fecha_hora DATETIME DEFAULT CURRENT_TIMESTAMP,
    descripcion VARCHAR(255),
    estado_colonia VARCHAR(30),           -- Optimo, Bueno, Regular, Deficiente, Critico
    id_colmena INT NOT NULL,
    FOREIGN KEY (id_colmena) REFERENCES colmena(id_colmena),
    INDEX idx_colmena_tipo_fecha (id_colmena, tipo, fecha_hora)
) ENGINE=InnoDB;

-- ── ALERTAS ───────────────────────────────────────────────────
CREATE TABLE alerta (
    id_alerta INT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(50) NOT NULL,
    nivel TINYINT NOT NULL,               -- 1 notificación, 2 urgente, 3 crítica
    mensaje VARCHAR(255) NOT NULL,
    fecha_hora DATETIME DEFAULT CURRENT_TIMESTAMP,
    estado ENUM('activa','atendida','descartada') DEFAULT 'activa',
    id_indicador BIGINT,
    id_usuario INT,
    FOREIGN KEY (id_indicador) REFERENCES indicador(id_indicador),
    FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario)
) ENGINE=InnoDB;

-- ── DATOS INICIALES MÍNIMOS PARA QUE EL SISTEMA ARRANQUE ────
-- (usuario admin, un apiario y una colmena — sin lecturas falsas)

INSERT INTO usuario (nombre, correo, contrasena, id_rol) VALUES
('Administrador', 'admin@beestation.io', '$2y$10$3sZ1V5vE2Q0oQe9G1kQe9uQ8m5r5r5r5r5r5r5r5r5r5r5r5r5r5r', 3);
-- IMPORTANTE: la contraseña de arriba es un placeholder.
-- Genera el hash real con: password_hash("tu_clave", PASSWORD_DEFAULT)
-- y reemplázalo antes de usar en producción (ver README.txt).

INSERT INTO apiario (nombre, ubicacion, municipio, id_usuario) VALUES
('Apiario Norte', 'Centro de Formación Ambiental SENA', 'Caucasia, Antioquia', 1);

INSERT INTO colmena (nombre, fecha_instalacion, estado, id_apiario) VALUES
('Alpha-01', CURDATE(), 'activa', 1);

-- Sensores asociados a la colmena Alpha-01 (aún sin lecturas)
INSERT INTO sensor (tipo, modelo, rango_min, rango_max, precision_valor, estado, fecha_instalacion, id_colmena, id_variable) VALUES
('temperatura_interna', 'DHT22',   -40, 80, 0.5, 'sin_senal', CURDATE(), 1, 1),
('temperatura_externa', 'DHT22',   -40, 80, 0.5, 'sin_senal', CURDATE(), 1, 2),
('humedad_relativa',    'DHT22',   0, 100, 3,   'sin_senal', CURDATE(), 1, 3),
('peso',                'HX711',   0, 50, 0.01, 'sin_senal', CURDATE(), 1, 4),
('sonido',              'MAX9814', 20, 20000, NULL, 'sin_senal', CURDATE(), 1, 5),
('co2',                 'MQ-135',  10, 300, NULL, 'sin_senal', CURDATE(), 1, 6);

-- NOTA: A propósito NO se insertan lecturas, indicadores ni alertas de ejemplo.
-- El sistema se llenará únicamente con datos reales enviados por el ESP32
-- a través de api/ingest.php, o los que ingreses manualmente para pruebas.
