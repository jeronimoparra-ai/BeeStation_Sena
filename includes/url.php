<?php
/**
 * includes/url.php
 * -----------------------------------------------------------------
 * Resolución de la URL canónica de BeeStation.
 *
 * PROBLEMA QUE RESUELVE
 *   El panel mostraba el endpoint de ingesta construyéndolo con
 *   $_SERVER['HTTP_HOST'] y con las cabeceras X-Forwarded-Host /
 *   X-Forwarded-Proto. Esas tres fuentes las controla el cliente: un
 *   visitante podía hacer que el panel le mostrara un endpoint apuntando
 *   a un dominio ajeno y, si el operador lo copiaba al ESP32, la
 *   telemetría habría viajado a un tercero.
 *
 * POLÍTICA (deliberadamente estricta)
 *   1. Si hay una URL canónica configurada (APP_URL por variable de
 *      entorno, config/app.local.php o config/db.local.php) se usa
 *      SIEMPRE. Es el único valor de confianza.
 *   2. Si no hay APP_URL y la petición llega por un host de bucle local
 *      (localhost / 127.0.0.1 / [::1]) se deriva el origen de la propia
 *      petición, sólo para comodidad del desarrollo local.
 *   3. En cualquier otro caso se devuelve null: la aplicación NO inventa
 *      una URL, NO cae a localhost y NO usa HTTP_HOST del cliente.
 *
 *   Nunca se consulta HTTP_X_FORWARDED_HOST. HTTP_X_FORWARDED_PROTO sólo
 *   se acepta cuando TRUST_PROXY está habilitado de forma explícita
 *   (BeeStation detrás de un proxy propio que termina TLS).
 *
 *   Una APP_URL que apunte a un host local se RECHAZA cuando la petición
 *   llega por un host no local: así una configuración de desarrollo no se
 *   filtra por accidente a producción.
 * -----------------------------------------------------------------
 */

