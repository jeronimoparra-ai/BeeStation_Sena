#ifndef MONITOREO_HTML_H
#define MONITOREO_HTML_H

#include <Arduino.h>

// Página de monitoreo en vivo servida por el propio ESP32 en modo estación.
// Se sirve en "/" y "/monitoreo" y refresca los datos cada 2 s usando el
// endpoint JSON existente "/status".
// Mismas variables de diseño que index_html.h para mantener la identidad visual.
const char MONITOREO_HTML[] PROGMEM = R"rawhtml(
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BeeStation — Monitoreo en Vivo</title>
<style>
:root{--brand:#E9A93F;--brand-dark:#C97C12;--ink:#0B0B0B;--ink-soft:#1A1A1A;
--border:rgba(255,255,255,.15);--text:#fff;--muted:#B3B3B3;--ok:#22c55e;--bad:#ef4444;
--r-card:24px;--r-ctl:14px}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
background:var(--ink);color:var(--text);min-height:100vh}
.wrap{max-width:1100px;margin:0 auto;padding:24px 16px 48px}
header{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;
flex-wrap:wrap;padding-bottom:16px;border-bottom:1px solid var(--border);margin-bottom:24px}
h1{font-size:1.5rem;margin-top:8px}
h1 span{color:var(--brand)}
.meta{font-size:.78rem;color:var(--muted);text-align:right;line-height:1.6}
.pill{display:inline-block;padding:4px 10px;border-radius:999px;font-size:.7rem;
font-weight:700;letter-spacing:.04em;background:rgba(233,169,63,.15);color:var(--brand);
border:1px solid rgba(233,169,63,.4)}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px}
.card{background:var(--ink-soft);border:1px solid var(--border);border-radius:var(--r-card);padding:18px}
.card .label{font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.card .value{font-size:2.3rem;font-weight:800;margin:8px 0 0;font-variant-numeric:tabular-nums;line-height:1}
.card .unit{font-size:.95rem;color:var(--brand);font-weight:600;margin-top:4px}
.card .range{font-size:.7rem;color:var(--muted);margin-top:12px;padding-top:10px;border-top:1px solid var(--border)}
.section{margin-top:28px}
.section h2{font-size:.75rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:10px}
.chips{display:flex;flex-wrap:wrap;gap:8px}
.chip{font-size:.76rem;padding:8px 13px;border-radius:var(--r-ctl);
background:rgba(255,255,255,.05);border:1px solid var(--border)}
.chip b{color:var(--ok);font-weight:700}
.chip b.no{color:var(--bad)}
.sdmeta{font-size:.74rem;color:var(--muted);margin-bottom:10px}
.sdmeta b{color:var(--brand)}
.tablebox{background:var(--ink-soft);border:1px solid var(--border);
border-radius:var(--r-card);overflow:auto;max-height:420px}
table{width:100%;border-collapse:collapse;font-size:.78rem;font-variant-numeric:tabular-nums}
th,td{padding:8px 12px;text-align:right;white-space:nowrap;border-bottom:1px solid var(--border)}
th:first-child,td:first-child{text-align:left}
th{font-size:.66rem;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);
position:sticky;top:0;background:var(--ink-soft);z-index:1}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover td{background:rgba(255,255,255,.04)}
td.empty{text-align:center;color:var(--muted);padding:22px 12px}
.foot{margin-top:30px;font-size:.72rem;color:var(--muted);text-align:center}
@media(max-width:520px){header{flex-direction:column;align-items:flex-start}.meta{text-align:left}}
</style>
</head>
<body>
<div class="wrap">

  <header>
    <div>
      <div class="pill">BeeStation · Nodo en vivo</div>
      <h1>Monitoreo de <span>Sensores</span></h1>
    </div>
    <div class="meta">
      <div id="uptime">Uptime: —</div>
      <div id="mac">MAC: —</div>
      <div id="fw">Firmware: —</div>
    </div>
  </header>

  <div class="grid">
    <div class="card">
      <div class="label">Temperatura interna</div>
      <div class="value" id="temp">—</div>
      <div class="unit">°C</div>
      <div class="range">Óptimo 34–36 °C</div>
    </div>
    <div class="card">
      <div class="label">Humedad relativa</div>
      <div class="value" id="hum">—</div>
      <div class="unit">%</div>
      <div class="range">Óptimo 50–70 %</div>
    </div>
    <div class="card">
      <div class="label">Presión atmosférica</div>
      <div class="value" id="pres">—</div>
      <div class="unit">hPa</div>
      <div class="range">Óptimo 1000–1025 hPa</div>
    </div>
    <div class="card">
      <div class="label">Peso de la colmena</div>
      <div class="value" id="peso">—</div>
      <div class="unit">kg</div>
      <div class="range">Celda de carga HX711</div>
    </div>
    <div class="card">
      <div class="label">Luminosidad</div>
      <div class="value" id="lux">—</div>
      <div class="unit">lux</div>
      <div class="range">Sensor BH1750</div>
    </div>
    <div class="card">
      <div class="label">Nivel acústico</div>
      <div class="value" id="sonido">—</div>
      <div class="unit">V</div>
      <div class="range">Micrófono MAX4466</div>
    </div>
  </div>

  <div class="section">
    <h2>Sensores detectados</h2>
    <div class="chips" id="chips"><span class="chip">Cargando…</span></div>
  </div>

  <div class="section">
    <h2>Red</h2>
    <div class="chips">
      <span class="chip">IP: <b id="ip">—</b></span>
      <span class="chip">Señal: <b id="rssi">—</b></span>
      <span class="chip">Red: <b id="ssid">—</b></span>
      <span class="chip">Destino: <b id="srv">—</b></span>
    </div>
  </div>

  <div class="section">
    <h2>Datos guardados en la microSD</h2>
    <div class="sdmeta">
      Fichero: <b id="sdfile">—</b> ·
      Filas registradas hoy: <b id="sdtot">—</b> ·
      Se leen de la tarjeta, funcionan sin WiFi
    </div>
    <div class="tablebox">
      <table>
        <thead>
          <tr>
            <th>Fecha y hora</th><th>Millis</th><th>Temp °C</th><th>Humedad %</th>
            <th>Presión hPa</th><th>Peso kg</th><th>Sonido V</th><th>Luz lux</th>
          </tr>
        </thead>
        <tbody id="sdbody">
          <tr><td colspan="8" class="empty">Cargando…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="foot">Actualización automática cada 2 s · <span id="ts">—</span></div>

