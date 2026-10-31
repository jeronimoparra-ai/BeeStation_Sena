# ESTADO ACTUAL DE LA AUDITORÍA — BeeStation_Sena

> Documento vivo. **Consúltalo antes de continuar cualquier auditoría, migración o
> cambio de esquema.** No es historial: refleja el estado verificado más reciente.
>
> Última actualización: **2026-10-30** · Responsable: agente principal OpenCode.

---

## 1. Estado de Git (verificado en vivo)

| Dato | Valor |
|---|---|
| Rama | `main` |
| `HEAD` | `8cee2c50a11b53aaee22434b7472736ea2921802` |
| `origin/main` | `0698155043dca88ade4a7f7d46893d0dd846625a` |
| Padre de `8cee2c5` | `0698155…` (correcto) |
| Diff / staged | vacío / vacío |
| Stash | `stash@{0}` — `HERMES-audit-20261030-<redactado>` (intacto) |
| Respaldo | `~/backups/beestation_audit_20261030_180018/` |

Commits publicados relevantes: `2356863` (registro público), `9bb77e0`
(política de contraseñas), `0698155` (bcrypt costo 12). Commit local sin
publicar: `8cee2c5` (4 archivos: `schema.sql`, `verificar-esquema.php`,
`diagnostico-bd.php`, `ARQUITECTURA-BASES-DE-DATOS.md`).

Archivos sin rastrear: `database/migrations/010_…sql`,
`tools/migrar-010-verificacion-bioclimatica.php`,
`tools/verificar-migracion-010.php`.
Depuradores de Hermes movidos a `/tmp/opencode/beestation-debug-20261030/`.

## 2. Entorno verificado

- OpenCode v2.2.5 · PHP 8.5.12 (pdo_mysql, mysqlnd).
- Servidor de pruebas `127.0.0.1:3310` → `11.8.8-MariaDB`, `MariaDB Server`.
- `beestation_verify@127.0.0.1` tiene `ALL PRIVILEGES ON *.*` y puede leer/escribir
  `beestation_sena` (verificado con un `COUNT`). Propuesta de mínimo privilegio en §11.
- H14 verificado: un índice UNIQUE marcado `IGNORED` en MariaDB 11.8.8 **sigue
  aplicando unicidad** (segundo INSERT duplicado → error 1062).
- `beestation_sena` local **ya tiene** `uq_variable_bioclimatica_nombre` y 9 filas
  → el entorno local **no** reproduce "instalación antigua sin índice".
- Bases huérfanas de intentos previos: `mig010_debug`, `mig010_prueba_recreate`,
  `mig010_prueba_reset`, `delimiter_test`, `deltest`, `b_test`,
  `beestation_verif_*`.

## 3. Tablero de trabajo

Estados: Pendiente · En análisis · En implementación · En validación · Bloqueada · Terminada.

| ID | Tarea | Estado |
|---|---|---|
| F0-1 | Diagnóstico de estado real del repo | Terminada |
| F0-2 | `AGENTS.md` persistente | Terminada |
| F0-3 | Permisos y auditores read-only en `.opencode/` | Terminada |
| F0-4 | Retirar depuradores del árbol | Terminada |
| F1-1 | Rediseñar 010: SQL real + migrador PHP seguro | Terminada |
| F1-2 | Reescribir verificador con pruebas A–H reales | Terminada |
| F1-3 | Pruebas en bases desechables con ownership | Terminada (15/15) |
| F1-4 | Revisión independiente del diff 010 | Terminada (2 auditores, observaciones corregidas) |
| F1-5 | Endurecer tolerancia del runner a "Duplicate key name" | Terminada (verificado con 4 casos negativos) |
| F1-6 | Documentar idempotencia, 009 y mínimo privilegio | Terminada |
| F1-7 | Commit de la Fase 1 | Bloqueada (requiere autorización) |
| F1-8 | Delegación Hermes (010) | Terminada (informe archivado) |
| F1-9 | Delegación Kilo (010) | Terminada (informe entregado; falta archivo) |
| F1-10 | Matriz de consolidación Hermes/Kilo | Terminada (APTO CON OBSERVACIONES) |
| F1-11 | Cierre documental Fase 1.1 (IP, conteos, errata) | Terminada |
| F1-12 | Archivar informe de Kilo | Bloqueada (falta transcripción íntegra) |
| F2-A | Auditoría arquitectura PHP | Pendiente |
| F2-B | Auditoría seguridad web | Pendiente |
| F2-C | Auditoría base de datos | Pendiente |
| F2-D | Auditoría APIs y firmware ESP32 | Pendiente |
| F2-E | Auditoría de interfaz | Pendiente |
| F2-F | Auditoría de despliegue / Hostinger | Pendiente |
| F3-1 | Rotar clave `beestation_verify` y sacarla del código | Bloqueada (requiere OK) |
| P1 | Limpieza y preparación para producción | Terminada (ver `docs/produccion/`) |

