# Auditoría técnica completa — BeeStation

**Proyecto:** `BeeStation_Sena` · Backend PHP 8.2 + MariaDB + firmware ESP32
**Fecha:** 20 de octubre de 2026
**Alcance:** 24 archivos PHP, 3 JS, 2 CSS (5.657 líneas), firmware ESP32 (2.031 líneas), 2 dumps SQL y la configuración de XAMPP.
**Método:** lectura completa del código + ingeniería inversa del comportamiento real + verificación empírica contra la BD y el servidor en ejecución (pruebas HTTP, `EXPLAIN`, `SHOW CREATE TABLE`, `information_schema`, pruebas de escritura con `ROLLBACK`) + renderizado de la UI con Chrome real.

> **Estado del sistema al iniciar la auditoría:** 2 colmenas · 14 sensores · 45 lecturas · 26 indicadores · 14 intentos de vinculación · 0 alertas · 7 variables bioclimáticas. Repositorio con 34 commits, árbol limpio.

---

## 0. Resumen ejecutivo

El proyecto **funciona**, está bien estructurado y es defendible ante una revisión técnica. El backend usa *prepared statements nativos* reales (`EMULATE_PREPARES=false`), todas las salidas están escapadas salvo dos casos, y los índices principales existen. Pero hay **dos capas de deuda seria** que conviene cerrar antes de que el sistema reciba datos reales de forma sostenida:

| | Riesgo | Consecuencia |
|---|---|---|
| 🔴 **Seguridad** | MySQL escuchando en la red con `root` sin contraseña, el repo git completo descargable por HTTP (`.git/config` → 200 OK) y la API Key versionada | Cualquiera en la LAN puede **borrar la base de datos** o **robar las credenciales** |
| 🔴 **Datos** | La API acepta `"abc"` como `0.0` válido y `9999` como válido; el 33 % de las mediciones del ESP32 real se descartan | Datos corruptos alimentan los indicadores y disparan alertas falsas |
| 🟠 **Escalabilidad** | 1,13 GB/año con 2 colmenas → 4,4 GB/año con 10; 24 consultas por render y 20 por *poll* de 10 s | El panel se degrada de forma silenciosa a partir del 3.er mes |
| 🟠 **UX crítica** | El badge acústico está **invertido** en el JS: una alerta real de enjambrazón se pinta en **verde** a los 10 s | La alerta más importante del sistema miente al usuario |
| 🔴 **Firmware** | La microSD **se trunca en cada ciclo** (`FILE_WRITE` abre en modo `"w"`): el "datalogger" solo guarda la última fila | **El almacenamiento offline que quieres NO funciona** — y se arregla cambiando **una palabra** |
| 🔴 **Firmware** | `if (httpResponseCode > 0)` → un 401/404/500 se interpreta como **éxito** | Pérdida de datos total, permanente e invisible |
| 🔴 **Firmware** | El portal cautivo es una **puerta de una sola dirección**: en runtime, `modoPortalCautivo=false` solo existe en `setup()` | Un nodo desatendido en campo **queda muerto para siempre** |

**Los 5 hallazgos que arreglaría hoy** (ordenados por relación esfuerzo/impacto):

1. **`.htaccess` + mover `test_connection.php` + rotar credenciales** (30 min) — cierra la fuga de `.git`, el dump SQL y la clave de la API.
2. **`FILE_WRITE` → `FILE_APPEND` en la microSD** (1 línea) — sin esto, tu función de recogida offline **no tiene datos que recoger**.
3. **Validación de valores en `api/ingest.php`** (1 h) — deja de aceptar basura como lectura válida.
4. **Interpretar bien el código HTTP en el firmware + `ts`/`seq` en el payload** (2 h) — cierra la pérdida silenciosa y habilita el fechado real de los datos offline.
5. **Registrar `presion` y `luminosidad`** (2 h) — el ESP32 real deja de perder 2 de sus 6 sensores.

> **El paquete de 5 acciones de arriba cabe en una tarde y cambia la naturaleza del sistema:** de "demo que funciona mientras haya red" a "nodo autónomo que no pierde datos".

**Las 2 funciones que pediste son viables y una ya está a medio hacer:**

- 📦 **Recogida offline**: el firmware **ya escribe en microSD** (`SD.h`, `SD_CS=5`, `guardarRespaldoSD()`) y ya sirve esos datos por el portal cautivo. Falta el camino de ingesta al backend → **~70 % hecho**.
- 📄 **PDF con gráficas e indicadores**: **viable y probado hoy** en este equipo con Chrome headless (`--print-to-pdf`): genera un PDF real con el texto vectorial y las gráficas de Chart.js renderizadas. Cero dependencias nuevas.

---

## 1. Ingeniería inversa: mapa del sistema

### 1.1 Capas y flujo de datos

```
┌─ NODO ESP32 (beestation_node.ino) ───────────────────────────────────────┐
│  Sensores: BME280(0x76) T/HR/Presión · BH1750 Lux · HX711 peso · MAX4466  │
│  Lector cada 30 s → payload JSON (6 tipos) → POST /api/ingest.php        │
│  microSD (CS=5): registro continuo de respaldo · portal cautivo AP       │
│  NVS (preferences): SSID, contraseña, token                               │
└──────────────────────────────────────────────┬──────────────────────────┘
                                               │ HTTP + X-API-Key
                                               ▼
┌─ BACKEND PHP 8.2 (XAMPP/Apache, docroot = htdocs/BeeStation_Sena) ──────┐
│  api/ingest.php     → valida token+API Key, inserta lectura, recalcula   │
│  api/validate_token.php → pre-valida token                              │
│  api/estado.php     → JSON de polling (cada 10 s desde el navegador)    │
│  api/ping.php       → healthcheck                                       │
│  includes/functions.php → 25 funciones: lecturas, series, indicadores,   │
│                         alertas, calibración, tiempo relativo            │
│  includes/{header,sidebar,footer}.php → shell del panel                  │
└──────────────────────────────────────────────┬──────────────────────────┘
                                               │ PDO (root, sin contraseña)
                                               ▼
┌─ MARIADB 10.4.32 (beestation_sena) ──────────────────────────────────────┐
│  usuario → apiario → colmena → sensor → lectura                          │
│                        ├→ calibracion   ├→ indicador → alerta            │
│  variable_bioclimatica (catálogo)     intento_vinculacion (bitácora)     │
└──────────────────────────────────────────────────────────────────────────┘
                                               │
                                               ▼
┌─ NAVEGADOR (dashboard, sensores, peso, acústica, energía, dispositivos) ┐
│  Chart.js (CDN) · Lucide (CDN) · Google Fonts (CDN) · polling 10 s       │
└──────────────────────────────────────────────────────────────────────────┘
```

