<div align="center">

# 🐝 BeeStation — Cómo funciona

### Resumen técnico: sensores, cálculos y base científica

*De la abeja al número de la pantalla.*

</div>

---

## 1. El sistema en pocas líneas

> **BeeStation es un médico que nunca abre la colmena.** Mide desde afuera, calcula por dentro y avisa si algo se sale de lo normal.

Todo se sostiene en la **bioclimatología apícola**: la colmena mantiene un microclima tan estable que leerlo equivale a diagnosticar.

```
  SENSOR  →  ESP32  →  WIFI  →  PHP  →  MySQL  →  DASHBOARD
  medir      convertir  enviar   validar   guardar    mostrar
  (físico)   (°C, kg)   (JSON)   (API Key) (lectura)  (gráficas)
```

| Paso | Dónde | Qué pasa |
|:-:|---|---|
| 1 | Sensor | Algo físico genera señal eléctrica |
| 2 | ESP32 (ADC 12 bits) | Se vuelve un número: `0 – 4095` |
| 3 | Firmware | Se traduce a unidades: `°C`, `kg`, `lux`, `V` |
| 4 | WiFi + HTTP | `POST` a `api/ingest.php` con API Key y token |
| 5 | PHP + MariaDB | Se valida, se calibra y se guarda |
| 6 | PHP | **Se calculan IBB, EV, ΔT, H_miel y alertas** |

---

## 2. 🔑 ¿Dónde empiezan los cálculos reales?

Hay **cuatro capas de matemática**. Solo la última *interpreta*; las tres primeras solo preparan el dato.

| Capa | Pregunta | Ejemplo | ¿Ciencia de las abejas? |
|:--:|---|---|:--:|
| **1** Física | ¿Cuánta electricidad? | `lectura = 2743` | ❌ |
| **2** Conversión | ¿Cuántos °C o kg? | `volts = (pico×3.3)/4095` | ❌ |
| **3** Calibración | ¿Miente el sensor? | `calibrado = bruto + factor` | ⚠️ |
| **4** **Interpretación** | **¿Está bien o mal?** | `IBB = 100 − (0.45·ft + 0.35·fh + 0.20·fc)` | ✅ |

> **Respuesta:** los cálculos que identifican si algo sube o baja empiezan en la **capa 4**, en `includes/functions.php`.

---

## 3. Sensores: qué miden y por qué importa

| Sensor | Chip | Mide | ¿Por qué le importa a la abeja? |
|---|---|---|---|
| 🌡️ **Temp. interna** | BME280 | °C | **La más importante.** Las larvas solo se desarrollan entre 34–36 °C; 1–2 °C fuera de rango ya daña |
| 🌡️ **Temp. externa** | BME280 | °C | Da contexto: sin ella no sabes si la colmena se está esforzando |
| 💧 **Humedad** | BME280 | %HR | Cría necesita alta; la miel debe bajar de 20 % para no fermentar |
| ⚖️ **Peso** | HX711 | kg | Mide **directamente el resultado**: cuánta comida entra y sale |
| 🔊 **Acústica** | MAX4466 | *(ver §6)* | Intenta oír el piping de reina (400–500 Hz) |
| 🌫️ **CO₂** | *no instalado* | ppm | Respiración de la colonia; obliga a ventilar |
| ☀️ **Luz** | BH1750 | lux | Indica actividad de forrajeo |

---

## 4. Los cuatro cálculos

### 🟢 IBB — Índice de Bienestar Bioclimático
*Una nota de 0 a 100, con ponderaciones.*

```
IBB = 100 − ( 0,45·desv_temp + 0,35·desv_humedad + 0,20·desv_CO₂ )
  desv_temp    = |temp − 35| ÷ 3 × 100
  desv_humedad = |HR − 60| ÷ 20 × 100
  desv_CO₂     = |CO₂ − 3000| ÷ 3000 × 100
```

**Ejemplo real (datos de la propia BD):** 34.5 °C · 62.1 %HR · 320 ppm

