# Auditoría REA — BeeStation_Sena

**Fecha:** 2026-10-29
**Herramienta:** REA 6.5.0 (`rea analyze-javascript-application`, CLI)
**Alcance:** sistema completo — artefactos desplegados, código de terceros, firmware ESP32 y base de datos.
**Commit auditado:** `bb4490b` (`main` == `origin/main`)

---

## 1. Método

REA se aplicó a los **artefactos entregables** (lo que realmente se ejecuta), no al árbol de fuentes PHP, que se revisó con herramientas de repositorio según la guía de la propia skill:

| Objetivo | Ruta | Ruta REA |
|---|---|---|
| JS de terceros minificado | `assets/vendor/` | `analyze-javascript-application` |
| Firmware ESP32 compilado | `beestation_node/build/esp32.esp32.esp32/` | inventario + `strings` (nativo bloqueado, ver §5) |
| Copia desplegada | `/opt/lampp/htdocs/BeeStation_Sena` | comparación de integridad contra el repo |
| Base de datos | `beestation_sena` | `scripts/verificar-esquema.php` |
| Código PHP | repo | revisión estática + suite de pruebas |

Evidencia REA principal: `ev_9bccb2d69462a348b622bf2ee3421ae6ae7f323af10f8f97a61ce1087560659b`
(digest del artefacto `assets/vendor`: `f16e3e8f007362454033496c7fdbab2286634f2595185033c4970e17e37e7d5a`).

---

## 2. Hallazgos

### H1 — Copia desplegada desactualizada (crítico, corregido)

`/opt/lampp/htdocs/BeeStation_Sena/api/ingest.php` era del **23-oct** y no contenía las mitigaciones de seguridad (límite de cuerpo de 1 MiB y validación de rangos `ts`/`seq`). El sistema en ejecución no estaba aplicando el código auditado.

- **Evidencia:** `diff` entre repo y `htdocs`; el archivo desplegado carecía del bloque de `$maxBytesCuerpo`.
- **Acción:** copia desplegada sincronizada; verificado con `diff -q` sin diferencias.

### H2 — Bloque duplicado en `api/ingest.php` (medio, corregido)

Residuo de la resolución del merge: la asignación de `$id_colmena` / `$colmena` aparecía dos veces. Funcionalmente inocuo (asignación idempotente), pero es código muerto que confunde en una auditoría.

- **Acción:** eliminado el bloque repetido; `php -l` sin errores y suite completa en verde.

### H3 — Protección de credenciales ausente en el repo (medio, corregido)

El `.htaccess` del repositorio había perdido el bloque `<FilesMatch "^(db\.php|db\.local\.php|db\.example\.php)$"> Require all denied`, presente en la copia desplegada. Un despliegue desde el repo habría eliminado esa defensa en profundidad.

- **Acción:** bloque restaurado en el repo (la copia desplegada ya lo tenía).

### H4 — JS de terceros íntegro (informativo, correcto)

REA analizó `assets/vendor/` como aplicación JavaScript inerte:

- 2 archivos, 563 685 bytes, **0 fallos de parseo**, 0 contradicciones de integridad.
- 33 814 nodos semánticos, 1 417 funciones, 0 APIs de Electron/IPC (no aplica).
- Sin `eval`, `new Function`, `fetch`, `XMLHttpRequest`, `WebSocket`, `sendBeacon`, `atob`, `document.cookie` ni `localStorage`.
- Únicas URLs: enlaces de documentación (`chartjs.org`, `jsdelivr.com`, `github.com/kurkle/color`) y el namespace SVG `http://www.w3.org/2000/svg`.

**Verificación de cadena de suministro:** los SHA-256 coinciden byte a byte con las publicaciones oficiales:

| Archivo | SHA-256 | Upstream |
|---|---|---|
| `chart.umd.min.js` | `206b6e8b…f5d0e` | `cdn.jsdelivr.net/npm/chart.js@4.4.7` |
| `lucide.min.js` | `34116928…42210e` | `unpkg.com/lucide@0.468.0` |

Los bundles son locales: el panel no necesita salida a internet.

### H5 — Firmware 3.2.1 coherente con la fuente (informativo, correcto)

- El binario declara `3.2.1`, igual que `FIRMWARE_VERSION` en `beestation_node.ino`.
- Endpoints presentes: `/BeeStation_Sena/api/ingest.php`, `/enroll.php`, `/enroll_estado.php`, `/ping.php`; hosts locales (`192.168.4.1`, `beestation.local`). **Ningún host externo.**
- Cabeceras de firma ECDSA presentes (`X-BeeStation-Dispositivo/Mac/Timestamp/Nonce/Firma`).
- **Sin secretos embebidos:** no hay contraseña WiFi ni API keys; `%AP_PASSWORD%` es una plantilla que se rellena desde la NVS en tiempo de ejecución.
- `tests/php/interop_firmware_test.php`: las cadenas canónicas del firmware y del servidor coinciden.

