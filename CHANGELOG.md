# Changelog

## 2026-10-21 — Fase 2: autenticación sin token permanente

### Added
- Identidad **ECDSA P-256** por nodo, generada en el propio ESP32: la clave privada nunca sale del dispositivo (`beestation_node/identidad_ecdsa.h`).
- Alta automática con prueba de posesión (`api/enroll.php`) y consulta de estado firmada (`api/enroll_estado.php`).
- Autorización y revocación individual de nodos desde `dispositivos.php`, con código de alta de un solo uso (hash, caducidad 30 min, 5 intentos).
- `lectura.id_medicion` (128 bits) y acuse **por lectura** (`resultados[]`) en la ingesta.
- Pruebas: flujo E2E del servidor (49), interoperabilidad real Mbed TLS ↔ OpenSSL (18) y cadenas canónicas firmware ↔ servidor.
- `docs/reconciliacion-fase2.md` con decisiones, mediciones y puesta en marcha.

### Changed
- El portal cautivo ya no pide token: muestra el identificador y el código de alta del nodo.
- La cola de la microSD usa CSV de 9 columnas (`Id_medicion`) y avanza también cuando el servidor atendió todas las lecturas y alguna fue rechazada de forma definitiva.
- El nodo no envía telemetría hasta que un usuario lo autoriza; mientras tanto sigue midiendo y guardando en la microSD.

### Fixed
- El SHA-256 de la cadena HMAC usaba hexadecimal en **mayúsculas** mientras el servidor usaba minúsculas: esas firmas nunca validaron. Se unificó en minúsculas.
- `mbedtls_pk_parse_key` recibe ahora `strlen(pem) + 1`.
- La opción `header` del wrapper `http://` de PHP recibe una lista de líneas, no un array asociativo.

### Security
- `database/beestation_sena.sql` deja de versionarse: contenía hashes de contraseñas, tokens de vinculación e intentos con tokens en claro. Rotar credenciales históricas.
- Firmware compilado con `PartitionScheme=min_spiffs` (1,9 MB de APP): requiere reflashear la tabla de particiones por USB una vez.

### Migration
- Aplicar `database/migrations/005_identidad_dispositivo.sql` y `006_identificador_medicion.sql` antes de desplegar el backend nuevo.

## 2026-10-21

### Security
- Se requiere una marca de tiempo sincronizada y un nonce de un solo uso para las solicitudes firmadas; el nonce y la MAC forman parte de la firma canónica.
- Se endurecen las cookies de sesión, se rota el ID al iniciar sesión y se añade CSRF al login y al registro de colmenas.
- El alta de colmenas exige autenticación y comprueba la propiedad del apiario.
- La conexión a base de datos deja de usar `root` como valor por defecto y rechaza configuración débil en producción.

### Fixed
- La cola SD ya no avanza por un HTTP 200 parcial ni salta filas CSV sin fecha o malformadas.
- Se eliminaron impresiones visibles del token heredado desde el firmware.
- Se corrigió el layout anidado de `dispositivos.php`.

### Migration
- Aplicar `database/migrations/004_nonce_antireplay.sql` antes de habilitar el backend actualizado. Coordinar la actualización con el firmware; las versiones anteriores no envían nonce.

### Database
- El esquema limpio ya no elimina una base existente ni inserta la cuenta de demostración con credenciales publicadas.
- La migración 001 lista sensores duplicados para reconciliación y preserva sus relaciones existentes.
- Se añadieron al esquema limpio las variables presión/luminosidad, campos que usa la instalación actual, idempotencia `seq`, trazabilidad de intentos/lotes y nonces.
- Se añadió un aprovisionador CLI para crear la primera cuenta sin credenciales predefinidas.
