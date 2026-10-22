# Plan de reestructuración — BeeStation

> Fecha: 2026-10-21
> Base: `docs/AUDITORIA-CAMPO-2026-10-21.md`
> Criterio de éxito: que un apicultor, con guantes y sin conocimientos de redes,
> pueda responder en menos de un minuto: **¿la colmena está bien?, ¿el nodo está
> vivo?, ¿qué debo hacer hoy?** Sin inventar diagnósticos que el hardware no mide.

## Regla de oro

Cada dato que aparezca en el panel debe cumplir las tres condiciones:

1. Existe un sensor físico que lo mide.
2. El backend lo recibe con unidad y fecha.
3. La UI distingue **dato reciente**, **dato antiguo**, **sensor no instalado** y
   **error de lectura**.

Si falta cualquiera, el dato no se muestra como diagnóstico. Se muestra como
“no disponible” o como “experimental”, nunca como una alerta.

---

## Fase 0 — Verdad y limpieza (cerrada en software)

**Objetivo:** quitar la ficción y dejar el panel honesto antes de añadir funciones.

### Hecho y verificado en código

- [x] Recursos locales: Chart.js y Lucide en `assets/vendor/`, sin Google Fonts.
- [x] Menú sin Energía, sin rol ficticio, sin “Hive OS”, “Live” o “premium”.
- [x] Buscador, campana, ayuda flotante y enlaces muertos eliminados.
- [x] Dashboard reescrito: estado del nodo, 4 métricas reales, alertas y
      “Qué revisar”; sin hero de IBB, EV, ΔT ni H_miel.
- [x] Frescura de datos: `lecturaReciente()` y `edadLecturaMinutos()`; una lectura
      de hace 6 días se pinta como “Dato antiguo”, nunca en verde.
- [x] Serie de temperatura agregada cada 15 min (máximo ~96 puntos/24 h).
- [x] Alertas activas contadas completas, no solo las 5 visibles.
- [x] `api/estado.php` dejó de devolver IBB/EV/ΔT/H_miel y la serie de peso
      duplicada; el JS ya no intenta pintar indicadores inexistentes.
- [x] `acustica.php` reescrita: “Actividad acústica”, sin “Pre-Enjambrazón”,
      “Canto de Reinas” ni alerta de enjambrazón; banda 400–600 Hz solo como
      referencia experimental.
- [x] `sensores.php`: unidades, `estado_real`, “sin datos” separado de “no
      instalado” por texto y nota de alcance.
- [x] `energia.php`: fuera del menú y marcada como no instalada; sin datos falsos.
- [x] `informe.php`: sin IBB/EV/ΔT/H_miel; resume temperatura, humedad, peso y
      actividad acústica; inventario con unidades y estado real.
- [x] Firmware: código de alta 4-4 generado y persistido (`identidad_ecdsa.h`).
- [x] Firmware: comando `test` ya **no inserta telemetría falsa** (25 °C, 50 %,
      1013 hPa, 0 kg); solo diagnostica sensores locales.
- [x] Firmware: `/status` ya no publica el código de alta.
- [x] Firmware compila con `arduino-cli compile --fqbn
      esp32:esp32:esp32:PartitionScheme=min_spiffs beestation_node`.
- [x] `php -l` sin errores en los archivos tocados.
- [x] Render autenticado verificado con Playwright; sin errores de consola ni
      peticiones externas.

### Pendiente para cerrar Fase 0

- [ ] Probar el alta 4-4 en hardware real y con NVS limpia.
- [ ] Confirmar en hardware que `test` no escribe en `lectura` y que `/status`
      no expone `codigo`.
- [x] Integrar en la auditoría los hallazgos H38–H43 y los informes de backend/UX.
- [x] Revisar los worktrees Orca e integrar selectivamente `seguridad-despliegue` y `docs-verdad`.
- [x] Commit y sincronización al docroot (`scripts/sincronizar-htdocs.ps1`).
- [x] Apache verificado: páginas autenticadas, assets locales y rutas privadas
      con 403 (`config/`, `.git/`, `docs/`, `tests/`, `README.md`, `.sql`, `.ino`,
      `.h`).

### Criterio de aceptación

- El dashboard no muestra ningún KPI sin sensor.
- Una lectura vieja nunca se ve verde.
- El menú no ofrece módulos vacíos.
- El firmware compila y el panel renderiza sin errores.
- La documentación no promete funciones no verificadas.

---

## Fase 1 — Contrato de hardware y datos

