<?php
// "My Missions" for an approved mission admin: the missions they manage and, for one mission,
// who needs attention — each astronaut's health status now, today's check-in, latest Alertness
// Check (compared with their own baseline), presence and any open crew emergency.
// Everything comes from data OrbitCare already records; nothing is estimated here.
declare(strict_types=1);

final class MissionBoardService
{
    private const ONLINE_SECONDS = 60;      // same rule as the crew chat status bar
    private const EMERGENCY_MINUTES = 120;  // same window as the crew alarm

    public function __construct(private MissionService $missions = new MissionService())
    {
    }

    // Missions this admin manages, with crew size and how many astronauts have an open emergency.
    public function missions(array $admin): array
    {
        $ids = $this->missions->managedMissionIds($admin);
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = db()->prepare(
            "SELECT m.id, m.code, m.name, m.status, m.description,
                    (SELECT COUNT(*) FROM mission_members x WHERE x.mission_id = m.id AND x.member_role = 'astronaut') AS astronauts,
                    (SELECT COUNT(DISTINCT e.astronaut_id) FROM crew_emergencies e
                      WHERE e.mission_id = m.id AND e.resolved_at IS NULL
                        AND e.created_at >= UTC_TIMESTAMP() - INTERVAL " . self::EMERGENCY_MINUTES . " MINUTE) AS emergencies
             FROM missions m WHERE m.id IN ({$placeholders})
             ORDER BY FIELD(m.status, 'active', 'planning', 'completed'), m.name"
        );
        $statement->execute($ids);
        return array_map(fn ($m) => [
            'id' => (int) $m['id'],
            'code' => $m['code'],
            'name' => $m['name'],
            'status' => $m['status'],
            'description' => $m['description'],
            'astronauts' => (int) $m['astronauts'],
            'emergencies' => (int) $m['emergencies'],
        ], $statement->fetchAll());
    }

    // One managed mission: summary tiles and the crew table.
    public function mission(array $admin, int $missionId): array
    {
        $mission = $this->missions->requireMissionAccess($admin, $missionId);
        if (!$mission['can_manage']) {
            throw ApiException::forbidden(t('err.not_your_mission'));
        }
        sync_mission_astronauts([$missionId]);

        $statement = db()->prepare(
            "SELECT u.id, u.full_name, a.astronaut_code, mm.last_seen_at,
                    (mm.last_seen_at IS NOT NULL AND mm.last_seen_at >= UTC_TIMESTAMP(3) - INTERVAL " . self::ONLINE_SECONDS . " SECOND) AS online,
                    EXISTS (SELECT 1 FROM crew_emergencies e WHERE e.astronaut_id = u.id AND e.mission_id = mm.mission_id AND e.resolved_at IS NULL
                            AND e.created_at >= UTC_TIMESTAMP() - INTERVAL " . self::EMERGENCY_MINUTES . " MINUTE) AS emergency,
                    (SELECT c.created_at FROM daily_checkins c WHERE c.user_id = u.id AND c.checkin_date = ?) AS checkin_at
             FROM mission_members mm
             JOIN users u ON u.id = mm.user_id
             LEFT JOIN astronauts a ON a.user_id = u.id
             WHERE mm.mission_id = ? AND mm.member_role = 'astronaut'
             ORDER BY u.full_name"
        );
        $statement->execute([(new CheckinService())->missionDay(), $missionId]);

        $health = new HealthService();
        $alertness = new AlertnessService();
        $crew = [];
        foreach ($statement->fetchAll() as $row) {
            $id = (int) $row['id'];
            try {
                $level = $health->current($id)['status']['level'];
            } catch (Throwable $error) {
                $level = 'NO_DATA';   // no recorded data source linked yet
            }
            $test = $alertness->overview($id);
            $crew[] = [
                'id' => $id,
                'name' => $row['full_name'],
                'astronaut_code' => $row['astronaut_code'],
                'health' => $level,
                'checkin_at' => iso_time($row['checkin_at']),
                'alertness' => $test['latest'] ? ['level' => $test['status']['level'], 'taken_at' => $test['latest']['taken_at']] : null,
                'online' => (bool) $row['online'],
                'last_seen_at' => iso_time($row['last_seen_at']),
                'emergency' => (bool) $row['emergency'],
            ];
        }

        $alerts = (new AlertService($this->missions))->list($admin, ['filter' => 'active', 'mission_id' => $missionId, 'limit' => 50]);
        return [
            'mission' => $mission,
            'stats' => [
                'astronauts' => count($crew),
                // Needs attention now: an open crew emergency or a critical (RED) health status.
                'attention' => count(array_filter($crew, fn ($c) => $c['emergency'] || $c['health'] === 'RED')),
                'checkins_today' => count(array_filter($crew, fn ($c) => $c['checkin_at'] !== null)),
                'active_alerts' => count($alerts),
            ],
            'crew' => $crew,
        ];
    }
}
