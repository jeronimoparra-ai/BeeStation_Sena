<div align="center">

![Banner BeeStation](https://capsule-render.vercel.app/api?type=waving&color=0:1a1a1a,50:D98E04,100:F5A623&height=220&section=header&text=BeeStation&fontSize=70&fontColor=FFFFFF&animation=fadeIn&fontAlignY=38&desc=Monitoreo%20Inteligente%20de%20Colmenas%20con%20IoT&descAlignY=58&descSize=20)

[![Typing SVG](https://readme-typing-svg.demolab.com/?font=Fira+Code&weight=600&size=22&pause=1200&color=F5A623&center=true&vCenter=true&width=650&lines=%F0%9F%90%9D+Cuidando+colmenas+con+tecnolog%C3%ADa;ESP32+%2B+MQTT+%2B+ThingSpeak+%2B+PHP+%2B+MariaDB;Proyecto+SENA+-+El+Bagre%2C+Antioquia)](https://git.io/typing-svg)

<br>

![Static Badge](https://img.shields.io/badge/Estado-En%20desarrollo-F5A623?style=for-the-badge&labelColor=1a1a1a)
![License](https://img.shields.io/github/license/jeronimoparra-ai/BeeStation_Sena?style=for-the-badge&color=F5A623&labelColor=1a1a1a)
![Last Commit](https://img.shields.io/github/last-commit/jeronimoparra-ai/BeeStation_Sena?style=for-the-badge&color=F5A623&labelColor=1a1a1a)
![Repo Size](https://img.shields.io/github/repo-size/jeronimoparra-ai/BeeStation_Sena?style=for-the-badge&color=F5A623&labelColor=1a1a1a)

</div>

<br>

<p align="center">
  <img src="https://raw.githubusercontent.com/MartinHeinz/MartinHeinz/master/wave.gif" width="100%" alt="separador">
</p>

## 📋 Tabla de contenido

- [Acerca del proyecto](#-acerca-del-proyecto)
- [Institución académica](#-institución-académica)
- [Equipo de desarrollo](#-equipo-de-desarrollo)
- [Características](#-características)
- [Arquitectura del sistema](#-arquitectura-del-sistema)
- [Stack tecnológico](#-stack-tecnológico)
- [Estructura de la base de datos](#-estructura-de-la-base-de-datos)
- [Instalación](#-instalación)
- [Hoja de ruta](#-hoja-de-ruta)
- [Licencia](#-licencia)

<br>

## 🐝 Acerca del proyecto

**BeeStation** es un sistema de monitoreo apícola no invasivo basado en Internet de las Cosas (IoT). Utiliza sensores conectados a un microcontrolador **ESP32** para recolectar en tiempo real variables críticas de las colmenas —temperatura, humedad, peso, sonido, calidad del aire y consumo energético— sin necesidad de abrir o intervenir físicamente el panal.

La información recolectada se transmite mediante el protocolo **MQTT** a **ThingSpeak** para su visualización y análisis, y se integra con un panel de control propio (dashboard web) desarrollado en HTML, CSS y PHP sobre una base de datos MariaDB, pensado para que apicultores y estudiantes puedan tomar decisiones informadas sobre la salud y productividad de sus colmenas.

Antes de llegar a la implementación actual, el equipo pasó por una etapa de diseño y prototipado: un modelo entidad-relación completo, un prototipo de interfaz navegable en Figma y un prototipo físico de la carcasa impreso en 3D, descritos con más detalle abajo.

El proyecto nace como iniciativa de formación en el **Centro Minero Ambiental (CFMA)** del SENA en El Bagre, Antioquia, articulando electrónica, desarrollo de software y sostenibilidad ambiental.

<br>

## 🏫 Institución académica

<div align="center">

| | |
|---|---|
| 🏢 **Centro de formación** | Centro Minero Ambiental (CFMA) - SENA |
| 📍 **Ubicación** | El Bagre, Antioquia, Colombia |
| 👨‍🏫 **Instructor** | Farley González |
| 🎓 **Programa** | Técnico en Programación de Software |

</div>

<br>

## 👥 Equipo de desarrollo

<div align="center">

| Integrante |
|---|
| 🐝 Andrés Jerónimo Parra |
| 🐝 Diego Noriega Vega |
| 🐝 Samuel Montoya Suárez |
| 🐝 Edwin Segundo Camacho |

</div>

<br>

## ✨ Características

<table align="center">
<tr>
<td width="50%">

### 🌡️ Monitoreo en tiempo real
Sensores ESP32 —**DHT22** (temperatura/humedad), **HX711** (peso), **MAX9814** (acústica) y **MQ-135** (calidad del aire)— capturan las variables de la colmena de forma continua.

</td>
<td width="50%">

### 🔋 Panel de energía
Seguimiento del consumo y estado energético del sistema de sensores en campo.

</td>
</tr>
<tr>
<td width="50%">

### 📊 Dashboard visual
Interfaz web con tarjetas informativas, pensada para lectura rápida de indicadores clave.

</td>
<td width="50%">

### ☁️ Integración con ThingSpeak
Transmisión de datos vía protocolo MQTT, con visualización histórica y análisis de tendencias.

</td>
</tr>
<tr>
<td width="50%">

### 🖨️ Prototipo físico
Carcasa hexagonal impresa en 3D, diseñada para proteger los sensores en campo.

</td>
<td width="50%">

### 🎨 Prototipo de interfaz
Maqueta navegable en Figma con control de acceso por roles (Administrativo / Apicultor) y mapa de ubicación de dispositivos con Leaflet.js + OpenStreetMap.

</td>
</tr>
</table>

<br>

## 🏗️ Arquitectura del sistema

```mermaid
flowchart LR
    A[🐝 Sensores ESP32] -->|WiFi + MQTT| B[☁️ ThingSpeak]
    A -->|WiFi| C[(🗄️ MariaDB)]
    C --> D[⚙️ Backend PHP]
    D --> E[💻 Dashboard Web]
    B --> E

    style A fill:#1a1a1a,stroke:#F5A623,color:#F5A623
    style B fill:#1a1a1a,stroke:#F5A623,color:#F5A623
    style C fill:#1a1a1a,stroke:#F5A623,color:#F5A623
    style D fill:#1a1a1a,stroke:#F5A623,color:#F5A623
    style E fill:#1a1a1a,stroke:#F5A623,color:#F5A623
```

<br>

## 🛠️ Stack tecnológico

<div align="center">

![ESP32](https://img.shields.io/badge/ESP32-000000?style=for-the-badge&logo=espressif&logoColor=F5A623)
![MQTT](https://img.shields.io/badge/MQTT-1a1a1a?style=for-the-badge&logo=mqtt&logoColor=F5A623)
![PHP](https://img.shields.io/badge/PHP-1a1a1a?style=for-the-badge&logo=php&logoColor=F5A623)
![MariaDB](https://img.shields.io/badge/MariaDB-1a1a1a?style=for-the-badge&logo=mariadb&logoColor=F5A623)
![HTML5](https://img.shields.io/badge/HTML5-1a1a1a?style=for-the-badge&logo=html5&logoColor=F5A623)
![CSS3](https://img.shields.io/badge/CSS3-1a1a1a?style=for-the-badge&logo=css3&logoColor=F5A623)
![Arduino](https://img.shields.io/badge/Arduino_IDE-1a1a1a?style=for-the-badge&logo=arduino&logoColor=F5A623)

</div>

**Sensores utilizados:** DHT22 (temperatura/humedad), HX711 (celda de carga para peso), MAX9814 (micrófono para acústica), MQ-135 (calidad del aire).

<br>

## 🗃️ Estructura de la base de datos

Jerarquía de entidades del modelo relacional:

```mermaid
flowchart TD
    Rol --> Usuario
    Usuario --> Apiario
    Apiario --> Colmena
    Colmena --> Sensor
    Sensor --> Lectura
    Colmena --> Alerta
    Indicador --> Alerta

    style Rol fill:#1a1a1a,stroke:#F5A623,color:#F5A623
    style Usuario fill:#1a1a1a,stroke:#F5A623,color:#F5A623
    style Apiario fill:#1a1a1a,stroke:#F5A623,color:#F5A623
    style Colmena fill:#1a1a1a,stroke:#F5A623,color:#F5A623
    style Sensor fill:#1a1a1a,stroke:#F5A623,color:#F5A623
    style Lectura fill:#1a1a1a,stroke:#F5A623,color:#F5A623
    style Indicador fill:#1a1a1a,stroke:#F5A623,color:#F5A623
    style Alerta fill:#1a1a1a,stroke:#F5A623,color:#F5A623
```

> El modelo completo se diseñó en notación Chen con 12 entidades. Para la implementación se optó por un esquema simplificado de 8 tablas (`database/beestation_schema.sql`), con relaciones de llave foránea correctamente definidas y un ajuste de nombre para evitar la palabra reservada `precision`, renombrada como `sensor_precision`.

<br>

## ⚙️ Instalación

### Requisitos previos

- [XAMPP](https://www.apachefriends.org/) (Apache + MariaDB + PHP)
- [Arduino IDE](https://www.arduino.cc/en/software) con soporte para ESP32
- Cuenta en [ThingSpeak](https://thingspeak.com/)

### Pasos

```bash
# 1. Clonar el repositorio
git clone https://github.com/jeronimoparra-ai/BeeStation_Sena.git

# 2. Mover el proyecto a la carpeta htdocs de XAMPP
# (en Windows) C:\xampp\htdocs\

# 3. Importar el esquema de base de datos en phpMyAdmin
# Archivo: /database/beestation_schema.sql

# 4. Configurar credenciales de conexión
# Editar el archivo de configuración PHP con tus datos de MariaDB

# 5. Cargar el firmware en el ESP32 desde Arduino IDE
# Configurar SSID, contraseña WiFi y canal MQTT de ThingSpeak

# 6. Iniciar Apache y MariaDB desde el panel de XAMPP
# Acceder al dashboard en http://localhost/BeeStation
```

<br>

## 🗺️ Hoja de ruta

- [x] Diseño del modelo entidad-relación (notación Chen, 12 entidades) y esquema SQL simplificado de 8 tablas con relaciones FK
- [x] Prototipo de interfaz navegable en Figma (roles de acceso, mapa de dispositivos)
- [x] Prototipo físico de la carcasa (impresión 3D, diseño hexagonal)
- [x] Conexión inalámbrica del ESP32 (WiFi + MQTT hacia ThingSpeak)
- [x] Dashboard web (login, resumen, dispositivos, sensores, acústica, peso, energía)
- [x] Estrategia de actualización de firmware OTA definida (ArduinoOTA + modo AP)
- [ ] Implementación de OTA en campo (el primer flasheo aún requiere conexión USB)
- [ ] Detección de enjambrazón con inteligencia artificial
- [ ] Conectividad LoRa para zonas rurales sin cobertura WiFi
- [ ] Reporte académico completo (justificación, metodología, cronograma, presupuesto)

<br>

## 📄 Licencia

Este proyecto está bajo la licencia MIT. Consulta el archivo [LICENSE](./LICENSE) para más detalles.

<br>

<div align="center">

![Footer BeeStation](https://capsule-render.vercel.app/api?type=waving&color=0:1a1a1a,50:D98E04,100:F5A623&height=150&section=footer)

**Hecho con 🐝 en El Bagre, Antioquia — SENA, Centro Minero Ambiental**

</div>
