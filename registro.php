<?php
/**
 * registro.php
 * ----------------------------------------------------------------
 * Formulario de registro público para nuevos usuarios en BeeStation.
 *
 * Arquitectura:
 * - Valida entradas estrictamente en servidor con PHP.
 * - Validación CSRF de sesión obligatoria.
 * - Hash criptográfico con password_hash(..., PASSWORD_DEFAULT).
 * - Protección contra fuerza bruta / rate-limiting en sesión e IP.
 * - Campo honeypot anti-bots / anti-spam.
 * - Sin sistema de roles: los nuevos usuarios no reciben privilegios
 *   ni modifican la cuenta principal admin@beestation.io.
 * - Aislamiento total: cada cuenta administra únicamente sus colmenas
 *   y apiarios; una cuenta nueva inicia en estado limpio.
 * ----------------------------------------------------------------
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Si ya tiene sesión abierta, redirigir al dashboard
if (isset($_SESSION['id_usuario'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$exito = '';

$hora = (int) date('H');
if ($hora >= 5 && $hora < 12) {
    $saludo = 'Buenos días';
} elseif ($hora >= 12 && $hora < 19) {
    $saludo = 'Buenas tardes';
} else {
    $saludo = 'Buenas noches';
}

// Inicializar control de rate limiting en sesión
if (!isset($_SESSION['reg_intentos'])) {
    $_SESSION['reg_intentos'] = 0;
    $_SESSION['reg_ultimo_intento'] = time();
} else {
    // Si pasaron más de 15 minutos desde el último intento, reiniciar contador
    if (time() - (int)$_SESSION['reg_ultimo_intento'] > 900) {
        $_SESSION['reg_intentos'] = 0;
        $_SESSION['reg_ultimo_intento'] = time();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Verificación de Rate Limiting (máximo 5 intentos por ventana de 15 min)
    if ($_SESSION['reg_intentos'] >= 5) {
        $error = 'Has superado el límite de intentos de registro. Por seguridad, espera 15 minutos antes de volver a intentarlo.';
    } elseif (!validarCsrfSesion($_POST['csrf'] ?? null)) {
        // 2. Verificación de Token CSRF
        $error = 'La sesión del formulario expiró o no es válida. Recarga la página e inténtalo nuevamente.';
    } elseif (!empty($_POST['sitio_web_empresa'])) {
        // 3. Protección Honeypot contra bots y automatización
        // Simular retardo y respuesta genérica sin tocar la base de datos
        sleep(1);
        $error = 'No fue posible procesar la solicitud.';
    } else {
        $_SESSION['reg_intentos']++;
        $_SESSION['reg_ultimo_intento'] = time();

        $nombreRaw = $_POST['nombre'] ?? '';
        $emailRaw  = $_POST['email'] ?? '';
        $claveRaw  = $_POST['password'] ?? '';
        $claveConf = $_POST['password_confirm'] ?? '';

        $nombre = is_string($nombreRaw) ? trim($nombreRaw) : '';
        $email  = is_string($emailRaw) ? strtolower(trim($emailRaw)) : '';
        $clave  = is_string($claveRaw) ? $claveRaw : '';
        $conf   = is_string($claveConf) ? $claveConf : '';

        // Validaciones en servidor
        if (mb_strlen($nombre, 'UTF-8') < 2 || mb_strlen($nombre, 'UTF-8') > 100) {
            $error = 'El nombre completo debe contener entre 2 y 100 caracteres.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            $error = 'Por favor, ingresa un correo electrónico válido.';
        } elseif (strlen($clave) < 12 || strlen($clave) > 1024) {
            $error = 'La contraseña debe tener al menos 12 caracteres por motivos de seguridad.';
        } elseif ($clave !== $conf) {
            $error = 'Las contraseñas no coinciden. Por favor, asegúrate de ingresarlas iguales.';
        } else {
            // Intentar crear la cuenta mediante la función centralizada
            $resultado = registrarUsuario($nombre, $email, $clave);

            if ($resultado['ok']) {
                // Registro exitoso: reiniciar contador de intentos
                $_SESSION['reg_intentos'] = 0;
                unset($_SESSION['csrf']); // Rotar CSRF tras operación de escritura

                // Redirigir a login con aviso de éxito y correo precargado
                header('Location: login.php?registrado=1&email=' . urlencode($email));
                exit;
            } else {
                $error = $resultado['error'] ?? 'No se pudo crear la cuenta. Inténtalo de nuevo.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Crear Cuenta — BeeStation</title>
  <meta name="description" content="Regístrate en BeeStation para monitorear apiarios y colmenas tecnificadas mediante sensores IoT.">
  <link rel="preload" href="assets/fonts/manrope-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="assets/fonts/bricolage-grotesque-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="css/fonts.css?v=<?= filemtime(__DIR__ . '/css/fonts.css') ?>">
  <link rel="stylesheet" href="css/login-redesign.css?v=<?= filemtime(__DIR__ . '/css/login-redesign.css') ?>">
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
          Únete al monitoreo<br>
          inteligente de colmenas,<br>
          <span class="accent">en tiempo real.</span>
        </h1>

        <!-- Sub-copy -->
        <p class="ls-sub">
          Crea tu cuenta pública para acceder a la plataforma IoT de apicultura de precisión. Supervisa indicadores bioclimáticos, peso, humedad y acústica de tus apiarios.
        </p>

        <!-- Bee photo -->
        <div class="ls-bee-wrap">
          <img src="assets/bee-hero.jpg" alt="Abeja sobre una flor con iluminación cálida" class="ls-bee-img">
        </div>

        <!-- Feature cards -->
        <div class="ls-cards">
          <div class="ls-card">
            <!-- Shield-check icon -->
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
              <polyline points="9 12 11 14 15 10"/>
            </svg>
            <span class="ls-card-label">Acceso<br>independiente</span>
          </div>
          <div class="ls-card">
            <!-- Box / hive icon -->
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
              <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
              <line x1="12" y1="22.08" x2="12" y2="12"/>
            </svg>
            <span class="ls-card-label">Tus propios<br>apiarios</span>
          </div>
          <div class="ls-card">
            <!-- Wifi icon -->
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M5 12.55a11 11 0 0 1 14.08 0"/>
              <path d="M1.42 9a16 16 0 0 1 21.16 0"/>
              <path d="M8.53 16.11a6 6 0 0 1 6.95 0"/>
              <line x1="12" y1="20" x2="12.01" y2="20" stroke-width="2.5"/>
            </svg>
            <span class="ls-card-label">Vinculación<br>ESP32 segura</span>
          </div>
          <div class="ls-card">
            <!-- Bar chart icon -->
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <line x1="18" y1="20" x2="18" y2="10"/>
              <line x1="12" y1="20" x2="12" y2="4"/>
              <line x1="6"  y1="20" x2="6"  y2="14"/>
            </svg>
            <span class="ls-card-label">Métricas y<br>alertas</span>
          </div>
        </div>

        <!-- Left footer -->
        <p class="ls-left-footer">© 2026 BeeStation. Todos los derechos reservados.</p>

      </div>
    </section>

    <!-- ════════  RIGHT PANEL — form  ════════ -->
    <section class="ls-right" aria-label="Formulario de registro">

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
          REGISTRO PÚBLICO
        </div>

        <p class="auth-greeting"><?= htmlspecialchars($saludo) ?>, bienvenido a BeeStation</p>

        <!-- Title -->
        <h2 class="ls-title">Crear cuenta</h2>

        <!-- Subtitle -->
        <p class="ls-subtitle">
          Completa tus datos para comenzar a monitorear<br>
          tus colmenas en <span class="brand-name">BeeStation</span>.
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
        <form action="registro.php" method="POST" id="registroForm" novalidate autocomplete="on">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(tokenCsrfSesion(), ENT_QUOTES, 'UTF-8') ?>">

          <!-- Honeypot anti-spam (invisible para humanos) -->
          <div style="display:none;position:absolute;left:-9999px;opacity:0;" aria-hidden="true">
            <label for="sitio_web_empresa">No llenar este campo:</label>
            <input type="text" name="sitio_web_empresa" id="sitio_web_empresa" tabindex="-1" autocomplete="off">
          </div>

          <!-- Nombre completo -->
          <div class="ls-field-group">
            <label class="ls-label" for="nombreInput">Nombre completo</label>
            <div class="ls-input-wrap">
              <span class="ls-input-icon">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                  <circle cx="12" cy="7" r="4"/>
                </svg>
              </span>
              <input
                type="text"
                name="nombre"
                id="nombreInput"
                class="ls-input"
                placeholder="Ej. Ana Pérez"
                value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>"
                autocomplete="name"
                required
                maxlength="100"
              >
            </div>
          </div>

          <!-- Correo electrónico -->
          <div class="ls-field-group">
            <label class="ls-label" for="emailInput">Correo electrónico</label>
            <div class="ls-input-wrap">
              <span class="ls-input-icon">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                  <polyline points="22,6 12,13 2,6"/>
                </svg>
              </span>
              <input
                type="email"
                name="email"
                id="emailInput"
                class="ls-input"
                placeholder="tu@correo.com"
                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                autocomplete="email"
                required
                maxlength="150"
              >
            </div>
          </div>

          <!-- Contraseña -->
          <div class="ls-field-group">
            <label class="ls-label" for="passwordInput">Contraseña</label>
            <div class="ls-input-wrap">
              <span class="ls-input-icon">
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
                placeholder="Mínimo 12 caracteres"
                autocomplete="new-password"
                required
              >
              <button type="button" class="ls-pw-toggle" id="togglePw" aria-label="Mostrar u ocultar contraseña">
                <svg viewBox="0 0 24 24" id="eyeIcon" aria-hidden="true">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                  <circle cx="12" cy="12" r="3"/>
                </svg>
              </button>
            </div>

            <!-- Indicador visual interactivo de fortaleza de contraseña -->
            <div class="ls-strength-meter" id="strengthMeter">
              <div class="ls-strength-track">
                <div class="ls-strength-segment" id="seg1"></div>
                <div class="ls-strength-segment" id="seg2"></div>
                <div class="ls-strength-segment" id="seg3"></div>
              </div>
              <div class="ls-strength-text">
                <span>Fortaleza:</span>
                <span class="ls-strength-label" id="strengthLabel">Mínimo 12 caracteres</span>
              </div>
            </div>

            <!-- Requisitos de seguridad -->
            <ul class="ls-pw-rules" id="pwRules">
              <li id="ruleLength">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Mínimo 12 caracteres</span>
              </li>
              <li id="ruleLetters">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Mayúsculas y minúsculas</span>
              </li>
              <li id="ruleNumbers">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Números o símbolos</span>
              </li>
            </ul>
          </div>

          <!-- Confirmar contraseña -->
          <div class="ls-field-group">
            <label class="ls-label" for="passwordConfirmInput">Confirmar contraseña</label>
            <div class="ls-input-wrap">
              <span class="ls-input-icon">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                  <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                  <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
              </span>
              <input
                type="password"
                name="password_confirm"
                id="passwordConfirmInput"
                class="ls-input"
                placeholder="Repite tu contraseña"
                autocomplete="new-password"
                required
              >
              <button type="button" class="ls-pw-toggle" id="togglePwConfirm" aria-label="Mostrar u ocultar confirmación">
                <svg viewBox="0 0 24 24" id="eyeIconConfirm" aria-hidden="true">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                  <circle cx="12" cy="12" r="3"/>
                </svg>
              </button>
            </div>
            <div class="ls-pw-match" id="pwMatchMsg"></div>
          </div>

          <!-- Submit -->
          <button type="submit" class="ls-submit-btn" id="submitBtn">
            <span class="btn-label">Crear cuenta</span>
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <line x1="5" y1="12" x2="19" y2="12"/>
              <polyline points="12 5 19 12 12 19"/>
            </svg>
          </button>

        </form>

        <!-- Enlace a inicio de sesión -->
        <div class="ls-switch-link">
          ¿Ya tienes una cuenta? <a href="login.php">Inicia sesión aquí</a>
        </div>

        <!-- Footer institucional -->
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
    /* ─── Toggle visibilidad de contraseñas ─── */
    const EYE_OPEN = `
      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
      <circle cx="12" cy="12" r="3"/>`;
    const EYE_SHUT = `
      <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
      <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
      <line x1="1" y1="1" x2="23" y2="23"/>`;

    const setupToggle = (btnId, inputId, iconId) => {
      const btn = document.getElementById(btnId);
      const input = document.getElementById(inputId);
      const icon = document.getElementById(iconId);
      if (!btn || !input || !icon) return;

      btn.addEventListener('click', () => {
        const hiding = input.type === 'password';
        input.type = hiding ? 'text' : 'password';
        icon.innerHTML = hiding ? EYE_SHUT : EYE_OPEN;
      });
    };

    setupToggle('togglePw', 'passwordInput', 'eyeIcon');
    setupToggle('togglePwConfirm', 'passwordConfirmInput', 'eyeIconConfirm');

    /* ─── Validación en vivo y medidor de fortaleza ─── */
    const pwInput        = document.getElementById('passwordInput');
    const pwConfirmInput = document.getElementById('passwordConfirmInput');
    const seg1           = document.getElementById('seg1');
    const seg2           = document.getElementById('seg2');
    const seg3           = document.getElementById('seg3');
    const strengthLabel  = document.getElementById('strengthLabel');
    const ruleLength     = document.getElementById('ruleLength');
    const ruleLetters    = document.getElementById('ruleLetters');
    const ruleNumbers    = document.getElementById('ruleNumbers');
    const pwMatchMsg     = document.getElementById('pwMatchMsg');

    const updateStrength = () => {
      const val = pwInput.value;
      const len = val.length;

      const hasLen = len >= 12;
      const hasUpper = /[A-Z]/.test(val);
      const hasLower = /[a-z]/.test(val);
      const hasNumOrSym = /[0-9!@#$%^&*(),.?":{}|<>]/.test(val);

      // Actualizar checklist
      ruleLength.classList.toggle('valid', hasLen);
      ruleLetters.classList.toggle('valid', hasUpper && hasLower);
      ruleNumbers.classList.toggle('valid', hasNumOrSym);

      // Calcular nivel
      let score = 0;
      if (hasLen) score++;
      if (hasUpper && hasLower) score++;
      if (hasNumOrSym) score++;

      // Reset segments
      seg1.className = 'ls-strength-segment';
      seg2.className = 'ls-strength-segment';
      seg3.className = 'ls-strength-segment';
      strengthLabel.className = 'ls-strength-label';

      if (len === 0) {
        strengthLabel.textContent = 'Mínimo 12 caracteres';
      } else if (score <= 1) {
        seg1.classList.add('active-weak');
        strengthLabel.classList.add('weak');
        strengthLabel.textContent = 'Débil';
      } else if (score === 2) {
        seg1.classList.add('active-medium');
        seg2.classList.add('active-medium');
        strengthLabel.classList.add('medium');
        strengthLabel.textContent = 'Media';
      } else {
        seg1.classList.add('active-strong');
        seg2.classList.add('active-strong');
        seg3.classList.add('active-strong');
        strengthLabel.classList.add('strong');
        strengthLabel.textContent = 'Fuerte (Recomendada)';
      }

      checkMatch();
    };

    const checkMatch = () => {
      const p1 = pwInput.value;
      const p2 = pwConfirmInput.value;

      if (!p2) {
        pwMatchMsg.className = 'ls-pw-match';
        pwMatchMsg.textContent = '';
        return;
      }

      if (p1 === p2) {
        pwMatchMsg.className = 'ls-pw-match match';
        pwMatchMsg.textContent = '✓ Las contraseñas coinciden';
      } else {
        pwMatchMsg.className = 'ls-pw-match mismatch';
        pwMatchMsg.textContent = '✗ Las contraseñas no coinciden';
      }
    };

    pwInput.addEventListener('input', updateStrength);
    pwConfirmInput.addEventListener('input', checkMatch);

    /* ─── Estado de carga en submit ─── */
    const registroForm = document.getElementById('registroForm');
    const submitBtn    = document.getElementById('submitBtn');
    const btnLabel     = submitBtn.querySelector('.btn-label');

    registroForm.addEventListener('submit', () => {
      submitBtn.disabled = true;
      btnLabel.textContent = 'Creando cuenta…';
      submitBtn.style.opacity = '0.8';
    });
  </script>

  <script src="assets/vendor/lucide.min.js"></script>
  <script src="js/app.js?v=<?= filemtime(__DIR__ . '/js/app.js') ?>"></script>
</body>
</html>
