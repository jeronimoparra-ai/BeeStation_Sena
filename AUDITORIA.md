# Auditoría técnica — BeeStation_Sena

Fecha: 2026-10-13
Rama de trabajo: `audit/fixes-2026-10-13`
Alcance: revisión estática del backend PHP, el esquema SQL de instalación, la validación de telemetría, autenticación de sesión y la validación del token en el firmware ESP32.

## Correcciones aplicadas

### 1. El esquema de instalación no coincidía con las consultas del backend
**Archivos:** `database/schema.sql`

- Se retiró `DROP DATABASE IF EXISTS`. Importar de nuevo el esquema ya no elimina deliberadamente una base existente.
- Se añadieron a `colmena` los campos `token_vinculacion`, `intentos_fallidos_token` y `token_revocado`, consultados por `resolverColmenaPorToken()` y las funciones de vinculación.
- Se añadió la tabla `intento_vinculacion`, usada por los endpoints de la API y el panel de diagnóstico.
- Se añadieron las variables `presion` y `luminosidad`, y sus sensores iniciales BME280/BH1750, porque el firmware ya transmite esas magnitudes.
- La colmena inicial recibe un token de vinculación, requerido por el nuevo esquema.
- Se anotó que el archivo es para instalaciones nuevas, no una migración automática. Si las tablas ya existen, no debe reimportarse sin una migración adecuada.
- Se sincronizó `dispositivos.php` para que cada nueva colmena también registre los sensores de presión y luminosidad con los identificadores de variable 8 y 9; se mantuvieron los rangos ya usados por los sensores existentes.

**Motivo:** sin estos campos y tabla, una instalación construida desde el antiguo `schema.sql` no podía validar tokens correctamente; además, el firmware enviaba dos tipos de lectura que el esquema limpio no tenía registrados.

### 2. Validación robusta del JSON de telemetría
**Archivo:** `api/ingest.php`

- Se limita el cuerpo a 16 KiB y el lote a 1–32 lecturas.
- Se comprueba que el token sea texto y no exceda 64 caracteres.
- Se comprueba que cada lectura sea un objeto con un tipo de texto válido y un valor numérico finito.
- Las lecturas individuales malformadas se reportan en `errores` y se omiten, siguiendo la política previa de aceptar las lecturas válidas de un lote.

**Motivo:** PHP convertía entradas no numéricas a `0` con un cast directo y un token de tipo incorrecto podía generar errores de ejecución. El cambio reduce el riesgo de registrar valores inválidos sin cambiar el formato general de respuesta.

### 3. Validación del cuerpo en la prevalidación del token
**Archivo:** `api/validate_token.php`

- Se valida el JSON antes de acceder a `token`.
- Se limita el cuerpo a 4 KiB y el token a 64 caracteres.

**Motivo:** evita errores por JSON inválido o por enviar un token que no sea una cadena.

### 4. Regeneración del identificador de sesión al autenticar
**Archivo:** `login.php`

- Se llama a `session_regenerate_id(true)` tras verificar correctamente las credenciales y antes de establecer la sesión autenticada.

**Motivo:** impide conservar el identificador previo de sesión al cambiar de visitante a usuario autenticado.

### 5. Mayor entropía en tokens de nuevas colmenas
**Archivo:** `includes/functions.php`

- `generarTokenVinculacion()` usa ahora 16 bytes aleatorios (128 bits) en vez de solo 3 bytes (24 bits); el sufijo hexadecimal crece a 32 caracteres y cabe en la columna `VARCHAR(64)`.

**Motivo:** 24 bits ofrecen un espacio de búsqueda demasiado pequeño para una credencial de vinculación. Los tokens ya existentes no se cambian automáticamente porque eso desconectaría dispositivos y requiere coordinación con cada ESP32.

### 6. El firmware ya no interpreta cualquier respuesta HTTP como token válido
**Archivo:** `beestation_node/beestation_node.ino`

- Solo permite el modo offline cuando la petición falla a nivel de transporte (`code < 0`).
- Si el servidor responde con un código HTTP distinto de 200, la validación falla en lugar de asumir que el token es válido.

**Motivo:** antes, una respuesta 401, 404 o 500 también se trataba como modo offline y permitía continuar sin validar el token. Se mantiene la tolerancia a una desconexión real de red, pero los errores HTTP explícitos ya no se interpretan como autorización.

