<?php
// Specialised agent — input/output contract in docs/AI_ARCHITECTURE.md.
declare(strict_types=1);

// Input: { messages: [{sender, body}] } â€” only what the user actually wrote.
final class SymptomInterviewAgent extends Agent
{
    public function name(): string
    {
        return 'symptom_interview';
    }

    public function outputFields(): array
    {
        return ['reported_symptoms', 'follow_up_questions', 'red_flags'];
    }
}