## 4. Hallazgos

### Resueltos en Fase 1 (migración 010)

- **C1** El `.sql` ya contiene un único `ALTER TABLE … ADD UNIQUE INDEX` ejecutable.
- **C2** Duplicados detectados **en SQL** (`GROUP BY`), con la collation real y `NULL` excluido.
- **C3** Credenciales fuera del código; solo `getenv()`. Sin `--pass` por argv.
- **C4** Caso G eliminado; la integridad se mide con huella SHA-256 real antes/después.
- **C5** El migrador usa el host/puerto configurados; el verificador usa entorno mínimo.
- **C6** `indiceCorrecto()` exige exactamente una columna, `nombre`, sin prefijo y BTREE.
- **A4** Cubiertos: collation con acentos/mayúsculas, tabla inexistente, homónimo compuesto,
  homónimo con prefijo, marcador ajeno, base real sin flag, `.sql` aplicado dos veces.
- **A5** `nuevaBase()` aborta si la base existe; limpieza solo de bases creadas en la ejecución.
- **H1** `tools/migrar-pruebas.php` ahora verifica el invariante 010 en
  `information_schema` después de aplicar y devuelve rc 1 si no se cumple
  (probado con homónimo no único, columna incorrecta, compuesto y prefijo).
- **H14** Demostrado que `IGNORED` (MariaDB) no desactiva la unicidad; no se añadió
  comprobación innecesaria. En MySQL 8 `IS_VISIBLE=NO` tampoco la desactiva (razonado,
  no verificado: no hay MySQL en el entorno).

### Abiertos

- **A3 (alto)** `009_verificacion_correo.sql` usa `ADD COLUMN IF NOT EXISTS`
  (solo MariaDB). MySQL 5.7/8.x: **no verificado**. Ver §10.
- **A2 (alto)** No hay tabla de control de migraciones aplicadas. Ver §10.
- **M1 (medio)** Dos mecanismos para cargar `schema.sql` (runner vs verificador).
- **M3 (medio)** `docs/instalacion.md` sigue describiendo `001–008`; se propone
  actualizarlo, no aplicado en esta fase.
- **C3-resto (alto)** La clave de `beestation_verify` circula fuera del código y el
  usuario tiene `ALL PRIVILEGES ON *.*`: requiere rotación autorizada. Ver §11.

### Riesgos asumidos y documentados

- TOCTOU entre prechequeo y DDL: una inserción concurrente hace fallar el `ALTER`
  (atómico, sin pérdida de datos). Se fija `lock_wait_timeout = 30`.
- El `ALTER` puede reconstruir la tabla según el motor; no se fuerza `ALGORITHM`.

## 5. Pruebas ejecutadas (evidencia real)

