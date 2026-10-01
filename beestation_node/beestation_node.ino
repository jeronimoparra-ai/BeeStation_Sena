#include <WiFi.h>
#include <DNSServer.h>
#include <WebServer.h>
#include <Preferences.h>
#include <HTTPClient.h>
#include <ESPmDNS.h>
#include <time.h>            // NTP + hora real para los registros de la microSD

// ── LIBRERÍAS DE HARDWARE REAL (Diseño de tu Placa) ──────────
#include <Wire.h>
#include <SPI.h>
#include <SD.h>
#include <Adafruit_Sensor.h> // Requiere "Adafruit Unified Sensor" en el Gestor de Bibliotecas
#include <Adafruit_BME280.h> // Requiere "Adafruit BME280 Library" en el Gestor de Bibliotecas
#include <BH1750.h>          // Requiere "BH1750" de Christopher Laws
#include <HX711.h>           // Requiere "HX711 Arduino Library" de Bogdan Necula
#include "index_html.h"      // Importa tu interfaz de la pestaña contigua
#include "monitoreo_html.h"  // Página de monitoreo en vivo (STA)

// ── VERSIÓN DEL FIRMWARE ──────────────────────────────────────
#define FIRMWARE_VERSION "3.1.0"
#define FIRMWARE_DATE    "2026-09-29"

// ── DEFINICIÓN DE PINES FÍSICOS (Según tu Plano Eléctrico) ────
// Bus I2C (compartido por BME280 y BH1750)
const int I2C_SDA = 21;       // GPIO 21 — Línea de datos I2C
const int I2C_SCL = 22;       // GPIO 22 — Línea de reloj I2C

// Sensor de peso HX711
const int HX711_DOUT   = 16;  // GPIO 16 — Pin de Datos del HX711
const int HX711_PD_SCK = 4;   // GPIO 4  — Pin de Reloj del HX711

// Micrófono analógico MAX4466 (ADC1 — compatible con WiFi activo)
const int MIC_ANALOG = 35;    // GPIO 35 (ADC1_CH7) — Entrada analógica del micrófono

// Tarjeta microSD (Bus VSPI nativo: MOSI=23, MISO=19, CLK=18)
const int SD_CS = 5;           // GPIO 5  — Chip Select de la microSD

// Controles físicos
const int BOTON_RESET = 0;    // Botón BOOT para formateo físico
const int LED_STATUS  = 2;    // LED azul integrado del ESP32 DevKit (GPIO2)

// ── Inicialización de sensores y servicios ────────────────────
Adafruit_BME280 bme;
BH1750 lightMeter;
HX711 scale;
DNSServer dnsServer;
WebServer server(80);
Preferences preferences;

// ── Variables de estado del hardware ──────────────────────────
bool bmeActivo     = false;
bool bh1750Activo  = false;
bool hx711Activo   = false;
bool sdActivo      = false;

// ── Variables de configuración de red y token seguro ──────────
String ssidGuardado  = "";
String passGuardado  = "";
String tokenGuardado = "";
String hostGuardado  = ""; // IP de tu PC o dominio web dinámico

// ── Flag de modo de operación ─────────────────────────────────
bool modoPortalCautivo = false;

// ── Configuración de red local para el Portal Cautivo ─────────
const byte DNS_PORT = 53;
IPAddress apIP(192, 168, 4, 1);

// ── SEGURIDAD: API Key sincronizada con config/db.php ─────────
// IMPORTANTE: Esta clave DEBE ser idéntica a BEESTATION_API_KEY
// definida en config/db.php del servidor PHP.
const char* apiKey = "ba20858ed12a853bbcaafec2d9c753db0ad9f7e60022d6e1a3f94f978743808a";

// ── Intervalo de RECOPILACIÓN de datos ────────────────────────
// Una lectura cada 5 minutos. La lectura se registra SIEMPRE en
// la microSD (con o sin WiFi) y, sólo si hay red, se sube al
// servidor. Así el nodo funciona igual de bien offline.
const unsigned long INTERVALO_LECTURA = 30000;  // 30 segundos
unsigned long ultimaLecturaPanel = 0;

// ── Estado del registro continuo en la microSD ────────────────
String archivoSDActivo = "";   // fichero del día en curso
unsigned long registrosSD = 0; // filas escritas en ese fichero

// ── Servidor web compartido entre modo AP (portal) y STA ──────
bool rutasWebConfiguradas = false;

// ── Control de reconexión WiFi con backoff exponencial ────────
unsigned long ultimoIntentoReconexion = 0;
int intentosReconexionConsecutivos = 0;
const int MAX_INTENTOS_ANTES_PORTAL = 5; // Intentar 5 veces antes de abrir portal

// ── Último diagnóstico de sensores (para endpoint /status) ────
float ultimaTemp     = 0.0;
float ultimaHum      = 0.0;
float ultimaPresion  = 0.0;
float ultimoPeso     = 0.0;
float ultimaLuz      = 0.0;
float ultimoSonido   = 0.0;
unsigned long ultimaLecturaMs = 0;

// ── Último dato SUBIDO al servidor (hora local exacta) ─────────
time_t ultimaSubidaEpoch = 0;
unsigned long ultimaSubidaMs = 0;

// Hora local HH:MM:SS de un epoch; sin reloj => "--:--:--"
String horaLocal(time_t ep) {
  if (ep <= 0) return "--:--:--";
  struct tm t;
  localtime_r(&ep, &t);
  char b[12];
  strftime(b, sizeof(b), "%H:%M:%S", &t);
  return String(b);
}

