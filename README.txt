============================================================
BEESTATION — SISTEMA CONECTADO A BASE DE DATOS REAL
Proyecto ApiTechnology · SENA Centro de Formación Ambiental
============================================================

Este proyecto NO usa datos ficticios en ningún lado. Todo lo que
se muestra en el dashboard viene de la tabla `lectura` de la base
de datos. Si aún no hay lecturas, las páginas muestran mensajes
de "sin datos todavía" en lugar de inventar números.

============================================================
1. INSTALACIÓN
============================================================

1.1 Requisitos
------------------
- PHP 8.0 o superior con extensión PDO MySQL
- MySQL o MariaDB
- Servidor local: XAMPP, WAMP, Laragon, o un hosting con PHP+MySQL

1.2 Crear la base de datos
------------------------------
- Abre phpMyAdmin (o el cliente MySQL que uses).
- Ejecuta el archivo: database/schema.sql
  Esto crea la base de datos "beestation_sena", todas las tablas,
  y una colmena de ejemplo (Alpha-01) con sus 6 sensores
  registrados, pero SIN lecturas falsas.

1.3 Configurar la conexión
------------------------------
Abre config/db.php y ajusta:

  define('DB_HOST', 'localhost');
  define('DB_NAME', 'beestation_sena');
  define('DB_USER', 'root');
  define('DB_PASS', '');   <-- pon tu clave real aquí

1.4 Generar la contraseña real del usuario admin
----------------------------------------------------
El schema.sql trae un usuario admin@beestation.io con una
contraseña de relleno que NO va a funcionar. Genera la real así:

  a) Crea un archivo temporal generar_clave.php con este contenido:

     <?php
     echo password_hash("TU_CLAVE_AQUI", PASSWORD_DEFAULT);
     ?>

  b) Ábrelo en el navegador, copia el texto que aparece (empieza
     con $2y$10$...)

  c) En phpMyAdmin, ve a la tabla `usuario`, edita el registro de
     admin@beestation.io y pega ese texto en el campo `contrasena`.

  d) Borra el archivo generar_clave.php por seguridad.

1.5 Copiar el proyecto al servidor
---------------------------------------
Copia toda la carpeta BeeStation/ dentro de la carpeta pública de
tu servidor (por ejemplo htdocs/ en XAMPP) y accede desde:

  http://localhost/BeeStation/


============================================================
2. CÓMO FUNCIONA EL FLUJO DE DATOS (de principio a fin)
============================================================

  ESP32 con sensores  --(POST JSON)-->  api/ingest.php
                                              |
                                              v
                                    Se guarda en tabla `lectura`
                                              |
                                              v
                            Se recalcula el indicador IBB real
                                              |
                                              v
                        Si el IBB es bajo, se crea una `alerta` real
                                              |
                                              v
                   dashboard.php / peso.php / acustica.php / sensores.php
                   consultan la base de datos y muestran los datos TAL
                   CUAL están guardados — nunca se inventan valores.

Si el ESP32 nunca ha enviado nada, todas las páginas muestran un
estado vacío ("Sin datos todavía") en lugar de números falsos.


============================================================
3. CÓDIGO ARDUINO PARA EL ESP32 (envío real de datos)
============================================================

Copia este código en Arduino IDE, ajusta el WiFi y la URL de tu
servidor, y cárgalo al ESP32. Cada 60 segundos leerá los sensores
(aquí simulados con dummy() solo como ejemplo de estructura — 
reemplázalos por tus lecturas reales de DHT22, HX711, etc.) y
enviará el JSON real a api/ingest.php.

------------------------------------------------------------
#include <WiFi.h>
#include <HTTPClient.h>

const char* ssid     = "TU_WIFI";
const char* password = "TU_CLAVE_WIFI";
const char* serverUrl = "http://192.168.1.100/BeeStation/api/ingest.php";
// Cambia 192.168.1.100 por la IP real de tu servidor en la red

const int ID_COLMENA = 1; // debe existir en la tabla `colmena`

void setup() {
  Serial.begin(115200);
  WiFi.begin(ssid, password);
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.println("\nConectado. IP: " + WiFi.localIP().toString());
}

