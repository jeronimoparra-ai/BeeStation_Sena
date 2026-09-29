/**
 * BeeStation — Firmware de simulación (Wokwi)
 * Replica el contrato JSON de api/ingest.php:
 * { "id_colmena": 1, "lecturas": [ {"tipo": "...", "valor": ...}, ... ] }
 *
 * Requiere header X-API-Key (bug B4 resuelto en ingest.php).
 * ⚠️ apiKey debe coincidir EXACTO con BEESTATION_API_KEY de tu config/db.php real.
 * Confirmado por ti: sigue siendo el placeholder de fábrica.
 *
 * Incluye sensor 'energia' (INA219) — bug B3 resuelto, ya existe en tu schema.sql.
 */
#include <WiFi.h>
#include <HTTPClient.h>
#include <DHT.h>
#include <ArduinoJson.h>

// ── Red: Wokwi-GUEST + Private Gateway ─────────────────────────
const char* ssid     = "Wokwi-GUEST";
const char* password = "";

// Ruta confirmada: htdocs/BeeStation_ConDB/BeeStation/
const char* serverUrl = "http://host.wokwi.internal/BeeStation_ConDB/BeeStation/api/ingest.php";

// Confirmado: sigue siendo el placeholder de config/db.php
const char* apiKey = "CAMBIAR_ESTA_CLAVE_ANTES_DE_PRODUCCION";

const int ID_COLMENA = 1; // debe existir en la tabla `colmena` real

// ── Pines ────────────────────────────────────────────────────
#define PIN_DHT_INT 4
#define PIN_DHT_EXT 5
#define PIN_POT_PESO    34
#define PIN_POT_SONIDO  35
#define PIN_POT_CO2     32
#define PIN_POT_ENERGIA 33

DHT dhtInt(PIN_DHT_INT, DHT22);
DHT dhtExt(PIN_DHT_EXT, DHT22);

unsigned long ultimoEnvio = 0;
const unsigned long INTERVALO_MS = 60000; // 60 s, igual que README.txt

void conectarWiFi() {
    Serial.print("Conectando a ");
    Serial.println(ssid);
    WiFi.begin(ssid, password);
    while (WiFi.status() != WL_CONNECTED) {
        delay(500);
        Serial.print(".");
    }
    Serial.println();
    Serial.print("WiFi conectado. IP: ");
    Serial.println(WiFi.localIP());
}

float leerPotComoRango(int pin, float min_val, float max_val) {
    int raw = analogRead(pin); // 0–4095 en ESP32
    return min_val + (raw / 4095.0) * (max_val - min_val);
}

void enviarLecturas() {
    if (WiFi.status() != WL_CONNECTED) {
        Serial.println("WiFi desconectado, reintentando...");
        WiFi.begin(ssid, password);
        return;
    }

    float temperatura = dhtInt.readTemperature();
    float humedad      = dhtInt.readHumidity();
    float tempExterna  = dhtExt.readTemperature();

    float peso    = leerPotComoRango(PIN_POT_PESO, 0, 50);       // kg, rango HX711 en schema.sql
    float sonido  = leerPotComoRango(PIN_POT_SONIDO, 20, 900);   // Hz, cubre las 3 zonas
    float co2     = leerPotComoRango(PIN_POT_CO2, 10, 300);      // ppm, rango MQ-135 en schema.sql
    float energia = leerPotComoRango(PIN_POT_ENERGIA, 0, 5);     // V, rango INA219 en schema.sql (0–5)

    if (isnan(temperatura) || isnan(humedad)) {
        Serial.println("Error leyendo DHT22 interno, se omite este ciclo.");
        return;
    }

    StaticJsonDocument<512> doc;
    doc["id_colmena"] = ID_COLMENA;
    JsonArray lecturas = doc.createNestedArray("lecturas");

    JsonObject l1 = lecturas.createNestedObject();
    l1["tipo"] = "temperatura_interna";
    l1["valor"] = temperatura;

    if (!isnan(tempExterna)) {
        JsonObject l2 = lecturas.createNestedObject();
        l2["tipo"] = "temperatura_externa";
        l2["valor"] = tempExterna;
    }

    JsonObject l3 = lecturas.createNestedObject();
    l3["tipo"] = "humedad_relativa";
    l3["valor"] = humedad;

    JsonObject l4 = lecturas.createNestedObject();
    l4["tipo"] = "peso";
    l4["valor"] = peso;

    JsonObject l5 = lecturas.createNestedObject();
    l5["tipo"] = "sonido";
    l5["valor"] = sonido;

    JsonObject l6 = lecturas.createNestedObject();
    l6["tipo"] = "co2";
    l6["valor"] = co2;

    JsonObject l7 = lecturas.createNestedObject();
    l7["tipo"] = "energia";
    l7["valor"] = energia;

    String payload;
    serializeJson(doc, payload);

    HTTPClient http;
    http.begin(serverUrl);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-API-Key", apiKey);

    Serial.println("Enviando: " + payload);
    int httpCode = http.POST(payload);
    String respuesta = http.getString();

    Serial.printf("Código HTTP: %d\n", httpCode);
    Serial.println("Respuesta: " + respuesta);

    http.end();
}

void setup() {
    Serial.begin(115200);
    dhtInt.begin();
    dhtExt.begin();
    conectarWiFi();
}

void loop() {
    if (millis() - ultimoEnvio >= INTERVALO_MS) {
        ultimoEnvio = millis();
        enviarLecturas();
    }
}