# TAREA-HERMES-FASE-1.1

- **Identificador:** HERMES-010-2026-10-30
- **Agente:** Hermes CLI
- **Modo:** SOLO LECTURA (no commits, no push, no ramas, no borrados)
- **Fecha de emisión:** 2026-10-30
- **Emisor:** OpenCode (orquestador principal)

## 1. Objetivo

Revisión técnica independiente de la migración 010 y de las herramientas que la
aplican. Buscar errores reproducibles, no proponer reescrituras.

## 2. Contexto técnico

- Repositorio: `BeeStation_Sena` (raíz del proyecto).
- `HEAD`: `8cee2c50a11b53aaee22434b7472736ea2921802` — **intacto**.
- Los archivos de la migración 010 están **sin rastrear** (no hay commit).
- `tools/migrar-pruebas.php` está **modificado sin commit**: ahora verifica el
  invariante del índice tras aplicar las migraciones.
- Motor de pruebas: MariaDB **11.8.8** en `127.0.0.1:3310`. PHP 8.5.12.
- Producción/Hostinger: **prohibido**.
- Credenciales: las proporciona el usuario por variables de entorno. No las
  escribas en ningún archivo ni en el informe.

## 3. Archivos que puedes inspeccionar

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

## 4. Prohibiciones

- No modificar archivos, índices ni datos.
- No ejecutar `git add`, `commit`, `push`, `reset`, `clean`, `stash drop`.
- No aplicar la migración a `beestation_sena` ni a ninguna base real.
- No crear ni alterar usuarios/grants de MySQL.
- Las únicas bases permitidas son desechables con sufijo `_prueba` o `_test`,
  creadas y eliminadas por la propia prueba.
- Si detectas un defecto, **no lo corrijas**: descríbelo.

## 5. Pruebas que debes ejecutar

Con las credenciales que te dé el usuario:

```bash
MIG010_HOST=127.0.0.1 MIG010_PORT=3310 \
MIG010_USER=<usuario> MIG010_PASS=<clave> \
php tools/verificar-migracion-010.php
```

Debe terminar con **15/15** y eliminar las 13 bases creadas. Comprueba que no
quedan bases `mig010_*` nuevas tras la ejecución.

Prueba adicional de idempotencia del runner (solo con bases desechables):

```bash
DB_PRUEBA_HOST=127.0.0.1 DB_PRUEBA_PORT=3310 \
DB_PRUEBA_USUARIO=<usuario> DB_PRUEBA_CLAVE=<clave> \
DB_PRUEBA_NOMBRE=<algo>_prueba \
php tools/migrar-pruebas.php --recrear
# segunda ejecución sin --recrear
```

Comprueba que la segunda ejecución devuelve rc 0 y que el índice
`uq_variable_bioclimatica_nombre` es UNIQUE, de una columna `nombre`, sin
prefijo y BTREE. Elimina después la base de prueba.

## 6. Qué debes verificar

1. ¿El SQL aplica exactamente `UNIQUE (nombre)` y nada más?
2. ¿La detección de duplicados respeta la collation real y excluye `NULL`?
3. ¿La comprobación del índice exige UNIQUE, una sola columna, `nombre`, sin
   prefijo y BTREE? ¿Detecta homónimos compuestos y con prefijo?
4. ¿El DDL se ejecuta solo después de todas las comprobaciones y únicamente con
   `--aplicar`?
5. ¿La segunda ejecución del migrador no produce DDL ni cambios?
6. ¿El runner ya no acepta `Duplicate key name` como prueba de unicidad?
7. ¿Hay riesgo de pérdida o modificación de datos en algún camino?
8. ¿Funcionaría en MySQL 8? Distingue **demostrado**, **razonado** y **no
   verificado**.
9. ¿La limpieza de bases temporales puede borrar una base ajena?
10. ¿Queda algún defecto reproducible?

## 7. Formato del informe

```markdown
# Informe Hermes — HERMES-010-2026-10-30

## Veredicto
APROBADO | APROBADO CON OBSERVACIONES | RECHAZADO

## Evidencia ejecutada
(comandos y salidas reales; sin credenciales)

## Hallazgos
| ID | Severidad | Archivo:línea | Evidencia | Corrección esperada |

## Compatibilidad
(demostrada / razonada / no verificada)

## Limitaciones
```

## 8. Riesgos que debes buscar

- Falsos negativos de duplicados por collation, PAD SPACE o `NULL`.
- Índices homónimos aceptados como válidos.
- DDL ejecutado sin prechequeo o en modo lectura.
- Pérdida o reescritura de filas.
- Bases huérfanas o borrado de bases ajenas.
- Incompatibilidad MariaDB/MySQL no declarada.
- Diferencias entre lo que el `.sql` hace y lo que el migrador hace.
