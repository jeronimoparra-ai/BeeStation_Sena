# Instalación de BeeStation

## Instalación nueva

1. Crea una base de datos vacía y un usuario MariaDB con privilegios limitados para esa base.
2. Configura `config/db.local.php` desde `config/db.example.php` o define `DB_HOST`, `DB_NAME`, `DB_USER` y `DB_PASS`. En producción define también `APP_ENV=production`; no uses `root` ni contraseña vacía.
3. Ejecuta `database/schema.sql` únicamente contra una base nueva/vacía. El script no borra bases, pero sus `CREATE TABLE` fallarán si ya existen tablas.
4. Provisiona la primera cuenta desde CLI. Define temporalmente `BEESTATION_BOOTSTRAP_NAME`, `BEESTATION_BOOTSTRAP_EMAIL` y `BEESTATION_BOOTSTRAP_PASSWORD` en el entorno del proceso y ejecuta:

   ```powershell
   php tools/crear-usuario-inicial.php
   ```

   Usa una contraseña aleatoria de al menos 12 caracteres. No la pongas en el historial de comandos ni en un archivo versionado; elimina las variables de entorno al terminar. El script solo funciona en CLI, no cambia cuentas existentes y guarda `password_hash()`.

## Actualizar una instalación existente

No importes `schema.sql` en una base con datos. Primero haz respaldo y revisa el resultado de cada migración en una copia de la base. La migración `001_seguridad_firma_y_datos.sql` contiene cambios de esquema y actualizaciones de catálogo; ahora inventaría sensores repetidos en vez de borrarlos. La migración `004_nonce_antireplay.sql` crea la tabla necesaria para el contrato de firma con nonce.

La API y el firmware con nonce deben desplegarse coordinadamente. Firmware anterior no envía `X-BeeStation-Nonce` y no podrá autenticarse tras activar el backend nuevo.

No se ejecutó ninguna migración en la instalación local durante esta auditoría.

## Verificaciones locales

```powershell
php tests/php/firma_test.php
$env:OPENSSL_CONF = 'C:\xampp\php\extras\openssl\openssl.cnf'
php tests/php/ecdsa_openssl_test.php
```

En otros servidores, configura `OPENSSL_CONF` a la ruta real del archivo `openssl.cnf` instalado con OpenSSL.
