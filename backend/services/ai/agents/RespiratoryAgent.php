<?php
// Specialised agent — input/output contract in docs/AI_ARCHITECTURE.md.
declare(strict_types=1);

// Input: { measurements: [{... metric: respiratory_rate|spo2 ...}], alerts: [...] }
final class RespiratoryAgent extends Agent
{
    public function name(): string
    {
        return 'respiratory';
    }

    public function outputFields(): array
    {
        return ['findings', 'concern_level', 'evidence_measurement_ids', 'missing_information'];
    }
}
