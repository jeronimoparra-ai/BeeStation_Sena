# Informe de limpieza y preparación para producción

- **Fecha:** 2026-10-30
- **Ámbito:** repositorio completo `BeeStation_Sena` (rama `main`, HEAD `8cee2c5`).
- **Modo:** inventario en solo lectura + cambios seguros autorizados.
- **No se ha desplegado nada, no se ha creado ningún commit y no se ha tocado
  ninguna base de datos.**

---

## 1. Método

1. Inventario completo del árbol (130 archivos antes de este informe).
2. Lectura de los puntos de entrada, `includes/`, `config/`, `api/`, `.htaccess`,
   `.gitignore` y SQL.
3. Búsqueda de depuración, datos ficticios, credenciales y código muerto.
4. Verificación de inclusiones y referencias (PHP, JS, CSS, API).
5. `php -l` sobre los 46 archivos PHP.
6. Solo entonces se aplicaron cambios de bajo riesgo (§4).

No se declaró muerto ningún archivo por el simple hecho de no aparecer en una
búsqueda: se revisó su contenido y sus referencias.

---

## 2. Inventario

| Categoría | Cantidad | Observación |
|---|---|---|
| Archivos totales (sin `.git`) | 130 | Antes de este informe |
| PHP | 46 | 14 páginas raíz, 8 API, 9 includes, 6 tests, 5 tools, 4 scripts |
| SQL | 12 | `schema.sql`, `001–010`, un limpiador de un solo uso |
| Markdown | 29 | Documentación interna |
| CSS / JS | 7 / 6 | Incluye 2 dependencias vendorizadas |
| Firmware | 6 | `.ino` + 5 `.h` |
| Recursos | 9 | Imágenes, fuentes, Chart.js, Lucide |
| Config | 4 | `db.php`, `db.example.php`, `.htaccess`, `api/.htaccess` |
| Pruebas | 8 | 6 PHP + 2 C |

---

## 3. Mapa funcional

| Funcionalidad | Archivos | Dependencias | Estado | Prueba |
|---|---|---|---|---|
| Registro público | `registro.php` | `includes/auth.php`, `functions.php` (`registrarUsuario`), `password.php`; tabla `usuario` | Implementado, con CSRF, honeypot y límite de 5 intentos/15 min | Revisión estática + `php -l`; **no probado por HTTP** |
| Inicio de sesión | `login.php` | `intentarLogin`, `password_verify`, `session_regenerate_id(true)` | Implementado | Estático; **no probado por HTTP** |
| Cierre de sesión | `logout.php` | `includes/auth.php` | Implementado | Estático |
| Entrada | `index.php` | sesión | Redirige a dashboard o login | Estático |
| Dashboard | `dashboard.php`, `includes/header.php`, `footer.php`, `sidebar.php`, `js/actualizar-dashboard.js` | `api/estado.php` cada 10 s, Chart.js | Implementado; estado vacío si no hay lecturas | Estático |
| Apiarios y colmenas | `dispositivos.php` | `includes/dispositivo.php`; tablas `apiario`, `colmena` | Implementado (crear, vincular, revocar) | Estático |
| Colmena activa | `seleccionar_colmena.php` | POST + CSRF + verificación de propiedad | Implementado (evita IDOR) | Estático |
| Sensores | `sensores.php` | `sensoresConEstado`, `tiposSensorNodoBasico` | Implementado; oculta tipos no instalados | Estático |
| Actividad acústica | `acustica.php`, `js/historico-graficas.js` | `api/serie.php` | Implementado (experimental) | Estático |
| Peso | `peso.php`, `js/historico-graficas.js` | `api/serie.php`, agregación diaria | Implementado (tendencia) | Estático |
| Informe imprimible | `informe.php`, `css/informe.css`, `css/print.css` | lecturas, alertas | Implementado | Estático |
| Centro de alertas | `centro-alertas.php` | `includes/funciones_alertas.php` | Implementado | Estático |
| Conexión de nodo (portal) | `conectar_dispositivo.php` | `api/enroll.php`, `api/enroll_estado.php` | Implementado (ECDSA P-256) | Estático |
| Ingesta de telemetría | `api/ingest.php` | `includes/firma.php`, `includes/dispositivo.php`; tablas `lectura`, `sensor`, `lote_ingesta` | Implementado; sin datos de ejemplo | Estático |
| API de estado | `api/estado.php` | sesión + colmena activa | Implementado | Estático |
| API de series | `api/serie.php` | periodos por tipo | Implementado | Estático |
| API de descubrimiento | `api/ping.php` | — | Implementado (público, solo GET) | Estático |
| Validación de token heredada | `api/validate_token.php` | `includes/firma.php` | Implementado (compatibilidad) | Estático |
| Energía | `energia.php` | — | **No enlazada** desde el menú; página honesta de "no instalado", sin cifras inventadas | Revisión de contenido |

**Límites entre usuarios:** las consultas de colmena usan
`a.id_usuario = ?` y la colmena activa se revalida contra el propietario
(`includes/functions.php:140–168`, `seleccionar_colmena.php`). No se detectó
IDOR en el camino revisado.

---

## 4. Cambios realizados

