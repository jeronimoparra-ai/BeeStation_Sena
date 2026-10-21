// ============================================================================
//  util_hex.h — Codificación hexadecimal compartida
//  BeeStation · Nodo ESP32
// ============================================================================
//
//  CONVENCIÓN DE HEXADECIMAL (crítica para la interoperabilidad)
//
//  La cadena canónica que se firma usa SIEMPRE hexadecimal en MINÚSCULAS.
//  Es la convención que PHP aplica de forma nativa:
//
//      hash('sha256', ...)     -> minúsculas
//      hash_hmac('sha256', ...) -> minúsculas
//
//  Si el nodo emitiera mayúsculas, la cadena canónica diferiría byte a byte
//  de la que construye el servidor y NINGUNA firma validaría. Este fue un
//  bug real del firmware (hexMayusculas) detectado en la auditoría.
//
//  Para las cabeceras que transportan identificadores opacos (token CSRF del
//  portal) se conserva la variante en mayúsculas, porque ahí la comparación
//  la hace el propio firmware de forma insensible a la caja.
//
//  Ver: includes/firma.php (PHP) y tests/php/interop_firmware_test.php.
// ============================================================================

#ifndef BEE_UTIL_HEX_H
#define BEE_UTIL_HEX_H

#include <Arduino.h>
#include <stdint.h>
#include <stdio.h>
#include <string.h>

// Hexadecimal en MAYÚSCULAS.
inline String hexMayusculas(const uint8_t* datos, size_t longitud) {
  String r = "";
  r.reserve(longitud * 2);
  for (size_t i = 0; i < longitud; i++) {
    char par[3];
    snprintf(par, sizeof(par), "%02X", datos[i]);
    r += par;
  }
  return r;
}

// Hexadecimal en MINÚSCULAS: convención de la cadena canónica firmada.
inline String hexMinusculas(const uint8_t* datos, size_t longitud) {
  String r = "";
  r.reserve(longitud * 2);
  for (size_t i = 0; i < longitud; i++) {
    char par[3];
    snprintf(par, sizeof(par), "%02x", datos[i]);
    r += par;
  }
  return r;
}

#endif  // BEE_UTIL_HEX_H