### 1.2 Inventario funcional

| Capa | Archivos | Líneas | Estado |
|---|---|---|---|
| Vistas | 8 PHP | 1.157 | Funcionales; 1 maqueta estática (`energia.php`, 48 l.) |
| Lógica | `includes/functions.php` | 527 | Correcta; 4 funciones muertas |
| API | 4 endpoints | 347 | Correcta; sin validación de datos ni límites |
| Front | 3 JS | 357 | Polling frágil; 7 controles muertos |
| Estilos | 2 CSS | 4.696 | **145 KB, la mitad huérfana** |
| Firmware | `.ino` + 2 cabeceras | 2.031 | Funcional; con microSD ya implementado |
| Esquema | 2 SQL | 862 | **3 fuentes de verdad divergentes** |
| Config | `config/db.php` | 31 | Credenciales reales versionadas |

---

## 2. Lo que se puede romper

### 2.1 🔴 P0 — Bloqueantes (4)

#### P0-1 · Base de datos accesible desde la LAN con `root` sin contraseña
**Evidencia:** `mysqld` escucha en `::3306`; `Test-NetConnection 192.168.1.7 -Port 3306` → **True**; `config/db.php:15-16` → `DB_USER='root'`, `DB_PASS=''`.
**Escenario:** cualquier equipo de la red entra a MySQL como superusuario y ejecuta `DROP DATABASE beestation_sena`. Un `INTO OUTFILE`允许 escribir un webshell en el docroot.
**Corrección:** `bind-address=127.0.0.1` en `my.ini`; crear `beestation_app` con `GRANT SELECT,INSERT,UPDATE,DELETE ON beestation_sena.*` y contraseña; regla de firewall que cierre 3306.

#### P0-2 · El repositorio git completo es descargable por HTTP
**Evidencia (verificado en vivo):** `GET /.git/config` → **HTTP 200**; `GET /database/beestation_sena.sql` → **HTTP 200 (35.835 B)**.
**Escenario:** sin `.htaccess` Apache sirve el directorio `.git`. Quien lo descarga obtiene **el historial completo** (con la API Key de `config/db.php` en los commits), los **hashes bcrypt de los usuarios**, los **tokens de vinculación** de las 2 colmenas y el **historial de IPs** de los dispositivos.
**Corrección:** `.htaccess` en la raíz con `Require all denied` sobre `database/`, `config/`, `beestation_node/`, `.git/`, `repomix-output.xml`; y rotar después API Key, contraseñas y tokens.

#### P0-3 · `schema.sql` destruye la base de datos de producción
**Evidencia:** `database/schema.sql:8` → `DROP DATABASE IF EXISTS beestation_sena;` y `README.md:332` instruye a importarlo.
**Escenario:** un operador sigue la guía sobre un servidor con datos → pierde lecturas, colmenas, usuarios y tokens sin aviso.
**Corrección:** sacar el `DROP` a `database/reset_dev.sql` con advertencia; el alta debe hacerse con migraciones incrementales.

#### P0-4 · La API acepta basura como lectura válida
**Evidencia (pruebas en vivo contra `api/ingest.php`):**

| Payload | Resultado observado |
|---|---|
| `{"tipo":"temperatura_interna","valor":"abc"}` | `ok:true`, `errores:[]` → **guardado como 0.0 con `es_valida=1`** |
| `{"tipo":"temperatura_interna","valor":9999}` | `ok:true`, `errores:[]` → **guardado como válido** |
| 300 lecturas en un POST | **aceptadas todas**, 605 ms, sin tope |

**Escenario:** un sensor descalibrado o un firmware con bug envía `"--"`; el backend lo convierte en 0 °C, ese 0 entra en el IBB (peso 45 %), dispara un IBB < 50 y genera una **alerta crítica falsa** en el panel del apicultor.
**Corrección:** validar `is_numeric()` + `is_finite()` + rango del sensor (con tolerancia); marcar `es_valida=0` y reportar en `errores[]`; tope de 50 lecturas por POST y límite del tamaño del body.

### 2.2 🟠 P1 — Altos (14)

