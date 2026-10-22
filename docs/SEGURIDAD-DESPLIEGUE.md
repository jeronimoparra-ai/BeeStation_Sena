# Checklist de seguridad y despliegue

## Docroot

- [ ] Publicar únicamente el contenido de la aplicación en el docroot. No copiar `tools/`, `tests/`, `database/`, `docs/`, `beestation_node/` ni `scripts/`.
- [ ] No dejar archivos `.sql`, `.ino` o `.h` en el docroot. Ejecutar `scripts/sincronizar-htdocs.ps1` y revisar su salida antes del despliegue.
- [ ] Confirmar que el docroot no contiene `.git`, volcados, copias antiguas ni archivos de configuración local ajenos a la aplicación.
- [ ] Mantener las credenciales locales fuera del control de versiones y restringir su lectura al usuario del servicio.
- [ ] Verificar que Apache aplica `.htaccess` (por ejemplo, `AllowOverride` para las directivas usadas) y que las rutas privadas responden con 404 o 403.

## Permisos

- [ ] El usuario del servidor web solo debe poder leer el código y escribir en los directorios que realmente necesiten carga o caché.
- [ ] Denegar escritura al servidor web sobre configuración, código, firmware, documentación, pruebas y volcados.
- [ ] No conceder permisos globales como `777`; comprobar propietario y grupo después de copiar los archivos.

## HTTPS y sesión

- [ ] Servir todo el panel por HTTPS y redirigir HTTP a HTTPS desde el servidor.
- [ ] Instalar y renovar un certificado válido; comprobar la cadena y la fecha de expiración.
- [ ] Activar HSTS cuando HTTPS esté confirmado para todos los subdominios necesarios.
- [ ] Confirmar que las cookies de sesión usan `Secure`, `HttpOnly` y `SameSite`, y que la aplicación valida sesión y CSRF en acciones sensibles.

## Backups y recuperación

- [ ] Programar copias de seguridad cifradas de la base de datos y los archivos de configuración necesarios.
- [ ] Guardar copias fuera del servidor de producción y limitar quién puede restaurarlas.
- [ ] Definir retención y comprobar periódicamente la restauración en un entorno aislado.
- [ ] No incluir volcados ni secretos en el repositorio o en el docroot.

## Verificación antes de publicar

- [ ] Revisar los cambios y los archivos que se copiarán; ejecutar primero el sincronizador con `-Simular`.
- [ ] Ejecutar `php -l` sobre los archivos PHP modificados y las pruebas CLI unitarias disponibles.
- [ ] Probar por HTTP que `config/`, `database/`, `beestation_node/`, `tools/`, `docs/`, `scripts/`, `tests/` y `.git/` no son accesibles.
- [ ] Confirmar que las páginas públicas y la API legítima siguen respondiendo correctamente.
- [ ] Revisar las cabeceras de seguridad, el certificado TLS, los permisos y los registros de Apache después del despliegue.