| Fecha | Prueba | Resultado |
|---|---|---|
| 2026-10-30 | `SELECT VERSION()` en `:3310` | `11.8.8-MariaDB` / `MariaDB Server` |
| 2026-10-30 | Índices en `beestation_sena.variable_bioclimatica` | `uq_variable_bioclimatica_nombre` UNIQUE, 1 columna, 9 filas |
| 2026-10-30 | Duplicados en bases locales | ninguno |
| 2026-10-30 | Migración 010 | **no ejecutada** en fase de diagnóstico |
| 2026-10-30 | Batería 010 v1 en bases desechables | 12/12 OK |
| 2026-10-30 | Batería 010 v2 (tras revisión) | **15/15 OK** (A–M), `11.8.8-MariaDB` |
| 2026-10-30 | Limpieza de bases de prueba | 13 creadas, 13 eliminadas; 0 huérfanas |
| 2026-10-30 | `beestation_sena` tras las pruebas | intacta |
| 2026-10-30 | Revisión auditor-datos | APROBADO CON OBSERVACIONES (H1–H22) |
| 2026-10-30 | Revisión auditor-php | APROBADO CON OBSERVACIONES (A-1…B-17) |
| 2026-10-30 | H14: UNIQUE `IGNORED` en MariaDB 11.8.8 | Sigue aplicando unicidad (error 1062) |
| 2026-10-30 | H1 positivo: runner ×2 con índice correcto | rc 0 / rc 0, invariante verificado |
| 2026-10-30 | H1 negativo: homónimo no único | rc 1, “El indice no es UNIQUE.” |
| 2026-10-30 | H1 negativo: índice en columna incorrecta | rc 1, “no usa la columna 'nombre'.” |
| 2026-10-30 | H1 negativo: índice compuesto | rc 1, “indice compuesto (2 columnas).” |
| 2026-10-30 | H1 negativo: índice con prefijo | rc 1, “usa prefijo (10).” |
| 2026-10-30 | Permisos OpenCode: `rm -rf` y `git push -h` | Bloqueados (`permission.rejected`) |

## 6. Riesgos vigentes

1. Un despliegue en Hostinger sin validar A3 puede fallar en MySQL.
2. La clave de pruebas y el usuario con privilegios totales pueden filtrarse
   junto con el repositorio (§11).
3. No hay evidencia de validación TLS/HTTPS del firmware ESP32.
4. La migración 010 está validada **solo** en MariaDB 11.8.8; MySQL no.
5. Sin tabla de control de migraciones: la aplicación de cada archivo se
   determina manualmente (§10).

## 7. Próximos pasos

1. Autorizar (o no) el commit de la Fase 1.1: 11 archivos nuevos en 7 entradas + `tools/migrar-pruebas.php`.
2. Rotar la credencial de `beestation_verify` y aplicar el mínimo privilegio (§11).
3. Actualizar `docs/instalacion.md` con 009 y 010 (propuesto, no aplicado).
4. Abrir Fase 2 (auditoría A–F) por orden de riesgo: seguridad → datos → PHP →
   API/firmware → interfaz → despliegue.

## 8. Archivos relevantes

```
AGENTS.md
database/schema.sql   # esquema unico consolidado
tools/migrar-pruebas.php            # runner real de migraciones
tools/migrar-010-verificacion-bioclimatica.php
tools/verificar-migracion-010.php
scripts/verificar-esquema.php
config/db.php                       # BEE_DB_AISLADA, salvaguardas
includes/password.php               # política bcrypt costo 12
docs/ARQUITECTURA-BASES-DE-DATOS.md
docs/auditorias/                    # informes y tareas de revisión independiente
```

## 9. Idempotencia de la migración 010

Hay **dos mecanismos distintos**, y no deben confundirse:

| Mecanismo | Comportamiento | Uso |
|---|---|---|
| `tools/migrar-010-verificacion-bioclimatica.php` | Comprueba definición real en `information_schema` antes del DDL. Si el UNIQUE correcto existe → rc 0 sin cambios. Segunda ejecución → rc 0, sin DDL, sin errores. | **Recomendado** en instalaciones existentes |
| `010_….sql` directo (CLI o runner) | Operación de **una sola aplicación**. La segunda ejecución falla con `Duplicate key name` (1061), comportamiento esperado y cubierto por el caso K. | Bases nuevas o ejecución única |

`Duplicate key name` **no** demuestra unicidad: por eso el runner verifica el
invariante después de aplicar y devuelve rc 1 si el índice no es UNIQUE, no usa
`nombre`, es compuesto o usa prefijo.

## 10. Migraciones y 009