| # | Hallazgo | Ubicación | Corrección |
|---|---|---|---|
| P1-1 | **El 33 % de las mediciones del ESP32 se pierde**: el firmware envía `presion` y `luminosidad`, que no existen como variables ni sensores → 2 errores en cada ingesta | `api/ingest.php:110` | Crear variables 8-9 y aprovisionarlas en `dispositivos.php:70-78` |
| P1-2 | **Badge acústico invertido**: dentro de 400-600 Hz el JS pinta *"Normal"* verde, cuando el PHP pinta *"Zona de alerta"* roja | `js/actualizar-dashboard.js:19` vs `dashboard.php:149-162` | Invertir el par `[r[2], r[3]]`; idealmente, **enviar los umbrales desde la API** para que no se dupliquen en 5 sitios |
| P1-3 | **XSS reflejado** en el alta de colmena: `$mensaje_exito` se imprime sin `htmlspecialchars` | `dispositivos.php:92` → `:143` | Escapar el nombre al construir el mensaje y al imprimirlo |
| P1-4 | **El dispositivo puede escribir en la colmena equivocada**: `token_vinculacion` es `NULL`-able y **no tiene `UNIQUE`** en la BD real | `functions.php:121-127` (`LIMIT 1` sin `ORDER BY`) | `UNIQUE` + `NOT NULL` y quitar el `LIMIT 1` arbitrario |
| P1-5 | **El contador de intentos fallidos no existe**: `incrementarIntentosFallidosToken()` nunca se llama, y se reinicia a 0 en cada ingesta exitosa | `functions.php:153-172` | Llamarlo en el camino de fallo y no reiniciarlo en cada ingesta |
| P1-6 | **Sin trazabilidad de ataques con API Key errónea**: el 401 sale *antes* de tocar la BD → no queda rastro | `api/ingest.php:55-59` | Registrar `resultado='sin_apikey'` con la IP |
| P1-7 | **La app queda inservible tras una instalación limpia**: `schema.sql` no crea `intento_vinculacion` ni las columnas de token → la ingesta da error 500 | `schema.sql` vs `functions.php:143` | Generar `schema.sql` desde migraciones |
| P1-8 | **Entropía del token = 24 bits** (`random_bytes(3)`) → 16,7 M combinaciones, agotable en horas | `functions.php:106` | 16 bytes (128 bits) |
| P1-9 | **La alerta de enjambrazón nunca puede dispararse**: el firmware mide **voltios** (0-3,3 V) con el MAX4466 pero el backend espera **Hz** (400-600) | `functions.php:469` | Requiere medir frecuencia real (FFT en el ESP32) o renombrar el indicador a "nivel acústico" |
| P1-10 | **N+1 de consultas**: 24 por render de dashboard, 20 por *poll* de 10 s | `functions.php` (11 `ultimaLectura` por poll) | Una consulta que devuelva las últimas lecturas de todos los tipos + caché de 10 s |
| P1-11 | **`peso.php` serializa 86.400 filas** (30 días a 30 s) → ~1,4 MB de JSON inline → con `memory_limit=128M` la pantalla queda en blanco | `peso.php:12` | Agregar en SQL por hora/día con `LIMIT` de seguridad |
| P1-12 | **Caída de red 100 % silenciosa**: `#lastUpdate` sigue marcando la hora vieja, el panel parece vivo | `js/actualizar-dashboard.js:159-175` | Contador de fallos → banner "Sin conexión · última actualización hace X" |
| P1-13 | **Sin internet = sin gráficas y sin iconos**: `new Chart` sin guardia → `ReferenceError`; 3 CDN sin versionar (`@latest`) | `includes/header.php:47-48` | Vendorizar Chart.js + Lucide con versión pineada + guarda `typeof Chart` |
| P1-14 | **Los servicios no arrancan solos**: Apache y MySQL se caieron 3 veces durante esta sesión (reinicios del equipo) | XAMPP | Instalar XAMPP como servicio de Windows + `scripts/iniciar-beestation.bat` como acceso directo |

### 2.3 🔴 Firmware ESP32 — lo que se puede romper en el nodo

Auditoría sobre `beestation_node.ino` (1.588 l.), `index_html.h` (454 l.) y `monitoreo_html.h` (227 l.), contrastados con el core `esp32:esp32@3.3.12` y las librerías instaladas.

#### 2.5.1 P0 — Críticos (4)

| # | Hallazgo | Ubicación | Escenario de fallo | Corrección |
|---|---|---|---|---|
| **F-01** | **La microSD trunca el CSV en cada ciclo**: `SD.open(fichero, FILE_WRITE)` abre en modo `"w"` = `O_TRUNC` | `.ino:1285` | Cada 30 s se borra `/log_2026-10-20.csv` y se reescriben cabecera + 1 fila. **El fichero contiene siempre cabecera + 1 fila.** Si el nodo pasa 6 h sin red, "conserva" 30 segundos de historial. Todo el respaldo local es decorativo | `FILE_APPEND` + `File` persistente en RAM + `flush()` cada N filas |
| **F-02** | **La microSD es solo de escritura**: no existe reenvío | `.ino:406` vs `:437-538` | El comentario dice *"se registra SIEMPRE en microSD y, sólo si hay red, se sube"*. Si el POST falla, la fila **se queda para siempre**: no hay cola ni offset de reenvío. 4 h sin red = 480 filas huérfanas | Cola de salida persistente (ver §4.1) |
| **F-03** | **Cualquier respuesta HTTP ≥ 400 se lee como ÉXITO** | `.ino:488` | `if (httpResponseCode > 0)` es cierto para 200, 401, 404 y 500. Con el token revocado o la API key desincronizada: parpadeo verde, "Última subida 12:34:56" y **cero datos en el servidor**. Pérdida total, permanente e invisible | `if (code == 200)` + parsear el cuerpo (`"ok":true`) y actuar sobre `errores[]` |
| **F-04** | **El portal cautivo es una puerta de una sola dirección** | `.ino:1228-1232`, `:1401` | `modoPortalCautivo = false` **solo existe en `setup()` (L354)**; en `loop()` se retorna temprano → `reconectarWiFi()` nunca se vuelve a llamar. Con energía intermitente, 40 s sin red ⇒ el nodo levanta un AP abierto, **deja de enviar datos para siempre** y solo revive si un humano lo reconfigura | Nunca entrar en portal por fallo de red en runtime (solo por token rechazado explícitamente o pulsación de BOOT); `WIFI_AP_STA` en vez de `WIFI_AP` |

#### 2.5.2 🟠 P1 — Altos (13 seleccionados)