// ══════════════════════════════════════════════════════════════
//                        S E T U P
// ══════════════════════════════════════════════════════════════
void setup() {
  Serial.begin(115200);
  delay(300);

  Serial.println("INICIANDO SISTEMA...");

  // ── Banner de inicio con información del sistema ────────────
  imprimirBanner();

  // Configuración del botón de reset físico y LED de estado
  pinMode(BOTON_RESET, INPUT_PULLUP);
  pinMode(LED_STATUS, OUTPUT);
  digitalWrite(LED_STATUS, LOW);

  // Parpadeo inicial para confirmar que el ESP32 está vivo
  for (int i = 0; i < 3; i++) {
    digitalWrite(LED_STATUS, HIGH);
    delay(150);
    digitalWrite(LED_STATUS, LOW);
    delay(150);
  }

  // ── 1. CONTROL DE RESET FÍSICO (BOOT) ───────────────────────
  if (digitalRead(BOTON_RESET) == LOW) {
    Serial.println("[RESET] ¡Botón BOOT detectado! Formateando memoria flash NVS...");
    preferences.begin("beestation", false);
    preferences.clear();
    preferences.end();
    Serial.println("[RESET] Memoria borrada. Iniciando Portal Cautivo...");
    // Parpadeo rápido de confirmación de reset
    for (int i = 0; i < 6; i++) {
      digitalWrite(LED_STATUS, HIGH);
      delay(80);
      digitalWrite(LED_STATUS, LOW);
      delay(80);
    }
    delay(500);
  }

  // ── 2. CONFIGURACIÓN DEL ADC PARA MICRÓFONO MAX4466 ─────────
  // Establecer resolución de 12 bits (0–4095) explícitamente
  analogReadResolution(12);
  // Atenuación de 11dB para rango completo de 0–3.3V en GPIO34
  analogSetAttenuation(ADC_11db);
  Serial.println("[ADC] Resolución: 12 bits (4096 niveles), Atenuación: 11dB (0-3.3V)");

  // ── 3. INICIALIZACIÓN FÍSICA DE SENSORES ────────────────────
  Serial.println("\n╔══════════════════════════════════════════════╗");
  Serial.println("║       DIAGNÓSTICO DE HARDWARE               ║");
  Serial.println("╚══════════════════════════════════════════════╝\n");

  // Bus I2C estándar según plano eléctrico
  Wire.begin(I2C_SDA, I2C_SCL);
  Wire.setClock(100000); // 100kHz estable para BME280 + BH1750
  Serial.printf("[I2C] Bus inicializado → SDA=GPIO%d, SCL=GPIO%d @ 100kHz\n", I2C_SDA, I2C_SCL);

  // ── Sensor BME280 (Temperatura + Humedad + Presión) ─────────
  if (bme.begin(0x76)) {
    bmeActivo = true;
    Serial.println("[✓] BME280  → Clima Interno (Temp/Hum/Presión) detectado en 0x76");
    // Configuración de muestreo optimizada para monitoreo ambiental
    bme.setSampling(
      Adafruit_BME280::MODE_NORMAL,
      Adafruit_BME280::SAMPLING_X2,   // temperatura
      Adafruit_BME280::SAMPLING_X16,  // presión
      Adafruit_BME280::SAMPLING_X1,   // humedad
      Adafruit_BME280::FILTER_X16,
      Adafruit_BME280::STANDBY_MS_500
    );
  } else if (bme.begin(0x77)) {
    // Dirección alternativa del BME280
    bmeActivo = true;
    Serial.println("[✓] BME280  → Clima Interno detectado en dirección alternativa 0x77");
    bme.setSampling(
      Adafruit_BME280::MODE_NORMAL,
      Adafruit_BME280::SAMPLING_X2,
      Adafruit_BME280::SAMPLING_X16,
      Adafruit_BME280::SAMPLING_X1,
      Adafruit_BME280::FILTER_X16,
      Adafruit_BME280::STANDBY_MS_500
    );
  } else {
    Serial.println("[✗] BME280  → NO detectado. Verifica cableado I2C (VCC→3V3, GND, SDA→21, SCL→22)");
  }

  // ── Sensor BH1750 (Luminosidad en Luxes) ────────────────────
  if (lightMeter.begin(BH1750::CONTINUOUS_HIGH_RES_MODE)) {
    bh1750Activo = true;
    Serial.println("[✓] BH1750  → Luminosidad (Luxes) detectado en bus I2C compartido");
  } else {
    Serial.println("[✗] BH1750  → NO detectado. Verifica cableado (comparte SDA/SCL con BME280)");
  }

  // ── Sensor HX711 (Celda de Carga / Peso) ────────────────────
  // Verificación de hardware que previene bootloop si no está conectado
  pinMode(HX711_DOUT, INPUT_PULLUP);
  pinMode(HX711_PD_SCK, OUTPUT);
  digitalWrite(HX711_PD_SCK, LOW);
  delay(20);

  bool hx711Detectado = false;
  unsigned long tStartHX = millis();
  while (millis() - tStartHX < 200) {
    if (digitalRead(HX711_DOUT) == LOW) {
      hx711Detectado = true;
      break;
    }
    delay(10);
  }

  if (hx711Detectado) {
    scale.begin(HX711_DOUT, HX711_PD_SCK);
    scale.set_scale(1.0); // Factor de calibración (ajustar con peso conocido)
    
    // Evitar bloqueo si el sensor se desconecta justo después de ser detectado
    if (scale.wait_ready_timeout(1000)) {
      scale.tare();
      hx711Activo = true;
      Serial.printf("[✓] HX711   → Peso (Celda de Carga) inicializado (DT→GPIO%d, SCK→GPIO%d)\n", HX711_DOUT, HX711_PD_SCK);
    } else {
      hx711Activo = false;
      Serial.printf("[✗] HX711   → NO detectado (Tiempo de espera agotado al hacer tare)\n");
    }
  } else {
    hx711Activo = false;
    Serial.printf("[✗] HX711   → NO detectado (DT→GPIO%d, SCK→GPIO%d). Verifica VCC→VIN(5V)\n", HX711_DOUT, HX711_PD_SCK);
  }

  // ── Micrófono MAX4466 (verificación rápida) ─────────────────
  int testMic = analogRead(MIC_ANALOG);
  Serial.printf("[✓] MAX4466 → Micrófono analógico en GPIO%d (ADC1_CH6). Lectura de prueba: %d/4095\n", MIC_ANALOG, testMic);

  // ── Tarjeta microSD (Bus VSPI) ──────────────────────────────
  if (SD.begin(SD_CS)) {
    sdActivo = true;
    uint64_t cardSize = SD.cardSize() / (1024 * 1024);
    Serial.printf("[✓] microSD → Datalogger listo. CS→GPIO%d, Tamaño: %lluMB\n", SD_CS, cardSize);
  } else {
    sdActivo = false;
    Serial.printf("[✗] microSD → NO detectada (CS→GPIO%d, MOSI→23, MISO→19, CLK→18)\n", SD_CS);
  }

  // Resumen del diagnóstico
  int sensoresOK = (int)bmeActivo + (int)bh1750Activo + (int)hx711Activo + (int)sdActivo + 1; // +1 por MAX4466
  Serial.printf("\n[RESUMEN] %d/5 sensores activos", sensoresOK);
  if (sdActivo) Serial.print(" + microSD");
  Serial.println("\n");

  // ── Servidor web ────────────────────────────────────────────
  // NO se arranca aquí: WiFi aún no está inicializado y
  // WebServer::begin() aborta con "xQueueSemaphoreTake".
  // Se arranca en iniciarPortalCautivo() (modo AP) o en el
  // camino de éxito de WiFi (modo STA).
  //

  // ── 4. RECUPERACIÓN DE CONFIGURACIÓN DE MEMORIA NVS ─────────
  preferences.begin("beestation", true);
  ssidGuardado  = preferences.getString("ssid", "");
  passGuardado  = preferences.getString("password", "");
  tokenGuardado = preferences.getString("token", "");
  hostGuardado  = preferences.getString("host", "");
  preferences.end();

  Serial.println("╔══════════════════════════════════════════════╗");
  Serial.println("║       CONFIGURACIÓN DE RED                  ║");
  Serial.println("╚══════════════════════════════════════════════╝\n");

  if (ssidGuardado != "") {
    Serial.printf("[NVS] SSID guardado : %s\n", ssidGuardado.c_str());
    Serial.printf("[NVS] Host servidor : %s\n", hostGuardado.length() > 0 ? hostGuardado.c_str() : "(auto-detectar gateway)");
    Serial.printf("[NVS] Token         : %s...%s\n", 
      tokenGuardado.substring(0, 3).c_str(), 
      tokenGuardado.substring(max(0, (int)tokenGuardado.length() - 4)).c_str());
  } else {
    Serial.println("[NVS] Sin configuración guardada.");
  }

  // ── 5. PROTOCOLO DE CONEXIÓN WI-FI ─────────────────────────
  if (ssidGuardado != "" && tokenGuardado != "") {
    Serial.printf("\n[WiFi] Conectando a '%s'...\n", ssidGuardado.c_str());
    WiFi.mode(WIFI_STA);
    WiFi.setHostname("BeeStation-Node");
    WiFi.begin(ssidGuardado.c_str(), passGuardado.c_str());

    int intentos = 0;
    while (WiFi.status() != WL_CONNECTED && intentos < 40) {
      delay(500);
      Serial.print(".");
      // Parpadeo lento mientras intenta conectar
      digitalWrite(LED_STATUS, intentos % 2 == 0 ? HIGH : LOW);
      intentos++;
    }
    digitalWrite(LED_STATUS, LOW);

    if (WiFi.status() == WL_CONNECTED) {
      Serial.println("\n[WiFi] ¡Conexión establecida!");
      Serial.printf("[WiFi] IP del nodo   : %s\n", WiFi.localIP().toString().c_str());
      Serial.printf("[WiFi] Gateway       : %s\n", WiFi.gatewayIP().toString().c_str());
      Serial.printf("[WiFi] RSSI (señal)  : %d dBm\n", WiFi.RSSI());

      // ── Hora real por NTP para los registros de la microSD ───
      // configTzTime fija TZ antes de arrancar SNTP. Hay que usar
      // esta y NO configTime(): esa última pisa TZ con "UTC0DST0".
      // Colombia no aplica horario de verano: UTC-5 fijo ("COT5").
      configTzTime("COT5", "pool.ntp.org", "time.google.com", "time.nist.gov");
      Serial.println("[NTP] Sincronizando hora… (los CSV usarán la hora de Colombia)");

      // Esperar a que el reloj local esté listo ANTES de la primera
      // escritura en la microSD: así la primera fila del día cae en
      // su fichero correcto (/log_AAAA-MM-DD.csv) y no en el de
      // respaldo. Máximo 3 s; si no hay NTP, se sigue con ese respaldo.
      {
        struct tm relojLocal;
        if (getLocalTime(&relojLocal, 3000)) {
          char ahora[24];
          strftime(ahora, sizeof(ahora), "%Y-%m-%d %H:%M:%S", &relojLocal);
          Serial.printf("[NTP] ✓ Hora local : %s  (COT5)\n", ahora);
        } else {
          Serial.println("[NTP] ✗ Aún sin hora → los registros van a /log_sin_hora.csv");
        }
      }

      // Iniciar mDNS para que el dispositivo sea accesible como beestation.local
      if (MDNS.begin("beestation")) {
        Serial.println("[mDNS] Accesible como → http://beestation.local");
      }

      // Validar token con el servidor antes de empezar a enviar datos
      if (validarTokenConServidor()) {
        Serial.println("[AUTH] ✓ Token validado exitosamente con el servidor.");
        // LED encendido fijo 1 segundo para confirmar conexión exitosa
        digitalWrite(LED_STATUS, HIGH);
        delay(1000);
        digitalWrite(LED_STATUS, LOW);
        modoPortalCautivo = false;
        intentosReconexionConsecutivos = 0;

        // Servidor web en modo estación: panel de monitoreo en vivo
        configurarRutasWeb();
        Serial.println("[WEB] Monitoreo en vivo → http://" + WiFi.localIP().toString() + "/monitoreo");
        imprimirMenuAyuda();
        return; // Sale de setup() y entra a loop() en modo envío de datos
      } else {
        Serial.println("[AUTH] ✗ Token rechazado por el servidor. Abriendo portal cautivo...");
        // El token fue rechazado: borrar la config guardada y abrir portal
        preferences.begin("beestation", false);
        preferences.clear();
        preferences.end();
        WiFi.disconnect(true);
      }
    } else {
      Serial.println("\n[WiFi] ✗ No se pudo conectar. Código de fallo: " + String(WiFi.status()));
      Serial.println("[WiFi] Posibles causas:");
      Serial.println("       • SSID o contraseña incorrectos");
      Serial.println("       • El router está apagado o fuera de alcance");
      Serial.println("       • Demasiados dispositivos conectados al router");
    }
  } else {
    if (ssidGuardado == "") Serial.println("[WiFi] No hay red WiFi configurada.");
    if (tokenGuardado == "") Serial.println("[WiFi] No hay token de vinculación configurado.");
  }

  // ── 6. ACTIVACIÓN DEL PORTAL CAUTIVO ────────────────────────
  modoPortalCautivo = true;
  iniciarPortalCautivo();
  imprimirMenuAyuda();
}

