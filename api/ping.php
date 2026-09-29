<?php
/**
 * api/ping.php
 * ----------------------------------------------------------------
 * Endpoint ultraligero usado por el ESP32 para autodescubrimiento
 * (Subnet Scanning) de la IP del servidor en la red local.
 * ----------------------------------------------------------------
 */

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");

echo json_encode([
    'app' => 'BeeStation',
    'status' => 'ok',
    'version' => '1.0'
]);
