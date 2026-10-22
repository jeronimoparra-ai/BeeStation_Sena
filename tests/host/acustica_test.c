/**
 * tests/host/acustica_test.c
 * ----------------------------------------------------------------
 * Prueba del estimador de frecuencia acústica del firmware.
 * Usa el MISMO encabezado que el ESP32 (beestation_node/acustica.h),
 * no una réplica, para que no puedan divergir.
 *
 * Compilar y ejecutar:
 *   gcc -O2 -Wall -Wextra -o acustica_test acustica_test.c -lm
 *   ./acustica_test
 * ----------------------------------------------------------------
 */
#include <stdio.h>
#include <stdlib.h>
#include <math.h>
#include "../../beestation_node/acustica.h"

#define N_MUESTRAS 256
#define F_MUESTREO 4000.0f
#define DOS_PI     6.28318530718f

static int fallos = 0;
static int pruebas = 0;

static void comprobar(int condicion, const char *que, float obtenido, float esperado) {
    pruebas++;
    if (condicion) {
        printf("  [OK]   %-38s obtenido=%8.2f esperado=%8.2f\n", que, obtenido, esperado);
    } else {
        printf("  [FALLO] %-37s obtenido=%8.2f esperado=%8.2f\n", que, obtenido, esperado);
        fallos++;
    }
}

static void seno(int16_t *m, float hz, float amplitud, float ruido, unsigned *semilla) {
    const float dt = 1.0f / F_MUESTREO;
    for (int i = 0; i < N_MUESTRAS; i++) {
        float v = amplitud * sinf(DOS_PI * hz * (float) i * dt);
        if (ruido > 0.0f && semilla != NULL) {
            // Ruido determinista tipo LCG, uniforme en [-1, 1].
            *semilla = (*semilla * 1664525u) + 1013904223u;
            const float r = ((float) ((*semilla >> 8) & 0xFFFF) / 32767.5f) - 1.0f;
            v += r * ruido;
        }
        m[i] = (int16_t) lroundf(2048.0f + v);
    }
}

int main(void) {
    int16_t m[N_MUESTRAS];
    const float duracion = (float) (N_MUESTRAS - 1) / F_MUESTREO;

    printf("Estimador acustico — %d muestras a %.0f Hz de muestreo\n",
           N_MUESTRAS, F_MUESTREO);

    // ── 1. Tonos puros en la banda de interes ──────────────────
    const float tonos[] = {250.0f, 300.0f, 400.0f, 500.0f, 600.0f, 900.0f};
    for (unsigned k = 0; k < sizeof(tonos) / sizeof(tonos[0]); k++) {
        seno(m, tonos[k], 800.0f, 0.0f, NULL);
        const float f = acusticaFrecuenciaHz(m, N_MUESTRAS, duracion);
        const float error = fabsf(f - tonos[k]) / tonos[k];
        char etiqueta[64];
        snprintf(etiqueta, sizeof(etiqueta), "Tono puro %.0f Hz", tonos[k]);
        comprobar(error <= 0.06f, etiqueta, f, tonos[k]);
    }

    // ── 2. Silencio: no debe inventar frecuencia ───────────────
    for (int i = 0; i < N_MUESTRAS; i++) m[i] = 2048;
    comprobar(acusticaFrecuenciaHz(m, N_MUESTRAS, duracion) == 0.0f,
              "Silencio plano -> 0 Hz", 0.0f, 0.0f);

    // ── 3. Ruido de cuantizacion (pico < umbral) -> 0 ──────────
    for (int i = 0; i < N_MUESTRAS; i++) m[i] = (i % 2) ? 2049 : 2047;
    comprobar(acusticaFrecuenciaHz(m, N_MUESTRAS, duracion) == 0.0f,
              "Ruido de cuantizacion -> 0 Hz", 0.0f, 0.0f);

    // ── 4. Tono con ruido de banda ancha (20 %) ────────────────
    unsigned semilla = 12345u;
    seno(m, 500.0f, 800.0f, 160.0f, &semilla);
    const float conRuido = acusticaFrecuenciaHz(m, N_MUESTRAS, duracion);
    comprobar(fabsf(conRuido - 500.0f) / 500.0f <= 0.12f,
              "Tono 500 Hz + ruido 20 %", conRuido, 500.0f);

    // ── 5. Onda cuadrada (armonicos): los cruces no cambian ────
    for (int i = 0; i < N_MUESTRAS; i++) {
        m[i] = (sinf(DOS_PI * 400.0f * (float) i / F_MUESTREO) >= 0.0f)
                   ? (int16_t) (2048 + 800)
                   : (int16_t) (2048 - 800);
    }
    const float cuadrada = acusticaFrecuenciaHz(m, N_MUESTRAS, duracion);
    comprobar(fabsf(cuadrada - 400.0f) / 400.0f <= 0.06f,
              "Onda cuadrada 400 Hz", cuadrada, 400.0f);

    // ── 6. Amplitud pico a pico en voltios ─────────────────────
    for (int i = 0; i < N_MUESTRAS; i++) m[i] = (i % 2) ? 1000 : 0;
    const float v = acusticaAmplitudV(m, N_MUESTRAS, 3.3f);
    const float vEsperado = (1000.0f * 3.3f) / 4095.0f;
    comprobar(fabsf(v - vEsperado) < 0.001f,
              "Amplitud pico a pico (V)", v, vEsperado);

    printf("\nResultado: %d/%d pruebas OK", pruebas - fallos, pruebas);
    if (fallos > 0) {
        printf(" — %d FALLO(S)\n", fallos);
        return 1;
    }
    printf("\n");
    return 0;
}
