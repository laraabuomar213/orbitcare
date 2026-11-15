<?php
// Contract every AI provider must implement. The rest of the app only talks to this
// interface, so a real provider can be plugged in without changing routes or frontend.
declare(strict_types=1);

interface AIProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * Runs one agent task.
     *
     * @param string $task   e.g. 'cardiovascular', 'respiratory', 'general', 'symptom_interview', 'coordinator', 'chat'
     * @param array  $input  structured input (see docs/AI_ARCHITECTURE.md); contains ONLY real data
     * @return AIResult
     */
    public function run(string $task, array $input): AIResult;
}
