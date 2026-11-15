<?php
// /api/device/readings — the astronaut's wearable (the OrbitCare vest / its Wokwi simulation)
// sends one reading at a time. No browser session: the device proves itself with the
// X-Device-Token header (DeviceService), so this route is the only one without CSRF.
declare(strict_types=1);

return function (Router $router): void {
    // Off by default (config devices.enabled / DEVICE_INPUT): live data is the PhysioNet recordings,
    // and with input off this route is not registered at all (404).
    if (!config()['devices']['enabled']) {
        return;
    }
    $router->post('/device/readings', function (array $params, array $body): array {
        $devices = new DeviceService();
        $device = $devices->authenticate($_SERVER['HTTP_X_DEVICE_TOKEN'] ?? null);
        return $devices->ingest($device, $body);
    }, ['auth' => false, 'csrf' => false, 'rate' => ['device_reading', 120, 60]]);
};
