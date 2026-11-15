<?php
// Persistent AI consultations and their messages (the database is the source of truth).
// Every read and write is scoped to the authenticated user: a consultation that belongs to
// someone else is reported as "not found", so its existence is never revealed.
declare(strict_types=1);

final class ConversationService
{
    public function list(int $userId, int $limit = 50): array
    {
        $statement = db()->prepare(
            "SELECT c.*, (SELECT COUNT(*) FROM conversation_messages m WHERE m.conversation_id = c.id) AS message_count
             FROM conversations c WHERE c.user_id = ?
             ORDER BY c.status = 'active' DESC, c.updated_at DESC LIMIT " . max(1, min(200, $limit))
        );
        $statement->execute([$userId]);
        return array_map([$this, 'format'], $statement->fetchAll());
    }

    public function get(int $userId, int $conversationId): array
    {
        $statement = db()->prepare(
            'SELECT c.*, (SELECT COUNT(*) FROM conversation_messages m WHERE m.conversation_id = c.id) AS message_count
             FROM conversations c WHERE c.id = ? AND c.user_id = ?'
        );
        $statement->execute([$conversationId, $userId]);
        $row = $statement->fetch();
        if (!$row) {
            throw ApiException::notFound(t('err.consultation_not_found'));
        }
        return $this->format($row) + ['handoff' => $row['handoff'] ? json_decode($row['handoff'], true) : null];
    }

    public function activeFor(int $userId, string $specialist): ?array
    {
        $statement = db()->prepare("SELECT id FROM conversations WHERE user_id = ? AND specialist = ? AND status = 'active' ORDER BY updated_at DESC LIMIT 1");
        $statement->execute([$userId, $specialist]);
        $id = $statement->fetchColumn();
        return $id === false ? null : $this->get($userId, (int) $id);
    }

    public function latest(int $userId, string $specialist): ?array
    {
        $statement = db()->prepare('SELECT id FROM conversations WHERE user_id = ? AND specialist = ? ORDER BY updated_at DESC LIMIT 1');
        $statement->execute([$userId, $specialist]);
        $id = $statement->fetchColumn();
        return $id === false ? null : $this->get($userId, (int) $id);
    }

    public function create(int $userId, string $specialist, ?int $referredFrom = null, ?array $handoff = null): array
    {
        db()->prepare('INSERT INTO conversations (user_id, specialist, referred_from_conversation_id, handoff) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $specialist, $referredFrom, $handoff ? json_encode($handoff, JSON_UNESCAPED_UNICODE) : null]);
        $id = (int) db()->lastInsertId();
        audit('ai.consultation_open', $userId, 'conversation', $id, ['specialist' => $specialist, 'referred_from' => $referredFrom]);
        return $this->get($userId, $id);
    }

    public function close(int $userId, int $conversationId): array
    {
        $this->get($userId, $conversationId);
        db()->prepare("UPDATE conversations SET status = 'closed', updated_at = UTC_TIMESTAMP(3) WHERE id = ? AND user_id = ?")
            ->execute([$conversationId, $userId]);
        audit('ai.consultation_close', $userId, 'conversation', $conversationId);
        return $this->get($userId, $conversationId);
    }

    // Messages of a consultation the caller already loaded with get() (ownership checked there).
    public function messages(int $conversationId, int $afterId = 0, int $limit = 200): array
    {
        $statement = db()->prepare(
            'SELECT * FROM (SELECT id, sender, body, payload, created_at FROM conversation_messages
             WHERE conversation_id = ? AND id > ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)) . ') latest ORDER BY id'
        );
        $statement->execute([$conversationId, $afterId]);
        return array_map([$this, 'formatMessage'], $statement->fetchAll());
    }

    public function userTexts(int $conversationId, int $limit = 20): array
    {
        $statement = db()->prepare("SELECT body FROM conversation_messages WHERE conversation_id = ? AND sender = 'user' ORDER BY id DESC LIMIT " . max(1, $limit));
        $statement->execute([$conversationId]);
        return array_reverse($statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function addMessage(int $conversationId, string $sender, string $body, ?array $payload = null, ?int $analysisId = null): array
    {
        db()->prepare('INSERT INTO conversation_messages (conversation_id, sender, body, payload, ai_analysis_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([$conversationId, $sender, $body, $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null, $analysisId]);
        $id = (int) db()->lastInsertId();
        db()->prepare('UPDATE conversations SET updated_at = UTC_TIMESTAMP(3) WHERE id = ?')->execute([$conversationId]);

        $statement = db()->prepare('SELECT id, sender, body, payload, created_at FROM conversation_messages WHERE id = ?');
        $statement->execute([$id]);
        return $this->formatMessage($statement->fetch());
    }

    public function lastMessage(int $conversationId): ?array
    {
        $statement = db()->prepare('SELECT id, sender, body, payload, created_at FROM conversation_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 1');
        $statement->execute([$conversationId]);
        $row = $statement->fetch();
        return $row ? $this->formatMessage($row) : null;
    }

    private function format(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'specialist' => $row['specialist'],
            'specialist_name' => t("ai.specialist.{$row['specialist']}.name"),
            'status' => $row['status'],
            'referred_from' => $row['referred_from_conversation_id'] ? (int) $row['referred_from_conversation_id'] : null,
            'created_at' => iso_time($row['created_at']),
            'updated_at' => iso_time($row['updated_at']),
            'message_count' => (int) ($row['message_count'] ?? 0),
        ];
    }

    private function formatMessage(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'sender' => $row['sender'],
            'body' => $row['body'],
            'payload' => $row['payload'] ? json_decode($row['payload'], true) : null,
            'created_at' => iso_time($row['created_at']),
        ];
    }
}
