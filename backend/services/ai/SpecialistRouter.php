<?php
// Decides which specialist (if any) the General Doctor recommends, and how urgent the
// next step is. Deterministic backend rules — the frontend never decides this, and an
// AI provider (when connected) can only add interpretation, not override urgency.
//
// Score per specialist:
//   +2  for each reported symptom that points to the specialist
//   +3 / +2  for a relevant metric currently RED / YELLOW, or an unresolved RED / YELLOW alert on it
//   +1  if that metric was out of range in ≥ 20 % of recent readings
//   +1  if a reported symptom and a relevant abnormal measurement occur together
// Highest score ≥ 2 wins (ties: measurement evidence first). Confidence: ≥ 5 high, ≥ 3 moderate, else low.
declare(strict_types=1);

final class SpecialistRouter
{
    private const SEVERITY_POINTS = ['RED' => 3, 'YELLOW' => 2, 'GREEN' => 0];

    public function __construct(private array $config = [])
    {
        $this->config = $config ?: ai_specialists_config();
    }

    /**
     * @param array $symptoms output of SymptomDetector::detect()
     * @param array $context  output of HealthContextService::build() for all metrics
     */
    public function route(array $symptoms, array $context): array
    {
        $scores = [];
        $reasons = [];
        $measurementEvidence = [];

        foreach ($symptoms as $symptom) {
            foreach ($symptom['specialists'] as $specialist) {
                if ($specialist === 'general') {
                    continue;
                }
                $scores[$specialist] = ($scores[$specialist] ?? 0) + 2;
                $reasons[$specialist][] = t('ai.reason.reported', ['quote' => $symptom['quote'], 'label' => $symptom['label']]);
            }
        }

        $alertsByMetric = [];
        foreach ($context['alerts'] as $alert) {
            $alertsByMetric[$alert['metric']] = max($alertsByMetric[$alert['metric']] ?? 0, self::SEVERITY_POINTS[$alert['severity']]);
        }
        $trendsByMetric = array_column($context['trends'], null, 'metric');

        foreach ($this->config['metric_specialists'] as $metric => $specialist) {
            $current = $this->findCurrent($context['current'], $metric);
            $currentPoints = $current && $current['available'] ? self::SEVERITY_POINTS[$current['classification']] : 0;
            $points = max($currentPoints, $alertsByMetric[$metric] ?? 0);

            // One reason per metric: the current value if it is abnormal now; otherwise an
            // unresolved alert or a recent trend explains why it still matters.
            $abnormalShare = $trendsByMetric[$metric]['abnormal_share'] ?? 0;
            if ($currentPoints > 0) {
                $reasons[$specialist][] = t('ai.reason.latest', [
                    'metric' => lcfirst($current['label']), 'value' => $current['value'], 'unit' => unit_label($current['unit']),
                    'min' => $current['normal_range'][0], 'max' => $current['normal_range'][1],
                    'share' => $abnormalShare >= 0.2 ? t('ai.reason.latest_share', ['percent' => (int) round($abnormalShare * 100)]) : '',
                ]);
            } elseif (($alertsByMetric[$metric] ?? 0) > 0) {
                foreach ($context['alerts'] as $alert) {
                    if ($alert['metric'] === $metric) {
                        $reasons[$specialist][] = t("ai.reason.alert.{$alert['severity']}", ['title' => $alert['title'], 'rule' => $alert['rule']]);
                        break;
                    }
                }
            } elseif ($abnormalShare >= 0.2) {
                $reasons[$specialist][] = t('ai.reason.trend', ['metric' => $trendsByMetric[$metric]['label'], 'percent' => (int) round($abnormalShare * 100)]);
            }
            if ($abnormalShare >= 0.2) {
                $points++;
            }
            if ($points > 0) {
                $scores[$specialist] = ($scores[$specialist] ?? 0) + $points;
                $measurementEvidence[$specialist] = true;
            }
        }

        // Symptom and measurement pointing the same way.
        foreach ($symptoms as $symptom) {
            foreach ($symptom['specialists'] as $specialist) {
                if (isset($measurementEvidence[$specialist], $scores[$specialist])) {
                    $scores[$specialist]++;
                    $reasons[$specialist][] = t('ai.reason.coincides', ['symptom' => $symptom['label']]);
                    break 2;
                }
            }
        }

        uksort($scores, function (string $a, string $b) use ($scores, $measurementEvidence) {
            return [$scores[$b], isset($measurementEvidence[$b])] <=> [$scores[$a], isset($measurementEvidence[$a])];
        });

        $recommendation = null;
        $best = array_key_first($scores);
        if ($best !== null && $scores[$best] >= 2) {
            $score = $scores[$best];
            $recommendation = [
                'specialist' => $best,
                'name' => t("ai.specialist.{$best}.name"),
                'confidence' => $score >= 5 ? 'high' : ($score >= 3 ? 'moderate' : 'low'),
                'reasons' => array_values(array_unique($reasons[$best])),
                'source' => 'rules',
                'rules_version' => $this->config['version'],
            ];
        }

        return [
            'recommendation' => $recommendation,
            'scores' => $scores,
            'next_step' => $this->nextStep($symptoms, $context, $recommendation),
        ];
    }

    // How urgent: red-flag symptoms or any critical value/alert → contact the medical officer now.
    public function nextStep(array $symptoms, array $context, ?array $recommendation): array
    {
        $redFlags = array_values(array_filter($symptoms, fn ($s) => $s['red_flag']));
        $critical = array_filter($context['current'], fn ($m) => $m['available'] && $m['classification'] === 'RED')
            || array_filter($context['alerts'], fn ($a) => $a['severity'] === 'RED');
        $warning = array_filter($context['current'], fn ($m) => $m['available'] && $m['classification'] === 'YELLOW')
            || $context['alerts'];

        if ($redFlags) {
            $labels = array_column($redFlags, 'label');
            if (current_language() === 'en') {
                $labels = array_map('strtolower', $labels);
            }
            return ['level' => 'urgent', 'text' => t('ai.next.urgent_symptoms', ['symptoms' => implode(t('common.list_separator'), $labels)])];
        }
        if ($critical) {
            return ['level' => 'urgent', 'text' => t('ai.next.urgent_value')];
        }
        if ($recommendation) {
            return ['level' => 'consult', 'text' => t('ai.next.consult', ['name' => $recommendation['name']])];
        }
        if ($warning) {
            return ['level' => 'monitor', 'text' => t('ai.next.warning')];
        }
        return ['level' => 'monitor', 'text' => t('ai.next.monitor')];
    }

    private function findCurrent(array $current, string $metric): ?array
    {
        foreach ($current as $item) {
            if ($item['metric'] === $metric) {
                return $item;
            }
        }
        return null;
    }
}
