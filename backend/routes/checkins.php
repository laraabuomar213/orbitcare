<?php
// /api/checkins/* — the astronaut's own daily check-in (always the signed-in user).
declare(strict_types=1);

return function (Router $router): void {
    $astronaut = ['roles' => ['astronaut']];

    $router->get('/checkins/today', function (array $params, array $body, array $user): array {
        return (new CheckinService())->overview((int) $user['id']);
    }, $astronaut);

    $router->put('/checkins/today', function (array $params, array $body, array $user): array {
        return (new CheckinService())->save((int) $user['id'], $body);
    }, $astronaut + ['rate' => ['checkin_save', 30, 60]]);
};
