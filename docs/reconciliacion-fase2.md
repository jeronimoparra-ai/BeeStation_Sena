# Reconciliación — Fase 2: autenticación sin token permanente

> Fecha: 2026-10-21 · Rama: `feat/phase2-tokenless-enrollment`
> Base: `origin/main` (`bd5fc29`) · Commits: `3b35b5d` + el commit de cierre de esta fase

---

## 1. Objetivo

Eliminar el token de vinculación como credencial permanente del nodo y
sustituirlo por una **identidad criptográfica propia de cada ESP32** (ECDSA
P-256), de modo que:

- no haya ningún secreto compartido que copiar, repartir ni filtrar;
- la clave privada **nunca** salga del dispositivo;
- el servidor autorice y revoque nodos de uno en uno;
- la telemetría se pueda reenviar desde la cola de la microSD sin duplicar
  filas ni bloquear la cola por un dato inválido.

La ruta HMAC se conserva **solo en el backend** para los nodos ya desplegados.

---

## 2. Qué había y qué hay ahora

| Área | Antes | Ahora |
|---|---|---|
| Credencial | Token por colmena, copiado a mano | Par de claves ECDSA generado en el nodo |
| Firma | HMAC-SHA256 | ECDSA P-256 / SHA-256, ASN.1 DER hex |
| Canon | `METODO\nRUTA\nTS\nMAC\nNONCE\nsha256(cuerpo)` | `BEE2\n…\nID_DISPOSITIVO\nsha256(cuerpo)` |
| Alta | Usuario pega el token en el portal | Prueba de posesión (`BEE2-POP`) + código de 8 caracteres |
| Autorización | Implícita al vincular | Explícita por un usuario autenticado (`dispositivos.php`) |
| Revocación | `colmena.token_revocado` | `dispositivo.estado = 'revocado'` |
| Deduplicación | `UNIQUE(id_sensor, seq)` | `lectura.id_medicion` (128 bits) + `UNIQUE` |
| Acuse | `ok_total` por lote | `resultados[]` por lectura + regla de avance de cola |
| Portal | Campo de token obligatorio | Sin token: muestra id y código de alta |

---

## 3. Cambios por archivo

### 3.1 Base de datos

| Archivo | Cambio |
|---|---|
| `database/migrations/005_identidad_dispositivo.sql` | Nuevas tablas `dispositivo` y `nonce_dispositivo` |
| `database/migrations/006_identificador_medicion.sql` | `lectura.id_medicion` + índice único |
| `database/schema.sql` | Incorpora ambas migraciones al esquema limpio |
| `tools/migrar-pruebas.php` | Aplicación idempotente de 001–006 sobre la BD de pruebas |

`dispositivo` guarda identificador, MAC, nombre, PEM público, huella SHA-256,
estado (`pendiente`/`autorizado`/`revocado`), colmena, usuario, **hash** del
código de alta, caducidad, intentos y marcas de tiempo. El código en claro
nunca se almacena.

### 3.2 Backend

| Archivo | Cambio |
|---|---|
| `includes/dispositivo.php` | Validación de PEM, huella, cadenas, verificación ECDSA, anti-replay, alta con PoP, código, aprobación, revocación, control de frecuencia |
| `api/enroll.php` | Alta con clave pública + PoP; deja el nodo pendiente |
| `api/enroll_estado.php` | Estado del nodo firmado con ECDSA (admite `pendiente`) |
| `api/ingest.php` | Doble esquema (ECDSA / HMAC), `id_medicion`, `resultados[]`, `motivo` |
| `includes/firma.php` | Sin cambios funcionales: HMAC heredado |

### 3.3 Firmware

| Archivo | Cambio |
|---|---|
| `beestation_node/identidad_ecdsa.h` | **Nuevo**: generación/carga de clave, firma DER, cadenas `BEE2`/`BEE2-POP`, código de alta, NVS |
| `beestation_node/util_hex.h` | **Nuevo**: hexadecimal en mayúsculas/minúsculas |
| `beestation_node/beestation_node.ino` | Cableado a ECDSA; eliminadas `firmaHmac`, `cadenaFirma`, `sha256Hex`, `validarTokenConServidor` y `mbedtls/md.h`; alta y sondeo cada 60 s; CSV de 9 columnas; firma de la cola; acuse por lote |
| `beestation_node/index_html.h` | Formulario sin token; muestra id y código de alta |

### 3.4 Panel y páginas

