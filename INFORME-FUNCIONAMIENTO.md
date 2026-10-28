# Informe de funcionamiento y límites — BeeStation

> Actualizado: 2026-10-21 · Firmware real: **3.2.0**
> Este documento describe lo que puede sostenerse con el nodo físico. No es una
> declaración de disponibilidad para producción ni para trabajo de campo.
> Para el estado resumido, ver `docs/ESTADO-REAL-DEL-PROYECTO.md`.

## 1. Resumen

BeeStation combina un nodo ESP32, una API PHP, una base de datos y un dashboard.
La descripción de pantallas, fórmulas o tablas no implica que todas las métricas
estén disponibles con el hardware actual. **El sistema no está declarado listo
para campo.**

## 2. Telemetría del nodo físico

El nodo envía:

| Variable | Unidad | Sensor |
|---|---|---|
| `temperatura_interna` | °C | BME280 |
| `humedad_relativa` | % HR | BME280 |
| `presion` | hPa | BME280 |
| `peso` | kg | HX711 + celda de carga |
| `sonido` | Hz estimados | MAX4466 |
| `luminosidad` | lux | BH1750 |

No envía CO₂, `temperatura_externa` ni `energia`. Chart.js y Lucide se sirven
localmente; la interfaz no depende de CDN. Eso no significa que el proyecto tenga
cero dependencias de software.

## 3. Indicadores y disponibilidad

| Indicador | Requisitos | Estado en campo |
|---|---|---|
| IBB | Temperatura interna, humedad relativa y CO₂ | **No se calcula**: falta CO₂ |
| EV | CO₂ | **No se calcula**: falta CO₂ |
| ΔT | Temperatura interior y exterior | **No se calcula**: falta temperatura exterior |
| H_miel | Fórmula indirecta basada en humedad relativa | Solo estimación indirecta; **no mide la humedad real de la miel** |
| Energía | Medición eléctrica | **No disponible**; `energia.php` es un placeholder |
| Flujo de peso | Serie temporal de peso | El peso se recibe; su interpretación como flujo de néctar no confirma entrada de néctar |

Una colmena puede estar en buen estado y aun así no tener CO₂ ni temperatura
exterior. En ese caso el panel debe decir “no disponible”, no inventar un valor.

## 4. Acústica

El campo `sonido` se expresa en **Hz**, como frecuencia estimada por cruces por
cero. No son voltios. La amplitud o voltaje de diagnóstico es un dato distinto.

La acústica permanece **experimental**: no hay confirmación de enjambrazón ni
validación en una colmena real. La banda 400–600 Hz es solo una referencia; no
debe describirse como detector o diagnóstico confirmado. La alerta automática de
enjambrazón está desactivada.

## 5. Alta ECDSA

La identidad ECDSA P-256 se genera en el ESP32 y la clave privada no sale del
dispositivo. El firmware ya genera y persiste el código de alta con formato
`4-4` (`beestation_node/identidad_ecdsa.h`).

El flujo completo **aún no se probó en hardware con NVS limpia**. No debe
afirmarse que el provisionamiento está verificado en un nodo real hasta completar
esa prueba.

## 6. Límites operativos

- El nodo no tiene RTC; sin internet no dispone de hora para firmar.
- No hay batería, panel solar ni medición de consumo.
- El HX711 no está calibrado en campo.
- El servidor HTTP del nodo permanece activo; `/status` ya no publica el código
  de alta, pero `/datos` y `/monitoreo` siguen sin autenticación.
- El panel funciona en LAN sin CDN, pero el servidor y la base de datos siguen
  siendo un PC con XAMPP, sin UPS ni HTTPS de producción.

## 7. Qué se puede afirmar y qué no

Se puede afirmar:

- El nodo mide y transmite las seis variables de la tabla.
- El backend valida la firma, aplica calibración por sensor y guarda lecturas.
- El dashboard distingue dato reciente, dato antiguo y ausencia de datos.
- Chart.js y Lucide se cargan localmente.

No se puede afirmar todavía:

- Que el sistema esté listo para un apiario real.
- Que detecte enjambrazón.
- Que el IBB, EV o ΔT funcionen con el nodo físico.
- Que la autonomía energética esté resuelta.
- Que el alta ECDSA esté probada en hardware.

## 8. Referencias principales

1. Kolodziejczyk *et al.* — *Honeybee Colony Thermoregulation* — PMC2813292.
2. *Drone and Worker Brood Microclimates* — **PLOS ONE**, 2016.
3. Jones *et al.* — *Honey bee nest thermoregulation* — **Science** 305:402, 2004.
4. Meikle, Barg & Weiss — *Honey bee colonies maintain CO₂ and temperature regimes* — **Apidologie** 53:51, 2022.
5. *A Low-Cost, Low-Power, Multisensory Device* — **Sensors**, 2023 (bandas acústicas).
6. *Acoustic and vibration monitoring of honeybee colonies* — **Comput. Electron. Agric.**, 2022.
7. NASA HoneyBeeNet — *Scale Hive Protocol*.
8. *Modelling daily weight variation in honey bee hives* — **Apidologie**.
9. *Temperature Sensing and Honey Bee Colony Strength* — PMC9175291.
10. EFSA — *Assessing the health status of managed honeybee colonies (HEALTHY-B)*, 2016.