> Contrato detallado y matriz de pruebas: `docs/FASE1-CONTRATO-HARDWARE.md`.

**Objetivo:** que el nodo y el backend hablen el mismo idioma.

### Tareas

1. **Perfil de hardware por nodo** (`basico`, `exterior`, `energia`, `co2`).
   - El backend crea solo los sensores del perfil.
   - El firmware reporta `perfil` y `capacidades` en `/status` y en el alta.
2. **Contrato de sensores único** en `docs/SENSORES-Y-UNIDADES.md`.
   - Tipo, unidad, rango, frecuencia, calibración y estado.
   - Sonido en Hz, no voltios.
3. **Hora local sin internet**.
   - RTC DS3231 o `GET /api/time.php` en el servidor local.
   - NTP solo como respaldo.
   - El nodo firma con hora del RTC/servidor y ventana ±300 s.
4. **Arranque sin router**.
   - Salir del portal sin reiniciar; reintentar WiFi con backoff.
   - No quedar atrapado en `BeeStation_Config`.
5. **Calibración del peso**.
   - Persistir escala y offset en NVS.
   - Asistente de calibración en la UI.
   - Validar `isfinite`, cero y estado “no listo” del HX711.
6. **`seq` monótono**.
   - No usar epoch como número de secuencia (H38).
   - Contador persistido o `epoch * 1000 + índice`.
7. **Cola SD robusta**.
   - Aislar líneas corruptas, no bloquear la cola.
   - Persistir el archivo activo; rotación y espacio libre.
   - Avanzar solo con acuse real por lectura, no con HTTP 200 genérico.

### Archivos principales

`beestation_node/beestation_node.ino`, `beestation_node/identidad_ecdsa.h`,
`api/ingest.php`, `api/enroll.php`, `includes/dispositivo.php`,
`database/schema.sql`, `database/migraciones/`, `docs/SENSORES-Y-UNIDADES.md`.

### Criterio de aceptación

- Un nodo con NVS limpia se da de alta, firma y sube en una LAN sin internet.
- Un corte de energía no pierde la cola ni duplica lecturas.
- El peso en kg coincide con una báscula de referencia (±20 g o ±0,1 %).
- El panel distingue “no instalado” de “sin datos recientes”.

---

## Fase 2 — Métricas y alertas con sentido

**Objetivo:** que una alerta sea accionable y que las métricas no prometan
diagnósticos que el hardware no puede sostener.

### Tareas

1. **Métricas núcleo**: temperatura interna, humedad relativa, peso y actividad
   acústica experimental.
2. **Métricas avanzadas condicionadas**: IBB, EV y ΔT solo si existen CO₂ y
   temperatura exterior. Etiquetadas “experimental” y fuera de la primera pantalla.
3. **H_miel**: retirar de la UI y del informe; no recomendar cosecha con una recta
   sobre HR ambiental.
4. **Flujo de peso**: usar lectura nocturna, mediana móvil y bitácora de
   intervenciones; no interpretar una caída como “pérdida de néctar”.
5. **Motor de alertas**:
   - umbral + histéresis + persistencia (3 lecturas o 15 min);
   - auto-resolución cuando el valor vuelve al rango;
   - cooldown y reconocimiento por el usuario;
   - alerta de nodo offline, temperatura, humedad y caída de peso;
   - prohibida la alerta de enjambrazón hasta validación con grabaciones.
6. **Calidad del dato**: marcar lectura vieja, fuera de rango, saturación,
   reinicio del nodo y sensor no instalado.

### Criterio de aceptación

- Ninguna alerta se dispara con una sola lectura aislada.
- Las alertas se resuelven solas o quedan reconocidas con usuario y fecha.
- El informe explica qué se midió, qué no y con qué calidad.
- Un apicultor entiende las 4 métricas sin manual.

---

## Fase 3 — Rediseño UX de campo

**Objetivo:** que el panel se use de pie, con guantes, bajo el sol y con prisa.

### Tareas

1. **Arquitectura de información**:
   - Resumen (estado + 3 acciones).
   - Colmenas (apiarios, colmenas, nodos).
   - Histórico (temperatura, humedad, peso, acústica).
   - Informe (PDF por impresión).
2. **Estado primero**: nodo en línea / sin reportar / sin configurar, última
   lectura, alertas y “qué revisar” antes de cualquier gráfica.
3. **Tipografía y contraste**: mínimo 16 px, botones de 44 px, tema claro por
   defecto, modo oscuro opcional, foco visible.
4. **Móvil y tablet**: una columna, tarjetas 2x2 en portátiles estrechos,
   sin tablas que se salgan.
