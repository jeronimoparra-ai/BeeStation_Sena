# BeeStation — Guía de Instalación y Configuración en Hostinger

**Proyecto:** BeeStation / ApiTechnology — SENA, El Bagre (Antioquia)  
**Dominio de destino:** `https://beestation.cfmasoft.com/`  
**Documento generado para:** Misión H.12.2  
**Fecha:** 2026-11-03  

---

## 1. Propósito y alcance

Este documento explica cómo llevar el paquete de entrega `BeeStation_Hostinger_Release.zip`
al entorno de Hostinger, con una base de datos MariaDB/ MySQL vacía y con las
instrucciones para la puesta en marcha segura.

**No cubre** la verificación en el servidor real (no hay acceso al panel de
Hostinger en esta misión). Las secciones marcadas como *“requiere acción en hPanel”*
deben completarse con el operador con acceso real.

---

## 2. Requisitos mínimos de PHP y extensiones detectadas en el proyecto

| Requisito | Valor detectado | Evidencia / estado |
|---|---|---|
| Versión PHP | **≥ 8.2** | Código PHP 8+. Hostinger depreca PHP 8.1 y inferiores (no disponibles en el panel de administración). |
| Extensión PDO | requerida | Incluida por defecto en planes Web/Cloud. |
| Extensión **PDO MySQL** (`pdo_mysql`) | requerida | Incluida por defecto; debe verificarse activada en el panel (PHP Configuration → extensions). |
| Extensión **mysqli** | recomendada | Incluida por defecto. |
| Extensión **mbstring** | requerida (`validarPasswordSegura`) | Incluida por defecto. |
| Extensión **openssl** | requerida (firma ECDSA P-256, password_hash bcrypt) | Incluida por defecto. |
| Extensión **curl** | recomendada (firmware/actualización) | Incluida por defecto. |
| Extensión **fileinfo** | recomendada | Incluida por defecto. |
| Extensión **json** | requerida | Incluida por defecto. |
| Extensión **hash** | requerida | Incluida por defecto. |
| Extensión **filter** | requerida (validaciones) | Incluida por defecto. |
| Extensión **zlib / bz2 / ftp** | opcionales | Incluidas por defecto. |

> **Nota:** en planes Web y Cloud de Hostinger las extensiones están habilitadas por
> defecto y se configuran vía el panel. El fichero `php.ini` **no es directamente
> accesible** en esos planes; las ajustes se hacen con el panel o con `.htaccess`.

**Verificación local (H.12.1):** las extensiones `pdo_mysql`, `mysqli`, `mbstring`,
`openssl`, `curl`, `fileinfo`, `json`, `hash` estaban ausentes en la instalación local
de XAMPP por error de `php.ini`; **reparado en esta misión** (ver FASE 2 de H.12.2) y
verificado con el ejecutable exacto de XAMPP. En Hostinger se asume default + verificación.

---

## 3. Creación de la base de datos en Hostinger (hPanel)

1. En el panel de Hostinger, abrir **Websites** → seleccionar el sitio
   (`beestation.cfmasoft.com`) → **Manage site**.
2. En el apartado **Databases**, pulsar **Create Database And Database User**
   (o equivalente del plan contratado).
3. Introducir:
   - **Database Name:** nombre deseado por el operador (p. ej. `beestation_sena`).
     Hostinger puede asignar un prefijo de usuario; anotarlo.
   - **Database User:** usuario deseado (p. ej. `beestation_app`).
   - **Password:** contraseña fuerte del usuario.
4. **Importante:** la base se asigna a un único sitio web. Anotar el host
   (`localhost` en planes de hosting compartido; `127.0.0.1` o IP en VPS).
5. No usar el usuario `root` para la aplicación: `config/db.php` lo rechaza
   en modo producción.

---

## 4. Permisos mínimos necesarios

- El usuario de base de datos creado (p. ej. `beestation_app`) debe tener:
  `SELECT`, `INSERT`, `UPDATE`, `DELETE` sobre la base de la aplicación.
- Sin `GRANT` sobre bases ajenas. No otorgar privilegios de `DROP`/`CREATE`.

---

## 5. Importación del esquema SQL

