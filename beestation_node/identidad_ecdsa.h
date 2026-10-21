// ============================================================================
//  identidad_ecdsa.h — Identidad criptográfica por dispositivo (ECDSA P-256)
//  BeeStation · Nodo ESP32
// ============================================================================
//
//  OBJETIVO
//
//  Sustituir el "token de vinculación" (secreto compartido que el usuario
//  tenía que pegar en el portal) por una identidad criptográfica que el
//  nodo genera por sí mismo:
//
//    · El nodo genera un par de claves ECDSA sobre la curva P-256 (NIST
//      secp256r1) la primera vez que arranca.
//    · La clave PRIVADA vive únicamente en la NVS del ESP32. Nunca sale del
//      dispositivo, nunca se envía al servidor, nunca se imprime por el
//      puerto serie, nunca entra en el repositorio.
//    · La clave PÚBLICA se registra en el servidor durante el alta y sirve
//      para verificar las firmas que envía el nodo.
//    · El servidor decide si el nodo está AUTORIZADO y a qué colmena
//      pertenece; la revocación es individual y no requiere reflashear.
//
//  FORMATOS (verificados, no supuestos)
//
//    · Clave pública .... SubjectPublicKeyInfo en PEM ("BEGIN PUBLIC KEY").
//                         Es el único formato que acepta
//                         openssl_pkey_get_public() en PHP (OpenSSL NO
//                         acepta SPKI en DER binario).
//    · Clave privada ... SEC1 en PEM ("BEGIN EC PRIVATE KEY"), guardada en
//                         la NVS del propio nodo.
//    · Firma ........... ASN.1 DER (SEQUENCE { INTEGER r, INTEGER s }),
//                         idéntico al que producen OpenSSL y
//                         mbedtls_ecdsa_write_signature(). Se transporta
//                         en hexadecimal en minúsculas.
//    · Huella .......... SHA-256 en hexadecimal en minúsculas del PEM de la
//                         clave pública. Vincula la identidad con la clave.
//
//  La interoperabilidad DER <-> OpenSSL NO se da por supuesta: se verifica
//  con la prueba cruzada real de tests/php/ecdsa_interop_test.php.
//
//  CADENA CANÓNICA FIRMADA (debe ser idéntica byte a byte en el servidor)
//
//      BEE2 \n
//      <MÉTODO HTTP en mayúsculas> \n
//      <RUTA normalizada, sin query string> \n
//      <TIMESTAMP epoch en segundos> \n
//      <MAC en mayúsculas con dos puntos> \n
//      <NONCE en hexadecimal en minúsculas> \n
//      <ID_DISPOSITIVO> \n
//      <SHA-256 en hexadecimal en minúsculas del cuerpo>
//
//  Sin salto de línea final. Todos los campos son ASCII y ninguno puede
//  contener "\n", por lo que la concatenación es unívoca.
//
// ============================================================================

#ifndef BEE_IDENTIDAD_ECDSA_H
#define BEE_IDENTIDAD_ECDSA_H

#include <Arduino.h>
#include <stdint.h>
#include <string.h>

#include <mbedtls/pk.h>
#include <mbedtls/ecp.h>
#include <mbedtls/ecdsa.h>
#include <mbedtls/md.h>
#include <mbedtls/sha256.h>
#include <mbedtls/error.h>
#include <esp_system.h>

#include "util_hex.h"

// ── Parámetros ──────────────────────────────────────────────────────────────
#define IDENTIDAD_VERSION        1
#define IDENTIDAD_CURVA         MBEDTLS_ECP_DP_SECP256R1
#define IDENTIDAD_TAMANO_PEM    1024   // clave privada SEC1 PEM  (~230 B)
#define IDENTIDAD_TAMANO_FIRMA  256    // firma DER P-256        (~72 B)
#define IDENTIDAD_TAMANO_HASH   32

