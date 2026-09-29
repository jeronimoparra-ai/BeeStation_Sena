<?php
/**
 * test_connection.php
 * ─────────────────────────────────────────────────────────────
 * Diagnóstico de conexión a la base de datos BeeStation.
 *
 * Verifica:
 *  1. Conexión PDO a MySQL/MariaDB
 *  2. Versión del servidor y charset
 *  3. Existencia de todas las tablas del esquema
 *  4. Conteo de registros por tabla
 *
 * ⚠  ELIMINAR o proteger este archivo en producción.
 * ─────────────────────────────────────────────────────────────
 */

// ── Tablas esperadas según database/beestation_sena.sql ──
$expectedTables = [
    'usuario',
    'apiario',
    'colmena',
    'variable_bioclimatica',
    'sensor',
    'calibracion',
    'lectura',
    'indicador',
    'alerta',
];

// ── Intentar conexión ──
$connectionOk  = false;
$serverVersion  = '';
$charset        = '';
$dbName         = '';
$errorMsg       = '';
$tableResults   = [];
$missingTables  = [];

try {
    require_once __DIR__ . '/config/db.php';
    $pdo = getPDO();
    $connectionOk = true;

    // Información del servidor
    $serverVersion = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    $dbName        = DB_NAME;

    // Charset activo
    $stmtCharset = $pdo->query("SELECT @@character_set_database AS cs");
    $charset     = $stmtCharset->fetchColumn() ?: '—';

    // Verificar tablas
    $stmtTables  = $pdo->query("SHOW TABLES");
    $existingTables = $stmtTables->fetchAll(PDO::FETCH_COLUMN);

    foreach ($expectedTables as $table) {
        if (in_array($table, $existingTables, true)) {
            $count = $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            $tableResults[$table] = ['exists' => true, 'count' => (int) $count];
        } else {
            $tableResults[$table] = ['exists' => false, 'count' => 0];
            $missingTables[] = $table;
        }
    }
} catch (Throwable $e) {
    $errorMsg = $e->getMessage();
}