### 7. Protección de la página de diagnóstico
**Archivo:** `test_connection.php`

- Por defecto solo permite el acceso desde loopback (`127.0.0.1` / `::1`), porque la página muestra metadatos de conexión, versiones, conteos de tablas y mensajes internos de error.
- El acceso remoto requiere habilitarlo deliberadamente con la variable de entorno `BEESTATION_ALLOW_REMOTE_DIAGNOSTICS=1`; no se recomienda en producción.
- Se añadió `intento_vinculacion` a la lista de tablas comprobadas, para que corresponda con el esquema consolidado.

**Motivo:** un `noindex` no es control de acceso y la página estaba accesible sin autenticación.

### 8. Documentación sincronizada
**Archivo:** `README.md`

- Se corrigió el número de tablas de `schema.sql`.
- Se documentó que el dump de desarrollo es histórico y contiene filas de sensores duplicadas.
- Se aclaró qué endpoints requieren API Key y que `ping.php` queda público para descubrimiento local.
- Se advirtió que `schema.sql` no es un mecanismo de migración para instalaciones ya creadas.

## Hallazgos que requieren una intervención coordinada

### Alto — API Key expuesta en el repositorio público
La misma clave compartida está escrita en `config/db.php` y en el firmware del repositorio público. Debe considerarse comprometida. El volcado público también contiene tokens de vinculación conocidos, incluido el token de demostración del esquema. No se rotaron automáticamente porque hacerlo sin coordinar el servidor, los datos existentes y cada ESP32 puede interrumpir la ingesta. Antes de desplegar, reemplaza la clave por una credencial mantenida fuera del repositorio, provisiona la misma de forma privada al dispositivo y rota todos los tokens de colmenas creadas desde el dump público. Una rotación compartida no resuelve por sí sola el problema si la nueva clave se vuelve a publicar dentro del firmware; la solución robusta necesita credenciales por dispositivo o un mecanismo privado de provisionamiento.

### Alto — La alerta acústica compara unidades incompatibles
El firmware calcula nivel pico a pico en voltios, mientras que la lógica del backend compara `sonido` con una banda de 400–600 Hz. Una amplitud en voltios no es una frecuencia en hercios. No se alteró la fórmula a ciegas: hace falta implementar y calibrar una extracción de frecuencia (por ejemplo, análisis espectral) y acordar unidades consistentes antes de afirmar que la detección de enjambrazón funciona.

### Medio — Indicadores incompletos por falta de lecturas
El firmware incluido envía temperatura interna, humedad, presión, peso, sonido y luminosidad, pero no envía CO₂, energía ni temperatura externa. Por tanto, funciones que requieren CO₂, como el IBB y la EV, o temperatura exterior, como ΔT, seguirán devolviendo `null` hasta que el hardware/firmware produzca esas variables. No se generaron datos ficticios para llenar esos huecos.

### Medio — Portal cautivo ESP32 sin contraseña
El punto de acceso `BeeStation_Config` se crea sin contraseña. Durante el aprovisionamiento, terceros dentro del alcance inalámbrico podrían conectarse al portal. La corrección necesita definir una política de contraseña/provisionamiento compatible con el proceso físico de configuración.

### Medio — Volcado de desarrollo con sensores duplicados
`database/beestation_sena.sql` contiene filas duplicadas de presión y luminosidad. Se conserva como volcado histórico y no se modificó para evitar borrar o alterar datos de desarrollo sin confirmar si deben preservarse. Conviene depurarlo por separado antes de usarlo para una instalación nueva.

## Validación realizada y límites

- Revisión estática de los archivos relevantes en la rama `main`.
- Cambios guardados en una rama de auditoría separada; no se modificó `main`.
- **No se pudo ejecutar** una prueba de integración contra MySQL/MariaDB real ni compilar el firmware en esta ejecución. Tampoco hay verificación física de sensores.
- Antes de desplegar, ejecutar `php -l` sobre los PHP modificados, importar `database/schema.sql` solo en una base de prueba vacía y compilar `beestation_node/beestation_node.ino` con las versiones de bibliotecas del proyecto.

## Archivos modificados

- `database/schema.sql`
- `dispositivos.php`
- `includes/functions.php`
- `api/ingest.php`
- `api/validate_token.php`
- `login.php`
- `test_connection.php`
- `beestation_node/beestation_node.ino`
- `README.md`
