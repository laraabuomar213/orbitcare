<?php
// Deterministic alert engine. Fed one replayed measurement at a time (in time order).
//
// Rules (per metric, thresholds from config/health-thresholds.php):
//  - An alert is created when the value stays outside the green range, on the same side,
//    for `sustain_seconds` consecutive samples. RED if the RED part of that streak is
//    long enough, otherwise YELLOW.
//  - A YELLOW alert that later qualifies as RED is resolved as "escalated" and a RED alert is created.
//  - An alert resolves when the value is back in the green range for `sustain_seconds` samples.
//  - A gap in the recording (missing seconds) breaks a streak; nothing is assumed about the gap.
//  - Durations are in seconds for every source: each sample stands for the source's sample interval
//    (BIDMC: 1 s → 10 samples = 10 s; the vest: 5 s → 2 samples = 10 s). For sources slower than
//    1 Hz a gap is a missing sample (more than 1.5 intervals), since arrival times jitter.
// No AI is involved in deciding whether a value is dangerous.
declare(strict_types=1);

final class AlertEngine
{
    public function __construct(private HealthAnalysis $analysis = new HealthAnalysis())
    {
    }

    /**
     * @param array  $session        replay session row (id, user_id)
     * @param array  $sample         id, metric, value, unit, original_timestamp, replayed_at
     * @param string $classification GREEN | YELLOW | RED
     * @param array  $state          per-session engine state (updated in place, stored as JSON)
     */
    public function observe(array $session, array $sample, string $classification, array &$state, ?int $missionId, bool $isTestFixture = false): void
    {
        $metric = $sample['metric'];
        $offset = (float) $sample['original_timestamp'];
        $value = (float) $sample['value'];
        $interval = max(1.0, (float) ($session['sample_interval_seconds'] ?? 1));
        $sustain = max(1, (int) ceil($this->analysis->metric($metric)['sustain_seconds'] / $interval));
        $gapLimit = $interval <= 1.0 ? 1.001 : $interval * 1.5;

        $s = $state[$metric] ?? [
            'last_offset' => null, 'direction' => null,
            'abnormal_count' => 0, 'abnormal_first_id' => null,
            'red_count' => 0, 'red_first_id' => null,
            'normal_count' => 0, 'active_alert_id' => null, 'active_severity' => null,
        ];

        // A gap (a missing sample) breaks every streak.
        if ($s['last_offset'] !== null && $offset - $s['last_offset'] > $gapLimit) {
            $s['abnormal_count'] = $s['red_count'] = $s['normal_count'] = 0;
            $s['direction'] = null;
        }
        $s['last_offset'] = $offset;

        if ($classification === 'GREEN') {
            $s['abnormal_count'] = $s['red_count'] = 0;
            $s['direction'] = null;
            $s['normal_count']++;

            if ($s['active_alert_id'] !== null && $s['normal_count'] >= $sustain) {
                $this->resolve((int) $s['active_alert_id'], $sample, $this->analysis->resolutionNote($metric, 'back_in_range'));
                $s['active_alert_id'] = $s['active_severity'] = null;
            }
            $state[$metric] = $s;
            return;
        }

        $s['normal_count'] = 0;
        $direction = $this->analysis->direction($metric, $value);
        if ($s['direction'] !== $direction) {
            $s['direction'] = $direction;
            $s['abnormal_count'] = $s['red_count'] = 0;
        }

        if ($s['abnormal_count']++ === 0) {
            $s['abnormal_first_id'] = (int) $sample['id'];
        }
        if ($classification === 'RED') {
            if ($s['red_count']++ === 0) {
                $s['red_first_id'] = (int) $sample['id'];
            }
        } else {
            $s['red_count'] = 0;
        }

        $severity = $s['red_count'] >= $sustain ? 'RED' : ($s['abnormal_count'] >= $sustain ? 'YELLOW' : null);
        $activeRank = $s['active_severity'] ? HealthAnalysis::SEVERITY_RANK[$s['active_severity']] : -1;

        if ($severity !== null && HealthAnalysis::SEVERITY_RANK[$severity] > $activeRank) {
            if ($s['active_alert_id'] !== null) {
                $this->resolve((int) $s['active_alert_id'], $sample, $this->analysis->resolutionNote($metric, 'escalated'));
            }
            $firstId = $severity === 'RED' ? $s['red_first_id'] : $s['abnormal_first_id'];
            $s['active_alert_id'] = $this->create($session, $sample, $severity, $direction, $firstId, $missionId, $isTestFixture);
            $s['active_severity'] = $severity;
        }

        $state[$metric] = $s;
    }

    private function create(array $session, array $sample, string $severity, string $direction, int $firstId, ?int $missionId, bool $isTestFixture): int
    {
        $metric = $sample['metric'];
        $value = (float) $sample['value'];

        db()->prepare(
            'INSERT INTO alerts
                (user_id, mission_id, replay_session_id, metric, observed_value, unit, rule, threshold_version,
                 severity, title, explanation, recommended_action, source_measurement_id, first_measurement_id,
                 occurred_at, is_test_fixture)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $session['user_id'], $missionId, $session['id'], $metric, $value, $sample['unit'],
            $this->analysis->ruleText($metric, $severity, $direction), $this->analysis->version(),
            $severity, $this->analysis->alertTitle($metric, $direction),
            $this->analysis->alertExplanation($metric, $value, $direction),
            $this->analysis->recommendedAction($metric, $severity),
            $sample['id'], $firstId, $sample['replayed_at'], (int) $isTestFixture,
        ]);
        $alertId = (int) db()->lastInsertId();

        log_event('alert.created', ['alert_id' => $alertId, 'user_id' => $session['user_id'], 'metric' => $metric, 'severity' => $severity, 'source_measurement_id' => $sample['id'], 'status' => 'active']);
        audit('alert.create', null, 'alert', $alertId, ['astronaut_id' => $session['user_id'], 'severity' => $severity, 'metric' => $metric]);
        if ($missionId !== null) {
            // Mission admins see it through the mission alert feed (GET /api/missions/{id}/alerts).
            log_event('mission.notification', ['mission_id' => $missionId, 'alert_id' => $alertId, 'severity' => $severity, 'status' => 'queued_in_feed']);
            // A critical alert rings for the whole crew; a warning posts a calm crew health note (no alarm).
            if (!$isTestFixture) {
                $emergencies = new EmergencyService();
                if ($severity === 'RED') {
                    $emergencies->raiseForAlert((int) $session['user_id'], $missionId, $alertId);
                } elseif ($severity === 'YELLOW') {
                    $emergencies->postHealthNote((int) $session['user_id'], $missionId, $alertId, $metric);
                }
            }
        }
        return $alertId;
    }

    private function resolve(int $alertId, array $sample, string $note): void
    {
        db()->prepare("UPDATE alerts SET status = 'resolved', resolved_at = ?, resolution_note = ? WHERE id = ? AND status <> 'resolved'")
            ->execute([$sample['replayed_at'], $note, $alertId]);
        (new EmergencyService())->resolveAlert($alertId);
        log_event('alert.resolved', ['alert_id' => $alertId, 'source_measurement_id' => $sample['id'], 'note' => $note, 'status' => 'resolved']);
    }
}
