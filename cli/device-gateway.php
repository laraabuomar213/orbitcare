<?php
// A small door for the Wokwi simulation to reach OrbitCare running on this computer.
//
// The simulated ESP32 runs in Wokwi's cloud, so it needs a public URL (e.g. ngrok). Pointing ngrok
// at Apache would publish ALL of XAMPP — including phpMyAdmin, which XAMPP only protects with
// "local requests only", and ngrok's requests arrive from this computer. So ngrok points here
// instead: this router answers ONLY  POST /api/device/readings  and returns 404 for everything else.
//
//   php -S 127.0.0.1:8091 cli/device-gateway.php
//   ngrok http 8091
//   firmware SERVER_URL = "https://<your-ngrok-address>/api/device/readings"
//
// Onboard a real spacecraft none of this is needed: the vest talks to the onboard OrbitCare server
// over the local network, with no internet.
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (PHP_SAPI !== 'cli-server' || $path !== '/api/device/readings' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"ok":false,"error":{"code":"not_found","message":"Only POST /api/device/readings is served here."}}';
    return true;
}

$_GET['route'] = 'device/readings';
require __DIR__ . '/../api/index.php';
return true;
