<?php
// The mission's crew chat: text messages and cards (emergency / recovery / crew changes),
// "seen by" for every message, and the crew status bar (who is online, who is in an emergency).
// Members see only their own mission. The status bar never shows medical details — only
// online / away and whether that person has an active emergency.
declare(strict_types=1);

final class CrewChatService
{
    private const ONLINE_SECONDS = 60;
    private const EMERGENCY_MINUTES = 120;

    public function __construct(
        private MissionService $missions = new MissionService(),
        private ?EmergencyService $emergencies = null
    ) {
        $this->emergencies ??= new EmergencyService($this->missions);
    }

    /**
     * Messages newer than $afterId (the newest 100), plus who has read up to where and the crew bar.
     * The client works out "seen by" from `reads`, so earlier messages update without re-sending them.
     */
    public function chat(array $viewer, int $missionId, int $afterId = 0): array
    {
        $mission = $this->missions->requireMissionAccess($viewer, $missionId);
        $this->emergencies->touchPresence((int) $viewer['id']);

        $statement = db()->prepare(
            'SELECT mm.id, mm.body, mm.kind, mm.payload, mm.created_at, u.id AS user_id, u.full_name, u.role
             FROM mission_messages mm JOIN users u ON u.id = mm.user_id
             WHERE mm.mission_id = ? AND mm.id > ? ORDER BY mm.id DESC LIMIT 100'
        );
        $statement->execute([$missionId, $afterId]);
        $messages = array_map(fn ($row) => $this->format($row, $viewer), array_reverse($statement->fetchAll()));

        return [
            'mission' => $mission,
            'messages' => $messages,
            'reads' => $this->reads($missionId),
            'crew' => $this->crewBar($missionId, (int) $viewer['id']),
        ];
    }

    public function send(array $viewer, int $missionId, string $body, int $afterId): array
    {
        $this->missions->postMessage($viewer, $missionId, $body);
        return $this->chat($viewer, $missionId, $afterId);
    }

    // The viewer has seen everything up to $messageId (never moves backwards).
    public function markRead(array $viewer, int $missionId, int $messageId): array
    {
        $this->missions->requireMissionAccess($viewer, $missionId);
        $latest = db()->prepare('SELECT COALESCE(MAX(id), 0) FROM mission_messages WHERE mission_id = ?');
        $latest->execute([$missionId]);
        $messageId = min($messageId, (int) $latest->fetchColumn());
        db()->prepare('UPDATE mission_members SET last_read_message_id = GREATEST(last_read_message_id, ?) WHERE mission_id = ? AND user_id = ?')
            ->execute([$messageId, $missionId, $viewer['id']]);
        return ['reads' => $this->reads($missionId)];
    }

    // ---------- internals ----------

    private function format(array $row, array $viewer): array
    {
        $message = [
            'id' => (int) $row['id'],
            'kind' => $row['kind'],
            'created_at' => iso_time($row['created_at']),
            'author' => ['id' => (int) $row['user_id'], 'name' => $row['full_name'], 'role' => $row['role']],
            'is_you' => (int) $row['user_id'] === (int) $viewer['id'],
        ];
        $payload = $row['payload'] ? json_decode($row['payload'], true) : [];

        if ($row['kind'] === 'text') {
            return $message + ['body' => $row['body']];
        }
        if ($row['kind'] === 'emergency' || $row['kind'] === 'recovery') {
            $items = $this->emergencies->load($payload['emergency_ids'] ?? [], (int) $viewer['id']);
            return $message + [
                'problems' => array_values(array_unique(array_column($items, 'problem'))),
                'emergencies' => $row['kind'] === 'emergency' ? $items : [],
                'report' => $row['kind'] === 'emergency' ? ($items[0]['report'] ?? null) : null,
                'resolved' => $items && !array_filter($items, fn ($e) => !$e['resolved']),
            ];
        }
        // health_note: a calm crew report for a sustained YELLOW alert (not an emergency, no alarm).
        // Reuses the alert's own bilingual text: what changed (title), what it may mean (explanation),
        // and how the crew can support (recommended action).
        if ($row['kind'] === 'health_note') {
            $alert = null;
            if (!empty($payload['alert_id'])) {
                $stmt = db()->prepare(
                    'SELECT metric, severity, observed_value, unit, threshold_version, title, explanation, recommended_action, resolution_note, occurred_at
                     FROM alerts WHERE id = ?'
                );
                $stmt->execute([(int) $payload['alert_id']]);
                $alert = $stmt->fetch() ?: null;
                if ($alert) {
                    $alert = (new AlertService())->localize($alert);
                }
            }
            return $message + [
                'metric' => $payload['metric'] ?? ($alert['metric'] ?? null),
                'title' => $alert['title'] ?? '',
                'detail' => $alert['explanation'] ?? '',
                'support' => $alert['recommended_action'] ?? '',
            ];
        }
        // system: crew changes ("Omar joined the crew")
        $subject = db()->prepare('SELECT full_name FROM users WHERE id = ?');
        $subject->execute([$payload['user_id'] ?? 0]);
        return $message + ['event' => $payload['event'] ?? '', 'subject' => $subject->fetchColumn() ?: null];
    }

    private function reads(int $missionId): array
    {
        $statement = db()->prepare(
            'SELECT u.id, u.full_name, mm.last_read_message_id FROM mission_members mm JOIN users u ON u.id = mm.user_id WHERE mm.mission_id = ?'
        );
        $statement->execute([$missionId]);
        return array_map(fn ($r) => ['user_id' => (int) $r['id'], 'name' => $r['full_name'], 'last_read' => (int) $r['last_read_message_id']], $statement->fetchAll());
    }

    // Crew status bar: name, role, online / away, active emergency. No medical details.
    private function crewBar(int $missionId, int $viewerId): array
    {
        $statement = db()->prepare(
            "SELECT u.id, u.full_name, mm.member_role, mm.last_seen_at,
                    (mm.last_seen_at IS NOT NULL AND mm.last_seen_at >= UTC_TIMESTAMP(3) - INTERVAL " . self::ONLINE_SECONDS . " SECOND) AS online,
                    EXISTS (SELECT 1 FROM crew_emergencies e WHERE e.astronaut_id = u.id AND e.mission_id = mm.mission_id AND e.resolved_at IS NULL
                            AND e.created_at >= UTC_TIMESTAMP() - INTERVAL " . self::EMERGENCY_MINUTES . " MINUTE) AS emergency
             FROM mission_members mm JOIN users u ON u.id = mm.user_id
             WHERE mm.mission_id = ? AND (mm.member_role = 'astronaut' OR u.admin_approved_at IS NOT NULL)
             ORDER BY mm.member_role = 'mission_admin' DESC, u.full_name"
        );
        $statement->execute([$missionId]);
        return array_map(fn ($r) => [
            'id' => (int) $r['id'],
            'name' => $r['full_name'],
            'role' => $r['member_role'],
            'online' => (bool) $r['online'],
            'last_seen_at' => iso_time($r['last_seen_at']),
            'emergency' => (bool) $r['emergency'],
            'is_you' => (int) $r['id'] === $viewerId,
        ], $statement->fetchAll());
    }
}
