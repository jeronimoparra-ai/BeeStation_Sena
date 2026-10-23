# Auditoría de campo y producto — BeeStation

> Fecha: 2026-10-21 · Alcance: firmware, backend, datos, dashboard y operación.
> Criterio: viabilidad en un apiario real, no en un laboratorio ni en una demo.
> Este documento no sustituye una prueba de campo; identifica lo que **no debe
> llevarse al campo** sin resolver antes.

---

## 1. Veredicto ejecutivo

BeeStation es hoy un **prototipo académico funcional con un dashboard visualmente
avanzado**, no un producto listo para un apiario. La idea —monitorear sin abrir la
colmena— es correcta y el esfuerzo de ingeniería es real, pero el sistema tiene
cuatro bloqueos que impiden usarlo en producción:

1. **El alta de nodos ECDSA está rota.** El firmware nunca genera el código de
   vinculación y, aunque lo generara, produce 13 caracteres mientras el backend
   exige 9. Ningún nodo nuevo puede autorizarse.
2. **El nodo no tiene hora si el apiario no tiene internet.** La firma exige un
   timestamp de ±300 s y no hay RTC ni endpoint de hora local. Sin internet, el
   nodo solo puede escribir CSV sin epoch: no firma, no sube y no deduplica.
3. **No hay sistema de energía.** No hay batería, panel, controlador ni medición
   de consumo; el ESP32 transmite cada 30 s con WiFi siempre activo y sin deep
   sleep. La autonomía real es de días, no de una temporada.
4. **Las métricas principales no existen en el nodo real.** El IBB y el EV
   requieren CO₂ (no instalado); el ΔT requiere temperatura exterior (no
   instalada); la “humedad de miel” es una estimación indirecta que no mide miel.
   El dashboard promete un diagnóstico que el hardware no puede producir.

A eso se suman dos problemas de confianza: la alerta de enjambrazón se dispara
con cualquier lectura entre 400 y 600 Hz —sin persistencia ni validación— y las
alertas nunca se resuelven solas. En campo eso produce dos fallos opuestos:
alarmas falsas que hacen abrir colmenas sin motivo, y fallos silenciosos que
nadie ve.

**Decisión recomendada:** no vender ni presentar como “listo para campo” hasta
cerrar la Fase 0 y la Fase 1 del plan. El valor actual es académico y de
prototipo; el valor comercial empieza cuando el sistema responde tres preguntas:
¿la colmena está en riesgo?, ¿el nodo está vivo?, ¿qué debo hacer hoy?

---

## 2. Qué se midió y cómo

Se revisaron, con lectura directa de código y documentación:

- `beestation_node/`: firmware 3.2.0, identidad ECDSA, acústica, SD, portal,
  WiFi, NTP y sensores.
- `api/`, `includes/`, `config/`, `database/`: ingesta, alta, autorización,
  esquema, migraciones, métricas y alertas.
- Todas las vistas del dashboard y sus recursos (`css/`, `js/`, `assets/`).
- `README.md`, `INFORME-FUNCIONAMIENTO.md`, `docs/*`.

No se flasheó hardware ni se ejecutaron escrituras de base de datos durante la
auditoría. Las afirmaciones de campo se basan en el código y en la física del
hardware declarado; se marcan como **verificadas** o **inferidas**.

---

## 3. Matriz de viabilidad en campo