// ══════════════════════════════════════════════════════════════
//                         L O O P
// ══════════════════════════════════════════════════════════════
void loop() {
  // ── Procesar comandos del Serial Monitor siempre ────────────
  procesarComandoSerial();

  // ── 1. RECOPILACIÓN (con o sin WiFi) ────────────────────────
  // Cada INTERVALO_LECTURA se leen los sensores, se registra una
  // fila en la microSD y se actualiza el panel. Este bloque es el
  // que hace que el nodo recoja datos TODO EL TIEMPO, incluso
  // sin conexión a la red.
  bool cicloNuevo = false;
  if (ultimaLecturaPanel == 0 ||
      millis() - ultimaLecturaPanel >= INTERVALO_LECTURA) {
    ultimaLecturaPanel = millis();
    cicloNuevo = true;
    leerSensores();
    guardarRespaldoSD(ultimaTemp, ultimaHum, ultimaPresion,
                      ultimoPeso, ultimoSonido, ultimaLuz);
    imprimirPanelSensores();
  }

  // ── 2. SERVIDOR WEB: siempre atiende peticiones ─────────────
  //   · modo AP  → portal de configuración en 192.168.4.1
  //   · modo STA → panel de monitoreo en vivo en http://<ip>/
  if (modoPortalCautivo) {
    dnsServer.processNextRequest();
  }
  server.handleClient();

  // ── 3. SIN CONEXIÓN: portal o reconexión, y salir ────────────
  // Ojo: la fila ya quedó guardada en la microSD en el paso 1,
  // así que perder la red no significa perder datos.
  if (modoPortalCautivo || WiFi.status() != WL_CONNECTED) {
    if (!modoPortalCautivo) {
      reconectarWiFi();
    }
    delay(20);
    return;
  }

  // ── 4. ENVÍO AL SERVIDOR (sólo con lectura nueva + WiFi) ────
  if (!cicloNuevo) {
    delay(20); // Pequeña pausa para no saturar el CPU
    return;
  }

  // Parpadeo del LED al iniciar el ciclo de envío
  digitalWrite(LED_STATUS, HIGH);

  // Usamos la última lectura: el registro en microSD ya ocurrió.
  float tempInterna  = ultimaTemp;
  float humRelativa  = ultimaHum;
  float presionAtm   = ultimaPresion;  // hPa
  float luzAmbiente  = ultimaLuz;
  float nivelSonido  = ultimoSonido;
  float pesoColmena  = ultimoPeso;

  // ── Construcción del JSON de telemetría ─────────────────────
  // CORRECCIÓN: El BH1750 mide luminosidad (luxes), NO CO2.
  // Se añade presión barométrica del BME280.
  String jsonPayload = "{\"token\":\"" + tokenGuardado + "\",\"lecturas\":[";
  jsonPayload += "{\"tipo\":\"temperatura_interna\",\"valor\":" + String(tempInterna, 2) + "},";
  jsonPayload += "{\"tipo\":\"humedad_relativa\",\"valor\":" + String(humRelativa, 2) + "},";
  jsonPayload += "{\"tipo\":\"presion\",\"valor\":" + String(presionAtm, 2) + "},";
  jsonPayload += "{\"tipo\":\"peso\",\"valor\":" + String(pesoColmena, 2) + "},";
  jsonPayload += "{\"tipo\":\"sonido\",\"valor\":" + String(nivelSonido, 2) + "},";
  jsonPayload += "{\"tipo\":\"luminosidad\",\"valor\":" + String(luzAmbiente, 2) + "}";
  jsonPayload += "]}";

  Serial.printf("[ENVÍO] → %s | Temp %.1f°C · Hum %.1f%% · Peso %.2f kg · Luz %.1f lux\n",
                hostGuardado.c_str(), tempInterna, humRelativa, pesoColmena, luzAmbiente);

  // ── RESOLUCIÓN DINÁMICA DE LA IP DEL SERVIDOR ───────────────
  if (hostGuardado == "") {
    Serial.println("[AUTO] No hay IP guardada. Iniciando autodescubrimiento...");
    descubrirServidor();
  }
  
  String ipServidor = hostGuardado;
  if (ipServidor == "") {
    // Si aún después de escanear no se encontró nada, usar gateway como último recurso
    ipServidor = WiFi.gatewayIP().toString();
    Serial.println("[AUTO] Escaneo fallido. Usando IP de puerta de enlace: " + ipServidor);
  }

  // Construimos la URL completa dinámica
  String serverUrl = "http://" + ipServidor + "/BeeStation_Sena/api/ingest.php";
  Serial.println("[HTTP] Destino: " + serverUrl);

  // ── Envío de Petición HTTP POST ─────────────────────────────
  HTTPClient http;
  http.begin(serverUrl);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-Key", apiKey);
  http.setTimeout(8000); // Timeout de 8 segundos

  int httpResponseCode = http.POST(jsonPayload);

  if (httpResponseCode > 0) {
    String response = http.getString();
    Serial.printf("[HTTP] Respuesta [%d]: %s\n", httpResponseCode, response.c_str());
    
    // Parpadeo de éxito: 2 destellos rápidos
    digitalWrite(LED_STATUS, LOW);
    delay(100);
    digitalWrite(LED_STATUS, HIGH);
    delay(100);
    digitalWrite(LED_STATUS, LOW);
    delay(100);
    digitalWrite(LED_STATUS, HIGH);
    delay(100);
    digitalWrite(LED_STATUS, LOW);

    // Marcar CUÁNDO se subió (hora local exacta) para el panel
    ultimaSubidaEpoch = time(nullptr);
    ultimaSubidaMs = millis();
    if (ultimaSubidaEpoch > 1000000) {
      Serial.printf("[ENV] ✓ Subido a las %s (hora local)\n",
                    horaLocal(ultimaSubidaEpoch).c_str());
    }

    // Reset del contador de reconexión tras envío exitoso
    intentosReconexionConsecutivos = 0;
  } else {
    Serial.printf("[HTTP] ✗ Error de transmisión: %s\n", http.errorToString(httpResponseCode).c_str());
    Serial.println("[HTTP] Verifica que Apache/XAMPP esté corriendo y que la IP sea correcta.");
    
    intentosReconexionConsecutivos++;
    
    // Si falla 2 veces consecutivas, podría ser porque la IP del servidor cambió por DHCP.
    // Lanzar un nuevo escaneo de red.
    if (intentosReconexionConsecutivos >= 2 && intentosReconexionConsecutivos < MAX_INTENTOS_ANTES_PORTAL) {
      Serial.println("[AUTO] Múltiples fallos detectados. Probable cambio de IP del servidor por DHCP.");
      if (descubrirServidor()) {
        // Si el descubrimiento encuentra una IP nueva, resetear el contador 
        // para darle tiempo a probar la nueva IP sin forzar el portal cautivo inmediatamente.
        intentosReconexionConsecutivos = 0; 
      }
    }
    
    // Parpadeo de error: 5 destellos muy rápidos
    for (int i = 0; i < 5; i++) {
      digitalWrite(LED_STATUS, HIGH);
      delay(50);
      digitalWrite(LED_STATUS, LOW);
      delay(50);
    }
  }
  http.end();

  // Apagar LED al terminar el ciclo
  digitalWrite(LED_STATUS, LOW);
}

// ══════════════════════════════════════════════════════════════
//            AUTODESCUBRIMIENTO DE SERVIDOR (SUBNET SCAN)
// ══════════════════════════════════════════════════════════════
bool descubrirServidor() {
  if (WiFi.status() != WL_CONNECTED) return false;
  
  IPAddress localIP = WiFi.localIP();
  Serial.println("\n[AUTO] Iniciando escaneo de subred para encontrar servidor BeeStation...");
  Serial.printf("[AUTO] IP Local: %s\n", localIP.toString().c_str());

  for (int i = 1; i <= 254; i++) {
    IPAddress targetIP = localIP;
    targetIP[3] = i;

    if (targetIP == localIP) continue; // No escanearse a sí mismo

    WiFiClient client;
    // Timeout ultra rápido (150ms) solo para ver si el puerto 80 responde
    if (client.connect(targetIP, 80, 150)) {
      // Puerto 80 abierto, probar si es nuestro servidor
      client.print(String("GET /BeeStation_Sena/api/ping.php HTTP/1.0\r\n") +
                   "Host: " + targetIP.toString() + "\r\n" +
                   "Connection: close\r\n\r\n");
      
      unsigned long timeout = millis();
      while (client.available() == 0) {
        if (millis() - timeout > 500) { break; }
        delay(1);
      }
      
      String response = "";
      while (client.available()) {
        response += (char)client.read();
      }
      client.stop();

      // Verificar si el JSON incluye nuestra app
      if (response.indexOf("\"app\":\"BeeStation\"") != -1 || response.indexOf("\"app\": \"BeeStation\"") != -1) {
        Serial.println("\n[AUTO] ¡Servidor BeeStation encontrado en " + targetIP.toString() + "!");
        
        // Guardar la nueva IP automáticamente en NVS
        hostGuardado = targetIP.toString();
        preferences.begin("beestation", false);
        preferences.putString("host", hostGuardado);
        preferences.end();
        Serial.println("[AUTO] Nueva IP guardada exitosamente. Autodescubrimiento completado.");
        return true;
      }
    }
    
    // Feedback visual (opcional) cada 20 IPs
    if (i % 20 == 0) Serial.print(".");
  }
  
  Serial.println("\n[AUTO] ✗ Escaneo completado. No se encontró el servidor.");
  return false;
}

