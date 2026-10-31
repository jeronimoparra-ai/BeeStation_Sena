# Informe de Misión 9.1 — Activación de skills y auditorías consolidadas

- **Fecha:** 2026-10-30
- **Repositorio:** raíz local del repositorio BeeStation_Sena, rama `main`, HEAD `8cee2c5`
- **Modo:** solo lectura. Sin cambios al código en esta misión (salvo este informe
  y la actualización del estado de auditoría). Sin commits, sin push, sin migraciones,
  sin acceso a Hostinger, sin cambios de grants ni de datos.
- **Subagentes OpenCode:** se intentaron 5 invocaciones paralelas
  (`auditor-seguridad`, `auditor-php`, `auditor-firmware` + 2 `general`). **Fallo por
  límite de uso gratuito** (`Free usage limit reached`) en los 5 casos. Por eso los
  hallazgos consolidados provienen de la auditoría directa de OpenCode (lectura,
  `grep`, `php -l`, inspección de archivos), no de subagentes. Se declara
  explícitamente para no atribuir trabajo no realizado.

---

## 1. Skills solicitadas y verificadas

Se buscó cada skill en todos los roots conocidos (`.opencode/skills/`,
`~/.config/opencode/skills/`, `.agents/skills/`, `~/.agents/skills/`,
`.claude/skills/`, `~/.claude/skills/`) y en todo `/home/andresdev` (máx. 9 niveles)
por nombre de directorio y por `name:` en el frontmatter de `SKILL.md`.

| Skill solicitada | Archivo SKILL.md encontrado | Disponible |
|---|---|---|
| `find-skills` | No | **No** |
| `security-and-hardening` | No | **No** |
| `security-audit` | No | **No** |
| `vibe-code-cleanup` | No | **No** |
| `find-dead-code` | No | **No** |

**Conclusión:** ninguna de las cinco está instalada. No se ejecutó ningún
instalador, script remoto ni `npx skills add` (prohibido sin autorización).

Referencias inspeccionadas para confirmar la ausencia:

- catálogo de plugins de Hermes (fuera del repositorio) — es un
  **plugin de Hermes** (comunidad, `dafka007`), no una skill de OpenCode; provee un
  tool `security_audit` vía Gitleaks/OSV/Semgrep. No se instaló ni se invocó.
- documentación de ejemplo de Gemini CLI (fuera del repositorio) — documento de ejemplo con un
  `security-auditor`; no es una skill instalada.

### Equivalencias funcionales usadas (sin instalar nada)

| Responsabilidad pedida | Skill real disponible y cargada |
|---|---|
| Controles generales de seguridad web | `security-headers-config` + `security-logging-monitoring` |
| Auditoría PHP, OWASP, auth, authz, API, datos | `secure-code-review` + `owasp-top10` + `api-security-testing` (cargada en Misión 9) |
| Limpieza conservadora de deuda técnica | `code-reviewer` (solo lectura) + revisión manual |
| Inventario de código muerto (solo lectura) | `code-reviewer` + inventario manual con evidencia |
| Descubrimiento de capacidades | `find-skills` no existe; se hizo búsqueda manual en los roots |

Limitación documentada: no se aplicaron comprobaciones exclusivas de TYPO3 ni de
frameworks; todas son específicas de PHP nativo con PDO.

---

## 2. Subagentes utilizados

| Agente | Resultado |
|---|---|
| `auditor-seguridad` | Falló (límite gratuito) — no hay informe |
| `auditor-php` | Falló (límite gratuito) — no hay informe |
| `auditor-firmware` | Falló (límite gratuito) — no hay informe |
| `auditor-datos` | No invocado en esta misión (ya auditó 010 en Misión 1) |
| `general` (×2) | Falló (límite gratuito) — no hay informe |

Los hallazgos se consolidan desde la auditoría directa de OpenCode. No se atribuye
ningún resultado a un subagente.

---

## 3. Hallazgos por especialidad

### 3.1 Seguridad (OWASP / auth / authz / API)

