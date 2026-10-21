# Autenticación del nodo y portal cautivo

> Sustituye a la antigua **API Key global**. Documento de referencia para la
> vinculación de nodos, el contrato de la API y la operación del portal.

---

## 1. Por qué cambió

El modelo anterior usaba una **clave global compartida**:

| Problema | Consecuencia |
|---|---|
| `BEESTATION_API_KEY` compilada en el firmware | Se extrae del binario con un lector de flash; la clave es la misma para todos los nodos |
| Viaja en claro en cada `POST` (HTTP) | Un *sniffer* pasivo en la LAN del apiario la captura |
| Versionada en git | Quedaba en el historial para siempre |
| Rotarla | Exigía **reflashear todos los ESP32** a la vez |
| Revocar un nodo | Implicaba tumbar el acceso de todos |

## 2. El modelo actual

**Una credencial por colmena, que nunca viaja por la red.**

```
Panel web                         ESP32
   │                                │
   │  genera un token de 128 bits   │
   │  (lo muestra una vez)          │
   ▼                                │
┌──────────────┐   el usuario lo   ▼
│  Token       │────pega en el──►  ESP32 (NVS)
│  BS-NODO-    │   portal          │  lo guarda y NUNCA
│  887E-D9F3-  │   cautivo         │  lo vuelve a enviar
│  C799-...    │                   │
└──────────────┘                   │  firma cada petición
       ▲                           │  con HMAC-SHA256
       │                           ▼
       │                    ┌──────────────┐
       │                    │  API PHP     │
       │                    │  verifica    │
       │                    │  la firma    │
       │                    └──────────────┘
```

### Lo que viaja en cada petición

| Cabecera | Valor | Para qué |
|---|---|---|
| `X-BeeStation-Mac` | `AA:BB:CC:DD:EE:FF` | Identifica al nodo (no es secreto) |
| `X-BeeStation-Timestamp` | epoch unix | Ventana anti-replay (±300 s) |
| `X-BeeStation-Signature` | HMAC-SHA256 en hexadecimal | Prueba que el nodo conoce el secreto |

Cadena canónica firmada (**debe coincidir exactamente**):

```
METODO \n RUTA \n TIMESTAMP \n sha256_hex(cuerpo)
```

- `METODO` en mayúsculas (`POST`)
- `RUTA` sin *query string* (p. ej. `/BeeStation_Sena/api/ingest.php`)
- `TIMESTAMP` tal cual viaja en la cabecera

### Garantías

| Propiedad | Cómo se consigue |
|---|---|
| El secreto no viaja | Solo se envía la firma |
| No hay clave global | Cada colmena tiene la suya; el firmware no lleva ninguna |
| Anti-replay | La marca de tiempo está dentro de la firma y el servidor acepta ±300 s |
| Anti-manipulación | El cuerpo va dentro del hash firmado |
| Rotación inmediata | Se regenera el token en el panel; el nodo se re-vincula |
| Revocación por nodo | `token_revocado = 1` en esa colmena |
| Entropía real | 128 bits (antes 24) |

---

## 3. Cómo vincular un nodo (paso a paso)

1. **En el panel**: `Dispositivos y Nodos` → registra la colmena (si no existe) y
   copia su **Token de Vinculación** (botón de copiar).
2. **En el ESP32**: enciende el nodo. Si no tiene configuración, levanta el portal
   cautivo. En el monitor serie se imprime la red y la clave:

   ```
   [AP]   Red    : BeeStation_Config
   [AP]   Clave  : bee12233
   [AP]   URL    : http://192.168.4.1
   ```

   La clave del AP se deriva de la MAC del nodo (`bee` + los 5 últimos dígitos).
3. Con el móvil o el portátil: conéctate a esa red y abre `http://192.168.4.1`.
4. Rellena: red del apiario, su contraseña y **pega el token**.
5. Pulsa **Vincular Colmena**. El nodo guarda la configuración, se reinicia y
   pasa a modo estación.
6. **Verificación**: en el monitor serie, `estado` muestra la MAC y el mDNS; en
   el panel, la colmena pasa a **En línea** con la MAC del nodo.

> La clave del AP también aparece en la propia página del portal, para poder
> consultarla desde otro dispositivo.

### Si el nodo ya estaba vinculado