5. **Movimiento reducido**: respetar `prefers-reduced-motion`.
6. **Onboarding**: integrar `conectar_dispositivo.php` en el alta de nodo, no
   como página suelta.
7. **Estados vacíos honestos**: “no instalado”, “sin datos”, “dato antiguo”,
   “error de lectura”, cada uno con una acción distinta.

### Criterio de aceptación

- Prueba con 5 apicultores: encuentran el estado y autorizan un nodo en < 60 s.
- Ningún texto se corta a 360 px de ancho.
- Contraste AA en texto principal y botones.
- El panel funciona sin internet y sin CDN.

---

## Fase 4 — Operación y seguridad

**Objetivo:** que el sistema se pueda dejar funcionando sin un técnico al lado.

### Tareas

1. **Docroot limpio**: no copiar `tools/`, `tests/`, `database/`, `docs/`,
   `beestation_node/`, `.sql`, `.ino`, `.h` a `htdocs`.
2. **Apache**: denegar configuración, base de datos, firmware y scripts;
   cabeceras de seguridad; HTTPS en producción.
3. **Tests CLI-only** y `tools/diagnostico-bd.php` fuera del docroot.
4. **Sesiones**: `conectar_dispositivo.php` con `auth.php`, CSRF e IDOR.
5. **Nodo**: servidor HTTP apagado por defecto o con token; `/status` sin código
   de alta; contraseña del AP no derivable del MAC.
6. **Respaldo y retención**: backup automático de MySQL, rotación de logs,
   retención 90 días crudo / 2 años agregados.
7. **Monitoreo del servidor**: disco, MySQL, Apache, UPS, alerta si el panel cae.
8. **Checklist de despliegue** en `docs/SEGURIDAD-DESPLIEGUE.md`.

### Criterio de aceptación

- Un escaneo desde otro equipo de la LAN no obtiene código de alta, `.sql`,
  `.env`, tests ni firmware.
- El sistema se recupera de un corte de energía y de un backup.
- Hay un procedimiento escrito para actualizar y volver atrás.

---

## Fase 5 — Piloto de campo (30 días)

**Objetivo:** demostrar que el sistema sirve para cuidar abejas, no solo para
una demo.

### Diseño del piloto

- 2 colmenas, 2 nodos, un apiario con WiFi local e internet intermitente.
- 30 días, con bitácora de inspecciones, cosechas y alimentación.
- Revisión semanal de la SD y del panel.
- Un responsable con nombre y un criterio de aborto.

### Métricas de salida

| Métrica | Umbral |
|---|---|
| Filas recuperadas | ≥ 99 % |
| Lecturas duplicadas | < 0,1 % |
| Alertas de enjambrazón no verificadas | 0 |
| Deriva de peso | < ±50 g/día |
| Autonomía | ≥ 7 días sin sol, ≥ 20 % de reserva |
| Nodo vivo tras 2 cortes de energía | Sí |
| Nodo vivo tras 1 semana sin internet | Sí |
| Tiempo para encontrar el estado | < 60 s |

### Criterio de salida

Si falla cualquier umbral, no se presenta como “listo para campo”. Se corrige y
se repite el piloto. El resultado se documenta en `docs/PILOTO-2026.md`.

---

## Dependencias y bloqueos

| Bloqueo | Fase | Qué desbloquea |
|---|---|---|
| ESP32 físico + NVS limpia | 0/1 | Alta 4-4, `test`, `/status`, calibración |
| RTC DS3231 o endpoint de hora | 1 | Firma sin internet |
| INA219 + batería + panel | 1/5 | Autonomía real |
| SCD41 NDIR (no MQ-135) | 2 | IBB/EV con CO₂ real |
| SHT31/DHT22 exterior | 2 | ΔT con temperatura exterior |
| Grabaciones etiquetadas + FFT | 2 | Acústica validada |
| Servidor local (Pi + UPS) | 4 | Operación desatendida |
| Worktrees Orca | 0 | Seguridad/despliegue y documentación |

## Orden de ejecución recomendado

1. Fase 0 cerrada, commiteada y desplegada; queda la prueba del alta en
   hardware real.
2. Hora + arranque sin router + alta en hardware (Fase 1).
3. Energía medida y calibración de peso (Fase 1).
4. Cola SD y `seq` (Fase 1).
5. Alertas y métricas avanzadas (Fase 2).
6. UX de campo (Fase 3).
7. Operación/seguridad (Fase 4).
8. Piloto de 30 días (Fase 5).
