# Sensores, unidades y disponibilidad

> BeeStation · actualizado 2026-10-21 · firmware 3.2.0

Este documento fija **qué mide cada sensor, en qué unidad viaja y qué se
puede calcular realmente** con un nodo físico. Evita que el panel muestre
un valor con unidades equivocadas o que un indicador parezca calculado
cuando le faltan entradas.

## 1. Contrato de telemetría

| `tipo` | Unidad | Sensor físico | ¿Lo envía el ESP32? | ¿Lo envía el simulador? |
|---|---|---|---|---|
| `temperatura_interna` | °C | BME280 | Sí | Sí |
| `humedad_relativa` | %HR | BME280 | Sí | Sí |
| `presion` | hPa | BME280 | Sí | Sí |
| `peso` | kg | HX711 | Sí | Sí |
| `sonido` | **Hz** | MAX4466 (ADC) | Sí (frecuencia estimada) | Sí |
| `luminosidad` | lux | BH1750 | Sí | Sí |
| `temperatura_externa` | °C | DHT22 externo | **No** (no instalado) | Sí |
| `co2` | ppm | MQ-135 | **No** (no instalado) | Sí |
| `energia` | V | INA219 | **No** (no instalado) | Sí |

Consecuencia directa:

- **IBB** necesita `temperatura_interna` + `humedad_relativa` + `co2`.
  En un nodo físico sin MQ-135 queda **sin calcular** (el panel muestra
  «Sin datos suficientes»). No se rellena con ceros.
- **ΔT** necesita `temperatura_interna` + `temperatura_externa`.
  Sin el segundo DHT22 queda **sin calcular**.
- **EV** necesita `co2`; **H_miel** solo necesita humedad y sí se calcula.

El simulador virtual envía los 9 tipos, por eso en la colmena 2 los
indicadores sí aparecen. Es la forma prevista de probar el panel completo
sin instrumentar una colmena real.

## 2. `sonido`: de voltios a frecuencia

### El problema

El MAX4466 entrega una **tensión analógica** proporcional al nivel de
sonido. El firmware 3.1.0 enviaba el voltaje pico a pico, pero:

- la variable `sonido` está declarada en **Hz** (rango 0–20 000);
- el panel y `acustica.php` lo presentaban como frecuencia;
- `evaluarAlertaEnjambrazon()` solo dispara entre 400 y 600, un rango
  imposible para una lectura de 0–3.3 V.

Resultado: la alerta de enjambrazón **nunca** podía activarse y el valor
mostrado no significaba nada.

### La solución (firmware 3.2.0)

`beestation_node/acustica.h` estima la **frecuencia dominante** por
cruces por cero:

1. Muestrea el ADC a ~4 kHz durante 50 ms (hasta 256 muestras).
2. Resta la componente continua (media).
3. Calcula el pico y fija una histéresis del 25 % para ignorar ruido.
4. Cuenta cruces ascendentes y descendentes; cada ciclo son dos cruces.
5. `f = cruces / (2 · duración_real)`.

La duración se mide con `micros()`, no se supone: así las pausas del WiFi
no inflan la frecuencia.

### Validación

`tests/host/acustica_test.c` usa **el mismo encabezado** que el firmware
(no una réplica) y se ejecuta en el PC:

```powershell
gcc -O2 -Wall -Wextra -o acustica_test tests\host\acustica_test.c -lm
.\acustica_test
```

Resultado actual: **11/11**.

| Caso | Esperado | Obtenido |
|---|---|---|
| Tono 250 Hz | 250 | 243,1 |
| Tono 400 Hz | 400 | 392,2 |
| Tono 500 Hz | 500 | 494,1 |
| Tono 600 Hz | 600 | 596,1 |
| Tono 900 Hz | 900 | 894,1 |
| Silencio | 0 | 0 |
| Ruido de cuantización | 0 | 0 |
| 500 Hz + ruido 20 % | 500 | 494,1 |
| Onda cuadrada 400 Hz | 400 | 400,0 |
| Amplitud 0–1000 cuentas | 0,806 V | 0,806 V |

El sesgo de ~1,5 % es la cuantización de los cruces en una ventana de
64 ms; es aceptable frente al ancho de la banda crítica (400–600 Hz).

### Límites que hay que decir en voz alta

- Es una **estimación**, no un analizador de espectro. Una señal de banda
  ancha (ruido de colmena) produce la frecuencia media de cruces, no la
  frecuencia del «piping» de la reina.
- No se ha medido todavía en una colmena real. Hasta entonces, la alerta
  de enjambrazón debe tratarse como **experimental**, no como diagnóstico.
- Para una detección robusta haría falta un micrófono digital con FFT
  (p. ej. INMP441) o un acelerómetro, y una campaña de grabaciones etiquetadas.

## 3. Intervalo de muestreo

`INTERVALO_LECTURA = 30000` (30 s). Los comentarios del firmware decían
«cada 5 minutos» y no coincidían con el código ni con la documentación de
recogida offline; se corrigieron en la versión 3.2.0.

A 30 s son 2 880 filas/día por sensor. Con 6 sensores, ~17 280 filas/día
en la microSD (≈ 1,5 MB), muy por debajo de lo que soporta una tarjeta
de 1 GB.

## 4. Cómo verificar un nodo recién flasheado

1. Monitor Serie a **115200**: debe aparecer `MAX4466 Son ✓ <n> Hz <v> V`.
2. En `/status` del nodo: `sonido` en Hz y `sonido_v` en voltios.
3. Enviar una lectura y comprobar en la BD:

   ```sql
   SELECT s.tipo, l.valor_calibrado, l.fecha_hora
   FROM lectura l JOIN sensor s ON s.id_sensor = l.id_sensor
   WHERE s.tipo = 'sonido' ORDER BY l.fecha_hora DESC LIMIT 5;
   ```

4. Si el valor está entre 0 y 3,3, el nodo sigue enviando voltios: el
   firmware no se actualizó.
