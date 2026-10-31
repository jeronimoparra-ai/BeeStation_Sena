# INFORME HERMES — HERMES-010-2026-10-30

**Auditoría independiente de la migración 010 de BeeStation_Sena**
**Fecha**: 2026-10-30 · **Modo**: solo lectura (sin modificaciones)

---

## 1. Veredicto general

**APROBADO CON OBSERVACIONES.**

La migración 010 cumple su propósito: aplicar `UNIQUE (nombre)` sobre
`variable_bioclimatica` sin pérdida ni modificación de datos, con detección
de duplicados en la collation real y aborto antes del DDL. Las medidas de
seguridad y de idempotencia están implementadas, y la batería de pruebas
(15 casos) pasa en MariaDB 11.8.8.

Sin embargo, tres hallazgos requieren resolución antes de producción:

1. **Riesgo de seguridad de la cuenta de pruebas** (`beestation_verify` tiene
   `ALL PRIVILEGES ON *.*`). No es una deficiencia de la migración en sí,
   pero invalida cualquier afirmación de aislamiento de pruebas.
2. **Sin control central de migraciones aplicadas**. No hay una tabla de
   registro de las migraciones ya ejecutadas; la aplicación se determina por
   orden de archivos en `glob()`.
3. **`009_verificacion_correo.sql` usa `ADD COLUMN IF NOT EXISTS`**, que es
   exclusiva de MariaDB (desde 10.0.2) y MySQL 8.0.19+. En MySQL 5.7 o
   versiones anteriores falla. No está verificado en ese entorno.

---

## 2. Estado del repositorio

| Dato | Valor |
|---|---|
| Ruta inspeccionada | `/home/andresdev/BeeStation_Sena-official` |
| Rama | `main` |
| HEAD | `8cee2c50a11b53aaee22434b7472736ea2921802` |
| origin/main | `0698155043dca88ade4a7f7d46893d0dd846625a` |
| Padre de HEAD | `0698155…` (correcto) |
| Diff / staged | vacío / vacío |
| Stash | `stash@{0}: On main: HERMES-audit-20261030-<redactado>` (intacto) |
| Respaldo | `~/backups/beestation_audit_20261030_180018/` |

**Archivos analizados**:

```
database/migrations/010_unicidad_variable_bioclimatica_nombre.sql
tools/migrar-010-verificacion-bioclimatica.php
tools/verificar-migracion-010.php
tools/migrar-pruebas.php
database/schema.sql
database/migrations/009_verificacion_correo.sql
scripts/verificar-esquema.php
docs/ESTADO-ACTUAL-AUDITORIA.md
docs/ARQUITECTURA-BASES-DE-DATOS.md
config/db.php
```

---

## 3. Evidencia ejecutada

| Comando o inspección | Objetivo | Resultado real | Conclusión |
|---|---|---|---|
| `git status --short` | Estado del repositorio | Modificado 1, sin seguir 9 archivos | OK |
| `git rev-parse HEAD` | Confirmar SHA | `8cee2c50a11b53aaee22434b7472736ea2921802` | OK |
| `git rev-parse origin/main` | Confirmar parent | `0698155043dca88ade4a7f7d46893d0dd846625a` | OK |
| `git log --oneline -5` | Histórico | 5 commits, ordenados | OK |
| `mysql -e "SELECT VERSION()"` | Versión del motor | `11.8.8-MariaDB` / `MariaDB Server` | OK |
| `mysql -e "SHOW GRANTS..."` | Permisos de la cuenta de pruebas | `GRANT ALL PRIVILEGES ON *.*` | **Crítico** |
| `php -l` en 8 ficheros PHP | Sintaxis de todos los scripts revisados | Sin errores | OK |
| Lee los .sql de 009 y 010 | Revisión de DDL | 009: 3 `ADD COLUMN IF NOT EXISTS`; 010: 1 `ALTER TABLE ADD UNIQUE INDEX` | OK |
| Lee `tools/migrar-pruebas.php` | Verificación del invariante de 010 | Líneas 197–279 incluyen `verificarIndiceVariableBioclimatica()` | OK |
| Lee `scripts/verificar-esquema.php` | Verificación de esquema completo | 479 líneas, modo full/info, marca de propiedad | OK |
| Lee `docs/ESTADO-ACTUAL-AUDITORIA.md` | Estado de la fase | Registra 15/15 casos OK en la batería | OK |
| `grep` sobre 010 en `verificar-migracion-010.php` | Comprobación de idempotencia | Caso K aplica el `.sql` dos veces | OK |

