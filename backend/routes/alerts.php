<?php
// /api/alerts/* — alert list, details and acknowledgment (access-checked in AlertService).
declare(strict_types=1);

return function (Router $router): void {
    $both = ['roles' => ['astronaut', 'mission_admin']];

    $router->get('/alerts', function (array $params, array $body, array $user): array {
        $missions = new MissionService();
        if ($user['role'] === 'astronaut') {
            (new HealthService())->syncedSession((new HealthService())->astronaut((int) $user['id']));
        } else {
            sync_mission_astronauts($missions->managedMissionIds($user));
        }

        return (new AlertService($missions))->list($user, [
            'filter' => query_param('filter', 'all'),
            'astronaut_id' => query_param('astronaut_id'),
            'mission_id' => query_param('mission_id'),
            'limit' => query_param('limit', '100'),
        ]);
    }, $both);

    $router->get('/alerts/{id}', function (array $params, array $body, array $user): array {
        return (new AlertService())->get($user, $params['id']);
    }, $both);

    $router->post('/alerts/{id}/acknowledge', function (array $params, array $body, array $user): array {
        return (new AlertService())->acknowledge($user, $params['id']);
    }, $both);
};
