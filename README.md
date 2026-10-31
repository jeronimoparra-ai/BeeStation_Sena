<div align="center">

<img src="assets/logo-beestation.png" width="160" alt="BeeStation logo" />

# 🐝 BeeStation

**Monitoreo apícola no invasivo con IoT**
*Un nodo ESP32 registra temperatura, humedad, peso y actividad acústica de la colmena. Sin nube y sin abrir la colmena para leer.*

<br>

![estado](https://img.shields.io/badge/estado-en_desarrollo-F5A623?style=for-the-badge&labelColor=1a1a1a)
![firmware](https://img.shields.io/badge/firmware-v3.2.1-1a1a1a?style=for-the-badge&labelColor=F5A623)
![php](https://img.shields.io/badge/PHP-8.2-1a1a1a?style=for-the-badge&logo=php&logoColor=F5A623)
![mariadb](https://img.shields.io/badge/MariaDB-10.4-1a1a1a?style=for-the-badge&logo=mariadb&logoColor=F5A623)
![esp32](https://img.shields.io/badge/ESP32-DevKit-000000?style=for-the-badge&logo=espressif&logoColor=F5A623)
![license](https://img.shields.io/github/license/jeronimoparra-ai/BeeStation_Sena?style=for-the-badge&labelColor=1a1a1a)
![last commit](https://img.shields.io/github/last-commit/jeronimoparra-ai/BeeStation_Sena?style=for-the-badge&labelColor=1a1a1a)

<br>

[**Cómo funciona**](#-cómo-funciona) •
[**Hardware**](#-hardware) •
[**API**](#-api) •
[**Instalación**](#-instalación) •
[**Roadmap**](#-roadmap)

<br>
</div>

---

## 📖 Qué es

BeeStation registra variables de una colmena mediante un nodo ESP32 y un backend PHP. Los indicadores tienen requisitos de entrada y limites descritos en Estado real.

> **Sin nube, sin cuentas, sin intermediarios.** Todo el flujo corre en tu propio servidor: el ESP32 hace `HTTP POST` directo a `api/ingest.php`. No hay MQTT, ni ThingSpeak, ni servicios de terceros.

Proyecto de formación — **SENA, Centro Minero Ambiental**, El Bagre (Antioquia).

---

## 🏗️ Cómo funciona

```mermaid
flowchart LR
    A["🐝 ESP32 + Sensores"] -->|"POST JSON · WiFi"| B["<b>api/ingest.php</b><br/>valida la firma ECDSA del nodo"]
    B --> C["Calibración<br/>por sensor"]
    C --> D[(MySQL /<br/>MariaDB)]
    D --> I["Backend PHP"]
    I --> J["Dashboard web<br/>según las entradas disponibles"]

    style A fill:#1a1a1a,stroke:#F5A623,color:#F5A623,stroke-width:2px
    style B fill:#F5A623,stroke:#1a1a1a,color:#1a1a1a,stroke-width:2px
    style J fill:#1a1a1a,stroke:#F5A623,color:#F5A623,stroke-width:2px
```

<br>

| Paso | Qué ocurre |
|:--:|---|
| **1** | El ESP32 despierta, lee todos los sensores y arma el JSON |
| **2** | `POST` a `api/ingest.php` con la petición **firmada** (ECDSA P-256) |
| **3** | El backend verifica la firma con la clave pública del nodo y que siga autorizado |
| **4** | Aplica el factor de calibración propio de cada sensor y guarda la lectura |
| **5** | Guarda las lecturas aceptadas; los indicadores requieren variables que el nodo no envía |
| **6** | La frecuencia acústica es experimental y no confirma enjambrazón |
| **7** | El dashboard muestra datos disponibles o estados explícitos de “sin datos” |

---

## ✨ Características

<table>
<tr>
<td width="50%">

### 🔴 Monitoreo periódico
- Temperatura y humedad internas — **BME280**
- Presión barométrica — **BME280**
- Peso de la colmena — **HX711** (celda de carga; calibración pendiente)
- Acústica — **MAX4466**, frecuencia estimada en Hz; experimental, no confirma enjambrazón
- Luminosidad ambiente — **BH1750**
- Respaldo local en **microSD** antes de cada transmisión

</td>
<td width="50%">

### 🧠 Indicadores y límites
- La fórmula del IBB está documentada; no se calcula en campo porque el nodo no envía CO₂.
- Umbrales por variable: **óptimo / atención / crítico**, con frescura obligatoria.
- La acústica es experimental y no confirma enjambrazón.
- ΔT, EV y H_miel están documentados, pero requieren sensores o mediciones que el nodo actual no tiene.
- **Cero datos ficticios**: sin lecturas, la vista muestra un estado vacío explícito.

</td>
</tr>
<tr>
<td>

### 🔒 Seguridad
- **Identidad ECDSA P-256 por nodo**: la clave privada se genera en el ESP32 y **nunca sale del dispositivo**
- Firma de cada petición + anti-replay por `nonce` y ventana de tiempo
- **Autorización explícita** del nodo desde el panel mediante un código de un solo uso, formato `4-4`
- Revocación individual sin reflashear el resto de nodos
- El alta ECDSA está corregida en código, pero **aún no se probó en hardware con NVS limpia**

</td>
<td>

### 📡 Firmware del nodo
- **Portal cautivo** para aprovisionar WiFi desde el celular (`BeeStation_Config`)
- Persistencia en **NVS**: guarda credenciales y no vuelve a pedirlas
- **Reset de fábrica** sosteniendo el botón BOOT 3 s (borra la NVS)
- Reconexión con **backoff exponencial** y diagnóstico por puerto serie

</td>
</tr>
</table>

---

## 🔧 Hardware

**Placa:** ESP32 Dev Module | **Firmware:** `beestation_node/` v3.2.1

| Módulo | Modelo | Interfaz | Pines GPIO | Función |
|---|:-:|:-:|:-:|---|
| 🌡️ Clima | **BME280** | I²C | `SDA 21` · `SCL 22` | Temp., humedad y presión |
| 💡 Luz | **BH1750** | I²C | `SDA 21` · `SCL 22` *(bus compartido)* | Luminosidad en luxes |
| ⚖️ Peso | **HX711** | Digital | `DOUT 16` · `SCK 4` | Celda de carga 24 bits |
| 🎤 Sonido | **MAX4466** | ADC1 | `GPIO 35` | Micrófono analógico |
| 💾 Almacenamiento | **microSD** | VSPI | `CS 5` · `MOSI 23` · `MISO 19` · `SCK 18` | Log local de respaldo |
| 🔘 Reset | Botón BOOT | Digital | `GPIO 0` | Formatea la NVS a los 3 s |
| 💡 Estado | LED integrado | Digital | `GPIO 2` | Indicador de estado |

> El HX711 no tiene calibración de campo validada y el micrófono entrega una
> frecuencia estimada experimental. No son balanza ni diagnóstico acústico certificados.

<details>
<summary><b>Requisitos del entorno de desarrollo</b></summary>
<br>

```bash
# Núcleo ESP32
arduino-cli core install esp32:esp32          # v3.3.12

# Bibliotecas (paso 2.1 de la guía técnica)
arduino-cli lib install \
  "Adafruit Unified Sensor" \
  "Adafruit BME280 Library" \
  "BH1750" \
  "HX711 Arduino Library"

# Verificar sin placa conectada
arduino-cli compile --fqbn esp32:esp32:esp32 beestation_node
```

> **Drivers USB:** los módulos `cp210x` y `ch341` ya vienen en el kernel de Linux. En Windows hay que instalar el driver CP210x o CH340 según tu placa.

</details>

---

## Sensores y disponibilidad

El nodo físico con firmware **3.2.1** envía `temperatura_interna` (°C), `humedad_relativa` (%HR), `presion` (hPa), `peso` (kg), `sonido` (Hz estimados) y `luminosidad` (lux). No envía CO₂, `temperatura_externa` ni `energia`.

| Estado | Variables y funciones |
|---|---|
| Disponible | Las seis variables emitidas por el nodo. El peso del HX711 se recibe, pero su calibración de campo sigue pendiente. Chart.js y Lucide se cargan desde archivos locales; la interfaz no depende de CDN. |
| Experimental | Acústica en Hz: no confirma enjambrazón. `H_miel` es una estimación indirecta de HR y no mide miel. El alta ECDSA ya genera el código `4-4` en firmware, pero no se probó en hardware. |
| No disponible | IBB y EV (falta CO₂), ΔT (falta temperatura exterior) y medición de energía. `energia.php` es un placeholder. |

El sistema no se declara listo para campo. Consulta [Sensores y unidades](docs/SENSORES-Y-UNIDADES.md) para el contrato de telemetría y [Fase 1: contrato de hardware](docs/FASE1-CONTRATO-HARDWARE.md) para los perfiles, la hora local, la calibración y la cola offline.

## 🧮 Índice de Bienestar Bioclimático

La fórmula definida para IBB requiere tres variables; como el nodo no envía CO₂, no se calcula en campo.

```
IBB = 100 − ( 0,45 · f_temp + 0,35 · f_hum + 0,20 · f_co2 )
```

> ⚠️ **Nota:** la fórmula exige las tres variables. El backend calcula el IBB solo cuando existen lecturas de temperatura, humedad **y CO₂**; mientras el firmware no emita `co2`, el indicador queda en `null` *(ver [Roadmap](#-roadmap))*.

| IBB | Umbral de referencia | Disponibilidad |
|:-:|---|---|
| **≥ 85** | 🟢 Óptimo | Sin alerta |
| 70-84 | Bueno | Solo cuando exista IBB calculado |
| 50-69 | Regular | Solo cuando exista IBB calculado |
| 30-49 | Deficiente | Solo cuando exista IBB calculado |
| Menor que 30 | Crítico | Solo cuando exista IBB calculado |

**Indicadores documentados y disponibilidad en campo:**

| Sigla | Nombre | Qué mide |
|:-:|---|---|
| **ΔT** | Diferencial térmico | Interior vs. exterior — si sube, la colmena se está ventilando |
| EV | Eficiencia de ventilación | Requiere CO₂; no se calcula en campo |
| H_miel | Estimación indirecta basada en HR | No mide la humedad real de la miel |
| Flujo de peso | Variación de peso | No confirma por sí sola flujo de néctar |

---

## 🌐 API

Cinco endpoints, todos en `api/`. La ingesta usa firma; el alta ECDSA está corregida en código, pero aún requiere prueba en hardware con NVS limpia.

| Método | Ruta | Descripción |
|:-:|---|---|
| `GET` | `api/ping.php` | Salud del servicio |
| `POST` | `api/enroll.php` | Alta del nodo con su clave pública y prueba de posesión |
| `POST` | `api/enroll_estado.php` | Estado de autorización del nodo |
| `POST` | `api/ingest.php` | Recibe, valida y guarda lecturas; indicadores requieren entradas disponibles |
| `POST` | `api/validate_token.php` | **Heredado**: valida el token de un nodo con firmware antiguo |

<br>

<details>
<summary><b>▸ Ejemplo: ingestión de lecturas</b></summary>
<br>

```bash
# La petición se firma con la clave privada del nodo; la clave NUNCA viaja.
CLAVE="nodo_clave_privada.pem"
ID="3f9a0c1d2e3b4a5968778695a4b3c2d1"
MAC="AA:BB:CC:DD:EE:FF"
RUTA="/BeeStation_Sena/api/ingest.php"
TS=$(date +%s)
NONCE=$(openssl rand -hex 16)
CUERPO='{"lecturas":[
  {"tipo":"temperatura_interna","valor":34.5,"id_medicion":"a1b2c3d4e5f60718293a4b5c6d7e8f00"},
  {"tipo":"humedad_relativa",   "valor":62.0,"id_medicion":"a1b2c3d4e5f60718293a4b5c6d7e8f01"},
  {"tipo":"peso",               "valor":18.7,"id_medicion":"a1b2c3d4e5f60718293a4b5c6d7e8f03"}
]}'
HASH=$(printf '%s' "$CUERPO" | sha256sum | cut -d' ' -f1)
CANON=$(printf 'BEE2\nPOST\n%s\n%s\n%s\n%s\n%s\n%s' "$RUTA" "$TS" "$MAC" "$NONCE" "$ID" "$HASH")
FIRMA=$(printf '%s' "$CANON" | openssl dgst -sha256 -sign "$CLAVE" | xxd -p -c 256 | tr -d '\n')

curl -X POST "http://localhost$RUTA" \
  -H 'Content-Type: application/json' \
  -H "X-BeeStation-Dispositivo: $ID" \
  -H "X-BeeStation-Mac: $MAC" \
  -H "X-BeeStation-Timestamp: $TS" \
  -H "X-BeeStation-Nonce: $NONCE" \
  -H "X-BeeStation-Firma: $FIRMA" \
  -d "$CUERPO"
```

Ejemplo ilustrativo de respuesta; no representa telemetría del nodo físico. El IBB no se calcula mientras falte CO₂.
```json
{
  "ok": true,
  "ok_total": true,
  "colmena": "Alpha-01",
  "resultados": [
    { "indice": 0, "id_medicion": "a1b2…f00", "estado": "insertada" },
    { "indice": 1, "id_medicion": "a1b2…f01", "estado": "insertada" },
    { "indice": 2, "id_medicion": "a1b2…f03", "estado": "insertada" }
  ],
  "insertados": 3,
  "rechazadas": 0,
  "ibb_calculado": null
}
```

`resultados[]` permite al nodo saber **qué lectura** se guardó, cuál era
duplicada y cuál fue rechazada, sin tener que reenviar el lote completo.

</details>

<details>
<summary><b>▸ Códigos de estado</b></summary>
<br>

| Código | Causa |
|:-:|---|
| `200` | Ingesta correcta |
| `401` | Firma ausente o inválida, nonce reutilizado, timestamp fuera de ventana, nodo pendiente o revocado |
| `400` | JSON malformado o lectura incompleta |
| `405` | Método distinto de `POST` |
| `413` | Más de 200 lecturas en una petición |
| `429` | Demasiados intentos de alta o de código |

</details>

---

## 🖥️ Dashboard

| Vista | Ruta | Qué muestra |
|---|---|---|
| 📊 Resumen | `dashboard.php` | Estado del nodo, cuatro métricas reales, alertas y “qué revisar” |
| 📱 Dispositivos | `dispositivos.php` | Registro de colmenas, autorización/revocación de nodos y estado de conexión |
| 🌡️ Sensores | `sensores.php` | Variables con unidad, estado real y frescura |
| Acústica | `acustica.php` | Frecuencia estimada experimental; no confirma enjambrazón |
| ⚖️ Peso | `peso.php` | Evolución del peso; no confirma por sí sola flujo de néctar |
| Energía | `energia.php` | Placeholder; el nodo no envía energía |
| 📡 Radar | `conectar_dispositivo.php` | Pantalla de espera que detecta la primera ingesta y redirige al dashboard |
| 🔑 Login | `login.php` | Autenticación de usuarios |

---

## 🗄️ Base de datos

```mermaid
erDiagram
    usuario ||--o{ apiario : tiene
    apiario ||--o{ colmena : contiene
    colmena ||--o{ sensor : "mide con"
    colmena ||--o{ indicador : "genera"
    variable_bioclimatica ||--o{ sensor : "clasifica a"
    sensor ||--o{ lectura : "registra"
    sensor ||--o{ calibracion : "ajusta con"
    indicador ||--o{ alerta : "dispara"
    colmena ||--o{ dispositivo : "recibe datos de"
```

**`schema.sql` — 14 tablas:** `usuario` · `apiario` · `colmena` · `variable_bioclimatica` · `sensor` · `calibracion` · `lectura` · `indicador` · `alerta` · `intento_vinculacion` · `lote_ingesta` · `nonce_peticion` · `dispositivo` · `nonce_dispositivo`

<details>
<summary><b>▸ Notas de diseño</b></summary>
<br>

- El sistema de roles (`rol` y `usuario.id_rol`) **fue eliminado del diseño**: todos los usuarios autenticados tienen el mismo nivel de acceso.
- El modelo completo se diseñó en notación Chen con 12 entidades; la implementación usa este esquema simplificado.
- El campo de precisión se llama `precision_valor` para evitar la palabra reservada `precision`.
- El esquema versionado es **`database/schema.sql`** (instalación única, base nueva y vacía). No hay migraciones sueltas: todo el esquema consolidado vive en ese archivo. El dump completo del entorno de desarrollo **no se versiona** porque contiene hashes de contraseñas y tokens de vinculación; cada instalación genera el suyo fuera del repositorio.

</details>

---

## 🚀 Instalación

### 1 · Backend web

```bash
git clone https://github.com/jeronimoparra-ai/BeeStation_Sena.git
```

| # | Paso |
|:-:|---|
| 1 | Copia el proyecto a `htdocs/` de XAMPP |
| 2 | Importa `database/schema.sql` en phpMyAdmin — crea la BD, las tablas y la colmena de ejemplo |
| 3 | Ajusta `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` en `config/db.php` |
| 4 | Genera un hash real con `password_hash()` y reemplázalo en la tabla `usuario` *(el que trae `schema.sql` es un placeholder)* |
| 5 | Copia `config/db.example.php` a `config/db.local.php` y ajusta las credenciales |
| 6 | Inicia Apache + MySQL y entra a `http://localhost/BeeStation` |

<details>
<summary><b>▸ Requisitos</b></summary>
<br>

- **PHP 8+** con extensión PDO MySQL
- **MySQL / MariaDB** (probado con MariaDB 10.4)
- **XAMPP** u otro stack Apache + PHP ([apachefriends.org](https://www.apachefriends.org/))
- No se necesita cuenta en ningún servicio externo

</details>

### 2 · Firmware

| # | Paso |
|:-:|---|
| 1 | Abre `beestation_node/beestation_node.ino` en **Arduino IDE** |
| 2 | Board: **ESP32 Dev Module** · Partition Scheme: **Minimal SPIFFS (1.9MB APP)** |
| 3 | Selecciona el puerto COM/USB |
| 4 | ✔ Verificar → ⬆ Subir *(mantén BOOT si no avanza)* |
| 5 | Enciende el nodo, conéctate a `BeeStation_Config` y guarda tu red (no hay token) |
| 6 | En `dispositivos.php` > *Nodos IoT*, usa el código de alta de formato `4-4`; el firmware ya lo genera, pero el flujo completo aún requiere prueba en hardware |

<details>
<summary><b>▸ Comprobación sin hardware</b></summary>
<br>

El firmware se puede validar sin la placa conectada:

```bash
arduino-cli compile --fqbn esp32:esp32:esp32:PartitionScheme=min_spiffs beestation_node
# El Sketch usa 1298228 bytes (66%) del espacio de almacenamiento de programa.
# Las variables globales usan 57544 bytes (17%) de la memoria dinámica.
```

> La Fase 2 (identidad ECDSA) deja el binario en ~1,30 MB. Con la tabla de
> particiones por defecto quedaría al 98 %; por eso se usa `min_spiffs`
> (1,9 MB de APP) y hay que **reflashear la tabla por USB una vez**.
> Detalles en `docs/reconciliacion-fase2.md`.
>
> Unidades, disponibilidad real de sensores y la estimación de frecuencia
> acústica (firmware 3.2.0): `docs/SENSORES-Y-UNIDADES.md`.

</details>

---

## 📁 Estructura del proyecto

```
BeeStation_Sena/
├── 🌐 api/
│   ├── ingest.php              # Ingesta de lecturas (ESP32 → servidor)
│   ├── enroll.php              # Alta del nodo con prueba de posesión (ECDSA)
│   ├── enroll_estado.php       # Estado de autorización del nodo
│   ├── validate_token.php      # Heredado: token de firmware antiguo
│   └── ping.php                # Salud del servicio
├── ⚙️ config/
│   └── db.php                  # Conexión PDO (las credenciales reales están en db.local.php)
├── 🗄️ database/
│   ├── schema.sql              # Esquema limpio (recomendado para empezar)
│   └── migrations/             # Migraciones incrementales (001–008)
├── ♾️ includes/
│   ├── functions.php           # Consultas, frescura e indicadores (no todos disponibles en campo)
│   ├── auth.php / header.php / sidebar.php / footer.php
├── 🐝 beestation_node/
│   ├── beestation_node.ino     # Firmware ESP32 v3.2.1
│   ├── identidad_ecdsa.h       # Identidad ECDSA P-256 + firma de peticiones
│   ├── util_hex.h              # Hexadecimal (mayúsculas/minúsculas)
│   └── index_html.h            # HTML del portal cautivo
├── 🎨 css/ · 📜 js/ · 🖼️ assets/
├── 📄 *.php                    # Vistas del dashboard
└── 📘 guia-configuracion-beestation.docx   # Manual técnico completo
```

---

## 🗺️ Roadmap

### ✅ Completado

- [x] Modelo entidad-relación (notación Chen, 12 entidades) y esquema SQL
- [x] Prototipo físico impreso en 3D (carcasa hexagonal)
- [x] Backend PHP + MySQL con dashboard sobre **datos reales** — sin valores de relleno
- [x] `api/ingest.php`: recibe, valida y guarda las lecturas compatibles
- [x] Alta ECDSA: el firmware ya genera y persiste el código `4-4`
- [ ] Alta ECDSA: validar el flujo completo en hardware con NVS limpia
- [x] Autorización y revocación individual de nodos desde el panel
- [x] Acuse por lectura (`id_medicion`) y cola offline que no se bloquea
- [x] Formulas documentadas; IBB, EV y DeltaT no disponibles en campo; H_miel es indirecta y no mide miel
- [x] Estructura de alertas persistidas en la tabla `alerta`; la alerta acústica está desactivada
- [x] Esquema preparado para energía (`energia`, INA219); hardware no instalado
- [x] Sistema de roles eliminado del esquema (ya no quedan restos)
- [x] **Firmware ESP32 v3.2.1**: identidad ECDSA sin API Key y descubrimiento del servidor antes del alta
- [x] Compilación verificada con `esp32:esp32@3.3.12` y las 4 bibliotecas de la guía
- [x] Zona horaria sincronizada entre PHP y MySQL (`America/Bogota`)

### ⏳ En curso

- [ ] **Carga del firmware al hardware físico** — no hay placa ESP32 conectada todavía
- [ ] **Prueba de campo del portal cautivo** — requiere el equipo encendido
- [ ] Verificar el mapa de pines definitivo *(la guía técnica y el firmware difieren en HX711 y MAX4466)*

### 📌 Pendiente

- [ ] Agregar CO₂ para IBB y temperatura exterior para ΔT; energía tampoco se transmite. El nodo sí envía presión y luminosidad.
- [ ] **Investigación:** IRE (fórmula no validada; no es una función del producto)
- [ ] **Investigación:** análisis acústico con grabaciones etiquetadas; no se promete detección de enjambrazón
- [ ] Conectividad **LoRa** para apiarios sin cobertura WiFi
- [ ] Ruta OTA para actualizar firmware sin cable
- [ ] Reporte académico completo

<details>
<summary><b>▸ Guía técnica y troubleshooting</b></summary>
<br>

El repositorio incluye **`guia-configuracion-beestation.docx`**: manual de instalación, compilación, aprovisionamiento por portal cautivo y diagnóstico de hardware.

**Errores frecuentes:**

| Síntoma | Causa | Solución |
|---|---|---|
| `fatal error: Adafruit_Sensor.h: No such file` | Falta la librería base | Instala **Adafruit Unified Sensor** |
| Puerto COM gris o inhabilitado | Cable de solo carga o driver faltante | Usa cable de datos e instala CP210x / CH340 |
| `HTTP 401 Unauthorized` en ingesta | Firma inválida, nodo revocado/pendiente o reloj desincronizado | Revisa el `motivo` de la respuesta y el estado del nodo en *Dispositivos → Nodos IoT* |
| El nodo no envía nada y el panel lo muestra pendiente | Falta autorizarlo con su código de alta | Introduce el código `4-4` en *Dispositivos → Nodos IoT* |
| `Sketch too big` al compilar | Tabla de particiones por defecto | Compila con `PartitionScheme=min_spiffs` y reflashea la tabla por USB |

</details>

---

## 👥 Equipo

<details>
<summary><b>Centro de formación y proyecto</b></summary>
<br>

| | |
|---|---|
| **Centro** | Centro Minero Ambiental (CFMA) — SENA, El Bagre, Antioquia |
| **Programa** | Técnico en Programación de Software |
| **Ficha** | 3412544 |
| **Instructor** | Farley González |

**Equipo**
- Andrés Jerónimo Parra Bastidas
- Diego Noriega Vega
- Samuel Montoya Suárez
- Edwin Segundo Camacho

</details>

---

<div align="center">

**Hecho con 🍯 en El Bagre, Antioquia**

[![MIT License](https://img.shields.io/badge/licencia-MIT-F5A623?style=for-the-badge&labelColor=1a1a1a)](./LICENSE)

</div>