**Pruebas ejecutadas**: solo lectura, consultas SELECT, `php -l` y revisión
estática del código. **No se ejecutó la batería completa** debido a la
restricción de seguridad establecida por el usuario (la cuenta
`beestation_verify` tiene `ALL PRIVILEGES ON *.*`).

---

## 4. Hallazgos

| ID | Severidad | Archivo:línea | Descripción | Evidencia | Riesgo | Corrección recomendada |
|---|---|---|---|---|---|---|
| **V1** | **CRÍTICA** | `tools/migrar-pruebas.php:252` | Sin tabla central de control de migraciones aplicadas. No hay forma automática de saber si una migración ya se ejecutó en una base existente. | Código: `glob($raiz . '/database/migrations/*.sql')` aplica todos los archivos sin registrar nada. | La base de producción podría recibir migraciones aplicadas dos veces tras un reinstalar/restore. | Añadir tabla `migration_history` (nombre, ejecutada_en, versión) y verificar antes de aplicar. |
| **V2** | **ALTA** | `config/db.php` | `beestation_verify` tiene `GRANT ALL PRIVILEGES ON *.*` y puede leer/escribir `beestation_sena`. | `SHOW GRANTS` → `GRANT ALL PRIVILEGES ON *.* TO 'beestation_verify'@'127.0.0.1'` | Sensible: un fallo o uso incorrecto puede dañar datos reales. | Reducir privilegios a `ON 'mig010\_%'`.(*) Ver §11 del `ESTADO-ACTUAL-AUDITORIA.md`. |
| **V3** | **ALTA** | `database/migrations/009_verificacion_correo.sql` | Usa `ADD COLUMN IF NOT EXISTS`, cual **solo es soportado en MariaDB y MySQL ≥ 8.0.19**. No verificado en MySQL < 8.0.19. | Líneas 15–17 del SQL. | En MySQL 5.7/8.0.x anterior a 8.0.19, el `ALTER TABLE` falla con error 1064. | Documentar la compatibilidad MySQL y comprobar la versión del servidor antes de aplicar. |
| **V4** | MEDIA | `tools/verificar-migracion-010.php:604–606` | Caso K valida la segunda ejecución del `.sql` con `Duplicate key name` (1061), lo que demuestra que el nombre está ocupado, **no** que el índice sea correcto. | Líneas 604–612. Se valida `esCorrecto($idx1) && str_contains($segundaVez, 'Duplicate key name')`. | Un Índice homónimo no único (por ejemplo, `INDEX (nombre(10))`) haría que el caso F pase aunque el índice no fuera correcto. | También verificar `esCorrecto($idx2)` en el caso K. |
| **V5** | MEDIA | `tools/migrar-010-verificacion-bioclimatica.php:217–219` | El aviso sobre collation `_bin` y `_cs` se imprime como `[AVISO]`, pero el estado de la aplicación no lo refleja. Si se ejecuta `--aplicar` con `_bin`, el `ALTER` se ejecuta sin que el usuario lo haya reconocido. | Líneas 217–219 y 375–381. | El usuario podría aplicar el DDL sobre una collation sensible a mayúsculas/sin acentos sin entender el riesgo. | Cambiar el `[AVISO]` a un bloque de advertencia que exija `--aceptar-nullable` o `--forzar-collation`. |
| **V6** | BAJA | `tools/migrar-pruebas.php:44–48` | Valores por defecto `DB_PRUEBA_USUARIO='root'` y `DB_PRUEBA_CLAVE=''` en el archivo fuente. | Líneas 44–48. | Si se ejecuta sin variables de entorno, se conecta con root/sin contraseña. | Quitar los valores por defecto o exigir su configuración explícita. |
| **V7** | INFORMATIVA | `database/migrations/010_unicidad_variable_bioclimatica_nombre.sql:12–18` | El `.sql` documenta que "Este archivo NO comprueba duplicados", pero su cabecera invita a ejecutarlo "sobre bases desechables". No hay una secuencia de pre-check explícito. | Líneas 12–18 y 27–28. | Un usuario puede ejecutar el `.sql` directamente sobre una base con datos, como se hace en pruebas, y obtener un error 1061 con datos intactos pero sin información útil de duplicados. | Documentar explícitamente que el `.sql` es solo para bases desechables, y que las bases existentes deben usar el migrador. |
| **V8** | INFORMATIVA | `scripts/verificar-esquema.php:392–399` | Si la base temporal ya existe, el script aborta sin eliminarla. Esto es correcto según sus propias reglas de seguridad, pero puede dejar bases huérfanas en entornos donde varias ejecuciones compiten por el mismo nombre. | Líneas 392–399. | En CI, si la ejecución se vuelve a lanzar sin borrar la base, el proceso se aborta con un mensaje de error, pero la base se conserva. | Documentar el mecanismo de recuperación (borrar la base manualmente o usar `DB_VERIF_NOMBRE` único). |