| Desviación | Cálculo | Resultado |
|---|---|---:|
| temp | \|34.5 − 35\| ÷ 3 × 100 | 16.67 |
| humedad | \|62.1 − 60\| ÷ 20 × 100 | 10.50 |
| CO₂ | \|320 − 3000\| ÷ 3000 × 100 | 89.33 |
| **penalización** | 0.45·16.67 + 0.35·10.50 + 0.20·89.33 | **29.04** |
| **IBB** | 100 − 29.04 | **71.0** |

> ✅ La BD guarda exactamente `IBB = 71, "Bueno"`. El cálculo manual lo reproduce.

| 85+ | 70–84 | 50–69 | 30–49 | <30 |
|:--:|:--:|:--:|:--:|:--:|
| 🟢 Óptimo | 🔵 Bueno | 🟡 Regular | 🟠 Deficiente → **alerta** | 🔴 Crítico |

### 🔵 EV — Eficiencia de Ventilación
`EV = 100 − |CO₂ − 3000| ÷ 3000 × 100`
Con los mismos 320 ppm: **EV = 10.7 → "Deficiente"** ✅ (también verificado contra la BD)

### 🟡 ΔT — Diferencial térmico
`ΔT = temp_interna − temp_externa` · si `ΔT < 2` → **posible colmena muerta**
*Una colmena viva siempre está más caliente que el ambiente.*

### 🟠 H_miel — Humedad estimada de la miel
`H_miel = 12 + (HR − 40) × 0.35` → ≤18 lista · ≤21 cerca · >21 inmadura
*Aproximación declarada en el propio código: no hay sensor dentro de la miel.*

---

## 5. ⚖️ El peso: ¿cómo sabe que sube o baja?

**Es el cálculo más simple del sistema — una sola resta:**

```php
// includes/functions.php:311
flujo = último_peso_últimas_24h − primer_peso_últimas_24h
```

| Hora | 00:00 | 12:00 | 24:00 | **Flujo** |
|---|---:|---:|---:|---:|
| Día bueno | 42.10 | 43.80 | **43.95** | **+1.85 kg** ✅ |
| Día malo | 43.95 | 42.10 | **41.05** | **−2.90 kg** ⚠️ |

### ¿Qué significa biológicamente que baje?

| Causa | Velocidad | Cómo se distingue |
|---|---|---|
| 🐝 **Enjambre** | Caída **repentina** (minutos) | −8 a −15 kg de golpe, casi siempre en un día |
| 🌤️ **Mal tiempo** | Pérdida **lenta** | Día a día, acompañada de lluvia |
| 🍯 **Consumo** | Pérdida **constante** | Invierno, sin entrada de néctar |
| 🛠️ **Apicultor** | Caída **instantánea** | Quitó un alza → hay que filtrarla |

### Ciclo diario (para interpretarlo bien)

- **Mañana:** el peso **cae** — miles de obreras salen a la vez
- **Tarde:** el peso **sube** — regresan con néctar
- **Noche:** se estabiliza → **el mejor momento para medir**

> 📌 La NASA mide siempre al anochecer y descarta cambios de ±1 kg en 15 min (intervenciones humanas). **BeeStation no filtra eso todavía.**

---

## 6. La alerta de enjambre — qué realidad hay

| | |
|---|---|
| El firmware mide | **voltios** (`0.0 – 3.3 V`) — `beestation_node.ino:929` |
| El backend espera | **frecuencia** (`400 – 600 Hz`) — `functions.php:432` |
| ¿Se llega a 400? | ❌ **No**, el máximo es 3.3 |
| **¿Se dispara la alerta?** | ❌ **Nunca** |

Confirmado con datos reales: la BD guarda `sonido = 3.26`.

Para que funcionara haría falta un **FFT** en el ESP32 que extraiga la frecuencia dominante. No está implementado.

---

## 7. Qué dice la ciencia vs. qué inventó el proyecto