void loop() {
  if (WiFi.status() == WL_CONNECTED) {
    HTTPClient http;
    http.begin(serverUrl);
    http.addHeader("Content-Type", "application/json");

    // ⚠️ Reemplaza estos valores por las lecturas REALES de tus
    // sensores (DHT22, HX711, MAX9814, MQ-135), no dejes números fijos.
    float temperatura = leerTemperaturaDHT22();
    float humedad      = leerHumedadDHT22();
    float peso          = leerPesoHX711();
    float sonido        = leerFrecuenciaSonido();
    float co2            = leerCO2MQ135();

    String json = "{";
    json += "\"id_colmena\":" + String(ID_COLMENA) + ",";
    json += "\"lecturas\":[";
    json += "{\"tipo\":\"temperatura_interna\",\"valor\":" + String(temperatura) + "},";
    json += "{\"tipo\":\"humedad_relativa\",\"valor\":" + String(humedad) + "},";
    json += "{\"tipo\":\"peso\",\"valor\":" + String(peso) + "},";
    json += "{\"tipo\":\"sonido\",\"valor\":" + String(sonido) + "},";
    json += "{\"tipo\":\"co2\",\"valor\":" + String(co2) + "}";
    json += "]}";

    int httpCode = http.POST(json);
    String respuesta = http.getString();

    Serial.println("Código HTTP: " + String(httpCode));
    Serial.println("Respuesta: " + respuesta);

    http.end();
  } else {
    Serial.println("WiFi desconectado, reintentando...");
    WiFi.begin(ssid, password);
  }

  delay(60000); // esperar 60 segundos antes de la próxima lectura
}

// ---- Reemplaza estas funciones con la lectura real de tus sensores ----
float leerTemperaturaDHT22() { /* usar librería DHT.h */ return 0; }
float leerHumedadDHT22()      { /* usar librería DHT.h */ return 0; }
float leerPesoHX711()          { /* usar librería HX711.h */ return 0; }
float leerFrecuenciaSonido()   { /* análisis FFT del MAX9814 */ return 0; }
float leerCO2MQ135()            { /* lectura analógica del MQ-135 */ return 0; }
------------------------------------------------------------


============================================================
4. PROBAR EL SISTEMA SIN TENER EL ESP32 A LA MANO
============================================================

Puedes simular el envío de datos reales con curl (o Postman) para
verificar que todo el flujo funciona antes de conectar el hardware:

  curl -X POST http://localhost/BeeStation/api/ingest.php \
    -H "Content-Type: application/json" \
    -d "{\"id_colmena\":1,\"lecturas\":[{\"tipo\":\"temperatura_interna\",\"valor\":35.2},{\"tipo\":\"humedad_relativa\",\"valor\":65},{\"tipo\":\"peso\",\"valor\":28.5},{\"tipo\":\"sonido\",\"valor\":280},{\"tipo\":\"co2\",\"valor\":2100}]}"

Después de ejecutar esto, entra al dashboard y verás los datos
reales reflejados — porque efectivamente ya están en la base de
datos, no porque estén hardcodeados en el código.


============================================================
5. ROLES DEL SISTEMA
============================================================

La tabla `rol` ya trae 3 roles predefinidos:

  1. Apicultor      (nivel_acceso 1) — ve el dashboard y alertas
  2. Administrativo (nivel_acceso 2) — además ve Sensores
  3. Desarrollador   (nivel_acceso 3) — acceso total

El menú lateral (includes/sidebar.php) ya oculta la opción
"Sensores" a los usuarios con nivel_acceso menor a 2.


============================================================
6. ESTRUCTURA DE ARCHIVOS
============================================================

BeeStation/
├── database/schema.sql        <- Ejecutar primero en MySQL
├── config/db.php               <- Configurar credenciales aquí
├── api/ingest.php               <- Endpoint real para el ESP32
├── includes/
│   ├── auth.php                 <- Login real con password_hash
│   ├── functions.php            <- Consultas reales + cálculo de IBB
│   ├── header.php, footer.php, sidebar.php
├── login.php
├── dashboard.php                <- Resumen con datos reales
├── sensores.php                 <- Estado real de cada sensor
├── peso.php                     <- Histórico real de peso
├── acustica.php                 <- Análisis real de sonido
├── energia.php                  <- Pendiente de sensor de energía
├── dispositivos.php             <- Instrucciones del endpoint
├── css/style.css
├── js/app.js, js/charts.js
└── assets/logo-beestation.png

============================================================
Documento elaborado para el proyecto ApiTechnology
SENA - Centro de Formación Ambiental - Caucasia, Antioquia - 2026
============================================================
