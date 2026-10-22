/**
 * js/actualizar-dashboard.js
 *
 * Refresca la dashboard cada 10 segundos SIN recargar la página:
 * pide a api/estado.php las últimas lecturas (con la hora exacta de
 * subida), las alertas, los indicadores y las series, y pinta los
 * cambios en el DOM. Si la sesión caduca (401) se detiene solo.
 */
(function () {
    'use strict';

    const INTERVALO_MS = 10000;
    const $ = (sel) => document.querySelector(sel);

    // [mínimo, máximo, texto en rango, texto fuera, decimales]
    const RANGOS = {
        temp:   [34, 36, 'Rango óptimo', 'Fuera de rango', 1],
        hum:    [50, 70, 'Normal', 'Atención', 0],
        sonido: [400, 600, 'Banda 400–600 Hz', 'Fuera de banda', 0]
    };

    function pintarLecturas(d) {
        ['temp', 'hum', 'peso', 'sonido'].forEach((clave) => {
            const l = d.lecturas ? d.lecturas[clave] : null;
            const card = document.querySelector('[data-metric="' + clave + '"]');
            if (!card || !l) return;

            const valorNumerico = Number(l.valor);
            const valorValido = l.valor !== null && l.valor !== undefined && Number.isFinite(valorNumerico);
            // Si el servidor no manda el campo, no se asume fresco: mejor
            // mostrar "dato antiguo" que pintar en verde una lectura dudosa.
            const fresca = l.fresca === true;

            const valor = card.querySelector('[data-countup]');
            if (valor && valorValido) {
                const dec = RANGOS[clave] ? RANGOS[clave][4] : 1;
                valor.textContent = valorNumerico.toFixed(dec);
                valor.setAttribute('data-countup', l.valor);
            }

            const tiempo = card.querySelector('.metric-time');
            if (tiempo) tiempo.textContent = l.subida + ' · ' + l.relativo;

            card.classList.remove('success', 'warning', 'critical', 'stale', 'brand');
            if (!fresca) card.classList.add('stale');

            const badge = card.querySelector('.metric-footer .badge');
            if (!badge) return;

            if (!fresca) {
                badge.className = 'badge badge-neutral';
                badge.textContent = 'Dato antiguo';
                return;
            }

            if (clave === 'sonido') {
                // La acústica es experimental: la banda 400–600 Hz es contexto,
                // no un diagnóstico de enjambrazón ni una alerta crítica.
                if (!valorValido || valorNumerico <= 0) {
                    badge.className = 'badge badge-neutral';
                    badge.textContent = 'Sin actividad';
                    return;
                }
                const enBanda = valorNumerico >= RANGOS.sonido[0] && valorNumerico <= RANGOS.sonido[1];
                badge.textContent = enBanda ? 'Banda 400–600 Hz' : 'Fuera de banda';
                badge.className = 'badge ' + (enBanda ? 'badge-warning' : 'badge-neutral');
                if (enBanda) card.classList.add('warning');
            } else if (clave === 'peso' && valorValido) {
                badge.className = 'badge badge-brand';
                badge.textContent = 'Balance 24 h';
                card.classList.add('brand');
            } else if (RANGOS[clave] && valorValido) {
                const r = RANGOS[clave];
                const ok = valorNumerico >= r[0] && valorNumerico <= r[1];
                badge.textContent = ok ? r[2] : r[3];
                badge.className = 'badge ' + (ok ? 'badge-success' : 'badge-warning');
                card.classList.add(ok ? 'success' : 'warning');
            }
        });

        const flujo = $('#f-flujo');
        if (flujo) {
            const pesoFresco = d.lecturas && d.lecturas.peso && d.lecturas.peso.fresca;
            if (!pesoFresco) {
                flujo.textContent = 'Sin datos recientes';
            } else if (d.flujo !== null && d.flujo !== undefined) {
                flujo.textContent = (d.flujo >= 0 ? '+' : '') + d.flujo + ' kg/24 h';
            }
        }
    }

    function pintarAlertas(d) {
        const cont = $('#alertCount');
        if (cont) {
            cont.textContent = (d.alertas_total || 0) + ' activas';
            cont.className = 'badge ' + ((d.alertas_total || 0) > 0 ? 'badge-critical' : 'badge-neutral');
        }

        const lista = $('#alertList');
        if (!lista) return;

        const vacio = $('#alertVacia');
        if (!d.alertas || !d.alertas.length) {
            lista.style.display = 'none';
            if (vacio) vacio.style.display = '';
            return;
        }

        if (vacio) vacio.style.display = 'none';
        lista.style.display = '';
        lista.innerHTML = d.alertas.map((a) =>
            '<li class="alert-item">' +
            '<div class="alert-dot ' + a.punto + '"></div>' +
            '<div class="alert-content">' +
            '<div class="alert-title">' + escapeHtml(a.tipo) + '</div>' +
            '<div class="alert-desc">' + escapeHtml(a.mensaje) + '</div>' +
            '</div>' +
            '<div class="alert-time">' + a.subida + ' · ' + a.relativo + '</div>' +
            '</li>'
        ).join('');
    }

    function pintarEstado(d) {
        const lecturas = d.lecturas || {};
        const valores = ['temp', 'hum', 'peso', 'sonido']
            .map((k) => lecturas[k])
            .filter(Boolean);
        const frescas = valores.filter((l) => l.fresca);
        const masReciente = valores
            .filter((l) => l.fecha)
            .sort((a, b) => String(b.fecha).localeCompare(String(a.fecha)))[0] || null;

        const titulo = $('#statusTitle');
        const sub = $('#statusSub');
        const dot = $('#statusDot');
        const ultimo = $('#statusLast');
        const alertas = $('#statusAlerts');
        const sensores = $('#statusSensors');

        if (titulo) {
            if (d.online) titulo.textContent = 'Nodo en línea';
            else if (valores.length) titulo.textContent = 'Nodo sin reportar';
            else titulo.textContent = 'Nodo sin configurar';
        }
        if (dot) {
            dot.className = 'status-dot ' + (d.online ? 'online' : (valores.length ? 'warning' : 'offline'));
        }
        if (sub) {
            if (d.online) sub.textContent = 'Recibiendo telemetría. Última lectura: ' + (masReciente ? masReciente.relativo : 'sin lecturas') + '.';
            else if (valores.length) sub.textContent = 'Última lectura: ' + (masReciente ? masReciente.relativo : 'sin lecturas') + '. Revisa energía y WiFi.';
            else sub.textContent = 'Aún no llega telemetría de esta colmena.';
        }
        if (ultimo) {
            if (!masReciente || !masReciente.fecha) {
                ultimo.textContent = 'Sin datos';
            } else {
                const f = String(masReciente.fecha);
                ultimo.textContent = f.slice(8, 10) + '/' + f.slice(5, 7) + ' ' + f.slice(11, 16);
            }
        }
        if (alertas) alertas.textContent = String(d.alertas_total || 0);
        if (sensores) sensores.textContent = frescas.length + ' de 4';

        const pie = $('#lastUpdate');
        if (pie) pie.textContent = 'Actualizado ' + d.hora;
    }

    function pintarGrafico(d) {
        const chart = window.tempChart;
        if (!chart || !d.serie_temp) return;
        chart.data.labels = d.serie_temp.labels;
        chart.data.datasets[0].data = d.serie_temp.data;
        chart.update('none');
    }

    function escapeHtml(txt) {
        const div = document.createElement('div');
        div.textContent = txt === null || txt === undefined ? '' : txt;
        return div.innerHTML;
    }

    let timer = null;

    async function refrescar() {
        if (document.visibilityState !== 'visible') return;
        try {
            const r = await fetch('api/estado.php', { cache: 'no-store' });
            if (r.status === 401) { detener(); return; }
            if (!r.ok) return;
            const d = await r.json();
            if (!d.ok) return;
            pintarLecturas(d);
            pintarAlertas(d);
            pintarEstado(d);
            pintarGrafico(d);
        } catch (e) {
            // sin red o servidor caído: se reintenta en el siguiente tick
        }
    }

    function detener() {
        if (timer) { clearInterval(timer); timer = null; }
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (document.querySelector('[data-metric]')) {
            timer = setInterval(refrescar, INTERVALO_MS);
            refrescar();
        }
    });
})();
