<?php
// Specialised agent — input/output contract in docs/AI_ARCHITECTURE.md.
declare(strict_types=1);

// Input: { measurements: [{raw_measurement_id, metric: heart_rate|pulse_rate, value, unit, offset_seconds}], alerts: [...] }
final class CardiovascularAgent extends Agent
{
    public function name(): string
    {
        return 'cardiovascular';
    }

    public function outputFields(): array
    {
        return ['findings', 'concern_level', 'evidence_measurement_ids', 'missing_information'];
    }
}
