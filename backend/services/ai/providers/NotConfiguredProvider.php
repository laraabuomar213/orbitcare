<?php
// Safe default used until a real AI provider is configured (AI_PROVIDER / AI_API_KEY).
// It never produces medical content — it only reports that no provider is connected.
declare(strict_types=1);

final class NotConfiguredProvider implements AIProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function run(string $task, array $input): AIResult
    {
        return AIResult::notConfigured();
    }
}
