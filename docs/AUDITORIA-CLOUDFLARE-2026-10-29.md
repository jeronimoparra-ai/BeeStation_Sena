# Auditoría Cloudflare (security-audit-skill) — BeeStation_Sena

**Fecha:** 2026-10-29
**Alcance:** sistema completo (PHP web + API + firmware ESP32 + base de datos)
**Commit auditado:** `d956836` (`main` == `origin/main`)
**Método:** revisión estática con las fases del skill (reconocimiento, caza guiada por cobertura, validación de candidatos, salida estructurada). Sin cambios a la base de datos ni al firmware.

---

## Resumen ejecutivo

No se encontraron vulnerabilidades críticas explotables (inyección SQL, bypass de autenticación, IDOR) en el código PHP. La autenticación ECDSA/HMAC, el anti-replay por nonce, el uso de prepared statements y el control de propiedad de colmena están bien implementados. Se detectaron **5 hallazgos de configuración/robustez** (la mayoría corregidos durante esta auditoría) y **3 mejoras de defensa en profundidad** recomendadas.

---

## Hallazgos

### H1 — Copia desplegada desactualizada (crítico, corregido)
- **Ubicación:** `/opt/lampp/htdocs/BeeStation_Sena/api/ingest.php` (23-oct) vs repo
- **Descripción:** la copia en ejecución carecía de las mitigaciones de seguridad (límite de cuerpo, validación de `ts`/`seq`).
- **Estado:** corregido (sincronizado).

### H2 — Bloque duplicado en `api/ingest.php` (medio, corregido)
- **Ubicación:** `api/ingest.php` líneas 130-136 (residuo del merge).
- **Estado:** corregido.

### H3 — Protección de credenciales ausente en el repo (medio, corregido)
- **Ubicación:** `.htaccess` (faltaba bloque `<FilesMatch>` para `db.php`, `db.local.php`, `db.example.php`).
- **Estado:** corregido.

### H4 — Límite de cuerpo ausente en `api/enroll.php` (medio, corregido)
- **Ubicación:** `api/enroll.php` (endpoint público sin autenticación previa, aceptaba `php://input` completo).
- **Estado:** corregido (límite de 1 MiB + validación de lectura).

### H5 — Sin rate limiting por IP/dispositivo (medio, `needs_validation`)
- **Ubicación:** `api/ingest.php`, `api/enroll.php`, `login.php`.
- **Descripción:** no hay límite de peticiones por IP o dispositivo; `login.php` no tiene bloqueo de intentos.
- **Impacto:** denegación de servicio / fuerza bruta de contraseña débil (`senasoft2026`).
- **Mitigación recomendada:** contador de intentos fallidos por correo+IP con bloqueo temporal (5 intentos → 15 min).

### H6 — `ts`/`seq` sin validación de rango (bajo, corregido)
- **Ubicación:** `api/ingest.php` (líneas 270-280).
- **Estado:** corregido (descarta valores ≤0 o > `time()+86400`).

### H7 — `REMOTE_ADDR` falsificable detrás de proxy (`needs_validation`)
- **Ubicación:** `api/ingest.php`, `api/enroll.php`, `api/validate_token.php`.
- **Descripción:** la IP de origen se obtiene de `REMOTE_ADDR`; si hay proxy inverso, se registra la IP del proxy.
- **Mitigación:** configurar `X-Forwarded-For` de forma segura si hay proxy.

### H8 — Token HMAC heredado sin validación de entropía (`needs_validation`)
- **Ubicación:** `includes/firma.php`, `api/ingest.php` (esquema HMAC heredado).
- **Descripción:** el esquema HMAC usa el token de vinculación de la colmena. Si el token es débil o predecible, un atacante podría falsificar peticiones.
- **Mitigación:** verificar que los tokens se generen con `random_bytes()` y tengan ≥128 bits de entropía.

