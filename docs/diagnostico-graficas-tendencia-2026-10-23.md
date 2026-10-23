# Diagnóstico y reparación de tendencias — 2026-10-23

## Instalación inspeccionada

El proceso que escucha en `127.0.0.1:8091` es PHP 8.2.12 con `-S 127.0.0.1:8091 -t .`. La copia servida es `C:\Users\andre\BeeStation_Sena`: el SHA-256 del JavaScript descargado coincide con ese archivo después del cambio. `C:\xampp\htdocs\BeeStation_Sena` es otra copia que inicialmente tenía el mismo JavaScript, pero no es la raíz que ejecuta este proceso. Una edición de prueba hecha allí durante la identificación se revirtió; sus hashes volvieron a los valores iniciales.

El checkout activo estaba limpio en `main`, 15 commits por delante de `origin/main`. Se creó la rama local `fix/andres-parra-beestation-trends` sin commit ni push. El worktree `fix-badge-acustico` quedó limpio y sin cambios de este diagnóstico.

## Causa de las gráficas vacías

La base configurada por la aplicación es `beestation_sena` en MariaDB 10.4.32. PHP y MySQL comparten la hora de Bogotá: PHP reporta `America/Bogota`, MySQL usa `-05:00` en sesión, `NOW()` coincide con la hora PHP y `UTC_TIMESTAMP()` está cinco horas adelante. `fecha_hora` es `DATETIME`.

Al 23 de octubre de 2026 a las 07:57 (Bogotá), había 121 lecturas válidas entre el 15 y el 21 de octubre. La más reciente era del 21 de octubre a las 20:45, unas 35 horas antes. Ninguna lectura entra en las ventanas de 6 ni 24 horas; por eso las tendencias de acústica (6 h) y temperatura del dashboard (24 h) regresan cero filas aunque `ultimaLectura()` todavía encuentre valores para las tarjetas.

| Colmena | Lecturas | Capturas | Ventanas comprobadas |
| --- | ---: | --- | --- |
| 1 | 10 | 15 oct. 11:28–11:29 | 0 en 6 h, 24 h y 7 días; peso conserva 2 muestras en 30 días. |
| 2 | 111 | 19 oct. 16:19–21 oct. 20:45 | 0 en 6 h y 24 h; peso conserva 17 muestras en 30 días (3 cubos). Temperatura y humedad conservan muestras en 48 h. |
| 4 | 0 | — | Sensores registrados, sin lecturas asociadas. |

Las consultas reales de series agregadas se ejecutaron con sus parámetros de cada módulo. Devuelven cero en las ventanas recientes descritas y sí devuelven los cubos de peso de 30 días para las colmenas 1 y 2. No hay evidencia de un defecto general del SQL, del filtro de colmena o de Chart.js. La colmena seleccionada por el usuario no se pudo conocer sin una sesión autenticada.

## Integridad y alimentación

- Las 121 lecturas tienen `es_valida = 1`; hay cero fechas nulas, futuras, filas huérfanas y grupos duplicados por `(id_sensor, seq)`.
- Las relaciones `lectura.id_sensor → sensor.id_sensor` y `sensor.id_colmena → colmena.id_colmena` existen como claves foráneas. Los tipos de sensor registrados coinciden con los solicitados por las series.
- Todas las filas tienen `id_medicion` nulo y `seq` con aspecto de epoch. Ese patrón es compatible con `scripts/recoger-datalog.ps1` en formato legado, que admite archivos sin ese identificador. No demuestra que ese script —ni OpenCode— haya generado las filas: la base no conserva origen, lote ni hora de inserción, y no se encontró una carga sintética reproducible en el árbol.
- No se insertaron, actualizaron ni borraron datos; tampoco se tocaron fechas ni se ejecutaron migraciones.

## Cambios aplicados

- `api/estado.php` define el contexto de API antes de cargar `auth.php`. Antes, `functions.php` trataba esta ruta como página web y redirigía a `login.php` relativo al directorio `api`; la respuesta final era un 404 en `/api/login.php`. Ahora el endpoint sin sesión responde `401` con JSON `{"ok":false,"error":"no_autenticado"}`.
- `js/actualizar-dashboard.js` registra el estado HTTP no exitoso, la respuesta de API inválida y el tipo de excepción, sin imprimir cuerpos, URLs completas ni mensajes de red potencialmente sensibles. El 401 informa que la sesión terminó y detiene el temporizador.
- `informe.php` muestra un mensaje por variable cuando la serie está vacía y omite el canvas correspondiente. La inicialización existente ya ignora un canvas ausente, así que Chart.js no recibe series vacías sin explicación.
- Se conservaron las ventanas temporales. La ausencia de lecturas recientes solo se resuelve cuando el ESP32 vuelve a enviar mediciones reales.

