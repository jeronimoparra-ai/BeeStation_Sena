<?php
/**
 * includes/dispositivo.php
 * ----------------------------------------------------------------
 * Identidad criptografica por dispositivo (ECDSA P-256 / NIST P-256).
 *
 * Sustituye al "token de vinculacion" compartido: cada nodo tiene su
 * propia clave y el servidor decide si puede hablar y con que colmena.
 *
 *   - El nodo genera su par de claves la primera vez que arranca.
 *   - La clave PRIVADA nunca sale del ESP32 (vive en su NVS).
 *   - La clave PUBLICA se registra en el alta y verifica sus firmas.
 *   - El servidor autoriza y revoca nodos de forma individual.
 *
 * CABECERAS DE UNA PETICION FIRMADA CON ECDSA
 *   X-BeeStation-Dispositivo   identificador del nodo (32 hex)
 *   X-BeeStation-Mac           MAC del ESP32 (AA:BB:CC:DD:EE:FF)
 *   X-BeeStation-Timestamp     epoch unix sincronizado del nodo
 *   X-BeeStation-Nonce         128 bits aleatorios, hexadecimal
 *   X-BeeStation-Firma         firma ECDSA ASN.1 DER en hexadecimal
 *
 * La cabecera "X-BeeStation-Signature" sigue reservada al esquema HMAC
 * heredado, de modo que ambos pueden convivir durante la transicion.
 *
 * CADENA CANONICA FIRMADA (identica byte a byte en el firmware)
 *   BEE2 \n METODO \n RUTA \n TIMESTAMP \n MAC \n NONCE \n ID_DISP \n sha256_hex(cuerpo)
 *
 *   - METODO en mayusculas
 *   - RUTA  = ruta de la peticion SIN query string
 *   - los hexadecimales SIEMPRE en minusculas (convencion de PHP)
 *
 * PRUEBA DE POSESION (PoP) en el alta
 *   BEE2-POP \n ID_DISP \n MAC \n TIMESTAMP \n NONCE \n sha256_hex(CLAVE_PUBLICA_PEM)
 *
 * El nodo firma esa cadena con su clave privada y el servidor la verifica
 * con la clave publica que el nodo acaba de enviar. Eso demuestra que el
 * nodo posee la clave privada sin necesidad de un reto previo del servidor.
 * ----------------------------------------------------------------
 */

if (!defined('BEESTATION_DISPOSITIVO_CONTEXT')) {
    define('BEESTATION_DISPOSITIVO_CONTEXT', true);
}

const DISPOSITIVO_VENTANA_SEGUNDOS   = 300;
const DISPOSITIVO_TIMESTAMP_MINIMO   = 1577836800;
const DISPOSITIVO_ESTADO_PENDIENTE   = 'pendiente';
const DISPOSITIVO_ESTADO_AUTORIZADO  = 'autorizado';
const DISPOSITIVO_ESTADO_REVOCADO    = 'revocado';
const DISPOSITIVO_CODIGO_MAX_INTENTOS = 5;
const DISPOSITIVO_CODIGO_TTL_MINUTOS = 30;

/**
 * Comprueba que el identificador de dispositivo tiene la forma correcta.
 */
function dispositivoIdValido(string $id): bool {
    return (bool) preg_match('/^[0-9a-f]{32}$/', $id);
}

/**
 * Huella de la clave publica: SHA-256 en hexadecimal en minúsculas de los
 * bytes EXACTOS del PEM. El nodo calcula la misma huella sobre el mismo
 * PEM, de modo que ambos coinciden sin depender del formato interno.
 */
function dispositivoHuella(string $pem): string {
    return hash('sha256', $pem);
}

/**
 * Valida el PEM de clave publica (SPKI) y devuelve la clave de OpenSSL.
 * Devuelve null si el PEM no es una clave publica EC valida.
 */
