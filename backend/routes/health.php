<?php
// /api/astronaut, /api/health/* — profile and replayed health data.
// Astronauts get their own data; mission admins pass ?astronaut_id= for a crew member they manage.
declare(strict_types=1);

return function (Router $router): void {
    $both = ['roles' => ['astronaut', 'mission_admin']];

    $router->get('/astronaut', function (array $params, array $body, array $user): array {
        $id = target_astronaut_id($user);
        $statement = db()->prepare(
            'SELECT u.id, u.full_name, u.email, a.astronaut_code, a.age, a.height_cm, a.weight_kg, a.data_source_record_id,
                    (mb.user_id IS NOT NULL) AS has_medical_background
             FROM users u JOIN astronauts a ON a.user_id = u.id
             LEFT JOIN astronaut_medical_background mb ON mb.user_id = u.id
             WHERE u.id = ?'
        );
        $statement->execute([$id]);
        $astronaut = $statement->fetch() ?: throw ApiException::notFound(t('err.astronaut_not_found'));

        $missions = array_map(fn ($m) => ['id' => (int) $m['id'], 'name' => $m['name'], 'status' => $m['status']],
            (new MissionService())->missionsFor(['id' => $id, 'role' => 'astronaut']));

        return [
            'id' => (int) $astronaut['id'],
            'name' => $astronaut['full_name'],
            'email' => (int) $user['id'] === $id ? $astronaut['email'] : null,
            'astronaut_code' => $astronaut['astronaut_code'],
            'age' => $astronaut['age'] === null ? null : (int) $astronaut['age'],
            'height_cm' => $astronaut['height_cm'] === null ? null : (float) $astronaut['height_cm'],
            'weight_kg' => $astronaut['weight_kg'] === null ? null : (float) $astronaut['weight_kg'],
            'medical_background_on_file' => (bool) $astronaut['has_medical_background'],
            'missions' => $missions,
            'data_source' => $astronaut['data_source_record_id'] ? (new HealthService())->sourceRecord($astronaut['data_source_record_id']) : null,
        ];
    }, $both);

    $router->put('/astronaut', function (array $params, array $body, array $user): array {
        $errors = [];
        $name = clean_text($body['full_name'] ?? '');
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['full_name'] = t('err.full_name_120');
        }
        $fields = ['age' => [18, 80], 'height_cm' => [120, 230], 'weight_kg' => [35, 200]];
        $values = [];
        foreach ($fields as $field => [$min, $max]) {
            $raw = $body[$field] ?? '';
            if (!is_numeric($raw) || (float) $raw < $min || (float) $raw > $max) {
                $errors[$field] = t('err.between', ['min' => $min, 'max' => $max]);
            }
            $values[$field] = round((float) $raw, 1);
        }
        if ($errors) {
            throw ApiException::validation($errors);
        }

        db_transaction(function (PDO $pdo) use ($user, $name, $values) {
            $pdo->prepare('UPDATE users SET full_name = ? WHERE id = ?')->execute([$name, $user['id']]);
            $pdo->prepare('UPDATE astronauts SET age = ?, height_cm = ?, weight_kg = ? WHERE user_id = ?')
                ->execute([(int) $values['age'], $values['height_cm'], $values['weight_kg'], $user['id']]);
        });
        audit('profile.update', (int) $user['id'], 'astronaut', $user['id'], ['fields' => ['full_name', 'age', 'height_cm', 'weight_kg']]);
        return ['saved' => true];
    }, ['roles' => ['astronaut']]);

    $router->get('/health/current', function (array $params, array $body, array $user): array {
        return (new HealthService())->current(target_astronaut_id($user));
    }, $both);

    $router->get('/health/waveform', function (array $params, array $body, array $user): array {
        return (new HealthService())->waveform(target_astronaut_id($user), (float) query_param('seconds', '8'));
    }, $both);

    $router->get('/health/sensors', function (array $params, array $body, array $user): array {
        return (new HealthService())->sensors(target_astronaut_id($user));
    }, $both);

    $router->get('/health/trends', function (array $params, array $body, array $user): array {
        return (new HealthService())->trend(
            target_astronaut_id($user),
            query_param('metric', 'heart_rate'),
            query_param('range', '1h'),
            (int) query_param('after_id', '0')
        );
    }, $both);

    $router->get('/health/history', function (array $params, array $body, array $user): array {
        return (new HealthService())->history(
            target_astronaut_id($user),
            query_param('metric', 'heart_rate'),
            (int) query_param('limit', '50'),
            (int) query_param('before_id', '0')
        );
    }, $both);

    $router->post('/health/replay/restart', function (array $params, array $body, array $user): array {
        $recordId = $user['data_source_record_id'] ?? throw new ApiException(409, 'no_data_source', t('err.no_data_source'));
        $service = new HealthService();
        return $service->replayInfo((new DataReplay())->restart((int) $user['id'], $recordId));
    }, ['roles' => ['astronaut'], 'rate' => ['replay_restart', 5, 60]]);
};
