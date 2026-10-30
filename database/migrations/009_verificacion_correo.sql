-- ============================================================
-- Migración 009: Preparación para verificación de correo
-- BeeStation / ApiTechnology
-- SENA - Centro Minero Ambiental - El Bagre, Antioquia
--
-- NOTA IMPORTANTE:
-- Esta migración es incremental y NO destructiva.
-- NO se ejecuta automáticamente en producción hasta que la
-- infraestructura SMTP (Hostinger) esté configurada y validada.
-- Mantiene compatibilidad total con la arquitectura sin roles.
-- Las cuentas existentes mantienen valor 1 (verificado) para
-- no interrumpir el acceso existente.
-- ============================================================

ALTER TABLE usuario
    ADD COLUMN IF NOT EXISTS correo_verificado TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=verificado o cuenta previa, 0=pendiente',
    ADD COLUMN IF NOT EXISTS token_verificacion_hash VARCHAR(64) DEFAULT NULL COMMENT 'Hash SHA-256 del token de un solo uso',
    ADD COLUMN IF NOT EXISTS token_verificacion_expira DATETIME DEFAULT NULL COMMENT 'Fecha/hora de expiración del token';
