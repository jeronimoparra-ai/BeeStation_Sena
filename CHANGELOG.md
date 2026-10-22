# Changelog

## 2026-10-21 — Fase 0: verdad y limpieza del panel

### Changed
- Dashboard reescrito alrededor del estado del nodo y de cuatro métricas reales
  (temperatura, humedad, peso y actividad acústica). Se retiraron IBB, EV, ΔT y
  H_miel de la primera pantalla y del informe.
- Frescura explícita: `fresca`, `edad_min`, “Dato antiguo” y “Nodo sin reportar”.
  Una lectura de hace días nunca se pinta en verde. El umbral es de 5 minutos,
  coherente con el envío cada 30 segundos y con el estado “en línea”.
- Serie de temperatura agregada cada 15 minutos; hasta ~96 puntos en 24 h.
- Acústica renombrada a **Actividad acústica (experimental)**. Se eliminaron
  “Pre-Enjambrazón”, “Canto de Reinas” y la alerta automática de enjambrazón.
- Sensores con unidad, `estado_real` y distinción entre “sin datos recientes” y
  “no instalado”.
- Peso: la página advierte que el HX711 no tiene calibración de campo validada;
  se muestra como tendencia, no como flujo de néctar confirmado.
- Menú sin Energía, sin rol ficticio, sin “Hive OS”, “Live”, “premium”,
  buscador, campana ni ayuda flotante.
- Chart.js y Lucide servidos desde `assets/vendor/`; el panel funciona en LAN
  sin internet.

### Fixed
- Alta ECDSA: el firmware genera y persiste el código `4-4` (`identidad_ecdsa.h`).
  Pendiente de prueba en hardware con NVS limpia.
- Comando `test`: ya no inserta telemetría falsa (25 °C, 50 %, 1013,25 hPa,
  0 kg). Solo diagnostica sensores locales.
- `/status`: ya no publica el código de alta.
- `api/estado.php`: dejó de devolver IBB/EV/ΔT/H_miel y la serie de peso
  duplicada; el JS ya no pinta indicadores inexistentes.
- `informe.php`: sin KPIs imposibles; muestra las variables medidas y advierte
  que la acústica es experimental.

### Security
- Tests PHP y `tools/diagnostico-bd.php` rechazan ejecución por HTTP.
- El sincronizador excluye `tools`, `tests`, `database`, `docs`,
  `beestation_node` y `scripts`, y elimina `.sql`, `.ino` y `.h` del docroot.
- `.htaccess` bloquea rutas privadas también sin `mod_rewrite`.
- `conectar_dispositivo.php` usa `includes/auth.php` en lugar de una sesión
  aislada.

## 2026-10-21 — Firmware 3.2.0 y puesta en marcha local

### Added
- `beestation_node/acustica.h`: estimación de la **frecuencia dominante** del micrófono por cruces por cero con histéresis. El backend recibe Hz reales; la acústica sigue siendo experimental y no se usa para alertar enjambrazón.
- `tests/host/acustica_test.c`: prueba del mismo encabezado que usa el ESP32 (11/11), con tonos de 250–900 Hz, silencio, ruido, onda cuadrada y amplitud.
- `docs/SENSORES-Y-UNIDADES.md`: contrato de unidades y matriz de disponibilidad real (qué mide el ESP32 y qué solo el simulador).

### Changed
- `INTERVALO_LECTURA` documentado como **30 s** (los comentarios decían 5 min y no coincidían con el código).
- El CSV de la microSD rotula la columna como `Sonido_Hz`.
- El panel y `acustica.php` muestran `Sin actividad`/neutro cuando la frecuencia es 0, en lugar de un falso `Normal` verde.
- El ícono de ΔT es neutro cuando faltan datos, no crítico.

### Fixed
- **Unidades del sonido**: el firmware enviaba voltios pico a pico a una variable declarada en Hz. Ahora envía frecuencia estimada y conserva la amplitud en `sonido_v` (solo diagnóstico); la alerta automática de enjambrazón permanece desactivada.
- El API devolvía **HTTP 200 con stack trace y rutas internas** ante un fallo: ahora responde `500` JSON genérico y registra el detalle en el log (`api/.htaccess` + manejador en `includes/functions.php`).
- El script de despliegue copiaba `tests/` al docroot (PHP ejecutable por HTTP). Se excluye y se bloquea también por `.htaccess`.
- `-Simular` del script de despliegue se ignoraba y copiaba igualmente.

### Migration
- Aplicadas en la BD local: `004_nonce_antireplay.sql`, `005_identidad_dispositivo.sql` y `006_identificador_medicion.sql` (13 tablas). Respaldo previo en `backups\pre-fase2_20261021-202730.sql`.

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
