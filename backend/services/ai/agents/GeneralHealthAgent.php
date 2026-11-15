<?php
// Specialised agent — input/output contract in docs/AI_ARCHITECTURE.md.
declare(strict_types=1);

// Input: { status: {...}, active_alerts: [...], profile_context: {age, height_cm, weight_kg} }
final class GeneralHealthAgent extends Agent
{
    public function name(): string
    {
        return 'general_health';
    }

    public function outputFields(): array
    {
        return ['summary', 'concern_level', 'missing_information'];
    }
}
