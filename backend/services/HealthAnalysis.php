<?php
// Deterministic health analysis: classifies a measured value against the configured
// thresholds (config/health-thresholds.php) and describes the result in plain language.
// The same input and the same threshold version always give the same output.
declare(strict_types=1);

final class HealthAnalysis
{
    public const SEVERITY_RANK = ['GREEN' => 0, 'YELLOW' => 1, 'RED' => 2];

    private array $config;

    public function __construct(?array $thresholds = null)
    {
        $this->config = $thresholds ?? health_thresholds();
    }

    public function version(): string
    {
        return $this->config['version'];
    }

    public function metric(string $metric): array
    {
        return $this->config['metrics'][$metric] ?? throw new InvalidArgumentException("Unknown metric {$metric}");
    }

    public function metrics(): array
    {
        return $this->config['metrics'];
    }

    public function classify(string $metric, float $value): string
    {
        $rule = $this->metric($metric);
        if ($value >= $rule['green'][0] && $value <= $rule['green'][1]) {
            return 'GREEN';
        }
        if ($value >= $rule['yellow'][0] && $value <= $rule['yellow'][1]) {
            return 'YELLOW';
        }
        return 'RED';
    }

    public function direction(string $metric, float $value): string
    {
        return $value < $this->metric($metric)['green'][0] ? 'low' : 'high';
    }

    // Texts below take a language: alerts are stored in English (a fixed record); the API shows
    // them in the reader's language by rebuilding them from the stored values (AlertService).

    // Display name of a metric, e.g. "Heart rate" / "معدل ضربات القلب".
    public function label(string $metric, string $language = 'en'): string
    {
        $this->metric($metric);
        return translate_in($language, "metric.label.{$metric}");
    }

    // The exact rule that a sustained out-of-range value broke, e.g. "SpO₂ below 90 % for at least 10 s".
    public function ruleText(string $metric, string $severity, string $direction, string $language = 'en'): string
    {
        $rule = $this->metric($metric);
        $bound = $severity === 'RED'
            ? ($direction === 'low' ? $rule['yellow'][0] : $rule['yellow'][1])
            : ($direction === 'low' ? $rule['green'][0] : $rule['green'][1]);

        return translate_in($language, "alert.rule.{$direction}", [
            'metric' => $this->label($metric, $language),
            'bound' => $this->number($bound),
            'unit' => translate_in($language, "unit.{$rule['unit']}"),
            'seconds' => $rule['sustain_seconds'],
        ]);
    }

    public function alertTitle(string $metric, string $direction, string $language = 'en'): string
    {
        return translate_in($language, "alert.title.{$direction}.{$metric}");
    }

    public function alertExplanation(string $metric, float $value, string $direction, string $language = 'en'): string
    {
        $rule = $this->metric($metric);
        return translate_in($language, "alert.explanation.{$direction}", [
            'metric' => $this->label($metric, $language),
            'value' => $this->number($value),
            'unit' => translate_in($language, "unit.{$rule['unit']}"),
            'min' => $this->number($rule['green'][0]),
            'max' => $this->number($rule['green'][1]),
            'seconds' => $rule['sustain_seconds'],
        ]);
    }

    public function recommendedAction(string $metric, string $severity, string $language = 'en'): string
    {
        $this->metric($metric);
        return translate_in($language, "alert.action.{$metric}.{$severity}");
    }

    // Note stored when an alert resolves (English), e.g. "Heart rate back in range for 10 s".
    public function resolutionNote(string $metric, string $reason, string $language = 'en'): string
    {
        return $reason === 'escalated'
            ? translate_in($language, 'alert.note.escalated')
            : translate_in($language, 'alert.note.back_in_range', [
                'metric' => $this->label($metric, $language),
                'seconds' => $this->metric($metric)['sustain_seconds'],
            ]);
    }

    // Overall status for the health card, built only from real current state.
    // $current: metric => classification|null (null = data unavailable)
    public function overallStatus(array $current, int $activeRed, int $activeYellow): array
    {
        $available = array_filter($current, fn ($classification) => $classification !== null);

        if (!$available && !$activeRed && !$activeYellow) {
            return [
                'level' => 'NO_DATA',
                'title' => t('status.no_data.title'),
                'message' => t('status.no_data.message'),
            ];
        }

        $labels = fn (array $metrics) => implode(t('common.list_separator'), array_map(fn ($m) => $this->label($m, current_language()), $metrics));
        $red = array_keys(array_filter($available, fn ($c) => $c === 'RED'));
        $yellow = array_keys(array_filter($available, fn ($c) => $c === 'YELLOW'));

        if ($red || $activeRed) {
            return [
                'level' => 'RED',
                'title' => t('status.red.title'),
                'message' => $red
                    ? t('status.red.metrics', ['metrics' => $labels($red)])
                    : t('status.red.unresolved'),
            ];
        }
        if ($yellow || $activeYellow) {
            return [
                'level' => 'YELLOW',
                'title' => t('status.yellow.title'),
                'message' => $yellow
                    ? t('status.yellow.metrics', ['metrics' => $labels($yellow)])
                    : t('status.yellow.unresolved'),
            ];
        }

        $unavailable = count($current) - count($available);
        return [
            'level' => 'GREEN',
            'title' => t('status.green.title'),
            'message' => t('status.green.message')
                . ($unavailable ? ' ' . t($unavailable > 1 ? 'status.green.unavailable_many' : 'status.green.unavailable_one', ['n' => $unavailable]) : ''),
        ];
    }

    private function number(float|int $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
    }
}