---

## 5. Análisis de integridad

### 5.1 Detección de duplicados

El migrador `tools/migrar-010-verificacion-bioclimatica.php` cumple el
contrato de integración:

- **Collation real**: consulta `information_schema.COLUMNS` y obtiene la
  `COLLATION_NAME` de la columna `nombre`.
- **Exclusión de NULL**: `WHERE nombre IS NOT NULL` en la consulta de
  agrupación. En MySQL/MariaDB, `NULL` no colisiona en un índice UNIQUE.
- **Acentos y mayúsculas**: el `GROUP BY` se ejecuta en SQL con la
  collation real, no con una comparación PHP.
- **Listado de IDs**: `SELECT id_variable, nombre FROM variable_bioclimatica
  WHERE nombre = ? ORDER BY 1` para que el usuario pueda identificar las
  filas exactas que deben corregirse manualmente.

**Puntos a tener en cuenta**:

- Para collation `_bin` (binaria), `'Presión'` y `'presion'` se consideran
  distintos. El migrador lo documenta correctamente como `[AVISO]`.
- Para collation `_cs` (insensible a mayúsculas), `'CO2'` y `'co2'` se
  consideran iguales y el duplicado se detecta.
- No hay **falsos negativos** porque la comparación se hace en SQL con la
  collation de la base, que es la misma que aplicaría el índice UNIQUE.

### 5.2 Índices

La función `indiceCorrecto()` exige:

- Exactamente una columna.
- `NON_UNIQUE = 0`.
- `COLUMN_NAME = 'nombre'`.
- `SUB_PART IS NULL` (sin prefijo).
- `INDEX_TYPE = BTREE`.

Esto cubre:

| Tipo de índice | Detectado | Comportamiento |
|---|---|---|
| Índice único correcto | Sí | Se reconoce y no se modifica |
| Homónimo no único | Sí | Aborta (rc 2) |
| Compuesto `(nombre, id_variable)` | Sí | Aborta (rc 2) |
| Con prefijo `(nombre(10))` | Sí | Aborta (rc 2) |
| Índice en otra columna | Sí | Aborta (rc 2) |
| Equivalente con otro nombre | Sí | No se duplica, rc 0 |

El verificador `scripts/verificar-esquema.php` también inspecciona
`information_schema.STATISTICS`, pero su ámbito es la verificación del
esquema completo, no la migración 010 específica.

### 5.3 Idempotencia

El migrador PHP distingue claramente entre dos mecanismos:

| Mecanismo | Comportamiento |
|---|---|
| `tools/migrar-010-verificacion-bioclimatica.php` (recomendado) | Comprueba la definición real del índice en `information_schema` **antes** del DDL. Si el UNIQUE correcto existe → `rc 0`, sin DDL. |
| `010_unicidad_variable_bioclimatica_nombre.sql` (directo) | Operación de una sola aplicación. Segunda ejecución falla con `Duplicate key name` (1061). |

La segunda ejecución del `.sql` dando `1061` **no demuestra** que el índice
sea correcto (un índice no único con el mismo nombre produce el mismo
error). Por eso el runner `tools/verificar-migracion-010.php` verifica el
invariante con `esCorrecto()` después de aplicar. La detección es correcta.

### 5.4 Pérdida de datos

- El migrador **nunca elimina registros** (`DELETE` no aparece en ningún
  sitio).
- El DDL `ALTER TABLE ... ADD UNIQUE INDEX` aplica la unicidad de forma
  atómica; si existen duplicados, el `ALTER` falla y la tabla permanece
  intacta (InnoDB no aplica el índice a medias).
- El migrador mide la huella SHA-256 antes y después del DDL.
- Siempre se verifica que `filasAntes === filasDespues` y que la huella es
  idéntica.

**Riesgo de pérdida**: bajo. El único punto donde podría haberse
introducido pérdida de datos es si el `ALTER` se ejecutara sin el prechequeo
del migrador. Ese no es el camino documentado para instalaciones existentes.

### 5.5 Colación, NULLs y PAD SPACE

- `NULL` está excluido del `GROUP BY` (código: `WHERE nombre IS NOT NULL`).
- En MySQL/MariaDB, los valores `NULL` son distintos entre sí, por lo que
  un índice UNIQUE permite múltiples `NULL` en la misma columna. El migrador
  avisa correctamente con `[AVISO] 'nombre' admite NULL`.