| ID | Severidad | Archivo:línea | Hallazgo | Estado |
|---|---|---|---|---|
| S-01 | ALTA | `beestation_verify` (BD) | Cuenta de pruebas con `ALL PRIVILEGES ON *.*` y acceso a `beestation_sena` | Abierto (no autorizado a cambiar) |
| S-02 | MEDIA | `login.php` | Sin rate limiting en login (sí en registro). Brute-force posible | Abierto |
| S-03 | MEDIA | `.htaccess` | Sin CSP. Sin HSTS (correcto mientras HTTPS no esté verificado). Sin `Permissions-Policy` para cámara/micrófono en el código (sí en .htaccess) | Abierto / parcial |
| S-04 | MEDIA | `includes/auth.php:1-16` | Cookie `Secure` depende de `$_SERVER['HTTPS']`; detrás de proxy TLS puede quedar en `false` | Razonado |
| S-05 | MEDIA | `config/db.php` | `root`/clave vacía permitidos fuera de producción (solo bloqueados con `APP_ENV=production`) | Aceptado (dev) |
| S-06 | BAJA | `registro.php` | Enumeración de usuarios: mensaje "Ya existe una cuenta…" permite confirmar correos registrados | Razonado |
| S-07 | BAJA | `api/ping.php` | Endpoint público de descubrimiento (intencional, solo GET, sin datos) | Aceptado |
| S-08 | ALTA (IoT) | `beestation_node/beestation_node.ino:845,851,1483,1502,1878` | Telemetría por **HTTP plano** (`WiFiClient`), sin TLS ni verificación de certificado | Abierto (pendiente coordinación firmware↔HTTPS) |
| S-09 | MEDIA | `config/db.php` (antes) | `display_errors` no forzado a Off en producción | **Corregido** en Misión 9 (L-02) |
| S-10 | MEDIA | `.htaccess` (antes) | `includes/`, `tasks/`, `.opencode/`, etc. servibles por HTTP | **Corregido** en Misión 9 (L-01) |
| S-11 | BAJA | `includes/password.php` | Mínimo 12 caracteres Unicode, máximo 72 bytes (límite de bcrypt) — correcto | OK |
| S-12 | BAJA | `conectar_dispositivo.php` | Honeypot y rate limiting por intentos no presentes en todos los formularios | Razonado |

**Controles verificados como correctos:**

- CSRF en todos los POST de negocio (`login`, `registro`, `dispositivos`, `seleccionar_colmena`).
- `session_regenerate_id(true)` tras login; `session.use_strict_mode=1`, `use_only_cookies=1`, `HttpOnly`, `SameSite=Lax`.
- Todas las consultas de colmena filtran por `a.id_usuario = ?` (`functions.php:140-186`, `dispositivos.php:89`).
- PDO con prepared statements en el código revisado; sin concatenación de entrada en SQL.
- `htmlspecialchars` usado de forma consistente (97 ocurrencias).
- ECDSA P-256 para el nodo, clave privada en NVS, nunca en el repo (verificado).
- Nonce anti-replay con `UNIQUE(mac, nonce)` atómico y limpieza a 10 min (`firma.php:148-158`).
- Cabeceras de seguridad presentes en `.htaccess`: `nosniff`, `X-Frame-Options SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`, `X-Permitted-Cross-Domain-Policies`, `X-Powered-By` eliminado, `Cache-Control: no-store` para `.php`, `X-Robots-Tag`.
- `api/.htaccess` fuerza `display_errors off` / `log_errors on`.

### 3.2 Arquitectura PHP y dependencias

