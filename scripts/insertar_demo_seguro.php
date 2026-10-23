<?php
declare(strict_types=1);

/**
 * Deshabilitado: la versión anterior insertaba mediciones ficticias en la BD.
 * No se conecta a la base de datos ni modifica datos reales.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

fwrite(STDERR, "Este script está deshabilitado: no se generan mediciones de demostración.\n");
exit(1);
