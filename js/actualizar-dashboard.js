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
        sonido: [400, 600, 'Banda de referencia', 'Fuera de banda de referencia', 0]
    };

    function pintarLecturas(d) {
        ['temp', 'hum', 'peso', 'sonido'].forEach((clave) => {
            const l = d.lecturas ? d.lecturas[clave] : null;
            const card = document.querySelector('[data-metric="' + clave + '"]');
            if (!card) return;

            const badge = card.querySelector('.metric-footer .badge');
            if (clave === 'sonido' && (!l || l.valor === null || l.valor === undefined || !Number.isFinite(Number(l.valor)))) {
                card.classList.remove('success', 'warning', 'critical', 'stale', 'brand');
                if (badge) {
                    badge.className = 'badge badge-neutral';
                    badge.textContent = 'Sin actividad';
                }
                return;
            }
            if (!l) return;

            const valorNumerico = Number(l.valor);
            const valorValido = l.valor !== null && l.valor !== undefined && Number.isFinite(valorNumerico);
            // Las lecturas incompletas no deben sobrescribir la presentación PHP.
            if (!valorValido || typeof l.subida !== 'string' || typeof l.relativo !== 'string'
                || typeof l.fresca !== 'boolean') return;
            if (clave === 'sonido' && valorNumerico <= 0) {
                card.classList.remove('success', 'warning', 'critical', 'stale', 'brand');
                if (!l.fresca) card.classList.add('stale');
                if (badge) {
                    badge.className = 'badge badge-neutral';
                    badge.textContent = 'Sin actividad';
                }
                return;
            }
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

            if (!badge) return;

            if (!fresca) {
                badge.className = 'badge badge-neutral';
                badge.textContent = 'Dato antiguo';
                return;
            }

            if (clave === 'sonido') {
                // La banda es solo una referencia experimental, no un umbral de alerta.
                const enBanda = valorNumerico >= RANGOS.sonido[0] && valorNumerico <= RANGOS.sonido[1];
                badge.textContent = enBanda ? RANGOS.sonido[2] : RANGOS.sonido[3];
                badge.className = 'badge badge-neutral';
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
        const periodo = $('#tempPeriod');
        if (periodo && periodo.value !== '24') return;
        const chart = window.tempChart;
        const serie = d.serie_temp;
        if (!serie || !Array.isArray(serie.labels) || !Array.isArray(serie.data) || !Array.isArray(serie.muestras)
            || serie.labels.length !== serie.data.length
            || serie.data.length !== serie.muestras.length
            || !serie.labels.every((label) => typeof label === 'string')
            || !serie.data.every((value) => typeof value === 'number' && Number.isFinite(value))
            || !serie.muestras.every((value) => Number.isInteger(value) && value >= 0)) {
            throw new TypeError('serie_temp incompleta o inconsistente');
        }
        if (!chart || !chart.data.datasets[0]) throw new Error('instancia de temperatura no disponible');

        chart.data.labels = serie.labels;
        chart.data.datasets[0].data = serie.data;
        chart.options.scales.x.offset = serie.data.length === 1;
        chart.update('none');

        const hayDatos = serie.data.length > 0;
        const shell = $('#tempChartShell');
        const vacio = $('#tempChartEmpty');
        const details = $('#tempChartTable');
        const tbody = details && details.querySelector('tbody');
        if (tbody) {
            const rows = serie.labels.map((label, index) => {
                const row = document.createElement('tr');
                const date = document.createElement('td');
                const value = document.createElement('td');
                const samples = document.createElement('td');
                date.textContent = label;
                value.textContent = serie.data[index].toFixed(1) + ' °C';
                samples.textContent = String(serie.muestras[index]);
                row.append(date, value, samples);
                return row;
            });
            tbody.replaceChildren(...rows);
        }
        if (details) details.style.display = hayDatos ? '' : 'none';
        if (shell) shell.style.display = hayDatos ? '' : 'none';
        if (vacio) vacio.style.display = hayDatos ? 'none' : '';
        if (!hayDatos && vacio) {
            const texto = vacio.querySelector('.empty-desc');
            const ultima = d.lecturas && d.lecturas.temp;
            if (texto) {
                let mensaje;
                if (ultima && ultima.relativo) {
                    mensaje = 'Sin lecturas válidas en las últimas 24 horas. La última lectura válida fue ' + ultima.relativo + '; amplía el período para consultarla.';
                } else if (serie.ultima_invalida) {
                    mensaje = 'No hay lecturas válidas en las últimas 24 horas.';
                } else {
                    mensaje = 'Sin lecturas válidas en las últimas 24 horas ni registros para esta colmena.';
                }
                if (serie.ultima_invalida) {
                    mensaje += ' Hay una lectura marcada inválida del ' + serie.ultima_invalida + ' que no se muestra.';
                }
                texto.textContent = mensaje;
            }
        }
        if (hayDatos) chart.resize();
    }

    function escapeHtml(txt) {
        const div = document.createElement('div');
        div.textContent = txt === null || txt === undefined ? '' : txt;
        return div.innerHTML;
    }

    let timer = null;
    let enVuelo = false;
    let ultimoError = null;

    function registrarError(clave, mensaje) {
        if (ultimoError !== clave) console.error(mensaje);
        ultimoError = clave;
    }

    function mostrarSesionCaducada() {
        const titulo = $('#statusTitle');
        const sub = $('#statusSub');
        if (titulo) titulo.textContent = 'Sesión finalizada';
        if (sub) {
            sub.replaceChildren(document.createTextNode('Inicia sesión para reanudar el panel. '));
            const enlace = document.createElement('a');
            enlace.href = 'login.php';
            enlace.textContent = 'Iniciar sesión';
            sub.appendChild(enlace);
        }
    }

    async function refrescar() {
        if (document.visibilityState !== 'visible' || enVuelo) return;
        enVuelo = true;
        try {
            const r = await fetch('api/estado.php', { cache: 'no-store' });
            if (r.status === 401) {
                mostrarSesionCaducada();
                detener();
                return;
            }
            if (!r.ok) {
                registrarError('http-' + r.status, '[BeeStation] El refresco del dashboard respondió HTTP ' + r.status + '.');
                return;
            }
            const d = await r.json();
            if (!d || d.ok !== true || !d.lecturas || typeof d.lecturas !== 'object'
                || typeof d.online !== 'boolean' || typeof d.hora !== 'string' || !Array.isArray(d.alertas)
                || !['temp', 'hum', 'peso', 'sonido'].every((clave) => {
                    const l = d.lecturas[clave];
                    return l === null || (l && typeof l === 'object'
                        && (l.valor === null || (typeof l.valor === 'number' && Number.isFinite(l.valor)))
                        && typeof l.subida === 'string' && typeof l.relativo === 'string'
                        && typeof l.fresca === 'boolean');
                })
                || !d.alertas.every((a) => a && typeof a.tipo === 'string' && typeof a.mensaje === 'string'
                    && typeof a.subida === 'string' && typeof a.relativo === 'string'
                    && ['bg-critical', 'bg-warning', 'brand-dot'].includes(a.punto))) {
                registrarError('api-payload', '[BeeStation] La API de estado devolvió una respuesta incompleta.');
                return;
            }
            ultimoError = null;
            pintarLecturas(d);
            pintarAlertas(d);
            pintarEstado(d);
            pintarGrafico(d);
        } catch (e) {
            // No registrar el mensaje, que puede incluir detalles de red o respuesta.
            const tipo = e instanceof Error ? e.name : 'Error desconocido';
            registrarError('exception-' + tipo, '[BeeStation] Falló el refresco del dashboard (' + tipo + '); se reintentará.');
        } finally {
            enVuelo = false;
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