## Validación y límites

- Antes del cambio, una solicitud real a `api/estado.php` terminó en `302` y `404 /api/login.php`. Después, una solicitud anónima devuelve `401 application/json`; Chrome DevTools confirma ese estado y cuerpo.
- `php -l` pasó para `api/estado.php` e `informe.php`; `node --check` pasó para `js/actualizar-dashboard.js`. Se verificó que Chart.js está servido (HTTP 200) y que el JavaScript entregado corresponde al checkout activo.
- La navegación a `dashboard.php` termina en `login.php` sin sesión. Por ello no se pudo observar el JSON autenticado ni verificar visualmente los gráficos del usuario. El único 404 de consola observado en login fue `favicon.ico`; no es un error de Chart.js.
- No se ejecutaron las pruebas de integración existentes porque pueden crear o eliminar bases/tablas. No se encontró una suite específica de regresión de gráficas. No se agregaron datos de prueba a la base.
- El reporte tenía canvas vacíos y el refresco JS silenciaba errores HTTP/API; ambos comportamientos se corrigieron estáticamente, pero la pantalla autenticada requiere validación manual.

## Prueba manual pendiente

Iniciar sesión en `http://127.0.0.1:8091`, confirmar la colmena seleccionada y abrir dashboard, acústica, peso e informe. Los gráficos de 6/24 horas deben permanecer en estado vacío hasta que existan capturas reales dentro de esas ventanas; peso debe mostrar sus cubos de 30 días cuando la colmena sea 1 o 2. Revisar DevTools: el endpoint autenticado debe devolver JSON 200, y los fallos de refresco deben registrar solo estado HTTP o tipo de excepción.

La base permanece sin cambios. La rama es `fix/andres-parra-beestation-trends`; los cambios están sin commit y sin push.

## Revisión y reparación adicional — 2026-10-23

### Evidencia actualizada

Se volvió a consultar la base configurada por la instalación local en modo de solo lectura. A las 08:19 (Bogotá), PHP y MariaDB reportaron `2026-10-23 08:19:06`, PHP `America/Bogota`, sesión SQL `-05:00` y `UTC_TIMESTAMP()` cinco horas adelante. Se mantienen cero lecturas válidas en 6 y 24 horas para todas las series; colmena 2 conserva dos muestras crudas por variable en 48 horas y 17 muestras crudas de peso en 30 días. Al ejecutar `serieHistoricaAgregada()` con los parámetros de los módulos, colmena 2 devuelve 2 cubos de temperatura en 48 h (21 oct. 20:30–20:45), 1 cubo de humedad en 48 h (21 oct. 20:00), 0 cubos de sonido en 12 h y 3 cubos diarios de peso en 30 días (19–21 oct.). Colmena 1 devuelve 0 cubos recientes y 1 cubo diario de peso (15 oct.); colmena 4 devuelve cero cubos para todos esos períodos. La ausencia en 6/24 horas deriva de la antigüedad de los registros, mientras el informe de 48 horas sí representa datos de colmena 2.

La diferencia entre “hace 35 horas” y “hace 7 días” no se origina en dos relojes o dos eventos: ambos indicadores usan `ultimaConexion()` sobre `lectura.fecha_hora` para la colmena activa, y `tiempoRelativo()` calcula la diferencia con el reloj PHP. Las dos edades corresponden a colmenas diferentes: la última lectura de colmena 2 data del 21 de octubre (aprox. 35 h) y la de colmena 1 del 15 de octubre (7 días completos según el redondeo hacia abajo de la etiqueta). La sesión aislada de Chrome redirige a login, por lo que no fue posible confirmar qué colmena tiene seleccionada el usuario.

### Correcciones adicionales

