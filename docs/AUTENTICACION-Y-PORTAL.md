# Autenticación del nodo y portal cautivo

> Modelo **sin token permanente**: cada nodo tiene su propia identidad
> criptográfica **ECDSA P-256**. El token compartido queda solo como ruta de
> transición en el backend, para los nodos que ya estaban desplegados.

---

## 1. Por qué cambió

| Modelo anterior (token / HMAC) | Modelo actual (identidad por nodo) |
|---|---|
| Un secreto por colmena, copiado a mano del panel al ESP32 | Cada nodo genera su clave al arrancar; no hay nada que copiar |
| El secreto vive en la NVS y en la base de datos | La clave **privada nunca sale del ESP32** |
| Rotarlo exige re-vincular el nodo | Cada nodo se revoca solo, sin tocar el resto |
| El panel muestra el secreto | El panel solo muestra identidad pública (id, MAC, huella) |
| Firma HMAC-SHA256 | Firma **ECDSA P-256 / SHA-256**, ASN.1 DER |

---

## 2. Identidad del nodo

La primera vez que arranca, el ESP32:

1. Genera un par de claves **ECDSA P-256** con `esp_random()` como entropía.
2. Guarda en su NVS (`namespace beestation`):
   `id_clave` (PEM privado), `id_pub` (SPKI PEM), `id_disp` (32 hex),
   `id_cod` (código de alta) e `id_ok` (alta confirmada por el servidor).
3. Se da de alta en `POST /api/enroll.php` con su clave pública y una
   **prueba de posesión**: firma con su clave privada una cadena que incluye
   la huella de su propia clave pública.
4. Queda **pendiente**. Un usuario autenticado lo autoriza desde
   `dispositivos.php` introduciendo el **código de 8 caracteres** (`XXXX-XXXX`)
   que el nodo muestra por el monitor serie y en su portal.

Hasta que se autoriza, el nodo **sigue midiendo y guardando en la microSD**,
pero no envía telemetría (el backend responde `401` con
`motivo: dispositivo_pendiente`).

### Cadenas canónicas

Firma de peticiones (`BEE2`):

```
BEE2 \n METODO \n RUTA \n TIMESTAMP \n MAC \n NONCE \n ID_DISPOSITIVO \n sha256_hex(cuerpo)
```

Prueba de posesión en el alta (`BEE2-POP`):

```
BEE2-POP \n ID_DISPOSITIVO \n MAC \n TIMESTAMP \n NONCE \n sha256_hex(PEM_PUBLICO)
```

Reglas:

- `METODO` en mayúsculas (`POST`).
- `RUTA` = ruta de la petición **sin query string**, tal como la ve el servidor
  (p. ej. `/BeeStation_Sena/api/ingest.php`).
- Hexadecimales **siempre en minúsculas**, incluido `sha256_hex`.
- La huella es el SHA-256 de los **bytes exactos** del PEM público.

### Cabeceras de una petición firmada

| Cabecera | Valor |
|---|---|
| `X-BeeStation-Dispositivo` | Identificador del nodo (32 hex) |
| `X-BeeStation-Mac` | `AA:BB:CC:DD:EE:FF` |
| `X-BeeStation-Timestamp` | epoch unix (±300 s) |
| `X-BeeStation-Nonce` | 128 bits aleatorios en hex |
| `X-BeeStation-Firma` | Firma ECDSA ASN.1 DER en hex |

La cabecera `X-BeeStation-Signature` (HMAC) queda reservada al esquema
heredado; ambos caminos conviven durante la transición.

### Garantías

| Propiedad | Cómo se consigue |
|---|---|
| La clave privada no viaja | Solo se envía la firma; el servidor guarda la pública |
| No hay secreto compartido | Cada nodo tiene su par de claves |
| Anti-replay | `UNIQUE(identificador, nonce)` en `nonce_dispositivo` + ventana de 300 s |
| Anti-manipulación | El SHA-256 del cuerpo va dentro de la cadena firmada |
| Revocación individual | `dispositivo.estado = 'revocado'` |
| Alta sin intervención | Prueba de posesión verificada con la clave recibida |
| Fuerza bruta del código | 5 intentos, caducidad de 30 min, solo se guarda el hash |

