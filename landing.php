<?php
/**
 * landing.php
 * ─────────────────────────────────────────────────────────────
 * Página pública de presentación de BeeStation.
 * No modifica la autenticación ni el login: solo lee la sesión para
 * redirigir a quien ya tiene sesión activa.
 * Flujo: VISITANTE → LANDING → login.php → dashboard.php
 * ─────────────────────────────────────────────────────────────
 */

// Sesión: mismos parámetros endurecidos que includes/auth.php.
// Solo se lee SESSION para redirigir; no se autentica ni se altera el login.
// Si ya hay una sesión activa no se vuelve a iniciar.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
if (!empty($_SESSION['id_usuario'])) {
    header('Location: dashboard.php');
    exit;
}

// Año del copyright en la zona horaria del proyecto, sin tocar la configuración global.
$anio = (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('Y');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BeeStation — Tecnología para observar, comprender y cuidar mejor las colmenas</title>
  <meta name="description" content="BeeStation conecta la apicultura con la observación tecnológica, facilitando el seguimiento de variables de las colmenas mediante sensores y una plataforma digital. Proyecto SENA, El Bagre, Antioquia.">
  <meta name="robots" content="index, follow">

  <!-- Open Graph -->
  <meta property="og:title" content="BeeStation — Tecnología para el cuidado de colmenas">
  <meta property="og:description" content="Plataforma IoT de monitoreo apícola desarrollada en el SENA, Centro Minero Ambiental, El Bagre, Antioquia.">
  <meta property="og:type" content="website">

  <!-- Favicon -->
  <link rel="icon" href="assets/landing/favicon.svg" type="image/svg+xml">

  <!-- Preload imagen LCP -->
  <link rel="preload" as="image" href="assets/landing/apicultor-hero.webp" fetchpriority="high">

  <!-- Preload de fuentes críticas (locales, sin peticiones externas) -->
  <link rel="preload" href="assets/fonts/bricolage-grotesque-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="assets/fonts/manrope-latin.woff2" as="font" type="font/woff2" crossorigin>

  <!-- Estilos de la landing (aislados) -->
  <link rel="stylesheet" href="assets/css/landing.css">

  <!-- Sin JavaScript todo debe seguir visible: refuerzo de degradación progresiva -->
  <noscript>
    <style>
      .bs-reveal, .bs-sensor-item, .bs-flow__node, .bs-flow__connector,
      .bs-colmena__mosaic-photo { opacity: 1 !important; transform: none !important; }
    </style>
  </noscript>
</head>
<body class="landing-page">

<!-- ════════════════════════════════════════════════════
     NAVEGACIÓN
     ════════════════════════════════════════════════════ -->
<header id="bs-nav" role="banner">
  <div class="bs-nav__inner">

    <!-- Logo -->
    <a href="landing.php" class="bs-nav__logo" aria-label="BeeStation inicio">
      <svg class="bs-nav__logo-hex" viewBox="0 0 38 38" fill="none" aria-hidden="true">
        <path d="M19 2 L35 11 L35 27 L19 36 L3 27 L3 11 Z" fill="none" stroke="#F59E0B" stroke-width="1.5"/>
        <path d="M19 8 L30 14 L30 24 L19 30 L8 24 L8 14 Z" fill="rgba(245,158,11,0.08)" stroke="#F59E0B" stroke-width="1"/>
        <circle cx="19" cy="19" r="4" fill="#F59E0B" opacity=".9"/>
      </svg>
      <div>
        <span class="bs-nav__brand-name">BeeStation</span>
        <span class="bs-nav__brand-sub">Observa · Cuida · Conecta</span>
      </div>
    </a>

    <!-- Links escritorio -->
    <nav aria-label="Navegación principal">
      <ul class="bs-nav__links">
        <li><a href="#bs-proposito">Explorar</a></li>
        <li><a href="#bs-como-funciona">Tecnología</a></li>
        <li><a href="#bs-tecnologia">Sensores</a></li>
        <li><a href="#bs-territorio">Nuestro propósito</a></li>
      </ul>
    </nav>

    <!-- Acciones escritorio -->
    <div class="bs-nav__actions">
      <a href="registro.php" class="bs-btn-ghost-nav">Crear cuenta</a>
      <a href="login.php" class="bs-btn-amber-nav">Iniciar sesión</a>
    </div>

    <!-- Hamburger móvil -->
    <button id="bs-hamburger" class="bs-nav__hamburger" aria-label="Abrir menú" aria-expanded="false" aria-controls="bs-mobile-menu">
      <span></span><span></span><span></span>
    </button>
  </div>
</header>

<!-- Menú móvil -->
<div id="bs-mobile-menu" class="bs-nav__mobile" role="dialog" aria-label="Menú de navegación móvil">
  <ul>
    <li><a href="#bs-proposito">Explorar</a></li>
    <li><a href="#bs-como-funciona">Tecnología</a></li>
    <li><a href="#bs-tecnologia">Sensores</a></li>
    <li><a href="#bs-territorio">Nuestro propósito</a></li>
  </ul>
  <div class="bs-nav__mobile-actions">
    <a href="registro.php" class="bs-btn-ghost-full">Crear cuenta</a>
    <a href="login.php" class="bs-btn-amber-full">Iniciar sesión</a>
  </div>
</div>

<main id="bs-main">

<!-- ════════════════════════════════════════════════════
     HERO
     ════════════════════════════════════════════════════ -->
<section id="bs-hero" aria-labelledby="bs-hero-title">

  <!-- Fotografía de fondo -->
  <div class="bs-hero__bg" aria-hidden="true">
    <img
      class="bs-hero__photo"
      src="assets/landing/apicultor-hero.webp"
      alt=""
      width="1920"
      height="1080"
      fetchpriority="high"
    >
  </div>

  <!-- Contenido -->
  <div class="bs-hero__content">
    <div class="bs-container">
      <div class="bs-hero__inner">

        <p class="bs-tag bs-hero__tag">BeeStation</p>

        <h1 id="bs-hero-title" class="bs-hero__heading">
          Cada colmena<br>
          tiene una historia<br>
          que merece ser<br>
          <em>escuchada.</em>
        </h1>

        <p class="bs-hero__description">
          Entre el trabajo del apicultor y el comportamiento de las abejas
          existen señales que vale la pena observar. BeeStation conecta
          sensores, tecnología y conocimiento apícola para conocer mejor
          tus colmenas.
        </p>

        <div class="bs-hero__actions">
          <a href="login.php" id="bs-cta-hero-primary" class="bs-btn-primary">
            Ingresar a BeeStation
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
              <path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </a>
          <a href="#bs-como-funciona" id="bs-cta-hero-secondary" class="bs-btn-outline">
            Cómo funciona
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
              <path d="M7 2v10M2 7l5 5 5-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </a>
        </div>

      </div><!-- /.bs-hero__inner -->
    </div><!-- /.bs-container -->
  </div><!-- /.bs-hero__content -->

  <!-- Badge tecnología -->
  <div class="bs-hero__badge" aria-hidden="true">
    <div class="bs-hero__badge-icon">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true">
        <polygon points="12,2 21,7.2 21,16.8 12,22 3,16.8 3,7.2"/>
        <circle cx="12" cy="12" r="2.8" fill="currentColor" fill-opacity="0.35"/>
      </svg>
    </div>
    <div class="bs-hero__badge-text">
      Tecnología al servicio
      <strong>de la naturaleza</strong>
    </div>
  </div>

  <!-- Scroll indicator -->
  <div class="bs-hero__scroll" aria-hidden="true">
    <div class="bs-hero__scroll-line"></div>
    Explorar
  </div>

</section>
<!-- /HERO -->


<!-- ════════════════════════════════════════════════════
     PROPÓSITO
     ════════════════════════════════════════════════════ -->
<section id="bs-proposito" aria-labelledby="bs-proposito-title">
  <div class="bs-container">
    <div class="bs-proposito__grid">

      <!-- Fotografías -->
      <div class="bs-proposito__photos">
        <img
          class="bs-proposito__photo-main"
          src="assets/landing/inspeccion-panal.webp"
          alt="Apicultor inspeccionando un marco de panal con abejas"
          width="700"
          height="875"
          loading="lazy"
        >
        <img
          class="bs-proposito__photo-accent"
          src="assets/landing/abejas-macro.webp"
          alt="Abejas en detalle sobre el panal"
          width="385"
          height="385"
          loading="lazy"
        >
      </div>

      <!-- Texto -->
      <div class="bs-proposito__content">
        <p class="bs-tag bs-proposito__tag bs-reveal">La apicultura, en el centro</p>
        <h2 id="bs-proposito-title" class="bs-proposito__heading bs-reveal">
          Antes de cada dato,<br>existe una colmena viva.
        </h2>
        <p class="bs-proposito__body bs-reveal">
          El trabajo apícola requiere observar, comprender y acompañar los cambios
          que ocurren en cada colonia. Conocer la temperatura, la humedad, el peso
          y la actividad sonora de una colmena puede ayudar al apicultor a tomar
          decisiones más informadas.
        </p>
        <p class="bs-proposito__body bs-reveal">
          BeeStation aporta información complementaria para cuidar y fortalecer
          tus apiarios, sin reemplazar la experiencia y el criterio del apicultor.
        </p>
        <a href="#bs-como-funciona" class="bs-proposito__link bs-reveal">
          Saber más
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </a>
      </div>

    </div>
  </div>
</section>
<!-- /PROPÓSITO -->


<!-- ════════════════════════════════════════════════════
     VIDA EN LA COLMENA
     ════════════════════════════════════════════════════ -->
<section id="bs-colmena" aria-labelledby="bs-colmena-title">
  <div class="bs-container">
    <div class="bs-colmena__inner">

      <!-- Texto -->
      <div class="bs-colmena__text">
        <p class="bs-tag bs-colmena__tag bs-reveal">Un mundo pequeño, un equilibrio extraordinario</p>
        <h2 id="bs-colmena-title" class="bs-colmena__heading bs-reveal">
          La vida dentro<br>de una colmena.
        </h2>
        <p class="bs-colmena__body bs-reveal">
          Abejas, panales y un ecosistema en equilibrio. Las colmenas son sistemas
          vivos que responden constantemente a su entorno: la temperatura exterior,
          la humedad, la disponibilidad de flores, los cambios de estación.
        </p>
        <p class="bs-colmena__body bs-reveal">
          Conocer sus condiciones internas nos ayuda a proteger su bienestar
          y el de nuestro entorno. BeeStation facilita el acceso a esa información
          de forma continua y organizada.
        </p>
        <a href="#bs-tecnologia" class="bs-colmena__link bs-reveal">
          Conocer la tecnología
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </a>
      </div>

      <!-- Mosaico fotográfico -->
      <div class="bs-colmena__mosaic" aria-hidden="true">
        <div class="bs-colmena__mosaic-photo bs-colmena__mosaic-photo--tall bs-colmena__mosaic-photo--a">
          <img
            src="assets/landing/abejas-macro.webp"
            alt="Abejas en detalle sobre panal de cera"
            width="400"
            height="530"
            loading="lazy"
          >
        </div>
        <div class="bs-colmena__mosaic-photo bs-colmena__mosaic-photo--b">
          <img
            src="assets/landing/inspeccion-panal.webp"
            alt="Inspección de marco de colmena"
            width="400"
            height="300"
            loading="lazy"
          >
        </div>
        <div class="bs-colmena__mosaic-photo bs-colmena__mosaic-photo--c">
          <img
            src="assets/landing/apiario-paisaje.webp"
            alt="Apiario con colmenas en el campo"
            width="400"
            height="300"
            loading="lazy"
          >
        </div>
      </div>

    </div>
  </div>
</section>
<!-- /VIDA EN LA COLMENA -->


<!-- ════════════════════════════════════════════════════
     CÓMO FUNCIONA — DIAGRAMA
     ════════════════════════════════════════════════════ -->
<section id="bs-como-funciona" aria-labelledby="bs-como-title">
  <div class="bs-container">

    <header class="bs-como__header">
      <p class="bs-tag bs-como__tag bs-reveal">Innovación en el campo</p>
      <h2 id="bs-como-title" class="bs-como__heading bs-reveal">
        Observar sin intervenir<br>constantemente.
      </h2>
      <p class="bs-como__body bs-reveal">
        Sensores instalados en las colmenas capturan datos clave de forma continua.
        La información viaja hasta la plataforma web donde puedes consultarla
        desde cualquier lugar.
      </p>
    </header>

    <!-- Diagrama de flujo -->
    <div class="bs-flow" role="img" aria-label="Diagrama del flujo de datos: Sensores, ESP32, Plataforma web">

      <!-- Nodo 1: Sensores -->
      <div class="bs-flow__node">
        <span class="bs-flow__node-num">01</span>
        <div class="bs-flow__node-icon" aria-hidden="true">
          <svg width="34" height="34" viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <rect x="5" y="8" width="14" height="18" rx="3"/>
            <circle cx="12" cy="15" r="3" fill="currentColor" fill-opacity="0.18"/>
            <path d="M12 18v4M9 22h6"/>
            <path d="M23 10a7 7 0 0 1 0 10"/>
            <path d="M26 7a11 11 0 0 1 0 16"/>
            <circle cx="12" cy="5" r="1.2" fill="currentColor"/>
          </svg>
        </div>
        <h3 class="bs-flow__node-title">Sensores</h3>
        <p class="bs-flow__node-text">
          BME280, BH1750, HX711 y MAX4466 miden temperatura, humedad, luminosidad, peso y actividad acústica.
        </p>
      </div>

      <!-- Conector 1→2 -->
      <div class="bs-flow__connector" aria-hidden="true">
        <svg width="36" height="20" viewBox="0 0 36 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <line x1="2" y1="10" x2="28" y2="10" stroke-dasharray="3 3"/>
          <circle cx="12" cy="10" r="2.5" fill="currentColor"/>
          <polyline points="24,5 30,10 24,15"/>
        </svg>
      </div>

      <!-- Nodo 2: ESP32 -->
      <div class="bs-flow__node">
        <span class="bs-flow__node-num">02</span>
        <div class="bs-flow__node-icon" aria-hidden="true">
          <svg width="34" height="34" viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <rect x="6" y="6" width="20" height="20" rx="3"/>
            <rect x="10" y="10" width="12" height="12" rx="1.5" fill="currentColor" fill-opacity="0.15"/>
            <path d="M13 14h6M13 18h4"/>
            <path d="M10 2v4M16 2v4M22 2v4M10 26v4M16 26v4M22 26v4M2 10h4M2 16h4M2 22h4M26 10h4M26 16h4M26 22h4"/>
          </svg>
        </div>
        <h3 class="bs-flow__node-title">ESP32</h3>
        <p class="bs-flow__node-text">
          Microcontrolador que recopila las lecturas de los sensores y las transmite a la plataforma vía red.
        </p>
      </div>

      <!-- Conector 2→3 -->
      <div class="bs-flow__connector" aria-hidden="true">
        <svg width="36" height="20" viewBox="0 0 36 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <line x1="2" y1="10" x2="28" y2="10" stroke-dasharray="3 3"/>
          <circle cx="12" cy="10" r="2.5" fill="currentColor"/>
          <polyline points="24,5 30,10 24,15"/>
        </svg>
      </div>

      <!-- Nodo 3: Plataforma web -->
      <div class="bs-flow__node">
        <span class="bs-flow__node-num">03</span>
        <div class="bs-flow__node-icon" aria-hidden="true">
          <svg width="34" height="34" viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="5" width="26" height="18" rx="2.5"/>
            <path d="M3 10h26M10 27h12M16 23v4"/>
            <circle cx="6.5" cy="7.5" r="1" fill="currentColor"/>
            <circle cx="9.5" cy="7.5" r="1" fill="currentColor"/>
            <path d="M7 18l4-4 4 3 6-5 4 3"/>
          </svg>
        </div>
        <h3 class="bs-flow__node-title">Plataforma web</h3>
        <p class="bs-flow__node-text">
          Dashboard accesible desde el navegador con histórico de lecturas, gráficas y gestión de apiarios.
        </p>
      </div>

    </div><!-- /.bs-flow -->

  </div>
</section>
<!-- /CÓMO FUNCIONA -->


<!-- ════════════════════════════════════════════════════
     TECNOLOGÍA Y SENSORES
     ════════════════════════════════════════════════════ -->
<section id="bs-tecnologia" aria-labelledby="bs-tech-title">

  <!-- Hexágono decorativo de fondo -->
  <svg class="bs-tech__bg-hex" viewBox="0 0 500 500" aria-hidden="true">
    <path d="M250 10 L475 140 L475 360 L250 490 L25 360 L25 140 Z" stroke="#F59E0B" stroke-width="1" fill="none"/>
    <path d="M250 60 L425 165 L425 335 L250 440 L75 335 L75 165 Z" stroke="#F59E0B" stroke-width="0.5" fill="none"/>
    <path d="M250 110 L375 190 L375 310 L250 390 L125 310 L125 190 Z" stroke="#F59E0B" stroke-width="0.3" fill="none"/>
  </svg>

  <div class="bs-container">
    <div class="bs-tech__grid">

      <!-- Foto del hardware -->
      <div class="bs-tech__photo-wrap">
        <img
          class="bs-tech__photo"
          src="assets/landing/hardware-sensores.webp"
          alt="Placa de circuito de uno de los módulos de sensores del proyecto BeeStation"
          width="700"
          height="525"
          loading="lazy"
        >
        <div class="bs-tech__photo-glow" aria-hidden="true"></div>
      </div>

      <!-- Contenido -->
      <div class="bs-tech__content">
        <p class="bs-tag bs-tech__tag bs-reveal">Innovación en el campo</p>
        <h2 id="bs-tech-title" class="bs-tech__heading bs-reveal">
          Observar sin intervenir<br>constantemente.
        </h2>
        <p class="bs-tech__body bs-reveal">
          Sensores como el ESP32, BME280 y más capturan datos clave
          de tu colmena. El sistema registra las variables y las mantiene
          disponibles para que tomes decisiones con información real.
        </p>

        <!-- Grid de sensores -->
        <div class="bs-sensors" role="list">

          <div class="bs-sensor-item" role="listitem">
            <span class="bs-sensor-item__icon" aria-hidden="true">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 14.76V3.5a2.5 2.5 0 0 0-5 0v11.26a4.5 4.5 0 1 0 5 0z"/>
                <circle cx="11.5" cy="17.5" r="2" fill="currentColor"/>
                <path d="M11.5 8.5v5"/>
                <path d="M16 6h2M16 10h2"/>
              </svg>
            </span>
            <div class="bs-sensor-item__text">
              <span class="bs-sensor-item__name">Temperatura</span>
              BME280 — interior y exterior
            </div>
          </div>

          <div class="bs-sensor-item" role="listitem">
            <span class="bs-sensor-item__icon" aria-hidden="true">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="4"/>
                <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>
              </svg>
            </span>
            <div class="bs-sensor-item__text">
              <span class="bs-sensor-item__name">Luminosidad</span>
              BH1750 — lux ambientales
            </div>
          </div>

          <div class="bs-sensor-item" role="listitem">
            <span class="bs-sensor-item__icon" aria-hidden="true">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/>
                <path d="M12 9a4 4 0 0 1 4 4" stroke-linecap="round"/>
              </svg>
            </span>
            <div class="bs-sensor-item__text">
              <span class="bs-sensor-item__name">Humedad</span>
              BME280 — humedad relativa
            </div>
          </div>

          <div class="bs-sensor-item" role="listitem">
            <span class="bs-sensor-item__icon" aria-hidden="true">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="9"/>
                <path d="M12 12l3.5-3.5"/>
                <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                <path d="M12 6v1M6 12h1M18 12h1"/>
              </svg>
            </span>
            <div class="bs-sensor-item__text">
              <span class="bs-sensor-item__name">Presión</span>
              BME280 — presión barométrica
            </div>
          </div>

          <div class="bs-sensor-item" role="listitem">
            <span class="bs-sensor-item__icon" aria-hidden="true">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6 3h12l3 6H3l3-6z"/>
                <path d="M4 9v9a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9"/>
                <path d="M9 13a3 3 0 0 1 6 0"/>
                <path d="M12 13v-1"/>
              </svg>
            </span>
            <div class="bs-sensor-item__text">
              <span class="bs-sensor-item__name">Peso</span>
              HX711 + celda de carga
            </div>
          </div>

          <div class="bs-sensor-item" role="listitem">
            <span class="bs-sensor-item__icon" aria-hidden="true">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M11 5L6 9H2v6h4l5 4V5z"/>
                <path d="M15.54 8.46a5 5 0 0 1 0 7.07"/>
                <path d="M19.07 4.93a10 10 0 0 1 0 14.14"/>
              </svg>
            </span>
            <div class="bs-sensor-item__text">
              <span class="bs-sensor-item__name">Actividad acústica</span>
              MAX4466 — micrófono
            </div>
          </div>

        </div><!-- /.bs-sensors -->

        <a href="login.php" class="bs-tech__link bs-reveal">
          Conocer la tecnología
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </a>
      </div>

    </div>
  </div>
</section>
<!-- /TECNOLOGÍA -->


<!-- ════════════════════════════════════════════════════
     TERRITORIO / CONTEXTO
     ════════════════════════════════════════════════════ -->
<section id="bs-territorio" aria-labelledby="bs-territorio-title">

  <!-- Fondo fotográfico -->
  <div class="bs-territorio__bg" style="background-image: url('assets/landing/apiario-paisaje.webp');" aria-hidden="true"></div>
  <div class="bs-territorio__overlay" aria-hidden="true"></div>

  <div class="bs-container">
    <div class="bs-territorio__inner">

      <!-- Texto -->
      <div class="bs-territorio__content">
        <p class="bs-tag bs-territorio__tag bs-reveal">Nuestro territorio</p>
        <h2 id="bs-territorio-title" class="bs-territorio__heading bs-reveal">
          Tecnología nacida<br>para observar<br>la naturaleza.
        </h2>
        <p class="bs-territorio__body bs-reveal">
          BeeStation es un proyecto del SENA, Centro Minero Ambiental,
          desarrollado en El Bagre, Antioquia, Colombia. Una iniciativa que
          une apicultura, electrónica, tecnología y formación para un
          futuro más sostenible.
        </p>
        <p class="bs-territorio__body bs-reveal">
          El sistema está diseñado para funcionar en condiciones reales
          de campo, con conectividad limitada y considerando las condiciones
          ambientales del trópico colombiano.
        </p>
        <a href="login.php" class="bs-territorio__link bs-reveal">
          Conocer más
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </a>
      </div>

      <!-- Foto + Badge -->
      <div class="bs-territorio__photo-col">
        <img
          class="bs-territorio__photo bs-reveal"
          src="assets/landing/apicultor-cierre.webp"
          alt="Apicultor revisando un marco de colmena en el apiario"
          width="600"
          height="375"
          loading="lazy"
        >
        <div class="bs-territorio__badge bs-reveal">
          <span class="bs-territorio__badge-pin" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/>
              <circle cx="12" cy="10" r="3" fill="currentColor" fill-opacity="0.3"/>
            </svg>
          </span>
          <div class="bs-territorio__badge-text">
            El Bagre, Antioquia
            <span>SENA · Centro Minero Ambiental</span>
          </div>
        </div>
      </div>

    </div>
  </div>
</section>
<!-- /TERRITORIO -->


<!-- ════════════════════════════════════════════════════
     CTA CIERRE
     ════════════════════════════════════════════════════ -->
<section id="bs-cta" aria-labelledby="bs-cta-title">

  <div class="bs-cta__bg" style="background-image: url('assets/landing/apiario-paisaje.webp');" aria-hidden="true"></div>
  <div class="bs-cta__overlay" aria-hidden="true"></div>

  <div class="bs-container">
    <div class="bs-cta__inner">

      <div>
        <h2 id="bs-cta-title" class="bs-cta__heading bs-reveal">
          Conoce mejor lo que sucede<br>
          <em>dentro de tus colmenas.</em>
        </h2>
        <p class="bs-cta__body bs-reveal">
          Accede a BeeStation y consulta la información disponible de tus
          apiarios desde un solo lugar.
        </p>
      </div>

      <div class="bs-cta__actions bs-reveal">
        <a href="login.php" id="bs-cta-final-primary" class="bs-btn-cta-primary">
          Iniciar sesión
          <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true">
            <path d="M3.5 9h11M10 4l5 5-5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </a>
        <a href="registro.php" id="bs-cta-final-secondary" class="bs-btn-cta-outline">
          Crear cuenta
        </a>
      </div>

    </div>
  </div>
</section>
<!-- /CTA -->

</main><!-- /#bs-main -->


<!-- ════════════════════════════════════════════════════
     FOOTER
     ════════════════════════════════════════════════════ -->
<footer id="bs-footer" role="contentinfo">
  <div class="bs-container">

    <div class="bs-footer__grid">

      <!-- Marca -->
      <div class="bs-footer__brand">
        <div class="bs-footer__logo">
          <svg class="bs-footer__logo-hex" viewBox="0 0 32 32" fill="none" aria-hidden="true">
            <path d="M16 2 L29 9 L29 23 L16 30 L3 23 L3 9 Z" stroke="#F59E0B" stroke-width="1.5" fill="none"/>
            <circle cx="16" cy="16" r="3.5" fill="#F59E0B" opacity=".9"/>
          </svg>
          <div>
            <span class="bs-footer__brand-name">BeeStation</span>
            <span class="bs-footer__tagline">Observa · Cuida · Conecta</span>
          </div>
        </div>
        <p class="bs-footer__desc">
          Plataforma IoT de monitoreo apícola desarrollada en el SENA,
          Centro Minero Ambiental, El Bagre, Antioquia, Colombia.
        </p>
      </div>

      <!-- Navegación -->
      <div>
        <h3 class="bs-footer__col-title">Explorar</h3>
        <ul class="bs-footer__links">
          <li><a href="#bs-proposito">La apicultura</a></li>
          <li><a href="#bs-como-funciona">Cómo funciona</a></li>
          <li><a href="#bs-tecnologia">Tecnología y sensores</a></li>
          <li><a href="#bs-territorio">Nuestro propósito</a></li>
        </ul>
      </div>

      <!-- Acceso -->
      <div>
        <h3 class="bs-footer__col-title">Acceso</h3>
        <ul class="bs-footer__links">
          <li><a href="login.php">Iniciar sesión</a></li>
          <li><a href="registro.php">Crear cuenta</a></li>
        </ul>
      </div>

    </div><!-- /.bs-footer__grid -->

    <div class="bs-footer__bottom">
      <p class="bs-footer__copy">
        &copy; <?php echo $anio; ?> BeeStation — SENA, Centro Minero Ambiental. Todos los derechos reservados.
      </p>
      <p class="bs-footer__credits">
        Fotografías: Pexels — Licencia gratuita de uso.<br>
        GSAP 3.12.5 — GreenSock Standard License.
      </p>
    </div>

  </div>
</footer>

<!-- ════════════════════════════════════════════════════
     SCRIPTS
     ════════════════════════════════════════════════════ -->

<!-- GSAP Core (local, versión fija 3.12.5) -->
<script src="assets/vendor/gsap/gsap.min.js" defer></script>

<!-- GSAP ScrollTrigger (local) -->
<script src="assets/vendor/gsap/ScrollTrigger.min.js" defer></script>

<!-- Sistema de animaciones de la landing (aislado) -->
<script src="assets/js/landing-motion.js" defer></script>

</body>
</html>
