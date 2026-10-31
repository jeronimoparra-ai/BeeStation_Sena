# Manifiesto de despliegue — BeeStation

- **Fecha:** 2026-10-30
- **Alcance:** paquete mínimo para ejecutar el aplicativo en producción.
- **Estado:** documento preparado; **no se ha generado ningún ZIP ni se ha subido nada** a Hostinger.
- **Base:** repositorio `BeeStation_Sena`, rama `main`, HEAD `8cee2c5`.

> Este documento distingue lo **verificado** (pruebas locales reales), lo
> **razonado** (por configuración/documentación) y lo **no verificado**
> (no hay entorno de producción disponible).

---

## 1. Árbol del paquete productivo

Solo estos elementos deben existir en el document root:

```
.htaccess                      # protección del docroot (obligatorio)
index.php                      # entrada: redirige a dashboard o login
login.php
registro.php
logout.php
seleccionar_colmena.php
dashboard.php
dispositivos.php
sensores.php
acustica.php
peso.php
energia.php
informe.php
centro-alertas.php
conectar_dispositivo.php

api/
  .htaccess
  ping.php
  estado.php
  serie.php
  ingest.php
  enroll.php
  enroll_estado.php
  validate_token.php

assets/
  bee-hero.jpg
  login-bg.jpg
  logo-beestation.png
  fonts/bricolage-grotesque-latin.woff2
  fonts/manrope-latin.woff2
  vendor/chart.umd.min.js
  vendor/lucide.min.js
  vendor/README.md

css/
  fonts.css
  style.css
  style-premium.css
  redesign.css
  login-redesign.css
  informe.css
  print.css

js/
  app.js
  actualizar-dashboard.js
  historico-graficas.js
  charts.js

includes/                      # requerido por PHP, NO accesible por HTTP
  auth.php
  functions.php
  password.php
  firma.php
  dispositivo.php
  funciones_alertas.php
  header.php
  footer.php
  sidebar.php

config/
  db.php                       # requerido; config/ está bloqueado por .htaccess
  db.local.php                 # SOLO en el servidor, nunca en el repositorio
```

**Nota:** `includes/` y `config/` deben existir en el servidor porque PHP los
incluye desde el sistema de archivos, pero `.htaccess` los bloquea para HTTP.

---

## 2. Archivos que deben excluirse del paquete

| Elemento | Motivo |
|---|---|
| `.git/` | Historial completo; nunca debe servirse |
| `.opencode/` | Configuración del agente; bloqueada además por `.htaccess` |
| `.vscode/`, `.idea/`, `.agents/`, `.claude/` | Configuración de herramientas |
| `AGENTS.md` | Reglas internas del proyecto |
| `docs/` | Documentación técnica y auditorías internas |
| `tasks/` | Notas de planificación |
| `tools/` | Migradores, diagnósticos y utilidades CLI |
| `tests/` | Pruebas automatizadas (C y PHP) |
| `database/` | Esquema, migraciones y script de limpieza de un solo uso |
| `scripts/` | Lanzadores locales y sincronizadores de Windows |
| `beestation_node/` | Firmware ESP32 (contiene material criptográfico) |
| `*.md`, `*.txt` de raíz | README, CHANGELOG, informes; bloqueados por `.htaccess` |
| `*.docx` | Informes internos (`INFORME-FUNCIONAMIENTO.docx`, `guia-configuracion-beestation.docx`) |
| `*.sql`, `*.sql.gz` | Volcados y migraciones |
| `config/db.example.php` | Plantilla; puede confundirse con configuración real |
| `config/db.local.php` | Se crea en el servidor; jamás se versiona ni se empaqueta |

---

## 3. Archivos sensibles que nunca deben publicarse

1. `config/db.local.php` (credenciales de base de datos).
2. Cualquier `.env`, `.env.*` o clave privada.
3. `database/beestation_sena.sql` y cualquier volcado (hashes, tokens).
4. `beestation_node/identidad_ecdsa.h` (claves del nodo).
5. `database/migracion-2026-10-17-limpiar-duplicados.sql` (**destructivo**: contiene `DELETE` y `USE beestation_sena`; es de un solo uso y no debe ejecutarse en producción).
6. Logs del servidor.

---

## 4. Ubicación recomendada del document root

**Opción A (recomendada, requiere reestructuración):** un directorio `public/`
que contenga solo los archivos web y dejar `includes/`, `config/`, `vendor/`
fuera del docroot. **No implementada** en esta fase porque implicaría mover
rutas de la aplicación.

**Opción B (soportada hoy):** usar la raíz del repositorio como document root y
confiar en `.htaccess`. Requiere:

- `AllowOverride All` (o, como mínimo, `FileInfo`, `Options`, `Headers`).
- `mod_rewrite` y `mod_headers` activos.
- Verificación posterior por HTTP (ver §13).

Si el hosting no permite `.htaccess`, **la opción B no es segura** y debe
elegirse la opción A.

---

## 5. Dependencias requeridas

- **PHP ≥ 8.0** (se usan `match`, `str_contains`, tipos union y `mixed`).
  Recomendado 8.2/8.3. Verificado por `php -l` con PHP 8.5.12; **no verificado
  en ejecución** con otras versiones.
- Extensiones: `pdo_mysql`, `openssl` (ECDSA P-256), `mbstring`, `json`,
  `session`. Opcional: `fileinfo`.
- **Sin Composer**: no hay `composer.json` ni `vendor/` PHP. Las dependencias
  front-end están vendorizadas en `assets/vendor/` (Chart.js 4.4.7 MIT,
  Lucide 0.468.0 ISC).
- Servidor web con soporte de `.htaccess` (Apache o LiteSpeed).

---

## 6. Configuración del entorno

