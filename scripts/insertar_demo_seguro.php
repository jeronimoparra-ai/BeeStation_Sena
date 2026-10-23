<?php
// Datos locales solo para visualización / demo — NO producción
// Respetar contrato: MySQL 127.0.0.1; este archivo es de prueba
require __DIR__ . '/../config/db.local.php';
$pdo = getPDO(); // fallará si DB no está; capturar
try {
  $pdo = null;
  if (function_exists('getPDO')) { $pdo = getPDO(); }
  if ($pdo) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO lecturas (id_colmena, tipo, valor, unidad, timestamp) VALUES (?, 'peso', ?, 'kg', NOW())");
    $stmt->execute([1, 18.70]);
    $stmt->execute([1, 15.20]);
    $stmt2 = $pdo->prepare("INSERT IGNORE INTO lecturas (id_colmena, tipo, valor, unidad, timestamp) VALUES (?, 'temperatura', ?, '°C', NOW())");
    $stmt2->execute([1, 34.5]);
    Write-Host("Datos de prueba insertados (demo). Sin impacto en producción.");
  } else {
    Write-Host("DB no conectada (seguro): sin inserciones. Datos listos para cuando MySQL vuelva.");
  }
} catch (Exception $e) {
  Write-Host("DB no disponible (esperado, no se modificó producción): " . $e->getMessage());
}
