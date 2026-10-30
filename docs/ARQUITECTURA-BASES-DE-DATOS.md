# ARQUITECTURA DE BASES DE DATOS — BeeStation_Sena

> Fuente de verdad inequívoca para la estructura SQL del proyecto.

---

## 1. ¿Cuál es el esquema oficial?

**`database/schema.sql`** es el único esquema oficial y la única ruta de instalación correcta.

Propiedades de `schema.sql`:

- Usa `CREATE TABLE IF NOT EXISTS` en todas las tablas → **idempotente de creación**.
- No crea ni selecciona la base de datos. El administrador decide el nombre: `mysql -u USUARIO -p NOMBRE_BASE < database/schema.sql`.
- No usa `DROP`, `ALTER`, `TRUNCATE` ni `CREATE DATABASE` en el flujo de instalación.
- Incluye un bloque `INSERT ... ON DUPLICATE KEY UPDATE` para el catálogo `variable_bioclimatica` → **idempotente de inserción de datos iniciales**.
- Contiene las 14 tablas del modelo, con 12 claves foráneas y 9 índices adicionales (más `PRIMARY KEY` y `UNIQUE KEY` por tabla).
- Es el resultado de aplicar, en orden, las migraciones `001`–`008` (y la limpieza del 2026-10-17 como independientes).

> **Regla de oro:** nunca importe `database/schema.sql` sobre una base con datos. Siempre respalde primero.

---

## 2. ¿Cómo instalo una base de datos nueva?

```bash
# 1. Crear la base y usuario (panel del hosting, o CLI)
CREATE DATABASE beestation_sena CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL ON beestation_sena.* TO 'beestation_u'@'localhost' IDENTIFIED BY 'STRONGPASS';

# 2. Cargar el esquema oficial (no olvidar el nombre de la base)
mysql -u beestation_u -p beestation_sena < database/schema.sql
```

- No se aplica `migraciones/*.sql` sobre una instalación **nueva**.
- `schema.sql` ya incorpora el resultado de `001`–`008` e incluye el catálogo de `variable_bioclimatica`.
- La primera cuenta se crea mediante `php tools/crear-usuario-inicial.php` (CLI) con variables de entorno bootstrap.

---

## 3. ¿Cuándo aplicar las migraciones?

- **Instalación nueva:** solo `schema.sql`. Las migraciones `001`–`008` **no** se aplican.
- **Instalación existente:** migraciones aplicadas una sola vez, en orden, sobre una copia antes de tocar el entorno real.
- Las migraciones son históricas y opcionales; `schema.sql` ya todo incluido.
- La migración `001_seguridad_firma_y_datos.sql` contenía `DELETE` de sensores duplicados → **no es idempotente y no debe reejecutarse**.

### Modo `--info` — limitación documental

`verificar-esquema.php --info` es **solamente lectura**: consulta `information_schema` y produce un `diff` de textos. **No es una comprobación de paridad estructural completa**:

- El parser simplificado de definiciones (`schemaSqlParseBody`/`schemaSqlFiltrarNivel`) no interpreta todos los patrones legales de `CREATE TABLE` (por ejemplo, `ON DUPLICATE KEY UPDATE` fuera de la definición de columna, o definiciones anidadas no contempladas en la aproximación de fragmentos por comas).
- Si el `diff` de `--info` es no vacío, **no automáticamente la base es errónea**: puede que falte una columna en la base real, o que el parser no reconozca un fragmento. Esto es habitual en bases antiguas (`beestation_sena`) o en columnas con `DEFAULT`, `ENUM`, `FLOAT`, etc.
- El modo **definitivo** de paridad es `verificar-esquema.php` en modo `full` (carga `schema.sql` en una base temporal y compara `information_schema`). Ese es el que se usa en CI.
- Las pruebas de este auditoría confirmaron que el parser no genera falsos positivos por `ON DUPLICATE KEY UPDATE` en el bloque de `variable_bioclimatica` (antes del fix generaba `COLUMNA\talerta\tON\tDUPLICATE`, que era un error del parser).