| Frente | Estado | Veredicto | Qué falta para aprobar |
|---|---|---|---|
| Alimentación | Sin diseño | **No viable** | Batería LiFePO4 + panel + controlador + INA219; medir mAh/día; 7 días sin sol |
| Autonomía de radio | WiFi continuo, 30 s | **No viable** | Deep sleep o ciclo 5–15 min; medir consumo; RSSI en telemetría |
| Hora | Solo NTP por internet | **No viable** | RTC DS3231 o endpoint de hora del servidor local; arranque sin internet |
| Alta de nodo | Rota (F1) | **No viable** | Generar código 4-4, guardarlo, mostrarlo y probar 10 altas |
| Firmas ECDSA | Implementadas, no probadas en campo | **Prometedor** | Interoperabilidad Mbed TLS ↔ OpenSSL en hardware real |
| Cola SD | Implementada, con riesgos | **Parcial** | Prueba de 7 días offline, corte de energía, línea corrupta, drenaje |
| Sensores básicos | BME280 + HX711 + MAX4466 | **Parcial** | Calibrar HX711; proteger sonda; validar acústica |
| CO₂ | No instalado; MQ-135 previsto | **No usar** | MQ-135 no es selectivo de CO₂; si se quiere, SCD41 NDIR |
| Temperatura exterior | No instalada | **Parcial** | DHT22/SHT31 exterior con radiación y lluvia |
| Energía | Página vacía | **Quitar del producto** | INA219 + hardware solar real |
| Peso | HX711 sin calibrar | **Parcial** | Calibración con pesas, filtro de intervenciones, lectura nocturna |
| Acústica | Cruces por cero | **Experimental** | Micrófono digital + FFT + grabaciones etiquetadas; no diagnosticar |
| Backend | PHP/MySQL local | **Parcial** | Despliegue en Pi/appliance, HTTPS, backups, monitoreo |
| Dashboard | Atractivo pero sobrecargado | **Parcial** | Rehacerlo “estado primero”, móvil, sol directo, sin CDN |
| Operación | XAMPP en PC | **No viable** | Pi con UPS, acceso remoto seguro, backup automático |
| Documentación | Rica, con contradicciones | **Parcial** | Una sola fuente de verdad; retirar afirmaciones no verificadas |

---

## 4. Lo que hay que quitar

| # | Elemento | Por qué | Acción |
|---|---|---|---|
| Q1 | Página **Energía** y su ítem de menú | Es un blueprint vacío; dice “datos reales” y “pendiente” en la misma pantalla | Quitar del menú y del producto hasta que exista INA219 + solar |
| Q2 | **IBB** como KPI principal | Requiere CO₂ que no está instalado; en nodo real siempre es `null` | Sacarlo del hero; conservarlo solo como indicador académico cuando existan las 3 entradas |
| Q3 | **EV** y **ΔT** en el dashboard | Dependen de CO₂ y temperatura exterior no instalados | Ocultarlos si falta la entrada; mostrarlos en “avanzado” solo con sensor |
| Q4 | **H_miel** y “Lista para cosecha” | No mide humedad de miel; es una recta sobre HR ambiental | Eliminarlo de la UI; si se conserva, renombrar “proxy” y prohibir recomendación de cosecha |
| Q5 | Alerta **ENJAMBRAZON** | Cualquier lectura 400–600 Hz dispara; no hay persistencia ni validación | Desactivar la alerta; dejar “actividad acústica experimental” sin diagnóstico |
| Q6 | Etiquetas **“Pre-Enjambrazón”**, **“Canto de Reinas”**, zonas 200–380/600–900 | El método de cruces por cero no separa esas bandas ni identifica eventos | Quitar las etiquetas y el semáforo de riesgo; explicar el límite |
| Q7 | **Presión** y **luminosidad** como métricas de colmena | No son accionables dentro de una colmena; la luz interior es casi cero | Quitarlas del dashboard; conservar en crudo si se usan para contexto externo |
| Q8 | **CO₂/MQ-135** del BOM y del IBB | El MQ-135 no es selectivo de CO₂ y consume/heats; invalidaría la autonomía | Quitarlo del perfil básico; si se retoma, SCD41 NDIR |
| Q9 | Buscador, campana y botón de ayuda | No hacen nada; parecen funciones y no lo son | Eliminarlos o implementarlos de verdad |
| Q10 | “Hive OS”, “Live”, “interfaz premium”, rol “Operador” | Branding y un rol que el esquema eliminó | Quitar; lenguaje operativo y honesto |
| Q11 | **Héroes repetidos** en cada página | Ocupan la primera pantalla con texto, no con estado | Un encabezado compacto y el estado arriba |
| Q12 | **CDN** de Google Fonts, Lucide y Chart.js | Sin internet no hay iconos, gráficas ni tipografía; contradice “sin nube” | Vendorizar todo en `assets/vendor/` y usar fuentes del sistema |
| Q13 | **HMAC legado**, `validate_token.php` y `token_vinculacion` | Duplican el modelo ECDSA y amplían la superficie de ataque | Retirarlos cuando no queden nodos antiguos |
| Q14 | **30 s** de muestreo | 17 280 filas/día por colmena; castiga batería, SD y BD | 5 min por defecto; agregación y retención |
| Q15 | **Sensores “sin señal” eternos** (CO₂, energía, temp. exterior) | Una colmena básica muestra 9 sensores, 3 nunca reportan | Perfil de hardware por nodo; crear solo los sensores instalados |
| Q16 | **conectar_dispositivo.php** como página aparte | Duplica onboarding y rompe la navegación | Integrarlo como asistente dentro de “Colmenas/Nodos” |