// ══════════════════════════════════════════════════════════════
//            BANNER DE INICIO Y MENÚ SERIAL
// ══════════════════════════════════════════════════════════════

// ══════════════════════════════════════════════════════════════
//        LECTURA AUTOMÁTICA Y VISUALIZACIÓN DE SENSORES
// ══════════════════════════════════════════════════════════════

// Lee todos los sensores y actualiza el estado de diagnóstico.
// Se ejecuta con independencia de que haya WiFi o no.
void leerSensores() {
  ultimaTemp    = bmeActivo  ? bme.readTemperature() : 0.0;
  ultimaHum     = bmeActivo  ? bme.readHumidity()    : 0.0;
  ultimaPresion = bmeActivo  ? (bme.readPressure() / 100.0F) : 0.0;
  ultimaLuz     = bh1750Activo ? lightMeter.readLightLevel() : 0.0;
  ultimoSonido  = leerNivelAcustico(50); // muestreo de 50 ms del MAX4466

  ultimoPeso = 0.0;
  if (hx711Activo && scale.wait_ready_timeout(500)) {
    ultimoPeso = scale.get_units(3); // promedio de 3 lecturas
  }

  ultimaLecturaMs = millis();
}

// ── Helpers de formato para las cajas del Monitor Serie ───────
// Arduino String::length() cuenta BYTES y ✗/✓/° son UTF-8 de
// varios bytes, así que el ancho se calcula contando caracteres.
static int anchoTexto(const String &s) {
  int n = 0;
  for (unsigned i = 0; i < s.length(); ) {
    uint8_t c = (uint8_t)s[i];
    i += (c < 0x80) ? 1 : ((c & 0xE0) == 0xC0) ? 2 : ((c & 0xF0) == 0xE0) ? 3 : 4;
    n++;
  }
  return n;
}

static const int ANCHO_CAJA = 52;

// Rellena con espacios hasta que el texto mida ANCHO_CAJA caracteres.
static String padCaja(const String &s) {
  String r = s;
  int n = anchoTexto(r);
  while (n < ANCHO_CAJA) { r += ' '; n++; }
  return r;
}

// Línea completa: borde izquierdo + contenido + borde derecho.
// Los bordes van como const char* porque '╔' como char perdería
// sus 3 bytes UTF-8 y se imprimiría como '?'.
static String lineaCaja(const String &contenido, const char *izq, const char *der) {
  String r = izq;
  r += padCaja(contenido);
  r += der;
  return r;
}

// Panel de visualización de TODOS los datos leídos por los sensores.
// Se imprime automáticamente en el Monitor Serie cada INTERVALO_LECTURA.
void imprimirPanelSensores() {
  unsigned long seg = millis() / 1000;
  unsigned long hh  = seg / 3600;
  unsigned long mm  = (seg % 3600) / 60;
  unsigned long ss  = seg % 60;
  unsigned long antiguedad = (ultimaLecturaMs > 0) ? (millis() - ultimaLecturaMs) / 1000 : 0;

  String sep;
  for (int i = 0; i < ANCHO_CAJA; i++) sep += "═";

  char hdr[96], tmp[80], relojStr[24];
  struct tm reloj;
  if (getLocalTime(&reloj, 0)) {
    strftime(relojStr, sizeof(relojStr), "%H:%M:%S", &reloj);
  } else {
    snprintf(relojStr, sizeof(relojStr), "uptime %02lu:%02lu:%02lu", hh, mm, ss);
  }
  snprintf(hdr, sizeof(hdr), "  LECTURA EN VIVO  ·  %s  ·  hace %lus",
           relojStr, antiguedad);

  Serial.println();
  Serial.println(lineaCaja(sep, "╔", "╗"));
  Serial.println(lineaCaja(String(hdr), "║", "║"));
  Serial.println(lineaCaja(sep, "╠", "╣"));
  Serial.println(lineaCaja("  SENSOR        ESTADO         VALOR     UNIDAD", "║", "║"));
  Serial.println(lineaCaja(sep, "╠", "╣"));

  if (bmeActivo) {
    snprintf(tmp, sizeof(tmp), "  BME280 Temp   ✓ ACTIVO   %8.2f °C", ultimaTemp);
    Serial.println(lineaCaja(String(tmp), "║", "║"));
    snprintf(tmp, sizeof(tmp), "  BME280 Hum    ✓ ACTIVO   %8.2f %%", ultimaHum);
    Serial.println(lineaCaja(String(tmp), "║", "║"));
    snprintf(tmp, sizeof(tmp), "  BME280 Pres   ✓ ACTIVO   %8.2f hPa", ultimaPresion);
    Serial.println(lineaCaja(String(tmp), "║", "║"));
  } else {
    Serial.println(lineaCaja("  BME280        ✗ NO DETECTADO  Temp/Hum/Presión", "║", "║"));
  }

  if (bh1750Activo) {
    snprintf(tmp, sizeof(tmp), "  BH1750 Luz    ✓ ACTIVO   %8.1f lux", ultimaLuz);
    Serial.println(lineaCaja(String(tmp), "║", "║"));
  } else {
    Serial.println(lineaCaja("  BH1750        ✗ NO DETECTADO  (Luminosidad)", "║", "║"));
  }

  if (hx711Activo) {
    snprintf(tmp, sizeof(tmp), "  HX711 Peso    ✓ ACTIVO   %8.2f kg", ultimoPeso);
    Serial.println(lineaCaja(String(tmp), "║", "║"));
  } else {
    Serial.println(lineaCaja("  HX711         ✗ NO DETECTADO  (Peso de la colmena)", "║", "║"));
  }

  snprintf(tmp, sizeof(tmp), "  MAX4466 Son   ✓ ANALÓGICO %8.3f V (GPIO%d)",
           ultimoSonido, MIC_ANALOG);
  Serial.println(lineaCaja(String(tmp), "║", "║"));

  snprintf(tmp, sizeof(tmp), "  microSD       %s Respaldo local",
           sdActivo ? "✓ ACTIVA" : "✗ NO DET.");
  Serial.println(lineaCaja(String(tmp), "║", "║"));

  Serial.println(lineaCaja(sep, "╚", "╝"));

  // Estado del registro continuo en la microSD
  if (sdActivo) {
    Serial.println("[SD]  " + archivoSDActivo + "  ·  " + String(registrosSD) + " registros");
  } else {
    Serial.println("[SD]  ✗ Sin tarjeta microSD — no se registra localmente");
  }

  if (ultimaSubidaMs > 0) {
    unsigned long haceS = (millis() - ultimaSubidaMs) / 1000;
    Serial.printf("[ENV] Última subida %s  ·  hace %lus\n",
                  horaLocal(ultimaSubidaEpoch).c_str(), haceS);
  } else {
    Serial.println("[ENV] Aún no se ha subido ningún dato al servidor");
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.printf("[RED] Conectado a '%s' · IP %s · %s\n",
                  WiFi.SSID().c_str(),
                  WiFi.localIP().toString().c_str(),
                  modoPortalCautivo ? "PORTAL" : "ESTACIÓN");
    Serial.printf("[WEB] Monitoreo en vivo → http://%s/monitoreo\n",
                  WiFi.localIP().toString().c_str());
  } else if (modoPortalCautivo) {
    Serial.println("[RED] Modo PORTAL CAUTIVO · conecta a 'BeeStation_Config' → http://192.168.4.1");
  } else {
    Serial.println("[RED] Sin conexión WiFi — leyendo sensores igualmente");
  }
}

void imprimirBanner() {
  Serial.println();
  Serial.println("╔══════════════════════════════════════════════════════╗");
  Serial.println("║   ____            ____  _        _   _              ║");
  Serial.println("║  | __ )  ___  ___/ ___|| |_ __ _| |_(_) ___  _ __  ║");
  Serial.println("║  |  _ \\ / _ \\/ _ \\___ \\| __/ _` | __| |/ _ \\| '_ \\ ║");
  Serial.println("║  | |_) |  __/  __/___) | || (_| | |_| | (_) | | | |║");
  Serial.println("║  |____/ \\___|\\___|____/ \\__\\__,_|\\__|_|\\___/|_| |_|║");
  Serial.println("║                                                      ║");
  Serial.printf( "║  Firmware v%s (%s)                       ║\n", FIRMWARE_VERSION, FIRMWARE_DATE);
  Serial.println("║  SENA Centro Minero Ambiental — El Bagre, Antioquia ║");
  Serial.println("╚══════════════════════════════════════════════════════╝\n");

  // Información del sistema
  Serial.printf("[SYS] Chip     : %s Rev.%d\n", ESP.getChipModel(), ESP.getChipRevision());
  Serial.printf("[SYS] CPU      : %d MHz, %d core(s)\n", ESP.getCpuFreqMHz(), ESP.getChipCores());
  Serial.printf("[SYS] Flash    : %d KB\n", ESP.getFlashChipSize() / 1024);
  Serial.printf("[SYS] Heap Free: %d bytes\n", ESP.getFreeHeap());
  Serial.printf("[SYS] MAC WiFi : %s\n", WiFi.macAddress().c_str());
  Serial.println();
}

