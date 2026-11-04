<?php
/**
 * config/app.example.php
 * ----------------------------------------------------------------
 * PLANTILLA de la configuración de URL canónica.
 *
 *   copy config\app.example.php config\app.local.php
 *
 * config/app.local.php NO se versiona (esta en .gitignore) y el
 * servidor web no lo sirve (config/ esta denegado en .htaccess).
 *
 * Para qué sirve
 *   BeeStation necesita saber su origen público para poder mostrar el
 *   endpoint de ingesta de los ESP32. Ese origen NO se deduce de las
 *   cabeceras del cliente (HTTP_HOST / X-Forwarded-*), porque el cliente
 *   puede falsificarlas.
 *
 * Formato de APP_URL
 *   Sólo esquema + host + puerto opcional. SIN ruta, sin barra final,
 *   sin credenciales, sin query:
 *
 *     Producción : 'https://beestation.cfmasoft.com'
 *     Local      : 'https://mi-equipo.local'   (opcional)
 *
 *   En desarrollo local NO hace falta configurar APP_URL: si se accede
 *   por http://localhost/... o http://127.0.0.1/... el origen se deriva
 *   de la propia petición. En cualquier otro caso, sin APP_URL, el panel
 *   muestra un aviso en lugar de inventar una URL.
 *
 * Alternativa
 *   También puede definirse la variable de entorno APP_URL, que tiene
 *   precedencia sobre este archivo. Y, por comodidad, se acepta la clave
 *   APP_URL dentro de config/db.local.php.
 * ----------------------------------------------------------------
 */

return [
    // Origen público. Sin ruta y sin barra final.
    'APP_URL' => 'https://beestation.cfmasoft.com',

    // Poner en true SOLO si BeeStation está detrás de un proxy propio
    // que termina TLS (nginx, Cloudflare Tunnel propio, etc.). Con true
    // se acepta X-Forwarded-Proto: https. X-Forwarded-Host se ignora
    // siempre, en cualquier caso.
    'TRUST_PROXY' => false,
];
