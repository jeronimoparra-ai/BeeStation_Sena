/* ==========================================================================
 *  tests/host/ecdsa_interop.c
 *  BeeStation — Prueba de interoperabilidad Mbed TLS <-> PHP/OpenSSL
 * ==========================================================================
 *
 *  Este programa replica, LITERALMENTE, las operaciones criptograficas que
 *  hace el firmware del ESP32 (beestation_node/identidad_ecdsa.h):
 *
 *    1. Genera un par de claves ECDSA sobre la curva P-256 (secp256r1).
 *    2. Exporta la clave publica en PEM SubjectPublicKeyInfo.
 *    3. Calcula la huella = SHA-256 en hexadecimal en minúsculas de los
 *       bytes EXACTOS del PEM.
 *    4. Firma una cadena canonica con mbedtls_pk_sign (firma ASN.1 DER).
 *    5. Devuelve PEM, firma en hexadecimal y huella en JSON.
 *
 *  Ademas, en modo "verificar", comprueba una firma generada por
 *  PHP/OpenSSL: es la prueba en sentido contrario.
 *
 *  Uso:
 *    ecdsa_interop firmar   <fichero_con_la_cadena_canonica>
 *    ecdsa_interop verificar <fichero_pem> <fichero_cadena> <firma_hex>
 *
 *  Salida: una linea JSON por stdout.
 *
 *  Compilacion (MinGW-w64):
 *    gcc -O2 -I<ruta_mbedtls>/include -o ecdsa_interop.exe \
 *        ecdsa_interop.c <ruta_mbedtls>/build_host/libmbedtls.a
 * ========================================================================== */

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>

#include "mbedtls/pk.h"
#include "mbedtls/ecp.h"
#include "mbedtls/ecdsa.h"
#include "mbedtls/md.h"
#include "mbedtls/sha256.h"
#include "mbedtls/entropy.h"
#include "mbedtls/ctr_drbg.h"
#include "mbedtls/error.h"

#define TAM_PEM    1024
#define TAM_FIRMA  256
#define TAM_HASH   32

/* ── Utilidades ─────────────────────────────────────────────────────────── */

/* Hexadecimal en MINUSCULAS: es la convencion de la cadena canonica. */
static void a_hex_min(const unsigned char *datos, size_t longitud, char *salida)
{
    static const char digitos[] = "0123456789abcdef";
    size_t i;
    for (i = 0; i < longitud; i++) {
        salida[i * 2]     = digitos[(datos[i] >> 4) & 0x0F];
        salida[i * 2 + 1] = digitos[datos[i] & 0x0F];
    }
    salida[longitud * 2] = '\0';
}

/* Volca una cadena de texto en JSON (escapando comillas y saltos). */
static void volcar_json(const char *texto)
{
    const unsigned char *p = (const unsigned char *) texto;
    putchar('"');
    for (; *p != '\0'; p++) {
        switch (*p) {
            case '"':  fputs("\\\"", stdout); break;
            case '\\': fputs("\\\\", stdout); break;
            case '\n': fputs("\\n", stdout);  break;
            case '\r': fputs("\\r", stdout);  break;
            case '\t': fputs("\\t", stdout);  break;
            default:
                if (*p < 0x20) {
                    printf("\\u%04x", *p);
                } else {
                    putchar(*p);
                }
        }
    }
    putchar('"');
}

/* Lee un fichero entero en memoria (con terminador '\0' anadido). */
static char *leer_fichero(const char *ruta, size_t *longitud)
{
    FILE *f = fopen(ruta, "rb");
    char *buffer;
    long tam;
    if (f == NULL) {
        fprintf(stderr, "No se puede abrir %s\n", ruta);
        return NULL;
    }
    fseek(f, 0, SEEK_END);
    tam = ftell(f);
    fseek(f, 0, SEEK_SET);
    buffer = (char *) malloc((size_t) tam + 1);
    if (buffer == NULL) { fclose(f); return NULL; }
    if (tam > 0 && fread(buffer, 1, (size_t) tam, f) != (size_t) tam) {
        fclose(f); free(buffer); return NULL;
    }
    buffer[tam] = '\0';
    fclose(f);
    if (longitud != NULL) {
        *longitud = (size_t) tam;
    }
    return buffer;
}