void imprimirMenuAyuda() {
  Serial.println();
  Serial.println("┌─── Comandos Serial (escribe y presiona Enter) ──────┐");
  Serial.println("│  status  → Estado actual de sensores y red           │");
  Serial.println("│  config  → Mostrar configuración guardada (NVS)      │");
  Serial.println("│  host    → Ver/fijar la IP del servidor (NVS)        │");
  Serial.println("│  datos   → Ver los datos guardados en la microSD     │");
  Serial.println("│  scan    → Escanear redes WiFi disponibles           │");
  Serial.println("│  test    → Enviar POST de prueba al servidor         │");
  Serial.println("│  reset   → Borrar configuración y reiniciar          │");
  Serial.println("│  help    → Mostrar este menú de ayuda                │");
  Serial.println("└─────────────────────────────────────────────────────┘");
  Serial.println();
  String periodo = (INTERVALO_LECTURA >= 60000)
      ? String(INTERVALO_LECTURA / 60000) + " minutos"
      : String(INTERVALO_LECTURA / 1000) + " segundos";
  Serial.println("[AUTOMÁTICO] Los sensores se leen cada " + periodo + ".");
  Serial.println("             Se guardan SIEMPRE en la microSD (con o sin WiFi)");
  Serial.println("             y, sólo si hay red, se suben al servidor.");
  Serial.println("             Verlos: escribe 'datos' o abre http://<ip>/monitoreo");
  Serial.println();
}

// ══════════════════════════════════════════════════════════════
//            PROCESAMIENTO DE COMANDOS SERIAL
// ══════════════════════════════════════════════════════════════

// Comando serial: ver o cambiar la IP del servidor guardada en NVS
//   host                → muestra la IP actual
//   host 172.30.0.170   → fija una IP concreta
//   host auto           → borra la IP (autodescubrimiento por subred)
void comandoHost(String arg) {
  preferences.begin("beestation", true);
  String actual = preferences.getString("host", "");
  preferences.end();

  if (arg.length() == 0) {
    Serial.println("── IP del servidor ──");
    Serial.printf("  Actual : %s\n",
      actual.length() > 0 ? actual.c_str() : "(auto-detect por subred)");
    Serial.println("  Uso    : host <ip>   → fija la IP del servidor");
    Serial.println("           host auto   → borra la IP (autodescubrimiento)");
    Serial.println("  Ejemplo: host 172.30.0.170");
    Serial.println();
    return;
  }

  bool esAuto = (arg == "auto" || arg == "clear");

  if (!esAuto) {
    IPAddress ip;
    if (!ip.fromString(arg) || ip[0] == 0) {
      Serial.printf("[HOST] ✗ '%s' no es una IP válida.\n", arg.c_str());
      Serial.println("[HOST]   Ejemplo correcto: host 172.30.0.170");
      Serial.println();
      return;
    }
  }

  preferences.begin("beestation", false);
  preferences.putString("host", esAuto ? "" : arg);
  preferences.end();

  hostGuardado = esAuto ? "" : arg;

  if (esAuto) {
    Serial.println("[HOST] ✓ IP borrada. El nodo autodescubrirá el servidor por subred.");
  } else {
    Serial.printf("[HOST] ✓ Servidor fijado en %s\n", hostGuardado.c_str());
    Serial.printf("[HOST]   Destino: http://%s/BeeStation_Sena/api/ingest.php\n",
                  hostGuardado.c_str());
  }
  Serial.println();
}

// Comando serial: muestra los datos YA guardados en la microSD
//   datos        → últimas 20 filas del CSV del día
//   datos 50     → últimas 50 filas
void comandoDatos(String arg) {
  int n = arg.toInt();
  if (n <= 0 || n > 200) n = 20;

  String ruta;
  int total = 0;
  String filas = leerUltimasFilasSD(n, ruta, total);

  Serial.println("\u2500\u2500 Datos guardados en la microSD \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500");
  if (ruta.length() == 0) {
    Serial.println("  \u2717 No hay ficheros de registro en la tarjeta.");
    Serial.println();
    return;
  }
  Serial.printf("  Fichero : %s\n", ruta.c_str());
  Serial.printf("  Filas   : %d (mostrando hasta %d)\n", total, n);
  if (filas.length() == 0) {
    Serial.println("  (fichero creado, a\u00fan sin datos)");
    Serial.println();
    return;
  }

  Serial.println();
  Serial.printf("  %-20s %9s %8s %6s %6s %6s %7s %6s\n",
                "Fecha_hora", "Millis", "Temp", "Hum", "Pres", "Peso", "Sonido", "Luz");

  unsigned int inicio = 0;
  while (inicio <= filas.length()) {
    int fin = filas.indexOf('\n', inicio);
    if (fin < 0) fin = filas.length();
    String l = filas.substring(inicio, fin);
    if (l.length() > 0) {
      char fh[24]; unsigned long ms = 0;
      float t = 0, h = 0, pr = 0, pz = 0, sn = 0, lz = 0;
      if (sscanf(l.c_str(), "%23[^,],%lu,%f,%f,%f,%f,%f,%f",
                 fh, &ms, &t, &h, &pr, &pz, &sn, &lz) == 8) {
        Serial.printf("  %-20s %9lu %8.2f %6.2f %6.2f %6.2f %7.3f %6.2f\n",
                      fh, ms, t, h, pr, pz, sn, lz);
      } else {
        Serial.println("  " + l);
      }
    }
    if ((unsigned int)fin >= filas.length()) break;
    inicio = fin + 1;
  }
  Serial.println();
}

void procesarComandoSerial() {
  if (!Serial.available()) return;

  String cmd = Serial.readStringUntil('\n');
  cmd.trim();
  cmd.toLowerCase();

  if (cmd == "") return;

  Serial.println(); // Línea en blanco antes de la respuesta

  if (cmd == "status") {
    comandoStatus();
  } else if (cmd == "config") {
    comandoConfig();
  } else if (cmd.startsWith("host")) {
    String arg = cmd.substring(4);
    arg.trim();
    comandoHost(arg);
  } else if (cmd.startsWith("datos")) {
    String arg = cmd.substring(5);
    arg.trim();
    comandoDatos(arg);
  } else if (cmd == "scan") {
    comandoScan();
  } else if (cmd == "test") {
    comandoTest();
  } else if (cmd == "reset") {
    comandoReset();
  } else if (cmd == "help" || cmd == "?") {
    imprimirMenuAyuda();
  } else {
    Serial.printf("[CMD] Comando '%s' no reconocido. Escribe 'help' para ver opciones.\n", cmd.c_str());
  }
}

void comandoStatus() {
  Serial.println("╔══════════════════════════════════════════════╗");
  Serial.println("║          ESTADO DEL SISTEMA                 ║");
  Serial.println("╚══════════════════════════════════════════════╝\n");

  // Estado de sensores
  Serial.println("── Sensores ──");
  Serial.printf("  BME280  (Temp/Hum/Pres) : %s\n", bmeActivo ? "✓ ACTIVO" : "✗ NO DETECTADO");
  Serial.printf("  BH1750  (Luminosidad)   : %s\n", bh1750Activo ? "✓ ACTIVO" : "✗ NO DETECTADO");
  Serial.printf("  HX711   (Peso)          : %s\n", hx711Activo ? "✓ ACTIVO" : "✗ NO DETECTADO");
  Serial.printf("  MAX4466 (Micrófono)     : ✓ ANALÓGICO (GPIO%d)\n", MIC_ANALOG);
  Serial.printf("  microSD (Datalogger)    : %s\n", sdActivo ? "✓ ACTIVO" : "✗ NO DETECTADA");

  // Últimas lecturas
  if (ultimaLecturaMs > 0) {
    unsigned long hace = (millis() - ultimaLecturaMs) / 1000;
    Serial.printf("\n── Últimas Lecturas (hace %lu s) ──\n", hace);
    Serial.printf("  Temperatura : %.1f °C\n", ultimaTemp);
    Serial.printf("  Humedad     : %.1f %%\n", ultimaHum);
    Serial.printf("  Presión     : %.1f hPa\n", ultimaPresion);
    Serial.printf("  Peso        : %.2f\n", ultimoPeso);
    Serial.printf("  Luz         : %.1f lux\n", ultimaLuz);
    Serial.printf("  Sonido      : %.3f V\n", ultimoSonido);
  }

  // Lecturas en tiempo real
  Serial.println("\n── Lectura en Tiempo Real ──");
  if (bmeActivo) {
    Serial.printf("  BME280  → T=%.1f°C  H=%.1f%%  P=%.1fhPa\n", 
      bme.readTemperature(), bme.readHumidity(), bme.readPressure() / 100.0F);
  }
  if (bh1750Activo) {
    Serial.printf("  BH1750  → %.1f lux\n", lightMeter.readLightLevel());
  }
  Serial.printf("  MAX4466 → ADC raw=%d\n", analogRead(MIC_ANALOG));

  // Estado de red
  Serial.println("\n── Red ──");
  Serial.printf("  Modo           : %s\n", modoPortalCautivo ? "PORTAL CAUTIVO (AP)" : "ESTACIÓN (STA)");
  if (WiFi.status() == WL_CONNECTED) {
    Serial.printf("  WiFi           : ✓ Conectado a '%s'\n", WiFi.SSID().c_str());
    Serial.printf("  IP Local       : %s\n", WiFi.localIP().toString().c_str());
    Serial.printf("  Gateway        : %s\n", WiFi.gatewayIP().toString().c_str());
    Serial.printf("  RSSI (señal)   : %d dBm\n", WiFi.RSSI());
    Serial.printf("  MAC            : %s\n", WiFi.macAddress().c_str());
  } else if (modoPortalCautivo) {
    Serial.printf("  AP SSID        : BeeStation_Config\n");
    Serial.printf("  AP IP          : %s\n", apIP.toString().c_str());
  } else {
    Serial.println("  WiFi           : ✗ Desconectado");
  }

  // Estado del sistema
  Serial.printf("\n── Sistema ──\n");
  Serial.printf("  Heap libre     : %d bytes\n", ESP.getFreeHeap());
  Serial.printf("  Uptime         : %lu s\n", millis() / 1000);
  Serial.printf("  Intentos recon.: %d/%d\n", intentosReconexionConsecutivos, MAX_INTENTOS_ANTES_PORTAL);
  Serial.println();
}

