-- ============================================================
-- BeeStation / ApiTechnology — Script de Migración
-- Objetivo: Eliminar la tabla `rol`, la columna `id_rol` en `usuario`
--           y corregir sede oficial en `apiario`.
-- Motor: MySQL 5.7+ / MySQL 8.0+ / MariaDB 10.2+
-- ============================================================

USE beestation_sena;

-- ────────────────────────────────────────────────────────────
-- 1. ELIMINAR LA FOREIGN KEY QUE APUNTA A `rol` EN `usuario`
-- (Se detecta dinámicamente el nombre asignado por InnoDB)
-- ────────────────────────────────────────────────────────────
SET @fk_name = (
    SELECT CONSTRAINT_NAME 
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'usuario' 
      AND REFERENCED_TABLE_NAME = 'rol' 
    LIMIT 1
);

SET @drop_fk_sql = IF(@fk_name IS NOT NULL, 
    CONCAT('ALTER TABLE usuario DROP FOREIGN KEY `', @fk_name, '`'), 
    'SELECT "OK: No existe FK hacia la tabla rol en usuario."'
);

PREPARE stmt_fk FROM @drop_fk_sql;
EXECUTE stmt_fk;
DEALLOCATE PREPARE stmt_fk;

-- ────────────────────────────────────────────────────────────
-- 2. ELIMINAR LA COLUMNA `id_rol` DE LA TABLA `usuario`
-- ────────────────────────────────────────────────────────────
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'usuario' 
      AND COLUMN_NAME = 'id_rol'
);

SET @drop_col_sql = IF(@col_exists > 0, 
    'ALTER TABLE usuario DROP COLUMN id_rol', 
    'SELECT "OK: La columna id_rol ya no existe en usuario."'
);

PREPARE stmt_col FROM @drop_col_sql;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;

-- ────────────────────────────────────────────────────────────
-- 3. ELIMINAR LA TABLA `rol`
-- ────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS rol;

-- ────────────────────────────────────────────────────────────
-- 4. CORREGIR SEDE / MUNICIPIO DEL APIARIO INICIAL
-- ────────────────────────────────────────────────────────────
UPDATE apiario 
SET municipio = 'El Bagre, Antioquia', 
    ubicacion = 'Centro Minero Ambiental SENA'
WHERE id_apiario = 1 OR municipio LIKE '%Caucasia%';
