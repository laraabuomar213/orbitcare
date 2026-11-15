<?php
// "What we noticed": deterministic rules over the astronaut's own recent check-ins and
// latest PHQ-4. Patterns need consecutive mission days; a missing day breaks a pattern
// (nothing is assumed about days without a check-in). Not a diagnosis.
// Texts are in backend/lang/{en,ar}/checkin.php (keys "insight.*").
declare(strict_types=1);

final class CheckinAnalysis
{
    public function __construct(private array $config)
    {
    }

    /**
     * @param array  $recent    check-ins keyed by date (from CheckinService::recent)
     * @param ?array $wellbeing latest PHQ-4 summary or null
     */
    public function insights(array $recent, ?array $wellbeing, string $today): array
    {
        $rules = $this->config['rules'];
        $target = $this->config['exercise_target_minutes'];
        $todayCheckin = $recent[$today] ?? null;
        $insights = [];

        if ($todayCheckin) {
            $fatigue = $todayCheckin['fatigue_level'];
            $fatigueLabel = t("checkin.fatigue.{$fatigue}");

            if ($fatigue >= $rules['fatigue_severe']) {
                $insights[] = $this->insight('fatigue_severe', 'urgent', 'fatigue',
                    ['label' => $fatigueLabel, 'level' => $fatigue], ['min' => $rules['fatigue_severe']], 'samn_perelli');
            } elseif ($fatigue >= $rules['fatigue_moderate']) {
                $insights[] = $this->insight('fatigue_moderate', 'attention', 'fatigue',
                    ['label' => $fatigueLabel, 'level' => $fatigue], ['min' => $rules['fatigue_moderate']], 'samn_perelli');
            }

            if ($todayCheckin['sleep_hours'] < $rules['sleep_short_hours']) {
                $insight = $this->insight('sleep_short', 'attention', 'sleep',
                    ['hours' => $this->number($todayCheckin['sleep_hours'])], ['hours' => $this->number($rules['sleep_short_hours'])], 'nasa_sleep');
                if ($fatigue >= $rules['fatigue_moderate']) {
                    $insight['text'] .= ' ' . t('insight.sleep_short.with_fatigue');
                }
                $insights[] = $insight;
            }

            if ($todayCheckin['exercise_minutes'] < $target) {
                $insights[] = $this->insight('exercise_below_target', 'info', 'exercise',
                    ['done' => $todayCheckin['exercise_minutes'], 'target' => $target], ['target' => $target], 'nasa_exercise');
            }

            foreach ($todayCheckin['symptoms'] as $symptom) {
                $definition = $this->config['symptoms'][$symptom] ?? null;
                if (!$definition) {
                    continue;
                }
                if ($definition['area'] === 'immune') {
                    $insight = $this->insight('symptom_immune', 'attention', 'immune', [], [], 'nasa_immune');
                    $insight['key'] = "symptom_{$symptom}";
                    $insight['title'] = t('insight.symptom_immune.title', ['symptom' => t("checkin.symptom.{$symptom}")]);
                    $insights[] = $insight;
                } elseif ($definition['area'] === 'sans') {
                    $insights[] = $this->insight('symptom_vision', 'urgent', 'sans', [], [], 'nasa_sans');
                } elseif ($symptom === 'dizziness') {
                    $insights[] = $this->insight('symptom_dizziness', 'attention', 'gravity', [], [], 'nasa_bone');
                }
            }
        }

        // Patterns across consecutive mission days ending today.
        $days = $this->consecutiveDays($recent, $today, $rules['pattern_days']);
        if ($days !== null) {
            $n = $rules['pattern_days'];
            if (!array_filter($days, fn ($d) => $d['sleep_hours'] >= $rules['sleep_short_hours'])) {
                $insights[] = $this->insight('sleep_pattern', 'attention', 'sleep',
                    ['days' => $n], ['hours' => $this->number($rules['sleep_short_hours']), 'days' => $n], 'nasa_sleep');
            }
            if (!array_filter($days, fn ($d) => $d['fatigue_level'] < $rules['fatigue_moderate'])) {
                $insights[] = $this->insight('fatigue_pattern', 'attention', 'fatigue',
                    ['days' => $n], ['min' => $rules['fatigue_moderate'], 'days' => $n], 'samn_perelli');
            }
            if (!array_filter($days, fn ($d) => $d['exercise_minutes'] >= $target)) {
                $insights[] = $this->insight('exercise_pattern', 'attention', 'exercise',
                    ['days' => $n], ['target' => $target, 'days' => $n], 'nasa_bone');
            }
        }

        // Same symptom on several of the last 7 days.
        $counts = [];
        foreach ($recent as $day) {
            foreach ($day['symptoms'] as $symptom) {
                $counts[$symptom] = ($counts[$symptom] ?? 0) + 1;
            }
        }
        foreach ($counts as $symptom => $count) {
            if ($symptom !== 'none' && $count >= $rules['repeat_symptom_days'] && isset($this->config['symptoms'][$symptom])) {
                $insight = $this->insight('recurring', 'attention', $this->config['symptoms'][$symptom]['area'],
                    ['symptom' => t("checkin.symptom.{$symptom}"), 'count' => $count], ['min' => $rules['repeat_symptom_days']],
                    $this->config['symptoms'][$symptom]['source'] ?? null);
                $insight['key'] = "recurring_{$symptom}";
                $insights[] = $insight;
            }
        }

        // Latest weekly PHQ-4 (screening only).
        if ($wellbeing) {
            if ($wellbeing['total_score'] >= 9) {
                $insights[] = $this->insight('wellbeing_severe', 'urgent', 'behavioral',
                    ['score' => $wellbeing['total_score']], [], 'phq4');
            } elseif ($wellbeing['depression_positive'] || $wellbeing['anxiety_positive']) {
                $area = $wellbeing['depression_positive'] && $wellbeing['anxiety_positive'] ? 'both'
                    : ($wellbeing['depression_positive'] ? 'mood' : 'anxiety');
                $insight = $this->insight('wellbeing_positive', 'attention', 'behavioral', [], [], 'phq4');
                $insight['title'] = t("insight.wellbeing_positive.title.{$area}");
                $insights[] = $insight;
            }
        }

        if ($todayCheckin && !$insights) {
            $insights[] = $this->insight('all_clear', 'info', 'general', [], [], null);
        }

        $order = ['urgent' => 0, 'attention' => 1, 'info' => 2];
        usort($insights, fn ($a, $b) => $order[$a['level']] <=> $order[$b['level']]);
        return $insights;
    }

    // The last N mission days ending today, only if every one of them has a check-in.
    private function consecutiveDays(array $recent, string $today, int $n): ?array
    {
        $days = [];
        for ($offset = 0; $offset < $n; $offset++) {
            $date = gmdate('Y-m-d', strtotime("{$today} -{$offset} days"));
            if (!isset($recent[$date])) {
                return null;
            }
            $days[] = $recent[$date];
        }
        return $days;
    }

    // Builds an insight from its texts: insight.<key>.title / .text / .rule
    private function insight(string $key, string $level, string $area, array $textParams, array $ruleParams, ?string $source): array
    {
        return [
            'key' => $key, 'level' => $level, 'area' => $area,
            'title' => t("insight.{$key}.title", $textParams),
            'text' => t("insight.{$key}.text", $textParams),
            'rule' => t("insight.{$key}.rule", $ruleParams),
            'source' => $source ? checkin_source($this->config, $source) : null,
        ];
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