void comandoConfig() {
  Serial.println("── Configuración Guardada (NVS) ──");
  preferences.begin("beestation", true);
  String s = preferences.getString("ssid", "(vacío)");
  String p = preferences.getString("password", "");
  String t = preferences.getString("token", "(vacío)");
  String h = preferences.getString("host", "(auto)");
  preferences.end();

  Serial.printf("  SSID       : %s\n", s.c_str());
  Serial.printf("  Contraseña : %s\n", p.length() > 0 ? "••••••••" : "(vacía)");
  Serial.printf("  Token      : %s\n", t.c_str());
  Serial.printf("  Host       : %s\n", h.length() > 0 ? h.c_str() : "(auto-detect vía gateway)");
  Serial.printf("  API Key    : %s...%s\n", 
    String(apiKey).substring(0, 6).c_str(), 
    String(apiKey).substring(max(0, (int)strlen(apiKey) - 4)).c_str());
  Serial.println();
}

void comandoScan() {
  Serial.println("[WiFi] Escaneando redes disponibles...");
  int n = WiFi.scanNetworks();
  if (n == 0) {
    Serial.println("[WiFi] No se encontraron redes WiFi.");
  } else {
    Serial.printf("[WiFi] %d redes encontradas:\n\n", n);
    Serial.println("  #  │ SSID                        │ RSSI  │ Canal │ Seguridad");
    Serial.println("─────┼─────────────────────────────┼───────┼───────┼──────────");
    for (int i = 0; i < n; i++) {
      String seguridad;
      switch (WiFi.encryptionType(i)) {
        case WIFI_AUTH_OPEN:            seguridad = "Abierta";  break;
        case WIFI_AUTH_WEP:             seguridad = "WEP";      break;
        case WIFI_AUTH_WPA_PSK:         seguridad = "WPA";      break;
        case WIFI_AUTH_WPA2_PSK:        seguridad = "WPA2";     break;
        case WIFI_AUTH_WPA_WPA2_PSK:    seguridad = "WPA/WPA2"; break;
        default:                        seguridad = "Otra";     break;
      }
      Serial.printf("  %2d │ %-27s │ %4d  │   %2d  │ %s\n",
        i + 1, WiFi.SSID(i).c_str(), WiFi.RSSI(i), WiFi.channel(i), seguridad.c_str());
    }
  }
  WiFi.scanDelete();
  Serial.println();
}

void comandoTest() {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[TEST] ✗ No hay conexión WiFi activa. Conecta primero a una red.");
    return;
  }

  String ipServidor = hostGuardado;
  if (ipServidor == "") {
    ipServidor = WiFi.gatewayIP().toString();
  }

  // Test 1: Ping básico (HTTP GET a la raíz)
  Serial.println("[TEST] 1/3 — Verificando acceso al servidor...");
  String urlBase = "http://" + ipServidor + "/BeeStation_Sena/";
  HTTPClient http;
  http.begin(urlBase);
  http.setTimeout(5000);
  int code = http.GET();
  if (code > 0) {
    Serial.printf("[TEST]   ✓ Servidor accesible en %s [HTTP %d]\n", urlBase.c_str(), code);
  } else {
    Serial.printf("[TEST]   ✗ Servidor NO accesible: %s\n", http.errorToString(code).c_str());
    Serial.println("[TEST]   Verifica que Apache/XAMPP esté ejecutándose.");
    Serial.printf("[TEST]   IP probada: %s\n", ipServidor.c_str());
    http.end();
    return;
  }
  http.end();

  // Test 2: Validar API Key
  Serial.println("[TEST] 2/3 — Validando API Key...");
  String urlValidate = "http://" + ipServidor + "/BeeStation_Sena/api/validate_token.php";
  http.begin(urlValidate);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-Key", apiKey);
  http.setTimeout(5000);

  String tokenPayload = "{\"token\":\"" + tokenGuardado + "\"}";
  code = http.POST(tokenPayload);
  if (code == 200) {
    String resp = http.getString();
    Serial.printf("[TEST]   ✓ Token validado [%d]: %s\n", code, resp.c_str());
  } else if (code == 401) {
    Serial.println("[TEST]   ✗ API Key rechazada. Verifica que coincida con config/db.php.");
  } else if (code == 404) {
    Serial.println("[TEST]   ✗ Token no encontrado en la base de datos.");
  } else {
    Serial.printf("[TEST]   ✗ Error inesperado [%d]: %s\n", code, http.errorToString(code).c_str());
  }
  http.end();

  // Test 3: Enviar datos de prueba
  Serial.println("[TEST] 3/3 — Enviando telemetría de prueba...");
  String urlIngest = "http://" + ipServidor + "/BeeStation_Sena/api/ingest.php";
  http.begin(urlIngest);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-Key", apiKey);
  http.setTimeout(8000);

  // Leer sensores reales para el test
  float t = bmeActivo ? bme.readTemperature() : 25.0;
  float h = bmeActivo ? bme.readHumidity() : 50.0;
  float p = bmeActivo ? (bme.readPressure() / 100.0F) : 1013.25;
  float l = bh1750Activo ? lightMeter.readLightLevel() : 100.0;
  float s = leerNivelAcustico(50);

  String testPayload = "{\"token\":\"" + tokenGuardado + "\",\"lecturas\":[";
  testPayload += "{\"tipo\":\"temperatura_interna\",\"valor\":" + String(t, 2) + "},";
  testPayload += "{\"tipo\":\"humedad_relativa\",\"valor\":" + String(h, 2) + "},";
  testPayload += "{\"tipo\":\"presion\",\"valor\":" + String(p, 2) + "},";
  testPayload += "{\"tipo\":\"peso\",\"valor\":0.00},";
  testPayload += "{\"tipo\":\"sonido\",\"valor\":" + String(s, 2) + "},";
  testPayload += "{\"tipo\":\"luminosidad\",\"valor\":" + String(l, 2) + "}";
  testPayload += "]}";

  Serial.println("[TEST]   Payload: " + testPayload);
  code = http.POST(testPayload);
  if (code > 0) {
    String resp = http.getString();
    Serial.printf("[TEST]   Respuesta [%d]: %s\n", code, resp.c_str());
  } else {
    Serial.printf("[TEST]   ✗ Fallo de envío: %s\n", http.errorToString(code).c_str());
  }
  http.end();
  Serial.println("[TEST] Diagnóstico completo.\n");
}

void comandoReset() {
  Serial.println("[RESET] ⚠ BORRANDO toda la configuración almacenada...");
  preferences.begin("beestation", false);
  preferences.clear();
  preferences.end();
  Serial.println("[RESET] Memoria NVS limpia. Reiniciando en 2 segundos...");
  delay(2000);
  ESP.restart();
}

// ══════════════════════════════════════════════════════════════
//       VALIDACIÓN PREVIA DEL TOKEN CON EL SERVIDOR
// ══════════════════════════════════════════════════════════════
// Envía un POST a /api/validate_token.php antes de iniciar el envío
// de datos. Si el servidor rechaza el token, retorna false para que
// setup() active el portal cautivo y permita reconfigurar.
bool validarTokenConServidor() {
  String ipServidor = hostGuardado;
  if (ipServidor == "") {
    ipServidor = WiFi.gatewayIP().toString();
  }

  String url = "http://" + ipServidor + "/BeeStation_Sena/api/validate_token.php";
  Serial.println("[AUTH] Validando token con: " + url);

  HTTPClient http;
  http.begin(url);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-Key", apiKey);
  http.setTimeout(8000);

  String payload = "{\"token\":\"" + tokenGuardado + "\"}";
  int code = http.POST(payload);

  bool valido = false;
  if (code == 200) {
    String resp = http.getString();
    Serial.println("[AUTH] Respuesta: " + resp);
    valido = resp.indexOf("\"ok\":true") >= 0;
  } else {
    // Si no hay red o el servidor no responde, asumimos token válido
    // para permitir operación offline con respaldo en SD
    Serial.printf("[AUTH] Servidor no accesible [%d]. Continuando en modo offline.\n", code);
    valido = true;
  }
  http.end();
  return valido;
}