| Archivo | Cambio |
|---|---|
| `dispositivos.php` | Sección *Nodos IoT y Autorización de Dispositivos*; alta por código, revocación; la columna de la colmena muestra el nodo asignado (no el token) |
| `conectar_dispositivo.php` | Sin token en pantalla; diagnóstico basado en nodos pendientes; animaciones respetan `prefers-reduced-motion` |

### 3.5 Pruebas

| Archivo | Qué comprueba |
|---|---|
| `tests/php/ecdsa_flujo_test.php` | Flujo E2E del servidor (49 comprobaciones) |
| `tests/php/ecdsa_interop_test.php` + `tests/host/ecdsa_interop.c` | Mbed TLS ↔ OpenSSL real, bidireccional (18 comprobaciones) |
| `tests/php/interop_firmware_test.php` | Cadenas canónicas HMAC y ECDSA firmware ↔ servidor |

---

## 4. Decisiones y por qué

1. **Firma DER en hex y clave pública SPKI PEM.** Es lo que acepta
   `openssl_verify`/`openssl_pkey_get_public` en PHP. La huella es el SHA-256 de
   los **bytes exactos** del PEM, no de una versión re-serializada.
2. **Hex siempre en minúsculas.** El firmware usaba `hexMayusculas` para el
   SHA-256 y el servidor `hash()` (minúsculas): las firmas HMAC antiguas
   **nunca validaron**. Se unificó en `hexMinusculas()` y se corrigió la prueba
   de interoperabilidad, que hasta ahora pasaba por comparar contra su propia
   réplica. *Es un bug preexistente, no introducido en esta fase.*
3. **`mbedtls_pk_ec()` por valor.** Verificado con el compilador tanto en Mbed
   TLS 3.6.6 como en el fork del core ESP32 3.3.12.
4. **`mbedtls_pk_parse_key(..., keylen + 1)`.** El PEM se parsea con su
   terminador nulo; sin el `+1` falla de forma intermitente.
5. **Un `id_medicion` por medición, seis derivados.** El nodo genera 128 bits al
   tomar la lectura; los seis sensores comparten los 30 primeros dígitos y
   cambian los dos últimos (`00`…`05`). Así cada fila es única (120 bits) sin
   almacenar seis identificadores en la microSD.
6. **Avance de la cola.** Se avanza si `ok_total` es `true` **o** si el servidor
   atendió las seis lecturas de todas las filas y alguna fue `rechazada`. Un
   dato permanentemente inválido no debe bloquear la telemetría futura.
7. **Modo de BD aislada.** `BEE_DB_AISLADA=1` ignora `config/db.local.php`,
   exige que el nombre termine en `_prueba`/`_test` y aborta si no. Todas las
   pruebas se ejecutaron contra MariaDB 10.4.32 en el puerto **3399**, nunca
   contra la base de XAMPP.
8. **`min_spiffs` como tabla de particiones.** El firmware no usa SPIFFS ni
   LittleFS; con la tabla por defecto el binario queda al 98 %. Ver §6.

---

## 5. Seguridad

- La clave privada no se imprime, no se envía y no se versiona. El panel solo
  muestra id corto, MAC, estado y huella.
- El código de alta se guarda como hash, caduca a los 30 minutos y admite
  **5 intentos**; los fallos se cuentan incluso cuando el código no existe
  (mediante `dispositivoLocalizarParaIntento`), sin revelar si era válido.
- Anti-replay por `UNIQUE(identificador, nonce)` y ventana de ±300 s.
- El nonce y el timestamp van **dentro** de la firma; el SHA-256 del cuerpo
  impide manipular la carga.
- Un `401` en la ingesta obliga al nodo a reconsumultar su autorización.
- El dump `database/beestation_sena.sql` **deja de versionarse**: contenía
  hashes de contraseñas, tokens de vinculación e intentos con tokens en claro.
  Sigue en disco para el entorno local, pero entra en `.gitignore`.

> **Acción pendiente del operador:** ese dump ya estaba en el historial de git.
> Conviene **rotar** las contraseñas de los usuarios de demostración y
> regenerar los tokens de las colmenas existentes.

---

## 6. Medición de flash y RAM

Placa: `esp32:esp32:esp32` · `arduino-cli` 1.5.1 · core ESP32 3.3.12.

| Versión | Flash | % (1.310.720 B) | RAM global |
|---|---|---|---|
| Antes de la Fase 2 (HMAC) | 1.289.876 B | 98 % | 53.872 B |
| Módulo ECDSA presente, sin cablear | 1.289.976 B | 98 % | 53.936 B |
| **Fase 2 cableada (tabla por defecto)** | **1.297.436 B** | **98 %** | **57.032 B** |
| **Fase 2 cableada (`min_spiffs`)** | **1.297.468 B** | **65 %** | **57.032 B** |