| ID | Severidad | Archivo:línea | Hallazgo |
|---|---|---|---|
| A-01 | BAJA | `scripts/verificar-esquema.php:86` y `tools/migrar-pruebas.php:93` | `sqlSentencias()` definida en dos CLI separados (no se incluyen juntos → sin redeclaración fatal) |
| A-02 | BAJA | `tests/php/*` | `env()`, `ok()`, `titulo()` duplicadas entre tests (ejecución independiente) |
| A-03 | MEDIA | `includes/auth.php:14-16` | `is_active()` e `intentarLogin()` viven en `functions.php` para evitar redeclaraciones — correcto, documentado |
| A-04 | MEDIA | `config/db.php` | Valores por defecto `root`/`''` solo fuera de producción; en producción se exige `APP_ENV=production` |
| A-05 | BAJA | `includes/functions.php:1-40` | `BEESTATION_API_CONTEXT` controla redirección a login y errores — diseño correcto |
| A-06 | BAJA | `includes/functions.php:29-30` | `display_errors=0` en contexto API, independiente del `.htaccess` |
| A-07 | BAJA | (todo el repo) | Sin `composer.json` ni `vendor/` PHP: dependencias front-end vendorizadas (Chart.js 4.4.7 MIT, Lucide 0.468.0 ISC) |
| A-08 | BAJA | `assets/vendor/README.md` | Dependencias vendorizadas documentadas con origen y licencia; sin CDN externo (funciona sin internet) |
| A-09 | MEDIA | `tools/`, `scripts/` | Mezcla de scripts de diagnóstico, migración y sincronización de Windows (`.bat`, `.ps1`) |
| A-10 | BAJA | `tests/php/*` | 6 tests PHP + 2 tests C; requieren BD de pruebas para algunos |
| A-11 | BAJA | `config/db.example.php` | Plantilla versionada (correcto); `config/db.local.php` no existe en el repo (correcto) |

**Fortalezas:** separación clara `pages / includes / api / tools / scripts / tests`; centralización de hashing en `includes/password.php`; control de acceso por propietario en SQL; manejo de errores diferenciado web/API.

### 3.3 Código muerto y limpieza (solo lectura, candidatos)

| Elemento | Evidencia | Clasificación |
|---|---|---|
| `energia.php` | 0 referencias desde PHP/JS/CSS (excluido a sí mismo); no está en `includes/sidebar.php` | (b) No referenciado pero funcional: página honesta de módulo no instalado. **Conservar** (funcionalidad declarada, no inventa datos) |
| `js/charts.js` | 0 referencias; stub documentado de 3 líneas | (b)/(c) Retirable sin impacto, pero su eliminación obliga a quitar el `<script src>` si existiera. **Conservar por compatibilidad documentada** o retirar en lote con verificación |
| `scripts/insertar_demo_seguro.php` | Deshabilitado; no conecta a BD | (b) Conservar: evidencia de limpieza previa, sin efecto |
| `database/migracion-2026-10-17-limpiar-duplicados.sql` | 0 referencias en runners; contiene `DELETE` y `USE beestation_sena` | (c) Candidato a archivar fuera del paquete; **no ejecutar**. Historial de un solo uso |
| `tools/diagnostico-bd.php` | Solo CLI (`PHP_SAPI !== 'cli'` → 403) | (b) Conservar en repo, excluir del paquete |
| `scripts/diagnostico-series-temporales.php` | Consulta directa sin filtro de usuario | (c) Candidato a retirar o proteger: no filtra por propietario; solo diagnóstico |
| `tools/crear-usuario-inicial.php`, `restablecer-clave.php` | CLI, requieren env | (b) Conservar: herramientas operativas de despliegue |
| `INFORME-FUNCIONAMIENTO.docx`, `guia-configuracion-beestation.docx` | Binarios en la raíz | (c) Candidato a mover a `docs/` o excluir del paquete (ya bloqueados por `.htaccess`) |
| `.htaccess` reglas | Bloquean `.docx` por extensión | OK |
| `beestation_node/` | 6 archivos (firmware) | (a) Usado: conservar fuera del docroot |
| `assets/vendor/` | Referenciado | (a) Usado |
| `css/style.css`, `redesign.css`, `style-premium.css` | Todos referenciados | (a) Usado (posible duplicación de reglas, no verificada) |
| `tests/` | 8 archivos | (a) Usado: pruebas legítimas, conservar |
| Variables/funciones no usadas | No se detectaron funciones huérfanas demostrables en `includes/` (todas se incluyen) | (d) No verificable sin análisis estático formal |

**Nada se marcó muerto solo por no aparecer en una búsqueda simple:** se revisó contenido y referencias (PHP, JS, CSS, firmware).

### 3.4 Despliegue y configuración Apache/PHP