function dispositivoClavePublica(string $pem): ?OpenSSLAsymmetricKey {
    if ($pem === '' || strpos($pem, '-----BEGIN PUBLIC KEY-----') === false) {
        return null;
    }
    // Límite de tamaño: una clave P-256 SPKI en PEM ocupa ~180 bytes.
    if (strlen($pem) > 1024) {
        return null;
    }
    $clave = @openssl_pkey_get_public($pem);
    if ($clave === false) {
        return null;
    }
    $detalles = openssl_pkey_get_details($clave);
    if (!is_array($detalles) || ($detalles['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
        return null;
    }
    // Solo se admite la curva P-256 (prime256v1 / secp256r1).
    $curva = strtolower((string) ($detalles['ec']['curve_name'] ?? ''));
    if ($curva !== 'prime256v1' && $curva !== 'secp256r1') {
        return null;
    }
    return $clave;
}

/**
 * Construye la cadena canonica firmada con ECDSA.
 */
function dispositivoCadenaCanonica(string $metodo, string $ruta, string $timestamp,
                                   string $mac, string $nonce, string $idDispositivo,
                                   string $cuerpo): string {
    return "BEE2\n"
         . strtoupper($metodo) . "\n"
         . $ruta . "\n"
         . $timestamp . "\n"
         . $mac . "\n"
         . strtolower($nonce) . "\n"
         . strtolower($idDispositivo) . "\n"
         . hash('sha256', $cuerpo);
}

/**
 * Construye la cadena canonica de la prueba de posesion.
 */
function dispositivoCadenaPop(string $idDispositivo, string $mac, string $timestamp,
                              string $nonce, string $huella): string {
    return "BEE2-POP\n"
         . strtolower($idDispositivo) . "\n"
         . $mac . "\n"
         . $timestamp . "\n"
         . strtolower($nonce) . "\n"
         . $huella;
}

/**
 * Lee las cabeceras de una peticion firmada con ECDSA.
 *
 * @return array{0: ?string, 1: ?string, 2: string, 3: string, 4: string}
 *         [idDispositivo, mac, timestamp, nonce, firmaHex]
 */
function dispositivoCabeceras(): array {
    $id  = strtolower(trim((string) ($_SERVER['HTTP_X_BEESTATION_DISPOSITIVO'] ?? '')));
    $mac = firmaMacNormalizada($_SERVER['HTTP_X_BEESTATION_MAC'] ?? null);
    $ts  = trim((string) ($_SERVER['HTTP_X_BEESTATION_TIMESTAMP'] ?? ''));
    $nonce = strtolower(trim((string) ($_SERVER['HTTP_X_BEESTATION_NONCE'] ?? '')));
    $firma = strtolower(trim((string) ($_SERVER['HTTP_X_BEESTATION_FIRMA'] ?? '')));
    return [$id !== '' ? $id : null, $mac, $ts, $nonce, $firma];
}

/**
 * Registra un nonce de dispositivo. La clave primaria (identificador, nonce)
 * hace atomica la comprobacion anti-replay.
 *
 * @return bool false si el nonce ya se habia usado
 */
function dispositivoRegistrarNonce(string $idDispositivo, string $nonce): bool {
    $pdo = getPDO();
    try {
        $pdo->exec("DELETE FROM nonce_dispositivo WHERE creado_en < (NOW() - INTERVAL 10 MINUTE)");
        $pdo->prepare('INSERT INTO nonce_dispositivo (identificador, nonce, creado_en) VALUES (?, ?, NOW())')
            ->execute([$idDispositivo, $nonce]);
        return true;
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) === 1062) {
            return false;
        }
        throw $e;
    }
}

/**
 * Verifica la firma ECDSA de la peticion actual.
 *
 * @param string     $metodo          Metodo HTTP
 * @param string     $ruta            Ruta sin query string
 * @param string     $cuerpo          Cuerpo crudo
 * @param bool       $permitirPendiente  Si true acepta nodos aun no autorizados
 *                                       (solo para consultar su estado).
 * @param array|null $disp            Salida: fila del dispositivo autenticado
 *
 * @return bool true si la peticion es genuina
 */