### H9 — `Content-Type` no validado (`rejected`, no es vulnerabilidad)
- **Ubicación:** `api/ingest.php`, `api/enroll.php`.
- **Descripción:** no se valida que `Content-Type` sea `application/json`, pero el cuerpo se decodifica como JSON. Sin impacto de seguridad directo.

### H10 — `lote` sin validación de caracteres (`rejected`, no es vulnerabilidad)
- **Ubicación:** `api/ingest.php` línea ~133.
- **Descripción:** `$lote` se trunca a 64 caracteres y se usa en prepared statements. No hay inyección SQL.

### H11 — `id_medicion` validado con regex (`rejected`, correcto)
- **Ubicación:** `api/ingest.php` líneas 175-180. Validación `^[0-9a-f]{32}$`. Correcto.

### H12 — Uso de prepared statements (`rejected`, correcto)
- **Ubicación:** `api/ingest.php`, `api/enroll.php`, `includes/functions.php`, `includes/dispositivo.php`, `includes/firma.php`.
- **Descripción:** todas las consultas revisadas usan `prepare()` y `execute()` con parámetros. No se encontró inyección SQL.

### H13 — `api/ping.php` con `Access-Control-Allow-Origin: *` (bajo, aceptable)
- **Ubicación:** `api/ping.php`.
- **Descripción:** CORS abierto en el endpoint de descubrimiento. Aceptable porque solo devuelve `app`, `status` y `version`, sin datos sensibles.

### H14 — IDOR no detectado (correcto)
- **Ubicación:** `centro-alertas.php`, `informe.php`, `api/serie.php`, `api/estado.php`, `seleccionar_colmena.php`.
- **Descripción:** todas las consultas filtran por `id_colmena` obtenida de `obtenerColmenaActiva($_SESSION['id_usuario'])`. No se encontró acceso a datos de otra colmena.

### H15 — CSRF parcialmente cubierto (medio, `needs_validation`)
- **Ubicación:** `login.php`, `dispositivos.php` usan `validarCsrfSesion`; `seleccionar_colmena.php` usa su propio token `csrf_colmena`.
- **Descripción:** los endpoints de API (`ingest`, `enroll`, `enroll_estado`, `validate_token`) no usan CSRF porque se autentican por firma ECDSA/HMAC. Correcto. Los formularios del panel sí tienen CSRF.

---

## Defensas correctas observadas

- Sesión: `use_strict_mode`, `use_only_cookies`, cookie `HttpOnly`, `Secure` según HTTPS, `SameSite=Lax`.
- Contraseñas: `password_hash()` / `password_verify()` con bcrypt costo 12.
- Tokens CSRF: `bin2hex(random_bytes(32))` con `hash_equals`.
- Anti-replay: nonce con tabla `nonce_dispositivo`/`nonce_peticion` y ventana de tiempo.
- Firma: ECDSA P-256 con prueba de posesión; HMAC heredado con secreto por colmena.
- Prepared statements en todas las consultas revisadas.
- `config/db.local.php` no está versionado en git.

---

## Recomendaciones prioritarias

1. **Rate limiting en `login.php`** (bloqueo tras 5 fallos, 15 min) — compensa la contraseña actual `senasoft2026`.
2. **Rate limiting por IP en `api/ingest.php` y `api/enroll.php`** en Apache/Nginx o firewall.
3. **Cambiar `senasoft2026`** por una contraseña fuerte antes de producción (o habilitar 2FA).
4. **HTTPS** obligatorio: la firma protege el payload pero la sesión viaja en claro.
5. **Validar `Content-Type: application/json`** en los endpoints de API (defensa en profundidad).
6. **Configurar `X-Forwarded-For`** de forma segura si hay proxy inverso.
7. **Verificar entropía de los tokens HMAC** de colmena (≥128 bits, generados con `random_bytes`).

---

## Conclusión

El sistema está bien diseñado en autenticación, validación y uso de prepared statements. Las debilidades son de configuración y rate limiting, no de inyección o bypass de autenticación. Las mitigaciones H1-H4 y H6 ya se aplicaron en esta auditoría.
