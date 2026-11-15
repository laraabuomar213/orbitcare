<?php
// The result of one AI task. status is 'success', 'not_configured' or 'error'.
// When status is not 'success', output is empty — nothing is ever made up to fill it.
declare(strict_types=1);

final class AIResult
{
    public function __construct(
        public readonly string $status,
        public readonly array $output = [],
        public readonly ?string $message = null,
        public readonly string $provider = 'none'
    ) {
    }

    public static function notConfigured(): self
    {
        return new self('not_configured', [], t('ai.status.not_configured'));
    }

    public static function error(string $message, string $provider): self
    {
        return new self('error', [], $message, $provider);
    }

    public function ok(): bool
    {
        return $this->status === 'success';
    }
}