---

## 3. Cómo vincular un nodo (paso a paso)

1. **Enciende el nodo**. Si no tiene red configurada, levanta el portal
   cautivo. En el monitor serie se imprime:

   ```
   [IDENTIDAD] Nodo 3f9a...c1
   [IDENTIDAD] Huella  9b7e...42
   [IDENTIDAD] Codigo de vinculacion: K7Q2-9M4X
   [AP]   Red    : BeeStation_Config
   [AP]   Clave  : bee12233
   [AP]   URL    : http://192.168.4.1
   ```

   La clave del AP se deriva de la MAC (`bee` + 5 últimos dígitos).
2. Conéctate a `BeeStation_Config` y abre `http://192.168.4.1`.
3. Elige la red del apiario, su contraseña y (opcional) la IP del servidor.
   **No hay token que pegar**: el portal muestra el código de alta del nodo.
4. Pulsa **Guardar y conectar**. El nodo se reinicia en modo estación, se da de
   alta solo y queda **pendiente**.
5. **En el panel**: `Dispositivos y Nodos` → *Nodos IoT y Autorización de
   Dispositivos* → introduce el código `XXXX-XXXX` y elige la colmena.
6. El nodo consulta su estado cada 60 s y empieza a enviar en cuanto se
   autoriza. El panel lo muestra como **Autorizado** y la colmena como
   **En línea**.

> Si el nodo está revocado o el servidor no lo reconoce, vuelve a quedar
> pendiente y reintenta el alta automáticamente (por ejemplo, tras recrear la
> base de datos).

---

## 4. El portal cautivo

| Aspecto | Comportamiento |
|---|---|
| Red | `BeeStation_Config` (WPA2, **con contraseña**) |
| Clave | `bee` + 5 últimos dígitos de la MAC (se imprime por serie) |
| IP | `192.168.4.1` |
| Modo radio | `WIFI_AP_STA`: el nodo **conserva** la conexión del router |
| Anti-CSRF | Token por sesión en el formulario `/save` |
| `/save` | **Solo activo en modo portal**: en modo estación responde `403` |
| Validación | Longitudes máximas (SSID 32, contraseña 64, host 64) |
| XSS | Los SSID se escapan antes de inyectarlos en el HTML |
| Escaneo WiFi | Se cachea 30 s |
| Salida | Tras guardar, el nodo se reinicia en modo estación |
| Red sin internet | El nodo **no abre el portal**: reintenta y guarda en la microSD |

El formulario ya **no incluye el campo de token**: muestra el identificador y
el código de alta del nodo, que es lo único que el usuario necesita para
autorizarlo en la plataforma.

---

## 5. Contrato de la API

### `POST /api/enroll.php` — alta del nodo

```json
{
  "identificador": "3f9a…c1",
  "mac": "AA:BB:CC:DD:EE:FF",
  "nombre": "Nodo AA:BB:CC:DD:EE:FF",
  "clave_publica": "-----BEGIN PUBLIC KEY-----\n…\n-----END PUBLIC KEY-----\n",
  "codigo": "K7Q2-9M4X",
  "timestamp": 1792588800,
  "nonce": "0123456789abcdef0123456789abcdef",
  "firma": "3045022100…"
}
```

`firma` es ECDSA DER hex sobre la cadena `BEE2-POP`. Respuesta `200`:

```json
{ "ok": true, "estado": "pendiente", "autorizado": false,
  "id_colmena": null, "nombre_colmena": null,
  "mensaje": "Nodo registrado. Espera a que un usuario lo autorice en el panel." }
```

Si la identidad ya existía (mismo identificador y misma huella), el alta se
acepta de nuevo y **regenera el código** para que el usuario pueda autorizarla.

### `POST /api/enroll_estado.php` — estado del nodo

Cuerpo `{}`, firmado con las cabeceras ECDSA. Admite el estado `pendiente`
(el nodo necesita preguntar antes de tener permiso para enviar).

```json
{ "ok": true, "estado": "autorizado", "autorizado": true,
  "id_colmena": 4, "nombre_colmena": "Colmena Norte" }
```