| # | Hallazgo | Ubicación | Impacto |
|---|---|---|---|
| F-05 | `validarTokenConServidor()` **nunca rechaza un token**: `else { valido = true; }` | `.ino:1164-1174` | Un token mal pegado → 404 → el firmware lo acepta → la rama de reprovisionamiento es **código muerto** |
| F-06 | **El "peso" no son kg, son conteos crudos del HX711** (`set_scale(1.0)` ⇒ `get_units()` = conteo 24 bits) | `.ino:232,236,620` | Se guardan ~482.913 con la unidad "kg" → 4 órdenes de magnitud fuera de rango; el "flujo diario de néctar" sale absurdo |
| F-07 | **La acústica se mide en voltios (0-3,3 V), el backend espera Hz (400-600)** | `.ino:1243-1260` | **La alerta de enjambrazón no puede dispararse jamás** y la columna se etiqueta "Hz" guardando voltios |
| F-08 | **El mapa de pines no coincide con la guía técnica**: HX711 (guía 26/27 vs firmware 16/4), MAX4466 (guía 34 vs firmware 35) | `.ino:30-34` | Quien cablea según el manual de campo deja **2 de 6 sensores muertos**, sin error visible |
| F-09 | El escaneo de subred **bloquea el loop entre 10 y 165 s** (254 IPs × 650 ms) | `.ino:554-596` | Se relanza en cada fallo ⇒ el nodo deja de muestrear durante minutos y genera huecos |
| F-10 | `atenderPortal()` hace un **escaneo WiFi completo en cada carga** | `.ino:1421` | 2-4 s de congelación por petición; el `scanNetworks()` además cambia el modo de radio a `WIFI_AP_STA` |
| F-11 | `/datos` **relee el CSV entero del día cada 15 s** (2.880 `String` por petición) | `.ino:1325-1347` | Cientos de ms de bloqueo + fragmentación progresiva del heap |
| F-12 | **XSS vía el SSID** concatenado sin escapar en el HTML del portal | `.ino:1427` | Una red con nombre malicioso ejecuta JS en el móvil del apicultor al aprovisionar |
| F-13 | **`/save` sigue activo en modo estación, sin autenticación** | `.ino:1383,1553-1588` | Cualquiera de la LAN hace POST a `/save` con su SSID y un token válido ⇒ **secuestro total del nodo**; además es CSRF desde cualquier web |
| F-14 | **El payload no lleva marca de tiempo** (`ts`, `seq`) | `.ino:450-457` | `fecha_hora` = instante de *llegada*, no de *medición* ⇒ **bloqueante nº1 para el diseño offline** y curvas que mienten |
| F-15 | **Sin watchdog de software** | todo el firmware | El escaneo de 254 IPs sin `delay()` puede superar el Task WDT de 5 s ⇒ **reinicio del chip** |
| F-16 | `http.getString()` **sin límite de tamaño** | `.ino:489,1083` | Una página de error de 50 KB se carga entera en el heap ⇒ `abort()` por falta de memoria |
| F-17 | La **API key está hardcodeada** en el binario y es la misma para todos los nodos; además el token completo se imprime por el puerto serie | `.ino:73,1008,1010` | La clave no se puede rotar sin reflashear todos los ESP32 |

#### 2.5.3 ⚪ P2/P3 — Medios y bajos (25)

Sin validación de longitud/formato del SSID, contraseña, token y host (⇒ inyección de cabeceras HTTP vía `host`) · `String` por todas partes ⇒ fragmentación del heap · `String(PORTAL_HTML)` lee 11 KB de PROGMEM al heap en vez de `send_P` · la respuesta del escaneo se lee sin tope · `INTERVALO_LECTURA = 30 s` contradice el comentario *"5 minutos"* · el reset de fábrica por BOOT es inalcanzable (GPIO0 LOW en arranque ⇒ modo bootloader) y un roce de 10 ms borra la NVS · `preferences.clear()` no borra las credenciales del driver WiFi · sin NTP las filas van a `log_sin_hora.csv` y el `Millis` se reinicia a 0, impidiendo ordenarlas · la microSD solo se detecta al arrancar (insertarla en caliente no funciona) · un sensor ausente se reporta como `0.0` **válido** ⇒ IBB = 0 ⇒ **alerta crítica falsa en una colmena sana** · atenuación del ADC global sin compensar · AP del portal sin contraseña · DNS del portal nunca se detiene · esperas bloqueantes de 5-20 s en el loop · busy-loop de 50 ms · el panel serie imprime ~1 KB por ciclo · `innerHTML` con datos del CSV (XSS desde la microSD) · mDNS con el mismo nombre en todos los nodos · `configTzTime("COT5")` no estándar.

#### 2.5.4 Contrato firmware ↔ backend: roto en tres ejes independientes

| Tipo enviado | ¿Existe en `sensor`? | ¿Unidad coherente? | Efecto actual |
|---|---|---|---|
| `temperatura_interna` | ✅ | °C ✅ | Se guarda |
| `humedad_relativa` | ✅ | %HR ✅ | Se guarda |
| `peso` | ✅ | **kg vs conteos crudos ❌** | Número absurdo |
| `sonido` | ✅ | **Hz vs V ❌** | La alerta nunca salta |
| `presion` | ❌ (solo en la colmena 1) | hPa ✅ | **Rechazado** |
| `luminosidad` | ❌ (solo en la colmena 1) | lux ✅ | **Rechazado** |
| `co2` | ✅ registrado | ppm | **El firmware no lo envía** ⇒ IBB y EV siempre `null` |
| `temperatura_externa` | ✅ registrado | °C | **El firmware no lo envía** ⇒ ΔT siempre `null` |
| `energia` | ✅ registrado | V | **El firmware no lo envía** |

> Los tres ejes se cierran con: **tipos** (registrar `presion`/`luminosidad` + enviar `co2`/`temperatura_externa`), **unidades** (kg reales, dBFS en vez de Hz) y **tiempo** (`ts`/`seq` en el payload).

### 2.4 🟡 P2 — Medios (26 seleccionados)