---

## 5. Lo que hay que postergar

| Elemento | Motivo |
|---|---|
| Detección de enjambrazón con IA | No hay dataset etiquetado ni validación; primero medir, después modelar |
| LoRa | Útil, pero antes hay que resolver energía, hora y sensores |
| OTA | Necesario para mantenimiento, pero después de estabilizar el firmware |
| Roles y multi-tenant | Un apiario familiar no los necesita; añaden complejidad |
| App móvil nativa | La web móvil bien hecha es suficiente para la Fase 1 |
| Reporte académico completo | Se puede generar con los datos actuales, pero no mezclarlo con el producto |
| Módulo solar completo | Postergar hasta tener hardware y consumo medido |

---

## 6. Lo que se mantiene y se refuerza

- **Identidad ECDSA por nodo** (corregida): es la dirección correcta.
- **Portal cautivo WPA2** para aprovisionar desde el celular.
- **Cola SD** con `id_medicion`, epoch y acuse por lectura.
- **Peso, temperatura y humedad** como núcleo del monitoreo.
- **Autorización/revocación individual** de nodos.
- **Informe imprimible** vía `window.print()` (sin librerías PDF).
- **MySQL solo en 127.0.0.1** y despliegue local.
- **Español claro** y una sola cuenta de usuario mientras el equipo sea pequeño.
- **Simulador** para probar el panel sin hardware; debe estar marcado como simulación.

---

## 7. Hallazgos por severidad

### Críticos

**C1 · Alta ECDSA imposible (verificado).**
`beestation_node/identidad_ecdsa.h:328-345` genera 8 caracteres + “-” + 4 =
**13 caracteres**; `includes/dispositivo.php:307` exige
`^[A-Z0-9]{4}-[A-Z0-9]{4}$` = **9**. Además, `identGenerarCodigoAlta()` **no se
llama en ningún punto** del firmware; `identCodigoAlta` queda vacío si la NVS no
lo trae. `api/enroll.php` envía `codigo` vacío y el backend responde
`codigo_invalido`. **Impacto:** ningún nodo nuevo se autoriza; el flujo de alta
documentado no existe en la práctica.

**C2 · Sin hora local, sin telemetría (verificado).**
`beestation_node.ino:648` usa `configTzTime` con servidores NTP de internet;
`horaSincronizada()` bloquea el envío si no hay epoch
(`beestation_node.ino:1788-1789`). No hay RTC ni endpoint de hora del servidor.
**Impacto:** un apiario con WiFi local pero sin internet no puede firmar ni
fechar; solo escribe `log_sin_hora.csv`. El requisito “sin nube” no se cumple.

**C3 · Sin autonomía energética (verificado).**
No hay batería, panel, controlador ni INA219 en el BOM; `energia.php:42-47`
confirma que el módulo no existe. El firmware usa `INTERVALO_LECTURA = 30000`
(`beestation_node.ino:387`), WiFi continuo y no usa deep sleep. **Impacto:** el
nodo depende de corriente permanente; no es desplegable en un apiario.

**C4 · KPIs principales sin entrada real (verificado).**
`calcularIBB()` retorna `null` si falta CO₂ (`includes/functions.php:450-455`);
`calcularEV()` idem (`:498-500`); `calcularDeltaT()` exige temperatura exterior
(`:477-481`). `docs/SENSORES-Y-UNIDADES.md:20-31` confirma que CO₂, temperatura
exterior y energía **no se envían** en el nodo físico. **Impacto:** el hero del
dashboard muestra “IBB pendiente” y las tarjetas “Sin datos” de forma permanente;
el producto promete un diagnóstico que no puede emitir.

