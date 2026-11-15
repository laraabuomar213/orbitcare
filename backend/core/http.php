<?php
// JSON API responses and errors.
// Success: { "ok": true,  "data": {...} }
// Error:   { "ok": false, "error": { "code": "...", "message": "...", "fields": {...} } }
declare(strict_types=1);

final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = []
    ) {
        parent::__construct($message);
    }

    // Messages are shown to users, so they are passed in already translated (t()).
    public static function validation(array $fields, ?string $message = null): self
    {
        return new self(422, 'validation_failed', $message ?? t('err.fix_fields'), $fields);
    }

    public static function notFound(?string $message = null): self
    {
        return new self(404, 'not_found', $message ?? t('err.not_found'));
    }

    public static function forbidden(?string $message = null): self
    {
        return new self(403, 'forbidden', $message ?? t('err.forbidden'));
    }
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function json_error(int $status, string $code, string $message, array $fields = []): never
{
    $error = ['code' => $code, 'message' => $message];
    if ($fields) {
        $error['fields'] = $fields;
    }
    json_response(['ok' => false, 'error' => $error], $status);
}

// Request body as an array: JSON (Content-Type: application/json) or a normal form post.
function request_body(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new ApiException(400, 'invalid_json', t('err.invalid_json'));
        }
        return $data;
    }
    return $_POST;
}

function query_param(string $key, ?string $default = null): ?string
{
    $value = $_GET[$key] ?? null;
    return is_string($value) && $value !== '' ? $value : $default;
}
