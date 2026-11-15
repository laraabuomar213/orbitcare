<?php
// CORS and rate limiting for the API.
declare(strict_types=1);

// Same-origin only by default. Extra origins can be allowed with CORS_ALLOWED_ORIGINS.
function enforce_cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if ($origin === null) {
        return;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $sameOrigin = $origin === $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '');

    if ($sameOrigin) {
        return;
    }
    if (in_array($origin, config()['cors_allowed_origins'], true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
        return;
    }
    throw new ApiException(403, 'origin_not_allowed', t('err.origin'));
}

// Allows at most $limit requests per $windowSeconds for this client in the given bucket.
function enforce_rate_limit(string $bucket, int $limit, int $windowSeconds): void
{
    $key = client_fingerprint();
    $pdo = db();

    $pdo->prepare('DELETE FROM rate_limit_hits WHERE bucket = ? AND created_at < UTC_TIMESTAMP() - INTERVAL ? SECOND')
        ->execute([$bucket, $windowSeconds]);

    $count = $pdo->prepare('SELECT COUNT(*) FROM rate_limit_hits WHERE bucket = ? AND client_hash = ?');
    $count->execute([$bucket, $key]);

    if ((int) $count->fetchColumn() >= $limit) {
        log_event('security.rate_limited', ['bucket' => $bucket, 'status' => 'blocked'], 'warning');
        throw new ApiException(429, 'rate_limited', t('err.rate_limited'));
    }

    $pdo->prepare('INSERT INTO rate_limit_hits (bucket, client_hash) VALUES (?, ?)')->execute([$bucket, $key]);
}
