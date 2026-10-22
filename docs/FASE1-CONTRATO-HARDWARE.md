# Fase 1 — Contrato de hardware y datos

> Estado: **preparación**. Este documento define el contrato que debe cumplir
> el nodo antes de instrumentar una colmena real. No afirma que las pruebas de
> hardware estén hechas.
>
> Fecha: 2026-10-21 · Firmware de referencia: 3.2.0 · Backend: esquema actual.

## 1. Principio

El backend no debe crear ni mostrar un sensor porque el esquema lo permita.
Solo debe existir un sensor cuando el nodo declara que ese hardware está
instalado. Un perfil incompleto no se rellena con ceros, valores nominales ni
lecturas de pruebas anteriores.

Estados visibles permitidos:

| Estado | Significado |
|---|---|
| `en_linea` | Hay una lectura reciente y válida. |
| `sin_datos_recientes` | El sensor está declarado, pero no reporta dentro del umbral. |
| `no_instalado` | El perfil del nodo no incluye ese hardware. |
| `calibracion_pendiente` | El sensor reporta, pero su escala no está validada. |
| `fuera_rango` | La lectura llegó, pero el backend la marcó como no válida. |

`sin_datos_recientes` no es lo mismo que `no_instalado`. El panel y el informe
deben conservar esa diferencia.

## 2. Hardware actual y límites

| Componente | Estado real | Consecuencia |
|---|---|---|
| ESP32 | Presente en el diseño del nodo. | Es la única plataforma probada para compilar. |
| BME280 (0x76/0x77) | Soportado por el firmware. | Temperatura interna, humedad relativa y presión. |
| BH1750 | Soportado por el firmware. | Luminosidad en lux. |
| HX711 + celda de carga | Soportado por el firmware, **sin calibración de campo validada**. | El peso se muestra como tendencia; no confirma flujo de néctar. |
| MAX4466 | Soportado por el firmware. | Frecuencia estimada en Hz; experimental. |
| microSD | Soportada por el firmware. | Cola local, todavía con semántica de acuse por validar. |
| RTC DS3231 | **No instalado.** | Sin NTP no hay hora fiable para firmar ni fechar. |
| INA219, batería, panel solar | **No instalados.** | No hay energía medida; el módulo sigue fuera del menú. |
| MQ-135 / CO₂ | **No instalado.** | IBB y EV no son diagnósticos disponibles. |
| DHT22 exterior | **No instalado.** | ΔT no está disponible. |

Las filas antiguas de la tabla `sensor` que etiquetan temperatura/humedad como
`DHT22` son herencia de pruebas. El firmware actual usa BME280. La UI muestra
el modelo del firmware; la limpieza de esas filas corresponde a esta fase.

## 3. Perfiles y capacidades

Perfiles previstos:

| Perfil | Sensores declarados |
|---|---|
| `basico` | `temperatura_interna`, `humedad_relativa`, `presion`, `peso`, `sonido`, `luminosidad` |
| `exterior` | `basico` + `temperatura_externa` |
| `co2` | `basico` + `co2` |
| `energia` | `basico` + `energia` |

Reglas:

1. El alta y `/status` deben declarar `perfil` y un mapa de capacidades
   (`bme280`, `bh1750`, `hx711`, `max4466`, `sd`, `rtc`, `ina219`, `co2`,
   `dht22_exterior`).
2. El backend crea las filas de `sensor` solo para capacidades declaradas.
3. Si una capacidad cambia, se actualiza el perfil; no se inventan lecturas
   para conservar filas antiguas.
4. Un sensor declarado pero no detectado al arrancar se reporta como
   `no_instalado` o `sin_datos_recientes`, nunca como cero.

## 4. Contrato de telemetría

### 4.1 Cabeceras

Se mantiene el esquema ECDSA actual:

```
Content-Type: application/json
X-BeeStation-Dispositivo: <identificador de 32 hex>
X-BeeStation-Mac:         AA:BB:CC:DD:EE:FF
X-BeeStation-Timestamp:   <epoch Unix usado en la firma>
X-BeeStation-Nonce:       <128 bits aleatorios en hex>
X-BeeStation-Firma:       <ECDSA ASN.1 DER en hex>
```

La firma cubre método, ruta, timestamp, MAC, nonce y hash del cuerpo. La clave
privada nunca sale del ESP32.

### 4.2 Cuerpo

```json
{
  "lote": "2026-10-21T18:30:00-05:00-001",
  "perfil": "basico",
  "capacidades": {
    "bme280": true,
    "bh1750": true,
    "hx711": true,
    "max4466": true,
    "sd": true,
    "rtc": false,
    "ina219": false,
    "co2": false,
    "dht22_exterior": false
  },
  "lecturas": [
    {
      "tipo": "temperatura_interna",
      "valor": 34.5,
      "unidad": "°C",
      "ts": 1792588800,
      "seq": 1042,
      "id_medicion": "0123456789abcdef0123456789abcdef"
    }
  ]
}
```

Reglas por lectura:

- `tipo`: uno de los seis tipos del perfil básico o de una capacidad adicional.
- `valor`: número real; nunca una cadena vacía ni un valor de relleno.
- `unidad`: debe coincidir con el contrato de unidades.
- `ts`: epoch de la **medición**, no de la subida. Si se reenvía desde la SD,
  conserva el `ts` original.
- `seq`: entero sin signo, **monótono por nodo** y persistido en NVS. No debe
  ser el epoch. Identifica la medición incluso si se repite la subida.