| ID | Severidad | Archivo:línea | Hallazgo |
|---|---|---|---|
| D-01 | ALTA | `.htaccess:35,38` | Tras la corrección, `includes/`, `tasks/`, `.opencode/`, `.vscode/`, `.idea/`, `.agents/`, `.claude/` quedan bloqueados. **Verificado en configuración**, no en servidor real |
| D-02 | MEDIA | `.htaccess` | Protección depende de `AllowOverride` y de `mod_rewrite`/`mod_headers`. **No verificado en Hostinger** |
| D-03 | MEDIA | `.htaccess` | No hay CSP ni HSTS (HSTS solo cuando HTTPS esté confirmado) |
| D-04 | MEDIA | `api/.htaccess` | Solo fuerza `display_errors off` para `mod_php7`/`mod_php`; si el hosting usa PHP-FPM, las directivas `php_flag` no aplican (se confía en `config/db.php` y `php.ini`) |
| D-05 | BAJA | `.htaccess:44` | `FilesMatch` bloquea por extensión: `sql, md, log, ini, env, bak, orig, old, swp, dist, example, local, bak1, log1` — cubre `.sql`, `.md`, `.docx` (por nombre), `.env` |
| D-06 | BAJA | (repo) | Sin archivos `.sql.gz`, `.bak`, `.log`, `.dump` ni directorios de cache/logs/uploads en el repo |
| D-07 | MEDIA | `config/db.php` | `APP_ENV=production` es la única palanca para modo seguro; si no se define, la app arranca en modo desarrollo |
| D-08 | MEDIA | `config/db.php` | Valores por defecto inseguros fuera de producción (`root`/clave vacía) |
| D-09 | BAJA | (repo) | Docroot actual = raíz del repo; alternativa `public/` no implementada (requeriría mover rutas) |
| D-10 | MEDIA | (todo) | Sin procedimiento de respaldo/reversión probado; documentado en el manifiesto |
| D-11 | BAJA | `php_value post_max_size 4M` | Límite de cuerpo en `.htaccess`; la API además limita a 1 MiB en código |
| D-12 | BAJA | `beestation_node/` | Firmware fuera del docroot; bloqueado por `.htaccess` |

### 3.5 API y firmware ESP32

| ID | Severidad | Archivo:línea | Hallazgo |
|---|---|---|---|
| F-01 | ALTA | `beestation_node/beestation_node.ino:845,851,1483,1502,1878` | Telemetría por HTTP plano; sin TLS. **No apto para endpoint público** |
| F-02 | MEDIA | `beestation_node/beestation_node.ino` | Sin `setCACert`/`setFingerprint` (no hay TLS que verificar) |
| F-03 | BAJA | `includes/dispositivo.php:96-130` | Canonical ECDSA coincide con firmware (`BEE2\n`, `strtoupper(metodo)`, `strtolower(id)`, `strtolower(nonce)`, sha256 del cuerpo) |
| F-04 | BAJA | `includes/firma.php:57-62` | Canonical HMAC (heredado) con `strtoupper(metodo)`, ruta, ts, mac, nonce, sha256(cuerpo) |
| F-05 | BAJA | `api/ingest.php:1-140` | Doble esquema (ECDSA + HMAC heredado) conviven; selección por cabecera `X-BeeStation-Dispositivo` |
| F-06 | BAJA | `includes/firma.php:50,148-158` | Nonce 128 bits, formato `[0-9a-f]{32}`, anti-replay atómico por `UNIQUE(mac,nonce)`, limpieza a 10 min |
| F-07 | BAJA | `api/ingest.php` | Límite de cuerpo 1 MiB en código; validación de JSON y lecturas[] |
| F-08 | MEDIA | `beestation_node/` | Clave privada en NVS (no en repo) — verificado por lectura; no se pudo ejecutar el firmware |
| F-09 | BAJA | `includes/dispositivo.php` | Prueba de posesión (PoP) con `BEE2-POP\n` e `identHuella` del PEM |
| F-10 | MEDIA | `beestation_node/beestation_node.ino` | URL del backend configurable por IP; sin HTTPS. Coordinación API+firmware pendiente antes de activar backend nuevo (nonce obligatorio) |

**Compatibilidad firmware↔API:** demostrada por inspección del código (canonical strings coinciden, nonce 128 bits, MAC normalizada a mayúsculas, timestamp epoch). **No verificada** con hardware real ni red. El firmware antiguo (HMAC) sigue soportado; el nuevo (ECDSA) requiere backend actual.

