# Checklist de prelanzamiento — BeeStation

- **Fecha:** 2026-10-30
- **Estado:** plantilla de verificación. **Ningún ítem está ejecutado contra
  producción.** Hostinger no se ha configurado ni probado.

Marca cada casilla solo con evidencia (salida de comando, captura o registro
fechado). Un ítem sin evidencia queda pendiente, aunque "parezca" hecho.

---

## Fase 0 · Decisiones previas

- [ ] Versión de PHP del hosting confirmada (≥ 8.0; recomendado 8.2/8.3).
- [ ] Motor de base de datos confirmado (MariaDB recomendado; MySQL 8 implicaría
      no aplicar `009` y validar el resto).
- [ ] Dominio definitivo y DNS bajo control.
- [ ] Responsable de la base de datos y del respaldo identificado.
- [ ] Ventana de despliegue acordada.
- [ ] Confirmado que **no** se usará `beestation_verify` como usuario de la app.

## Fase 1 · Hosting

- [ ] Plan con soporte de `.htaccess`, `mod_rewrite` y `mod_headers`
      (o decisión de reestructurar a `public/`).
- [ ] `AllowOverride` suficiente; verificado con una regla de prueba.
- [ ] HTTPS activo con certificado válido y renovación automática.
- [ ] Redirección HTTP → HTTPS.
- [ ] Document root definido (raíz del proyecto o `public/`).
- [ ] Acceso SSH/CLI disponible si se van a ejecutar herramientas.
- [ ] Zona horaria del servidor y de PHP revisadas (`America/Bogota` en la app).

## Fase 2 · Base de datos

- [ ] Base creada con `utf8mb4` / `utf8mb4_unicode_ci`.
- [ ] `database/schema.sql` cargado en la base productiva (instalación limpia).
- [ ] Usuario `beestation_app` creado con `SELECT, INSERT, UPDATE, DELETE`
      únicamente sobre esa base.
- [ ] Contraseña aleatoria ≥ 20 caracteres, guardada en el gestor de secretos.
- [ ] `APP_ENV=production` definido en el entorno del servicio.
- [ ] Conexión verificada con `php -r` o con una página de prueba **temporal**
      (retirada después).
- [ ] Respaldo inicial de la base vacía/instalada.
- [ ] Plan de respaldo diario y retención definidos.
- [ ] Restauración probada en una base desechable.

## Fase 3 · Paquete de despliegue

- [ ] Paquete construido según `docs/produccion/MANIFIESTO-DESPLIEGUE.md`.
- [ ] Verificado que **no** incluye `.git/`, `docs/`, `tools/`, `tests/`,
      `database/`, `scripts/`, `beestation_node/`, `tasks/`, `.opencode/`,
      `.vscode/`, `*.docx`, `*.md`, `*.sql`.
- [ ] `config/db.local.php` **no** está en el paquete.
- [ ] `assets/vendor/` incluido (Chart.js y Lucide).
- [ ] Listado del paquete revisado por una segunda persona.
- [ ] Hash/checksum del paquete registrado.

## Fase 4 · Despliegue

- [ ] Copia del paquete al docroot.
- [ ] Permisos: directorios `755`, archivos `644`, `config/db.local.php` `640`.
- [ ] Propietario/grupo correctos (usuario del servicio, no `root`).
- [ ] Variables de entorno o `db.local.php` configurados en el servidor.
- [ ] Logs de PHP fuera del docroot y con rotación.
- [ ] `php.ini`: `display_errors=Off`, `log_errors=On`, `expose_php=Off`.
- [ ] Cuenta inicial creada con `tools/crear-usuario-inicial.php` (solo CLI) y
      variables de entorno borradas después.
- [ ] Ninguna migración aplicada sin respaldo previo.

## Fase 5 · Verificación posterior

- [ ] `/` responde login o dashboard.
- [ ] Registro de usuario nuevo funciona.
- [ ] Login, logout y expiración de sesión funcionan.
- [ ] Dashboard sin datos muestra estado vacío (sin cifras inventadas).
- [ ] Con datos reales, frescura y "dato antiguo" se muestran correctamente.
- [ ] `/config/db.php`, `/config/db.local.php`, `/includes/functions.php`,
      `/docs/`, `/tools/`, `/tests/`, `/scripts/`, `/database/`,
      `/beestation_node/`, `/.opencode/`, `/.git/config`, `/README.md`
      responden **403/404**.
- [ ] `api/ping.php` responde JSON.
- [ ] Cabeceras: `X-Content-Type-Options`, `X-Frame-Options`,
      `Referrer-Policy`, sin `X-Powered-By`.
- [ ] Cookie de sesión con `HttpOnly`, `Secure`, `SameSite=Lax`.
- [ ] Un error forzado **no** muestra rutas ni stack traces.
- [ ] Log de errores recibe el fallo sin datos sensibles.
- [ ] Acceso entre dos usuarios: A no puede ver ni operar datos de B.

## Fase 6 · Operación

- [ ] Monitorización de disponibilidad y del log de errores.
- [ ] Procedimiento de actualización documentado (`docs/instalacion.md`).
- [ ] Procedimiento de reversión probado.
- [ ] Responsable de incidentes y canal de contacto.
- [ ] Rotación de credenciales programada.
- [ ] Revisión de dependencias vendorizadas (Chart.js, Lucide) y sus licencias.

## Fase 7 · Pendientes específicos de BeeStation

- [ ] Firmware ESP32 con validación TLS contra el endpoint público.
- [ ] Coordinación de despliegue API + firmware (nonce obligatorio).
- [ ] Confirmar que `009` **no** se aplica en producción.
- [ ] Confirmar que la migración `010` se aplicó con el migrador y quedó
      registrada.
- [ ] Revisar `database/migracion-2026-10-17-limpiar-duplicados.sql`: **no
      ejecutar** en producción.

---

## Criterio de salida

El lanzamiento solo puede declararse cuando **todas** las casillas de las fases
2 a 5 tengan evidencia. Las fases 0, 1, 6 y 7 pueden quedar parcialmente
abiertas si se documentan como riesgo aceptado por el responsable.
