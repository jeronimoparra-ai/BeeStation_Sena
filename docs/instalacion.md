# Instalación de BeeStation

## Instalación nueva

1. Crea una base de datos vacía y un usuario MariaDB con privilegios limitados para esa base.
2. Configura `config/db.local.php` desde `config/db.example.php` o define `DB_HOST`, `DB_NAME`, `DB_USER` y `DB_PASS`. En producción define también `APP_ENV=production`; no uses `root` ni contraseña vacía.
3. Carga el esquema consolidado en esa base:

   ```bash
   mysql -u USUARIO -p NOMBRE_BASE < database/schema.sql
   ```

   `schema.sql` no crea ni selecciona la base: el nombre lo decides tú, así que no puede aplicarse por error a otra base de la misma instancia. Usa `CREATE TABLE IF NOT EXISTS`, por lo que es seguro ejecutarlo dos veces; no altera tablas que ya existan. **Es el único archivo SQL del proyecto**: contiene el esquema consolidado definitivo (el resultado de las migraciones `001`–`010`, incluida la unicidad de `variable_bioclimatica.nombre` y la trazabilidad de lotes). No existe carpeta `database/migrations/`. La verificación de correo (`009`) no forma parte del esquema y no debe aplicarse hasta que exista infraestructura SMTP validada.

   Para una base de pruebas aislada (nombre con sufijo `_prueba` o `_test`):

   ```bash
   DB_PRUEBA_NOMBRE=beestation_prueba php tools/migrar-pruebas.php
   ```
4. Provisiona la primera cuenta desde CLI. Define temporalmente `BEESTATION_BOOTSTRAP_NAME`, `BEESTATION_BOOTSTRAP_EMAIL` y `BEESTATION_BOOTSTRAP_PASSWORD` en el entorno del proceso y ejecuta:

   ```powershell
   php tools/crear-usuario-inicial.php
   ```

   Usa una contraseña aleatoria de al menos 12 caracteres. No la pongas en el historial de comandos ni en un archivo versionado; elimina las variables de entorno al terminar. El script solo funciona en CLI, no cambia cuentas existentes y guarda `password_hash()`.

## Actualizar una instalación existente

No importes `schema.sql` sobre una base con datos: está pensado para bases nuevas y
vacías. Para una instalación existente, primero haz respaldo y compara la estructura
real con `database/schema.sql` mediante `php scripts/verificar-esquema.php`. Aplica
manualmente (o con el migrador controlado) solo las diferencias detectadas; nunca
ejecutes `database/migracion-2026-10-17-limpiar-duplicados.sql` (contiene `DELETE`
y `USE beestation_sena`) ni ningún SQL histórico (la carpeta `database/migrations/`
ya no existe).

La API y el firmware con nonce deben desplegarse coordinadamente. Firmware anterior
no envía `X-BeeStation-Nonce` y no podrá autenticarse tras activar el backend nuevo.

### Unicidad de `variable_bioclimatica.nombre`

`schema.sql` ya incluye `UNIQUE KEY uq_variable_bioclimatica_nombre (nombre)`.
En una instalación existente donde el índice falte, **no ejecutes un `.sql`
sueltos** (ya no existe): usa el migrador controlado, que detecta duplicados en
SQL con la collation real, aborta antes del DDL y solo aplica si todo está en
orden.

```bash
MIG010_HOST=127.0.0.1 MIG010_PORT=3306 \
MIG010_USER=USUARIO MIG010_PASS='...' MIG010_DB=NOMBRE_BASE \
php tools/migrar-010-verificacion-bioclimatica.php --info
```

Sin `--aplicar` el migrador solo informa (código `4` si hace falta el índice).
Con `--aplicar` crea el índice; sobre una base cuyo nombre no termina en
`_prueba`/`_test` exige además `--permitir-base-real`. Códigos: `0` sin
cambios, `1` error de entorno o política, `2` índice homónimo incorrecto,
`3` duplicados (no se toca nada), `4` dry-run.

Si existen duplicados, el migrador lista **cada nombre y los IDs** afectados y
no modifica nada: la resolución es manual y auditada. Nunca fusiona ni borra
filas. La segunda ejecución del migrador termina con código `0` y sin DDL; la
segunda ejecución del `.sql` directo devuelve `Duplicate key name` (1061), que
**no** demuestra que el índice sea correcto.

### Verificación de correo (no implementada)

La verificación de correo **no está implementada** (SMTP no configurado). Por eso
`schema.sql` **no** incluye `correo_verificado`, `token_verificacion_hash` ni
`token_verificacion_expira`. No añadas esas columnas hasta que la funcionalidad
esté implementada y validada. `ADD COLUMN IF NOT EXISTS` es sintaxis exclusiva de
MariaDB y no está soportada en MySQL 8.0/8.4.

### Verificación del esquema

No existe una tabla central de historial de migraciones (y no se creará). Para
saber si una base existente coincide con el esquema oficial, ejecuta
`php scripts/verificar-esquema.php`. Anota cada cambio en el control de cambios
del despliegue.

## Verificación del esquema

Antes de cada despliegue, comprueba que la base real no se ha separado de `schema.sql`:

```bash
php scripts/verificar-esquema.php
```

El script crea una base temporal (`beestation_verif_prueba`), carga `schema.sql` y compara columnas, índices y claves foráneas contra la base real. Devuelve código `0` si coinciden y `1` si hay diferencias, indicando exactamente qué sobra o falta. La base temporal se elimina siempre al terminar.

El 2026-10-29 se reconcilió la base local `beestation_sena` con el esquema consolidado: se alinearon tipos y nulabilidad (`colmena`, `lectura`, `lote_ingesta`, `intento_vinculacion`), se renombró la clave primaria a `id_intento`, se añadió `UNIQUE (id_colmena, tipo)` en `sensor` y se ajustaron índices redundantes. Respaldo previo: `~/backups/beestation_sena-pre-reconciliacion-20261029-085950.sql`.

## Verificaciones locales

```powershell
php tests/php/firma_test.php
$env:OPENSSL_CONF = 'C:\xampp\php\extras\openssl\openssl.cnf'
php tests/php/ecdsa_openssl_test.php
```

En otros servidores, configura `OPENSSL_CONF` a la ruta real del archivo `openssl.cnf` instalado con OpenSSL.
