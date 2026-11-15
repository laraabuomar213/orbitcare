<?php
// Crew emergency alarms. When an astronaut is in danger — a critical (RED) alert from their
// measurements, or a dangerous symptom they write to the AI (chest pain, can't breathe, fainting,
// confusion, difficulty speaking) — their account posts an emergency card in the mission's crew
// chat, and every crewmate hears a siren and a voice: "<name>: <problem>". It repeats on each
// member's screen until that member answers "seen" or "on my way"; everyone sees who is coming.
//
// The card carries a short situation report for the crew — what the astronaut feels NOW (their
// latest words and current readings only, nothing from their history) and what the crew should do
// for them. The online AI writes it when there is a link; otherwise OrbitCare's own first-response
// steps are shown. Not a diagnosis.
//
// Privacy: the crew gets the name, the problem, this report and the time — never the medical record.
// The AI never receives the name.
declare(strict_types=1);

final class EmergencyService
{
    public const RESPONSES = ['seen', 'on_my_way'];
    private const RING_FOR_MINUTES = 120;          // older, unanswered emergencies stop ringing
    private const SYMPTOM_REPEAT_MINUTES = 30;     // the same symptom does not raise a new alarm within this time
    private const HEALTH_NOTE_REPEAT_MINUTES = 30; // one calm crew health note per metric within this time
    private const REPORT_RETRY_SECONDS = 60;       // a report that failed (no link) is tried again after this
    public const REPORT_FIELDS = ['situation_en', 'crew_actions_en', 'situation_ar', 'crew_actions_ar'];

    private AIProvider $provider;

    public function __construct(private MissionService $missions = new MissionService(), ?AIProvider $provider = null)
    {
        $this->provider = $provider ?? AIService::providerFromConfig();
    }

    // ---------- Raising and resolving ----------

    // Called by the alert engine when a RED alert is created.
    public function raiseForAlert(int $astronautId, int $missionId, int $alertId): void
    {
        db()->prepare("INSERT INTO crew_emergencies (mission_id, astronaut_id, kind, alert_id, created_at) VALUES (?, ?, 'vital', ?, UTC_TIMESTAMP(3))")
            ->execute([$missionId, $astronautId, $alertId]);
        $id = (int) db()->lastInsertId();
        $this->logRaised($id, $astronautId, $missionId, 'vital');
        $this->missions->postCard($missionId, $astronautId, 'emergency', ['emergency_ids' => [$id]]);
    }

    // Called when an alert resolves (back in range for the sustain time): the alarm stops ringing
    // and a "back to normal" card is posted in the crew chat.
    public function resolveAlert(int $alertId): void
    {
        $open = db()->prepare('SELECT id, mission_id, astronaut_id FROM crew_emergencies WHERE alert_id = ? AND resolved_at IS NULL');
        $open->execute([$alertId]);
        foreach ($open->fetchAll() as $row) {
            db()->prepare('UPDATE crew_emergencies SET resolved_at = UTC_TIMESTAMP(3) WHERE id = ?')->execute([$row['id']]);
            $this->missions->postCard((int) $row['mission_id'], (int) $row['astronaut_id'], 'recovery', ['emergency_ids' => [(int) $row['id']]]);
        }
    }