### H6 — Análisis nativo del `.elf` bloqueado (pendiente)

`beestation_node.ino.elf` (Xtensa, 16 MB) no pudo desensamblarse: el entorno no tiene Ghidra/IDA, ni `binwalk`/`unblob`, ni `pwntools`, que son los proveedores que exigen `open_binary`, `inspect_firmware_regions` e `inspect_binary_layout`. La revisión del firmware se limita por tanto a cadenas, cabeceras y coherencia con la fuente: **no prueba ausencia de código malicioso a nivel de instrucciones**.

---

## 3. Verificaciones ejecutadas

| Verificación | Resultado |
|---|---|
| `scripts/verificar-esquema.php` | **OK** — `beestation_sena` coincide con `schema.sql` (173 definiciones) |
| `tests/php/firma_test.php` | **OK** |
| `tests/php/ecdsa_flujo_test.php` | **49 pruebas, 0 fallos** |
| `tests/php/interop_firmware_test.php` | **OK** (cadenas canónicas idénticas) |
| `tests/php/ecdsa_interop_test.php` | Omitida: falta Mbed TLS para host (dependencia de entorno) |
| `dashboard.php` sin sesión | `302` (redirige a login, correcto) |
| `api/ingest.php` por GET | `405` (rechaza método, correcto) |
| `schema.sql` cargado dos veces | Sin errores (idempotente) |
| Bases de datos en la instancia | Solo `beestation_sena` |

---

## 4. Estado de la base de datos

- Esquema reconciliado con `schema.sql`: tipos, nulabilidad, PK `id_intento`, índices y claves foráneas idénticos.
- `sensor` protegida con `UNIQUE (id_colmena, tipo)` para impedir duplicados por colmena.
- Datos reales conservados: 1 apiario, 2 usuarios, 9 variables bioclimáticas; el resto de tablas vacías (sin datos de demostración).
- Respaldos: `~/backups/beestation_sena-backup-20261029-085008.sql` (pre-limpieza) y `~/backups/beestation_sena-pre-reconciliacion-20261029-085950.sql` (pre-reconciliación).

---

## 5. Limitaciones de esta auditoría

1. Sin desensamblado del firmware (H6): falta proveedor nativo.
2. REA analiza JavaScript **inerte**: no se ejecutó el panel en navegador; el comportamiento en runtime no se observó.
3. La revisión PHP es estática más la suite de pruebas; no incluye pruebas de carga ni de penetración sobre HTTP real.
4. `ecdsa_interop_test.php` no se ejecutó por falta de Mbed TLS para host.

---

## 7. Re-verificación post-cambios (2026-10-29, commit `d956836`)

- **JS de terceros:** SHA-256 sin cambios, idénticos a las publicaciones oficiales de Chart.js 4.4.7 y Lucide 0.468.0.
- **Copia desplegada:** `diff -rq` entre repo y `htdocs` sin diferencias de contenido (incluye `ingest.php` y `enroll.php` con las mitigaciones).
- **Firmware:** binario con `3.2.1`, endpoints y cabeceras esperadas, sin secretos embebidos.
- **Base de datos:** `scripts/verificar-esquema.php` → OK (173 definiciones). Una sola base (`beestation_sena`); respaldos en `~/backups/`.
- **Prueba de carga (Apache Bench):** `login.php` GET 200 req/20 conc → 1 477 req/s, 0 fallos; `api/ingest.php` POST 100 req/20 conc → 1 884 req/s, 0 fallos. El servidor no se saturó bajo carga moderada.
- **Prueba multi-modelo (Orca):** se lanzaron 3 agentes con proveedores distintos (Gemini, Claude, Codex) en terminales paralelas con prompts de tráfico HTTP y entradas malformadas. Los terminales se cerraron antes de que los agentes terminaran de responder (agentes interactivos, no por lotes), por lo que la evidencia cualitativa no se recogió; la prueba objetiva con `ab` sustituye la conclusión de saturación.
- **Credencial:** `admin@beestation.io` con contraseña `senasoft2026` (hash bcrypt `$2y$12$`), login verificado end-to-end. **Pendiente:** rate limiting en `login.php` (H5) y cambio por una contraseña fuerte antes de producción.

1. **Usuario MySQL dedicado** (no `root`), con contraseña, y `APP_ENV=production`; `config/db.php` ya rechaza `root` o contraseña vacía en producción.
2. **Ejecutar `php scripts/verificar-esquema.php` en cada despliegue** y antes de migrar datos reales.
3. **Desplegar siempre desde `main`** y comparar la copia publicada con el repo (H1 se detectó así).
4. **HTTPS** en el servidor final: la firma ECDSA protege la integridad del payload, pero el token de sesión viaja en claro sin TLS.
5. Si se requiere análisis nativo del firmware, instalar Ghidra o `binwalk` y repetir H6.
