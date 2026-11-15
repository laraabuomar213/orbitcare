<?php
// Shared health-data layer for every AI (General Doctor and specialists).
//
// Selects and summarises only what a consultation needs — it never hands the whole
// database to the AI:
//   - profile: astronaut details and the medical background they entered
//   - current: newest replayed value per metric (with timestamp, freshness, raw ID)
//   - trends: aggregates over the last N minutes vs. earlier in the same replay
//   - alerts: unresolved alerts
//   - patterns: deterministic cross-metric observations (e.g. low SpO2 with fast breathing)
// Every number comes from stored measurements; anything missing is reported as missing.
declare(strict_types=1);

final class HealthContextService
{
    public function __construct(
        private HealthService $health = new HealthService(),
        private HealthAnalysis $analysis = new HealthAnalysis()
    ) {
    }

    /** @param string[] $metrics metrics to include (relevant to the specialist) */
    public function build(int $userId, array $metrics, int $windowSeconds): array
    {
        $current = $this->health->current($userId);   // also brings the replay up to date
        $byMetric = array_column($current['metrics'], null, 'metric');

        $selected = [];
        foreach ($metrics as $metric) {
            if (isset($byMetric[$metric])) {
                $selected[$metric] = $byMetric[$metric];
            }
        }

        $trends = $metrics ? $this->trends($userId, $metrics, $windowSeconds, $current['replay']) : [];
        $hasAnyData = (bool) array_filter($current['metrics'], fn ($m) => !empty($m['provenance']));

        return [
            'profile' => $this->profile($userId),
            'data_status' => $hasAnyData ? 'available' : 'none',
            'data_last_updated' => $current['last_update'],
            'replay' => $current['replay'],
            'overall_status' => $current['status'],
            'current' => array_values(array_map([$this, 'formatCurrent'], $selected)),
            'trends' => $trends,
            'alerts' => $this->alerts($userId, $metrics),
            'patterns' => $this->patterns($byMetric, $trends),
            'window_seconds' => $windowSeconds,
            'self_reports' => $this->selfReports($userId),
        ];
    }

    // What the astronaut reported in their own daily check-ins (last 7 mission days).
    private function selfReports(int $userId): array
    {
        $checkins = new CheckinService();
        $recent = $checkins->recent($userId, 7);
        $wellbeing = $checkins->wellbeingStatus($userId)['last'];
        $config = checkin_config();
        $today = $recent[$checkins->missionDay()] ?? null;

        $average = function (string $key) use ($recent): ?float {
            $values = array_column($recent, $key);
            return $values ? round(array_sum($values) / count($values), 1) : null;
        };
        $symptoms = [];
        foreach ($recent as $day) {
            foreach ($day['symptoms'] as $symptom) {
                if ($symptom !== 'none' && isset($config['symptoms'][$symptom])) {
                    $label = t("checkin.symptom.{$symptom}");
                    $symptoms[$label] = ($symptoms[$label] ?? 0) + 1;
                }
            }
        }

        return [
            'days_recorded' => count($recent),
            'today' => $today ? [
                'sleep_hours' => $today['sleep_hours'],
                'sleep_quality' => t("checkin.sleep_quality.{$today['sleep_quality']}"),
                'fatigue' => "{$today['fatigue_level']}/7 – " . t("checkin.fatigue.{$today['fatigue_level']}"),
                'exercise_minutes' => $today['exercise_minutes'],
                'exercise_target_minutes' => $config['exercise_target_minutes'],
                'symptoms' => array_map(fn ($s) => isset($config['symptoms'][$s]) ? t("checkin.symptom.{$s}") : t('checkin.symptom.none'), $today['symptoms']),
            ] : null,
            'seven_day_average' => ['sleep_hours' => $average('sleep_hours'), 'fatigue_level' => $average('fatigue_level'), 'exercise_minutes' => $average('exercise_minutes')],
            'symptom_days_last_7' => $symptoms,
            'latest_alertness_test' => $this->latestAlertness($userId),
            'latest_wellbeing_screen' => $wellbeing ? [
                'completed_on' => $wellbeing['completed_on'], 'band' => $wellbeing['band'],
                'low_mood_flag' => $wellbeing['depression_positive'], 'anxiety_flag' => $wellbeing['anxiety_positive'],
            ] : null,
        ];
    }

    // The newest Alertness Check (PVT-B) from the last 7 days, compared with the astronaut's own baseline.
    private function latestAlertness(int $userId): ?array
    {
        $overview = (new AlertnessService())->overview($userId);
        $latest = $overview['latest'];
        if (!$latest || strtotime($latest['taken_at']) < time() - 7 * 86400) {
            return null;
        }
        return [
            'taken_at' => $latest['taken_at'],
            'median_reaction_time_ms' => $latest['median_rt_ms'],
            'lapses' => $latest['lapses'],
            'false_starts' => $latest['false_starts'],
            'compared_with_own_baseline' => $overview['status']['level'],
            'blinks_per_minute' => $latest['blinks_per_minute'],
            'perclos_pct' => $latest['perclos_pct'],
        ];
    }