- **No existe tabla central de registro de migraciones.** A partir de la
  consolidacion (Mision 10.2) no hay carpeta `database/migrations/`: el esquema
  completo vive en `database/schema.sql` y `tools/migrar-pruebas.php` aplica solo
  ese archivo sobre una base de pruebas aislada.
- **Cómo saber manualmente si una migración se aplicó:** comparar la estructura
  real con `information_schema` (columnas, índices, claves) o ejecutar
  `scripts/verificar-esquema.php`. Para 010: comprobar el índice
  `uq_variable_bioclimatica_nombre` con `NON_UNIQUE=0`, una columna `nombre`,
  `SUB_PART NULL` y `INDEX_TYPE=BTREE`.
- **009** (`009_verificacion_correo.sql`) prepara columnas para verificación de
  correo (`correo_verificado`, `token_verificacion_hash`, `token_verificacion_expira`).
  Usa `ADD COLUMN IF NOT EXISTS`, sintaxis **exclusiva de MariaDB**; en MySQL
  5.7/8.x falla (no verificado en el entorno, razonado por documentación).
- **No activar SMTP ni aplicar 009** en esta fase. Las cuentas existentes
  conservan `correo_verificado = 1`.

## 11. Propuesta de mínimo privilegio (C3)

Estado actual: `beestation_verify@127.0.0.1` tiene `ALL PRIVILEGES ON *.*` y
puede operar sobre `beestation_sena`. Es un riesgo real de uso accidental.

Permisos que el arnés necesita de verdad:

- Crear y eliminar bases desechables cuyo nombre empiece por `mig010_`.
- En esas bases: `CREATE`, `ALTER`, `INDEX`, `DROP`, `SELECT`, `INSERT`,
  `UPDATE`, `DELETE`, `CREATE TEMPORARY TABLES`, `LOCK TABLES`, `REFERENCES`.
- Lectura de `information_schema` (implícita).

Propuesta (no aplicada, requiere autorización y verificación previa del entorno):

```sql
-- 1) Retirar el privilegio global
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'beestation_verify'@'127.0.0.1';

-- 2) Conceder solo sobre el prefijo de bases de prueba
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX,
      REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES
  ON `mig010\_%`.* TO 'beestation_verify'@'127.0.0.1';

FLUSH PRIVILEGES;
```

Con este esquema el usuario no puede tocar `beestation_sena` ni crear bases
fuera del prefijo. La contraseña sigue siendo un secreto a rotar: no debe
circular en documentación ni en historiales.

## 12. Orquestación multiagente (estado)

### Disponibilidad comprobada (2026-10-30)

| Agente | Binario | Proceso activo | Control desde OpenCode |
|---|---|---|---|
| Hermes | `~/.local/bin/hermes` | sí (perfil default) | **No directo** (sin MCP ni API de terminal) |
| Kilo | `~/.kilo/bin/kilo` | sí | **No directo** |
| Orca | `~/.config/orca/.../orca` | — | Existe CLI; no autorizado a operar terminales |

OpenCode no dispone de integración que controle esos terminales. Se usa
**delegación manual asistida**: OpenCode emite la tarea, el usuario la entrega,
y OpenCode consolida el informe recibido. No se atribuirá ningún resultado a
Hermes o Kilo sin evidencia de su informe.

### Entregables de delegación

- `docs/auditorias/TAREA-HERMES-FASE-1.1.md` — arquitectura, SQL, migraciones.
- `docs/auditorias/TAREA-KILO-FASE-1.1.md` — seguridad, calidad, permisos.

Ambas son de solo lectura y prohíben commits, push, cambios de grants y uso de
producción.

### Informes recibidos

- `docs/auditorias/2026-10-30-auditor-datos-010.md` (subagente OpenCode).
- `docs/auditorias/2026-10-30-auditor-php-010.md` (subagente OpenCode).
- `docs/auditorias/2026-10-30-auditor-hermes-010.md` (Hermes CLI; renombrado
  desde la errata `hermesso` el 2026-10-30).
- Kilo CLI: informe **entregado al usuario** (`KILO-010-2026-10-30`), **no
  archivado** en el repositorio. No se ha generado un archivo porque no se
  dispone de la transcripción íntegra; no se inventa contenido.

