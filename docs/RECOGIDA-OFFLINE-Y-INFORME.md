# Recogida offline e informe imprimible

> Las dos funciones añadidas tras la auditoría. Documento de operación.

---

## 1. Recogida de datos sin red

### 1.1 Qué hace ahora el nodo

El ESP32 **nunca pierde una lectura**. Cada ciclo (30 s):

```
leerSensores()
   ├─► guardarRespaldoSD()      ← se escribe SIEMPRE en la microSD
   └─► envío en vivo (HTTP)     ← si hay red, sube la lectura
          ├─ ok  → se avanza el marcador de la cola
          └─ ko  → la línea queda en la tarjeta
```

En el ciclo siguiente (y en los siguientes) `enviarPendientesSD()` reintenta
**por lotes de 30 líneas** lo que quedó pendiente, hasta que el servidor lo
confirma.

### 1.2 Formato del CSV

Ruta en la tarjeta: `/log_AAAA-MM-DD.csv` (un fichero por día).

```
Fecha_hora,Epoch,Id_medicion,Temp,Hum,Presion,Peso,Sonido_Hz,Luz
2026-10-21 00:37:45,1792561065,a1b2c3d4e5f60718293a4b5c6d7e8f00,35.20,58.30,1013.42,28.34,494.1,412.70
```

| Columna | Significado |
|---|---|
| `Fecha_hora` | Hora local de la medición (legible) |
| `Epoch` | **Epoch UTC de la medición** → fecha real del dato y clave de idempotencia |
| `Id_medicion` | 128 bits hex generados **al tomar** la lectura; los 6 sensores derivan de él cambiando los 2 últimos dígitos |
| `Temp`…`Luz` | Los 6 valores medidos en ese ciclo |
| `Sonido_Hz` | Frecuencia dominante **estimada** por cruces por cero (ver `docs/SENSORES-Y-UNIDADES.md`) |

> El `Epoch` es lo que permite que un dato recogido **a días vista** se guarde
> con su fecha de medición y no con la de subida. El `Id_medicion` hace que
> reenviar un lote **no duplique** nada aunque cambie el orden o se mezclen
> ficheros.

> Los CSV de 8 columnas grabados por firmware anterior se aceptan como legado:
> no traen `Id_medicion` y se deduplican por `(sensor, seq)`.

### 1.3 El estado de la cola

| Clave NVS | Contenido |
|---|---|
| `up_file` | Fichero del que se han confirmado líneas |
| `up_line` | Nº de líneas confirmadas de ese fichero |

Solo se escriben **cuando el servidor confirma un lote**, así que no se
desgasta la flash.

El nodo avanza cuando `ok_total` es `true` **o** cuando el servidor atendió las
6 lecturas de todas las filas del lote y alguna fue rechazada de forma
definitiva. Así un dato inválido (por ejemplo, un sensor que ya no existe) no
bloquea la telemetría futura.

### 1.4 Acuse por lectura

La respuesta de `api/ingest.php` incluye `resultados[]`, con el estado de cada
lectura: `insertada`, `duplicada` o `rechazada`. El nodo puede así distinguir
"ya estaba" de "no se pudo guardar" sin reenviar el lote completo.

### 1.4 Vía automática (el nodo sube solo)

Al recuperar la red, el nodo reanuda automáticamente. No requiere
intervención. Es el caso habitual (el router se cayó un rato).

### 1.5 Vía física (recoger la microSD)

Para apiarios sin cobertura, se extrae la tarjeta y se sube desde un PC:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\recoger-datalog.ps1 `
    -Carpeta "E:\" `
    -BaseUrl "http://<IP-PC-B>/BeeStation_Sena" `
    -Token   "BS-NODO-..." `
    -Mac     "AA:BB:CC:DD:EE:FF"
