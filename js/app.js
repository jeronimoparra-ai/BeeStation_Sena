window.BeeStationTheme = {
    css(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    },
    chartColors() {
        return {
            brand: this.css('--color-brand'),
            brandDark: this.css('--color-brand-dark'),
            brandAreaStrong: this.css('--chart-brand-area-strong'),
            brandAreaMedium: this.css('--chart-brand-area-medium'),
            brandAreaSoft: this.css('--chart-brand-area-soft'),
            success: this.css('--color-success'),
            warning: this.css('--color-warning'),
            critical: this.css('--color-critical'),
            muted: this.css('--color-text-tertiary'),
            text: this.css('--color-text'),
            textSecondary: this.css('--color-text-secondary'),
            grid: this.css('--color-chart-grid'),
            surface: this.css('--color-surface')
        };
    },
    applyChartDefaults() {
        if (!window.Chart) return;
        const colors = this.chartColors();
        Chart.defaults.color = colors.textSecondary;
        Chart.defaults.borderColor = colors.grid;
        Chart.defaults.font.family = 'Inter';
    }
};

window.BeeStationTheme.applyChartDefaults();

document.addEventListener('DOMContentLoaded', () => {
    // ── Initialize Lucide icons ──────────────────────────────────
    if (window.lucide) lucide.createIcons();

    // ── Clock ────────────────────────────────────────────────────
    const clockEl = document.getElementById('topbarClock');
    if (clockEl) {
        const updateClock = () => {
            const now = new Date();
            clockEl.textContent = now.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
        };
        updateClock();
        setInterval(updateClock, 1000);
    }

    // ── Mobile Menu Toggle ───────────────────────────────────────
    const mobileBtn = document.getElementById('mobileMenuBtn');
    const sidebar   = document.getElementById('sidebar');
    if (mobileBtn && sidebar) {
        mobileBtn.addEventListener('click', () => {
            sidebar.classList.toggle('open');
        });
        // Close on outside click
        document.addEventListener('click', (e) => {
            if (sidebar.classList.contains('open') && !sidebar.contains(e.target) && !mobileBtn.contains(e.target)) {
                sidebar.classList.remove('open');
            }
        });
    }

    // ── Active Section Name in Topbar ────────────────────────────
    const activeNav     = document.querySelector('.nav-item.active');
    const sectionNameEl = document.getElementById('currentSectionName');
    if (activeNav && sectionNameEl) {
        sectionNameEl.textContent = activeNav.getAttribute('data-name') || 'Sección';
    }

    // ── Dark Mode Toggle ─────────────────────────────────────────
    const darkBtn = document.getElementById('darkModeToggle') || document.getElementById('authDarkToggle');
    const html    = document.documentElement;

    const applyDark = (isDark) => {
        html.classList.toggle('dark', isDark);
        if (darkBtn) {
            darkBtn.innerHTML = isDark
                ? '<i data-lucide="sun"></i>'
                : '<i data-lucide="moon"></i>';
            if (window.lucide) lucide.createIcons();
        }
        window.BeeStationTheme.applyChartDefaults();
        refreshChartsForTheme();
    };

    // Restore saved preference
    const savedDark = localStorage.getItem('beestation-dark') === 'true';
    applyDark(savedDark);

    if (darkBtn) {
        darkBtn.addEventListener('click', () => {
            const isDark = !html.classList.contains('dark');
            applyDark(isDark);
            localStorage.setItem('beestation-dark', isDark);
        });
    }

    // ── Card Entrance Animations (Intersection Observer) ─────────
    const cards = document.querySelectorAll('.card, .stat-pill');
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry, i) => {
                if (entry.isIntersecting) {
                    entry.target.style.animationDelay = `${i * 0.04}s`;
                    entry.target.style.opacity = '';
                    entry.target.classList.add('animate-fadeUp');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.05 });

        cards.forEach(card => {
            if (!card.classList.contains('animate-fadeUp')) {
                card.style.opacity = '0';
            }
            observer.observe(card);
        });
    }

    // ── Count-Up Animation for metric values ─────────────────────
    const metricValues = document.querySelectorAll('[data-countup]');
    metricValues.forEach(el => {
        const target = parseFloat(el.getAttribute('data-countup'));
        const decimals = (el.getAttribute('data-decimals') || '0');
        const duration = 1200;
        const start = performance.now();

        const tick = (now) => {
            const elapsed = now - start;
            const progress = Math.min(elapsed / duration, 1);
            const ease = 1 - Math.pow(1 - progress, 3); // ease-out cubic
            el.textContent = (target * ease).toFixed(parseInt(decimals));
            if (progress < 1) requestAnimationFrame(tick);
        };

        requestAnimationFrame(tick);
    });

    // ── Progress bars from data attributes ───────────────────────
    document.querySelectorAll('[data-progress]').forEach(el => {
        const value = parseFloat(el.getAttribute('data-progress'));
        if (Number.isNaN(value)) return;
        el.style.width = `${Math.max(0, Math.min(100, value))}%`;
    });

    // ── Btn Ripple Effect ────────────────────────────────────────
    document.querySelectorAll('.btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            const ripple = document.createElement('span');
            const rect = this.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);
            const rippleColor = window.BeeStationTheme.css('--color-ripple');
            ripple.style.cssText = `
                position:absolute;
                width:${size}px;height:${size}px;
                top:${e.clientY - rect.top - size/2}px;
                left:${e.clientX - rect.left - size/2}px;
                border-radius:50%;
                background:${rippleColor};
                transform:scale(0);
                animation:ripple 0.5s ease-out forwards;
                pointer-events:none;
            `;
            this.style.position = 'relative';
            this.style.overflow = 'hidden';
            this.appendChild(ripple);
            setTimeout(() => ripple.remove(), 500);
        });
    });

    function refreshChartsForTheme() {
        if (!window.Chart) return;
        const colors = window.BeeStationTheme.chartColors();
        document.querySelectorAll('canvas').forEach((canvas) => {
            const chart = Chart.getChart(canvas);
            if (!chart) return;

            if (chart.options.plugins?.legend?.labels) {
                chart.options.plugins.legend.labels.color = colors.textSecondary;
            }

            Object.values(chart.options.scales || {}).forEach((scale) => {
                if (scale.ticks) scale.ticks.color = colors.textSecondary;
                if (scale.title) scale.title.color = colors.textSecondary;
                if (scale.grid) scale.grid.color = colors.grid;
            });

            chart.update('none');
        });
    }

    // ── Web Serial API (Dispositivos page) ───────────────────────
    const connectBtn = document.getElementById('btnConnectSerial');
    if (connectBtn) {
        connectBtn.addEventListener('click', async () => {
            if ('serial' in navigator) {
                try {
                    const port = await navigator.serial.requestPort();
                    await port.open({ baudRate: 115200 });
                    alert('¡Dispositivo conectado exitosamente!');
                } catch (err) {
                    console.error('Error al conectar:', err);
                }
            } else {
                alert('Tu navegador no soporta Web Serial API. Usa Chrome o Edge (v89+).');
            }
        });
    }
});