| # | Hallazgo | Ubicación |
|---|---|---|
| P2-1 | Sin `STRICT_TRANS_TABLES` → truncamiento silencioso de datos (un token de 60 chars se guarda como 50) | `@@sql_mode` |
| P2-2 | Zona horaria PHP/MySQL no sincronizada *por construcción* (no hay `SET time_zone`): depende de la zona del SO | `config/db.php:8-11` |
| P2-3 | Buffer pool de 16 MB para una BD que crece 1,13 GB/año (solo cabe el 2,5 % del índice) | `my.ini` |
| P2-4 | `log_bin=0` → sin PITR ni replicación; la propia purga de datos sería irreversible | `my.ini` |
| P2-5 | 34 columnas `NULL`-able y **0 CHECK constraints** en toda la base | `information_schema` |
| P2-6 | 3 fuentes de verdad del esquema divergentes (8 diferencias) y sin carpeta de migraciones | `database/` |
| P2-7 | 9 FKs todas `RESTRICT` sin cascada → **ninguna colmena es borrable** (verificado: ERROR 1451) | esquema |
| P2-8 | Los 6 umbrales de `variable_bioclimatica` **nunca se leen**: los umbrales reales están hardcodeados en `functions.php:381-383` y `js/actualizar-dashboard.js:16-20` | `functions.php` |
| P2-9 | Columnas muertas: `lectura.unidad` (100 % NULL), `sensor.rango_*` (nunca leídos), `alerta.id_usuario` (siempre NULL), `calibracion` (0 filas, solo lectura) | esquema |
| P2-10 | `alerta.nivel` siempre vale 2 → la rama "crítico" de la UI nunca se activa | `api/ingest.php:175` |
| P2-11 | `alerta.estado` nunca se actualiza → todas las alertas quedan 'activa' para siempre | `functions.php:310` |
| P2-12 | `index.php` **cierra la sesión** al entrar al sitio (es un clon de `logout.php`) | `index.php` |
| P2-13 | Sin `session_regenerate_id` tras login → fijación de sesión | `login.php:21-38` |
| P2-14 | Sin CSRF en el login (sí lo hay en `seleccionar_colmena.php` → inconsistente) | `login.php` |
| P2-15 | HTML desbalanceado y **`<main>` duplicado** en `dispositivos.php` (3 etiquetas sin cerrar) | `dispositivos.php:118-129` |
| P2-16 | Barras IBB/EV **congeladas**: el JS cambia `data-progress` pero `app.js` solo lo lee en `DOMContentLoaded` | `js/actualizar-dashboard.js:76` |
| P2-17 | Los badges de ΔT y H_miel nunca cambian de color (un "colonia muerta" en verde) | `js/actualizar-dashboard.js:56-62` |
| P2-18 | Las series Temperatura y Peso están **desalineadas en el tiempo** (2 consultas distintas → 2 arrays de longitudes distintas sobre el mismo eje X) | `dashboard.php:342-363` |
| P2-19 | "N alertas activas" no es el número de alertas (LIMIT 5 en PHP, LIMIT 10 en el poll) | `api/estado.php:71` |
| P2-20 | `energia.php` es una maqueta de 48 líneas sin datos ni guard de `$colmenaActiva` | `energia.php` |
| P2-21 | `sensores.php` muestra el estado **estático** (`sensor.estado`) en vez del dinámico por antigüedad | `sensores.php:11` vs `functions.php:273` |
| P2-22 | Contraste WCAG AA fallido en 4 tokens de color (`#E3A83B` = 2,07:1) | `css/style-premium.css:4,30,32,25` |
| P2-23 | 3 `<canvas>` sin `role="img"` ni alternativa textual; `<th>` sin `scope` | 3 vistas |
| P2-24 | Cajón lateral móvil sin `aria-expanded`, sin `Esc`, sin focus trap y **tabulable aunque oculto** | `js/app.js:49-61` |
| P2-25 | 7 controles muertos: buscador, campana y 5 botones "Ayuda" | `includes/header.php` + 5 vistas |
| P2-26 | `.single-column-grid` no existe en ningún CSS → el chart de peso queda al 50 % con media columna vacía | `peso.php:70` |

### 2.5 ⚪ P3 — Bajos (13)

`js/charts.js` muerto · 4 funciones PHP muertas · 71,5 KB de CSS huérfano · **1,27 MB de imágenes sin optimizar** (logo de 539 KB mostrado a 30×30 px) · `repomix-output.xml` en el docroot · `footer.php` con un `if` vacío · `<title>` idéntico en todas las páginas · `json_encode` sin `JSON_HEX_TAG` · etiquetas de peso duplicadas en los ticks · `localStorage` sin `try/catch` · `.sidebar-footer-note` sin estilo · el script `.sh` de arranque apunta a `/opt/lampp` y a IPs fijas de un laboratorio que ya no existen.

---

## 3. Lo que se puede mejorar y optimizar

### 3.1 Rendimiento (medido, no supuesto)

| Métrica | Hoy | Con 1 año | Con 10 colmenas |
|---|---|---|---|
| Consultas por render de dashboard | **24** | 24 | 24 |
| Consultas por *poll* de 10 s | **20** | 20 | 20 |
| Lecturas/año (2 colmenas) | 45 | **6.307.200** | 42.048.000 |
| Disco/año | 37 KB | **≈1,13 GB** | ≈4,4 GB |
| Buffer pool vs tabla `lectura` | 16 MB | solo cabe el **2,5 %** | — |
| Payload del poll | ~90 KB cada 10 s | ~90 KB | — |
| `peso.php` (30 días) | 86.400 filas | ↑ | ↑ |

**Optimizaciones con mejor relación impacto/esfuerzo:**

| # | Optimización | Impacto | Esfuerzo |
|---|---|---|---|
| O-1 | **Una consulta de "última lectura por tipo"** + caché de 10 s → 20 → 3 consultas por poll | −85 % de carga de BD | M |
| O-2 | **Agregación horaria/diaria en SQL** para series > 24 h (y rollups `lectura_hora`) | 86.400 → 720 filas | M |
| O-3 | **Dejar de insertar el indicador si el valor no cambió** (umbral) y **no registrar los `ok`** de la bitácora | −4.320 filas/día de golpe | S |
| O-4 | **Índices**: `alerta(id_colmena,estado,fecha_hora)`, `lectura(id_sensor,es_valida,fecha_hora)`, `lectura(fecha_hora)`, `UNIQUE sensor(id_colmena,tipo)`, `UNIQUE colmena(token_vinculacion)` | elimina 3 escaneos totales | S |
| O-5 | **`EXISTS` en vez de `COUNT(*)`** en `dispositivoConectado()` | evita escaneo de 6 M filas | S |
| O-6 | **Vendorizar Chart.js + Lucide + fuentes** | el panel funciona sin internet | S/M |
| O-7 | **Optimizar assets** (logo → WebP ≤5 KB, foto → ≤120 KB) | −1,1 MB por carga | S |
| O-8 | **Unificar el CSS** (mover 11 clases huérfanas, borrar `style.css`) | −71,5 KB | S |

### 3.2 Escalabilidad — política de retención propuesta

| Tabla | Retención en crudo | Rollup | Retención final | Disco |
|---|---|---|---|---|
| `lectura` | 180 días | `lectura_hora` (avg/min/max) | 730 días | ≤330 MB |
| `indicador` | 180 días | `indicador_dia` | 730 días | ≤150 MB |
| `intento_vinculacion` | **30 días y solo fallos** | — | 30 días | ≤15 MB |
| `alerta` | 730 días (nunca purgar 'activa') | — | 730 días | ≤2 MB |
| **Total estable** | | | | **≈500 MB constantes** |

