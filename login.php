<?php
require_once __DIR__ . '/includes/auth.php';

// Destruir la sesión si existe para forzar a que siempre pida contraseña al entrar aquí
if (isset($_SESSION['id_usuario'])) {
    session_unset();
    session_destroy();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = trim($_POST['email'] ?? '');
    $clave  = $_POST['password'] ?? '';

    $usuario = intentarLogin($correo, $clave);

    if ($usuario) {
        $_SESSION['id_usuario'] = $usuario['id_usuario'];
        $_SESSION['nombre']     = $usuario['nombre'];
        $_SESSION['correo']     = $usuario['correo'];
        $_SESSION['rol']        = $usuario['nombre_rol'];
        $_SESSION['nivel_acceso'] = $usuario['nivel_acceso'];
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
    <title>Iniciar Sesión - BeeStation</title>
    <script>
        if (localStorage.getItem('beestation-dark') === 'true') {
            document.documentElement.classList.add('dark');
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=Inter:wght@400;500;600;700&family=Sora:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style-premium.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="auth-body">
    <div class="auth-shell">
        <section class="auth-brand-panel">
            <div class="brand-content">
                <div class="auth-banner">
                    <div class="auth-banner-label"><i data-lucide="sparkles"></i> Hive Intelligence Platform</div>
                    <div class="auth-banner-title">Monitoreo premium para colmenas con lectura en tiempo real.</div>
                    <div class="auth-banner-copy">Una interfaz diseñada para operar como un producto comercial de alto nivel: rápida, limpia, elegante y centrada en datos reales.</div>
                </div>

                <div class="login-hero-row">
                    <img src="assets/logo-beestation.png" alt="BeeStation Logo" class="brand-logo">
                    <div>
                        <h1 class="brand-title">BeeStation</h1>
                        <p class="brand-slogan">Monitoreo IoT para colmenas con datos reales, lectura clara y control operativo en tiempo real.</p>
                    </div>
                </div>

                <div class="auth-hero-stats">
                    <div class="auth-hero-stat"><strong>24/7</strong><span>Vigilancia continua</span></div>
                    <div class="auth-hero-stat"><strong>ESP32</strong><span>Telemetría directa</span></div>
                    <div class="auth-hero-stat"><strong>IBB</strong><span>Bienestar bioclimático</span></div>
                    <div class="auth-hero-stat"><strong>Live</strong><span>Alertas y tendencias</span></div>
                </div>

                <div class="auth-signal-grid">
                    <div class="auth-signal"><i data-lucide="activity"></i><span>Señales acústicas</span></div>
                    <div class="auth-signal"><i data-lucide="thermometer"></i><span>Clima interno</span></div>
                    <div class="auth-signal"><i data-lucide="scale"></i><span>Peso y flujo</span></div>
                    <div class="auth-signal"><i data-lucide="wifi"></i><span>ESP32 online</span></div>
                </div>

                <div class="hero-chip-row">
                    <span class="hero-chip"><i data-lucide="shield-check"></i> Acceso seguro</span>
                    <span class="hero-chip"><i data-lucide="sparkles"></i> UI premium</span>
                </div>
            </div>
        </section>

        <section class="auth-form-panel">
            <div class="auth-card login-card <?= $error ? 'shake-animation' : '' ?>">
                <div class="login-head">
                    <span class="login-head-badge"><i data-lucide="shield-check"></i> Acceso seguro</span>
                    <div class="login-lock-visual"><i data-lucide="lock-keyhole"></i></div>
                </div>

                <div class="login-hero-copy">
                    <h2>Iniciar sesión</h2>
                    <p class="auth-copy">Ingresa con tus credenciales para revisar el estado operativo de tus colmenas.</p>
                </div>

                <div class="login-meta-grid">
                    <div class="login-meta-card"><strong>Tiempo real</strong><span>Lecturas vivas y alertas</span></div>
                    <div class="login-meta-card"><strong>Visual</strong><span>Panel profesional premium</span></div>
                    <div class="login-meta-card"><strong>Seguro</strong><span>Acceso protegido</span></div>
                </div>

                <?php if ($error): ?>
                    <div class="login-error">
                        <i data-lucide="triangle-alert"></i>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <form action="login.php" method="POST" id="loginForm">
                    <div class="form-group floating-field">
                        <div class="field-shell">
                            <input type="email" name="email" id="emailInput" class="form-control" placeholder=" " required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            <label class="form-label" for="emailInput">Correo electrónico</label>
                        </div>
                    </div>
                    <div class="form-group floating-field">
                        <div class="field-shell password-input-wrapper">
                            <input type="password" name="password" id="passwordInput" class="form-control" placeholder=" " required>
                            <label class="form-label" for="passwordInput">Contraseña</label>
                            <button type="button" class="password-toggle" id="togglePassword">
                                <i data-lucide="eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-options">
                        <label class="remember-me">
                            <input type="checkbox" name="remember">
                            <span>Recordarme</span>
                        </label>
                        <a href="#" class="forgot-password">Recuperar contraseña</a>
                    </div>

                    <button type="submit" class="btn btn-brand login-btn" id="submitBtn">
                        <span class="btn-text">Ingresar</span>
                        <i data-lucide="arrow-right" class="btn-symbol"></i>
                        <i data-lucide="loader-2" class="btn-spinner is-hidden"></i>
                    </button>
                </form>

                <div class="login-footer">
                    BeeStation © 2026 · SENA Centro de Formación Ambiental
                </div>
            </div>
        </section>
    </div>

    <script>
        if (window.lucide) lucide.createIcons();

        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('passwordInput');

        togglePassword.addEventListener('click', function () {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.innerHTML = type === 'password' ? '<i data-lucide="eye"></i>' : '<i data-lucide="eye-off"></i>';
            if (window.lucide) lucide.createIcons();
        });

        const loginForm = document.getElementById('loginForm');
        const submitBtn = document.getElementById('submitBtn');
        const btnText = submitBtn.querySelector('.btn-text');
        const btnIcon = submitBtn.querySelector('.btn-symbol');
        const btnSpinner = submitBtn.querySelector('.btn-spinner');

        loginForm.addEventListener('submit', function() {
            submitBtn.classList.add('loading');
            btnText.textContent = 'Procesando...';
            btnIcon.classList.add('is-hidden');
            btnSpinner.classList.remove('is-hidden');
        });

        const heroPanel = document.querySelector('.auth-brand-panel');
        heroPanel?.addEventListener('mousemove', (event) => {
            const rect = heroPanel.getBoundingClientRect();
            const x = ((event.clientX - rect.left) / rect.width - 0.5) * 12;
            const y = ((event.clientY - rect.top) / rect.height - 0.5) * 12;
            heroPanel.style.setProperty('--parallax-x', `${x}px`);
            heroPanel.style.setProperty('--parallax-y', `${y}px`);
        });
    </script>
</body>
</html>