---

## 4. Entornos: desarrollo, pruebas, producción

| Entorno | Base | Datos | Regla de oro |
|---|---|---|---|
| **Desarrollo** | `beestation_sena` | Datos de desarrollo (pueden ser reales de campo) | Sin acceso automático a producción. Solo lectura/escritura local. |
| **Pruebas** | `beestation_prueba` o `beestation_test` | Datos **ficticios** únicamente | Se usa `BEE_DB_AISLADA=1`; ninguna prueba puede tocar la base real. |
| **Producción** | Nombre asignado en el panel | Datos reales | Usuario SQL dedicado. Respaldo propio. |

La separación entre pruebas y producción **no es** un solo fichero: se logra con (a) nombre distinto de la base, (b) variables de entorno separadas, (c) la guardia `BEE_DB_AISLADA=1`, (d) el script `verificar-esquema.php` que rechaza bases ajenas a la ejecución actual.

### `BEE_DB_AISLADA=1`

Es una **guía de uso**, no un control técnico de base de datos. Activarla no restringe por sí mismo las conexiones PDO; lo que lo hace:

- El código de pruebas usa `BEE_DB_AISLADA=1` para indicar que **sólo puede tocar la base de pruebas**.
- `verificar-esquema.php` reconoce esta variable y, además, **nunca borra una base temporal que no tenga el marcador de propiedad de la ejecución actual**.

No se confunde con un aislamiento de red o de usuario SQL.

---

## 5. Llaves primarias, índices, relaciones

- **Primary keys:** 14 (una por tabla). Todas `INT`/`BIGINT AUTO_INCREMENT`.
- **Unique:** `correo`, `token_vinculacion`, `uq_variable_bioclimatica_nombre`, `uq_sensor_colmena_tipo`, `uq_sensor_seq`, `uq_lectura_id_medicion`, `uq_dispositivo_identificador`, `uq_dispositivo_huella` → 8 índices únicos (incluyendo PRIMARY KEY).
- **Normal `INDEX`:** 9 (`idx_sensor_cal`, `idx_sensor_fecha`, `idx_fecha`, `idx_colmena_tipo_fecha`, `idx_estado_fecha`, `idx_intento_resultado_fecha`, `idx_lote_identificador`, `idx_lote_colmena`, `idx_lote_recibido`, `idx_nonce_dispositivo_fecha`, `idx_nonce_creado`).
- **Foreign keys:** 12 relaciones (`apiario → usuario`, `colmena → apiario`, `colmena → variable_bioclimatica`, `sensor → colmena`, `sensor → variable_bioclimatica`, `calibracion → sensor`, `lectura → sensor`, `indicador → colmena`, `alerta → indicador`, `alerta → usuario`, `lote_ingesta → colmena`, `dispositivo → colmena`, `dispositivo → usuario`).
- **Reglas de acción ON DELETE:** `RESTRICT` en `lote_ingesta`, `SET NULL` en `dispositivo`.

> Los `UNIQUE` declarados dentro de la cabecera de columna (`UNIQUE KEY ...` en línea) y los `INDEX`/`KEY` declarados explícitamente se cubren con `UNIQUE KEY` y `KEY` en el `CREATE TABLE`.

---

## 6. El verificador corregido (`scripts/verificar-esquema.php`)

Verifica que la base real (`beestation_sena` en desarrollo, u otra asignada) coincide con `database/schema.sql`.

### Comportamiento seguro

1. **Genera un identificador único** (`exec_id`) para cada ejecución.
2. **Crea la base temporal** con un nombre aleatorio (`beestation_verif_<random>_<pid>_<ts>_prueba`).
   - No usa `DROP DATABASE IF EXISTS`.
   - Si el nombre ya existe, **aborta y conserva** la base.