| Umbral | ¿Respaldado? | La literatura dice |
|---|:--:|---|
| **Temp. cría 34–36 °C** | ✅ | *"mantienen el nido de cría alrededor de 34–36 °C, óptimo para el desarrollo"* — PLOS ONE, 2016 |
| **Centro 35 °C, ±3** | ✅ | 35 °C es el óptimo clásico (Himmer 1927; Fahrenholz 1989) |
| **Peso −24 h = flujo** | ✅ | Práctica estándar del protocolo de básculas de la NASA |
| **HR 50–70 %** | ⚠️ | La literatura dice 50–60 %; el nido de cría ronda ~75 % |
| **ΔT < 2 °C → colmena muerta** | ⚠️ | El principio es sólido (colmena vacía ≈ **−5.55 °C**); el umbral 2 es del proyecto |
| **Sonido 400–600 Hz** | ⚠️ | La banda existe, pero *"no se ha encontrado ninguna señal directa que preceda al enjambre"* (2022) |
| **Pesos IBB 45/35/20** | ⚠️ | Del proyecto; el concepto de índice compuesto sí es estándar (EFSA, 2016) |
| **CO₂ óptimo 3000 ppm** | ❌ | Apidologie 2022: las colmenas reales superan **11 000 ppm** a diario |

**Leyenda:** ✅ respaldado · ⚠️ plausible pero del proyecto · ❌ contradicho

---

## 8. Limitaciones clave del código actual

| # | Limitación | Efecto |
|:-:|---|---|
| 1 | La acústica mide **voltios, no Hz** | La alerta de enjambre **nunca se dispara** |
| 2 | El firmware **no emite CO₂** | **El IBB nunca se calcula** (necesita las 3 variables) |
| 3 | `ingest.php:110` rechaza `presion` y `luminosidad` | 2 de los 6 datos que llega se **descartan** |
| 4 | El HX711 está en `set_scale(1.0)` | Los kilogramos absolutos **no están calibrados** |
| 5 | No se filtran cambios bruscos de peso | Quitar un alza ensucia el `flujo_diario` |
| 6 | `temp_externa` nunca llega | El **ΔT no se calcula** → no detecta colmenas muertas |

---

## 9. Cinco ideas para quedarse

1. **La bioclimatología apícola** es la base: leer el microclima interior es diagnosticar sin abrir.

2. **Hay cuatro capas de matemática**; las que *identifican* anomalías son la **capa 4** (`includes/functions.php`).

3. **El IBB es una nota ponderada 0–100** (temp 45 % · HR 35 % · CO₂ 20 %). Con los datos reales de la BD da exactamente **71 → "Bueno"**.

4. **El peso se calcula con una resta** de 24 h. Es simple — por eso hay que filtrar las intervenciones humanas para que signifique algo.

5. **Los umbrales de temperatura son sólidos**; los de **CO₂ y acústica necesitan revisión**, y el firmware tiene dos huecos que impiden que IBB, ΔT y la alerta de enjambre funcionen.

---

## 📚 Referencias principales

1. Kolodziejczyk *et al.* — *Honeybee Colony Thermoregulation* — PMC2813292
2. *Drone and Worker Brood Microclimates* — **PLOS ONE**, 2016
3. Jones *et al.* — *Honey bee nest thermoregulation* — **Science** 305:402, 2004
4. Meikle, Barg & Weiss — *Honey bee colonies maintain CO₂ and temperature regimes* — **Apidologie** 53:51, 2022
5. *A Low-Cost, Low-Power, Multisensory Device* — **Sensors**, 2023 (bandas acústicas)
6. *Acoustic and vibration monitoring of honeybee colonies* — **Comput. Electron. Agric.**, 2022
7. NASA HoneyBeeNet — *Scale Hive Protocol*
8. *Modelling daily weight variation in honey bee hives* — **Apidologie**
9. *Temperature Sensing and Honey Bee Colony Strength* — PMC9175291
10. EFSA — *Assessing the health status of managed honeybee colonies (HEALTHY-B)*, 2016

---

<div align="center">
<br>

*Resumen verificado contra `includes/functions.php`, `api/ingest.php`, `beestation_node.ino` y `database/beestation_sena.sql`.*

</div>
