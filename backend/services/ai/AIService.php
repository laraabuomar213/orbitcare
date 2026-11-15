<?php
// Entry point for all AI features. Builds agent inputs from real stored data only,
// records every request in ai_analysis (with the IDs of the data analysed), and returns
// "not configured" honestly until a provider is connected through server-side settings.
declare(strict_types=1);

final class AIService
{
    private AIProvider $provider;

    public function __construct(?AIProvider $provider = null)
    {
        $this->provider = $provider ?? self::providerFromConfig();
    }

    // Providers are chosen with AI_PROVIDER in .env (keys come from env only, never the browser).
    public static function providerFromConfig(): AIProvider
    {
        $settings = config()['ai'];
        return match ($settings['provider']) {
            'gemini' => new GeminiProvider($settings),
            default => new NotConfiguredProvider(),
        };
    }

    public function status(): array
    {
        return [
            'configured' => $this->provider->isConfigured(),
            'provider' => $this->provider->name(),
            'message' => t($this->provider->isConfigured() ? 'ai.status.available' : 'ai.status.not_configured'),
            'agents' => ['cardiovascular', 'respiratory', 'general_health', 'symptom_interview', 'coordinator'],
        ];
    }

    // Runs the specialised agents on the astronaut's recent replayed data, then the coordinator.
    public function analyzeHealth(int $userId): array
    {
        $measurements = $this->recentMeasurements($userId);
        $alerts = (new AlertService())->list(['id' => $userId, 'role' => 'astronaut'], ['filter' => 'active', 'limit' => 20]);
        $ids = array_column($measurements, 'raw_measurement_id');

        $byMetric = fn (array $metrics) => array_values(array_filter($measurements, fn ($m) => in_array($m['metric'], $metrics, true)));
        $outputs = [
            'cardiovascular' => (new CardiovascularAgent($this->provider))->run(['measurements' => $byMetric(['heart_rate', 'pulse_rate']), 'alerts' => $alerts]),
            'respiratory' => (new RespiratoryAgent($this->provider))->run(['measurements' => $byMetric(['respiratory_rate', 'spo2']), 'alerts' => $alerts]),
        ];

        if (!$this->allOk($outputs)) {
            return $this->record($userId, 'health_analysis', 'coordinator', $ids, null, $this->firstProblem($outputs));
        }

        $coordinator = (new CoordinatorAgent($this->provider))->run(['agent_outputs' => array_map(fn (AIResult $r) => $r->output, $outputs)]);
        return $this->record($userId, 'health_analysis', 'coordinator', $ids, null, $coordinator);
    }

    public function analyzeSymptoms(int $userId, array $messages): array
    {
        $result = (new SymptomInterviewAgent($this->provider))->run(['messages' => $messages]);
        return $this->record($userId, 'symptom_analysis', 'symptom_interview', [], null, $result);
    }

    public function explainAlert(int $userId, array $alert): array
    {
        // The AI gets the alert's facts, never who it belongs to.
        $facts = array_diff_key($alert, ['astronaut' => true, 'mission' => true, 'acknowledged' => true]);
        $result = (new GeneralHealthAgent($this->provider))->run([
            'alert' => $facts,
            'request' => 'Explain this alert to the astronaut in plain words: what the measurement means, why the rule was triggered, '
                . 'and what the recommended action is for. concern_level is one of low, moderate, high. '
                . 'missing_information lists what would help judge it better (array of strings).',
        ]);
        return $this->record($userId, 'alert_explanation', 'general_health', [$alert['source_measurement']['raw_measurement_id']], $alert['id'], $result);
    }

    public function recommendSpecialty(int $userId): array
    {
        return $this->analyzeHealth($userId);
    }

    // Chat and consultations live in ConsultationService (General Doctor AI + specialists).

    // ---------- internals ----------

    private function recentMeasurements(int $userId): array
    {
        $statement = db()->prepare(
            'SELECT rm.id, rm.metric, rm.value, rm.unit, rm.original_timestamp
             FROM processed_measurements pm JOIN raw_measurements rm ON rm.id = pm.raw_measurement_id
             WHERE pm.user_id = ? ORDER BY pm.id DESC LIMIT 240'
        );
        $statement->execute([$userId]);
        return array_map(fn ($row) => [
            'raw_measurement_id' => (int) $row['id'], 'metric' => $row['metric'], 'value' => (float) $row['value'],
            'unit' => $row['unit'], 'offset_seconds' => (float) $row['original_timestamp'],
        ], $statement->fetchAll());
    }

    // Stores every AI request with the IDs of the data analysed (also used by ConsultationService).
    public function record(int $userId, string $type, string $agent, array $measurementIds, ?int $alertId, AIResult $result): array
    {
        db()->prepare(
            'INSERT INTO ai_analysis (user_id, analysis_type, agent, provider, input_measurement_ids, input_alert_id, output, status, error_message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId, $type, $agent, $result->provider ?: $this->provider->name(),
            json_encode(array_values($measurementIds)), $alertId,
            $result->output ? json_encode($result->output) : null,
            $result->status, $result->ok() ? null : $result->message,
        ]);
        $id = (int) db()->lastInsertId();

        log_event('ai.request', ['analysis_id' => $id, 'type' => $type, 'agent' => $agent, 'provider' => $this->provider->name(), 'status' => $result->status]);
        audit('ai.request', $userId, 'ai_analysis', $id, ['type' => $type, 'status' => $result->status]);

        return ['id' => $id, 'status' => $result->status, 'message' => $result->message, 'output' => $result->output, 'analyzed_measurement_ids' => array_values($measurementIds)];
    }

    private function allOk(array $results): bool
    {
        return !array_filter($results, fn (AIResult $r) => !$r->ok());
    }

    private function firstProblem(array $results): AIResult
    {
        foreach ($results as $result) {
            if (!$result->ok()) {
                return $result;
            }
        }
        return AIResult::notConfigured();
    }

}