    private function formatCurrent(array $m): array
    {
        $rule = $this->analysis->metric($m['metric']);
        return [
            'metric' => $m['metric'],
            'label' => $m['label'],
            'unit' => $m['unit'],
            'available' => $m['available'],
            'value' => $m['value'] ?? null,
            'classification' => $m['classification'] ?? null,
            'normal_range' => $rule['green'],
            'measured_at' => $m['measured_at'] ?? null,
            'reason_unavailable' => $m['available'] ? null : ($m['reason'] ?? t('ai.no_recorded_data')),
            'raw_measurement_id' => $m['provenance']['raw_measurement_id'] ?? null,
            'source_record_id' => $m['provenance']['source_record_id'] ?? null,
            'recording_offset_seconds' => $m['provenance']['original_timestamp_seconds'] ?? null,
        ];
    }

    // Aggregates per metric over the recent window, plus the mean earlier in the same replay
    // ("your usual range" within the recorded data). Two grouped queries, no row dumps.
    private function trends(int $userId, array $metrics, int $windowSeconds, ?array $replay): array
    {
        if ($replay === null) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($metrics), '?'));

        $recent = db()->prepare(
            "SELECT pm.metric, COUNT(*) AS samples, MIN(rm.value) AS min, MAX(rm.value) AS max, AVG(rm.value) AS mean,
                    SUM(pm.classification <> 'GREEN') AS abnormal, SUM(pm.classification = 'RED') AS red,
                    MIN(pm.replayed_at) AS first_at, MAX(pm.replayed_at) AS last_at,
                    SUBSTRING_INDEX(GROUP_CONCAT(rm.value ORDER BY pm.replayed_at), ',', 1) AS first_value,
                    SUBSTRING_INDEX(GROUP_CONCAT(rm.value ORDER BY pm.replayed_at DESC), ',', 1) AS last_value
             FROM processed_measurements pm JOIN raw_measurements rm ON rm.id = pm.raw_measurement_id
             WHERE pm.replay_session_id = ? AND pm.metric IN ({$placeholders})
               AND pm.replayed_at >= UTC_TIMESTAMP(3) - INTERVAL ? SECOND
             GROUP BY pm.metric"
        );
        $recent->execute([$replay['session_id'], ...$metrics, $windowSeconds]);

        $earlier = db()->prepare(
            "SELECT pm.metric, COUNT(*) AS samples, AVG(rm.value) AS mean
             FROM processed_measurements pm JOIN raw_measurements rm ON rm.id = pm.raw_measurement_id
             WHERE pm.replay_session_id = ? AND pm.metric IN ({$placeholders})
               AND pm.replayed_at < UTC_TIMESTAMP(3) - INTERVAL ? SECOND
             GROUP BY pm.metric"
        );
        $earlier->execute([$replay['session_id'], ...$metrics, $windowSeconds]);
        $baseline = array_column($earlier->fetchAll(), null, 'metric');

        $trends = [];
        foreach ($recent->fetchAll() as $row) {
            $rule = $this->analysis->metric($row['metric']);
            $first = (float) $row['first_value'];
            $last = (float) $row['last_value'];
            $change = $last - $first;
            $tolerance = max(1.0, abs($first) * 0.05);   // under 5 % (or 1 unit) counts as stable
            $base = $baseline[$row['metric']] ?? null;

            $trends[] = [
                'metric' => $row['metric'],
                'label' => $this->analysis->label($row['metric'], current_language()),
                'unit' => $rule['unit'],
                'samples' => (int) $row['samples'],
                'from' => iso_time($row['first_at']),
                'to' => iso_time($row['last_at']),
                'min' => (float) $row['min'],
                'max' => (float) $row['max'],
                'mean' => round((float) $row['mean'], 1),
                'first' => $first,
                'last' => $last,
                'direction' => abs($change) < $tolerance ? 'stable' : ($change > 0 ? 'rising' : 'falling'),
                'abnormal_samples' => (int) $row['abnormal'],
                'critical_samples' => (int) $row['red'],
                'abnormal_share' => round((int) $row['abnormal'] / max(1, (int) $row['samples']), 2),
                'earlier_mean' => $base ? round((float) $base['mean'], 1) : null,
                'earlier_samples' => $base ? (int) $base['samples'] : 0,
                'normal_range' => $rule['green'],
            ];
        }
        return $trends;
    }

    private function alerts(int $userId, array $metrics): array
    {
        $statement = db()->prepare(
            "SELECT id, metric, severity, title, explanation, recommended_action, resolution_note, threshold_version,
                    observed_value, unit, rule, occurred_at, status
             FROM alerts WHERE user_id = ? AND status <> 'resolved' ORDER BY severity = 'RED' DESC, occurred_at DESC LIMIT 20"
        );
        $statement->execute([$userId]);

        $alerts = [];
        $alertTexts = new AlertService();
        foreach ($statement->fetchAll() as $row) {
            if ($metrics && !in_array($row['metric'], $metrics, true)) {
                continue;
            }
            $row = $alertTexts->localize($row);   // title and rule in the reader's language
            $alerts[] = [
                'id' => (int) $row['id'], 'metric' => $row['metric'], 'severity' => $row['severity'], 'title' => $row['title'],
                'observed_value' => (float) $row['observed_value'], 'unit' => $row['unit'], 'rule' => $row['rule'],
                'occurred_at' => iso_time($row['occurred_at']), 'status' => $row['status'],
            ];
        }
        return $alerts;
    }

    // Deterministic cross-metric observations, only from data that is present.
    private function patterns(array $current, array $trends): array
    {
        $patterns = [];
        $value = fn (string $metric) => ($current[$metric]['available'] ?? false) ? $current[$metric] : null;

        $spo2 = $value('spo2');
        $resp = $value('respiratory_rate');
        if ($spo2 && $resp && $spo2['classification'] !== 'GREEN' && $resp['classification'] !== 'GREEN') {
            $patterns[] = t('ai.pattern.spo2_resp', ['spo2' => $spo2['value'], 'resp' => $resp['value']]);
        }

        $heart = $value('heart_rate');
        $pulse = $value('pulse_rate');
        if ($heart && $pulse && abs($heart['value'] - $pulse['value']) >= 15) {
            $patterns[] = t('ai.pattern.hr_pulse', ['hr' => $heart['value'], 'pulse' => $pulse['value'], 'diff' => (int) abs($heart['value'] - $pulse['value'])]);
        }

        foreach ($trends as $trend) {
            if ($trend['abnormal_share'] >= 0.5 && $trend['samples'] >= 10) {
                $patterns[] = t('ai.pattern.abnormal_share', ['metric' => $trend['label'], 'percent' => (int) round($trend['abnormal_share'] * 100), 'n' => $trend['samples']]);
            }
            if ($trend['earlier_mean'] !== null && $trend['earlier_samples'] >= 30) {
                [$low, $high] = $trend['normal_range'];
                $wasNormal = $trend['earlier_mean'] >= $low && $trend['earlier_mean'] <= $high;
                $nowOut = $trend['mean'] < $low || $trend['mean'] > $high;
                if ($wasNormal && $nowOut) {
                    $patterns[] = t('ai.pattern.shifted', ['metric' => $trend['label'], 'mean' => $trend['mean'], 'unit' => unit_label($trend['unit']), 'earlier' => $trend['earlier_mean']]);
                }
            }
        }
        return $patterns;
    }

    // The astronaut's own profile and medical background (only their own data).
    private function profile(int $userId): array
    {
        $statement = db()->prepare(
            'SELECT a.astronaut_code, a.age, a.height_cm, a.weight_kg,
                    mb.has_chronic_conditions, mb.chronic_conditions, mb.takes_medications, mb.has_allergies, mb.allergies, mb.medical_history_notes
             FROM astronauts a LEFT JOIN astronaut_medical_background mb ON mb.user_id = a.user_id
             WHERE a.user_id = ?'
        );
        $statement->execute([$userId]);
        $row = $statement->fetch() ?: [];

        $medications = db()->prepare('SELECT name, purpose FROM astronaut_medications WHERE user_id = ? ORDER BY id');
        $medications->execute([$userId]);

        $hasBackground = isset($row['has_chronic_conditions']);
        return [
            'astronaut_code' => $row['astronaut_code'] ?? null,
            'age' => isset($row['age']) ? (int) $row['age'] : null,
            'height_cm' => isset($row['height_cm']) ? (float) $row['height_cm'] : null,
            'weight_kg' => isset($row['weight_kg']) ? (float) $row['weight_kg'] : null,
            // Privacy: the astronaut's free-text notes are NOT sent to the AI provider — only a flag that
            // notes exist. Structured facts (conditions, medications, allergies) are sent so the AI can
            // reason safely about them. No name or astronaut code ever leaves OrbitCare (PromptBuilder strips them).
            'medical_background' => $hasBackground ? [
                'chronic_conditions' => $row['has_chronic_conditions'] ? $row['chronic_conditions'] : 'None reported',
                'medications' => $row['takes_medications'] ? $medications->fetchAll() : [],
                'allergies' => $row['has_allergies'] ? $row['allergies'] : 'None reported',
                'has_free_text_history_notes' => !empty($row['medical_history_notes']),
            ] : null,
        ];
    }
}