// ══════════════════════════════════════════════════════════════
//          RECONEXIÓN AUTOMÁTICA DE WI-FI
// ══════════════════════════════════════════════════════════════
// Si la conexión WiFi se pierde durante la operación normal,
// intenta reconectar con backoff exponencial. Si se agotan los
// intentos, activa el portal cautivo.
void reconectarWiFi() {
  // Backoff exponencial: esperar más tiempo entre cada intento
  // tope 8 s (antes llegaba a 64 s): el nodo vuelve a la red antes
  unsigned long intervaloBackoff = (unsigned long)(1000 * pow(2, min(intentosReconexionConsecutivos, 3)));
  unsigned long ahora = millis();

  if (ahora - ultimoIntentoReconexion < intervaloBackoff) {
    delay(100);
    return;
  }
  ultimoIntentoReconexion = ahora;
  intentosReconexionConsecutivos++;

  Serial.printf("[WiFi] Reconexión intento %d/%d (espera: %lums)...\n", 
    intentosReconexionConsecutivos, MAX_INTENTOS_ANTES_PORTAL, intervaloBackoff);

  // Parpadeo lento de reconexión
  digitalWrite(LED_STATUS, HIGH);
  delay(200);
  digitalWrite(LED_STATUS, LOW);

  WiFi.disconnect();
  delay(100);
  WiFi.begin(ssidGuardado.c_str(), passGuardado.c_str());

  int intentos = 0;
  while (WiFi.status() != WL_CONNECTED && intentos < 20) {
    delay(250);
    Serial.print(".");
    digitalWrite(LED_STATUS, intentos % 2 == 0 ? HIGH : LOW);
    intentos++;
  }
  digitalWrite(LED_STATUS, LOW);

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\n[WiFi] ✓ ¡Reconexión exitosa!");
    Serial.printf("[WiFi] IP: %s  RSSI: %d dBm\n", WiFi.localIP().toString().c_str(), WiFi.RSSI());
    intentosReconexionConsecutivos = 0;

    // Re-iniciar mDNS
    MDNS.begin("beestation");
  } else {
    Serial.println();
    if (intentosReconexionConsecutivos >= MAX_INTENTOS_ANTES_PORTAL) {
      Serial.println("[WiFi] ✗ Máximo de intentos alcanzado. Activando Portal Cautivo...");
      modoPortalCautivo = true;
      intentosReconexionConsecutivos = 0;
      iniciarPortalCautivo();
    } else {
      Serial.printf("[WiFi] ✗ Fallo. Próximo intento en %.1f segundos.\n", 
        (1000 * pow(2, min(intentosReconexionConsecutivos, 3))) / 1000.0);
    }
  }
}

// ══════════════════════════════════════════════════════════════
//        CÁLCULO ACÚSTICO DE ENJAMBRAZÓN (MAX4466)
// ══════════════════════════════════════════════════════════════
float leerNivelAcustico(unsigned int milliseconds) {
  unsigned long startMillis = millis();
  unsigned int signalMax = 0;
  unsigned int signalMin = 4095; // Resolución de 12 bits

  // Muestreo continuo durante el periodo especificado
  while (millis() - startMillis < milliseconds) {
    int sample = analogRead(MIC_ANALOG);
    if (sample < 4095) { // Descartar lecturas saturadas
      if (sample > (int)signalMax) signalMax = sample;
      if (sample < (int)signalMin) signalMin = sample;
    }
  }

  unsigned int peakToPeak = signalMax - signalMin;
  float volts = (peakToPeak * 3.3) / 4095.0;
  return volts;
}

// ══════════════════════════════════════════════════════════════
//       SISTEMA DE RESPALDO EN MICROSD (DATALOGGER)
// ══════════════════════════════════════════════════════════════
// ── Registro continuo en microSD ───────────────────────────────
// Se llama en CADA ciclo de recopilación (cada 5 minutos), con o
// sin WiFi. Un fichero por día (/log_AAAA-MM-DD.csv) y columna de
// fecha real vía NTP; si aún no hay hora, /log_sin_hora.csv.
void guardarRespaldoSD(float temp, float hum, float pres, float peso, float s, float l) {
  if (!sdActivo) return;

  // Hora local (America/Bogota) si el NTP ya se sincronizó
  struct tm reloj;
  bool hayHora = getLocalTime(&reloj, 0);
  char fecha[24] = "sin_hora";
  char nombreFichero[32] = "/log_sin_hora.csv";

  if (hayHora) {
    strftime(fecha, sizeof(fecha), "%Y-%m-%d %H:%M:%S", &reloj);
    char dia[16];
    strftime(dia, sizeof(dia), "%Y-%m-%d", &reloj);
    snprintf(nombreFichero, sizeof(nombreFichero), "/log_%s.csv", dia);
  }

  File dataFile = SD.open(nombreFichero, FILE_WRITE);
  if (!dataFile) {
    Serial.println("[SD] ✗ Error de acceso a tarjeta.");
    return;
  }

  bool ficheroNuevo = (dataFile.size() == 0);
  if (ficheroNuevo) {
    dataFile.println("Fecha_hora,Millis,Temp,Hum,Presion,Peso,Sonido,Luz");
    if (archivoSDActivo != nombreFichero) {
      archivoSDActivo = nombreFichero;
      registrosSD = 0;
      Serial.println("[SD] ▶ Nuevo fichero: " + String(nombreFichero));
    }
  }

  dataFile.printf("%s,%lu,%.2f,%.2f,%.2f,%.2f,%.3f,%.2f\n",
                  fecha, millis(), temp, hum, pres, peso, s, l);
  dataFile.close();
  registrosSD++;
}

// ── Consulta de los datos YA guardados en la microSD ──────────
// Ruta del CSV del día en curso (con respaldo si no hay hora).
String rutaCSVActual() {
  struct tm reloj;
  if (getLocalTime(&reloj, 0)) {
    char dia[16];
    strftime(dia, sizeof(dia), "%Y-%m-%d", &reloj);
    String r = String("/log_") + dia + ".csv";
    if (SD.exists(r)) return r;
  }
  if (archivoSDActivo.length() > 0 && SD.exists(archivoSDActivo)) return archivoSDActivo;
  if (SD.exists("/log_sin_hora.csv")) return "/log_sin_hora.csv";
  return "";
}

// Devuelve las últimas 'n' filas de datos (sin la cabecera),
// separadas por '\n'. 'ruta' y 'totalFilas' salen rellenos.
// Se usa un anillo en memoria para no cargar el fichero entero.
String leerUltimasFilasSD(int n, String &ruta, int &totalFilas) {
  ruta = rutaCSVActual();
  totalFilas = 0;
  if (ruta.length() == 0) return "";

  File f = SD.open(ruta, FILE_READ);
  if (!f) return "";

  String *anillo = new String[n];
  if (anillo == NULL) { f.close(); return ""; }

  int idx = 0, llenas = 0, lineaNum = 0;
  while (f.available()) {
    String linea = f.readStringUntil('\n');
    linea.trim();
    if (linea.length() == 0) continue;
    lineaNum++;
    if (lineaNum == 1) continue;            // ignora la cabecera
    totalFilas++;
    anillo[idx] = linea;
    idx = (idx + 1) % n;
    if (llenas < n) llenas++;
  }
  f.close();

  String out = "";
  int inicio = (llenas == n) ? idx : 0;    // lleno -> idx apunta a la mas vieja
  for (int i = 0; i < llenas; i++) {
    if (i > 0) out += "\n";
    out += anillo[(inicio + i) % n];
  }
  delete[] anillo;
  return out;
}

// ══════════════════════════════════════════════════════════════
//            RUTINAS DEL PORTAL CAUTIVO
// ══════════════════════════════════════════════════════════════
// ══════════════════════════════════════════════════════════════
//   SERVIDOR WEB COMPARTIDO (PORTAL CAUTIVO + MONITOREO EN VIVO)
// ══════════════════════════════════════════════════════════════

// Registra todas las rutas y arranca el servidor UNA sola vez.
// Funciona igual en modo AP (portal de configuración) y en modo
// STA (panel de monitoreo en vivo servido por el propio ESP32).
void configurarRutasWeb() {
  if (rutasWebConfiguradas) return;
  rutasWebConfiguradas = true;

  // "/" → portal de configuración en AP, panel de monitoreo en STA
  server.on("/", HTTP_GET, []() {
    if (modoPortalCautivo) atenderPortal();
    else                   atenderMonitoreo();
  });

  server.on("/monitoreo", HTTP_GET, atenderMonitoreo);
  server.on("/status",    HTTP_GET, atenderStatusJSON);
  server.on("/datos",     HTTP_GET, atenderDatosSD);
  server.on("/save",      HTTP_POST, procesarGuardado);

  server.onNotFound([]() {
    server.sendHeader("Location", modoPortalCautivo ? "http://192.168.4.1/" : "/monitoreo", true);
    server.send(302, "text/plain", "");
  });

  server.begin();
  Serial.println("[WEB] Servidor web iniciado en el puerto 80.");
}

// Página de monitoreo en vivo (modo estación)
void atenderMonitoreo() {
  server.send(200, "text/html; charset=utf-8", String(MONITOREO_HTML));
}

