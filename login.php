<?php
require_once __DIR__ . '/includes/auth.php';

// Forzar nueva sesión siempre que se acceda al login
if (isset($_SESSION['id_usuario'])) {
    session_unset();
    session_destroy();
}

$error = '';
$hora = (int) date('H');
if ($hora >= 5 && $hora < 12) $saludo = 'Buenos días';
elseif ($hora >= 12 && $hora < 19) $saludo = 'Buenas tardes';
else $saludo = 'Buenas noches';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = trim($_POST['email'] ?? '');
    $clave  = $_POST['password'] ?? '';
    $usuario = intentarLogin($correo, $clave);

    if ($usuario) {
        $_SESSION['id_usuario']   = $usuario['id_usuario'];
        $_SESSION['nombre']       = $usuario['nombre'];
        $_SESSION['correo']       = $usuario['correo'];
        header("Location: conectar_dispositivo.php");
        exit;
    } else {
        $error = 'Correo o contraseña incorrectos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Iniciar Sesión — BeeStation</title>
  <meta name="description" content="Accede a tu plataforma BeeStation para monitoreo inteligente de colmenas con lectura en tiempo real.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/login-redesign.css">
  <script>
    if (localStorage.getItem('beestation-dark') === 'true') {
        document.documentElement.classList.add('dark');
    }
  </script>
</head>
<body class="login-page">

  <!-- ═══════════════════════════  SHELL  ═══════════════════════════ -->
  <div class="ls-shell">

    <!-- ════════  LEFT PANEL — dark brand  ════════ -->
    <section class="ls-left" aria-label="Presentación BeeStation">

      <!-- Hexágonos flotantes decorativos -->
      <div class="floating-hexagons" aria-hidden="true">
        <i data-lucide="hexagon" class="float-hex float-hex-1"></i>
        <i data-lucide="hexagon" class="float-hex float-hex-2"></i>
        <i data-lucide="hexagon" class="float-hex float-hex-3"></i>
      </div>

      <div class="ls-left-inner">

        <!-- Logo -->
        <div class="ls-logo">
          <img src="assets/logo-beestation.png" alt="BeeStation" class="ls-logo-img">
          <span class="ls-logo-text">BeeStation</span>
        </div>

        <!-- Headline -->
        <h1 class="ls-headline">
          Monitoreo inteligente<br>
          para colmenas con<br>
          <span class="accent">lectura en tiempo real.</span>
        </h1>

        <!-- Sub-copy -->
        <p class="ls-sub">
          Plataforma IoT diseñada para brindar control,
          análisis y alertas en tiempo real del estado
          de tus colmenas.
        </p>

        <!-- Bee photo -->
        <div class="ls-bee-wrap">
          <img src="assets/bee-hero.jpg" alt="Abeja sobre una flor con iluminación cálida" class="ls-bee-img">
        </div>

        <!-- Feature cards -->
        <div class="ls-cards">
          <div class="ls-card">
            <!-- Activity / pulse icon -->
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
            </svg>
            <span class="ls-card-label">Lecturas<br>en tiempo real</span>
          </div>
          <div class="ls-card">
            <!-- Wifi icon -->
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M5 12.55a11 11 0 0 1 14.08 0"/>
              <path d="M1.42 9a16 16 0 0 1 21.16 0"/>
              <path d="M8.53 16.11a6 6 0 0 1 6.95 0"/>
              <line x1="12" y1="20" x2="12.01" y2="20" stroke-width="2.5"/>
            </svg>
            <span class="ls-card-label">Conexión<br>ESP32</span>
          </div>
          <div class="ls-card">
            <!-- Shield-check icon -->
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
              <polyline points="9 12 11 14 15 10"/>
            </svg>
            <span class="ls-card-label">Seguridad<br>avanzada</span>
          </div>
          <div class="ls-card">
            <!-- Bar chart icon -->
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <line x1="18" y1="20" x2="18" y2="10"/>
              <line x1="12" y1="20" x2="12" y2="4"/>
              <line x1="6"  y1="20" x2="6"  y2="14"/>
            </svg>
            <span class="ls-card-label">Análisis y<br>tendencias</span>
          </div>
        </div>

        <!-- Left footer -->
        <p class="ls-left-footer">© 2026 BeeStation. Todos los derechos reservados.</p>

      </div>
    </section>



    <!-- ════════  RIGHT PANEL — white form  ════════ -->
    <section class="ls-right" aria-label="Formulario de inicio de sesión">

      <!-- Theme toggle -->
      <button class="ls-theme-btn auth-dark-toggle" id="authDarkToggle" aria-label="Cambiar tema" title="Modo oscuro">
        <i data-lucide="moon"></i>
      </button>

      <!-- Form wrapper -->
      <div class="ls-form-wrap">

        <!-- 🔒 Secure badge -->
        <div class="ls-secure-badge">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
          </svg>
          ACCESO SEGURO
        </div>

        <p class="auth-greeting"><?= htmlspecialchars($saludo) ?>, bienvenido de vuelta</p>

        <!-- Title -->
        <h2 class="ls-title">Iniciar sesión</h2>

        <!-- Subtitle -->
        <p class="ls-subtitle">
          Ingresa con tus credenciales para acceder<br>
          a tu plataforma <span class="brand-name">BeeStation</span>.
        </p>

        <!-- Error message (PHP) -->
        <?php if ($error): ?>
          <div class="ls-error-msg" role="alert">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
              <line x1="12" y1="9"  x2="12" y2="13"/>
              <line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
            <span><?= htmlspecialchars($error) ?></span>
          </div>
        <?php endif; ?>

        <!-- Form -->
        <form action="login.php" method="POST" id="loginForm" novalidate>

          <!-- Correo electrónico -->
          <div class="ls-field-group">
            <label class="ls-label" for="emailInput">Correo electrónico</label>
            <div class="ls-input-wrap">
              <span class="ls-input-icon">
                <!-- User icon -->
                <svg viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                  <circle cx="12" cy="7" r="4"/>
                </svg>
              </span>
              <input
                type="email"
                name="email"
                id="emailInput"
                class="ls-input"
                placeholder="admin"
                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                autocomplete="email"
                required
              >
            </div>
          </div>

          <!-- Contraseña -->
          <div class="ls-field-group">
            <label class="ls-label" for="passwordInput">Contraseña</label>
            <div class="ls-input-wrap">
              <span class="ls-input-icon">
                <!-- Lock icon -->
                <svg viewBox="0 0 24 24" aria-hidden="true">
                  <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                  <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
              </span>
              <input
                type="password"
                name="password"
                id="passwordInput"
                class="ls-input"
                placeholder="••••••••"
                autocomplete="current-password"
                required
              >
              <button type="button" class="ls-pw-toggle" id="togglePw" aria-label="Mostrar contraseña">
                <!-- Eye icon (show) -->
                <svg viewBox="0 0 24 24" id="eyeIcon" aria-hidden="true">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                  <circle cx="12" cy="12" r="3"/>
                </svg>
              </button>
            </div>
          </div>

          <!-- Remember + Forgot -->
          <div class="ls-form-options">
            <label class="ls-remember">
              <input type="checkbox" name="remember" id="rememberMe" checked>
              <span>Recordarme</span>
            </label>
            <a href="#" class="ls-forgot">¿Olvidaste tu contraseña?</a>
          </div>

          <!-- Submit -->
          <button type="submit" class="ls-submit-btn" id="submitBtn">
            <span class="btn-label">Ingresar</span>
            <!-- Arrow right icon -->
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <line x1="5" y1="12" x2="19" y2="12"/>
              <polyline points="12 5 19 12 12 19"/>
            </svg>
          </button>

        </form>

        <!-- Footer institucional -->
        <!-- NOTA: la frase de "cifrado de extremo a extremo" se omite porque el
             proyecto no documenta HTTPS/cifrado implementado. Se usa el footer
             institucional verificable en su lugar. -->
        <div class="ls-security-note">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
          <span>BeeStation &copy; 2026 &middot; SENA Centro de Formación Ambiental</span>
        </div>

      </div><!-- /.ls-form-wrap -->
    </section><!-- /.ls-right -->

  </div><!-- /.ls-shell -->

  <script>
    /* ─── Password toggle ─── */
    const togglePw      = document.getElementById('togglePw');
    const passwordInput = document.getElementById('passwordInput');
    const eyeIcon       = document.getElementById('eyeIcon');

    const EYE_OPEN = `
      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
      <circle cx="12" cy="12" r="3"/>`;
    const EYE_SHUT = `
      <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
      <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
      <line x1="1" y1="1" x2="23" y2="23"/>`;

    togglePw.addEventListener('click', () => {
      const hiding = passwordInput.type === 'password';
      passwordInput.type = hiding ? 'text' : 'password';
      eyeIcon.innerHTML  = hiding ? EYE_SHUT : EYE_OPEN;
    });

    /* ─── Submit loading state ─── */
    const loginForm = document.getElementById('loginForm');
    const submitBtn = document.getElementById('submitBtn');
    const btnLabel  = submitBtn.querySelector('.btn-label');

    loginForm.addEventListener('submit', () => {
      submitBtn.disabled = true;
      btnLabel.textContent = 'Procesando…';
      submitBtn.style.opacity = '0.8';
    });
  </script>

  <script src="https://unpkg.com/lucide@latest"></script>
  <script src="js/app.js"></script>
</body>
</html>