**C5 · Alertas sin confianza ni ciclo de vida (verificado).**
`evaluarAlertaEnjambrazon()` dispara con cualquier valor 400–600 Hz
(`includes/functions.php:544-577`), sin persistencia temporal ni validación
espectral. No hay auto-resolución ni caducidad de alertas; `alertasActivas()`
solo lee `estado='activa'` (`:386-400`). No existe alerta de nodo offline ni de
temperatura/humedad fuera de rango, aunque los umbrales están en la BD.
**Impacto:** falsos positivos de enjambrazón y fallos silenciosos.

**C6 · Dependencia de CDN en un sistema offline (verificado).**
`includes/header.php:42-48`, `informe.php:111-112` y `login.php:59-61,328`
cargan Google Fonts, Lucide y Chart.js desde internet. **Impacto:** en el
apiario, sin salida a internet, el panel pierde iconos, gráficas y tipografía;
el informe no se ve igual. Contradice el “sin nube” del README.

### Altos

**A1 · Muestreo y volumen desproporcionados.**
30 s × 6 sensores ≈ 17 280 filas/día por colmena (`docs/SENSORES-Y-UNIDADES.md:106-112`).
No hay agregación ni retención. **Impacto:** batería, SD, BD y tiempo de carga.
La dinámica de una colmena se entiende con 5 min; 30 s solo tiene sentido para
acústica continua, que no es el caso.

**A2 · Sensores no adecuados al campo.**
BME280 dentro de la colmena se ensucia con propóleo/cera y mide un punto, no el
nido; el HX711 está en `set_scale(1.0)` (sin calibrar); el MAX4466 es analógico y
su frecuencia por cruces por cero no es un espectro; el BH1750 dentro de la
colmena mide oscuridad; el MQ-135 no es selectivo de CO₂. **Impacto:** datos
plausibles pero no confiables.

**A3 · Acústica sobrevendida.**
La documentación reconoce que es una estimación y que no hay señal directa de
enjambrazón (`docs/SENSORES-Y-UNIDADES.md:94-102`), pero la UI muestra
“Pre-Enjambrazón”, “Canto de Reinas” y “Posible enjambrazón detectada”
(`acustica.php:121-145`). **Impacto:** el apicultor puede abrir la colmena por
una falsa alarma; el sistema pierde credibilidad.

**A4 · Flujo de peso engañoso.**
`calcularFlujoDiarioPeso()` resta la primera y la última lectura de 24 h
(`includes/functions.php:424-443`). Incluye el ciclo mañana/tarde y cualquier
intervención humana (quitar un alza). **Impacto:** el “flujo de néctar” puede
ser negativo por la salida de pecoreadoras, no por falta de néctar. Falta
lectura nocturna, mediana móvil y bitácora de intervenciones.

**A5 · Dashboard no apto para campo.**
Hero + 4 métricas + 3 indicadores + gráfica + alertas + IBB en una sola página;
tipografía pequeña, tema oscuro por defecto, animaciones, buscador/campana/ayuda
falsos. **Impacto:** con guantes, sol directo y prisa, el apicultor no encuentra
“qué pasa” ni “qué hago”.

**A6 · Despliegue no es un electrodoméstico de campo.**
XAMPP en un PC Windows, sin HTTPS, sin backups automáticos, sin monitoreo, sin
UPS. **Impacto:** el sistema se cae con el PC; no es operable por un apicultor.

**A7 · Seguridad heredada y de alta.**
Convive HMAC legado (`validate_token.php`) con ECDSA; el re-enrolamiento puede
regenerar código sin autenticación de usuario (`api/enroll.php`); la clave del AP
se deriva de la MAC (`bee` + 5 dígitos, ~20 bits,
`docs/AUTENTICACION-Y-PORTAL.md:103,126`). **Impacto:** superficie extra y
provisionamiento predecible; el riesgo físico es bajo, pero no es un diseño
defendible.