if (!function_exists('bee_config_app')) {

    /**
     * Configuración de URL canónica.
     *
     * Orden de precedencia: variable de entorno > config/app.local.php >
     * clave APP_URL dentro de config/db.local.php (para no obligar a crear
     * dos archivos locales).
     *
     * @return array<string,mixed>
     */
    function bee_config_app(): array
    {
        static $cfg = null;
        if (is_array($cfg)) {
            return $cfg;
        }
        $cfg = [];

        $archivoApp = __DIR__ . '/../config/app.local.php';
        if (is_file($archivoApp)) {
            $cargado = require $archivoApp;
            if (is_array($cargado)) {
                $cfg = $cargado;
            }
        }

        $tieneUrl = isset($cfg['APP_URL']) && trim((string) $cfg['APP_URL']) !== '';
        if (!$tieneUrl) {
            // Compatibilidad: APP_URL puede vivir en el ya obligatorio
            // config/db.local.php (que es un simple `return [...]`).
            $archivoDb = __DIR__ . '/../config/db.local.php';
            if (is_file($archivoDb)) {
                $cargado = require $archivoDb;
                if (is_array($cargado) && isset($cargado['APP_URL']) && trim((string) $cargado['APP_URL']) !== '') {
                    $cfg['APP_URL'] = $cargado['APP_URL'];
                }
            }
        }

        return $cfg;
    }

    /**
     * APP_URL en crudo (cadena vacía si no está configurada).
     */
    function bee_url_configurada(): string
    {
        $entorno = getenv('APP_URL');
        if ($entorno !== false && trim((string) $entorno) !== '') {
            return trim((string) $entorno);
        }
        $cfg = bee_config_app();
        return isset($cfg['APP_URL']) ? trim((string) $cfg['APP_URL']) : '';
    }

    /**
     * ¿Se acepta X-Forwarded-Proto? Sólo si se declaró un proxy propio.
     */
    function bee_proxy_confiable(): bool
    {
        $entorno = strtolower(trim((string) (getenv('TRUST_PROXY') ?: '')));
        if (in_array($entorno, ['1', 'true', 'si', 'yes', 'on'], true)) {
            return true;
        }
        $cfg = bee_config_app();
        $valor = $cfg['TRUST_PROXY'] ?? false;
        if ($valor === true || $valor === 1) {
            return true;
        }
        return is_string($valor)
            && in_array(strtolower(trim($valor)), ['1', 'true', 'si', 'yes', 'on'], true);
    }

    /**
     * ¿El nombre de host es de bucle local?
     */
    function bee_host_local(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return false;
        }
        if (preg_match('/^\[([0-9a-f:]+)\](?::\d+)?$/i', $host, $m)) {
            return $m[1] === '::1';
        }
        if (preg_match('/^([^:]+):\d+$/', $host, $m)) {
            $host = $m[1];
        }
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * Host que declaró el cliente. Se usa SÓLO para comprobaciones de
     * coherencia (rechazar), nunca para construir una URL.
     */
    function bee_host_de_peticion(): string
    {
        return strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    }

    /**
     * ¿La petición llegó por HTTPS?
     *
     * X-Forwarded-Proto se ignora salvo que TRUST_PROXY esté activo.
     */
    function bee_peticion_es_https(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (bee_proxy_confiable()) {
            $proto = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0];
            if (strtolower(trim($proto)) === 'https') {
                return true;
            }
        }
        return false;
    }

    /**
     * Ruta base de la instalación ('' en la raíz del dominio,
     * '/BeeStation_Sena' en un subdirectorio local).
     */
    function bee_ruta_base(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script === '') {
            return '';
        }
        $dir = str_replace('\\', '/', dirname($script));
        $dir = (string) preg_replace('#/+#', '/', $dir);
        $dir = rtrim($dir, '/');
        return ($dir === '' || $dir === '.') ? '' : $dir;
    }

    /**
     * Valida una URL canónica y devuelve el origen normalizado.
     *
     * @return array{ok:bool,origen:?string,motivo:string}
     */
    function bee_validar_url_canonica(string $url): array
    {
        if (trim($url) === '') {
            return ['ok' => false, 'origen' => null, 'motivo' => 'sin_configurar'];
        }

        $partes = parse_url($url);
        if (!is_array($partes)) {
            return ['ok' => false, 'origen' => null, 'motivo' => 'app_url_invalida'];
        }

        $esquema = strtolower((string) ($partes['scheme'] ?? ''));
        if (!in_array($esquema, ['http', 'https'], true)) {
            return ['ok' => false, 'origen' => null, 'motivo' => 'app_url_invalida'];
        }
        if (!empty($partes['user']) || !empty($partes['pass'])) {
            return ['ok' => false, 'origen' => null, 'motivo' => 'app_url_invalida'];
        }
        if (isset($partes['query']) || isset($partes['fragment'])) {
            return ['ok' => false, 'origen' => null, 'motivo' => 'app_url_invalida'];
        }

        // APP_URL es un ORIGEN: no debe llevar ruta propia. La ruta base
        // la aporta bee_ruta_base() a partir del despliegue real.
        $ruta = (string) ($partes['path'] ?? '');
        if ($ruta !== '' && rtrim($ruta, '/') !== '') {
            return ['ok' => false, 'origen' => null, 'motivo' => 'app_url_invalida'];
        }

        $host = (string) ($partes['host'] ?? '');
        if ($host === '') {
            return ['ok' => false, 'origen' => null, 'motivo' => 'app_url_invalida'];
        }
        $hostValido = preg_match(
            '/^(?:[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?$/i',
            $host
        ) || preg_match('/^\[[0-9a-f:]+\]$/i', $host);
        if (!$hostValido) {
            return ['ok' => false, 'origen' => null, 'motivo' => 'app_url_invalida'];
        }

        $puerto = isset($partes['port']) ? (int) $partes['port'] : 0;
        $origen = $esquema . '://' . $host . ($puerto > 0 ? ':' . $puerto : '');

        // Una URL local configurada no debe emitirse desde un despliegue
        // remoto: sería publicar "localhost" en producción.
        if (bee_host_local($host) && !bee_host_local(bee_host_de_peticion())) {
            return ['ok' => false, 'origen' => null, 'motivo' => 'app_url_local_en_remoto'];
        }

        return ['ok' => true, 'origen' => $origen, 'motivo' => 'configurada'];
    }

    /**
     * Origen canónico efectivo.
     *
     * @return array{origen:?string,fuente:string,motivo:string}
     */
    function bee_origen_canonico(): array
    {
        $configurada = bee_url_configurada();
        $validacion = bee_validar_url_canonica($configurada);

        if ($validacion['ok']) {
            return [
                'origen' => $validacion['origen'],
                'fuente' => 'configurada',
                'motivo' => 'configurada',
            ];
        }

        if ($configurada !== '') {
            // Había configuración pero se rechazó: no se cae a la petición.
            return ['origen' => null, 'fuente' => 'configurada', 'motivo' => $validacion['motivo']];
        }

        // Sin APP_URL: sólo se deriva en desarrollo local.
        $host = bee_host_de_peticion();
        if (!bee_host_local($host)) {
            return ['origen' => null, 'fuente' => 'peticion', 'motivo' => 'sin_configurar'];
        }

        $esquema = bee_peticion_es_https() ? 'https' : 'http';
        $nombre = '';
        $puerto = '';

        if (preg_match('/^(\[[0-9a-f:]+\])(?::(\d+))?$/i', $host, $m)) {
            $nombre = $m[1];
            $puerto = $m[2] ?? '';
        } elseif (preg_match('/^([a-z0-9.\-]+)(?::(\d+))?$/i', $host, $m)) {
            $nombre = $m[1];
            $puerto = $m[2] ?? '';
        } else {
            return ['origen' => null, 'fuente' => 'peticion', 'motivo' => 'host_invalido'];
        }

        if ($puerto !== '') {
            $p = (int) $puerto;
            $esDefecto = ($esquema === 'http' && $p === 80) || ($esquema === 'https' && $p === 443);
            if ($p > 0 && !$esDefecto) {
                $nombre .= ':' . $p;
            }
        }

        return [
            'origen' => $esquema . '://' . $nombre,
            'fuente' => 'peticion',
            'motivo' => 'local_derivada',
        ];
    }

    /**
     * URL absoluta de un recurso interno, o null si no hay origen seguro.
     *
     * @param string $ruta Ruta relativa a la raíz de la instalación
     *                     (p. ej. 'api/ingest.php').
     */
    function bee_url_absoluta(string $ruta): ?string
    {
        $origen = bee_origen_canonico();
        if ($origen['origen'] === null) {
            return null;
        }
        return $origen['origen'] . bee_ruta_base() . '/' . ltrim($ruta, '/');
    }
}