// ── Claves en la NVS (namespace "beestation") ───────────────────────────────
#define IDENTIDAD_NVS_CLAVE     "id_clave"   // PEM de la clave privada
#define IDENTIDAD_NVS_PUBLICA   "id_pub"     // PEM de la clave pública
#define IDENTIDAD_NVS_ID        "id_disp"    // identificador del dispositivo
#define IDENTIDAD_NVS_CODIGO    "id_cod"     // código corto de alta

// ── Estado ──────────────────────────────────────────────────────────────────
static mbedtls_pk_context identPk;
static bool identListo        = false;
static String identIdDisp     = "";
static String identPubPem     = "";
static String identHuella     = "";
static String identCodigoAlta = "";

// ── Fuente de entropía ──────────────────────────────────────────────────────
//  esp_random() es el generador de hardware del ESP32 (TrueRNG). Evita
//  arrastrar mbedtls_entropy + mbedtls_ctr_drbg (≈+8 KB de flash) para
//  nada: la firma ECDSA solo necesita bytes aleatorios de calidad criptográfica.
static int identRng(void* ctx, unsigned char* salida, size_t longitud) {
  (void)ctx;
  size_t i = 0;
  while (i < longitud) {
    uint32_t v = esp_random();
    size_t trozo = (longitud - i < 4) ? (longitud - i) : 4;
    memcpy(salida + i, &v, trozo);
    i += trozo;
  }
  return 0;
}

// ── SHA-256 en hexadecimal en minúsculas ───────────────────────────────────
static String identSha256Hex(const uint8_t* datos, size_t longitud) {
  uint8_t hash[IDENTIDAD_TAMANO_HASH];
  mbedtls_sha256(datos, longitud, hash, 0);
  return hexMinusculas(hash, sizeof(hash));
}

// ── Carga la clave privada desde la NVS y publica la clave pública ─────────
static bool identCargarClave() {
  if (identListo) return true;

  Preferences prefs;
  prefs.begin("beestation", true);
  String pemPrivada = prefs.getString(IDENTIDAD_NVS_CLAVE, "");
  prefs.end();

  if (pemPrivada.length() < 32) return false;

  mbedtls_pk_init(&identPk);
  // IMPORTANTE: para datos PEM, keylen debe incluir el terminador nulo,
  // es decir, strlen(pem) + 1. Si se pasa solo la longitud del texto, la
  // funcion rechaza la clave con PEM_NO_HEADER_FOOTER_PRESENT y el nodo
  // no arrancaria con su identidad.
  int rc = mbedtls_pk_parse_key(&identPk,
                                 (const unsigned char*)pemPrivada.c_str(),
                                 pemPrivada.length() + 1,
                                 NULL, 0, identRng, NULL);
  if (rc != 0) {
    Serial.printf("[IDENTIDAD] clave privada ilegible: -0x%04x\n", (unsigned)(-rc));
    mbedtls_pk_free(&identPk);
    return false;
  }

  // Reexportamos la clave pública: Mbed TLS es determinista, por lo que el
  // PEM resultante es byte a byte el mismo que se registró en el servidor.
  static char pemBuf[IDENTIDAD_TAMANO_PEM];
  rc = mbedtls_pk_write_pubkey_pem(&identPk, (unsigned char*)pemBuf, sizeof(pemBuf));
  if (rc != 0) {
    Serial.printf("[IDENTIDAD] no se pudo exportar la clave publica: -0x%04x\n", (unsigned)(-rc));
    mbedtls_pk_free(&identPk);
    return false;
  }
  identPubPem = String(pemBuf);

  // Huella = SHA-256 de los bytes EXACTOS del PEM (los mismos que se envían).
  identHuella = identSha256Hex((const uint8_t*)identPubPem.c_str(), identPubPem.length());

  identListo = true;
  return true;
}

