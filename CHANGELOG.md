# Changelog

## 2026-10-21

### Security
- Se requiere una marca de tiempo sincronizada y un nonce de un solo uso para las solicitudes firmadas; el nonce y la MAC forman parte de la firma canónica.
- Se endurecen las cookies de sesión, se rota el ID al iniciar sesión y se añade CSRF al login y al registro de colmenas.
- El alta de colmenas exige autenticación y comprueba la propiedad del apiario.
- La conexión a base de datos deja de usar `root` como valor por defecto y rechaza configuración débil en producción.

### Fixed
- La cola SD ya no avanza por un HTTP 200 parcial ni salta filas CSV sin fecha o malformadas.
- Se eliminaron impresiones visibles del token heredado desde el firmware.
- Se corrigió el layout anidado de `dispositivos.php`.

### Migration
- Aplicar `database/migrations/004_nonce_antireplay.sql` antes de habilitar el backend actualizado. Coordinar la actualización con el firmware; las versiones anteriores no envían nonce.

### Database
- El esquema limpio ya no elimina una base existente ni inserta la cuenta de demostración con credenciales publicadas.
- La migración 001 lista sensores duplicados para reconciliación y preserva sus relaciones existentes.
- Se añadieron al esquema limpio las variables presión/luminosidad, campos que usa la instalación actual, idempotencia `seq`, trazabilidad de intentos/lotes y nonces.
- Se añadió un aprovisionador CLI para crear la primera cuenta sin credenciales predefinidas.