function dispositivoVerificarFirma(string $metodo, string $ruta, string $cuerpo,
                                  bool $permitirPendiente = false,
                                  ?array &$disp = null): bool {
    $disp = null;
    $GLOBALS['dispositivo_motivo_rechazo'] = '';

    [$id, $mac, $ts, $nonce, $firma] = dispositivoCabeceras();
    if ($id === null || $mac === null) {
        $GLOBALS['dispositivo_motivo_rechazo'] = 'cabeceras_incompletas';
        return false;
    }
    if (!dispositivoIdValido($id)) {
        $GLOBALS['dispositivo_motivo_rechazo'] = 'dispositivo_invalido';
        return false;
    }
    if (!firmaTimestampValido($ts)) {
        $GLOBALS['dispositivo_motivo_rechazo'] = 'timestamp_expirado';
        return false;
    }
    if (!firmaNonceValido($nonce)) {
        $GLOBALS['dispositivo_motivo_rechazo'] = 'nonce_invalido';
        return false;
    }
    if (!preg_match('/^[0-9a-f]{100,200}$/', $firma)) {
        $GLOBALS['dispositivo_motivo_rechazo'] = 'firma_invalida';
        return false;
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT d.*, c.nombre AS nombre_colmena
        FROM dispositivo d
        LEFT JOIN colmena c ON c.id_colmena = d.id_colmena
        WHERE d.identificador = ?
    ");
    $stmt->execute([$id]);
    $fila = $stmt->fetch();
    if (!$fila) {
        $GLOBALS['dispositivo_motivo_rechazo'] = 'dispositivo_desconocido';
        return false;
    }

    // La MAC declarada debe coincidir con la registrada en el alta.
    if (strtoupper((string) $fila['mac']) !== $mac) {
        $GLOBALS['dispositivo_motivo_rechazo'] = 'mac_no_corresponde';
        return false;
    }

    if ($fila['estado'] === DISPOSITIVO_ESTADO_REVOCADO) {
        $GLOBALS['dispositivo_motivo_rechazo'] = 'dispositivo_revocado';
        return false;
    }
    if ($fila['estado'] === DISPOSITIVO_ESTADO_PENDIENTE && !$permitirPendiente) {
        $GLOBALS['dispositivo_motivo_rechazo'] = 'dispositivo_pendiente';
        return false;
    }
    if ($fila['estado'] === DISPOSITIVO_ESTADO_AUTORIZADO && $fila['id_colmena'] === null) {
        $GLOBALS['dispositivo_motivo_rechazo'] = 'dispositivo_sin_colmena';
        return false;
    }

    $clave = dispositivoClavePublica((string) $fila['clave_publica_pem']);
    if ($clave === null) {
        $GLOBALS['dispositivo_motivo_rechazo'] = 'clave_publica_invalida';
        return false;
    }

    $canonica = dispositivoCadenaCanonica($metodo, $ruta, $ts, $mac, $nonce, $id, $cuerpo);
    $der = @hex2bin($firma);
    if ($der === false || $der === '') {
        $GLOBALS['dispositivo_motivo_rechazo'] = 'firma_invalida';
        return false;
    }

    $verificado = @openssl_verify($canonica, $der, $clave, OPENSSL_ALGO_SHA256);
    if ($verificado !== 1) {
        $GLOBALS['dispositivo_motivo_rechazo'] = 'firma_no_valida';
        return false;
    }

    // Anti-replay: el nonce solo puede usarse una vez por dispositivo.
    if (!dispositivoRegistrarNonce($id, $nonce)) {
        $GLOBALS['dispositivo_motivo_rechazo'] = 'nonce_reutilizado';
        return false;
    }

    $disp = $fila;
    return true;
}

/**
 * Explica al nodo por que se rechazo su peticion.
 */
function dispositivoMotivoRechazo(): string {
    $motivo = $GLOBALS['dispositivo_motivo_rechazo'] ?? '';
    $mensajes = [
        'cabeceras_incompletas' => 'Faltan las cabeceras de autenticación del dispositivo.',
        'dispositivo_invalido'  => 'El identificador de dispositivo no es válido.',
        'dispositivo_desconocido' => 'Este nodo no está registrado en el servidor.',
        'dispositivo_pendiente' => 'El nodo todavía no ha sido autorizado por el usuario.',
        'dispositivo_revocado'  => 'El nodo ha sido revocado.',
        'dispositivo_sin_colmena' => 'El nodo no tiene ninguna colmena asignada.',
        'mac_no_corresponde'    => 'La MAC declarada no corresponde a este dispositivo.',
        'clave_publica_invalida' => 'La clave pública registrada no es válida.',
        'firma_no_valida'       => 'La firma del nodo no valida.',
        'firma_invalida'        => 'La firma del nodo no tiene un formato válido.',
        'nonce_reutilizado'     => 'Solicitud repetida. Genera una prueba nueva para reintentar.',
        'nonce_invalido'        => 'Solicitud no válida.',
        'timestamp_expirado'    => 'La marca de tiempo del nodo está fuera de la ventana permitida. Sincroniza la hora y reintenta.',
    ];
    return $mensajes[$motivo] ?? 'Petición no autorizada.';
}

/**
 * Registra (o re-registra) un nodo en el alta.
 *
 * @return array{ok: bool, error?: string, dispositivo?: array}
 */
function dispositivoRegistrarAlta(string $idDispositivo, string $mac, string $pem,
                                  string $codigo, string $nombre, string $timestamp,
                                  string $nonce, string $firmaHex): array {
    if (!dispositivoIdValido($idDispositivo)) {
        return ['ok' => false, 'error' => 'identificador_invalido'];
    }
    if (!firmaTimestampValido($timestamp)) {
        return ['ok' => false, 'error' => 'timestamp_expirado'];
    }
    if (!firmaNonceValido($nonce)) {
        return ['ok' => false, 'error' => 'nonce_invalido'];
    }
    if (!preg_match('/^[0-9a-f]{100,200}$/', $firmaHex)) {
        return ['ok' => false, 'error' => 'firma_invalida'];
    }
    if (!preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $codigo)) {
        return ['ok' => false, 'error' => 'codigo_invalido'];
    }

    $clave = dispositivoClavePublica($pem);
    if ($clave === null) {
        return ['ok' => false, 'error' => 'clave_publica_invalida'];
    }

    $huella = dispositivoHuella($pem);

    // Prueba de posesion: el nodo firma con su clave privada una cadena que
    // incluye la huella de su clave publica. Si valida, el nodo posee la
    // clave privada correspondiente a la clave publica enviada.
    $canonicaPop = dispositivoCadenaPop($idDispositivo, $mac, $timestamp, $nonce, $huella);
    $der = @hex2bin($firmaHex);
    if ($der === false || $der === '') {
        return ['ok' => false, 'error' => 'firma_invalida'];
    }
    if (@openssl_verify($canonicaPop, $der, $clave, OPENSSL_ALGO_SHA256) !== 1) {
        return ['ok' => false, 'error' => 'prueba_posesion_invalida'];
    }

    $pdo = getPDO();

    // Anti-replay del alta: el nonce no puede reutilizarse.
    if (!dispositivoRegistrarNonce($idDispositivo, $nonce)) {
        return ['ok' => false, 'error' => 'nonce_reutilizado'];
    }

    $stmt = $pdo->prepare("SELECT * FROM dispositivo WHERE identificador = ?");
    $stmt->execute([$idDispositivo]);
    $existente = $stmt->fetch();

    $codigoHash = hash('sha256', $codigo);
    $nombre = mb_substr(trim($nombre), 0, 120);

    if ($existente) {
        // Si la clave publica cambio, el nodo es OTRO dispositivo con el
        // mismo identificador: hay que volver a autorizarlo.
        $mismaClave = hash_equals((string) $existente['huella_clave'], $huella);
        if ($mismaClave) {
            $pdo->prepare("
                UPDATE dispositivo
                SET mac = ?, codigo_hash = ?, codigo_expira = DATE_ADD(NOW(), INTERVAL ? MINUTE),
                    codigo_intentos = 0, codigo_usado = 0, ultimo_contacto = NOW(),
                    nombre = COALESCE(NULLIF(?, ''), nombre)
                WHERE id_dispositivo = ?
            ")->execute([$mac, $codigoHash, DISPOSITIVO_CODIGO_TTL_MINUTOS, $nombre, $existente['id_dispositivo']]);
        } else {
            $pdo->prepare("
                UPDATE dispositivo
                SET mac = ?, clave_publica_pem = ?, huella_clave = ?,
                    estado = ?, id_colmena = NULL, id_usuario = NULL,
                    codigo_hash = ?, codigo_expira = DATE_ADD(NOW(), INTERVAL ? MINUTE),
                    codigo_intentos = 0, codigo_usado = 0,
                    aprobado_en = NULL, revocado_en = NULL, motivo_revocacion = NULL,
                    ultimo_contacto = NOW(), nombre = COALESCE(NULLIF(?, ''), nombre)
                WHERE id_dispositivo = ?
            ")->execute([
                $mac, $pem, $huella, DISPOSITIVO_ESTADO_PENDIENTE,
                $codigoHash, DISPOSITIVO_CODIGO_TTL_MINUTOS, $nombre,
                $existente['id_dispositivo'],
            ]);
        }
    } else {
        $pdo->prepare("
            INSERT INTO dispositivo
                (identificador, mac, nombre, clave_publica_pem, huella_clave, estado,
                 codigo_hash, codigo_expira, codigo_intentos, codigo_usado, creado_en)
            VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), 0, 0, NOW())
        ")->execute([
            $idDispositivo, $mac, $nombre !== '' ? $nombre : null, $pem, $huella,
            DISPOSITIVO_ESTADO_PENDIENTE, $codigoHash, DISPOSITIVO_CODIGO_TTL_MINUTOS,
        ]);
    }

    $stmt = $pdo->prepare("SELECT * FROM dispositivo WHERE identificador = ?");
    $stmt->execute([$idDispositivo]);
    $fila = $stmt->fetch();

    return ['ok' => true, 'dispositivo' => $fila];
}