    /**
     * Called by the consultation when the astronaut's latest message has red-flag symptoms.
     * One card per mission covers all symptoms of that message.
     * @param string[] $symptomKeys red-flag symptom keys from SymptomDetector
     */
    public function raiseForSymptoms(int $astronautId, array $symptomKeys, string $latestText = ''): int
    {
        $raised = 0;
        foreach ($this->missions->missionsFor(['id' => $astronautId, 'role' => 'astronaut']) as $mission) {
            $ids = [];
            foreach (array_unique($symptomKeys) as $key) {
                $recent = db()->prepare(
                    "SELECT COUNT(*) FROM crew_emergencies WHERE astronaut_id = ? AND mission_id = ? AND kind = 'symptom' AND symptom_key = ?
                       AND created_at >= UTC_TIMESTAMP() - INTERVAL " . self::SYMPTOM_REPEAT_MINUTES . ' MINUTE'
                );
                $recent->execute([$astronautId, $mission['id'], $key]);
                if ((int) $recent->fetchColumn() > 0) {
                    continue;
                }
                db()->prepare("INSERT INTO crew_emergencies (mission_id, astronaut_id, kind, symptom_key, trigger_text, created_at) VALUES (?, ?, 'symptom', ?, ?, UTC_TIMESTAMP(3))")
                    ->execute([$mission['id'], $astronautId, $key, mb_substr($latestText, 0, 2000) ?: null]);
                $ids[] = (int) db()->lastInsertId();
                $this->logRaised(end($ids), $astronautId, (int) $mission['id'], 'symptom');
            }
            if ($ids) {
                $this->missions->postCard((int) $mission['id'], $astronautId, 'emergency', ['emergency_ids' => $ids]);
                $raised += count($ids);
            }
        }
        return $raised;
    }

    // Called by the alert engine for a sustained YELLOW alert: posts a calm, non-ringing health note
    // to the crew chat (what changed, what it may mean, how the crew can support) — NOT an emergency,
    // no alarm, no crew_emergencies row. The report reuses the alert's own bilingual text, so it works
    // offline. One note per metric per mission within HEALTH_NOTE_REPEAT_MINUTES.
    public function postHealthNote(int $astronautId, int $missionId, int $alertId, string $metric): void
    {
        $recent = db()->prepare(
            "SELECT COUNT(*) FROM mission_messages
             WHERE mission_id = ? AND kind = 'health_note'
               AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.metric')) = ?
               AND created_at >= UTC_TIMESTAMP() - INTERVAL " . self::HEALTH_NOTE_REPEAT_MINUTES . ' MINUTE'
        );
        $recent->execute([$missionId, $metric]);
        if ((int) $recent->fetchColumn() > 0) {
            return;
        }
        $this->missions->postCard($missionId, $astronautId, 'health_note', ['alert_id' => $alertId, 'metric' => $metric]);
        log_event('crew.health_note', ['mission_id' => $missionId, 'astronaut_id' => $astronautId, 'alert_id' => $alertId, 'metric' => $metric, 'status' => 'posted']);
    }

    // ---------- Reading ----------

    /**
     * What one member's screen needs: emergencies of crewmates (ringing until answered) and the
     * member's own emergencies (to show that the crew was alerted and who is coming).
     */
    public function forViewer(array $viewer): array
    {
        $missionIds = array_map('intval', array_column($this->missions->missionsFor($viewer), 'id'));
        if (!$missionIds) {
            return ['crew' => [], 'mine' => []];
        }
        $this->touchPresence((int) $viewer['id']);
        $this->syncCrewReplays($missionIds);

        $placeholders = implode(',', array_fill(0, count($missionIds), '?'));
        $statement = db()->prepare(
            "SELECT e.id FROM crew_emergencies e
             WHERE e.mission_id IN ({$placeholders}) AND e.created_at >= UTC_TIMESTAMP() - INTERVAL " . self::RING_FOR_MINUTES . ' MINUTE
             ORDER BY e.created_at DESC LIMIT 20'
        );
        $statement->execute($missionIds);

        $crew = [];
        $mine = [];
        foreach ($this->load($statement->fetchAll(PDO::FETCH_COLUMN), (int) $viewer['id']) as $item) {
            if ($item['astronaut']['id'] === (int) $viewer['id']) {
                $mine[] = $item;
            } else {
                $crew[] = $item;
            }
        }
        return ['crew' => $crew, 'mine' => $mine];
    }

