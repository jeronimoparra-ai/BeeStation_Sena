/**
 * beestation_node/acustica.h
 * ----------------------------------------------------------------
 * Análisis de la señal del micrófono analógico (MAX4466 / MAX9814).
 *
 * El backend y el panel interpretan `sonido` como FRECUENCIA en Hz
 * (banda de pre-enjambrazón 400–600 Hz). El firmware anterior enviaba
 * el voltaje pico a pico del micrófono, de modo que:
 *   · el panel mostraba "274 Hz" cuando en realidad eran voltios;
 *   · la alerta de enjambrazón nunca podía dispararse.
 *
 * Aquí se estima la frecuencia dominante por cruces por cero con
 * histéresis. Es un método barato (sin FFT) y adecuado para una señal
 * tonal dominante; con ruido de banda ancha da una aproximación de la
 * frecuencia media. Debe validarse en una colmena real antes de
 * tratar el valor como diagnóstico definitivo.
 *
 * Este archivo es C puro (sin Arduino) para poder probarlo en el host:
 *   tests/host/acustica_test.c
 * ----------------------------------------------------------------
 */
#ifndef BEESTATION_ACUSTICA_H
#define BEESTATION_ACUSTICA_H

#include <stdint.h>
#include <math.h>

/** Muestras mínimas para que la estimación tenga sentido. */
#define ACUSTICA_MIN_MUESTRAS 32

/** Umbral de silencio, en cuentas del ADC (0–4095). */
#define ACUSTICA_UMBRAL_SILENCIO 8.0f

/**
 * Frecuencia dominante estimada, en Hz.
 *
 * @param muestras     Muestras crudas del ADC (0–4095).
 * @param n            Número de muestras válidas.
 * @param duracionSeg  Tiempo real entre la primera y la última muestra.
 *
 * @return Frecuencia en Hz, o 0.0f si no hay señal suficiente.
 */
static inline float acusticaFrecuenciaHz(const int16_t *muestras, int n, float duracionSeg) {
    if (muestras == 0 || n < ACUSTICA_MIN_MUESTRAS || duracionSeg <= 0.0f) {
        return 0.0f;
    }

    // Componente continua: la señal del micrófono está centrada en Vcc/2.
    long suma = 0;
    for (int i = 0; i < n; i++) {
        suma += muestras[i];
    }
    const float media = (float) suma / (float) n;

    // Amplitud pico respecto a la media: sirve para detectar silencio y
    // para fijar una histéresis proporcional a la señal.
    float pico = 0.0f;
    for (int i = 0; i < n; i++) {
        const float v = fabsf((float) muestras[i] - media);
        if (v > pico) {
            pico = v;
        }
    }
    if (pico < ACUSTICA_UMBRAL_SILENCIO) {
        return 0.0f;
    }

    // Sin histéresis, el ruido de cuantización genera cruces falsos y
    // dispara la frecuencia estimada. El 25 % del pico ignora ese ruido
    // y sigue contando los cruces de la onda dominante.
    const float umbral = pico * 0.25f;
    int estado = 0;   // -1 = bajo, +1 = alto, 0 = aún sin cruzar
    int cruces = 0;

    for (int i = 0; i < n; i++) {
        const float v = (float) muestras[i] - media;
        if (estado <= 0 && v > umbral) {
            if (estado != 0) {
                cruces++;   // cruce ascendente real
            }
            estado = 1;
        } else if (estado >= 0 && v < -umbral) {
            if (estado != 0) {
                cruces++;   // cruce descendente real
            }
            estado = -1;
        }
    }

    // Un ciclo completo = dos cruces (subida + bajada).
    return (float) cruces / (2.0f * duracionSeg);
}

/**
 * Amplitud pico a pico de la señal, en voltios.
 *
 * @param muestras  Muestras crudas del ADC.
 * @param n         Número de muestras.
 * @param vref      Tensión de referencia del ADC (3.3 V en el ESP32).
 */
static inline float acusticaAmplitudV(const int16_t *muestras, int n, float vref) {
    if (muestras == 0 || n < 2) {
        return 0.0f;
    }
    int16_t minimo = muestras[0];
    int16_t maximo = muestras[0];
    for (int i = 1; i < n; i++) {
        if (muestras[i] < minimo) minimo = muestras[i];
        if (muestras[i] > maximo) maximo = muestras[i];
    }
    return ((float) (maximo - minimo) * vref) / 4095.0f;
}

#endif // BEESTATION_ACUSTICA_H