/* ── Acceso al par de claves EC ─────────────────────────────────────────── */
/*
 *  mbedtls_pk_ec() toma el contexto POR VALOR tanto en Mbed TLS upstream
 *  3.6.x como en el fork que incluye el core de Espressif 3.3.12 (el que
 *  usa el firmware). Se comprueba contra AMBAS cabeceras, no de memoria.
 *
 *  Se deja la variante POR PUNTERO para otros forks, pero la de
 *  Espressif 3.3.12 -la que de verdad compila el firmware- es la de valor.
 */
#ifdef MBEDTLS_PK_EC_POR_PUNTERO
static mbedtls_ecp_keypair *obtener_clave_ec(mbedtls_pk_context *pk)
{
    return mbedtls_pk_ec(pk);
}
#else
static mbedtls_ecp_keypair *obtener_clave_ec(mbedtls_pk_context *pk)
{
    return mbedtls_pk_ec(*pk);
}
#endif

/* ── Generador de numeros aleatorios ───────────────────────────────────── */
/*  En el ESP32 se usa esp_random() (TrueRNG del hardware). En el host se
 *  usa CTR_DRBG sobre la entropia del sistema: lo que se prueba aqui es la
 *  REPRESENTACION de la firma, no la fuente de entropia.               */
static int rng_cb(void *ctx, unsigned char *salida, size_t longitud)
{
    return mbedtls_ctr_drbg_random((mbedtls_ctr_drbg_context *) ctx, salida, longitud);
}

/* ── Modo "firmar" ─────────────────────────────────────────────────────── */
static int modo_firmar(const char *rutaCadena)
{
    mbedtls_pk_context pk;
    mbedtls_entropy_context entropy;
    mbedtls_ctr_drbg_context drbg;
    mbedtls_ecp_keypair *clave;
    unsigned char pem[TAM_PEM];
    unsigned char hash[TAM_HASH];
    unsigned char firma[TAM_FIRMA];
    char firmaHex[TAM_FIRMA * 2 + 1];
    char huellaHex[TAM_HASH * 2 + 1];
    size_t largoFirma = 0;
    size_t longitud = 0;
    int rc;
    char *cadena;

    cadena = leer_fichero(rutaCadena, &longitud);
    if (cadena == NULL) {
        return 1;
    }

    mbedtls_pk_init(&pk);
    mbedtls_entropy_init(&entropy);
    mbedtls_ctr_drbg_init(&drbg);

    rc = mbedtls_ctr_drbg_seed(&drbg, mbedtls_entropy_func, &entropy,
                               (const unsigned char *) "BeeStation", 12);
    if (rc != 0) { fprintf(stderr, "ctr_drbg_seed: -0x%04x\n", (unsigned) -rc); return 1; }

    rc = mbedtls_pk_setup(&pk, mbedtls_pk_info_from_type(MBEDTLS_PK_ECKEY));
    if (rc != 0) { fprintf(stderr, "pk_setup: -0x%04x\n", (unsigned) -rc); return 1; }

    clave = obtener_clave_ec(&pk);
    if (clave == NULL) { fprintf(stderr, "pk_ec: NULL\n"); return 1; }

    rc = mbedtls_ecp_gen_key(MBEDTLS_ECP_DP_SECP256R1, clave, rng_cb, &drbg);
    if (rc != 0) { fprintf(stderr, "ecp_gen_key: -0x%04x\n", (unsigned) -rc); return 1; }

    /* Clave publica en PEM SPKI: escribe desde el inicio del buffer. */
    rc = mbedtls_pk_write_pubkey_pem(&pk, pem, sizeof(pem));
    if (rc != 0) { fprintf(stderr, "write_pubkey_pem: -0x%04x\n", (unsigned) -rc); return 1; }

    /* Huella = SHA-256 de los bytes EXACTOS del PEM. */
    mbedtls_sha256(pem, strlen((const char *) pem), hash, 0);
    a_hex_min(hash, sizeof(hash), huellaHex);

    /* Firma: SHA-256 de la cadena canonica + firma ECDSA DER. */
    mbedtls_sha256((const unsigned char *) cadena, longitud, hash, 0);
    rc = mbedtls_pk_sign(&pk, MBEDTLS_MD_SHA256, hash, sizeof(hash),
                         firma, sizeof(firma), &largoFirma, rng_cb, &drbg);
    if (rc != 0) { fprintf(stderr, "pk_sign: -0x%04x\n", (unsigned) -rc); return 1; }
    a_hex_min(firma, largoFirma, firmaHex);

    printf("{");
    printf("\"pem\":");
    volcar_json((const char *) pem);
    printf(",\"firma_hex\":\"%s\"", firmaHex);
    printf(",\"huella\":\"%s\"", huellaHex);
    printf(",\"firma_bytes\":%lu", (unsigned long) largoFirma);
    printf("}\n");

    mbedtls_pk_free(&pk);
    mbedtls_ctr_drbg_free(&drbg);
    mbedtls_entropy_free(&entropy);
    free(cadena);
    return 0;
}