| ID | Cambio | Justificación | Riesgo |
|---|---|---|---|
| L-01 | `.htaccess`: bloquear `includes/`, `tasks/`, `.opencode/`, `.vscode/`, `.idea/`, `.agents/`, `.claude/` | `includes/` se servía por HTTP y `.opencode/opencode.jsonc` también; son archivos internos | Bajo: solo afecta a HTTP, las inclusiones PHP usan el sistema de archivos |
| L-02 | `config/db.php`: con `APP_ENV=production`, forzar `display_errors=0`, `display_startup_errors=0`, `log_errors=1` y reutilizar la constante en `getPDO()` | Sin esto, un `php.ini` mal configurado podía mostrar stack traces al navegador | Bajo: en desarrollo no cambia nada |
| L-03 | `docs/ESTADO-ACTUAL-AUDITORIA.md`: redactar IP privada y corregir conteo (11 archivos / 7 entradas) | Publicación segura y exactitud | Nulo (documentación) |
| L-04 | Renombrar `2026-10-30-auditor-hermesso-010.md` → `2026-10-30-auditor-hermes-010.md` | Errata en el nombre | Nulo |
| L-05 | Redactar IP privada en el informe de Hermes | Publicación segura | Nulo (no cambia conclusiones) |
| L-06 | `docs/instalacion.md`: documentar 010, 009 y el registro manual de migraciones | Procedimiento de actualización inexistente | Nulo |
| L-07 | Crear `docs/produccion/` con manifiesto, este informe y checklist | Entregables de producción | Nulo |

**No se tocó:** lógica de negocio, autenticación, esquema, migraciones,
firmware, pruebas, datos, grants ni credenciales.

---

## 5. Elementos revisados y conservados

| Elemento | Motivo de conservación |
|---|---|
| `scripts/insertar_demo_seguro.php` | Ya está **deshabilitado**: no conecta a BD ni inserta lecturas ficticias |
| `energia.php` | Página honesta de módulo no instalado; no inventa datos. No enlazada, pero es funcionalidad declarada |
| `js/charts.js` | Stub intencional documentado, sin efectos |
| `tools/diagnostico-bd.php` | Útil en soporte; **solo CLI** y bloqueado por `.htaccess`; se excluye del paquete |
| `database/migracion-2026-10-17-limpiar-duplicados.sql` | Historial de un solo uso; **no** lo ejecuta el runner (no está en `migrations/`). Se documenta como destructivo y se excluye del paquete |
| `tests/` (6 PHP + 2 C) | Pruebas legítimas; se quedan en el repositorio |
| `docs/` y auditorías | Trazabilidad; se quedan en el repositorio, nunca en el docroot |
| `beestation_node/` | Firmware con material criptográfico; fuera del docroot |

---

## 6. Hallazgos confirmados

| ID | Hallazgo | Severidad | Estado |
|---|---|---|---|
| H-01 | `.htaccess` no cubría `includes/` ni directorios de herramientas | MEDIA | **Corregido** (L-01) |
| H-02 | `display_errors` no se forzaba a `Off` en producción | MEDIA | **Corregido** (L-02) |
| H-03 | `beestation_verify` con `ALL PRIVILEGES ON *.*` | ALTA | **Abierto** (no autorizado a cambiar) |
| H-04 | Sin tabla de historial de migraciones | MEDIA | **Abierto** (deuda de trazabilidad) |
| H-05 | `009` incompatible con MySQL 8 | ALTA (solo MySQL) | **Abierto**, documentado |
| H-06 | Script SQL de limpieza con `DELETE` y `USE beestation_sena` | MEDIA | **Documentado**; excluido del paquete |
| H-07 | Informe de Kilo no archivado en el repositorio | BAJA (proceso) | **Pendiente**: falta la transcripción íntegra |
| H-08 | `.htaccess` depende de `AllowOverride`; no verificado en Hostinger | MEDIA | **No verificado** |

## 7. Búsquedas negativas (nada encontrado)

- `var_dump`, `print_r`, `phpinfo`, `die()` de depuración: **0**.
- `console.log` / `debugger` en `js/`: **0**.
- Lecturas de sensor hardcodeadas en el camino de ejecución: **0**.
- Usuarios, apiarios o colmenas de demostración creados por la aplicación: **0**
  (`dispositivos.php` documenta que se retiró el apiario de demo).
- Credenciales en `config/db.php`: **0**.
- `composer.json` / dependencias PHP externas: **no existen**.

## 8. Pruebas ejecutadas

| Prueba | Resultado real |
|---|---|
| `php -l` sobre los 46 archivos PHP (antes de los cambios) | **46/46 sin errores de sintaxis** |
| `php -l config/db.php` (después del cambio) | Sin errores |
| Búsqueda de depuración y datos ficticios | 0 hallazgos |
| Búsqueda de credenciales en archivos versionables | 0 hallazgos |
| Referencias cruzadas PHP → JS/CSS/API | Todas resueltas salvo `js/charts.js` (stub intencional) |

## 9. Pruebas pendientes

1. Ejecución HTTP real (registro, login, dashboard, API) en un entorno de
   pruebas aislado.
2. `scripts/verificar-esquema.php` contra una copia de la base.
3. Pruebas de acceso entre dos usuarios (IDOR) con datos desechables.
4. Validación de `.htaccess` en el servidor definitivo.
5. Compatibilidad con la versión de PHP y el motor de base de datos del hosting.
6. Pruebas `tests/php/*` (requieren base de pruebas y, algunas, OpenSSL).

## 10. Riesgos abiertos

1. Cuenta de pruebas con privilegios globales (H-03).
2. Sin historial de migraciones (H-04).
3. `009` y MySQL (H-05).
4. `.htaccess` no verificado en producción (H-08).
5. Firmware ESP32 sin validación TLS contra endpoint público.
6. Sin archivo del informe de Kilo (H-07).

## 11. Conclusión

El aplicativo **no contiene datos ficticios ni código de depuración** en su
camino de ejecución. La limpieza se limitó a cerrar dos superficies reales
(`includes/` y errores PHP en producción) y a corregir documentación. No se
eliminó ninguna funcionalidad.
