<?php
// One shared PDO connection built from DATABASE_URL. Always use prepared statements with it.
declare(strict_types=1);

final class DatabaseUnavailable extends RuntimeException
{
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $databaseUrl = config()['database_url'];
    $parts = $databaseUrl ? parse_url($databaseUrl) : false;
    if (!$parts || ($parts['scheme'] ?? '') !== 'mysql' || empty($parts['path'])) {
        throw new DatabaseUnavailable('DATABASE_URL is missing or invalid (expected mysql://user:pass@host:port/db).');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $parts['host'] ?? '127.0.0.1',
        $parts['port'] ?? 3306,
        ltrim($parts['path'], '/')
    );

    try {
        $pdo = new PDO($dsn, urldecode($parts['user'] ?? ''), urldecode($parts['pass'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $error) {
        // The message can contain the user name, so only the error code is passed on.
        log_event('database.connect', ['status' => 'error', 'code' => $error->getCode()], 'error');
        throw new DatabaseUnavailable('Could not connect to the database.', 0, $error);
    }

    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

// Runs $work inside a transaction and rolls back on any error.
function db_transaction(callable $work): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $work($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
