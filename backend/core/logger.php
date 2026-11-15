<?php
// Structured logging: one JSON object per line in logs/app.log.
// Every entry has a timestamp, level and event name; secrets are always removed.
declare(strict_types=1);

function log_event(string $event, array $context = [], string $level = 'info'): void
{
    $entry = ['ts' => gmdate('Y-m-d\TH:i:s\Z'), 'level' => $level, 'event' => $event] + redact_secrets($context);

    $file = config()['log_file'];
    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0775, true);
    }
    @file_put_contents($file, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function redact_secrets(array $context): array
{
    foreach ($context as $key => $value) {
        if (is_string($key) && preg_match('/pass|secret|token|api_?key|authorization/i', $key)) {
            $context[$key] = '[redacted]';
        } elseif (is_array($value)) {
            $context[$key] = redact_secrets($value);
        }
    }
    return $context;
}
