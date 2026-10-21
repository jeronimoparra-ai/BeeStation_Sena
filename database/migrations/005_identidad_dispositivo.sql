-- ============================================================
--  Migración 005 · Identidad criptográfica por dispositivo
--  BeeStation — 2026-10-21
--
--  Sustituye el "token de vinculación" compartido por una identidad
--  ECDSA P-256 propia de cada nodo:
--
--    · La clave privada se genera en el ESP32 y nunca sale de él.
--    · La clave pública (SPKI PEM) se registra aquí.
--    · La huella SHA-256 de esa clave identifica al nodo sin ambigüedad.
--    · El estado (pendiente / autorizado / revocado) lo decide el usuario.
--
--  NO DESTRUCTIVA: solo crea tablas nuevas. No modifica ni elimina
--  ninguna tabla, columna o fila existente, de modo que el esquema HMAC
--  heredado sigue intacto durante la transición.
--
--  Compatible con MySQL 5.7 / MariaDB 10.2+ (no usa CTE ni ventanas).
-- ============================================================

-- ------------------------------------------------------------
--  Identidad de los dispositivos (nodos)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dispositivo (
    id_dispositivo   INT AUTO_INCREMENT PRIMARY KEY,

    -- Identificador del nodo: 128 bits aleatorios en hexadecimal en minúsculas.
    -- Lo genera el propio ESP32 y se mantiene estable entre reinicios.
    identificador     CHAR(32) NOT NULL,

    -- MAC del ESP32, en formato AA:BB:CC:DD:EE:FF.
    mac               VARCHAR(17) NOT NULL,

    -- Nombre legible ("Nodo colmena Norte").
    nombre            VARCHAR(120) NULL,

    -- Clave pública en formato SubjectPublicKeyInfo PEM ("BEGIN PUBLIC KEY").
    -- Es el único formato que acepta openssl_pkey_get_public() en PHP.
    clave_publica_pem TEXT NOT NULL,

    -- SHA-256 en hexadecimal en minúsculas de los bytes exactos del PEM.
    -- Vincula la identidad con la clave y detecta cambios de clave.
    huella_clave      CHAR(64) NOT NULL,

    -- pendiente  = registrado, aún no autorizado por el usuario
    -- autorizado = puede enviar telemetría a la colmena asignada
    -- revocado   = bloqueado de inmediato, de forma individual
    estado            ENUM('pendiente', 'autorizado', 'revocado') NOT NULL DEFAULT 'pendiente',

    -- Colmena a la que pertenece el nodo (NULL mientras está pendiente).
    id_colmena        INT NULL,

    -- Usuario que autorizó el nodo.
    id_usuario        INT NULL,

    -- Código corto de un solo uso para el alta. Solo se guarda su SHA-256.
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

-- ------------------------------------------------------------
--  Anti-replay de las peticiones firmadas con ECDSA
--
--  La clave primaria (identificador, nonce) hace atómica la
--  comprobación: un nonce no puede reutilizarse nunca para el
--  mismo dispositivo, ni siquiera en peticiones concurrentes.
--
--  Es una tabla aparte de nonce_peticion (esquema HMAC heredado)
--  para que ambos caminos puedan convivir durante la transición
--  sin interferirse entre sí.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS nonce_dispositivo (
    identificador CHAR(32) NOT NULL,
    nonce         CHAR(32) NOT NULL,
    creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (identificador, nonce),
    KEY idx_nonce_dispositivo_fecha (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
