<?php
// Audit trail for important actions (login, alerts, profile/mission changes, AI and
// medical API requests). Stored in the audit_logs table; also written to the app log.
declare(strict_types=1);

function audit(string $action, ?int $userId, ?string $entityType = null, int|string|null $entityId = null, array $details = []): void
{
    $details = redact_secrets($details);
    log_event('audit.' . $action, ['user_id' => $userId, 'entity' => $entityType, 'entity_id' => $entityId, 'status' => 'recorded']);

    try {
        db()->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, client_hash)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            $action,
            $entityType,
            $entityId === null ? null : (string) $entityId,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            client_fingerprint(),
        ]);
    } catch (Throwable $error) {
        log_event('audit.write_failed', ['action' => $action, 'status' => 'error', 'error' => $error->getMessage()], 'error');
    }
}