**A8 · Inventario de sensores ficticio.**
Cada colmena se crea con 9 sensores, pero el nodo básico solo reporta 6
(`docs/SENSORES-Y-UNIDADES.md:12-22`). **Impacto:** la tabla muestra “sin señal”
para sensores que nunca existirán; el usuario no distingue “no instalado” de
“averiado”.

### Medios

- **M1 · Contradicciones documentales:** README dice firmware 3.0.0 y el código
  es 3.2.0; `energia.php` dice “Datos reales” y “pendiente”; el README dice “sin
  nube” y usa CDN; `INFORME-FUNCIONAMIENTO.md` describe la acústica en voltios
  (versión antigua).
- **M2 · Sin bitácora de intervenciones:** no se puede explicar una caída de
  peso por cosecha, alimentación o inspección.
- **M3 · Sin calidad de dato:** no se marca lectura vieja, fuera de rango,
  sensor saturado o nodo reiniciado.
- **M4 · Multi-apiario incompleto:** el selector es por colmena; no hay vista
  de apiario ni comparación entre colmenas.
- **M5 · Sin exportación CSV** de lecturas; solo PDF.
- **M6 · Accesibilidad:** faltan foco visible, contraste en tema claro, textos
  alternativos de gráficas y reducción global de movimiento.
- **M7 · Sin cola offline del navegador/PWA:** si el WiFi del apiario cae, el
  panel no muestra nada.
- **M8 · Sin auditoría de acciones de usuario** (crear, autorizar, revocar,
  calibrar).
- **M9 · Sin backups/retención:** no hay política de respaldo ni purga.
- **M10 · Sin protocolo de prueba de campo** ni criterios de aceptación.

---

## 8. Auditoría por módulo del dashboard

| Módulo | Qué es hoy | Veredicto | Decisión |
|---|---|---|---|
| `dashboard.php` | Hero IBB + 4 métricas + 3 indicadores + gráfica + alertas | **Sobrecargado y con KPIs vacíos** | Rediseñar: estado primero, 4 tarjetas, alertas y una tendencia |
| `acustica.php` | Frecuencia + zonas + alerta de enjambrazón | **Experimental y sobrevendido** | Renombrar “Actividad acústica”; quitar diagnóstico y alerta |
| `peso.php` | Peso actual + serie 30 días | **Útil, pero mal interpretado** | Agregar por día/noche, calibración y bitácora |
| `energia.php` | Página vacía “pendiente” | **Innecesario hoy** | Quitar del menú/producto |
| `sensores.php` | Inventario de sensores, sin unidades ni calibración | **Útil como inventario** | Renombrar “Sensores”, añadir unidad y estado “no instalado”; calibración |
| `dispositivos.php` | Apiarios + colmenas + guía + nodos + autorización | **Sobrecargado** | Separar “Colmenas” y “Nodos”; asistente de alta |
| `conectar_dispositivo.php` | Radar de primera conexión | **Útil pero duplicado** | Integrar en el alta de nodo |
| `informe.php` | 5 bloques, PDF por impresión | **Útil para registro** | Quitar KPIs sin datos; vendorizar; añadir calidad del dato |
| `login.php` | Login con imagen/branding | **Correcto** | Simplificar y vendorizar fuentes |
| `seleccionar_colmena.php` | Cambio de colmena por POST/CSRF | **Correcto** | Extender a apiario cuando haya varios |

---

## 9. Auditoría de métricas y lógica

| Métrica | Fórmula actual | Problema | Decisión |
|---|---|---|---|
| **IBB** | `100 − (0.45·ft + 0.35·fh + 0.20·fc)` | Exige CO₂; umbral CO₂ 3000 ppm contradicho por la literatura; peso 45/35/20 inventado | No usar como KPI de campo; conservar como ejercicio académico si hay las 3 entradas |
| **EV** | `100 − |CO₂−3000|/3000·100` | Sin CO₂ no existe; con CO₂, “eficiencia” no es lo que mide | Quitar de la UI |
| **ΔT** | `T_int − T_ext`, `<2` = “posible muerta” | Sin sensor exterior; el umbral 2 °C es del proyecto, no de la literatura | Quitar; si se instala exterior, mostrar “diferencial” sin diagnosticar muerte |
| **H_miel** | `12 + (HR−40)·0.35` | No mide miel; la HR ambiental no predice humedad de miel | Quitar; no recomendar cosecha con esto |
| **Flujo de néctar** | última − primera de 24 h | Ciclo diario + intervenciones | Leer de noche, mediana móvil, bitácora, ventana 24 h |
| **Alerta acústica** | 400–600 Hz | Sin persistencia ni espectro; falsos positivos | Desactivar; convertir en “evento acústico” informativo tras validación |
| **Estado del nodo** | Lectura en últimos 5 min | No alerta; no muestra RSSI/batería | Añadir “en línea/offline”, última lectura, RSSI, batería, firmware |
| **Calibración** | Tabla `calibracion` sin UI | El HX711 queda en 1.0; los kg no son reales | Añadir asistente de calibración y guardar factor |