/* ── Modo "verificar" ──────────────────────────────────────────────────── */
static int modo_verificar(const char *rutaPem, const char *rutaCadena, const char *firmaHex)
{
    mbedtls_pk_context pk;
    unsigned char hash[TAM_HASH];
    unsigned char firma[TAM_FIRMA];
    size_t longitudFirma = 0;
    size_t longitud = 0;
    size_t i;
    int rc;
    char *pem;
    char *cadena;

    pem = leer_fichero(rutaPem, &longitud);
    if (pem == NULL) return 1;
    longitud = 0;
    cadena = leer_fichero(rutaCadena, &longitud);
    if (cadena == NULL) { free(pem); return 1; }

    /* La firma viaja en hexadecimal en minúsculas. */
    if (strlen(firmaHex) % 2 != 0 || strlen(firmaHex) > TAM_FIRMA * 2) {
        fprintf(stderr, "firma_hex con longitud invalida\n");
        return 1;
    }
    for (i = 0; i < strlen(firmaHex) / 2; i++) {
        unsigned int byte;
        if (sscanf(firmaHex + i * 2, "%2x", &byte) != 1) {
            fprintf(stderr, "firma_hex no es hexadecimal valido\n");
            return 1;
        }
        firma[i] = (unsigned char) byte;
    }
    longitudFirma = strlen(firmaHex) / 2;

    mbedtls_pk_init(&pk);
    /* Para PEM, keylen debe incluir el terminador nulo (strlen + 1). */
    rc = mbedtls_pk_parse_public_key(&pk, (const unsigned char *) pem, strlen(pem) + 1);
    if (rc != 0) {
        char detalle[256];
        mbedtls_strerror(rc, detalle, sizeof(detalle));
        printf("{\"ok\":false,\"error\":\"clave publica PEM no parseable\",\"rc\":%d,\"detalle\":\"%s\",\"pem_bytes\":%lu}\n",
               rc, detalle, (unsigned long) strlen(pem));
        return 1;
    }

    mbedtls_sha256((const unsigned char *) cadena, longitud, hash, 0);
    rc = mbedtls_pk_verify(&pk, MBEDTLS_MD_SHA256, hash, sizeof(hash),
                           firma, longitudFirma);
    printf("{\"ok\":%s,\"rc\":%d}\n", (rc == 0) ? "true" : "false", rc);

    mbedtls_pk_free(&pk);
    free(pem);
    free(cadena);
    return (rc == 0) ? 0 : 1;
}

int main(int argc, char **argv)
{
    if (argc < 2) {
        fprintf(stderr, "Uso: %s firmar <cadena> | verificar <pem> <cadena> <firma_hex>\n", argv[0]);
        return 2;
    }
    if (strcmp(argv[1], "firmar") == 0 && argc == 3) {
        return modo_firmar(argv[2]);
    }
    if (strcmp(argv[1], "verificar") == 0 && argc == 5) {
        return modo_verificar(argv[2], argv[3], argv[4]);
    }
    fprintf(stderr, "Argumentos incorrectos.\n");
    return 2;
}
