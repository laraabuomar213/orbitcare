<?php
// /api/alertness — the signed-in astronaut's own Alertness Check (PVT-B + camera eye signs).
declare(strict_types=1);

return function (Router $router): void {
    $astronaut = ['roles' => ['astronaut']];

    $router->get('/alertness', function (array $params, array $body, array $user): array {
        return (new AlertnessService())->overview((int) $user['id']);
    }, $astronaut);

    $router->post('/alertness', function (array $params, array $body, array $user): array {
        return (new AlertnessService())->save((int) $user['id'], $body);
    }, $astronaut + ['rate' => ['alertness_save', 20, 600]]);
};