---

## 10. Arquitectura objetivo

### 10.1 Hardware por perfiles

| Perfil | Sensores | Uso |
|---|---|---|
| `basico` | BME280 (T/HR), HX711, MAX4466 | Colmena sin energía exterior |
| `exterior` | + SHT31/DHT22 exterior | ΔT y contexto |
| `energia` | + INA219, batería, panel | Nodo solar |
| `co2` | + SCD41 NDIR | Investigación, no perfil básico |

El nodo reporta `perfil` y `capacidades`; el backend crea solo los sensores del
perfil. “No instalado” no es “sin señal”.

### 10.2 Tiempo y conectividad

- RTC DS3231 o endpoint `GET /api/time.php` en el servidor local.
- NTP solo como respaldo cuando hay internet.
- Firma con timestamp del RTC/servidor; ventana ±300 s.
- Reintento con backoff y estado visible en el panel.
- RSSI, batería y versión de firmware en cada lote.

### 10.3 Alertas

Motor de reglas con:

- umbral por variable (temperatura, humedad, peso, nodo offline);
- histéresis y persistencia (p. ej. 3 lecturas o 15 min);
- auto-resolución cuando el valor vuelve al rango;
- reconocimiento por el usuario y registro de quién/cuándo;
- severidad informativa/atención/crítica;
- supresión por cooldown;
- prohibición de diagnósticos no validados (enjambrazón, colmena muerta).

### 10.4 Datos

- Lectura cada 5 min; agregados de 15 min, 1 h y 1 día.
- Retención: 90 días de crudo, 2 años de agregados.
- `evento`/bitácora: inspección, cosecha, alimentación, tratamiento, reina.
- Exportación CSV y respaldo automático.

### 10.5 Interfaz

- Cuatro secciones: **Resumen**, **Colmenas**, **Histórico**, **Informe**.
- Resumen: estado del nodo, 4 tarjetas (T, HR, peso, actividad acústica),
  alertas y una tendencia; nada más en la primera pantalla.
- Tema claro por defecto, alto contraste, tipografía grande, botones de 44 px,
  modo oscuro opcional.
- Sin héroes decorativos, sin buscador/campana/ayuda falsos.
- Recursos locales; funciona en LAN sin internet.
- Lenguaje: “Temperatura”, “Humedad”, “Peso”, “Actividad acústica”, “Nodo”;
  siglas solo con explicación.

---

## 11. Pruebas de campo mínimas antes de decir “listo”

1. **Alta:** 10 nodos, código 4-4, autorización, revocación y re-alta.
2. **Hora:** arranque sin internet; RTC/servidor; firma válida; CSV con epoch.
3. **Energía:** consumo medido; 7 días sin sol; 30 días con panel; brownout.
4. **Conectividad:** RSSI en el apiario; pérdida de paquetes; reinicio del router;
   escenario sin internet.
5. **SD:** 7 días offline; corte de energía; línea corrupta; drenaje completo.
6. **Sensores:** calibración HX711 con pesas; BME280 en nido vs tapa; SAT/deriva;
   acústica con grabaciones etiquetadas y FFT.
7. **Peso:** comparar flujo con báscula manual y bitácora de cosecha.
8. **Alertas:** precisión/recall de alertas reales; fatiga de alarmas; auto-resolve.
9. **Usabilidad:** 5 apicultores, con guantes y sol, tareas “ver estado” y
   “autorizar nodo”; medir tiempo y errores.