---

## 4. Código que realmente puede retirarse (con evidencia)

Solo se listan elementos con prueba de no uso. **No se ha retirado ninguno.**

1. `js/charts.js` — 0 referencias; stub de 3 líneas documentado. Retirable sin impacto funcional (verificar que ningún template use `<script src="js/charts.js">`).
2. `database/migracion-2026-10-17-limpiar-duplicados.sql` — 0 referencias en runners; contiene `DELETE` y `USE beestation_sena`. Archivar fuera del paquete; **no ejecutar**.
3. `scripts/diagnostico-series-temporales.php` — consulta sin filtro de usuario; diagnóstico. Candidato a retirar o proteger.
4. `INFORME-FUNCIONAMIENTO.docx`, `guia-configuracion-beestation.docx` — binarios en la raíz; mover a `docs/` o excluir del paquete (ya bloqueados por `.htaccess`).
5. `energia.php` — 0 referencias desde la navegación, pero es una página funcional y honesta. **No retirar** sin decisión de producto; si se retira, quitar también la entrada futura del menú.

**No se retira:** `tools/`, `tests/`, `docs/`, `beestation_node/`, migraciones, `scripts/insertar_demo_seguro.php` (deshabilitado), `tools/diagnostico-bd.php` (CLI, bloqueado).

---

## 5. Código que debe preservarse

- Registro y autenticación (`login.php`, `registro.php`, `includes/auth.php`, `includes/password.php`).
- Separación por propietario (`a.id_usuario = ?` en `functions.php` y `dispositivos.php`).
- Dashboard y API (`dashboard.php`, `api/estado.php`, `api/serie.php`).
- Apiarios y colmenas (`dispositivos.php`, `seleccionar_colmena.php`).
- Telemetría ESP32 (`api/ingest.php`, `includes/firma.php`, `includes/dispositivo.php`, `beestation_node/`).
- Migraciones y schema (`database/schema.sql`, `database/migrations/001-010`).
- Pruebas automatizadas (`tests/`).
- Vendor front-end (`assets/vendor/`).

---

## 6. Riesgos de producción (consolidado)

1. `beestation_verify` con privilegios globales (S-01).
2. Telemetría por HTTP sin TLS (S-08, F-01).
3. `.htaccess` no verificado en el hosting (D-02).
4. `APP_ENV=production` no configurado aún (D-07).
5. Valores por defecto inseguros fuera de producción (D-08).
6. Sin historial de migraciones (H-04).
7. `009` incompatible con MySQL 8 (H-05).
8. Login sin rate limiting (S-02).
9. Enumeración de correos en registro (S-06).
10. Firmware↔API no verificado con hardware real (F-10).

---

## 7. Pruebas necesarias

1. Ejecución HTTP real (registro, login, dashboard, API) en entorno aislado.
2. `scripts/verificar-esquema.php` contra una copia de la base.
3. Pruebas de acceso entre dos usuarios (IDOR) con datos desechables.
4. Validación de `.htaccess` en el servidor definitivo (casos de la §13 del manifiesto).
5. Compatibilidad con la versión de PHP y el motor de base del hosting.
6. `tests/php/*` (requieren BD de pruebas y OpenSSL).
7. Prueba de telemetría extremo a extremo con un nodo real (o simulador) por HTTPS.

---

## 8. Dictamen actualizado

**Código:** LISTO CON OBSERVACIONES.
**Sistema:** NO LISTO PARA PRODUCCIÓN (pendientes de entorno: HTTPS, base productiva,
credenciales de mínimo privilegio, `.htaccess` en el hosting, TLS del firmware,
validación HTTP).

No hay defectos críticos en el código revisado; los bloqueantes son de entorno y
de infraestructura. No se declaró listo para producción nada que no tenga evidencia.

---

## 9. Estado Git

Sin cambios al código en esta misión. Este informe y la actualización del estado
de auditoría son los únicos archivos nuevos/modificados. No hay commit ni push.
Stash `stash@{0}` intacto. HEAD sigue en `8cee2c5`.