- `id_medicion`: 32 hex estables, generados al tomar la lectura. Tienen
  prioridad para deduplicar; `seq` es el respaldo para firmware heredado.
- `lote`: identificador del envío; se conserva al reenviar una cola SD.

El backend ya acepta `lote`, `ts`, `seq`, `id_medicion` y devuelve un resultado
por lectura. Lo que falta es declarar y persistir el perfil/capacidades,
sustituir `seq = epoch` por el contador monotónico y cerrar la semántica de la
cola.

### 4.3 Unidades y disponibilidad

| `tipo` | Unidad | Perfil que lo declara | Diagnóstico |
|---|---|---|---|
| `temperatura_interna` | °C | básico | Lectura real. |
| `humedad_relativa` | %HR | básico | Lectura real. |
| `presion` | hPa | básico | Lectura real. |
| `peso` | kg | básico | Tendencia; calibración pendiente. |
| `sonido` | Hz | básico | Experimental; no confirma enjambrazón. |
| `luminosidad` | lux | básico | Lectura real. |
| `temperatura_externa` | °C | exterior | No disponible sin DHT22. |
| `co2` | ppm | co2 | No disponible sin MQ-135. |
| `energia` | V | energia | No disponible sin INA219. |

## 5. Hora local y firma sin internet

Situación actual: el firmware arranca SNTP contra `pool.ntp.org`,
`time.google.com` y `time.nist.gov`. Sin hora válida no firma ni envía. En una
LAN sin router ni internet el nodo queda sin hora.

Opciones para Fase 1:

1. **RTC DS3231** en I2C: mantiene la hora entre cortes de energía.
2. **Endpoint local** `GET /api/time.php` desde el servidor de la LAN: el nodo
   obtiene una hora de referencia antes de firmar; NTP queda como respaldo.
3. Si no hay RTC ni endpoint, el nodo puede guardar con `seq` monotónico y
   `ts` desconocido en la SD, pero **no debe firmar una hora inventada**.

Criterio: una lectura no se convierte en “válida” por usar `millis()` como
epoch. La ventana de frescura del backend sigue siendo de 5 minutos.

## 6. Calibración del peso

El HX711 entrega una señal proporcional, no kilogramos. Antes de usar el peso
como diagnóstico:

1. Persistir `escala` y `offset` en NVS.
2. Exponer un asistente de calibración con dos masas conocidas.
3. Rechazar `NaN`, `Inf` y lecturas fuera del rango físico.
4. Marcar el sensor como `calibracion_pendiente` hasta completar la validación.
5. Aceptación: comparar con una báscula de referencia; objetivo ±20 g o ±0,1 %
   en el rango de la colmena.

Mientras eso no ocurra, la UI debe seguir diciendo “tendencia” y no “flujo de
néctar confirmado”.

## 7. Secuencia y cola offline

### 7.1 `seq` monotónico

- Contador `uint64` persistido en NVS e incrementado por cada lectura.
- Se conserva al reenviar desde la SD.
- `id_medicion` sigue siendo el identificador principal; `seq` evita
  colisiones cuando dos lecturas caen en el mismo segundo.

### 7.2 Acuse por lectura

El backend ya responde con `resultados[]`:

| Estado | Acción del nodo |
|---|---|
| `insertada` | Puede borrar la lectura de la cola. |
| `insertada_no_valida` | Puede borrarla: fue recibida y marcada fuera de rango. |
| `duplicada` | Puede borrarla: ya estaba en el servidor. |
| `rechazada` | **No** borrarla; corregir o reintentar. |

Regla: un HTTP 200 genérico no confirma la cola completa. Solo se avanza el
cursor después de interpretar el acuse por lectura o el `ok_total` del lote.

### 7.3 Robustez de la SD

- Aislar líneas corruptas sin bloquear el resto del archivo.
- Persistir el archivo activo y el cursor entre reinicios.
- Rotar por día o tamaño y comprobar espacio libre.
- No duplicar lecturas al reiniciar a mitad de un lote.
- Probar un corte de energía durante la escritura y durante el reenvío.

## 8. Matriz de pruebas de hardware

Estas pruebas requieren la placa física y no se pueden cerrar desde este
entorno:

- [ ] Alta con NVS limpia y código `4-4`; verificar que el código se persiste.
- [ ] `test` no inserta filas en `lectura`; `/status` no expone `codigo`.
- [ ] Portal cautivo sin router; salir del portal sin reiniciar.
- [ ] RTC o `GET /api/time.php`: firmar y subir en LAN sin internet.
- [ ] Calibración HX711 contra báscula de referencia.
- [ ] Corte de energía con cola SD; no perder ni duplicar lecturas.
- [ ] Reintento de un lote ya aceptado; todos los resultados deben ser
      `duplicada`, no `rechazada`.
- [ ] Nodo con un sensor desconectado; el panel debe mostrar
      `no_instalado` o `sin_datos_recientes`, nunca cero.
- [ ] 24 h de operación con intervalos de 30 s y revisión de memoria/heap.

## 9. Criterio de aceptación de Fase 1

- Un nodo con NVS limpia se da de alta, firma y sube en una LAN sin internet.
- Un corte de energía no pierde la cola ni duplica lecturas.
- El peso en kg coincide con una báscula de referencia (±20 g o ±0,1 %).
- El panel distingue `no_instalado` de `sin_datos_recientes`.
- El firmware declara perfiles y el backend crea solo los sensores declarados.