10. **Datos:** duplicados, replay, fuera de orden, cuerpo alterado, nonce reusado.

---

## 12. Conclusión

La dirección es correcta: medir sin abrir, con identidad por nodo, cola offline y
un panel propio. Pero hoy el sistema confunde **lo que el dashboard puede
mostrar** con **lo que el hardware puede medir**. La prioridad no es añadir
módulos: es quitar los que no tienen sensor, corregir el alta, dar hora y energía
al nodo, y convertir el panel en una herramienta de una sola pregunta:
**“¿qué necesita esta colmena hoy?”**.

---

## 13. Adenda de implementación — Fase 0 (2026-10-21)

Esta sección registra lo que ya se corrigió en el árbol de trabajo y lo que sigue
abierto. No cambia el veredicto: **sin prueba de hardware y sin piloto, BeeStation
sigue sin ser un producto de campo**.

### 13.1 Estado de los bloqueos críticos

| ID | Hallazgo original | Estado tras Fase 0 | Qué falta |
|---|---|---|---|
| C1 | Alta ECDSA rota (13 caracteres, código no generado) | **Corregido en código**: genera y persiste 4-4; `identIniciar()` asegura el código | Probar en hardware con NVS limpia y 5 nodos |
| C2 | Sin hora local sin internet | **Abierto** | RTC DS3231 o endpoint de hora local; prueba sin router |
| C3 | Sin autonomía energética | **Abierto** | INA219 + batería + panel; consumo medido |
| C4 | IBB/EV/ΔT/H_miel sin entradas reales | **Mitigado en UI**: fuera del dashboard y del informe; siguen en `functions.php` como código académico | Eliminar o aislar en una sección experimental; no volver a mostrarlos como KPI |
| C5 | Alerta de enjambrazón sin validación | **Mitigado**: `api/ingest.php` ya no la dispara; la UI acústica ya no la presenta | Borrar la función legado o marcarla “no usar” |
| C6 | Dependencia de CDN | **Corregido**: Chart.js y Lucide locales; sin Google Fonts | Verificar en LAN sin internet |

### 13.2 Cambios verificados en la interfaz

- Dashboard reescrito: estado del nodo, 4 métricas reales, alertas y “Qué revisar”.
- Las lecturas de hace 6 días se muestran como **“Dato antiguo”**, con edad, y
  nunca en verde; `api/estado.php` devuelve `fresca` y `edad_min`.
- Serie de temperatura agregada cada 15 min; máximo ~96 puntos en 24 h.
- `acustica.php` ya no muestra “Pre-Enjambrazón”, “Canto de Reinas” ni alerta
  crítica; la banda 400–600 Hz es solo una referencia experimental.
- `sensores.php` muestra unidad, `estado_real` y separa “sin datos” de “no
  instalado” mediante el texto y el perfil de hardware (el perfil real queda para
  Fase 1).
- `energia.php` está fuera del menú y marcada como no instalada.
- `informe.php` ya no presenta IBB/EV/ΔT/H_miel; resume las variables medidas y
  advierte que la acústica es experimental.
- Recursos locales, sin buscador, campana, ayuda flotante ni roles ficticios.

### 13.3 Hallazgos de firmware H38–H43

| ID | Hallazgo | Severidad | Estado |
|---|---|---|---|
| H38 | `seq` es el epoch; dos lecturas en el mismo segundo colisionan y la segunda se descarta como duplicada | Baja | **Abierto**; usar contador monótono persistido |
| H39 | El comando `test` insertaba 25 °C, 50 %, 1013,25 hPa, 100 lux y peso 0 kg | Alta | **Corregido**: ahora solo diagnostica y no envía telemetría |
| H40 | IBB, EV, ΔT y H_miel se presentaban como diagnóstico sin base física | Alta | **Mitigado en UI**; el código de cálculo sigue existiendo |
| H41 | `/status` publicaba el código de alta en la LAN | Media | **Corregido en código**: el código solo queda en portal y serial; el servidor HTTP sigue siempre activo |
| H42 | Convive HMAC legado con ECDSA; el script de recogida solo firma HMAC | Baja | **Abierto**: congelar como legado y documentarlo |
| H43 | Documentación contradictoria: versión, 7 vs 9 sensores, reset, pines, unidades | Media | **Parcial**: el worktree `docs-verdad` lo está corrigiendo |