### Pendiente

- Archivar el informe de Kilo cuando el usuario facilite su texto completo.
- Fase 2 (auditorías A–F).

## 13. Cierre Fase 1.1 y limpieza para producción (2026-10-30)

### Correcciones documentales aplicadas

| Corrección | Archivos |
|---|---|
| IP privada redactada (`<redactado>`) | `docs/ESTADO-ACTUAL-AUDITORIA.md`, `docs/auditorias/2026-10-30-auditor-hermes-010.md` |
| Conteo corregido a 11 archivos / 7 entradas | `docs/ESTADO-ACTUAL-AUDITORIA.md`, `docs/auditorias/TAREA-KILO-FASE-1.1.md` |
| Errata `hermesso` → `hermes` | `docs/auditorias/2026-10-30-auditor-hermes-010.md` |
| Procedimiento 010, 009 y registro manual | `docs/instalacion.md` |

### Endurecimiento aplicado

| Cambio | Motivo |
|---|---|
| `.htaccess` bloquea `includes/`, `tasks/`, `.opencode/`, `.vscode/`, `.idea/`, `.agents/`, `.claude/` | `includes/` y la configuración de herramientas eran accesibles por HTTP |
| `config/db.php` fuerza `display_errors=0` y `log_errors=1` con `APP_ENV=production` | Evitar stack traces en la respuesta si `php.ini` está mal configurado |

### Dictamen de la consolidación

**APTO CON OBSERVACIONES.** Sin bloqueantes en el diff. Pendientes:
rotación y mínimo privilegio de `beestation_verify`, validación en MySQL 8,
historial de migraciones, `.htaccess` sin verificar en el hosting y archivo del
informe de Kilo.

### Documentos de producción

- `docs/produccion/MANIFIESTO-DESPLIEGUE.md`
- `docs/produccion/INFORME-LIMPIEZA.md`
- `docs/produccion/CHECKLIST-PRELANZAMIENTO.md`

### Estado real

Código preparado: **sí** (con observaciones). Paquete de despliegue: **definido,
no generado**. Hosting: **no configurado**. Base productiva: **no validada**.
Aplicación desplegada: **no**. Sistema comprobado en producción: **no**.

## 14. Misión 9.1 — Activación de skills y auditorías consolidadas (2026-10-30)

### Skills solicitadas
Las cinco (`find-skills`, `security-and-hardening`, `security-audit`,
`vibe-code-cleanup`, `find-dead-code`) **no están instaladas** en ningún root
(`.opencode/skills`, `~/.config/opencode/skills`, `.agents/skills`, `~/.agents/skills`,
`.claude/skills`, `~/.claude/skills`). Se verificó por nombre de directorio y por
`name:` en el frontmatter de `SKILL.md`. No se instaló ninguna.

Equivalencias funcionales usadas (sin instalar nada):
- Controles generales de seguridad web → `security-headers-config` + `security-logging-monitoring`.
- Auditoría PHP/OWASP/auth/authz/API/datos → `secure-code-review` + `owasp-top10` + `api-security-testing`.
- Limpieza conservadora → `code-reviewer` + revisión manual.
- Inventario de código muerto → `code-reviewer` + inventario manual con evidencia.
- Descubrimiento → búsqueda manual (no existe `find-skills`).

### Subagentes
Se intentaron 5 invocaciones paralelas (`auditor-seguridad`, `auditor-php`,
`auditor-firmware`, 2× `general`). Las 5 fallaron por límite de uso gratuito
(`Free usage limit reached`). Los hallazgos se consolidan desde auditoría directa.
No se atribuye ningún resultado a subagentes.

### Dictamen
Código: **LISTO CON OBSERVACIONES**. Sistema: **NO LISTO PARA PRODUCCIÓN**.
Informe detallado: `docs/produccion/INFORME-MISION-9.1.md`.

## 15. Misión 10 — Limpieza real y paquete productivo (2026-10-30)