3. **Carga `schema.sql`** en la base temporal.
4. **Carga la estructura real** de `beestation_sena` (o la base indicada).
5. **Compara** columnas, índices y claves foráneas.
6. **Registra un marcador de propiedad** dentro de la base temporal (`verif_ownership` con la `exec_id`).
7. Solo **elimina la base temporal si el marcador coincide exactamente** con la ejecución actual.
8. **Nunca borra una base ajena**, tampoco en caso de fallo ni de interrupción.

### Modo `--info` (solo lectura)

- Compara `information_schema` de la base real contra `schema.sql`.
- No crea, no elimina, no ejecuta migraciones.
- Devuelve `OK` si coinciden, o `1` con el listado de diferencias.
- Es legado para desarrollo; el modo `full` (defecto) es el que se usa en CI.

---

## 7. La base local de desarrollo

`beestation_sena` es la base de desarrollo local. Con `BEE_DB_AISLADA=1` el software **debe** aplicarse únicamente sobre `beestation_sena` y nunca sobre bases de pruebas. El diseño de `config/db.php` permite sobrescribir la conexión con `config/db.local.php` o con variables de entorno (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `APP_ENV`).

---

## 8. `config/db.php` — resolución de configuración

1. `config/db.local.php` (si existe) → sobreescribe todas las constantes.
2. Variables de entorno (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`).
3. Valores por defecto asequibles para desarrollo.

En `APP_ENV=production` se rechaza `DB_USER=root` y contraseña vacía. La conexión centraliza todas las vistas y APIs; **no deben existir conexiones alternativas** que apunten a otras bases sin documentar.

---

## 9. Limitaciones de `schema.sql`

- No gestiona **migración de datos** ni renombrado de columnas.
- El bloque `INSERT ... ON DUPLICATE KEY UPDATE` **no elimina** registros antiguos; solo garantiza que los valores presentes en la lista no se dupliquen.
- El esquema está diseñado para una **instalación nueva**; partir de un dump antiguo (`beestation_sena.sql`, que contenía contraseñas en claro) requiere una serie de rendimientos manuales.
- El `verificar-esquema.php --info` es una aproximación simplificada del método de lectura; el modo `full` es el definitivo.

---

## 10. Operaciones que requieren privilegios

| Operación | Requiere |
|---|---|
| Instalar `schema.sql` sobre una nueva base | `CREATE`, `INSERT` en esa base |
| Ejecutar migraciones | `ALTER` en la base, privilegios de migración |
| `verificar-esquema.php` (modo `full`) | Usuario SQL con `CREATE`, `DROP` y `SELECT` en `information_schema`; la base temporal se gestiona por el marcador de propiedad |
| `verificar-esquema.php --info` | Solo `SELECT` en `information_schema` |

---

## 11. Operaciones exclusivamente de lectura

- `verificar-esquema.php --info`
- Consultas a `information_schema.COLUMNS`, `STATISTICS`, `REFERENTIAL_CONSTRAINTS`
- `SELECT DATABASE(), @@hostname, @@port, VERSION(), @@time_zone, NOW()` (diagnóstico de sesión)

---

## 12. Checklist antes de conectar Hostinger

- [ ] `database/schema.sql` es la fuente a usar (ya incluye `001`–`008`).
- [ ] Usuario SQL dedicado y credenciales en el panel, no en el repositorio.
- [ ] Respaldo independiente del dump de carga.
- [ ] `config/db.local.php` alojado fuera del repositorio (o generado en el servidor).
- [ ] `BEE_DB_AISLADA=1` en el pipeline de pruebas.
- [ ] `php scripts/verificar-esquema.php` devuelve `0` antes del primer despliegue.
- [ ] No se usa `DROP DATABASE IF EXISTS` en ninguna ruta automática.

---

*Documento realizado a partir de la auditoría de bases de datos de BeeStation_Sena (2026-10-30).*
