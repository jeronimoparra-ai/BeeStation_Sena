<?php
/**
 * config/db.example.php
 * ----------------------------------------------------------------
 * PLANTILLA de la configuración local. Copia este archivo como
 * config/db.local.php y ajusta los valores:
 *
 *   copy config\db.example.php config\db.local.php
 *
 * config/db.local.php NO se versiona (esta en .gitignore).
 *
 * IMPORTANTE: guardalo SIN BOM (UTF-8 sin marca de orden de bytes).
 * Con BOM, PHP emite esos 3 bytes antes de cada respuesta y el JSON
 * deja de ser parseable por los clientes estrictos.
 *
 * Usuario recomendado con privilegios mínimos:
 *   CREATE USER 'beestation_app'@'127.0.0.1' IDENTIFIED BY '<clave>';
 *   GRANT SELECT, INSERT, UPDATE, DELETE ON beestation_sena.* TO 'beestation_app'@'127.0.0.1';
 *   FLUSH PRIVILEGES;
 * ----------------------------------------------------------------
 */

return [
    'DB_HOST' => '127.0.0.1',
    'DB_NAME' => 'beestation_sena',
    'DB_USER' => 'beestation_app',
    'DB_PASS' => 'cambia-esta-clave',
];
