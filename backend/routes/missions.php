<?php
// /api/missions/*, /api/admin/overview — missions, members, mission alert feed, messages,
// and the mission admin overview.
declare(strict_types=1);

return function (Router $router): void {
    $both = ['roles' => ['astronaut', 'mission_admin']];

    $admin = ['roles' => ['mission_admin']];

    // ---------- Admin: own missions and their crew ----------

    // { name, description?, status?: planning|active } — the admin becomes the mission's admin.
    $router->post('/missions', function (array $params, array $body, array $user): array {
        return (new MissionService())->create($user, $body);
    }, $admin + ['rate' => ['mission_create', 20, 600]]);

    $router->post('/missions/{id}/status', function (array $params, array $body, array $user): array {
        return (new MissionService())->updateStatus($user, $params['id'], (string) ($body['status'] ?? ''));
    }, $admin);

    // "My Missions": the missions this admin manages, and one mission's tiles + crew table.
    $router->get('/admin/missions', function (array $params, array $body, array $user): array {
        return (new MissionBoardService())->missions($user);
    }, $admin);

    $router->get('/admin/missions/{id}', function (array $params, array $body, array $user): array {
        return (new MissionBoardService())->mission($user, $params['id']);
    }, $admin);

    // Astronauts not in any mission yet (?q= searches name or astronaut ID).
    $router->get('/admin/astronauts', function (array $params, array $body, array $user): array {
        return (new MissionService())->unassignedAstronauts($user, (string) query_param('q', ''));
    }, $admin);

    $router->post('/missions/{id}/members', function (array $params, array $body, array $user): array {
        return (new MissionService())->addAstronaut($user, $params['id'], (int) ($body['astronaut_id'] ?? 0));
    }, $admin);

    $router->post('/missions/{id}/members/{astronaut}/remove', function (array $params, array $body, array $user): array {
        return (new MissionService())->removeAstronaut($user, $params['id'], $params['astronaut']);
    }, $admin);

    // ---------- Crew chat (messages + cards, "seen by", crew status bar) ----------

    $router->get('/missions/{id}/chat', function (array $params, array $body, array $user): array {
        return (new CrewChatService())->chat($user, $params['id'], (int) query_param('after_id', '0'));
    }, $both);

    // { body, after_id } → the new messages since after_id
    $router->post('/missions/{id}/chat', function (array $params, array $body, array $user): array {
        return (new CrewChatService())->send($user, $params['id'], (string) ($body['body'] ?? ''), (int) ($body['after_id'] ?? 0));
    }, $both + ['rate' => ['mission_message', 30, 60]]);

    // { message_id } — everything up to this message has been seen
    $router->post('/missions/{id}/read', function (array $params, array $body, array $user): array {
        return (new CrewChatService())->markRead($user, $params['id'], (int) ($body['message_id'] ?? 0));
    }, $both);

    $router->get('/missions', function (array $params, array $body, array $user): array {
        return array_map(fn ($m) => [
            'id' => (int) $m['id'], 'code' => $m['code'], 'name' => $m['name'], 'status' => $m['status'], 'member_role' => $m['member_role'],
        ], (new MissionService())->missionsFor($user));
    }, $both);

    $router->get('/missions/{id}', function (array $params, array $body, array $user): array {
        return (new MissionService())->requireMissionAccess($user, $params['id']);
    }, $both);

    $router->get('/missions/{id}/members', function (array $params, array $body, array $user): array {
        return (new MissionService())->members($user, $params['id']);
    }, $both);

    // Mission alert feed. Mission admins see the whole crew; astronauts see only their own alerts.
    $router->get('/missions/{id}/alerts', function (array $params, array $body, array $user): array {
        $missions = new MissionService();
        $missions->requireMissionAccess($user, $params['id']);
        if ($user['role'] === 'mission_admin') {
            sync_mission_astronauts([$params['id']]);
        }
        $filters = ['filter' => query_param('filter', 'all'), 'mission_id' => $params['id'], 'limit' => 50];
        $alerts = (new AlertService($missions))->list($user, $filters);
        if ($user['role'] === 'astronaut') {
            $alerts = array_values(array_filter($alerts, fn ($a) => ($a['mission']['id'] ?? null) === $params['id']));
        }
        return $alerts;
    }, $both);

    $router->get('/missions/{id}/messages', function (array $params, array $body, array $user): array {
        return (new MissionService())->messages($user, $params['id'], (int) query_param('after_id', '0'));
    }, $both);

    $router->post('/missions/{id}/messages', function (array $params, array $body, array $user): array {
        return (new MissionService())->postMessage($user, $params['id'], (string) ($body['body'] ?? ''));
    }, $both + ['rate' => ['mission_message', 30, 60]]);

    // Mission admin dashboard: crew status + active mission alerts for one managed mission.
    $router->get('/admin/overview', function (array $params, array $body, array $user): array {
        $missions = new MissionService();
        $managed = $missions->missionsFor($user);
        $managed = array_values(array_filter($managed, fn ($m) => $m['member_role'] === 'mission_admin'));
        if (!$managed) {
            return ['missions' => [], 'mission' => null, 'crew' => [], 'alerts' => []];
        }

        $missionId = (int) query_param('mission_id', (string) $managed[0]['id']);
        $mission = $missions->requireMissionAccess($user, $missionId);
        if (!$mission['can_manage']) {
            throw ApiException::forbidden(t('err.not_your_mission'));
        }

        sync_mission_astronauts([$missionId]);
        $health = new HealthService();
        $crew = [];
        foreach ($missions->members($user, $missionId) as $member) {
            if ($member['role'] !== 'astronaut') {
                continue;
            }
            $current = $health->current($member['id']);
            $crew[] = $member + [
                'status' => $current['status'],
                'metrics' => $current['metrics'],
                'replay' => $current['replay'],
                'last_update' => $current['last_update'],
            ];
        }

        return [
            'missions' => array_map(fn ($m) => ['id' => (int) $m['id'], 'name' => $m['name'], 'status' => $m['status']], $managed),
            'mission' => $mission,
            'crew' => $crew,
            'alerts' => (new AlertService($missions))->list($user, ['filter' => 'active', 'mission_id' => $missionId, 'limit' => 50]),
        ];
    }, ['roles' => ['mission_admin']]);
};