    /**
     * Emergencies by id, formatted for one viewer (used by the alarm and by the chat cards).
     * Generates the crew report the first time it is needed.
     * @param int[] $ids
     */
    public function load(array $ids, int $viewerId): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = db()->prepare(
            "SELECT e.*, u.full_name, a.metric, a.observed_value
             FROM crew_emergencies e JOIN users u ON u.id = e.astronaut_id LEFT JOIN alerts a ON a.id = e.alert_id
             WHERE e.id IN ({$placeholders}) ORDER BY e.created_at DESC"
        );
        $statement->execute($ids);
        $rows = $statement->fetchAll();
        $this->ensureReports($rows);
        return array_map(fn ($row) => $this->format($row, $viewerId), $rows);
    }

    public function respond(array $viewer, int $emergencyId, string $response): array
    {
        if (!in_array($response, self::RESPONSES, true)) {
            throw ApiException::validation(['response' => t('err.emergency_response')]);
        }
        $statement = db()->prepare('SELECT * FROM crew_emergencies WHERE id = ?');
        $statement->execute([$emergencyId]);
        $emergency = $statement->fetch();
        if (!$emergency || !$this->missions->canAccessMission($viewer, (int) $emergency['mission_id'])) {
            throw ApiException::notFound(t('err.emergency_not_found'));
        }
        db()->prepare(
            'INSERT INTO crew_emergency_responses (emergency_id, user_id, response, responded_at) VALUES (?, ?, ?, UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE response = VALUES(response), responded_at = VALUES(responded_at)'
        )->execute([$emergencyId, $viewer['id'], $response]);
        audit('emergency.respond', (int) $viewer['id'], 'crew_emergency', $emergencyId, ['response' => $response]);
        log_event('emergency.response', ['emergency_id' => $emergencyId, 'user_id' => (int) $viewer['id'], 'response' => $response]);
        return $this->forViewer($viewer);
    }

    // The member is using OrbitCare now (crew status bar). Written at most every 20 s.
    public function touchPresence(int $userId): void
    {
        db()->prepare('UPDATE mission_members SET last_seen_at = UTC_TIMESTAMP(3) WHERE user_id = ? AND (last_seen_at IS NULL OR last_seen_at < UTC_TIMESTAMP(3) - INTERVAL 20 SECOND)')
            ->execute([$userId]);
    }

    // ---------- Formatting ----------

    private function format(array $row, int $viewerId): array
    {
        $responses = db()->prepare(
            'SELECT r.user_id, r.response, r.responded_at, u.full_name FROM crew_emergency_responses r JOIN users u ON u.id = r.user_id
             WHERE r.emergency_id = ? ORDER BY r.responded_at'
        );
        $responses->execute([$row['id']]);
        $answers = $responses->fetchAll();
        $mine = array_values(array_filter($answers, fn ($a) => (int) $a['user_id'] === $viewerId))[0] ?? null;

        $name = $row['full_name'];
        $problem = $this->problem($row);
        return [
            'id' => (int) $row['id'],
            'mission_id' => (int) $row['mission_id'],
            'astronaut' => ['id' => (int) $row['astronaut_id'], 'name' => $name],
            'kind' => $row['kind'],
            'problem' => $problem,
            // Spoken by the browser in the reader's language, plus English for devices without that voice.
            'speech' => t('emergency.speech', ['name' => $name, 'problem' => $problem]),
            'speech_en' => translate_in('en', 'emergency.speech', ['name' => $name, 'problem' => $this->problem($row, 'en')]),
            'report' => $this->report($row),
            'created_at' => iso_time($row['created_at']),
            'resolved' => $row['resolved_at'] !== null,
            'responses' => array_map(fn ($a) => ['name' => $a['full_name'], 'response' => $a['response'], 'at' => iso_time($a['responded_at'])], $answers),
            'my_response' => $mine['response'] ?? null,
        ];
    }

    // "Low blood oxygen (SpO₂)" / "Chest pain" — in the given language.
    private function problem(array $row, ?string $language = null): string
    {
        $language ??= current_language();
        if ($row['kind'] === 'vital' && $row['metric'] !== null) {
            $analysis = new HealthAnalysis();
            if (isset($analysis->metrics()[$row['metric']])) {
                return $analysis->alertTitle($row['metric'], $analysis->direction($row['metric'], (float) $row['observed_value']), $language);
            }
        }
        if ($row['kind'] === 'symptom' && $row['symptom_key'] !== null) {
            return translate_in($language, "ai.symptom.{$row['symptom_key']}");
        }
        return translate_in($language, 'emergency.unknown_problem');
    }

    // ---------- Crew report: what they feel now + what the crew should do ----------

    // The AI report in the reader's language, or OrbitCare's own first-response steps.
    private function report(array $row): array
    {
        $language = current_language() === 'ar' ? 'ar' : 'en';
        $stored = $row['report'] ? json_decode($row['report'], true) : null;
        if ($stored && !empty($stored["crew_actions_{$language}"])) {
            return [
                'source' => 'ai',
                'situation' => (string) $stored["situation_{$language}"],
                'crew_actions' => array_values(array_filter((array) $stored["crew_actions_{$language}"], 'is_string')),
            ];
        }
        $key = $row['kind'] === 'symptom' ? (string) $row['symptom_key'] : 'vital_' . ($row['metric'] ?? 'other');
        $specific = translate_in($language, "emergency.action.{$key}");
        $actions = [t('emergency.action.stay')];
        if ($specific !== "emergency.action.{$key}") {
            $actions[] = $specific;
        }
        $actions[] = t('emergency.action.call_officer');
        $actions[] = t('emergency.action.kit');
        return ['source' => 'rules', 'situation' => t('emergency.rules_situation', ['problem' => $this->problem($row)]), 'crew_actions' => $actions];
    }

    // Writes the AI report once per emergency card (all emergencies raised together share it).
    private function ensureReports(array &$rows): void
    {
        if (!$this->provider->isConfigured()) {
            return;
        }
        foreach ($rows as &$row) {
            if ($row['report'] !== null || $row['resolved_at'] !== null) {
                continue;
            }
            // Siblings: the same astronaut's emergencies raised within the same second (one card).
            $group = db()->prepare(
                'SELECT id, kind, symptom_key, alert_id, trigger_text FROM crew_emergencies
                 WHERE astronaut_id = ? AND mission_id = ? AND report IS NULL AND ABS(TIMESTAMPDIFF(SECOND, created_at, ?)) <= 1'
            );
            $group->execute([$row['astronaut_id'], $row['mission_id'], $row['created_at']]);
            $members = $group->fetchAll();
            $ids = array_map('intval', array_column($members, 'id'));
            if (!$ids) {
                continue;   // a sibling in this card already received the report
            }

            // Only one poll generates it; a failed attempt is retried after a minute.
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $claim = db()->prepare(
                "UPDATE crew_emergencies SET report_claimed_at = UTC_TIMESTAMP() WHERE id IN ({$placeholders})
                 AND (report_claimed_at IS NULL OR report_claimed_at < UTC_TIMESTAMP() - INTERVAL " . self::REPORT_RETRY_SECONDS . ' SECOND)'
            );
            $claim->execute($ids);
            if ($claim->rowCount() === 0) {
                continue;
            }

            $report = $this->generateReport($row, $members);
            if ($report === null) {
                continue;
            }
            $json = json_encode($report, JSON_UNESCAPED_UNICODE);
            db()->prepare("UPDATE crew_emergencies SET report = ?, report_source = 'ai' WHERE id IN ({$placeholders})")
                ->execute([$json, ...$ids]);
            $generated[] = [$ids, $json];
        }
        unset($row);
        // Every emergency of the same card shows the same report.
        foreach ($generated ?? [] as [$ids, $json]) {
            foreach ($rows as &$row) {
                if (in_array((int) $row['id'], $ids, true)) {
                    $row['report'] = $json;
                }
            }
            unset($row);
        }
    }

    private function generateReport(array $row, array $members): ?array
    {
        $problems = array_map(fn ($m) => $this->problem(['kind' => $m['kind'], 'symptom_key' => $m['symptom_key'], 'metric' => $row['metric'], 'observed_value' => $row['observed_value']], 'en'), $members);
        $words = array_values(array_unique(array_filter(array_column($members, 'trigger_text'))));
        $current = [];
        try {
            foreach ((new HealthService())->current((int) $row['astronaut_id'])['metrics'] as $metric) {
                if ($metric['available']) {
                    $current[] = ['measurement' => translate_in('en', "metric.label.{$metric['metric']}"), 'value' => $metric['value'],
                        'unit' => $metric['unit'], 'classification' => $metric['classification']];
                }
            }
        } catch (Throwable $error) {
            // No recorded data source: the report uses the words only.
        }

        $result = $this->provider->run('crew_emergency_report', [
            'instructions' => 'You support the CREW of an astronaut who is in a medical emergency on a space mission. '
                . 'Using ONLY the data given (what the astronaut says they feel right now and their current readings), write: '
                . '(1) situation: 1–2 calm sentences for the crew describing how the astronaut is right now — no diagnosis, no history, no name; '
                . '(2) crew_actions: 3–5 short, practical first-response steps the crew can do now (stay with them, positioning, '
                . 'breathing, what to watch, follow the crew medical/oxygen protocol, contact the mission medical officer immediately). '
                . 'Never suggest giving, stopping or changing medication. Write each in English (_en) and in Arabic (_ar).',
            'output_fields' => self::REPORT_FIELDS,
            'output_schema' => [
                'type' => 'OBJECT',
                'properties' => [
                    'situation_en' => ['type' => 'STRING'],
                    'crew_actions_en' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                    'situation_ar' => ['type' => 'STRING'],
                    'crew_actions_ar' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                ],
                'required' => self::REPORT_FIELDS,
                'propertyOrdering' => self::REPORT_FIELDS,
            ],
            'problems' => $problems,
            'astronaut_words_now' => $words,
            'current_readings' => $current,
        ]);
        if (!$result->ok()) {
            return null;
        }
        // Steps as a list; a single "1. … 2. …" string (older replies) is split into its steps.
        $clean = function (mixed $list): array {
            if (is_string($list)) {
                $list = preg_split('/(?:^|\s+|\n)\d+[.)]\s+|\n+/u', $list);
            }
            $steps = array_map(fn ($s) => is_string($s) ? trim($s) : '', (array) $list);
            return array_slice(array_values(array_filter($steps, fn ($s) => $s !== '' && mb_strlen($s) <= 300)), 0, 6);
        };
        $report = [
            'situation_en' => mb_substr((string) $result->output['situation_en'], 0, 600),
            'crew_actions_en' => $clean($result->output['crew_actions_en']),
            'situation_ar' => mb_substr((string) $result->output['situation_ar'], 0, 600),
            'crew_actions_ar' => $clean($result->output['crew_actions_ar']),
        ];
        return $report['crew_actions_en'] && $report['crew_actions_ar'] ? $report : null;
    }

    // ---------- internals ----------

    // Recorded data is replayed on request; bring every crew member's replay up to "now" so a
    // critical value is detected even while that astronaut is not looking at OrbitCare.
    private function syncCrewReplays(array $missionIds): void
    {
        $placeholders = implode(',', array_fill(0, count($missionIds), '?'));
        $statement = db()->prepare(
            "SELECT DISTINCT a.user_id, a.data_source_record_id FROM mission_members mm JOIN astronauts a ON a.user_id = mm.user_id
             WHERE mm.mission_id IN ({$placeholders}) AND mm.member_role = 'astronaut' AND a.data_source_record_id IS NOT NULL"
        );
        $statement->execute($missionIds);
        $health = new HealthService();
        foreach ($statement->fetchAll() as $astronaut) {
            try {
                $health->syncedSession(['id' => (int) $astronaut['user_id'], 'data_source_record_id' => $astronaut['data_source_record_id']]);
            } catch (Throwable $error) {
                log_event('emergency.sync_failed', ['user_id' => (int) $astronaut['user_id'], 'error' => $error->getMessage()], 'warning');
            }
        }
    }

    private function logRaised(int $id, int $astronautId, int $missionId, string $kind): void
    {
        audit('emergency.raise', null, 'crew_emergency', $id, ['astronaut_id' => $astronautId, 'kind' => $kind]);
        log_event('emergency.raised', ['emergency_id' => $id, 'mission_id' => $missionId, 'astronaut_id' => $astronautId, 'kind' => $kind]);
    }
}