Operación: purga nocturna por lotes de 5.000 filas + `OPTIMIZE TABLE` mensual + binlog activado + backup diario.

### 3.3 Funcionalidad nueva priorizada

| # | Función | Beneficio | Esfuerzo |
|---|---|---|---|
| F-1 | **Recogida offline de datos** (microSD/USB → backend) | el nodo no pierde datos sin red | **M** (firmware ya tiene el 70 %) |
| F-2 | **Informe PDF con gráficas e indicadores** | entregable de tesis/interventoría | **S** (probado hoy) |
| F-3 | **Exportación CSV** de cualquier serie | análisis externo, Excel es-ES | S |
| F-4 | **Filtros de rango y granularidad** | hoy el rango está hardcodeado | M |
| F-5 | **Umbrales configurables desde la UI** (mata la duplicación PHP/JS y el P1-2) | el apicultor ajusta por clima | M |
| F-6 | **Ciclo de vida de alertas** (acusar recibo, descartar, `id_usuario`) | las alertas se acumulan para siempre | M |
| F-7 | **Estado real del sistema** (batería, voltaje, RSSI, uptime) | `energia.php` es una maqueta | L |
| F-8 | **Comparación multi-colmena** | visión de apiario | L |
| F-9 | **Alertas por webhook/Telegram** | el panel solo sirve si alguien lo mira | M |
| F-10 | **PWA / service worker** | instalable y tolerante a cortes | L |

---

## 4. Diseño de las dos funciones que pediste

### 4.1 📦 Almacenamiento offline y recogida de datos

**Estado real: la base existe, pero está rota por dos bugs concretos.** El firmware ya incluye `SD.h` (`SD_CS = GPIO 5`), `guardarRespaldoSD()` y un visor de datos guardados en el portal cautivo (`monitoreo_html.h`). El commit `3b30525` lo titula *"registro continuo en microSD y consulta de datos guardados"*.

> 🔴 **Pero la auditoría encontró que el "respaldo local" no funciona**, y son exactamente los dos puntos que esta función necesita:
>
> 1. **F-01 — el CSV se trunca en cada ciclo.** `SD.open(fichero, FILE_WRITE)` abre en modo `"w"` (= `O_TRUNC`). Cada 30 s se borra el fichero y se reescriben cabecera + 1 fila. El fichero **siempre** contiene 1 fila. **Corrección: una palabra → `FILE_APPEND`.**
> 2. **F-02 — no hay reenvío.** Se escribe la fila y, en el mismo ciclo, se intenta el POST. Si falla, la fila se queda para siempre: no hay cola, ni offset, ni reintento. **Corrección: cola de salida con marcador de secuencia en NVS.**
> 3. **F-14 — el payload no lleva `ts`.** `fecha_hora` = instante de *llegada* al servidor, no de *medición*. Sin esto, los datos recuperados quedarían fechados en el momento de la descarga. **Es el bloqueante nº1 del diseño.**

#### Arquitectura propuesta

```
 ESP32 SIN RED                          ESP32 CON RED
 ┌──────────────────────┐              ┌──────────────────────────────┐
 │ leerSensores()       │              │ leerSensores()               │
 │        ↓             │              │        ↓                     │
 │ guardarRespaldoSD()  │─────────────▶│ guardarRespaldoSD()          │  ← se escribe SIEMPRE
 │ append + flush()     │              │ append + flush()             │
 └──────────────────────┘              │        ↓                     │
                                       │ envío directo                │
                                       │  ├─ ok  → up_seq = seq       │
                                       │  └─ falla → NO avanzar        │
                                       │        ↓                     │
                                       │ replay desde up_file/up_line  │
                                       │  POST lote de ≤50 filas      │
                                       │  ├─ 200 ok → avanzar + NVS    │
                                       │  └─ error → no avanzar        │
                                       └──────────────────────────────┘
```

#### Formato del archivo: **CSV** (descartados JSON Lines y SQLite)

| Opción | Evaluación |
|---|---|
| **CSV ✅** | ~90 B por fila; el ESP32 ya lo usa; parseable con `sscanf`; compatible con el visor del portal y con Excel es-ES. **Elegido.** |
| JSON Lines ❌ | 2-3× el tamaño, más `String` en el ESP32 y `json_decode` por línea en el backend |
| SQLite ❌ | No hay motor SQLite en el ESP32; el wear-leveling de la flash lo desaconseja |

**Formato de línea — compatible con el CSV actual (se amplía, no se rompe):**

```
Cabecera:  Fecha_hora,Epoch,Seq,Millis,Temp,Hum,Presion,Peso,Sonido,Luz,Flags
Línea:     2026-10-20 14:22:03,1789456123,42,884123,34.87,61.20,1013.42,28.51,0.18,412.70,0
```

| Campo | Tipo | Para qué |
|---|---|---|
| `Epoch` | epoch UTC | **Fechado real de la medición** → `lectura.fecha_hora` |
| `Seq` | uint32 monotónico | **Clave de idempotencia** del replay (evita duplicados) |
| `Millis` | uint32 | Reconstruye la hora si el RTC se pierde: `ts = ts_base + (millis − millis_base)` |
| `Flags` | bitfield | Qué sensor falta (permite distinguir "0 °C real" de "sensor caído") |

**Estructura de carpetas:**
```
/datos/2026/10/20.csv        ← fichero del día (rotación diaria)
/datos/2026/10/20_01.csv     ← rollover por tamaño (2 MB o 100.000 filas)
/datos/2026/10/20.csv.enviado ← marca de lote confirmado
```

**Capacidad real:** con 30 s → 2.880 filas/día ≈ 260 KB/día ⇒ una microSD de 8 GB ≈ **10 meses** de autonomía. Con 5 min → 26 KB/día ⇒ **8 GB ≈ 10 años**. Retención recomendada: 30 días en la tarjeta, borrando solo ficheros **sin pendientes**.

#### Cambios mínimos en el backend (retrocompatibles al 100 %)