### `POST /api/ingest.php` — telemetría

```json
{
  "lecturas": [
    { "tipo": "temperatura_interna", "valor": 35.2, "ts": 1789456123,
      "seq": 1789456123, "id_medicion": "a1b2…f0" }
  ]
}
```

- `id_medicion` (32 hex) lo genera el nodo **al tomar la lectura**. Los seis
  sensores de una misma medición comparten los 30 primeros dígitos y difieren
  en los dos últimos (`00`…`05`), de modo que cada fila de `lectura` tiene un
  identificador único con 120 bits de entropía.
- `ts` = momento de la medición; `seq` se conserva por compatibilidad.
- Máximo 200 lecturas por petición (`413` si se supera).

Respuesta (acuse **por lectura**):

```json
{
  "ok": true,
  "ok_total": true,
  "resultados": [
    { "indice": 0, "id_medicion": "a1b2…00", "estado": "insertada" },
    { "indice": 1, "id_medicion": "a1b2…01", "estado": "duplicada" }
  ],
  "insertados": 5,
  "rechazadas": 0
}
```

Estados por lectura: `insertada`, `duplicada`, `rechazada`.

El firmware avanza la cola de la microSD cuando `ok_total` es `true` **o**
cuando el servidor atendió las 6 lecturas de todas las filas del lote y alguna
fue rechazada de forma definitiva: así un dato inválido no bloquea la cola
para siempre.

### Códigos de respuesta

| Código | Significado |
|---|---|
| `200` | Correcto. Mirar `ok_total` y `resultados[]` |
| `400` | JSON o lectura malformada |
| `401` | Firma inválida, nonce reutilizado, timestamp fuera de ventana, nodo pendiente o revocado (mirar `motivo`) |
| `404` | La colmena ya no existe |
| `413` | Demasiadas lecturas en la petición |
| `429` | Demasiados intentos de alta o de código |

---

## 6. Requisitos del reloj del nodo

La firma incluye un *timestamp* que el servidor compara con su reloj
(tolerancia **±300 s**). Si el ESP32 no ha sincronizado NTP, **no firma**: la
lectura se queda en la microSD y se reintenta. Para NTP el firmware usa
`configTzTime("COT5", ...)` al conectar.

---

## 7. Revocación y recuperación

| Acción | Cómo |
|---|---|
| Revocar un nodo | Panel → *Nodos IoT* → **Revocar** (inmediato, solo ese nodo) |
| Ver identidad de un nodo | Panel → *Nodos IoT* (id corto, MAC, estado, último contacto) |
| Recuperar un nodo revocado | Borrar su identidad (botón BOOT al arrancar) y autorizarlo de nuevo |
| Nodo que no aparece | Comprobar que el alta llegó al servidor (`enroll.php`) y que el código no ha caducado |

---

## 8. Archivos que implementan el modelo

| Archivo | Rol |
|---|---|
| `includes/dispositivo.php` | Validación PEM, huella, cadenas, firma, alta, autorización, revocación |
| `includes/firma.php` | Verificación HMAC heredada (transición) |
| `api/enroll.php` | Alta con prueba de posesión |
| `api/enroll_estado.php` | Consulta de estado del nodo |
| `api/ingest.php` | Ingesta autenticada (ECDSA o HMAC heredado) |
| `beestation_node/identidad_ecdsa.h` | Identidad ECDSA en el ESP32 |
| `beestation_node/beestation_node.ino` | Firma, alta, sondeo y portal |
| `beestation_node/index_html.h` | Formulario del portal (sin token) |
| `dispositivos.php` | Autorización y revocación de nodos |
| `database/migrations/005_identidad_dispositivo.sql` | Tablas `dispositivo` y `nonce_dispositivo` |
| `database/migrations/006_identificador_medicion.sql` | `lectura.id_medicion` y acuse por lectura |
| `tests/php/ecdsa_flujo_test.php` | Flujo E2E del servidor |
| `tests/php/ecdsa_interop_test.php` | Interoperabilidad real Mbed TLS ↔ OpenSSL |
| `tests/php/interop_firmware_test.php` | Cadenas canónicas firmware ↔ servidor |
