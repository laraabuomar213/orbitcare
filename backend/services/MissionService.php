<?php
// Missions, membership and the permission rules built on them.
//
//  - An astronaut can see their own data and the missions they belong to.
//  - A mission admin (approved) can see the missions they manage and the health data of
//    astronauts in those missions — nothing else.
//  - Astronauts never see another astronaut's health data, even in the same mission.
declare(strict_types=1);

final class MissionService
{
    public function availableMissions(): array
    {
        return db()->query("SELECT id, code, name, status FROM missions WHERE status IN ('active', 'planning') ORDER BY status = 'active' DESC, name")->fetchAll();
    }

    public function missionsFor(array $user): array
    {
        $statement = db()->prepare(
            'SELECT m.id, m.code, m.name, m.status, m.description, mm.member_role
             FROM missions m JOIN mission_members mm ON mm.mission_id = m.id
             WHERE mm.user_id = ? ORDER BY m.status = "active" DESC, m.name'
        );
        $statement->execute([$user['id']]);
        $missions = $statement->fetchAll();

        // A pending (unapproved) admin can't manage anything yet.
        if ($user['role'] === 'mission_admin' && !is_approved_mission_admin($user)) {
            return [];
        }
        return $missions;
    }

    public function primaryMissionId(int $userId): ?int
    {
        $statement = db()->prepare("SELECT mission_id FROM mission_members WHERE user_id = ? AND member_role = 'astronaut' ORDER BY joined_at LIMIT 1");
        $statement->execute([$userId]);
        $id = $statement->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function managedMissionIds(array $user): array
    {
        if (!is_approved_mission_admin($user)) {
            return [];
        }
        $statement = db()->prepare("SELECT mission_id FROM mission_members WHERE user_id = ? AND member_role = 'mission_admin'");
        $statement->execute([$user['id']]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function canAccessMission(array $user, int $missionId): bool
    {
        return in_array($missionId, array_map('intval', array_column($this->missionsFor($user), 'id')), true);
    }

    public function canViewAstronaut(array $viewer, int $astronautId): bool
    {
        if ((int) $viewer['id'] === $astronautId) {
            return true;
        }
        $missionIds = $this->managedMissionIds($viewer);
        if (!$missionIds) {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count($missionIds), '?'));
        $statement = db()->prepare("SELECT 1 FROM mission_members WHERE user_id = ? AND member_role = 'astronaut' AND mission_id IN ({$placeholders})");
        $statement->execute([$astronautId, ...$missionIds]);
        return (bool) $statement->fetchColumn();
    }

    public function requireMissionAccess(array $user, int $missionId): array
    {
        $statement = db()->prepare('SELECT id, code, name, status, description FROM missions WHERE id = ?');
        $statement->execute([$missionId]);
        $mission = $statement->fetch() ?: throw ApiException::notFound(t('err.mission_not_found'));

        if (!$this->canAccessMission($user, $missionId)) {
            throw ApiException::forbidden(t('err.not_mission_member'));
        }
        $mission['id'] = (int) $mission['id'];
        $mission['can_manage'] = in_array($missionId, $this->managedMissionIds($user), true);
        return $mission;
    }

    // Members of a mission. Health status is only included for viewers who manage the mission.
    public function members(array $user, int $missionId): array
    {
        $mission = $this->requireMissionAccess($user, $missionId);

        $statement = db()->prepare(
            "SELECT u.id, u.full_name, mm.member_role, a.astronaut_code,
                    (SELECT MAX(pm.replayed_at) FROM processed_measurements pm WHERE pm.user_id = u.id) AS last_update,
                    (SELECT COUNT(*) FROM alerts al WHERE al.user_id = u.id AND al.status <> 'resolved' AND al.severity = 'RED') AS active_red,
                    (SELECT COUNT(*) FROM alerts al WHERE al.user_id = u.id AND al.status <> 'resolved' AND al.severity = 'YELLOW') AS active_yellow
             FROM mission_members mm
             JOIN users u ON u.id = mm.user_id
             LEFT JOIN astronauts a ON a.user_id = u.id
             WHERE mm.mission_id = ? AND (mm.member_role = 'astronaut' OR u.admin_approved_at IS NOT NULL)
             ORDER BY mm.member_role = 'mission_admin' DESC, u.full_name"
        );
        $statement->execute([$missionId]);

        return array_map(function (array $row) use ($mission, $user) {
            $member = [
                'id' => (int) $row['id'],
                'name' => $row['full_name'],
                'role' => $row['member_role'],
                'astronaut_code' => $row['astronaut_code'],
                'is_you' => (int) $row['id'] === (int) $user['id'],
            ];
            if ($row['member_role'] === 'astronaut' && ($mission['can_manage'] || $member['is_you'])) {
                $member['health'] = [
                    'alert_level' => $row['active_red'] > 0 ? 'RED' : ($row['active_yellow'] > 0 ? 'YELLOW' : 'GREEN'),
                    'active_alerts' => (int) $row['active_red'] + (int) $row['active_yellow'],
                    'last_update' => iso_time($row['last_update']),
                ];
            }
            return $member;
        }, $statement->fetchAll());
    }

    public function messages(array $user, int $missionId, int $afterId = 0): array
    {
        $this->requireMissionAccess($user, $missionId);
        $statement = db()->prepare(
            'SELECT mm.id, mm.body, mm.created_at, u.id AS user_id, u.full_name, u.role
             FROM mission_messages mm JOIN users u ON u.id = mm.user_id
             WHERE mm.mission_id = ? AND mm.id > ? AND mm.kind = \'text\' ORDER BY mm.id DESC LIMIT 100'
        );
        $statement->execute([$missionId, $afterId]);

        return array_reverse(array_map(fn ($row) => [
            'id' => (int) $row['id'],
            'body' => $row['body'],
            'created_at' => iso_time($row['created_at']),
            'author' => ['id' => (int) $row['user_id'], 'name' => $row['full_name'], 'role' => $row['role']],
            'is_you' => (int) $row['user_id'] === (int) $user['id'],
        ], $statement->fetchAll()));
    }

    public function postMessage(array $user, int $missionId, string $body): array
    {
        $this->requireMissionAccess($user, $missionId);
        $body = clean_multiline($body);
        if ($body === '') {
            throw ApiException::validation(['body' => t('err.message_empty')]);
        }
        if (mb_strlen($body) > 1000) {
            throw ApiException::validation(['body' => t('err.message_1000')]);
        }

        db()->prepare('INSERT INTO mission_messages (mission_id, user_id, body) VALUES (?, ?, ?)')->execute([$missionId, $user['id'], $body]);
        log_event('mission.message', ['mission_id' => $missionId, 'user_id' => $user['id'], 'status' => 'posted']);
        return $this->messages($user, $missionId, (int) db()->lastInsertId() - 1);
    }

    public function join(int $userId, int $missionId, string $memberRole): void
    {
        db()->prepare('INSERT IGNORE INTO mission_members (mission_id, user_id, member_role) VALUES (?, ?, ?)')
            ->execute([$missionId, $userId, $memberRole]);
        audit('mission.join', $userId, 'mission', $missionId, ['member_role' => $memberRole]);
    }

    // ---------- Admin: missions and crew ----------
    // Each approved admin creates their own missions; an astronaut belongs to at most one mission,
    // chosen by the admin (astronauts no longer pick a mission at sign-up).

    public function create(array $admin, array $input): array
    {
        $this->requireApprovedAdmin($admin);
        $name = clean_text($input['name'] ?? '');
        $description = clean_multiline($input['description'] ?? '');
        $status = in_array($input['status'] ?? 'active', ['planning', 'active'], true) ? ($input['status'] ?? 'active') : null;
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = t('err.mission_name');
        }
        if (mb_strlen($description) > 500) {
            $errors['description'] = t('err.mission_description');
        }
        if ($status === null) {
            $errors['status'] = t('err.mission_status');
        }
        if ($errors) {
            throw ApiException::validation($errors);
        }

        $code = $this->newCode($name);
        db()->prepare('INSERT INTO missions (code, name, status, description, created_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$code, $name, $status, $description ?: null, $admin['id']]);
        $missionId = (int) db()->lastInsertId();
        $this->join((int) $admin['id'], $missionId, 'mission_admin');
        audit('mission.create', (int) $admin['id'], 'mission', $missionId, ['code' => $code]);
        return $this->requireMissionAccess($admin, $missionId);
    }

    public function updateStatus(array $admin, int $missionId, string $status): array
    {
        $this->requireManaged($admin, $missionId);
        if (!in_array($status, ['planning', 'active', 'completed'], true)) {
            throw ApiException::validation(['status' => t('err.mission_status')]);
        }
        db()->prepare('UPDATE missions SET status = ? WHERE id = ?')->execute([$status, $missionId]);
        audit('mission.status', (int) $admin['id'], 'mission', $missionId, ['status' => $status]);
        return $this->requireMissionAccess($admin, $missionId);
    }

    // Registered astronauts (profile complete) who are not in any mission yet; optional search by name or ID.
    public function unassignedAstronauts(array $admin, string $search = ''): array
    {
        $this->requireApprovedAdmin($admin);
        $search = mb_substr(clean_text($search), 0, 60);
        $statement = db()->prepare(
            "SELECT u.id, u.full_name, a.astronaut_code
             FROM users u JOIN astronauts a ON a.user_id = u.id
             WHERE u.role = 'astronaut' AND u.profile_completed_at IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM mission_members mm WHERE mm.user_id = u.id AND mm.member_role = 'astronaut')
               AND (? = '' OR u.full_name LIKE ? OR a.astronaut_code LIKE ?)
             ORDER BY u.full_name LIMIT 50"
        );
        $like = '%' . addcslashes($search, '%_\\') . '%';
        $statement->execute([$search, $like, $like]);
        return array_map(fn ($row) => ['id' => (int) $row['id'], 'name' => $row['full_name'], 'astronaut_code' => $row['astronaut_code']], $statement->fetchAll());
    }

    public function addAstronaut(array $admin, int $missionId, int $astronautId): array
    {
        $this->requireManaged($admin, $missionId);
        $statement = db()->prepare(
            "SELECT u.id, u.full_name,
                    (SELECT mm.mission_id FROM mission_members mm WHERE mm.user_id = u.id AND mm.member_role = 'astronaut' LIMIT 1) AS current_mission
             FROM users u JOIN astronauts a ON a.user_id = u.id
             WHERE u.id = ? AND u.role = 'astronaut' AND u.profile_completed_at IS NOT NULL"
        );
        $statement->execute([$astronautId]);
        $astronaut = $statement->fetch() ?: throw ApiException::notFound(t('err.astronaut_not_found'));
        if ($astronaut['current_mission'] !== null) {
            throw new ApiException(409, 'already_in_mission', t('err.already_in_mission'));
        }

        $this->join($astronautId, $missionId, 'astronaut');
        $this->postCard($missionId, (int) $admin['id'], 'system', ['event' => 'joined', 'user_id' => $astronautId]);
        audit('mission.add_astronaut', (int) $admin['id'], 'mission', $missionId, ['astronaut_id' => $astronautId]);
        return $this->members($admin, $missionId);
    }

    public function removeAstronaut(array $admin, int $missionId, int $astronautId): array
    {
        $this->requireManaged($admin, $missionId);
        $deleted = db()->prepare("DELETE FROM mission_members WHERE mission_id = ? AND user_id = ? AND member_role = 'astronaut'");
        $deleted->execute([$missionId, $astronautId]);
        if ($deleted->rowCount() === 0) {
            throw ApiException::notFound(t('err.astronaut_not_found'));
        }
        $this->postCard($missionId, (int) $admin['id'], 'system', ['event' => 'removed', 'user_id' => $astronautId]);
        audit('mission.remove_astronaut', (int) $admin['id'], 'mission', $missionId, ['astronaut_id' => $astronautId]);
        return $this->members($admin, $missionId);
    }

    // A chat card (emergency / recovery / system) posted from an account.
    public function postCard(int $missionId, int $authorId, string $kind, array $payload): int
    {
        db()->prepare('INSERT INTO mission_messages (mission_id, user_id, body, kind, payload) VALUES (?, ?, \'\', ?, ?)')
            ->execute([$missionId, $authorId, $kind, json_encode($payload)]);
        return (int) db()->lastInsertId();
    }

    private function requireApprovedAdmin(array $user): void
    {
        if (($user['role'] ?? '') !== 'mission_admin' || !is_approved_mission_admin($user)) {
            throw ApiException::forbidden();
        }
    }

    private function requireManaged(array $admin, int $missionId): void
    {
        $this->requireApprovedAdmin($admin);
        if (!in_array($missionId, $this->managedMissionIds($admin), true)) {
            throw ApiException::forbidden(t('err.not_your_mission'));
        }
    }

    // Short unique code from the name, e.g. "Artemis Crew 3" → "ARTEMIS-4821"; Arabic names → "MSN-4821".
    private function newCode(string $name): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 8)) ?: 'MSN';
        do {
            $code = $prefix . '-' . random_int(1000, 9999);
            $exists = db()->prepare('SELECT 1 FROM missions WHERE code = ?');
            $exists->execute([$code]);
        } while ($exists->fetchColumn());
        return $code;
    }
}
