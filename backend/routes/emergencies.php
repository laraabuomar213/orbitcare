<?php
// /api/emergencies — crew emergency alarms (EmergencyService). Polled by every signed-in page.
declare(strict_types=1);

return function (Router $router): void {
    $crew = ['roles' => ['astronaut', 'mission_admin']];

    $router->get('/emergencies', function (array $params, array $body, array $user): array {
        return (new EmergencyService())->forViewer($user);
    }, $crew);

    // { response: "seen" | "on_my_way" }
    $router->post('/emergencies/{id}/respond', function (array $params, array $body, array $user): array {
        return (new EmergencyService())->respond($user, $params['id'], (string) ($body['response'] ?? ''));
    }, $crew + ['rate' => ['emergency_respond', 60, 60]]);
};
