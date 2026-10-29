# Instalación de BeeStation

## Instalación nueva

1. Crea una base de datos vacía y un usuario MariaDB con privilegios limitados para esa base.
2. Configura `config/db.local.php` desde `config/db.example.php` o define `DB_HOST`, `DB_NAME`, `DB_USER` y `DB_PASS`. En producción define también `APP_ENV=production`; no uses `root` ni contraseña vacía.
3. Carga el esquema consolidado en esa base:

   ```bash
   mysql -u USUARIO -p NOMBRE_BASE < database/schema.sql
   ```

   `schema.sql` no crea ni selecciona la base: el nombre lo decides tú, así que no puede aplicarse por error a otra base de la misma instancia. Usa `CREATE TABLE IF NOT EXISTS`, por lo que es seguro ejecutarlo dos veces; no altera tablas que ya existan. `schema.sql` es la ruta de instalación limpia y ya incluye el resultado de las migraciones `001`–`008`: **no apliques las migraciones sobre una instalación nueva**.

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

No importes `schema.sql` en una base con datos. Primero haz respaldo y revisa el resultado de cada migración en una copia de la base. Las migraciones solo se aplican a instalaciones existentes, en orden y una sola vez cada una. La migración `001_seguridad_firma_y_datos.sql` contiene cambios de esquema y actualizaciones de catálogo; ahora inventaría sensores repetidos en vez de borrarlos. La migración `004_nonce_antireplay.sql` crea la tabla necesaria para el contrato de firma con nonce.

La API y el firmware con nonce deben desplegarse coordinadamente. Firmware anterior no envía `X-BeeStation-Nonce` y no podrá autenticarse tras activar el backend nuevo.

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