### Cambios aplicados
- Eliminado: `js/charts.js` (0 referencias, stub de 3 líneas). Respaldado.
- Trasladados a `docs/`: `INFORME-FUNCIONAMIENTO.docx`, `guia-configuracion-beestation.docx`.
- Paquete candidato: `/tmp/opencode/beestation-release-candidate/` — 51 archivos, 1,6 MB.
- Backup: `~/backups/beestation_mision10_20261030_234001/repo-pre-mision10.tar.gz`.

### Dictamen
Limpieza completada con observaciones. Riesgos de lanzamiento siguen abiertos.
Informe: `docs/produccion/INFORME-LIMPIEZA-MISION-10.md`.

## 16. Misión 10.2 — Consolidación definitiva de la base de datos (2026-10-31)

### Resultado
- `database/` contiene **únicamente** `schema.sql` (esquema consolidado definitivo).
- Carpeta `database/migrations/` eliminada (001–010) y `migracion-2026-10-17-limpiar-duplicados.sql` eliminado.
- Backup histórico: `~/backups/beestation_db_historico_20261031_090501/` (migraciones + SQL de limpieza).
- `schema.sql`: 14 tablas, todas con `DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`;
  `nonce_peticion.nonce` conserva `CHARACTER SET ascii COLLATE ascii_bin`.
- `variable_bioclimatica.nombre` UNIQUE (`uq_variable_bioclimatica_nombre`).
- `lectura`: `seq BIGINT NULL`, `id_medicion CHAR(32) NULL`, UNIQUE `uq_sensor_seq` y
  `uq_lectura_id_medicion`; sin índice redundante sobre `id_medicion`.
- `lote_ingesta.id_lote` BIGINT.
- `sensor` conserva UNIQUE (`id_colmena`, `tipo`).
- Semilla: 9 variables con `INSERT IGNORE` (sin sobrescribir personalizaciones).
- Sin columnas de verificación de correo (009 excluida).
- Sin historial de migraciones ni tabla de control.

### Herramientas adaptadas
- `tools/migrar-pruebas.php`: ya no recorre `database/migrations/`; aplica solo `schema.sql`
  y ejecuta siempre la verificación del índice.
- `tools/verificar-migracion-010.php`: el DDL de la 010 se toma de una constante
  (el `.sql` independiente ya no existe).
- `scripts/verificar-esquema.php`: mensaje actualizado (sin referencia a migraciones).
- `README.md` y `docs/instalacion.md`: actualizados al esquema único.

### Pruebas
- `php -l` sobre todos los PHP: 0 fallos.
- Validación estática del esquema: 14 tablas, 14 charsets, FK a tablas existentes,
  sin columnas 009, sin índice redundante, secuencias BIGINT.
- No se ejecutó DDL sobre ninguna base (no hay cuenta con privilegios mínimos
  disponible; `beestation_verify` tiene `ALL PRIVILEGES ON *.*`).

### Dictamen
Esquema consolidado definitivo preparado. Base de datos productiva no validada
dinámicamente (sin DDL ejecutado).

## 17. Misión 10.3 — Validación final de schema.sql (2026-10-31)

### Validación estática
14 tablas, 14 charsets utf8mb4, FK válidas, 9 variables con INSERT IGNORE,
BIGINT en lectura.seq y lote_ingesta.id_lote, 0 columnas 009, 0 índice redundante
sobre id_medicion. Compatibilidad de runtime verificada: todos los INSERT/UPDATE
del código PHP usan columnas existentes; 0 referencias a correo_verificado etc.

### Primera cuenta
tools/crear-usuario-inicial.php: CLI only, validación completa, hash centralizado
bcrypt costo 12, sin credenciales predeterminadas, sin exposición de contraseña.
Procedimiento adecuado.

### Prueba dinámica
BLOQUEADA: instancia 127.0.0.1:3310 caída; única cuenta beestation_verify tiene
ALL PRIVILEGES ON *.* (prohibida para DDL). Falta infraestructura con cuenta de
privilegios mínimos sobre base desechable.

### Dictamen
Esquema apto para instalación en base nueva (validación estática + compatibilidad
de runtime). Validación dinámica bloqueada por falta de infraestructura segura.
Informe: docs/produccion/INFORME-MISION-10.3.md.