1. Descargar `database/schema.sql` del paquete.
2. En phpMyAdmin: entrar al sitio web → **Databases** → **Enter phpMyAdmin** en la base
   recién creada → pestaña **Import**.
3. Subir el archivo `.sql` (o `.sql.zip`, gzip/bzip2 soportado). **Límite documentado de
   Hostinger: 256 MB** por subida vía phpMyAdmin Web.
4. El esquema es **idempotente** (`CREATE TABLE IF NOT EXISTS`) y **no contiene**
   `CREATE DATABASE`, `CREATE USER`, `GRANT`, `USE`, `DROP`, ni datos de demostración:
   importa limpio en una base vacía.
5. Tras la importación: el schema genera las **14 tablas** esperadas y un catálogo de
   **9 variables** en `variable_bioclimatica`. No debe haber filas en tablas de datos.

### 5.1 Resultado de la prueba local de importación (H.12.2)

| Verificación | Resultado |
|---|---|
| Importación en base temporal `beestation_sena_h122_qa` | ✅ exitosa (rc=0) |
| Tablas creadas | ✅ exactamente las 14 esperadas, nada más ni menos |
| Claves primarias / FKs / índices | ✅ 14 PKs, 12 FKs, 51 entradas de índice, índices UNIQUE correctos |
| Idempotencia (reimport) | ✅ sin errores |
| Filas de datos en tablas funcionales | ✅ 0 (base vacía) |
| Catálogo `variable_bioclimatica` | ✅ 9 filas (incl. variables de alerta) |
| Limpieza de la base temporal | ✅ DROP ejecutado; base real `beestation_sena` intacta |

**Conclusión:** *la base de datos está preparada para importarse en Hostinger.*

---

## 6. Configuración de la conexión de producción

Crear (en el servidor) `config/db.local.php` a partir de `config/db.example.php`:

```php
return [
    'DB_HOST' => 'localhost',
    'DB_NAME' => 'beestation_sena',
    'DB_USER' => '<usuario_asignado>',
    'DB_PASS' => '<clave_asignada>',
    'DB_PORT' => 3306,
];
```

- No incluir credenciales reales en el ZIP; este fichero es local y está excluido de Git
  y del paquete.
- El usuario de producción **no debe ser `root`** (rechazado por `config/db.php` en modo
  producción).
- La DSN usa `charset=utf8mb4`; la sesión fuerza
  `sql_mode = STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE`
  y `time_zone = '-05:00'`.
- Evitar el modo aislado (`BEE_DB_AISLADA=1`) en producción.

---

## 7. Configuración de la URL canónica

La aplicación NO confía en `HTTP_HOST`, `X-Forwarded-Host` ni en la ruta absoluta para
construir su URL base. Se añadió `includes/url.php` (misión H.12.2):

- Fuente de confianza única: `APP_URL` (variable de entorno o `config/app.local.php`).
- Sin `APP_URL`: en desarrollo local se deriva el origen de la petición (subdirectorio o
  bucle local); en cualquier otro host se devuelve `null` (fallo seguro, no redirección a
  localhost).
- `X-Forwarded-Host` se ignora siempre. `X-Forwarded-Proto` solo se acepta con
  `TRUST_PROXY` habilitado explícitamente.
- `APP_URL` con host local en despliegue remoto se **rechaza**.
- Host manipulado (p. ej. `evil.example`) se **rechaza** / no se emite URL.

### 7.1 Configuración recomendada en producción

```php
// config/app.local.php
return [
    'APP_URL'     => 'https://beestation.cfmasoft.com',
    'TRUST_PROXY' => false,
];
```

- Con `TRUST_PROXY => false` (default), se ignora `X-Forwarded-Proto` (comportamiento
  correcto si Hostinger termina TLS de forma nativa).
- Si se usa un proxy propio que termina TLS, establecer `TRUST_PROXY => true`.

### 7.2 Verificación realizada (H.12.2, servidor HTTP real)