```

Qué hace:
1. Localiza los `log_*.csv` de la carpeta.
2. Convierte cada línea en 6 lecturas con su `Epoch`.
3. Sube **por lotes de 30 líneas** con la firma HMAC heredada.
4. Renombra el fichero a `*.enviado.csv` cuando está todo subido.

> **Camino legado.** El firmware actual firma con una clave ECDSA que vive
> dentro del ESP32 y no se puede exportar, así que este script solo sirve para
> nodos con firmware antiguo (HMAC). La vía recomendada es la cola automática
> del nodo; este script se retirará cuando se elimine el esquema HMAC del
> backend.

### 1.6 Trazabilidad

Cada envío con lote queda registrado en la tabla `lote_ingesta`:

| Columna | Contenido |
|---|---|
| `identificador` | MAC del nodo que envía |
| `lote` | Nombre del fichero de origen |
| `id_colmena` | Colmena destino |
| `filas_recibidas` | Lecturas admitidas |
| `recibido_en` | Momento de recepción |

Así se puede reconciliar lo que el nodo dice que ha subido con lo que el
servidor ha recibido.

### 1.7 Requisitos

| Requisito | Por qué |
|---|---|
| **El nodo debe tener hora (NTP)** | Sin `Epoch` no se puede fechar el dato ni deduplicar |
| La microSD debe ser de 1 GB o más | 30 s ⇒ ~260 KB/día ⇒ 8 GB ≈ 10 años |

Si el nodo no tiene reloj, el CSV se escribe sin `Epoch` y el script avisa y
omite esas líneas (no se suben datos sin fecha).

---

## 2. Informe imprimible a PDF

### 2.1 Cómo se usa

Panel lateral → **Informe** (o `informe.php?dias=7`).

Botón **Descargar PDF** → el navegador imprime → *Guardar como PDF*.

Selector de periodo: 1 · 3 · 7 · 15 · 30 días.

### 2.2 Qué contiene

| Bloque | Contenido |
|---|---|
| 1 · Resumen bioclimático | IBB, EV, ΔT, H_miel, flujo de peso y estado del nodo |
| 2 · Última lectura de cada variable | 9 variables con valor, unidad y **momento de medición** |
| 3 · Evolución de las variables | 4 gráficas: temperatura, humedad, peso y frecuencia acústica estimada |
| 4 · Inventario de sensores | Tipo, modelo, estado y última lectura de cada sensor |
| 5 · Alertas activas | Tipo, nivel, mensaje y momento |

### 2.3 Por qué no hay una librería PDF

El informe se genera en **HTML** y lo imprime el navegador. Ventajas:

- **Cero dependencias**: ni Composer, ni FPDF, ni TCPDF, ni GD (que además no
  está instalado en este XAMPP).
- Las gráficas de Chart.js se imprimen **exactamente** como se ven en pantalla.
- El texto sigue siendo **vectorial y seleccionable** en el PDF.

Verificado con Chrome headless: **2 páginas A4**, 18 fuentes embebidas,
gráficas renderizadas, 1,7 MB.

### 2.4 Maquetación de papel

`css/print.css` define:
- Página A4 con márgenes de 12–14 mm.
- Se ocultan la barra lateral, la cabecera, los botones flotantes y la barra
  de herramientas.
- Las rejillas de KPI (3 columnas) y de gráficas (2 columnas) **se mantienen**
  en papel: sin eso el informe se alargaría a tres páginas.
- Los bloques no se parten entre páginas.
- El informe se imprime **siempre en claro**, aunque el panel esté en modo
  oscuro.

### 2.5 Agregación de series

Los informes no traen las filas crudas: `serieHistoricaAgregada()` agrupa en
cubos (15 min, 1 h o 1 día según el rango) y devuelve media, mínimo, máximo y
nº de muestras. 30 días a 30 s serían 86.400 filas por sensor; con la
agregación son 11 cubos en 23 ms.

---

## 3. Verificación realizada

| Prueba | Resultado |
|---|---|
| Recogida física con microSD simulada | 7 líneas → 42 lecturas subidas, HTTP 200 |
| Reenvío del mismo lote | 416 lecturas antes y después: **0 duplicados** |
| Fecha del dato en la BD | hora de **medición** (00:37:45), no de subida (00:38:16) |
| Lotes registrados | `lote_ingesta` con MAC, fichero y nº de filas |
| Informe en pantalla | 6 KPIs, 4 gráficas, 19 filas de tabla, 5 bloques, sin errores JS |
| PDF generado | 2 páginas A4, `%PDF-1.4`, 18 fuentes, gráficas renderizadas |
| Maquetación de papel | KPI a 3 columnas, gráficas a 2, bloques sin partir |
| Compilación del firmware | 1.288.292 bytes (98 %) |