// ── Genera un par de claves nuevo y lo guarda en la NVS ────────────────────
static bool identGenerarClave() {
  mbedtls_pk_init(&identPk);

  int rc = mbedtls_pk_setup(&identPk, mbedtls_pk_info_from_type(MBEDTLS_PK_ECKEY));
  if (rc != 0) {
    Serial.printf("[IDENTIDAD] pk_setup fallo: -0x%04x\n", (unsigned)(-rc));
    mbedtls_pk_free(&identPk);
    return false;
  }

  mbedtls_ecp_keypair* clave = mbedtls_pk_ec(&identPk);
  if (clave == NULL) {
    Serial.println("[IDENTIDAD] no se pudo obtener el par de claves EC");
    mbedtls_pk_free(&identPk);
    return false;
  }

  rc = mbedtls_ecp_gen_key(IDENTIDAD_CURVA, clave, identRng, NULL);
  if (rc != 0) {
    Serial.printf("[IDENTIDAD] generacion de clave fallo: -0x%04x\n", (unsigned)(-rc));
    mbedtls_pk_free(&identPk);
    return false;
  }

  // Clave privada -> PEM SEC1 (nunca sale del dispositivo).
  static char pemPriv[IDENTIDAD_TAMANO_PEM];
  rc = mbedtls_pk_write_key_pem(&identPk, (unsigned char*)pemPriv, sizeof(pemPriv));
  if (rc != 0) {
    Serial.printf("[IDENTIDAD] no se pudo serializar la clave privada: -0x%04x\n", (unsigned)(-rc));
    mbedtls_pk_free(&identPk);
    return false;
  }

  // Clave pública -> PEM SPKI (sí se envía al servidor).
  static char pemPub[IDENTIDAD_TAMANO_PEM];
  rc = mbedtls_pk_write_pubkey_pem(&identPk, (unsigned char*)pemPub, sizeof(pemPub));
  if (rc != 0) {
    Serial.printf("[IDENTIDAD] no se pudo serializar la clave publica: -0x%04x\n", (unsigned)(-rc));
    mbedtls_pk_free(&identPk);
    return false;
  }
  identPubPem = String(pemPub);
  identHuella = identSha256Hex((const uint8_t*)identPubPem.c_str(), identPubPem.length());

  // Identificador estable del dispositivo: 128 bits aleatorios en hex.
  uint8_t aleatorio[16];
  for (size_t i = 0; i < sizeof(aleatorio); i += 4) {
    uint32_t v = esp_random();
    memcpy(aleatorio + i, &v, 4);
  }
  identIdDisp = hexMinusculas(aleatorio, sizeof(aleatorio));

  Preferences prefs;
  prefs.begin("beestation", false);
  prefs.putString(IDENTIDAD_NVS_CLAVE, String(pemPriv));
  prefs.putString(IDENTIDAD_NVS_PUBLICA, identPubPem);
  prefs.putString(IDENTIDAD_NVS_ID, identIdDisp);
  prefs.end();

  identListo = true;
  // Importante: solo se imprime la HUELLA, nunca la clave privada.
  Serial.printf("[IDENTIDAD] nueva identidad %s (huella %s)\n",
                identIdDisp.c_str(), identHuella.substring(0, 16).c_str());
  return true;
}

// ── Punto de entrada: carga la identidad o la genera la primera vez ───────
static bool identIniciar() {
  if (identListo) return true;

  if (identCargarClave()) {
    Preferences prefs;
    prefs.begin("beestation", true);
    identIdDisp = prefs.getString(IDENTIDAD_NVS_ID, "");
    identCodigoAlta = prefs.getString(IDENTIDAD_NVS_CODIGO, "");
    prefs.end();
    if (identIdDisp.length() == 32) {
      Serial.printf("[IDENTIDAD] identidad cargada %s\n", identIdDisp.c_str());
      return true;
    }
    Serial.println("[IDENTIDAD] identidad incompleta, regenerando");
    identListo = false;
  }

  return identGenerarClave();
}

