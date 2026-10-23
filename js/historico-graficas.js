(function () {
    'use strict';

    const configuraciones = [
        {
            tipo: 'temperatura_interna',
            selector: '#tempPeriod',
            chart: () => window.tempChart,
            shell: '#tempChartShell',
            empty: '#tempChartEmpty',
            table: '#tempChartTable',
            title: '#tempChartTitle',
            note: '#tempChartNote',
            etiqueta: 'temperatura interna',
            refrescar: true
        },
        {
            tipo: 'sonido',
            selector: '#acousticPeriod',
            chart: () => window.acousticChart,
            shell: '#acousticChartShell',
            empty: '#acousticChartEmpty',
            table: '#acousticChartTable',
            title: '#acousticChartTitle',
            note: '#acousticChartNote',
            etiqueta: 'actividad acústica',
            refrescar: true
        },
        {
            tipo: 'peso',
            selector: null,
            horasFijas: 720,
            chart: () => window.weightChart,
            shell: '#weightChartShell',
            empty: '#weightChartEmpty',
            table: '#weightChartTable',
            title: '#weightChartTitle',
            note: '#weightChartNote',
            etiqueta: 'peso',
            refrescar: true
        }
    ];

    function horasTexto(horas) {
        return Number(horas) === 168 ? '7 días'
            : (Number(horas) === 720 ? '30 días' : horas + ' horas');
    }

    function mostrarEstado(config, mensaje) {
        const empty = document.querySelector(config.empty);
        const shell = document.querySelector(config.shell);
        const details = document.querySelector(config.table);
        if (empty) {
            const text = empty.querySelector('.empty-desc');
            if (text) text.textContent = mensaje;
            empty.style.display = '';
        }
        if (shell) shell.style.display = 'none';
        if (details) details.style.display = 'none';
    }

    function textoCubo(minutos) {
        if (minutos >= 1440) return 'Promedio diario';
        if (minutos >= 60 && minutos % 60 === 0) return 'Promedio cada ' + (minutos / 60) + ' h';
        return 'Promedio cada ' + minutos + ' min';
    }

    function actualizarGrafica(config, payload, horas) {
        if (!payload || payload.ok !== true || !Array.isArray(payload.labels)
            || !Array.isArray(payload.data) || !Array.isArray(payload.muestras)
            || !Array.isArray(payload.ultima_muestra)
            || payload.labels.length !== payload.data.length || payload.data.length !== payload.muestras.length
            || payload.data.length !== payload.ultima_muestra.length
            || !Number.isInteger(payload.bucket_min) || payload.bucket_min < 1
            || !payload.labels.every((label) => typeof label === 'string')
            || !payload.data.every((value) => typeof value === 'number' && Number.isFinite(value))
            || !payload.muestras.every((value) => Number.isInteger(value) && value >= 0)
            || !payload.ultima_muestra.every((value) => typeof value === 'string')) {
            throw new TypeError('Respuesta de serie inválida');
        }

        const chart = config.chart();
        if (!chart || !chart.data.datasets[0]) throw new Error('Gráfica no inicializada');
        chart.data.labels = payload.labels;
        chart.data.datasets[0].data = payload.data;
        chart.data.datasets[0].ultimaMuestra = payload.ultima_muestra;
        chart.options.scales.x.offset = payload.data.length === 1;
        chart.update('none');

        const shell = document.querySelector(config.shell);
        const empty = document.querySelector(config.empty);
        const title = document.querySelector(config.title);
        const note = document.querySelector(config.note);
        const details = document.querySelector(config.table);
        const tbody = details && details.querySelector('tbody');
        if (tbody) {
            const rows = payload.labels.map((label, index) => {
                const row = document.createElement('tr');
                const date = document.createElement('td');
                const value = document.createElement('td');
                date.textContent = label;
                if (config.tipo === 'peso') {
                    const lastSample = document.createElement('td');
                    const sampleCount = document.createElement('td');
                    lastSample.textContent = payload.ultima_muestra[index];
                    sampleCount.textContent = String(payload.muestras[index]);
                    value.textContent = payload.data[index].toFixed(2) + ' kg (promedio diario)';
                    row.append(date, lastSample, value, sampleCount);
                } else {
                    value.textContent = payload.data[index].toFixed(config.tipo === 'sonido' ? 0 : 1)
                        + (config.tipo === 'sonido' ? ' Hz' : ' °C');
                    row.append(date, value);
                    const samples = document.createElement('td');
                    samples.textContent = String(payload.muestras[index]);
                    row.appendChild(samples);
                }
                return row;
            });
            tbody.replaceChildren(...rows);
        }
        if (details) details.style.display = payload.data.length > 0 ? '' : 'none';
        if (title) title.textContent = config.tipo === 'sonido'
            ? 'Frecuencia estimada · ' + horasTexto(horas)
            : (config.tipo === 'peso' ? 'Peso · últimos 30 días' : 'Temperatura interna · ' + horasTexto(horas));
        if (note) note.textContent = config.tipo === 'peso'
            ? 'Cada punto es un promedio diario; la hora corresponde a la última muestra válida del día.'
            : textoCubo(payload.bucket_min) + ' · solo lecturas válidas';

        if (payload.data.length > 0) {
            if (shell) shell.style.display = '';
            if (empty) empty.style.display = 'none';
            chart.resize();
            return;
        }

        let message = payload.error === 'sin_colmena'
            ? 'No hay una colmena activa para consultar.'
            : 'Sin lecturas válidas de ' + config.etiqueta + ' en el período de ' + horasTexto(horas) + '.';
        if (payload.ultima_lectura && payload.ultima_lectura.fuera_periodo) {
            message += ' La lectura válida más reciente es del ' + payload.ultima_lectura.fecha + '; amplía el período para consultarla.';
        }
        if (payload.ultima_invalida) {
            message += ' Hay una lectura inválida del ' + payload.ultima_invalida.fecha + ' que no se incluye en la gráfica.';
        } else if (!payload.ultima_lectura && !payload.ultima_invalida && payload.error !== 'sin_colmena') {
            message += ' No hay lecturas registradas para esta colmena.';
        }
        mostrarEstado(config, message);
    }

    configuraciones.forEach((config) => {
        const select = config.selector ? document.querySelector(config.selector) : null;
        if (config.selector && !select) return;

        let secuencia = 0;
        let controller = null;
        let debounce = null;
        let timer = null;
        let enVuelo = false;
        let ultimoError = null;
        let detenerPorSesion = false;

        async function consultar() {
            if (detenerPorSesion) return;
            const horas = select ? select.value : String(config.horasFijas);
            if (controller) controller.abort();
            controller = new AbortController();
            const miSecuencia = ++secuencia;
            enVuelo = true;
            try {
                const query = new URLSearchParams({ tipo: config.tipo, horas });
                const response = await fetch('api/serie.php?' + query.toString(), {
                    cache: 'no-store',
                    credentials: 'same-origin',
                    signal: controller.signal,
                    headers: { Accept: 'application/json' }
                });
                if (response.status === 401) {
                    detenerPorSesion = true;
                    mostrarEstado(config, 'La sesión expiró. Inicia sesión de nuevo para consultar el histórico.');
                    if (config.tipo === 'temperatura_interna') {
                        const title = document.querySelector('#statusTitle');
                        if (title) title.textContent = 'Sesión finalizada';
                    }
                    return;
                }
                if (!response.ok) throw new Error('HTTP ' + response.status);
                const payload = await response.json();
                if (miSecuencia !== secuencia) return;
                actualizarGrafica(config, payload, horas);
                ultimoError = null;
            } catch (error) {
                if (error.name === 'AbortError') return;
                const clave = error instanceof Error ? error.name + ':' + error.message : 'error';
                if (ultimoError !== clave) console.error('[BeeStation] No se pudo actualizar la serie histórica (' + (error.name || 'Error') + ').');
                ultimoError = clave;
                if (miSecuencia === secuencia) {
                    mostrarEstado(config, 'No se pudo consultar el período seleccionado. Se reintentará automáticamente.');
                }
            } finally {
                if (miSecuencia === secuencia) {
                    controller = null;
                    enVuelo = false;
                }
            }
        }

        if (select) {
            select.addEventListener('change', () => {
                secuencia += 1;
                if (debounce) window.clearTimeout(debounce);
                if (controller) controller.abort();
                debounce = window.setTimeout(consultar, 180);
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', consultar, { once: true });
        } else {
            window.setTimeout(consultar, 0);
        }

        if (config.refrescar) {
            const programar = () => {
                if (detenerPorSesion) return;
                timer = window.setTimeout(async () => {
                    const periodoPredeterminado = config.tipo === 'temperatura_interna' && select && select.value === '24';
                    if (document.visibilityState === 'visible' && !enVuelo && !periodoPredeterminado) await consultar();
                    programar();
                }, 30000);
            };
            programar();
            document.addEventListener('visibilitychange', () => {
                const periodoPredeterminado = config.tipo === 'temperatura_interna' && select && select.value === '24';
                if (document.visibilityState === 'visible' && !enVuelo && !periodoPredeterminado) consultar();
            });
        }
    });
})();