</div>

<script>
const $=id=>document.getElementById(id);
const num=(v,d)=>(v===null||v===undefined||isNaN(v))?'—':Number(v).toFixed(d);
function pintar(d){
  $('fw').textContent='Firmware: '+(d.firmware||'—');
  $('mac').textContent='MAC: '+(d.mac||'—');
  const u=d.uptime||0;
  const p=n=>String(n).padStart(2,'0');
  $('uptime').textContent='Uptime: '+p(Math.floor(u/3600))+':'+p(Math.floor(u%3600/60))+':'+p(u%60);

  if(d.bme280_data){
    $('temp').textContent=num(d.bme280_data.temp,1);
    $('hum').textContent=num(d.bme280_data.hum,1);
    $('pres').textContent=num(d.bme280_data.pres,1);
  }
  if('peso' in d) $('peso').textContent=num(d.peso,2);
  if('sonido' in d) $('sonido').textContent=num(d.sonido,3);
  if('lux' in d) $('lux').textContent=num(d.lux,1);

  if(d.red){
    $('ip').textContent=d.red.ip||'—';
    $('ssid').textContent=d.red.ssid||'—';
    $('rssi').textContent=(d.red.rssi===null||d.red.rssi===undefined)?'—':d.red.rssi+' dBm';
    $('srv').textContent=d.red.servidor||'auto';
  }

  const s=d.sensores||{};
  const items=[['BME280',s.bme280],['BH1750',s.bh1750],['HX711',s.hx711],['MAX4466',s.max4466],['microSD',s.microsd]];
  $('chips').innerHTML=items.map(function(it){
    const ok=it[1];
    return '<span class="chip"><b class="'+(ok?'':'no')+'">'+(ok?'✓':'✗')+'</b> '+it[0]+'</span>';
  }).join('');

  $('ts').textContent='Actualizado '+new Date().toLocaleTimeString();
}
async function tick(){
  try{ const r=await fetch('/status'); if(r.ok) pintar(await r.json()); }catch(e){}
}
async function cargarDatos(){
  try{
    const r=await fetch('/datos?n=60'); if(!r.ok) return;
    const d=await r.json();
    $('sdfile').textContent=d.fichero||'(sin fichero)';
    $('sdtot').textContent=(d.total===undefined)?'—':d.total;
    const filas=d.filas||[];
    if(!filas.length){
      $('sdbody').innerHTML='<tr><td colspan="8" class="empty">Sin datos guardados todavía</td></tr>';
      return;
    }
    $('sdbody').innerHTML=filas.map(function(f){
      const c=f.split(',');
      let h='<tr>';
      for(let i=0;i<8;i++){ h+='<td>'+(c[i]===undefined?'':c[i])+'</td>'; }
      return h+'</tr>';
    }).join('');
  }catch(e){}
}
tick(); setInterval(tick,2000);
cargarDatos(); setInterval(cargarDatos,15000);
</script>
</body>
</html>
)rawhtml";

#endif