| # | Cambio | Efecto |
|---|---|---|
| B-01 | `api/ingest.php` acepta `ts` opcional por lectura → `FROM_UNIXTIME(?)` | Fechado real; habilita el replay |
| B-02 | `lectura.seq` + `UNIQUE(id_sensor, seq)` + `INSERT IGNORE` | Reenvíos idempotentes |
| B-03 | `api/ingest.php` responde con `ok_total: false` (o `422` si fallan todos) | El firmware puede distinguir "todo bien" de "parcial" (cierra F-03) |
| B-04 | `dispositivos.php` registra `presion` y `luminosidad` (9 tipos, no 7) | Los 2 de 6 datos que hoy se descartan |
| B-05 | `calibracion.factor_correccion` pasa a ser **multiplicativo** (hoy es una suma) | El peso del HX711 es un factor de escala, no un offset |
| B-06 | Los indicadores se recalculan **una vez por lote**, no por lectura | Evita 4 filas de `indicador` por cada lectura del replay |
| B-07 | `api/heartbeat.php` (nuevo) | Detectar nodo muerto en <2 min y exponer el estado real |

#### Puntos de diseño a decidir contigo

| Decisión | Opciones | Mi recomendación |
|---|---|---|
| ¿Cómo llega el dato al backend? | (a) el nodo sube solo al recuperar red · (b) recogida física de la microSD | **Ambas**: (a) es automático y cubre el 80 %; (b) cubre la zona sin cobertura. El endpoint es el mismo |
| ¿Dónde vive la clave API? | compilada en el firmware (hoy) o en la microSD | **microSD**, junto al token → rotable sin reflashear |
| ¿Cómo se evitan duplicados? | | `seq` global persistente en NVS + `UNIQUE(id_sensor, seq)` |
| ¿Cuándo se borra un fichero? | | cuando el backend confirma el `seq` recibido |
| ¿Quién "toma" la microSD? | | `scripts/recoger-datalog.ps1`: lista, sube, borra y confirma |
| ¿littleFS como respaldo? | | sí, como cola de emergencia (~4 días) si falla la tarjeta |

**Esfuerzo estimado:** firmware 6-8 h (append + cola + `ts`/`seq` + rotación + replay) + backend 4-5 h (B-01…B-07) + script de recogida 1 h. **~2 días de trabajo para el ciclo completo.**

> 📌 **El orden importa:** con solo el cambio `FILE_WRITE → FILE_APPEND` (1 línea) ya se conserva el histórico en la tarjeta, aunque todavía haya que descargarlo a mano. Es el **primer paso** y el de mejor relación esfuerzo/impacto de toda la función.

### 4.2 📄 Informe PDF con gráficas, números e indicadores

**Veredicto: viable, probado hoy en este equipo, sin dependencias nuevas.**

**Prueba de viabilidad ejecutada** (no es una suposición): generé un informe HTML con el estilo real de BeeStation (tarjetas KPI + gráfica Chart.js + tabla), lo pasé por Chrome headless y obtuve un PDF válido:

```
PDF generado: 59.2 KB   Firma: %PDF-1.4   Páginas: 1
Imágenes embebidas: 2   (el canvas de Chart.js, renderizado)
Fuentes: 6              (texto vectorial real, seleccionable y buscable)
```

**Camino 1 — Recomendado: HTML + Chrome headless (`--print-to-pdf`)**
- Un `informe.php` reutiliza **las mismas funciones de negocio** (`ultimaLectura`, `serieHistorica*`, `calcularIBB/EV/DeltaT/HMiel`, `alertasActivas`) → un solo origen de verdad.
- Se monta la plantilla con `css/print.css` (`@page { size: A4; margin: 14mm }`, ocultar sidebar/topbar, forzar tema claro, `break-inside: avoid`).
- El servidor invoca Chrome headless (`--print-to-pdf`) y devuelve el PDF.
- **Ventajas:** 0 librerías, 0 Composer, las gráficas de Chart.js se renderizan **exactamente** como en pantalla, texto vectorial, y el motor ya está instalado y probado.
- **Inconveniente:** requiere Chrome/Edge en el servidor (ya está) y ~14 s por informe (se puede paralelizar y cachear).

**Camino 2 — Alternativa sin proceso externo: `window.print()` + `@media print`**
- El botón "Descargar PDF" invoca `window.print()` sobre una vista de informe.
- El usuario elige "Guardar como PDF" en el diálogo del navegador.
- Cero backend, cero procesos. Es lo más simple y **lo que yo implementaría primero**.

**Camino 3 — PDF nativo en el servidor**
- `TCPDF` **ya está disponible** en `C:\xampp\phpMyAdmin\vendor\tecnickcom\tcpdf` (un archivo, sin Composer, con SVG nativo) → PDF vectorial profesional.
- **Pero**: `GD` **no está instalado** en este XAMPP (la línea `;extension=gd` está comentada y **no existe `gd.dll`** en `php\ext`), así que la vía "dibujar la gráfica en PHP" no es posible sin instalar la extensión. TCPDF + **SVG generado en PHP puro** sí lo es (unas 300-500 líneas por gráfico).

**Qué usaría y en qué orden:**

| Fase | Entregable | Esfuerzo | Dependencias |
|---|---|---|---|
| **1** | `informe.php` + `css/print.css` + botón "Descargar PDF" (`window.print()`) | **S** (1-2 días) | ninguna |
| **2** | Endpoint `api/informe.php` con **Chrome headless** → PDF descargable desde el panel, con rangos y gráficos | **M** (3-5 días) | Chrome (ya instalado) |
| **3** | PDF nativo con TCPDF + SVG en PHP | **M** (3-5 días) | TCPDF (ya en XAMPP) |

**Advertencia importante para el PDF:** antes de renderizar hay que **agregar en SQL** (nunca `fetchAll()` de 86.400 filas: `memory_limit=128M` en XAMPP) y decidir el *bucket* según el rango (hora para 24 h, día para 30-90 d).

---

## 5. Plan de trabajo propuesto

