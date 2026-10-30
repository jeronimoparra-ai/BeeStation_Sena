<?php
/**
 * includes/password.php
 * ----------------------------------------------------------------
 * Política centralizada de hash y validación de contraseñas de BeeStation.
 *
 * Decisión de arquitectura (Compatibilidad unificada de runtimes):
 *   - Algoritmo único obligatorio: PASSWORD_BCRYPT.
 *   - Costo bcrypt: 12.
 *   - Longitud mínima: 12 caracteres Unicode (evaluada con mb_strlen).
 *   - Longitud máxima técnica: 72 bytes reales (evaluada con strlen).
 *   - Codificación obligatoria: UTF-8 válido (mb_check_encoding).
 *   - Sin truncamiento: Cualquier entrada > 72 bytes es rechazada de inmediato.
 *   - Sin trim(): Preserva espacios iniciales, internos y finales.
 *
 * Justificación de algoritmo único:
 *   En entornos heterogéneos (por ejemplo, PHP CLI 8.5 con Argon2id vs
 *   XAMPP Apache PHP 8.2 sin libargon compilada), la selección automática
 *   provocaba que cuentas creadas por CLI no pudieran autenticarse en Apache.
 *   Al fijar PASSWORD_BCRYPT con costo 12, se garantiza que cualquier hash
 *   generado por cualquier proceso de BeeStation sea 100% verificable en
 *   todos los entornos (CLI, Apache, PHP-FPM y Hostinger).
 *
 * Adopción futura de Argon2id:
 *   Argon2id se evaluará cuando TODOS los runtimes (CLI de desarrollo, servidor
 *   local, CLI de producción y Apache/PHP-FPM en Hostinger) dispongan
 *   demostradamente de libargon/Sodium, y se haya medido el consumo de memoria
 *   bajo concurrencia. Hasta entonces, Bcrypt costo 12 es el estándar único.
 * ----------------------------------------------------------------
 */
declare(strict_types=1);

/**
 * Devuelve la configuración estricta de la política de contraseñas.
 * Idéntica para todos los runtimes y entornos.
 *
 * @return array{
 *     algoritmo: string,
 *     algo_const: int|string,
 *     cost: int,
 *     min_caracteres: int,
 *     max_bytes: int,
 *     opciones: array{cost: int}
 * }
 */
function obtenerPoliticaPassword(): array {
    if (!defined('PASSWORD_BCRYPT')) {
        throw new RuntimeException('El entorno PHP no dispone del algoritmo PASSWORD_BCRYPT.');
    }

    return [
        'algoritmo'      => 'bcrypt',
        'algo_const'     => PASSWORD_BCRYPT,
        'cost'           => 12,
        'min_caracteres' => 12,
        'max_bytes'      => 72,
        'opciones'       => [
            'cost' => 12,
        ],
    ];
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

    // Longitud máxima técnica en bytes reales (strlen) para Bcrypt
    $numBytes = strlen($clave);
    if ($numBytes > $politica['max_bytes']) {
        return [
            'valida' => false,
            'error'  => sprintf(
                'La contraseña excede el límite máximo de %d bytes permitido por el algoritmo de seguridad (longitud recibida: %d bytes).',
                $politica['max_bytes'],
                $numBytes
            ),
        ];
    }

    return [
        'valida' => true,
        'error'  => null,
    ];
}

/**
 * Genera un hash Bcrypt costo 12 según la política centralizada.
 *
 * @param string $clave Contraseña en texto plano.
 * @return string Hash resultante ($2y$12$...).
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