/**
 * Estado actual de un nodo (para la consulta periodica del firmware).
 */
function dispositivoEstado(string $idDispositivo): ?array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT d.*, c.nombre AS nombre_colmena
        FROM dispositivo d
        LEFT JOIN colmena c ON c.id_colmena = d.id_colmena
        WHERE d.identificador = ?
    ");
    $stmt->execute([$idDispositivo]);
    return $stmt->fetch() ?: null;
}

/**
 * Busca un nodo pendiente a partir del codigo corto que muestra el
 * dispositivo. Solo se guarda el hash del codigo.
 */
function dispositivoBuscarPorCodigo(string $codigo): ?array {
    $codigo = strtoupper(trim($codigo));
    if (!preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $codigo)) {
        return null;
    }
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT d.*, c.nombre AS nombre_colmena
        FROM dispositivo d
        LEFT JOIN colmena c ON c.id_colmena = d.id_colmena
        WHERE d.codigo_hash = ?
          AND d.estado = ?
          AND d.codigo_usado = 0
          AND d.codigo_expira >= NOW()
          AND d.codigo_intentos < d.codigo_max_intentos
    ");
    $stmt->execute([hash('sha256', $codigo), DISPOSITIVO_ESTADO_PENDIENTE]);
    return $stmt->fetch() ?: null;
}

