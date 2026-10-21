# Auditoría técnica y correcciones BeeStation

Fecha: 2026-10-21

## Alcance comprobado

Se inspeccionaron autenticación, ingesta, firmas, esquema limpio, migraciones, alta de colmenas, selector, páginas de sesión y las rutas críticas de respaldo/reenvío del firmware. No se ejecutó SQL ni se leyó `config/db.local.php`. La carpeta de trabajo no incluye `.git`, por lo que no fue posible verificar rama/remoto/historial ni preservar un diff Git.

## Hallazgos y cambios aplicados

| Área | Evidencia previa | Cambio |
|---|---|---|
| Anti-replay | `includes/firma.php` aceptaba `timestamp=0` y no registraba nonces | La firma requiere epoch desde 2020, dentro de 300 s, nonce hexadecimal de 128 bits y MAC incluida en el mensaje canónico; inserción única por MAC/nonce |
| MicroSD | El firmware avanzaba tras cualquier HTTP 200; omitía epoch cero y filas CSV incompletas | Solo avanza ante `ok_total:true`; detiene la cola delante de una fila sin hora o malformada; reintentos requieren hora actual y nonce nuevos |
| Credenciales en firmware | El sketch imprimía el token completo en el portal/diagnóstico y fragmentos en serie | Se retiraron las impresiones del token y su reflejo en la página posterior al guardado |
| Sesiones | `includes/auth.php` abría sesiones sin parámetros y `login.php` no regeneraba ID | Cookies HttpOnly, SameSite=Lax, Secure bajo HTTPS; modo estricto; rotación de ID tras login; cierre que elimina la cookie |
| CSRF/propiedad | Alta de colmena asumía usuario 1 y no verificaba propiedad del apiario | Requiere usuario autenticado, token CSRF y comprueba que el apiario pertenece al usuario |
| Errores | Alta de colmena imprimía excepciones de DB en UI | Mensaje público genérico y detalle mínimo solo en log |
| Vista de dispositivos | `header.php` ya abría layout/sidebar/main y la página volvía a anidarlos | Se eliminó el wrapper duplicado; el footer cierra el layout compartido |
| Configuración DB | Sin configuración caía silenciosamente a root/contraseña vacía | Requiere usuario configurado y rechaza root o contraseña vacía en `APP_ENV=production` |

## Contrato de solicitud firmado actual

Cabeceras requeridas: `X-BeeStation-Mac`, `X-BeeStation-Timestamp`, `X-BeeStation-Nonce` (16 bytes aleatorios como 32 hex) y `X-BeeStation-Signature` (HMAC-SHA256 hexadecimal). La cadena canónica ahora es:

```text
MÉTODO\nRUTA_SIN_QUERY\nEPOCH\nMAC\nNONCE\nSHA256_HEX_DEL_CUERPO
```

El nonce se registra en `nonce_peticion` bajo una clave única, tras validar la firma. Se purgan entradas con más de diez minutos; la ventana de firma es de cinco minutos. El servidor rechaza timestamps cero y fuera de ventana. En reintentos, el contenido histórico puede mantener su fecha de medición, pero la firma de transporte usa hora actual y nonce nuevo.

## Migración y despliegue de esta corrección

1. Respaldar la base de datos fuera de este repositorio.
2. Aplicar `database/migrations/004_nonce_antireplay.sql` en una base objetivo revisada.
3. Publicar de forma coordinada el backend y el firmware actualizado. Los firmwares anteriores no envían nonce y no podrán autenticarse con este contrato; no se debe actualizar únicamente el servidor si hay nodos en campo que deban seguir transmitiendo.
4. Compilar y validar el sketch con el modelo exacto de placa, luego probar firma, hora sincronizada, reintento y cola SD en una base aislada y hardware real.

El archivo de migración define solo una tabla nueva y un índice primario; no altera lecturas ni datos anteriores. No se ejecutó en XAMPP.

## Riesgos y trabajo pendiente

Esta entrega no reemplaza aún el secreto HMAC por identidad de clave pública por nodo. `includes/firma.php` todavía compara con `colmena.token_vinculacion`, y el portal continúa aceptando esa credencial para mantener compatibilidad hasta implementar un flujo de enrolamiento pendiente, aprobación autenticada por usuario, prueba de posesión, revocación individual y ruta de transición. Por tanto, no se cumple aún el criterio de dejar de copiar credenciales permanentes.

También quedan pendientes: acuse por identificador estable de cada medición (el backend sigue respondiendo con estado parcial y nombres de tipos); colisiones de `seq` derivado del epoch tras reinicio o varias muestras por segundo; conservación de mediciones CSV sin hora con un formato que permita asignarles tiempo fiable; límite de intentos/rate limit; HTTPS con CA/certificado validado; auditoría de todos los POST web; coherencia completa de sensores/unidades/fórmulas; esquemas limpios y migraciones sin supuestos; saneamiento del volcado SQL; pruebas de integración MariaDB aislada y pruebas de navegador/hardware.

Hallazgos SQL que no se ejecutaron: `database/schema.sql` ya no borra la base ni incluye credenciales demo, y la migración 001 ya no elimina sensores duplicados. El esquema limpio todavía no tiene todos los campos que usa el backend. `database/beestation_sena.sql` conserva hashes de contraseña y registros de usuario/colmena y debe sanearse; cualquier secreto activo debe rotarse. No se consultó ni alteró la instancia local.

## Comprobaciones ejecutadas

- `php -l` sobre 27 archivos PHP: correcto.
- `php tests/php/firma_test.php`: correcto; cubre hora válida, límites/expiración, rechazo de cero y hora antigua, nonce válido/inválido y canonicalización.
- `php tests/php/ecdsa_openssl_test.php`: correcto; crea P-256, firma/verifica SHA-256 y rechaza mensaje alterado. No es una prueba cruzada con el firmware.
- La primera ejecución ECDSA falló porque PHP apuntaba a un `openssl.cnf` inexistente. Con `OPENSSL_CONF=C:\xampp\php\extras\openssl\openssl.cnf` pasó; configura la ruta real en el entorno servidor.
- No se ejecutaron migraciones ni pruebas DB para evitar tocar la base local.
- `arduino-cli compile --fqbn esp32:esp32:esp32 beestation_node`: compilación correcta del firmware con nonce/cola conservadora; 98% de flash y 16% de RAM.
- No hubo ESP32 conectado; las pruebas de campo no se ejecutaron.
- No se ejecutaron pruebas de navegador ni se comprobó HTTPS real.

## Viabilidad de ECDSA P-256 para la siguiente etapa

El core instalado es Arduino ESP32 3.3.12 y sus headers locales identifican Mbed TLS 3.6.6, con `MBEDTLS_ECDSA_C` y `MBEDTLS_ECP_DP_SECP256R1_ENABLED` habilitados. PHP 8.2 dispone de OpenSSL y SHA-256. ECDSA P-256 con SHA-256 es un candidato compatible para identidad por dispositivo. Antes de integrarlo se requiere una prueba cruzada real entre `mbedtls_ecdsa_*` en firmware y `openssl_verify` en PHP, y medir el binario objetivo: el sketch actual ocupa 98% de flash, con poco margen para añadir código.

El esquema limpio ya no inserta usuarios ni credenciales; `tools/crear-usuario-inicial.php` permite crear la primera cuenta desde CLI usando variables de entorno y `password_hash()`. No se ejecutó con una base de datos real.