| Bloque | Contenido | Esfuerzo | Riesgo que cierra |
|---|---|---|---|
| **1 · Contención** | `.htaccess`, mover `test_connection.php` y `repomix-output.xml`, MySQL a `127.0.0.1` + usuario con privilegios mínimos, rotar API Key y contraseñas, `index.php` como portada real | 1 día | P0-1, P0-2, P2-12 |
| **2 · Datos correctos** | Validación en `ingest.php` (numérico/finito/rango + `es_valida` + tope), registrar `presion`/`luminosidad`, corregir rango de CO₂ (400-5000 ppm), desempate en `ultimaLectura`, `UNIQUE` en token y en `(id_colmena,tipo)` | 2-3 días | P0-4, P1-1, P1-4 |
| **3 · Verdades del panel** | Badge acústico, umbrales servidos por la API, barras IBB/EV, badges de ΔT/H_miel, contador de alertas, series alineadas, estado de red, estado dinámico en `sensores.php` | 2-3 días | P1-2, P1-10, P1-12, P2-16…21 |
| **4 · Esquema y escalabilidad** | `database/migrations/` + runner, índices, `NOT NULL`/`CHECK`, catálogos, política de retención + purga, binlog, buffer pool, `SET time_zone` | 1-2 semanas | P2-1…P2-9, P1-7 |
| **5 · Producto** | Informe PDF (fase 1→2), exportación CSV, filtros de rango, umbrales configurables, ciclo de vida de alertas, `energia.php` real, vendorizar CDN, accesibilidad AA, unificar CSS | 2-4 semanas | P2-13…P2-26, P1-13 |
| **5 · Nodo autónomo** | `FILE_APPEND` + cola de salida + `ts`/`seq` + rotación, `code == 200` estricto, portal reversible, watchdog, `presion`/`luminosidad`, unidades kg y dBFS, calibración del HX711, `/save` autenticado | 1-2 semanas | P1-9, F-01…F-17, F-1 |
| **6 · Recogida y reporte** | Reporte PDF (fase 1→2), exportación CSV, filtros de rango, umbrales configurables, ciclo de vida de alertas, `energia.php` real, vendorizar CDN, accesibilidad AA, unificar CSS | 2-4 semanas | P2-13…P2-26, P1-13, F-1 |

> **Orden recomendado por el firmware** (del agente de auditoría, verificado contra el core 3.3.12):
> 1. `FILE_WRITE` → `FILE_APPEND` (1 línea) e interpretar bien el código HTTP → reparan la mayor parte de la pérdida silenciosa.
> 2. Portal cautivo reversible → el nodo vuelve a ser autónomo en campo.
> 3. Unidades coherentes (kg reales, dBFS) → coherencia firmware ↔ backend ↔ UI.
> 4. Cola + replay + `ts`/`seq` → el respaldo offline pasa de decorativo a funcional.

---

## 6. Anexo — Verificaciones ejecutadas

| Verificación | Resultado |
|---|---|
| `POST /api/ingest.php` con `"valor":"abc"` | `ok:true`, `errores:[]` → fila con `valor_bruto=0`, `es_valida=1` |
| `POST /api/ingest.php` con `valor=9999` | `ok:true`, `errores:[]` → fila válida |
| `POST /api/ingest.php` con 300 lecturas | 300 insertadas, 605 ms, sin tope |
| `GET /api/estado.php` sin sesión | **404** (el guard redirige a `api/login.php`) en lugar de 401 |
| Consultas por página | dashboard 24 · api/estado 20 · dispositivos 13 · acustica 14 · peso 10 · sensores 9 · energía 8 |
| `SHOW INDEX` (10 tablas) | `intento_vinculacion` **solo PK**; `alerta` sin índice por estado/fecha |
| `EXPLAIN alertasActivas` | `alerta` → `type: ALL` + `Using filesort` |
| `EXPLAIN serieHistorica` | `sensor` → `type: ALL` + `Using temporary; Using filesort` |
| `DELETE FROM colmena/sensor/usuario` (en transacción) | **ERROR 1451 ×3** (FK RESTRICT) |
| `information_schema.CHECK_CONSTRAINTS` | **0** |
| Columnas nullable | **34** |
| `@@sql_mode` | sin `STRICT_TRANS_TABLES` → truncamiento silencioso verificado (60 → 50 chars) |
| `@@innodb_buffer_pool_size` / `@@log_bin` | 16 MB / 0 |
| Puerto 3306 desde la IP LAN | **alcanzable** (`TcpTestSucceeded=True`) |
| `GET /.git/config` · `GET /database/beestation_sena.sql` | **HTTP 200** en ambos |
| Extensiones PHP | `gd` **ausente** (no hay `gd.dll`); `curl`, `mbstring`, `openssl`, `zlib` presentes |
| Librerías PDF en XAMPP | `TCPDF` en `phpMyAdmin/vendor` ✅ · `FPDF` solo como carpeta de licencias · sin Composer |
| Chrome headless `--print-to-pdf` | **PDF válido** con 2 imágenes y 6 fuentes |
| Crecimiento estimado | 1,13 GB/año (2 colmenas) · 4,4 GB/año (10 colmenas) |
| Diferencia repo ↔ copia servida | 0 archivos divergentes (31 comparados) |
| **Firmware · `SD.open(fichero, FILE_WRITE)`** | **`FS.h`: `FILE_WRITE "w"` → `fopen(temp,"w")` = `O_TRUNC`** ⇒ el CSV se trunca en cada ciclo (F-01) |
| **Firmware · `if (httpResponseCode > 0)`** | `true` para 200, 401, 404 y 500 ⇒ los errores se leen como éxito (F-03) |
| **Firmware · `modoPortalCautivo = false`** | aparece **solo** en `setup()` (L354); en `loop()` se retorna temprano ⇒ el portal es irreversible (F-04) |
| **Firmware · `validarTokenConServidor()`** | `else { valido = true; }` ⇒ nunca rechaza un token (F-05) |
| **Firmware · `HX711::get_units()`** | `(conteo_24bit − OFFSET) / SCALE` con `set_scale(1.0)` ⇒ devuelve el **conteo crudo**, no kg (F-06) |
| **Firmware · `leerNivelAcustico()`** | devuelve `peakToPeak * 3.3 / 4095` ⇒ **voltios**, contra los Hz que espera el backend (F-07) |
| **Firmware · mapa de pines** | HX711 16/4 (guía: 26/27) · MAX4466 35 (guía: 34) (F-08) |
| **Firmware · `dnsServer.stop()` / `server.end()`** | no existen en el código ⇒ el servidor DNS cautivo nunca se detiene |
| **Firmware · core y librerías** | `esp32:esp32@3.3.12` + `HX711_Arduino_Library`, `BH1750`, `Adafruit_BME280_Library` instaladas y verificadas |
