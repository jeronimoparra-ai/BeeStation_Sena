# Estado real del proyecto

Actualizado: 2026-10-21. Firmware real: **3.2.0**.

Este documento describe lo que puede sostenerse sobre el sistema con el nodo fisico. No constituye una declaracion de disponibilidad para produccion o trabajo de campo.

## Telemetria y capacidades

| Categoria | Estado | Detalle |
|---|---|---|
| Disponible | Telemetria | El nodo envia `temperatura_interna` (deg C), `humedad_relativa` (%HR), `presion` (hPa), `peso` (kg), `sonido` (Hz estimados) y `luminosidad` (lux). El peso es una tendencia: el HX711 no tiene calibracion de campo validada. |
| Disponible | Assets del navegador | Chart.js y Lucide se sirven localmente; la interfaz no depende de CDN. El proyecto mantiene dependencias de software y hardware. |
| Experimental | Acustica | Se estima frecuencia en Hz. No es una confirmacion de enjambrazon y no se ha validado como diagnostico en campo. |
| Experimental | `H_miel` | Es una estimacion indirecta basada en humedad relativa; no mide la humedad real de la miel. |
| Experimental | Alta ECDSA | El firmware ya genera y persiste el codigo `4-4` en codigo; el flujo aun no se ha probado con hardware ni con NVS limpia. |
| No disponible | CO2 | El nodo no envia CO2; IBB y EV no se calculan en campo. |
| No disponible | Temperatura exterior | El nodo no la envia; DeltaT no se calcula en campo. |
| No disponible | Energia | El nodo no envia energia. La pagina `energia.php` es un placeholder, no una funcion activa. |

Para el contrato de variables, unidades y notas de muestreo, consultar [Sensores y unidades](SENSORES-Y-UNIDADES.md).

> El manual `guia-configuracion-beestation.docx` y los informes históricos pueden
> conservar afirmaciones anteriores. Para el estado vigente, usar este documento,
> `README.md` y `docs/AUDITORIA-CAMPO-2026-10-21.md`.

## Limites de las afirmaciones

- Una formula, columna, umbral o vista presente en el software no demuestra que exista una medicion fisica correspondiente.
- La lectura acustica esta en Hz, no en voltios. La tension/amplitud de diagnostico es un dato distinto.
- La acustica no confirma enjambrazon.
- No afirmar que el sistema esta listo para campo. La alta ECDSA no esta validada en hardware y los indicadores listados requieren sensores ausentes.
- La ausencia de CDN no significa que BeeStation tenga cero dependencias.