### 13.4 Hallazgos consolidados de backend y UX

Además de C1–C6 y A1–A8, las revisiones de backend y de interfaz coinciden en:

- **Despliegue:** el docroot puede servir `tools/`, `tests/`, `database/` y
  documentación; hay que excluirlos y añadir reglas de Apache que no dependan solo
  de `mod_rewrite`.
- **Sesión:** `conectar_dispositivo.php` debe usar `includes/auth.php` y conservar
  CSRF/IDOR, no un `session_start()` aislado.
- **Controles falsos:** buscador, campana, ayuda, “Recordarme”, “¿Olvidaste tu
  contraseña?”, rol “Operador”, “Hive OS”, “Live” y “premium” no tenían función.
- **Sobrecarga:** héroes repetidos, KPIs vacíos, gráficas con dos escalas y tablas
  sin unidad; la primera pantalla debe responder “qué pasa”.
- **Accesibilidad:** foco visible, contraste, textos alternativos de gráficas,
  botones táctiles y `prefers-reduced-motion`.
- **Datos:** no había marca de lectura vieja; ahora sí. Falta marcar saturación,
  sensor no instalado, reinicio y error de lectura.
- **Operación:** XAMPP en un PC sin UPS, HTTPS, backups ni monitoreo no es un
  despliegue de campo.

### 13.5 Verificación ejecutada

| Comprobación | Resultado |
|---|---|
| `php -l` en dashboard, API, funciones, sensores, acústica, informe, login, header y sidebar | **Sin errores** |
| `arduino-cli compile --fqbn esp32:esp32:esp32:PartitionScheme=min_spiffs beestation_node` | **Compila**: 65 % de programa, 17 % de RAM |
| Playwright autenticado en `dashboard.php` | **Renderiza sin errores de consola**; 0 peticiones externas; 4 tarjetas en “Dato antiguo”; estado “Nodo sin reportar” |
| `api/estado.php` autenticado | **200**, `fresca:false`, `edad_min:9295`, sin IBB/EV/ΔT/H_miel |
| `firma_test.php` | **OK** |
| `interop_firmware_test.php` | **OK**: cadenas HMAC y ECDSA coinciden |
| `ecdsa_flujo_test.php` | **Bloqueado**: necesita MySQL aislado en el puerto 3399, no disponible |
| `ecdsa_interop_test.php` | **Falla 1 de 15**: PHP/OpenSSL no genera clave P-256 en este entorno |
| `ecdsa_openssl_test.php` | **Bloqueado**: configuración de OpenSSL local inválida |
| Tests PHP y `tools/diagnostico-bd.php` por HTTP | **403**: no se ejecutan desde el navegador |
| `scripts/sincronizar-htdocs.ps1 -Simular` | **OK**: reporta 19 archivos a copiar y la limpieza de firmware/SQL del docroot |
| Login sin sesión | **OK**: sin recursos externos; muestra el formulario y vuelve al dashboard tras autenticar |

Ninguna de estas comprobaciones sustituye una prueba en hardware ni un piloto de
30 días.

Los worktrees de Orca se integraron de forma selectiva: de `seguridad-despliegue`
se tomaron `.htaccess`, el sincronizador, los guards CLI y
`docs/SEGURIDAD-DESPLIEGUE.md`; de `docs-verdad` se tomó
`docs/ESTADO-REAL-DEL-PROYECTO.md` y se corrigieron `README.md`,
`CHANGELOG.md` e `INFORME-FUNCIONAMIENTO.md` sin borrar las referencias
bibliográficas.

### 13.6 Lo que sigue siendo verdad

- El nodo no tiene RTC ni hora propia; sin internet no firma ni sube.
- No hay batería, panel ni medición de consumo.
- El HX711 no está calibrado en campo.
- La acústica es una estimación por cruces por cero, no un espectro.
- El servidor del nodo sigue permanentemente activo y `/datos`/`/monitoreo`
  siguen sin autenticación.
- La documentación aún puede contradecir al código hasta que se fusione
  `docs-verdad`.
