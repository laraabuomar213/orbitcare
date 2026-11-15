<?php
// Small shared helpers: config, URLs, escaping, CSRF, input cleaning, time formatting.
declare(strict_types=1);

function config(): array
{
    static $config = null;
    return $config ??= require dirname(__DIR__, 2) . '/config/config.php';
}

function health_thresholds(): array
{
    static $thresholds = null;
    return $thresholds ??= require dirname(__DIR__, 2) . '/config/health-thresholds.php';
}

function checkin_config(): array
{
    static $checkin = null;
    return $checkin ??= require dirname(__DIR__, 2) . '/config/checkin.php';
}

function alertness_config(): array
{
    static $alertness = null;
    return $alertness ??= require dirname(__DIR__, 2) . '/config/alertness.php';
}

// A check-in source for display: its title in the current language and its URL.
function checkin_source(array $config, string $key): array
{
    return ['label' => t("checkin.source.{$key}"), 'url' => $config['sources'][$key]['url']];
}

function ai_specialists_config(): array
{
    static $specialists = null;
    return $specialists ??= require dirname(__DIR__, 2) . '/config/ai-specialists.php';
}

// Builds a link from the project root, e.g. url('pages/signup.php') → /orbitcare/pages/signup.php
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

// Escapes text before it is printed into HTML.
function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

// CSRF protection: every form and API write carries a secret token that must match the session.
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(mixed $submitted): bool
{
    return is_string($submitted)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submitted);
}

// Single-line text: trimmed, inner whitespace collapsed.
function clean_text(mixed $value): string
{
    return is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';
}

// Multi-line text: trimmed, line breaks kept.
function clean_multiline(mixed $value): string
{
    return is_string($value) ? trim(str_replace("\r\n", "\n", $value)) : '';
}

// Returns why a password is not acceptable, or null if it is fine.
// 72 bytes is the most bcrypt can use, so longer passwords are refused rather than silently cut.
function password_problem(string $password): ?string
{
    if (strlen($password) < 8) {
        return t('err.password_short');
    }
    if (strlen($password) > 72) {
        return t('err.password_long');
    }
    if (!preg_match('/\pL/u', $password) || !preg_match('/\d/', $password)) {
        return t('err.password_mix');
    }
    return null;
}

// Database times are stored in UTC; the browser shows them in local time.
function iso_time(?string $utcDateTime): ?string
{
    if ($utcDateTime === null) {
        return null;
    }
    return (new DateTimeImmutable($utcDateTime, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
}

// Pseudonymised client address for logs/rate limits (raw IPs are never stored).
function client_fingerprint(): string
{
    return hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? 'cli', config()['auth_secret'] ?: 'orbitcare');
}