- `dashboard.php` ahora mantiene el canvas de temperatura presente en el DOM y un estado vacío separado. La instancia de Chart.js se inicializa también con cero puntos; así la API puede llenar la serie en el siguiente refresco sin requerir recarga. Una respuesta válida vacía borra puntos anteriores y vuelve a mostrar el estado vacío. Se alineó el render inicial del badge acústico con el contrato indicado: 400–600 Hz crítico, actividad positiva fuera del rango normal success y cero/sin dato neutral “Sin actividad”.
- `js/actualizar-dashboard.js` valida que `labels` y `data` sean arrays de igual longitud y que cada muestra sea válida antes de actualizar. Evita peticiones solapadas, deduplica en consola un error idéntico hasta que haya recuperación, detiene el timer ante HTTP 401 y muestra enlace de inicio de sesión. Los errores no incluyen cuerpos, mensajes de red ni credenciales.
- El mismo archivo alinea el badge acústico con PHP: 400–600 Hz es “Zona de alerta” crítico, otros valores positivos son “Normal” success y cero/sin dato se representa como “Sin actividad” neutral. Los datos nulos o no numéricos de las demás métricas no reescriben el badge PHP.
- La respuesta no autenticada de `api/estado.php` conserva el contexto API reconocido por `includes/functions.php`; una página HTML protegida sigue usando redirección. No se cambió `includes/auth.php`.
- En `informe.php`, las ventanas de temperatura y humedad se mantienen acotadas por el período general (máximo 48 h), y ahora sus títulos y estados vacíos muestran la duración efectiva cuando se elige un informe de un día.

### Validación adicional y límites

La petición actual al dashboard en Chrome aislado termina en `login.php`, confirmando el flujo de página protegida. Queda pendiente validar el payload autenticado, la selección de colmena y el estado visual del gráfico en una sesión válida. La base no se modificó. Los periodos de 24 h, 6 h, 48 h y 30 días se conservaron. La copia servida es el checkout `C:\Users\andre\BeeStation_Sena`; el proceso PHP de `127.0.0.1:8091` ejecuta `php -S 127.0.0.1:8091 -t .` desde esa raíz, verificado por el recurso JavaScript servido tras comparar su hash con el archivo local.

## Auditoría e integración — 2026-10-23

- A las 09:44 (Bogotá), el diagnóstico de solo lectura confirmó diferencia de reloj PHP/MariaDB de 0 s. Alpha-01 (colmena 1) tiene 2 lecturas válidas por sensor base hasta el 15 oct. 11:29:05; no conserva lecturas válidas en 7 días. El nodo virtual (colmena 2) conserva 19 lecturas válidas de temperatura interna hasta el 21 oct. 20:45:58; hay 0 en 24 h y 2 en 48 h. Para sonido, Alpha-01 tiene 2 lecturas válidas hasta 15 oct. y el nodo virtual 10 hasta 21 oct.; ambos están vacíos en 6 h, con 2 válidas del nodo virtual en 48 h. Colmena 4 no tiene lecturas. El vacío de ventanas cortas es antigüedad/ausencia, no un fallo demostrado de Chart.js.
- La banda de 400–600 Hz queda representada como contexto experimental neutral. No constituye una alerta ni una clasificación biológica.
- Se añadió espaciado acotado a los bloques de tablas/formularios de `dispositivos.php`; se amplió moderadamente el espaciado interno del panel de estado de Resumen. Las reglas responsivas existentes conservan dos columnas hasta 900 px para el panel, una debajo de 640 px, y las cuadrículas de contenido colapsan debajo de 860 px.
- El contenido previo de `scripts/insertar_demo_seguro.php` hacía `INSERT` de valores ficticios y podía modificar mediciones reales. No se ejecutó; se sustituyó por una salida de deshabilitado que no conecta ni escribe en la base.
- La prueba visual autenticada continúa pendiente: Chrome aislado redirige a `login.php`. No se fabricó una sesión ni se verificó visualmente el contenido tras iniciar sesión.
- Los tres scripts `check_tables.php`, `check_alerta.php` y `check_umbral.php` no se recuperaron como originales. El historial consultado acredita un intento de `Remove-Item` sobre rutas absolutas, pero no guarda el código de salida ni el contenido borrado. No son dependencias de la aplicación y no hay evidencia suficiente para reconstruir sus implementaciones.
- La base de datos permaneció intacta y no se ejecutaron migraciones.
