<?php
// Reading and acknowledging alerts, with access checks.
// Astronauts see their own alerts; mission admins see alerts of the missions they manage.
declare(strict_types=1);

final class AlertService
{
    public const FILTERS = ['all', 'active', 'resolved', 'critical', 'warning'];

    public function __construct(
        private MissionService $missions = new MissionService(),
        private HealthAnalysis $analysis = new HealthAnalysis()
    ) {
    }

    public function list(array $viewer, array $filters = []): array
    {
        $filter = $filters['filter'] ?? 'all';
        if (!in_array($filter, self::FILTERS, true)) {
            throw ApiException::validation(['filter' => t('err.unknown_filter')]);
        }

        [$scopeSql, $params] = $this->scope($viewer, $filters);
        $where = [$scopeSql];
        if ($filter === 'active') {
            $where[] = "a.status <> 'resolved'";
        } elseif ($filter === 'resolved') {
            $where[] = "a.status = 'resolved'";
        } elseif ($filter === 'critical') {
            $where[] = "a.severity = 'RED'";
        } elseif ($filter === 'warning') {
            $where[] = "a.severity = 'YELLOW'";
        }

        $limit = max(1, min(200, (int) ($filters['limit'] ?? 100)));
        $statement = db()->prepare($this->select() . ' WHERE ' . implode(' AND ', $where)
            . " ORDER BY (a.status <> 'resolved') DESC, a.severity = 'RED' DESC, a.occurred_at DESC LIMIT {$limit}");
        $statement->execute($params);

        return array_map([$this, 'format'], $statement->fetchAll());
    }

    public function get(array $viewer, int $alertId): array
    {
        $statement = db()->prepare($this->select() . ' WHERE a.id = ?');
        $statement->execute([$alertId]);
        $alert = $statement->fetch() ?: throw ApiException::notFound(t('err.alert_not_found'));

        if (!$this->missions->canViewAstronaut($viewer, (int) $alert['user_id'])) {
            throw ApiException::forbidden(t('err.alert_forbidden'));
        }
        return $this->format($alert, true);
    }

    public function acknowledge(array $viewer, int $alertId): array
    {
        $alert = $this->get($viewer, $alertId);
        if ($alert['status'] === 'active') {
            db()->prepare("UPDATE alerts SET status = 'acknowledged', acknowledged_by = ?, acknowledged_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'active'")
                ->execute([$viewer['id'], $alertId]);
            audit('alert.acknowledge', (int) $viewer['id'], 'alert', $alertId);
        }
        return $this->get($viewer, $alertId);
    }

    // Restricts a query to the alerts this viewer may see.
    private function scope(array $viewer, array $filters): array
    {
        if ($viewer['role'] === 'astronaut') {
            return ['a.user_id = ?', [$viewer['id']]];
        }

        $missionIds = $this->missions->managedMissionIds($viewer);
        if (!empty($filters['mission_id'])) {
            $missionIds = array_values(array_intersect($missionIds, [(int) $filters['mission_id']]));
        }
        if (!$missionIds) {
            return ['1 = 0', []];
        }

        $placeholders = implode(',', array_fill(0, count($missionIds), '?'));
        $sql = "a.user_id IN (SELECT user_id FROM mission_members WHERE member_role = 'astronaut' AND mission_id IN ({$placeholders}))";
        $params = $missionIds;
        if (!empty($filters['astronaut_id'])) {
            $sql .= ' AND a.user_id = ?';
            $params[] = (int) $filters['astronaut_id'];
        }
        return [$sql, $params];
    }

    private function select(): string
    {
        return 'SELECT a.*, u.full_name AS astronaut_name, ast.astronaut_code, m.name AS mission_name,
                       ack.full_name AS acknowledged_by_name,
                       rm.source, rm.source_record_id, rm.source_row, rm.original_timestamp, rm.value AS source_value, rm.unit AS source_unit,
                       first.original_timestamp AS first_offset
                FROM alerts a
                JOIN users u ON u.id = a.user_id
                LEFT JOIN astronauts ast ON ast.user_id = a.user_id
                LEFT JOIN missions m ON m.id = a.mission_id
                LEFT JOIN users ack ON ack.id = a.acknowledged_by
                JOIN raw_measurements rm ON rm.id = a.source_measurement_id
                JOIN raw_measurements first ON first.id = a.first_measurement_id';
    }

    // Alerts are stored in English. When the thresholds that created them are still the current
    // ones, the texts are rebuilt from the stored values in the reader's language (same wording
    // as stored, for English); otherwise the stored English texts are shown unchanged.
    public function localize(array $row): array
    {
        $metric = $row['metric'];
        if ($row['threshold_version'] !== $this->analysis->version() || !isset($this->analysis->metrics()[$metric])) {
            return $row;
        }
        $language = current_language();
        $value = (float) $row['observed_value'];
        $direction = $this->analysis->direction($metric, $value);

        $row['title'] = $this->analysis->alertTitle($metric, $direction, $language);
        $row['explanation'] = $this->analysis->alertExplanation($metric, $value, $direction, $language);
        $row['rule'] = $this->analysis->ruleText($metric, $row['severity'], $direction, $language);
        $row['recommended_action'] = $this->analysis->recommendedAction($metric, $row['severity'], $language);
        foreach (['back_in_range', 'escalated'] as $reason) {
            if ($row['resolution_note'] === $this->analysis->resolutionNote($metric, $reason)) {
                $row['resolution_note'] = $this->analysis->resolutionNote($metric, $reason, $language);
            }
        }
        return $row;
    }

    private function format(array $row, bool $detailed = false): array
    {
        $row = $this->localize($row);
        $alert = [
            'id' => (int) $row['id'],
            'astronaut' => ['id' => (int) $row['user_id'], 'name' => $row['astronaut_name'], 'code' => $row['astronaut_code']],
            'mission' => $row['mission_id'] ? ['id' => (int) $row['mission_id'], 'name' => $row['mission_name']] : null,
            'severity' => $row['severity'],
            'status' => $row['status'],
            'title' => $row['title'],
            'metric' => $row['metric'],
            'observed_value' => (float) $row['observed_value'],
            'unit' => $row['unit'],
            'explanation' => $row['explanation'],
            'occurred_at' => iso_time($row['occurred_at']),
            'is_test_fixture' => (bool) $row['is_test_fixture'],
        ];
        if (!$detailed) {
            return $alert;
        }

        return $alert + [
            'rule' => $row['rule'],
            'threshold_version' => $row['threshold_version'],
            'recommended_action' => $row['recommended_action'],
            'acknowledged' => $row['acknowledged_at'] ? ['by' => $row['acknowledged_by_name'], 'at' => iso_time($row['acknowledged_at'])] : null,
            'resolved' => $row['resolved_at'] ? ['at' => iso_time($row['resolved_at']), 'note' => $row['resolution_note']] : null,
            'source_measurement' => [
                'raw_measurement_id' => (int) $row['source_measurement_id'],
                'first_measurement_id' => (int) $row['first_measurement_id'],
                'source' => $row['source'],
                'source_record_id' => $row['source_record_id'],
                'source_row' => (int) $row['source_row'],
                'original_timestamp_seconds' => (float) $row['original_timestamp'],
                'condition_started_at_seconds' => (float) $row['first_offset'],
                'value' => (float) $row['source_value'],
                'unit' => $row['source_unit'],
            ],
        ];
    }
}