/**
 * Registra un intento fallido de introducir el codigo (limite de intentos).
 *
 * @return int numero de intentos acumulados
 */
function dispositivoRegistrarIntentoCodigo(int $idDispositivo): int {
    $pdo = getPDO();
    $pdo->prepare('UPDATE dispositivo SET codigo_intentos = codigo_intentos + 1 WHERE id_dispositivo = ?')
        ->execute([$idDispositivo]);
    $stmt = $pdo->prepare('SELECT codigo_intentos FROM dispositivo WHERE id_dispositivo = ?');
    $stmt->execute([$idDispositivo]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * Autoriza un nodo y lo asocia a una colmena.
 *
 * @param int $idDispositivo  Nodo a autorizar
 * @param int $idColmena      Colmena destino (debe pertenecer al usuario)
 * @param int $idUsuario      Usuario que autoriza
 */
function dispositivoAprobar(int $idDispositivo, int $idColmena, int $idUsuario): array {
    $pdo = getPDO();

    // La colmena debe pertenecer al usuario que autoriza.
    $stmt = $pdo->prepare("
        SELECT c.id_colmena
        FROM colmena c
        JOIN apiario a ON a.id_apiario = c.id_apiario
        WHERE c.id_colmena = ? AND a.id_usuario = ?
    ");
    $stmt->execute([$idColmena, $idUsuario]);
    if (!$stmt->fetch()) {
        return ['ok' => false, 'error' => 'colmena_no_pertenece'];
    }

    $stmt = $pdo->prepare("SELECT estado FROM dispositivo WHERE id_dispositivo = ?");
    $stmt->execute([$idDispositivo]);
    if (!$stmt->fetch()) {
        return ['ok' => false, 'error' => 'dispositivo_inexistente'];
    }

    $pdo->prepare("
        UPDATE dispositivo
        SET estado = ?, id_colmena = ?, id_usuario = ?, aprobado_en = NOW(),
            codigo_usado = 1, revocado_en = NULL, motivo_revocacion = NULL
        WHERE id_dispositivo = ?
    ")->execute([DISPOSITIVO_ESTADO_AUTORIZADO, $idColmena, $idUsuario, $idDispositivo]);

    return ['ok' => true];
}

/**
 * Revoca un nodo de forma individual. El nodo deja de poder enviar datos
 * en el siguiente instante, sin reflashear ningun otro dispositivo.
 */
function dispositivoRevocar(int $idDispositivo, int $idUsuario, string $motivo = ''): array {
    $pdo = getPDO();

    // Solo se puede revocar un nodo de una colmena propia.
    $stmt = $pdo->prepare("
        SELECT d.id_dispositivo
        FROM dispositivo d
        LEFT JOIN colmena c ON c.id_colmena = d.id_colmena
        LEFT JOIN apiario a ON a.id_apiario = c.id_apiario
        WHERE d.id_dispositivo = ? AND (a.id_usuario = ? OR d.id_usuario = ?)
    ");
    $stmt->execute([$idDispositivo, $idUsuario, $idUsuario]);
    if (!$stmt->fetch()) {
        return ['ok' => false, 'error' => 'dispositivo_no_pertenece'];
    }

    $pdo->prepare("
        UPDATE dispositivo
        SET estado = ?, revocado_en = NOW(), motivo_revocacion = ?, codigo_usado = 1
        WHERE id_dispositivo = ?
    ")->execute([DISPOSITIVO_ESTADO_REVOCADO, mb_substr(trim($motivo), 0, 255), $idDispositivo]);

    return ['ok' => true];
}

/**
 * Registra el ultimo contacto de un nodo.
 */
function dispositivoRegistrarContacto(int $idDispositivo): void {
    try {
        $pdo = getPDO();
        $pdo->prepare('UPDATE dispositivo SET ultimo_contacto = NOW() WHERE id_dispositivo = ?')
            ->execute([$idDispositivo]);
    } catch (Throwable $e) {
        // Informativo: nunca debe tumbar la ingesta.
    }
}

/**
 * Control de frecuencia del alta.
 *
 * Un atacante podria inundar el endpoint de alta con pares de claves
 * generados por el mismo, de modo que hay que limitar los intentos por
 * MAC y por direccion IP de origen.
 *
 * @return bool true si se ha superado el limite (hay que rechazar)
 */
function dispositivoExcesoDeIntentos(string $mac, ?string $ip): bool {
    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM intento_vinculacion
            WHERE resultado = 'alta_fallida'
              AND fecha_hora >= (NOW() - INTERVAL 15 MINUTE)
              AND token_recibido = ?
        ");
        $stmt->execute([$mac]);
        if ((int) ($stmt->fetchColumn() ?: 0) >= 10) {
            return true;
        }

        if ($ip !== null && $ip !== '') {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM intento_vinculacion
                WHERE resultado = 'alta_fallida'
                  AND fecha_hora >= (NOW() - INTERVAL 15 MINUTE)
                  AND ip_origen = ?
            ");
            $stmt->execute([$ip]);
            if ((int) ($stmt->fetchColumn() ?: 0) >= 30) {
                return true;
            }
        }
    } catch (Throwable $e) {
        // Si la comprobacion falla, no se bloquea el alta: la Prueba de
        // posesion ya aporta la proteccion criptografica real.
    }
    return false;
}