void iniciarPortalCautivo() {
  Serial.println("\n[AP] Levantando señal inalámbrica 'BeeStation_Config'...");
  WiFi.mode(WIFI_AP);
  WiFi.softAPConfig(apIP, apIP, IPAddress(255, 255, 255, 0));
  WiFi.softAP("BeeStation_Config");

  dnsServer.start(DNS_PORT, "*", apIP);

  // Rutas y servidor web (registrados una sola vez, ver configurarRutasWeb)
  configurarRutasWeb();
  Serial.println("[AP] Servidor Web del Portal Cautivo listo.");
  Serial.println("[AP] ─────────────────────────────────────────────");
  Serial.println("[AP]   Conéctate al WiFi 'BeeStation_Config'");
  Serial.println("[AP]   y abre http://192.168.4.1 en tu navegador");
  Serial.println("[AP] ─────────────────────────────────────────────");

  // LED fijo encendido mientras está en modo Portal Cautivo
  // para indicar visualmente que espera configuración
  digitalWrite(LED_STATUS, HIGH);
}

void atenderPortal() {
  int n = WiFi.scanNetworks();
  String opcionesWifi = "";
  if (n == 0) {
    opcionesWifi = "<option value='' disabled>No se encontraron redes WiFi</option>";
  } else {
    for (int i = 0; i < n; ++i) {
      opcionesWifi += "<option value='" + WiFi.SSID(i) + "'>" + WiFi.SSID(i) + " (" + String(WiFi.RSSI(i)) + " dBm)</option>";
    }
  }

  // Construir indicadores de sensores para inyectar en el HTML
  String sensorStatus = "";
  sensorStatus += generarIndicadorSensor("BME280", "Temp / Hum / Presión", bmeActivo);
  sensorStatus += generarIndicadorSensor("BH1750", "Luminosidad (Luxes)", bh1750Activo);
  sensorStatus += generarIndicadorSensor("HX711", "Peso (Celda de Carga)", hx711Activo);
  sensorStatus += generarIndicadorSensor("MAX4466", "Micrófono (Sonido)", true);
  sensorStatus += generarIndicadorSensor("microSD", "Datalogger de Respaldo", sdActivo);

  String htmlFinal = String(PORTAL_HTML);
  htmlFinal.replace("%WIFI_NETWORKS%", opcionesWifi);
  htmlFinal.replace("%SENSOR_STATUS%", sensorStatus);
  htmlFinal.replace("%FIRMWARE_VERSION%", FIRMWARE_VERSION);
  htmlFinal.replace("%MAC_ADDRESS%", WiFi.macAddress());
  server.send(200, "text/html; charset=utf-8", htmlFinal);
}

// Genera una fila HTML de estado de sensor para el portal cautivo
String generarIndicadorSensor(const char* nombre, const char* descripcion, bool activo) {
  String color = activo ? "#22c55e" : "#ef4444";
  String icono = activo ? "✓" : "✗";
  String estado = activo ? "Detectado" : "No detectado";
  
  String html = "<div style='display:flex;align-items:center;gap:10px;padding:8px 12px;";
  html += "background:rgba(255,255,255,0.04);border-radius:10px;margin-bottom:6px;'>";
  html += "<span style='color:" + color + ";font-weight:700;font-size:1.1rem;'>" + String(icono) + "</span>";
  html += "<div><strong style='font-size:0.85rem;'>" + String(nombre) + "</strong>";
  html += "<br><span style='font-size:0.72rem;color:var(--color-text-secondary);'>" + String(descripcion) + " — " + estado + "</span>";
  html += "</div></div>";
  return html;
}

// Endpoint JSON con los datos YA guardados en la microSD.
// Devuelve las últimas filas del CSV del día en curso para que
// la página de monitoreo pueda mostrarlas al abrirse.
// GET /datos?n=50
void atenderDatosSD() {
  int n = server.hasArg("n") ? server.arg("n").toInt() : 50;
  if (n <= 0 || n > 200) n = 50;

  String ruta;
  int total = 0;
  String filas = leerUltimasFilasSD(n, ruta, total);

  int mostradas = 0;
  String json = "{";
  json += "\"fichero\":\"" + ruta + "\",";
  json += "\"total\":" + String(total) + ",";
  json += "\"filas\":[";

  unsigned int inicio = 0;
  while (inicio <= filas.length()) {
    int fin = filas.indexOf('\n', inicio);
    if (fin < 0) fin = filas.length();
    String l = filas.substring(inicio, fin);
    if (l.length() > 0) {
      if (mostradas > 0) json += ",";
      json += "\"" + l + "\"";
      mostradas++;
    }
    if ((unsigned int)fin >= filas.length()) break;
    inicio = fin + 1;
  }

  json += "],\"mostradas\":" + String(mostradas) + "}";
  server.send(200, "application/json", json);
}

// Endpoint JSON para diagnóstico desde el navegador y para la
// página de monitoreo en vivo (/monitoreo).
// Usa las lecturas cacheadas por leerSensores() para que la
// respuesta nunca bloquee el loop (el HX711 puede tardar 500 ms).
void atenderStatusJSON() {
  String json = "{";
  json += "\"firmware\":\"" + String(FIRMWARE_VERSION) + "\",";
  json += "\"mac\":\"" + WiFi.macAddress() + "\",";
  json += "\"heap\":" + String(ESP.getFreeHeap()) + ",";
  json += "\"uptime\":" + String(millis() / 1000) + ",";
  json += "\"lectura_edad_s\":" + String((ultimaLecturaMs > 0) ? (millis() - ultimaLecturaMs) / 1000 : -1) + ",";

  json += "\"sensores\":{";
  json += "\"bme280\":" + String(bmeActivo ? "true" : "false") + ",";
  json += "\"bh1750\":" + String(bh1750Activo ? "true" : "false") + ",";
  json += "\"hx711\":" + String(hx711Activo ? "true" : "false") + ",";
  json += "\"max4466\":true,";
  json += "\"microsd\":" + String(sdActivo ? "true" : "false");
  json += "}";

  // Últimas lecturas de todos los sensores
  json += ",\"bme280_data\":{";
  json += "\"temp\":" + String(ultimaTemp, 1) + ",";
  json += "\"hum\":" + String(ultimaHum, 1) + ",";
  json += "\"pres\":" + String(ultimaPresion, 1);
  json += "}";
  // Cuándo se subió el último dato (hora local exacta)
  json += ",\"subida\":{";
  if (ultimaSubidaMs > 0) {
    json += "\"hora\":\"" + horaLocal(ultimaSubidaEpoch) + "\",";
    json += "\"edad_s\":" + String((millis() - ultimaSubidaMs) / 1000);
  } else {
    json += "\"hora\":null,\"edad_s\":-1";
  }
  json += "}";

  json += ",\"lux\":" + String(ultimaLuz, 1);
  json += ",\"peso\":" + String(ultimoPeso, 2);
  json += ",\"sonido\":" + String(ultimoSonido, 3);
  json += ",\"mic_raw\":" + String(analogRead(MIC_ANALOG));

  // Estado de red (informativo en ambos modos)
  json += ",\"red\":{";
  json += "\"modo\":\"" + String(modoPortalCautivo ? "ap" : "sta") + "\",";
  json += "\"ssid\":\"" + String(modoPortalCautivo ? "BeeStation_Config" : WiFi.SSID()) + "\",";
  json += "\"ip\":\"" + String(modoPortalCautivo ? apIP.toString() : WiFi.localIP().toString()) + "\",";
  json += "\"rssi\":" + String(WiFi.status() == WL_CONNECTED ? WiFi.RSSI() : 0) + ",";
  json += "\"servidor\":\"" + hostGuardado + "\"";
  json += "}";

  json += "}";

  server.send(200, "application/json", json);
}

void procesarGuardado() {
  String nuevoSsid  = server.arg("ssid");
  String nuevoPass  = server.arg("password");
  String nuevoToken = server.arg("token_vinculacion");
  String nuevoHost  = server.arg("host_servidor"); 

  if (nuevoSsid != "" && nuevoToken != "") {
    preferences.begin("beestation", false);
    preferences.putString("ssid", nuevoSsid);
    preferences.putString("password", nuevoPass);
    preferences.putString("token", nuevoToken);
    preferences.putString("host", nuevoHost); 
    preferences.end();

    Serial.println("\n[PORTAL] ¡Configuración guardada exitosamente!");
    Serial.printf("[PORTAL] SSID  : %s\n", nuevoSsid.c_str());
    Serial.printf("[PORTAL] Host  : %s\n", nuevoHost.length() > 0 ? nuevoHost.c_str() : "(auto)");
    Serial.printf("[PORTAL] Token : %s\n", nuevoToken.c_str());

    String respuesta = "<!DOCTYPE html><html><body style='background:#0B0B0B;color:#FFF;font-family:sans-serif;text-align:center;padding:50px;'>";
    respuesta += "<div style='max-width:400px;margin:0 auto;'>";
    respuesta += "<div style='font-size:3rem;margin-bottom:16px;'>✓</div>";
    respuesta += "<h2 style='color:#22c55e;margin-bottom:12px;'>Configuración Guardada</h2>";
    respuesta += "<p style='color:#999;'>El dispositivo se está reiniciando para conectarse a <strong>" + nuevoSsid + "</strong>...</p>";
    respuesta += "<p style='color:#666;font-size:0.8rem;margin-top:20px;'>Token: " + nuevoToken + "</p>";
    respuesta += "</div></body></html>";
    server.send(200, "text/html", respuesta);

    // Apagar LED antes de reiniciar
    digitalWrite(LED_STATUS, LOW);
    delay(2000);
    ESP.restart();
  } else {
    server.send(400, "text/plain", "Datos incompletos. Se requiere SSID y Token de vinculación.");
  }
}