- Coste neto del cambio: **+7.560 B de flash** y **+3.160 B de RAM** respecto a
  la base (el código HMAC eliminado compensa parte del coste).
- Con la tabla por defecto quedan solo ~13,0 KB libres: no hay margen para
  crecer ni para un OTA cómodo. Por eso se adopta **`PartitionScheme=min_spiffs`**
  (1,9 MB de APP, 128 KB de SPIFFS), que deja ~653 KB libres.

### Reflash obligatorio de la tabla de particiones

Cambiar de esquema **exige grabar la tabla de particiones por USB una vez**:

```bash
arduino-cli compile --fqbn esp32:esp32:esp32:PartitionScheme=min_spiffs beestation_node
arduino-cli upload  --fqbn esp32:esp32:esp32:PartitionScheme=min_spiffs -p COMx beestation_node
```

La partición NVS (offset `0x9000`) no cambia, así que **la identidad del nodo
se conserva**; el nodo no necesita volver a darse de alta. Los OTA posteriores
deben compilarse con el mismo `PartitionScheme`.

---

## 7. Resultados de las pruebas

| Prueba | Resultado |
|---|---|
| `tests/php/ecdsa_flujo_test.php` | **49/49 OK** |
| `tests/php/ecdsa_interop_test.php` (Mbed TLS ↔ OpenSSL) | **18/18 OK** |
| `tests/php/interop_firmware_test.php` (cadenas HMAC + ECDSA) | **OK** |
| Importación de `database/schema.sql` en BD vacía | **OK** (14 tablas) |
| Migraciones 001–006 (idempotentes, 2ª pasada) | **OK** |
| Compilación del firmware (`exit=0`) | **OK** (ambas tablas) |
| Carga de `login.php`, `dispositivos.php`, `conectar_dispositivo.php` | **OK** (200 / 302 a login) |

El flujo E2E cubre: alta con PoP, rechazo de firma inválida y nonce repetido,
nodo pendiente sin permiso de ingesta, autorización desde el panel, colmena
ajena rechazada, código consumido, límite de intentos, telemetría firmada,
idempotencia por `id_medicion`, anti-replay, manipulación del cuerpo, timestamp
fuera de ventana, consulta de estado, revocación individual y convivencia con
el esquema HMAC heredado.

---

## 8. Diferencias con las copias de solo lectura

`C:\xampp\htdocs\BeeStation_Sena` y
`C:\Users\andre\AppData\Local\Temp\BeeStation_Sena_phase2_work` **no se
modificaron**. El conjunto de archivos del repo es un superconjunto del de
XAMPP; no hay ningún archivo presente solo en la copia de XAMPP. Las
diferencias de contenido son las de §3.

Además, `C:\xampp\htdocs\BeeStation_Sena` conserva el dump
`database/beestation_sena.sql` (ya no versionado).

---

## 9. Limitaciones y trabajo pendiente

1. **No se ha flasheado un ESP32 físico.** La compilación y la prueba cruzada
   Mbed TLS ↔ OpenSSL están verificadas, pero la ejecución en placa (NVS,
   `esp_random`, tiempos de firma, consumo) no se ha medido.
2. **El reflash de la tabla de particiones** debe hacerse por USB; no se puede
   hacer por OTA desde la tabla anterior.
3. **El panel no se ha probado con un usuario real en navegador**: solo se
   verificó que las páginas cargan y que el backend responde. Falta un recorrido
   visual completo (autorizar, revocar, estados vacíos, móvil).
4. **Rotación de credenciales históricas** (§5).
5. La ruta HMAC sigue viva en el backend; retirarla cuando ya no queden nodos
   con el firmware anterior.

---

## 10. Puesta en marcha

1. Aplicar migraciones 005 y 006 (o importar `database/schema.sql` en una base
   nueva).
2. Desplegar el backend (`api/`, `includes/`, `dispositivos.php`).
3. Compilar y flashear el firmware con `PartitionScheme=min_spiffs` por USB.
4. Encender el nodo, configurar la red en el portal cautivo y anotar el código
   de alta del monitor serie.
5. Autorizarlo en *Dispositivos → Nodos IoT* y comprobar que la colmena pasa a
   **En línea**.
6. Verificar en el monitor serie `[AUTH] OK: nodo AUTORIZADO` y el envío de la
   primera telemetría.
