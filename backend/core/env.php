<?php
// Reads settings: real environment variables first (hosting platform / secret manager),
// then the private .env file in the project root.
declare(strict_types=1);

function env(string $key, ?string $default = null): ?string
{
    static $fileValues = null;
    $fileValues ??= read_env_file(dirname(__DIR__, 2) . '/.env');

    $value = getenv($key);
    if ($value === false || $value === '') {
        $value = $fileValues[$key] ?? '';
    }
    return $value === '' ? $default : $value;
}

function read_env_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $values[$key] = trim($value, "\"'");
    }
    return $values;
}