| Escenario | Resultado |
|---|---|
| Local sin `APP_URL`, `Host: localhost` (subdir) | origen derivado `http://localhost/...` ✅ |
| Local sin `APP_URL`, `Host: 127.0.0.1:8099` | origen derivado ✅ |
| Host manipulado sin `APP_URL` | `origen = null` (fail-safe) ✅ |
| `X-Forwarded-Host: evil.example` (sin `APP_URL`) | ignorado ✅ |
| `X-Forwarded-Proto: https` sin `TRUST_PROXY` | ignorado ✅ |
| `X-Forwarded-Proto: https` con `TRUST_PROXY=1` | aceptado ✅ |
| Producción con `APP_URL`, host real | origen correcto ✅ |
| Producción con `APP_URL` + host manipulado | host controlado ✅ |
| Producción **sin** `APP_URL` | `origen = null` (fail-safe) ✅ |
| `APP_URL = http://localhost` en despliegue remoto | **rechazado** ✅ |
| `APP_URL = http://localhost` en local | aceptado (desarrollo) ✅ |
| `APP_URL` con ruta (`/sub/dir`) | **rechazado** ✅ |
| `APP_URL` con credenciales (`user:pass@host`) | **rechazado** ✅ |

### 7.3 Lógica **antes** vs **después** (auditoría H.12.2 sobre la misma petición)

| Petición | Antes (`dispositivos.php`) | Después (`includes/url.php`) |
|---|---|---|
| Host normal | endpoint construido | endpoint correcto ✅ |
| `X-Forwarded-Host: evil.example` | endpoint a `evil.example` ❌ (vulnerabilidad) | ignorado / no emitido ✅ |
| `X-Forwarded-Proto: https` no confiable | sólo `https` | ignorado salvo `TRUST_PROXY` ✅ |

---

## 8. Carga y extracción del ZIP

- **Ruta final:** `C:\Users\andre\BeeStation_Entrega\BeeStation_Hostinger_Release.zip`
- **Origen:** repositorio fuente validado (`HEAD` + cambios de esta misión).
- **Nunca** partir de la copia XAMPP (`C:\xampp\htdocs\BeeStation_Sena`).

### 8.1 Contenido del paquete (reconstruido)

El paquete actual incluye las entradas previas más los archivos añadidos en esta
misión:

- `includes/url.php` (nuevo — resolución de URL canónica)
- `config/app.example.php` (nuevo — plantilla de `APP_URL` / `TRUST_PROXY`)
- `INSTALACION-HOSTINGER.md` (este documento)

Se excluyen explícitamente (sin romper referencias): `.git/`, `config/db.local.php`,
`config/app.local.php`, credenciales, backups, logs, informes internos, capturas,
`scripts/`, `tests/`, `tasks/`, `.opencode/`, `.vscode/`, `docs/`.

| Entrada | Tamaño (bytes) | SHA-256 (resumen) |
|---|---|---|
| (resumen; hash completo en `MANIFIESTO-SHA256-ZIP.txt`) | | |

---

## 9. Verificación del `.htaccess` en el destino

`/.htaccess` y `/api/.htaccess` se copian al servidor.

Puntos clave:

- `Options -Indexes`, `DirectoryIndex index.php`.
- Denegación de `config/`, `includes/`, `database/`, `beestation_node/`, `tools/`,
  `docs/`, `scripts/`, `tests/`, `tasks/`, `.opencode/`, etc.
- `api/.htaccess`: `display_errors off`, `display_startup_errors off`, `log_errors on`.
- Cabeceras: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
  `Permissions-Policy`, `X-Permitted-Cross-Domain-Policies`, `X-Robots-Tag`.
- `X-Powered-By` desactivado.
- Bloqueo de caché para `.php`.
- `post_max_size 4M`, `upload_max_filesize 2M` (API de ingesta).

**Requiere acción en hPanel:** confirmar que el plan de Hostinger permite
`AllowOverride` con `Options`/rewrites (LiteSpeed lo gestiona). Si la sintaxis no
es aceptada, sustituir por reglas equivalentes; la aplicación no depende del funcionamiento
de la redirección para arrancar.

---

## 10. Activación y comprobación de HTTPS