// ── Resumen general ──
$totalTables   = count($expectedTables);
$foundTables   = $totalTables - count($missingTables);
$overallStatus = $connectionOk && empty($missingTables) ? 'ok' : ($connectionOk ? 'warn' : 'error');
$statusLabels  = ['ok' => '✅ Todo correcto', 'warn' => '⚠️ Conexión OK — tablas faltantes', 'error' => '❌ Error de conexión'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Test de Conexión — BeeStation</title>
  <meta name="robots" content="noindex, nofollow">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    /* ── Reset & base ── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Inter', system-ui, sans-serif;
      background: #0f1117;
      color: #e2e4ea;
      min-height: 100vh;
      display: flex;
      justify-content: center;
      padding: 2rem 1rem;
    }

    /* ── Container ── */
    .tc-container {
      width: 100%;
      max-width: 820px;
    }

    /* ── Header ── */
    .tc-header {
      text-align: center;
      margin-bottom: 2rem;
    }
    .tc-header h1 {
      font-size: 1.6rem;
      font-weight: 800;
      color: #f5a623;
      letter-spacing: -0.02em;
      margin-bottom: .35rem;
    }
    .tc-header p {
      font-size: .85rem;
      color: #8b8fa3;
    }

    /* ── Cards ── */
    .tc-card {
      background: #181a21;
      border: 1px solid #2a2d38;
      border-radius: 14px;
      padding: 1.5rem 1.75rem;
      margin-bottom: 1.25rem;
      transition: box-shadow .2s;
    }
    .tc-card:hover { box-shadow: 0 0 0 1px #f5a62344; }
    .tc-card h2 {
      font-size: 1rem;
      font-weight: 700;
      margin-bottom: 1rem;
      display: flex;
      align-items: center;
      gap: .5rem;
    }
    .tc-card h2 .icon { font-size: 1.15rem; }

    /* ── Status banner ── */
    .tc-status {
      border-radius: 14px;
      padding: 1.15rem 1.5rem;
      font-weight: 600;
      font-size: 1.05rem;
      margin-bottom: 1.25rem;
      display: flex;
      align-items: center;
      gap: .6rem;
    }
    .tc-status.ok    { background: #12261a; border: 1px solid #22c55e55; color: #4ade80; }
    .tc-status.warn  { background: #261f12; border: 1px solid #eab30855; color: #fbbf24; }
    .tc-status.error { background: #261212; border: 1px solid #ef444455; color: #f87171; }

    /* ── Info grid ── */
    .tc-info-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: .75rem;
    }
    .tc-info-item {
      background: #1e2029;
      border-radius: 10px;
      padding: .85rem 1rem;
    }
    .tc-info-item .label {
      font-size: .7rem;
      text-transform: uppercase;
      letter-spacing: .08em;
      color: #6b6f82;
      margin-bottom: .3rem;
    }
    .tc-info-item .value {
      font-size: .95rem;
      font-weight: 600;
      color: #cfd1da;
      word-break: break-all;
    }

    /* ── Table ── */
    .tc-table {
      width: 100%;
      border-collapse: collapse;
      font-size: .875rem;
    }
    .tc-table thead th {
      text-align: left;
      font-size: .7rem;
      text-transform: uppercase;
      letter-spacing: .06em;
      color: #6b6f82;
      padding: .55rem .75rem;
      border-bottom: 1px solid #2a2d38;
    }
    .tc-table tbody td {
      padding: .65rem .75rem;
      border-bottom: 1px solid #1e2029;
    }
    .tc-table tbody tr:last-child td { border-bottom: none; }
    .tc-table tbody tr:hover td { background: #1e2029; }

    /* ── Badges ── */
    .badge {
      display: inline-flex;
      align-items: center;
      gap: .3rem;
      font-size: .75rem;
      font-weight: 600;
      padding: .2rem .55rem;
      border-radius: 6px;
    }
    .badge.ok    { background: #12261a; color: #4ade80; }
    .badge.miss  { background: #261212; color: #f87171; }

    .count-pill {
      display: inline-block;
      font-size: .8rem;
      font-weight: 600;
      min-width: 2.2rem;
      text-align: center;
      padding: .15rem .45rem;
      border-radius: 6px;
      background: #23252e;
      color: #a1a4b2;
    }
    .count-pill.has-data { background: #17261c; color: #4ade80; }

    /* ── Error block ── */
    .tc-error-block {
      background: #1f1215;
      border: 1px solid #ef444444;
      border-radius: 10px;
      padding: 1rem 1.25rem;
      font-family: 'Courier New', monospace;
      font-size: .82rem;
      color: #f87171;
      white-space: pre-wrap;
      word-break: break-all;
    }

    /* ── Footer ── */
    .tc-footer {
      text-align: center;
      margin-top: 1.75rem;
      font-size: .75rem;
      color: #4a4d5c;
    }
    .tc-footer a {
      color: #f5a623;
      text-decoration: none;
    }

    /* ── Responsive ── */
    @media (max-width: 540px) {
      .tc-card { padding: 1.15rem 1.15rem; }
      .tc-info-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<div class="tc-container">

  <!-- Header -->
  <div class="tc-header">
    <h1>🐝 BeeStation — Test de Conexión</h1>
    <p>Diagnóstico de la base de datos <strong><?= htmlspecialchars(defined('DB_NAME') ? DB_NAME : '—') ?></strong> · <?= date('d/m/Y H:i:s') ?></p>
  </div>

  <!-- Status banner -->
  <div class="tc-status <?= $overallStatus ?>">
    <span><?= $statusLabels[$overallStatus] ?></span>
  </div>

  <?php if (!$connectionOk): ?>
    <!-- ── Error de conexión ── -->
    <div class="tc-card">
      <h2><span class="icon">❌</span> Detalle del error</h2>
      <div class="tc-error-block"><?= htmlspecialchars($errorMsg) ?></div>
    </div>

  <?php else: ?>

    <!-- ── Información del servidor ── -->
    <div class="tc-card">
      <h2><span class="icon">🔗</span> Conexión al servidor</h2>
      <div class="tc-info-grid">
        <div class="tc-info-item">
          <div class="label">Host</div>
          <div class="value"><?= htmlspecialchars(DB_HOST) ?></div>
        </div>
        <div class="tc-info-item">
          <div class="label">Base de datos</div>
          <div class="value"><?= htmlspecialchars($dbName) ?></div>
        </div>
        <div class="tc-info-item">
          <div class="label">Usuario MySQL</div>
          <div class="value"><?= htmlspecialchars(DB_USER) ?></div>
        </div>
        <div class="tc-info-item">
          <div class="label">Versión del servidor</div>
          <div class="value"><?= htmlspecialchars($serverVersion) ?></div>
        </div>
        <div class="tc-info-item">
          <div class="label">Charset (BD)</div>
          <div class="value"><?= htmlspecialchars($charset) ?></div>
        </div>
        <div class="tc-info-item">
          <div class="label">Driver PDO</div>
          <div class="value"><?= htmlspecialchars($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) ?></div>
        </div>
      </div>
    </div>

    <!-- ── Tablas ── -->
    <div class="tc-card">
      <h2><span class="icon">🗄️</span> Tablas del esquema (<?= $foundTables ?>/<?= $totalTables ?>)</h2>
      <table class="tc-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Tabla</th>
            <th>Estado</th>
            <th>Registros</th>
          </tr>
        </thead>
        <tbody>
          <?php $i = 1; foreach ($tableResults as $table => $info): ?>
          <tr>
            <td style="color:#4a4d5c"><?= $i++ ?></td>
            <td><code style="color:#ddd"><?= htmlspecialchars($table) ?></code></td>
            <td>
              <?php if ($info['exists']): ?>
                <span class="badge ok">✔ Existe</span>
              <?php else: ?>
                <span class="badge miss">✖ Faltante</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($info['exists']): ?>
                <span class="count-pill <?= $info['count'] > 0 ? 'has-data' : '' ?>"><?= number_format($info['count']) ?></span>
              <?php else: ?>
                <span style="color:#4a4d5c">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <?php if (!empty($missingTables)): ?>
        <div style="margin-top:1rem; padding:.75rem 1rem; background:#261212; border-radius:8px; font-size:.82rem; color:#f87171;">
          ⚠️ Tablas faltantes: <strong><?= htmlspecialchars(implode(', ', $missingTables)) ?></strong>
          <br><small style="color:#a17070">Importa el esquema desde <code>database/beestation_sena.sql</code></small>
        </div>
      <?php endif; ?>
    </div>

  <?php endif; ?>

  <!-- Footer -->
  <div class="tc-footer">
    BeeStation © <?= date('Y') ?> · SENA Centro Minero Ambiental — El Bagre, Antioquia
    <br>
    <span style="color:#6b6f82">⚠️ Eliminar este archivo en entorno de producción.</span>
  </div>

</div>

</body>
</html>
