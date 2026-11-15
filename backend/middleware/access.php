<?php
// Decides whose health data a request is about, and enforces who may see it.
declare(strict_types=1);

// Astronauts: always themselves (asking for someone else is refused).
// Mission admins: must name an astronaut in a mission they manage.
function target_astronaut_id(array $user): int
{
    $requested = query_param('astronaut_id');

    if ($user['role'] === 'astronaut') {
        if ($requested !== null && (int) $requested !== (int) $user['id']) {
            throw ApiException::forbidden(t('err.own_data_only'));
        }
        return (int) $user['id'];
    }

    if ($requested === null || !ctype_digit($requested)) {
        throw ApiException::validation(['astronaut_id' => t('err.choose_astronaut')]);
    }
    if (!(new MissionService())->canViewAstronaut($user, (int) $requested)) {
        throw ApiException::forbidden(t('err.not_your_astronaut'));
    }
    return (int) $requested;
}

// Brings the replay of every astronaut in the given missions up to date, so alerts
// exist before a mission admin looks at them.
function sync_mission_astronauts(array $missionIds): void
{
    if (!$missionIds) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($missionIds), '?'));
    $statement = db()->prepare(
        "SELECT DISTINCT a.user_id AS id, a.data_source_record_id
         FROM mission_members mm JOIN astronauts a ON a.user_id = mm.user_id
         WHERE mm.member_role = 'astronaut' AND mm.mission_id IN ({$placeholders})"
    );
    $statement->execute($missionIds);

    $replay = new DataReplay();
    foreach ($statement->fetchAll() as $astronaut) {
        $session = $replay->currentSession((int) $astronaut['id']);
        if ($session !== null) {
            $replay->advance((int) $session['id']);
        }
    }
}
