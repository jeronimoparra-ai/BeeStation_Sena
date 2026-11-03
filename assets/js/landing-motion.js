/**
 * BeeStation Landing — Motion System v2.0 (Fase B.3)
 * Motor: GSAP 3.12.5 + ScrollTrigger
 * Concepto: Naturaleza, tecnología, precisión, fluidez y observación científica
 * Arquitectura: Modular, acelerado por hardware, compatible con accesibilidad (WCAG)
 */
(function () {
  'use strict';

  // ── Elementos clave que requieren visibilidad total inmediata en fallback/reduced-motion
  var ESSENTIAL_ELEMENTS = [
    '.bs-reveal',
    '.bs-sensor-item',
    '.bs-sensor-item__icon',
    '.bs-flow__node',
    '.bs-flow__connector',
    '.bs-colmena__mosaic-photo',
    '#bs-hero .bs-hero__tag',
    '#bs-hero .bs-hero__heading',
    '#bs-hero .bs-hero__description',
    '#bs-hero .bs-hero__actions > *',
    '#bs-hero .bs-hero__photo',
    '#bs-hero .bs-hero__badge',
    '#bs-hero .bs-hero__scroll',
    '#bs-tecnologia .bs-tech__bg-hex path'
  ].join(', ');

  // ── Utilidad: dejar todo visible sin animar (degradación real) ─
  function revealAll() {
    document.querySelectorAll(ESSENTIAL_ELEMENTS).forEach(function (el) {
      el.style.opacity = '1';
      el.style.transform = 'none';
      el.style.clipPath = 'none';
    });
    // Sin este marcador el CSS no oculta nada (contenido visible por defecto).
    document.documentElement.classList.remove('bs-motion-ready');
  }

  // ── Detección de prefers-reduced-motion ──────────────────
  var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ── Guard: GSAP no disponible ──────────────────────────────
  if (typeof gsap === 'undefined') {
    console.warn('[BeeStation Motion] GSAP no disponible. Revelando contenido estático.');
    revealAll();
    // La navegación y el menú móvil son funcionales sin motor de animación.
    initNav();
    initMobileMenu();
    return;
  }

  // ── Guard: sin ScrollTrigger (o con movimiento reducido) no se oculta nada ─
  if (prefersReducedMotion || typeof ScrollTrigger === 'undefined') {
    if (typeof ScrollTrigger === 'undefined') {
      console.warn('[BeeStation Motion] ScrollTrigger no disponible. Revelando contenido estático.');
    }
    revealAll();
    // Solo inicializar navegación y menú móvil esenciales
    initNav();
    initMobileMenu();
    return;
  }

  // ── Motor confirmado: solo ahora se permite el estado oculto ─
  gsap.registerPlugin(ScrollTrigger);
  document.documentElement.classList.add('bs-motion-ready');

  // ── matchMedia para responsive ───────────────────────────
  var mm = gsap.matchMedia();

  // ──────────────────────────────────────────────────────────
  // 1. HERO CINEMATOGRÁFICO (Entrada coordinada y Parallax)
  // ──────────────────────────────────────────────────────────
  function initHero() {
    var hero = document.getElementById('bs-hero');
    if (!hero) return;

    var tl = gsap.timeline({ delay: 0.08 });

    // Foto hero: revelado suave con sutil scale-down (sensación cinematográfica)
    tl.fromTo('#bs-hero .bs-hero__photo',
      { opacity: 0, scale: 1.08 },
      { opacity: 0.55, scale: 1, duration: 1.4, ease: 'power2.out' },
      0
    );

    // Tag superior con línea ámbar
    tl.from('#bs-hero .bs-hero__tag', {
      opacity: 0,
      y: 18,
      duration: 0.6,
      ease: 'power3.out'
    }, 0.15);

    // Titular editorial
    tl.from('#bs-hero .bs-hero__heading', {
      opacity: 0,
      y: 38,
      duration: 0.85,
      ease: 'power4.out'
    }, 0.28);

    // Descripción introductoria
    tl.from('#bs-hero .bs-hero__description', {
      opacity: 0,
      y: 22,
      duration: 0.65,
      ease: 'power3.out'
    }, 0.45);

    // Botones de acción coordinados con stagger
    tl.from('#bs-hero .bs-hero__actions > *', {
      opacity: 0,
      y: 18,
      duration: 0.6,
      ease: 'power3.out',
      stagger: 0.1
    }, 0.6);

    // Badge de tecnología y naturaleza (con micro-escala suave)
    tl.from('#bs-hero .bs-hero__badge', {
      opacity: 0,
      y: 20,
      scale: 0.92,
      duration: 0.7,
      ease: 'back.out(1.2)'
    }, 0.75);

    // Micro-rotación sutil en el hexágono del badge
    tl.from('#bs-hero .bs-hero__badge-icon svg', {
      rotation: -30,
      opacity: 0,
      duration: 0.6,
      ease: 'power2.out'
    }, 0.85);

    // Indicador scroll
    tl.from('#bs-hero .bs-hero__scroll', {
      opacity: 0,
      duration: 0.6
    }, 0.95);

    // Parallax suave del Hero (solo en desktop/laptop)
    mm.add('(min-width: 1024px)', function () {
      gsap.to('#bs-hero .bs-hero__photo', {
        yPercent: 12,
        ease: 'none',
        scrollTrigger: {
          trigger: '#bs-hero',
          start: 'top top',
          end: 'bottom top',
          scrub: 1.2
        }
      });
    });
  }

  // ──────────────────────────────────────────────────────────
  // 2. NAVEGACIÓN al scroll + sección activa
  // ──────────────────────────────────────────────────────────
  function initNav() {
    var nav = document.getElementById('bs-nav');
    if (!nav) return;

    // Indicador de sección activa (funciona con o sin GSAP).
    initNavScrollSpy();

    // Entrada discreta del logotipo, solo si hay motor de animación.
    if (typeof gsap !== 'undefined' && !prefersReducedMotion) {
      gsap.from('.bs-nav__logo-mark', { opacity: 0, scale: .72, rotate: -14, duration: .9, ease: 'back.out(1.7)', delay: .05, clearProps: 'all' });
      gsap.from('.bs-nav__brand > *', { opacity: 0, y: 8, duration: .55, stagger: .09, ease: 'power2.out', delay: .18, clearProps: 'all' });
    }

    // ScrollTrigger sin GSAP queda indefinido a efectos prácticos: se exigen ambos.
    if (typeof ScrollTrigger !== 'undefined' && typeof gsap !== 'undefined') {
      ScrollTrigger.create({
        start: 'top -50px',
        onEnter: function () { nav.classList.add('bs-nav--scrolled'); },
        onLeaveBack: function () { nav.classList.remove('bs-nav--scrolled'); }
      });
      return;
    }

    // Fallback sin ScrollTrigger: mismo efecto con un listener pasivo.
    var actualizarNav = function () {
      if (window.scrollY > 50) nav.classList.add('bs-nav--scrolled');
      else nav.classList.remove('bs-nav--scrolled');
    };
    window.addEventListener('scroll', actualizarNav, { passive: true });
    actualizarNav();
  }

  // ── Sección activa: marca el enlace correspondiente según el scroll ──
  function initNavScrollSpy() {
    var enlaces = Array.prototype.slice.call(
      document.querySelectorAll('.bs-nav__links a[data-section], .bs-nav__mobile a[data-section]')
    );
    if (!enlaces.length) return;

    // Secciones únicas en orden de documento (evita duplicados escritorio/móvil).
    var secciones = [];
    enlaces.forEach(function (enlace) {
      var id = enlace.getAttribute('data-section');
      var el = id ? document.getElementById(id) : null;
      if (el && secciones.indexOf(el) === -1) secciones.push(el);
    });
    if (!secciones.length) return;

    var activa = null;
    var pendiente = false;

    function marcar(id) {
      if (id === activa) return;
      activa = id;
      enlaces.forEach(function (enlace) {
        var on = enlace.getAttribute('data-section') === id;
        enlace.classList.toggle('is-active', on);
        if (on) enlace.setAttribute('aria-current', 'true');
        else enlace.removeAttribute('aria-current');
      });
    }

    function calcular() {
      pendiente = false;
      var referencia = window.innerHeight * 0.35;
      var actual = null;
      secciones.forEach(function (seccion) {
        if (seccion.getBoundingClientRect().top <= referencia) actual = seccion.id;
      });
      marcar(actual);
    }

    function alDesplazar() {
      if (pendiente) return;
      pendiente = true;
      window.requestAnimationFrame(calcular);
    }

    window.addEventListener('scroll', alDesplazar, { passive: true });
    window.addEventListener('resize', alDesplazar, { passive: true });
    calcular();
  }

  // ──────────────────────────────────────────────────────────
  // 3. REVELADO GENERAL con ScrollTrigger
  // ──────────────────────────────────────────────────────────
  function initScrollReveal() {
    var reveals = document.querySelectorAll('.bs-reveal');

    reveals.forEach(function (el) {
      gsap.fromTo(el,
        { opacity: 0, y: 36 },
        {
          opacity: 1,
          y: 0,
          duration: 0.85,
          ease: 'power3.out',
          scrollTrigger: {
            trigger: el,
            start: 'top 88%',
            once: true
          }
        }
      );
    });
  }

  // ──────────────────────────────────────────────────────────
  // 4. SECCIÓN PROPÓSITO — Revelado de imágenes y profundidad
  // ──────────────────────────────────────────────────────────
  function initProposito() {
    var section = document.getElementById('bs-proposito');
    if (!section) return;

    // Foto principal con clip-path suave
    gsap.from('#bs-proposito .bs-proposito__photo-main', {
      clipPath: 'inset(8% 0% 8% 0% round 24px)',
      opacity: 0,
      duration: 1.1,
      ease: 'power4.out',
      scrollTrigger: {
        trigger: '#bs-proposito .bs-proposito__photos',
        start: 'top 82%',
        once: true
      }
    });

    // Foto acento flotante
    gsap.from('#bs-proposito .bs-proposito__photo-accent', {
      opacity: 0,
      scale: 0.88,
      x: 24,
      duration: 0.9,
      ease: 'back.out(1.3)',
      delay: 0.25,
      scrollTrigger: {
        trigger: '#bs-proposito .bs-proposito__photos',
        start: 'top 82%',
        once: true
      }
    });

    // Profundidad entre capas (Parallax diferencial en desktop)
    mm.add('(min-width: 1024px)', function () {
      gsap.to('#bs-proposito .bs-proposito__photo-accent', {
        y: -30,
        ease: 'none',
        scrollTrigger: {
          trigger: '#bs-proposito',
          start: 'top bottom',
          end: 'bottom top',
          scrub: 1.2
        }
      });
    });
  }

  // ──────────────────────────────────────────────────────────
  // 5. MOSAICO COLMENA — Aparición escalonada y zoom
  // ──────────────────────────────────────────────────────────
  function initColmena() {
    if (!document.querySelector('#bs-colmena')) return;

    var photos = document.querySelectorAll('#bs-colmena .bs-colmena__mosaic-photo');

    gsap.from(photos, {
      opacity: 0,
      y: 35,
      scale: 0.95,
      duration: 0.85,
      ease: 'power3.out',
      stagger: { each: 0.14, from: 'start' },
      scrollTrigger: {
        trigger: '#bs-colmena .bs-colmena__mosaic',
        start: 'top 82%',
        once: true
      }
    });
  }

  // ──────────────────────────────────────────────────────────
  // 6. DIAGRAMA DE FLUJO NARRATIVO (Sensores → ESP32 → Plataforma web)
  // ──────────────────────────────────────────────────────────
  function initFlowDiagram() {
    var flow = document.querySelector('.bs-flow');
    if (!flow) return;

    var nodes = document.querySelectorAll('.bs-flow__node');
    var connectors = document.querySelectorAll('.bs-flow__connector');

    if (nodes.length < 3) return;

    // Timeline secuencial que narra el viaje telemétrico
    var flowTl = gsap.timeline({
      scrollTrigger: {
        trigger: flow,
        start: 'top 80%',
        once: true
      }
    });

    // 1. Nodo Sensores
    flowTl.from(nodes[0], {
      opacity: 0,
      y: 40,
      scale: 0.92,
      duration: 0.65,
      ease: 'back.out(1.2)'
    });

    // 2. Conector 1 -> 2
    if (connectors[0]) {
      flowTl.from(connectors[0], {
        opacity: 0,
        scaleX: 0,
        transformOrigin: 'left center',
        duration: 0.45,
        ease: 'power2.out'
      }, '-=0.2');
    }

    // 3. Nodo ESP32
    flowTl.from(nodes[1], {
      opacity: 0,
      y: 40,
      scale: 0.92,
      duration: 0.65,
      ease: 'back.out(1.2)'
    }, '-=0.15');

    // 4. Conector 2 -> 3
    if (connectors[1]) {
      flowTl.from(connectors[1], {
        opacity: 0,
        scaleX: 0,
        transformOrigin: 'left center',
        duration: 0.45,
        ease: 'power2.out'
      }, '-=0.2');
    }

    // 5. Nodo Plataforma Web
    flowTl.from(nodes[2], {
      opacity: 0,
      y: 40,
      scale: 0.92,
      duration: 0.65,
      ease: 'back.out(1.2)'
    }, '-=0.15');
  }

  // ──────────────────────────────────────────────────────────
  // 7. SENSORES — Entrada coordinada e iconografía viva
  // ──────────────────────────────────────────────────────────
  function initSensors() {
    var sensors = document.querySelectorAll('.bs-sensor-item');
    if (!sensors.length) return;

    var sensorsTl = gsap.timeline({
      scrollTrigger: {
        trigger: '.bs-sensors',
        start: 'top 85%',
        once: true
      }
    });

    // Entrada escalonada de las tarjetas
    sensorsTl.from(sensors, {
      opacity: 0,
      y: 26,
      duration: 0.55,
      ease: 'power3.out',
      stagger: { each: 0.08 }
    });

    // Micro-animación de los iconos SVG interiores (precisión instrumental)
    var icons = document.querySelectorAll('.bs-sensor-item__icon svg');
    if (icons.length) {
      sensorsTl.from(icons, {
        scale: 0.75,
        rotation: -8,
        duration: 0.45,
        ease: 'back.out(1.4)',
        stagger: { each: 0.08 }
      }, 0.15);
    }
  }

  // ──────────────────────────────────────────────────────────
  // 8. SISTEMA HEXAGONAL Y FOTO HARDWARE
  // ──────────────────────────────────────────────────────────
  function initHexagonsAndTech() {
    var tech = document.getElementById('bs-tecnologia');
    if (!tech) return;

    // Hexágono de fondo: trazo y aparición progresiva
    var hexPaths = tech.querySelectorAll('.bs-tech__bg-hex path');
    if (hexPaths.length) {
      gsap.from(hexPaths, {
        opacity: 0,
        scale: 0.9,
        transformOrigin: 'center center',
        duration: 1.1,
        stagger: 0.15,
        ease: 'power2.out',
        scrollTrigger: {
          trigger: tech,
          start: 'top 75%',
          once: true
        }
      });
    }

    // Parallax suave en foto tecnología (solo desktop)
    mm.add('(min-width: 1025px)', function () {
      gsap.to('#bs-tecnologia .bs-tech__photo', {
        y: -30,
        ease: 'none',
        scrollTrigger: {
          trigger: '#bs-tecnologia',
          start: 'top bottom',
          end: 'bottom top',
          scrub: 1.3
        }
      });

      if (hexPaths.length) {
        gsap.to(hexPaths, {
          rotation: 6,
          transformOrigin: 'center center',
          ease: 'none',
          scrollTrigger: {
            trigger: '#bs-tecnologia',
            start: 'top bottom',
            end: 'bottom top',
            scrub: 2
          }
        });
      }
    });
  }

  // ──────────────────────────────────────────────────────────
  // 9. CTA CIERRE — Zoom fotográfico sutil
  // ──────────────────────────────────────────────────────────
  function initCtaParallax() {
    if (!document.querySelector('#bs-cta .bs-cta__bg')) return;

    mm.add('(min-width: 769px)', function () {
      gsap.fromTo('#bs-cta .bs-cta__bg',
        { scale: 1.08 },
        {
          scale: 1,
          ease: 'none',
          scrollTrigger: {
            trigger: '#bs-cta',
            start: 'top bottom',
            end: 'bottom top',
            scrub: 2
          }
        }
      );
    });
  }

  // ──────────────────────────────────────────────────────────
  // 10. MENÚ HAMBURGUESA (JS puro — accesible y fluido)
  // ──────────────────────────────────────────────────────────
  function initMobileMenu() {
    var btn = document.getElementById('bs-hamburger');
    var menu = document.getElementById('bs-mobile-menu');
    if (!btn || !menu) return;

    btn.addEventListener('click', function () {
      var open = btn.classList.toggle('is-open');
      menu.classList.toggle('is-open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    // Cerrar al hacer click en cualquier enlace interno
    menu.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        btn.classList.remove('is-open');
        menu.classList.remove('is-open');
        btn.setAttribute('aria-expanded', 'false');
      });
    });

    // Cerrar con tecla Escape
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && menu.classList.contains('is-open')) {
        btn.classList.remove('is-open');
        menu.classList.remove('is-open');
        btn.setAttribute('aria-expanded', 'false');
        btn.focus();
      }
    });
  }

  // ──────────────────────────────────────────────────────────
  // INIT — Orquestación al cargar el DOM
  // ──────────────────────────────────────────────────────────
  function init() {
    try {
      initNav();
      initHero();
      initScrollReveal();
      initProposito();
      initColmena();
      initFlowDiagram();
      initSensors();
      initHexagonsAndTech();
      initCtaParallax();
      initMobileMenu();
    } catch (error) {
      // Ante cualquier fallo de inicialización, el contenido nunca queda oculto.
      console.warn('[BeeStation Motion] Error de inicialización. Revelando contenido estático.', error);
      revealAll();
    }

    // Refrescar ScrollTrigger tras carga de recursos multimedia
    window.addEventListener('load', function () {
      if (typeof ScrollTrigger !== 'undefined') {
        ScrollTrigger.refresh();
      }
      // Red de seguridad: si algo quedó oculto dentro del viewport, se muestra.
      window.setTimeout(asegurarVisibilidadEnViewport, 1200);
    });
  }

  // Red de seguridad de degradación progresiva: solo actúa sobre contenido
  // que ya debería estar visible (dentro del viewport) y sigue con opacity 0.
  function asegurarVisibilidadEnViewport() {
    var alto = window.innerHeight || document.documentElement.clientHeight;
    document.querySelectorAll('.bs-reveal').forEach(function (el) {
      var caja = el.getBoundingClientRect();
      if (caja.top < alto && caja.bottom > 0 && window.getComputedStyle(el).opacity === '0') {
        el.style.opacity = '1';
        el.style.transform = 'none';
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