| Variable | Obligatoria | Uso |
|---|---|---|
| `APP_ENV=production` | Sí | Activa modo producción: `display_errors=0`, `log_errors=1`, y bloquea `root`/clave vacía |
| `DB_HOST` | Sí | Host de la base |
| `DB_NAME` | Sí | Nombre de la base |
| `DB_USER` | Sí | Usuario con privilegios mínimos |
| `DB_PASS` | Sí | Clave del usuario |
| `DB_PORT` | No (3306) | Puerto |
| `BEE_DB_AISLADA` | Solo pruebas | Fuerza entorno aislado y exige sufijo `_prueba`/`_test` |

Alternativa soportada: `config/db.local.php` devolviendo un array con las
mismas claves. **En producción se prefiere variables de entorno** y, si se usa
el archivo, permisos `640` y propietario del usuario del servicio.

`config/db.php` **no contiene secretos** (verificado).

---

## 7. Requisitos de PHP

```ini
display_errors = Off          ; la aplicación además lo fuerza con APP_ENV=production
display_startup_errors = Off
log_errors = On
error_log = /ruta/fuera/del/docroot/php-error.log
expose_php = Off
session.use_strict_mode = 1
session.use_only_cookies = 1
session.cookie_httponly = 1
session.cookie_samesite = Lax
upload_max_filesize = 2M
post_max_size = 4M
```

La aplicación ya configura `session.use_strict_mode`, `use_only_cookies`,
`HttpOnly`, `SameSite=Lax` y `Secure` cuando detecta HTTPS
(`includes/auth.php`). **Limitación:** detrás de un proxy TLS, `Secure` depende
de que el servidor exponga `HTTPS=on`; verificar en el hosting.

---

## 8. Configuración de MariaDB/MySQL

- Motor recomendado: **MariaDB** (validado en 11.8.8). MySQL 8.0/8.4:
  **no verificado**; además `009` no es compatible con MySQL.
- Charset: `utf8mb4` / `utf8mb4_unicode_ci`.
- `sql_mode` de la sesión: `STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE`.
- Zona horaria de sesión: `-05:00` (fijada por la aplicación).
- Usuario de aplicación con privilegios mínimos:

```sql
CREATE USER 'beestation_app'@'127.0.0.1' IDENTIFIED BY '<clave-aleatoria>';
GRANT SELECT, INSERT, UPDATE, DELETE ON beestation_sena.* TO 'beestation_app'@'127.0.0.1';
FLUSH PRIVILEGES;
```

- **No reutilizar** `beestation_verify` (tiene `ALL PRIVILEGES ON *.*`).
- `schema.sql` para instalación limpia; migraciones solo en instalaciones
  existentes (ver `docs/instalacion.md`).

---

## 9. Permisos de directorios

```text
Directorios: 755   (propietario: usuario del servicio)
Archivos:    644
config/db.local.php: 640
Sin escritura del servidor web sobre código, configuración ni documentación.
Nada de 777.
```

La aplicación **no sube archivos** ni escribe en disco: no requiere directorios
de escritura.

---

## 10. Configuración de logs

- Errores PHP al log del servidor, fuera del docroot.
- `error_log()` de la aplicación registra solo mensajes genéricos (sin
  credenciales ni datos personales); verificado por revisión de código.
- Recomendado: un `error_log` por sitio y rotación diaria, retención ≥ 30 días.
- La API desactiva `display_errors` por `.htaccess` y por código
  (`BEESTATION_API_CONTEXT`), y responde JSON genérico ante excepciones.

---

## 11. Requisitos HTTPS

- Todo el panel y la API por HTTPS; redirección 301 desde HTTP.
- Certificado válido y renovación automática.
- HSTS solo cuando HTTPS esté confirmado en todos los subdominios.
- **Firmware ESP32:** la validación TLS contra un endpoint público **no está
  verificada**; no debe enviarse telemetría a producción por HTTP.

---

## 12. Respaldo y reversión

**Antes del despliegue**

```bash
mysqldump --single-transaction --routines --triggers \
  -u USUARIO -p BASE > respaldo_previa_$(date +%Y%m%d_%H%M).sql
tar czf respaldo_archivos_$(date +%Y%m%d_%H%M).tgz /ruta/docroot
```

**Reversión**

1. Restaurar el respaldo SQL en una base nueva y verificar.
2. Restaurar el paquete de archivos anterior.
3. Revisar el log de errores y las rutas bloqueadas.
4. No revertir con `git reset --hard` en el servidor: se restaura el paquete.

---

## 13. Verificaciones posteriores al despliegue

```text
[ ] https://dominio/            → login (sin sesión)
[ ] /config/db.php              → 403/404
[ ] /config/db.local.php        → 403/404
[ ] /includes/functions.php     → 403/404
[ ] /docs/                      → 403/404
[ ] /tools/                     → 403/404
[ ] /tests/                     → 403/404
[ ] /scripts/                   → 403/404
[ ] /beestation_node/           → 403/404
[ ] /database/                  → 403/404
[ ] /.opencode/                 → 403/404
[ ] /.git/config                → 403/404
[ ] /README.md                  → 403/404
[ ] Registro de usuario nuevo   → funciona
[ ] Login / logout              → funciona
[ ] Dashboard sin datos         → estado vacío, sin cifras inventadas
[ ] API ping                    → {"app":"BeeStation","status":"ok"}
[ ] Cabeceras: nosniff, SAMEORIGIN, Referrer-Policy, sin X-Powered-By
[ ] Cookie de sesión: HttpOnly, Secure, SameSite=Lax
[ ] Log de errores sin stack traces en la respuesta
```

**Estado:** ninguna de estas comprobaciones se ha ejecutado contra un servidor
real. Son requisitos, no resultados.