// ── Firma ECDSA de un mensaje ──────────────────────────────────────────────
//  Devuelve la firma ASN.1 DER en hexadecimal en minúsculas ("" si falla).
static String identFirmarHex(const String& mensaje) {
  if (!identListo) return "";

  uint8_t hash[IDENTIDAD_TAMANO_HASH];
  mbedtls_sha256((const unsigned char*)mensaje.c_str(), mensaje.length(), hash, 0);

  uint8_t firma[IDENTIDAD_TAMANO_FIRMA];
  size_t largo = 0;
  int rc = mbedtls_pk_sign(&identPk, MBEDTLS_MD_SHA256,
                           hash, sizeof(hash),
                           firma, sizeof(firma), &largo,
                           identRng, NULL);
  if (rc != 0) {
    Serial.printf("[IDENTIDAD] la firma fallo: -0x%04x\n", (unsigned)(-rc));
    return "";
  }
  return hexMinusculas(firma, largo);
}

// ── Cadena canónica de una petición (idéntica en PHP) ──────────────────────
static String identCadenaCanonica(const String& metodo,
                                   const String& ruta,
                                   const String& timestamp,
                                   const String& mac,
                                   const String& nonce,
                                   const String& hashCuerpo) {
  String c;
  c.reserve(64 + metodo.length() + ruta.length() + timestamp.length() +
            mac.length() + nonce.length() + identIdDisp.length() + hashCuerpo.length());
  c += "BEE2\n";
  c += metodo;   c += "\n";
  c += ruta;     c += "\n";
  c += timestamp; c += "\n";
  c += mac;      c += "\n";
  c += nonce;    c += "\n";
  c += identIdDisp; c += "\n";
  c += hashCuerpo;
  return c;
}

// ── Cadena canónica de la prueba de posesión (PoP) ──────────────────────────
//  El nodo firma con su clave privada una cadena que incluye su propia huella.
//  El servidor la verifica con la clave pública que el nodo acaba de enviar:
//  eso demuestra que el nodo posee la clave privada (proof of possession)
//  sin necesidad de un reto previo del servidor.
static String identCadenaPop(const String& mac,
                              const String& timestamp,
                              const String& nonce) {
  String c;
  c.reserve(48 + mac.length() + timestamp.length() + nonce.length() + identHuella.length());
  c += "BEE2-POP\n";
  c += identIdDisp; c += "\n";
  c += mac;        c += "\n";
  c += timestamp;  c += "\n";
  c += nonce;      c += "\n";
  c += identHuella;
  return c;
}

// ── Acceso a los datos de la identidad ─────────────────────────────────────
static String identId()        { return identIdDisp; }
static String identPublicaPem(){ return identPubPem; }
static String identHuellaClave(){ return identHuella; }
static String identCodigo()    { return identCodigoAlta; }

static void identGuardarCodigo(const String& codigo) {
  identCodigoAlta = codigo;
  Preferences prefs;
  prefs.begin("beestation", false);
  prefs.putString(IDENTIDAD_NVS_CODIGO, codigo);
  prefs.end();
}

// ── Código corto de alta (alfabeto sin caracteres ambiguos: sin O/0, I/1) ──
static String identGenerarCodigoAlta() {
  static const char alfabeto[] = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
  const size_t n = sizeof(alfabeto) - 1;
  String codigo;
  uint8_t bytes[8];
  for (size_t i = 0; i < sizeof(bytes); i += 4) {
    uint32_t v = esp_random();
    memcpy(bytes + i, &v, 4);
  }
  for (size_t i = 0; i < 8; i++) {
    codigo += alfabeto[bytes[i] % n];
  }
  codigo += "-";
  codigo += alfabeto[bytes[4] % n];
  codigo += alfabeto[bytes[5] % n];
  codigo += alfabeto[bytes[6] % n];
  codigo += alfabeto[bytes[7] % n];
  return codigo;
}

#endif  // BEE_IDENTIDAD_ECDSA_H
