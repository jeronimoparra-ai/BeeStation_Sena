#ifndef INDEX_HTML_H
#define INDEX_HTML_H

#include <Arduino.h>

// Portal Cautivo HTML con diagnóstico de hardware integrado
// Las variables %WIFI_NETWORKS%, %SENSOR_STATUS%, %FIRMWARE_VERSION%, %MAC_ADDRESS%
// son reemplazadas dinámicamente por atenderPortal() en beestation_node.ino
const char PORTAL_HTML[] PROGMEM = R"rawhtml(
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BeeStation — Configuración del Nodo IoT</title>
  <style>
    :root {
      --color-brand: #E9A93F;
      --color-brand-dark: #C97C12;
      --color-ink: #0B0B0B;
      --color-ink-soft: #1A1A1A;
      --color-border: rgba(255, 255, 255, 0.15);
      --color-text: #FFFFFF;
      --color-text-secondary: #B3B3B3;
      --color-success: #22c55e;
      --color-danger: #ef4444;
      --radius-card: 24px;
      --radius-control: 14px;
    }
    
    * { 
      box-sizing: border-box; 
      margin: 0; 
      padding: 0; 
    }
    
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      background-color: var(--color-ink);
      color: var(--color-text);
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      padding: 20px;
      position: relative;
    }
    
    /* Textura hexagonal de panal de abejas de fondo */
    body::before {
      content: '';
      position: absolute;
      inset: 0;
      opacity: 0.05;
      pointer-events: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='60' height='52'%3E%3Cpolygon points='30,1 59,16 59,36 30,51 1,36 1,16' fill='none' stroke='%23E9A93F' stroke-width='1'/%3E%3C/svg%3E");
      background-size: 60px 52px;
    }
    
    .container { 
      width: 100%; 
      max-width: 440px; 
      position: relative; 
      z-index: 1; 
    }
    
    .card {
      background: linear-gradient(180deg, rgba(26, 26, 26, 0.95) 0%, rgba(15, 15, 15, 0.98) 100%);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-card);
      padding: 30px;
      box-shadow: 0 24px 48px rgba(0, 0, 0, 0.6);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
    }
    
    .header { 
      text-align: center; 
      margin-bottom: 24px; 
    }
    
    .logo-wrap {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 54px;
      height: 54px;
      border-radius: 16px;
      background: rgba(233, 169, 63, 0.12);
      border: 1px solid rgba(233, 169, 63, 0.3);
      margin-bottom: 12px;
    }
    
    .logo-wrap svg { 
      width: 32px; 
      height: 32px; 
      fill: var(--color-brand); 
    }
    
    .title { 
      font-size: 1.6rem; 
      font-weight: 800; 
      letter-spacing: -0.02em;
      margin-bottom: 6px; 
    }
    
    .subtitle { 
      font-size: 0.88rem; 
      color: var(--color-text-secondary); 
      line-height: 1.4; 
    }

    .version-badge {
      display: inline-block;
      font-size: 0.65rem;
      font-weight: 600;
      color: var(--color-brand);
      background: rgba(233, 169, 63, 0.1);
      border: 1px solid rgba(233, 169, 63, 0.2);
      border-radius: 8px;
      padding: 2px 8px;
      margin-top: 8px;
      letter-spacing: 0.05em;
    }

    /* ── Sección de diagnóstico de hardware ── */
    .hw-section {
      margin-bottom: 22px;
      padding: 16px;
      background: rgba(255, 255, 255, 0.03);
      border: 1px solid var(--color-border);
      border-radius: 16px;
    }

    .hw-title {
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--color-brand);
      margin-bottom: 12px;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .hw-title svg {
      width: 14px;
      height: 14px;
      fill: none;
      stroke: var(--color-brand);
      stroke-width: 2;
    }

    .hw-grid {
      display: flex;
      flex-direction: column;
      gap: 0;
    }

    .live-data {
      margin-top: 12px;
      padding: 10px 12px;
      background: rgba(34, 197, 94, 0.06);
      border: 1px solid rgba(34, 197, 94, 0.15);
      border-radius: 10px;
      font-size: 0.75rem;
      color: var(--color-text-secondary);
      text-align: center;
    }

    .live-data strong {
      color: var(--color-success);
    }

    .live-data .val {
      display: inline-block;
      margin: 0 6px;
      color: #fff;
      font-weight: 600;
    }

    /* ── Formulario ── */
    .form-group { 
      margin-bottom: 20px; 
    }
    
    .form-label { 
      display: block; 
      font-size: 0.8rem; 
      font-weight: 700; 
      text-transform: uppercase; 
      letter-spacing: 0.08em; 
      color: var(--color-brand); 
      margin-bottom: 8px; 
    }
    
    .form-control {
      width: 100%;
      height: 50px;
      padding: 12px 16px;
      border-radius: var(--radius-control);
      border: 1px solid var(--color-border);
      background: rgba(255, 255, 255, 0.08); 
      color: #FFFFFF !important;
      font-size: 0.95rem;
      font-weight: 500;
      outline: none;
      transition: all 0.2s ease;
    }
    
    .form-control:focus { 
      border-color: var(--color-brand); 
      background: rgba(255, 255, 255, 0.12);
      box-shadow: 0 0 0 3px rgba(233, 169, 63, 0.25);
    }

    .form-control::placeholder {
      color: rgba(255, 255, 255, 0.35);
    }
    
    select.form-control {
      appearance: none;
      -webkit-appearance: none;
      -moz-appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23E9A93F' stroke-width='2.5'%3E%3Cpath d='m6 9 6 6 6-9'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 16px center;
      background-size: 14px;
      padding-right: 40px;
      cursor: pointer;
    }
    
    select.form-control option {
      background-color: #141414 !important; 
      color: #FFFFFF !important; 
      padding: 14px;
      font-size: 0.95rem;
    }
    
    select.form-control option:disabled {
      color: #666666 !important; 
    }
    
    .btn-submit {
      width: 100%; 
      height: 50px;
      background: linear-gradient(135deg, var(--color-brand) 0%, var(--color-brand-dark) 100%);
      color: #000000; 
      border: none; 
      border-radius: var(--radius-control);
      font-size: 1rem; 
      font-weight: 700; 
      cursor: pointer;
      display: flex; 
      align-items: center; 
      justify-content: center; 
      gap: 10px;
      box-shadow: 0 12px 24px rgba(233, 169, 63, 0.2); 
      margin-top: 26px;
      transition: all 0.2s ease;
    }
    
    .btn-submit:hover {
      transform: translateY(-1px);
      box-shadow: 0 16px 32px rgba(233, 169, 63, 0.35);
    }

    .btn-submit:active {
      transform: translateY(0);
    }
    
    .helper-text {
      font-size: 0.75rem; 
      color: var(--color-text-secondary); 
      margin-top: 6px; 
      display: block;
      line-height: 1.3;
    }

    .footer-note { 
      text-align: center; 
      font-size: 0.72rem; 
      color: rgba(255, 255, 255, 0.3); 
      margin-top: 24px; 
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    /* ── Colapsable de diagnóstico ── */
    .collapsible-toggle {
      display: flex;
      align-items: center;
      justify-content: space-between;
      cursor: pointer;
      -webkit-user-select: none;
      user-select: none;
    }

    .collapsible-toggle .arrow {
      transition: transform 0.3s ease;
      font-size: 0.7rem;
      color: var(--color-text-secondary);
    }

    .collapsible-content {
      max-height: 0;
      overflow: hidden;
      transition: max-height 0.4s ease;
    }

    .collapsible-content.open {
      max-height: 500px;
    }

    .mac-text {
      font-size: 0.68rem;
      color: rgba(255,255,255,0.25);
      text-align: center;
      margin-top: 8px;
      font-family: monospace;
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="card">
      <div class="header">
        <div class="logo-wrap">
          <svg viewBox="0 0 24 24">
            <path d="M12 2l9 4.9v10.2L12 22l-9-4.9V6.9L12 2z"/>
          </svg>
        </div>
        <h1 class="title">BeeStation</h1>
        <p class="subtitle">Aprovisionamiento del Nodo IoT de colmenas</p>
        <div class="version-badge">Firmware v%FIRMWARE_VERSION%</div>
      </div>

      <!-- DIAGNÓSTICO DE HARDWARE -->
      <div class="hw-section">
        <div class="collapsible-toggle" onclick="toggleDiag()">
          <div class="hw-title" style="margin-bottom:0;">
            <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
              <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
            </svg>
            Diagnóstico de Hardware
          </div>
          <span class="arrow" id="diagArrow">▼</span>
        </div>
        <div class="collapsible-content" id="diagContent">
          <div class="hw-grid" style="margin-top:12px;">
            %SENSOR_STATUS%
          </div>
          <div id="liveData" class="live-data" style="display:none;"></div>
        </div>
      </div>

      <form action="/save" method="POST">
        <!-- 1. SELECCIÓN DE RED WI-FI -->
        <div class="form-group">
          <label class="form-label" for="ssid">Red WiFi del Apiario</label>
          <select name="ssid" id="ssid" class="form-control" required>
            <option value="" disabled selected>Selecciona una red...</option>
            %WIFI_NETWORKS%
          </select>
        </div>

        <!-- 2. CONTRASEÑA DE RED -->
        <div class="form-group">
          <label class="form-label" for="password">Contraseña del WiFi</label>
          <input type="password" name="password" id="password" class="form-control" placeholder="Escribe la clave de red" required>
        </div>

        <!-- 3. IP O DOMINIO DEL SERVIDOR (HOST) -->
        <div class="form-group">
          <label class="form-label" for="host_servidor">Servidor IP (Opcional)</label>
          <input type="text" name="host_servidor" id="host_servidor" class="form-control" placeholder="Ej: 192.168.1.38">
          <span class="helper-text">
            *¡Recomendado! Déjalo en <b>blanco</b> y el ESP32 buscará la IP automáticamente en tu red.
          </span>
        </div>

        <!-- 4. TOKEN DE VINCULACIÓN ÚNICO -->
        <div class="form-group">
          <label class="form-label" for="token_vinculacion">Token de Vinculación Único</label>
          <input type="text" name="token_vinculacion" id="token_vinculacion" class="form-control" placeholder="Ej: BS-ALPHA01-9F2D" required>
          <span class="helper-text">
            Copia el token exacto desde la plataforma web BeeStation.
          </span>
        </div>

        <button type="submit" class="btn-submit">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
          </svg>
          Vincular Colmena
        </button>
      </form>
    </div>
    <div class="footer-note">ApiTechnology SENA</div>
    <div class="mac-text">MAC: %MAC_ADDRESS%</div>
  </div>

  <script>
    // Toggle del panel de diagnóstico
    function toggleDiag() {
      var content = document.getElementById('diagContent');
      var arrow = document.getElementById('diagArrow');
      content.classList.toggle('open');
      arrow.textContent = content.classList.contains('open') ? '▲' : '▼';
      
      // Cargar datos en vivo cuando se abre
      if (content.classList.contains('open')) {
        fetchLiveData();
      }
    }

    // Solicitar datos en vivo del endpoint /status
    function fetchLiveData() {
      fetch('/status')
        .then(function(r) { return r.json(); })
        .then(function(d) {
          var el = document.getElementById('liveData');
          var parts = [];
          if (d.bme280_data) {
            parts.push('<strong>Temp:</strong> <span class="val">' + d.bme280_data.temp + '°C</span>');
            parts.push('<strong>Hum:</strong> <span class="val">' + d.bme280_data.hum + '%</span>');
            parts.push('<strong>Pres:</strong> <span class="val">' + d.bme280_data.pres + ' hPa</span>');
          }
          if (d.bh1750_data) {
            parts.push('<strong>Luz:</strong> <span class="val">' + d.bh1750_data.lux + ' lux</span>');
          }
          if (d.mic_raw !== undefined) {
            parts.push('<strong>Mic:</strong> <span class="val">' + d.mic_raw + '/4095</span>');
          }
          if (parts.length > 0) {
            el.innerHTML = '📡 Lectura en vivo: ' + parts.join(' · ');
            el.style.display = 'block';
          }
        })
        .catch(function() {});
    }

    // Auto-abrir diagnóstico al cargar
    document.addEventListener('DOMContentLoaded', function() {
      toggleDiag();
    });
  </script>
</body>
</html>
)rawhtml";

#endif