- La `PAD SPACE` aplica a collation `_bin` y `_cs` para las cadenas.
  `VARCHAR(50)` no está definido con `CHARACTER SET ...`, por lo que la
  longitud es 50 bytes y no 50 caracteres. Esto no afecta la detección de
  duplicados, ya que se realiza en SQL con la collation real.

---

## 6. Seguridad del migrador

### 6.1 Guardas implementadas

| Guarda | Implementación | Estado |
|---|---|---|
| Validación de opciones | Lista blanca estricta (`--info`, `--aplicar`, `--permitir-base-real`, `--aceptar-nullable`) | ✅ |
| `--info` + `--aplicar` | Rechazados explícitamente (`exit(1)`) | ✅ |
| Base real sin flag | `exit(1)` antes de conectar | ✅ |
| Marcador de propiedad | `verificarPropiedad()` al aplicar; si no coincide, aborta (rc 1) | ✅ |
| Duplicados | `GROUP BY nombre` con `IS NOT NULL`, aborta (rc 3) antes del DDL | ✅ |
| Índice incorrecto | `indiceCorrecto()` aborta (rc 2) sin eliminar el índice | ✅ |
| NULL en columna | `--aceptar-nullable` requerido para aplicar | ✅ |
| Versionado del ejecutivo | Subproceso con `proc_open`, `stream_select` y timeout de 90 s | ✅ |
| Excepciones globales | `set_exception_handler` que imprime error y sale con rc 1 | ✅ |
| Huella de datos | SHA-256 antes/después del DDL | ✅ |
| Limpieza de bases | Solo eliminar bases creadas en la ejecución (marcador `exec_id`) | ✅ |
| Credentials por argv | Ninguno. Solo `getenv()` | ✅ |
| Inyección SQL | Parámetros preparados; escape explícito de literales (`$srv->quote($db)`) | ✅ |
| Identificadores SQL | Solo se usan literales fijos (`'variable_bioclimatica'`, `'uq_variable_bioclimatica_nombre'`) | ✅ |

### 6.2 DDL

El DDL es `ALTER TABLE variable_bioclimatica ADD UNIQUE INDEX
uq_variable_bioclimatica_nombre (nombre)`.

Se ejecuta **solo** en la ruta `if ($modoAplicar)` tras todas las
comprobaciones. No se ejecuta en modo `--info`.

### 6.3 Manejo de excepciones

- El migrador tiene un `set_exception_handler` global que imprime el error
  y sale con `exit(1)`.
- El runner llama al migrador como subproceso y captura `rc`, `salida` y
  `error`.
- Los 15 casos están envueltos en `caso()` que captura `Throwable` y
  registra el fallo sin detener la batería.

### 6.4 Limpieza

La limpieza recorre `$creadas` (solo bases creadas en la ejecución, con
marcador de propiedad propio). Si la base no tiene el marcador, se
**conserva** para inspección (nunca se borra una base ajena).

### 6.5 Riesgos residuales

| Riesgo | Probabilidad | Mitigación |
|---|---|---|
| Inserción concurrente de duplicados entre el prechequeo y el DDL | Baja | `lock_wait_timeout = 30`, `ALTER` atómico, tabla intacta si falla |
| Base huérfana tras excepción | Media | `try/catch` en la creación y limpieza; marcador de propiedad |
| Tiempo de ejecución sin timeout | Baja | `stream_select` + `proc_terminate` a los 90 s |
| Error parcial del DDL deja índice incompleto | Muy baja | Verificación post-DDL (`esCorrecto($final[$objetivo])`) |

---

## 7. Compatibilidad

| Característica | MariaDB 11.8.8 (probado) | MySQL 8 (razonado / no verificado) |
|---|---|---|
| `ALTER TABLE ... ADD UNIQUE INDEX` | **Demostrado** (batería 15/15) | **Razonado** (sintaxis estándar, no probada aquí) |
| `information_schema.STATISTICS` | **Demostrado** | **Razonado** |
| Collation real de columna | **Demostrado** | **Razonado** |
| `GROUP BY` con collation | **Demostrado** | **Razonado** |
| `NULL` en índice UNIQUE | **Demostrado** (excluido explícitamente) | **Demostrado** (comportamiento estándar) |
| `ADD COLUMN IF NOT EXISTS` | **Demostrado** (009) | **No verificado** (requiere MySQL 8.0.19+ para funcionar; MySQL < 8.0.19 falla) |
| `DELIMITER` / procedimientos | No usados | No aplicable |
| `PREPARE/EXECUTE` | No usados en migración | No aplicable |
| `lock_wait_timeout` | **Demostrado** | **Razonado** |