- Hostinger emite certificados SSL gratuitos (Let's Encrypt) para dominios y
  subdominios en sus planes de hosting.
- El dominio objetivo: `beestation.cfmasoft.com` → HTTPS.
- Tras el despliegue: comprobar `https://beestation.cfmasoft.com/` responde 200 y los
  recursos internos se sirven por HTTPS.

---

## 11. Creación segura del administrador inicial

Mecanismo autorizado: `php tools/crear-usuario-inicial.php` (CLI únicamente; rejeita
peticiones HTTP con 404/403).

- No crear cuentas por petición pública HTTP.
- No incluir contraseña por defecto.
- No sobrescribir `admin@beestation.io` (el script aborta si ya existe).
- Variables de entorno CLI: `BEESTATION_BOOTSTRAP_NAME`, `BEESTATION_BOOTSTRAP_EMAIL`,
  `BEESTATION_BOOTSTRAP_PASSWORD` + validación de contraseña segura.

---

## 12. Pruebas posteriores a la instalación

1. `landing.php` → 200, sin errores.
2. `login.php`, `registro.php` → 200.
3. `index.php` → 302 → `landing.php`.
4. Módulos internos sin sesión → 302 → `login.php`.
5. `api/ping.php` → 200; `api/estado.php`, `api/serie.php` → 401.
6. `api/ingest.php` → 401 sin token.
7. Rutas protegidas (`config/`, `includes/`, `database/`, `tools/`, `docs/`, …) →
   denied (sin enumeración).
8. Endpoint de ingesta: debe aparecer con la URL canónica configurada.

---

## 13. Protección de archivos de configuración

- `config/db.local.php` excluido del paquete; no versionado.
- `config/app.local.php` excluido del paquete.
- `.htaccess` bloquea el acceso HTTP a estos ficheros.

---

## 14. Lista de verificaciones antes de habilitar el sistema al público

- [ ] ZIP descomprimido en el directorio correcto del docroot.
- [ ] `database/schema.sql` importado en la base de producción (vacía).
- [ ] `config/db.local.php` creado con credenciales reales del host (fuera del ZIP).
- [ ] `config/app.local.php` (o `APP_URL`) configurado a `https://beestation.cfmasoft.com`.
- [ ] `APP_ENV=production` activo (apaga `display_errors`).
- [ ] PHP 8.2+ activo, extensiones pdo_mysql/mbstring/openssl/curl/fileinfo activas.
- [ ] HTTPS activo y redirección a HTTPS operativa.
- [ ] `.htaccess` aceptado por el servidor.
- [ ] `admin@beestation.io` operativo con login real.
- [ ] Sin datos ficticios en la base.
- [ ] Sin credeniales en el ZIP.

---

## 15. Procedimiento básico de reversión

1. Detener el sitio en hPanel (modo mantenimiento o desactivar dominio).
2. Restaurar el ZIP previo / backup del directorio.
3. Eliminar la base y reimportar el schema desde cero si se requiere.
4. No borrar datos operativos sin copia de seguridad.

---

## 16. Limitaciones conocidas al cierre de la misión

- El paquete está **preparado**, no validado en servidor real.
- Validación funcional local de módulos internos requiere sesión legítima
  (no disponible en esta misión) y base local con datos.
- Hardware ESP32 no disponible en esta misión.
- Detalle pendiente: comportamiento en LiteSpeed/Hostinger (M-06) y política de
  hosts desconocidos (documentada).
- Corrupción local de `C:\xampp\php\php.ini` (C-01) reparada en esta misión
  (ver FASE 2 de H.12.2).

---

## 17. Resumen de la ejecución de la misión H.12.2 relacionada con este documento

| Paso | Resultado |
|---|---|
| FASE 0 | Respaldos y línea base OK |
| FASE 1 | Auditoría de URL canónica + correcciones + evidencia |
| FASE 2 | Restauración de `php.ini` local + verificación de extensiones |
| FASE 3 | Esquema SQL validado (14 tablas, base limpia, idempotente) |
| FASE 4 | Documento de instalación creado (este fichero) |
| FASE 5 | Reconstrucción del ZIP pendiente de autorización/ ejecución |
| FASE 6 | Alineación XAMPP + validación |
| FASE 7 | Informe final |

**Estado:** FASES 1–4 completadas. **Fase 5 pendiente** (reconstrucción del ZIP) y
**Fase 7** (informe final).
