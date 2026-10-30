<?php
/**
 * includes/password.php
 * ----------------------------------------------------------------
 * Política centralizada de hash y validación de contraseñas de BeeStation.
 *
 * Arquitectura de seguridad:
 *   1. Detección en tiempo de ejecución:
 *      - Argon2id (PASSWORD_ARGON2ID): Si está compilado en PHP (password_algos()),
 *        se utiliza con parámetros acordes a OWASP:
 *        memory_cost = 65536 KiB (64 MiB), time_cost = 3, threads = 1.
 *        Límite: mínimo 12 caracteres, máximo 1024 bytes.
 *      - Bcrypt (PASSWORD_BCRYPT): Si Argon2id no está disponible en el entorno,
 *        se utiliza explícitamente PASSWORD_BCRYPT con costo 12.
 *        Límite: mínimo 12 caracteres, máximo estricto de 72 bytes (strlen).
 *        Rechaza cualquier entrada > 72 bytes sin truncamiento (previene colisiones
 *        y ambigüedades por truncamiento silencioso).
 *
 *   2. Validación rigurosa:
 *      - Longitud mínima evaluada en caracteres Unicode con mb_strlen().
 *      - Longitud técnica máxima evaluada en bytes reales con strlen().
 *      - Rechazo de codificaciones no válidas con mb_check_encoding().
 *      - No se aplica trim() ni se eliminan espacios internos.
 *      - Confirmación exacta de contraseñas.
 *
 *   3. Compatibilidad:
 *      - password_verify() resuelve de forma transparente cualquier hash
 *        existente ($2y$, $argon2id$, etc.), preservando cuentas previas
 *        como admin@beestation.io sin necesidad de migración ni restablecimiento.
 * ----------------------------------------------------------------
 */
declare(strict_types=1);

/**
 * Devuelve la configuración de la política de contraseñas según el entorno.
 *
 * @return array{
 *     algoritmo: string,
 *     algo_const: string|int,
 *     min_caracteres: int,
 *     max_bytes: int,
 *     opciones: array<string, int>
 * }
 */
function obtenerPoliticaPassword(): array {
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $algosDisponibles = function_exists('password_algos') ? password_algos() : [];
    $tieneArgon2id = defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, $algosDisponibles, true);

    if ($tieneArgon2id) {
        $config = [
            'algoritmo'      => 'argon2id',
            'algo_const'     => PASSWORD_ARGON2ID,
            'min_caracteres' => 12,
            'max_bytes'      => 1024,
            'opciones'       => [
                'memory_cost' => 65536,
                'time_cost'   => 3,
                'threads'     => 1,
            ],
        ];
    } else {
        $config = [
            'algoritmo'      => 'bcrypt',
            'algo_const'     => PASSWORD_BCRYPT,
            'min_caracteres' => 12,
            'max_bytes'      => 72,
            'opciones'       => [
                'cost' => 12,
            ],
        ];
    }

    return $config;
}

/**
 * Valida que una contraseña cumpla estrictamente con la política de seguridad.
 * No modifica ni aplica trim() a la contraseña.
 *
 * @param mixed $clave Contraseña en texto plano a validar.
 * @return array{valida: bool, error: ?string}
 */
function validarPasswordSegura(mixed $clave): array {
    if (!is_string($clave)) {
        return [
            'valida' => false,
            'error'  => 'La contraseña debe ser una cadena de texto.',
        ];
    }

    // Comprobar codificación UTF-8 válida
    if (!mb_check_encoding($clave, 'UTF-8')) {
        return [
            'valida' => false,
            'error'  => 'La contraseña contiene caracteres con codificación no válida.',
        ];
    }

    $politica = obtenerPoliticaPassword();

    // Longitud mínima en caracteres Unicode (mb_strlen)
    $numCaracteres = mb_strlen($clave, 'UTF-8');
    if ($numCaracteres < $politica['min_caracteres']) {
        return [
            'valida' => false,
            'error'  => sprintf('La contraseña debe tener al menos %d caracteres.', $politica['min_caracteres']),
        ];
    }

    // Longitud máxima técnica en bytes (strlen)
    $numBytes = strlen($clave);
    if ($numBytes > $politica['max_bytes']) {
        if ($politica['algoritmo'] === 'bcrypt') {
            return [
                'valida' => false,
                'error'  => sprintf('La contraseña excede el límite máximo de %d bytes permitido por el algoritmo de seguridad actual (longitud recibida: %d bytes).', $politica['max_bytes'], $numBytes),
            ];
        }
        return [
            'valida' => false,
            'error'  => sprintf('La contraseña supera el límite máximo permitido de %d bytes.', $politica['max_bytes']),
        ];
    }

    return [
        'valida' => true,
        'error'  => null,
    ];
}

/**
 * Genera un hash seguro para la contraseña según la política activa.
 *
 * @param string $clave Contraseña en texto plano.
 * @return string Hash resultante.
 * @throws InvalidArgumentException Si la contraseña no cumple la política.
 * @throws RuntimeException Si el motor PHP falla al calcular el hash.
 */
function hashearPasswordSegura(string $clave): string {
    $validacion = validarPasswordSegura($clave);
    if (!$validacion['valida']) {
        throw new InvalidArgumentException($validacion['error'] ?? 'Contraseña inválida.');
    }

    $politica = obtenerPoliticaPassword();
    $hash = password_hash($clave, $politica['algo_const'], $politica['opciones']);

    if (!is_string($hash) || $hash === '') {
        throw new RuntimeException('Fallo al generar el hash criptográfico de la contraseña.');
    }

    return $hash;
}