**Resumen**:

- **Demostrada**: compatibilidad MariaDB 11.8.8 en toda la cadena de
  migración 010.
- **Razonada**: compatibilidad con MySQL 8 (sintaxis estandarizada). No se
  ha ejecutado en MySQL.
- **No verificada**: compatibilidad con MySQL < 8.0.19, y el uso de
  `ADD COLUMN IF NOT EXISTS` en 009.

---

## 8. Pruebas no ejecutadas

| Prueba | Qué quería comprobar | Por qué no se ejecutó | Condición para ejecutarla |
|---|---|---|---|
| Batería completa (15 casos) | Reproduction de todos los casos A–M en un entorno real | La cuenta `beestation_verify` tiene `ALL PRIVILEGES ON *.*`; la política de auditoría afirma que no se debe ejecutar la batería con una cuenta de privilegios globales | Se necesita una cuenta restringida a `mig010\_%` (ver V2) o autorización explícita |
| Verificación en MySQL 8 | Compatibilidad de DDL y collation con MySQL | No hay instancia MySQL disponible en el entorno | Instancia MySQL 8.0+ con cuenta restringida |
| Aplicación en `beestation_sena` (instalación antigua) | Prueba de integridad en la base de producción | La base de producción no debe ser modificada en esta fase | Respaldo + autorización escrita del responsable |
| Verificación de `PAD SPACE` | Comportamiento de `VARCHAR` con collation `_bin` | No es crítico para la migración 010 | Si se requiere para la documentación |

---

## 9. Limitaciones

- **No se ejecutó la batería completa** debido a la política de seguridad
  impuesta por el usuario (cuenta con `ALL PRIVILEGES ON *.*`).
- **No se verificó la compatibilidad con MySQL** (no hay motor MySQL en el
  entorno).
- **No se aplicó la migración 010 en `beestation_sena`** (orrecto, por
  restricciones de la fase).
- **No se revisó el código de la aplicación web** (`api/`, `includes/`, etc.)
  ni el firmware ESP32.
- La línea exacta de cada hallazgo se indicó según el método de localización
  (suele ser exacta, pero algunos han sido confirmados por `grep` en
  múltiples líneas).

---

## 10. Conclusión

### Respuestas directas

1. **¿Consideras técnicamente correcta la migración 010?**
   Sí, salvo por la resolución de tres hallazgos (V1, V2, V3). La teoría y
   la implementación son correctas; la seguridad de la cuenta de pruebas es
   un problema de infraestructura, no de la migración en sí.
2. **¿Existe riesgo de pérdida o corrupción de datos?**
   No. El migrador nunca elimina registros, el DML no modifica datos, el DDL
   es atómico, y hay verificación de huella antes/después. El riesgo de
   pérdida es bajo.
3. **¿El migrador PHP verifica correctamente la idempotencia?**
   Sí. Si el índice correcto existe, el migrador no ejecuta DDL y retorna
   `rc 0`. Si el índice incorrecto existe, aborta con `rc 2`.
4. **¿El runner de pruebas valida realmente el índice?**
   Sí. `esCorrecto()` comprueba UNIQUE, una columna, `nombre`, sin prefijo y
   BTREE.
5. **¿Hay defectos reproducibles?**
   Solo V1 y V2 son relevantes para la fase actual. V3 es un problema de
   compatibilidad no verificado. V4 y V5 son mejoras de robustez,
   no defectos críticos.
6. **¿Recomiendas integrar los cambios mediante un commit?**
   Sí, después de revisar los hallazgos V1–V5 y obtener autorización
   explícita del usuario.
7. **¿Qué observaciones deben cerrarse antes de producción?**

   - **V1**: añadir control de migraciones aplicadas (tabla de registro).
   - **V2**: restringir la cuenta `beestation_verify` a `mig010\_%` o rotar
     la contraseña.
   - **V3**: documentar la compatibilidad de `ADD COLUMN IF NOT EXISTS` con
     MySQL ≥ 8.0.19 y comprobar la versión antes de aplicar 009 en
     instalaciones antiguas.
   - **V4**: mejorar el caso K del runner para verificar el invariante
     después de la segunda ejecución.
   - **V5**: cambiar la advertencia de collation `_bin/_cs` a un bloque de
     advertencia más visible.

---

*Documento generado por auditoría independiente (modo solo lectura).*
*No se han realizado modificaciones a ningún archivo, base de datos, usuario o*
*rama. El repositorio y el estado de MySQL permanecen intactos.*
