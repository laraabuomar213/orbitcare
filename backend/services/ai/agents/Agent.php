<?php
// Base for the specialised agents. Each agent:
//   - receives ONLY data the system already has (measurements with IDs, alerts, the user's own words),
//   - declares the exact output fields it must return,
//   - rejects provider output that is missing fields, so malformed answers never reach users.
declare(strict_types=1);

abstract class Agent
{
    public function __construct(protected AIProvider $provider)
    {
    }

    abstract public function name(): string;

    // Output fields every successful answer must contain.
    abstract public function outputFields(): array;

    public function run(array $input): AIResult
    {
        $result = $this->provider->run($this->name(), $input + ['instructions' => $this->instructions(), 'output_fields' => $this->outputFields()]);
        if (!$result->ok()) {
            return $result;
        }

        $missing = array_diff($this->outputFields(), array_keys($result->output));
        if ($missing) {
            log_event('ai.malformed_response', ['agent' => $this->name(), 'missing' => array_values($missing), 'status' => 'error'], 'warning');
            return AIResult::error(t('ai.error.incomplete'), $result->provider);
        }
        return $result;
    }

    protected function instructions(): string
    {
        return 'Use only the data provided. Never invent measurements, symptoms, medical history or test results. '
            . 'Do not give a definitive diagnosis. Say clearly when information is missing.';
    }
}
