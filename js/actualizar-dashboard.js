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
        sonido: [400, 600, 'Normal', 'Zona de alerta', 0]
    };

    const num = (v, d) => (v === null || v === undefined || isNaN(v)) ? '—' : Number(v).toFixed(d);

    function pintarLecturas(d) {
        ['temp', 'hum', 'peso', 'sonido'].forEach((clave) => {
            const l = d.lecturas ? d.lecturas[clave] : null;
            const card = document.querySelector('[data-metric="' + clave + '"]');
            if (!card || !l) return;

            const valor = card.querySelector('[data-countup]');
            if (valor && l.valor !== null && l.valor !== undefined) {
                const dec = RANGOS[clave] ? RANGOS[clave][4] : 1;
                valor.textContent = Number(l.valor).toFixed(dec);
                valor.setAttribute('data-countup', l.valor);
            }

            const tiempo = card.querySelector('.metric-time');
            if (tiempo) tiempo.textContent = l.subida + ' · ' + l.relativo;

            const badge = card.querySelector('.metric-footer .badge');
            if (badge && RANGOS[clave] && l.valor !== null) {
                const r = RANGOS[clave];
                const ok = l.valor >= r[0] && l.valor <= r[1];
                badge.textContent = ok ? r[2] : r[3];
                badge.className = 'badge ' + (ok ? 'badge-success' : 'badge-warning');
            }
        });

        const flujo = $('#f-flujo');
        if (flujo && d.flujo !== null && d.flujo !== undefined) {
            flujo.textContent = (d.flujo >= 0 ? '+' : '') + d.flujo + ' kg/día';
        }
    }

    function pintarIndicadores(d) {
        const pintar = (valor, badge, datos, dec) => {
            if (!datos) return;
            const v = $(valor);
            if (v) v.textContent = num(datos.valor, dec);
            const b = $(badge);
            if (b && datos.estado) b.textContent = datos.estado;
        };

        pintar('#v-deltat', '#b-deltat', d.delta_t, 1);
        pintar('#v-hmiel', '#b-hmiel', d.h_miel, 1);

        const fDelta = $('#f-deltat');
        if (fDelta) fDelta.textContent = 'Actual · ' + d.hora;
        const fHmiel = $('#f-hmiel');
        if (fHmiel) fHmiel.textContent = 'Actual · ' + d.hora;

        if (d.ibb) {
            const ibb = $('#ibbValue');
            if (ibb) ibb.textContent = d.ibb.valor;
            const fill = $('#ibbBar');
            if (fill) fill.setAttribute('data-progress', d.ibb.valor);
            const badge = $('#ibbBadge');
            if (badge && d.ibb.estado) {
                badge.textContent = d.ibb.estado;
                badge.className = 'badge ' + (d.ibb.valor >= 85 ? 'badge-success' : (d.ibb.valor >= 50 ? 'badge-warning' : 'badge-critical'));
            }
            const hero = $('#heroIbb');
            if (hero) hero.textContent = num(d.ibb.valor, 1);
            const heroLbl = $('#heroIbbLabel');
            if (heroLbl && d.ibb.estado) heroLbl.textContent = d.ibb.estado;
        }

        if (d.ev) {
            const ev = $('#evValue');
            if (ev) ev.textContent = d.ev.valor;
            const fill = $('#evBar');
            if (fill) fill.setAttribute('data-progress', d.ev.valor);
            const badge = $('#evBadge');
            if (badge && d.ev.estado) {
                badge.textContent = d.ev.estado;
                badge.className = 'badge ' + (d.ev.valor >= 50 ? 'badge-success' : 'badge-warning');
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
        const esp = $('#heroEsp');
        if (esp) esp.textContent = d.online ? 'ESP32 sincronizado' : 'Reconectando…';
        const chipAlertas = $('#heroAlertas');
        if (chipAlertas) chipAlertas.textContent = (d.alertas_total || 0) + ' alertas activas';
        const pie = $('#lastUpdate');
        if (pie) pie.textContent = 'Actualizado ' + d.hora;
    }

    function pintarGrafico(d) {
        const chart = window.tempWeightChart;
        if (!chart || !d.serie_temp) return;
        const labels = d.serie_temp.labels.length ? d.serie_temp.labels : d.serie_peso.labels;
        chart.data.labels = labels;
        chart.data.datasets[0].data = d.serie_temp.data;
        chart.data.datasets[1].data = d.serie_peso.data;
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
            pintarIndicadores(d);
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