No hace falta el portal: basta con copiar el token nuevo y pegarlo por el
monitor serie, o mantener el anterior. Si el token cambia, el nodo deja de
firmar correctamente y el backend responde `401` (no se pierden datos: quedan
en la microSD).

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
| Validación | Longitudes máximas (SSID 32, contraseña 64, token 64, host 64) |
| XSS | Los SSID se escapan antes de inyectarlos en el HTML |
| Escaneo WiFi | Se cachea 30 s (antes congelaba el nodo 2-4 s en cada carga) |
| Salida | Tras guardar, el nodo se reinicia en modo estación |
| Red sin internet | El nodo **no abre el portal**: sigue reintentando y guarda en la microSD |

---

## 5. Contrato de la API

### `POST /api/ingest.php`

```json
{
  "lecturas": [
    { "tipo": "temperatura_interna", "valor": 35.2, "ts": 1789456123, "seq": 1789456123 },
    { "tipo": "presion",             "valor": 1013.7, "ts": 1789456123, "seq": 1789456123 }
  ]
}
```

- **No lleva token**: la identidad la determina la firma.
- `ts` (epoch, opcional) = momento de la **medición**; sin él se usa la hora de llegada.
- `seq` (opcional) = número de secuencia; con `UNIQUE(id_sensor, seq)` el
  reenvío de un lote nunca duplica filas.
- Máximo 200 lecturas por petición (`413` si se supera).

Respuesta:

```json
{
  "ok": true,
  "ok_total": true,
  "colmena": "Nodo virtual (simulador)",
  "insertados": ["temperatura_interna", "presion"],
  "rechazadas": 0,
  "errores": [],
  "ibb_calculado": { "valor": 93.9, "estado": "Óptimo" },
  "delta_t": { "valor": 13.8, "estado": "Normal" },
  "ev": { "valor": 99.5, "estado": "Excelente" },
  "h_miel": { "valor": 18.4, "estado": "Cerca de madurar" }
}
```

`ok_total: false` indica que el backend aceptó el envío pero **descartó parte**
de los datos: el firmware debe avisar en vez de dar el envío por bueno.

### `POST /api/validate_token.php`

Cuerpo `{}` (el token no viaja). Devuelve `{"ok":true,"nombre_colmena":"...","mac":"..."}`.

### Códigos de respuesta

| Código | Significado |
|---|---|
| `200` | Correcto. Mirar `ok_total` y `errores[]` |
| `400` | JSON o lectura malformada |
| `401` | Falta la firma, es inválida, o el timestamp está fuera de ventana |
| `404` | La colmena ya no existe |
| `413` | Demasiadas lecturas en la petición |
| `422` | (reservado) todas las lecturas fueron rechazadas |

---

## 6. Requisitos del reloj del nodo

La firma incluye un *timestamp* que el servidor compara con su reloj
(tolerancia **±300 s**). Si el ESP32 no tiene NTP, el `ts` vale `0` y el
servidor omite la comprobación de ventana: la petición sigue siendo válida
(la firma lo demuestra), pero **no hay protección anti-replay**.

Para NTP: el firmware ya llama a `configTzTime()` al conectar. Si el nodo nunca
consigue sincronizar, conviene revisar la configuración de `pool.ntp.org`.

---

## 7. Rotación y revocación

| Acción | Cómo |
|---|---|
| Rotar el token | Panel → colmena → regenerar token → re-vincular el nodo |
| Revocar un nodo | `UPDATE colmena SET token_revocado = 1 WHERE id_colmena = ?` |
| Ver el nodo de una colmena | Panel → *Dispositivos y Nodos* → columna "Nodo" (MAC y último contacto) |
| Detectar un token usado desde otro equipo | La MAC registrada en `colmena.nodo_mac` no coincide con la de la petición |

---

## 8. Archivos que implementan el modelo

| Archivo | Rol |
|---|---|
| `includes/firma.php` | Verificación de la firma (servidor) |
| `api/ingest.php` | Ingesta autenticada por firma |
| `api/validate_token.php` | Validación de la credencial del nodo |
| `includes/functions.php` | Generación del token de 128 bits |
| `beestation_node/beestation_node.ino` | Firma en el nodo + portal cautivo |
| `beestation_node/index_html.h` | Formulario del portal (anti-CSRF, clave del AP) |
| `database/migrations/001_seguridad_firma_y_datos.sql` | Columnas, índices y variables nuevas |
