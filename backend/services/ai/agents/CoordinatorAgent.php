<?php
// Specialised agent — input/output contract in docs/AI_ARCHITECTURE.md.
declare(strict_types=1);

// Input: { agent_outputs: {cardiovascular, respiratory, general_health, symptom_interview} }
final class CoordinatorAgent extends Agent
{
    public function name(): string
    {
        return 'coordinator';
    }

    public function outputFields(): array
    {
        return ['overall_interpretation', 'confidence', 'important_findings', 'recommended_next_step', 'recommended_specialty'];
    }
}
