<?php
// Data replay: plays a recorded dataset record back as if it were arriving live.
//
// A replay session maps the recording's own clock onto wall-clock time:
//     replay position (s) = (now − started_at) × speed, capped at the record's length
// Every request simply "catches up" to the current position: measurements whose original
// timestamp has been reached are analysed, stored as processed_measurements and fed to
// the alert engine — in order, exactly once. No background process, no altered values,
// no invented samples. When the recording ends, the replay ends (it does not loop).
declare(strict_types=1);

final class DataReplay
{
    public function __construct(
        private HealthAnalysis $analysis = new HealthAnalysis(),
        private ?AlertEngine $engine = null
    ) {
        $this->engine ??= new AlertEngine($this->analysis);
    }

    // The astronaut's current replay (running or finished), or null if none started yet.
    public function currentSession(int $userId): ?array
    {
        $statement = db()->prepare(
            "SELECT rs.*, sr.duration_seconds, sr.sample_interval_seconds, sr.source, sr.source_file
             FROM replay_sessions rs JOIN source_records sr ON sr.record_id = rs.source_record_id
             WHERE rs.user_id = ? AND rs.status <> 'stopped'
             ORDER BY rs.id DESC LIMIT 1"
        );
        $statement->execute([$userId]);
        return $statement->fetch() ?: null;
    }

    // Starts a replay for the astronaut's assigned record if none exists yet.
    public function ensureSession(int $userId, ?string $recordId): ?array
    {
        $session = $this->currentSession($userId);
        if ($session !== null || $recordId === null) {
            return $session;
        }
        return $this->start($userId, $recordId);
    }

    // $speed: recorded data uses the configured replay speed; a live device stream always runs at 1.
    public function start(int $userId, string $recordId, ?float $startedAt = null, ?float $speed = null): array
    {
        $record = db()->prepare('SELECT record_id FROM source_records WHERE record_id = ?');
        $record->execute([$recordId]);
        if (!$record->fetch()) {
            throw new ApiException(409, 'record_not_ingested', t('err.record_not_ingested', ['record' => $recordId]));
        }

        db()->prepare("UPDATE replay_sessions SET status = 'stopped' WHERE user_id = ? AND status = 'running'")->execute([$userId]);
        db()->prepare('INSERT INTO replay_sessions (user_id, source_record_id, started_at, speed) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $recordId, $this->formatTime($startedAt ?? microtime(true)), $speed ?? config()['replay']['speed']]);

        $sessionId = (int) db()->lastInsertId();
        log_event('replay.started', ['session_id' => $sessionId, 'user_id' => $userId, 'record_id' => $recordId, 'status' => 'running']);
        return $this->currentSession($userId);
    }

    public function restart(int $userId, string $recordId): array
    {
        audit('replay.restart', $userId, 'replay', $recordId);
        return $this->start($userId, $recordId);
    }

    // Seconds into the recording that the replay has reached.
    public function position(array $session, ?float $now = null): float
    {
        $started = $this->parseTime($session['started_at']);
        $elapsed = max(0.0, (($now ?? microtime(true)) - $started) * (float) $session['speed']);
        return min($elapsed, (float) $session['duration_seconds']);
    }

    // Wall-clock time at which a given offset of the recording is replayed.
    public function replayTime(array $session, float $offset): string
    {
        return $this->formatTime($this->parseTime($session['started_at']) + $offset / (float) $session['speed']);
    }

    // Processes every measurement the replay has reached since the last call.
    public function advance(int $sessionId, ?float $now = null, bool $isTestFixture = false): array
    {
        return db_transaction(function (PDO $pdo) use ($sessionId, $now, $isTestFixture): array {
            $lock = $pdo->prepare(
                'SELECT rs.*, sr.duration_seconds, sr.sample_interval_seconds, sr.source, sr.source_file
                 FROM replay_sessions rs JOIN source_records sr ON sr.record_id = rs.source_record_id
                 WHERE rs.id = ? FOR UPDATE'
            );
            $lock->execute([$sessionId]);
            $session = $lock->fetch();
            if (!$session || $session['status'] !== 'running') {
                return $session ?: throw new RuntimeException("Replay session {$sessionId} not found");
            }

            $position = $this->position($session, $now);
            if ($position <= (float) $session['last_processed_offset']) {
                return $session;
            }

            $rows = $pdo->prepare(
                'SELECT id, metric, value, unit, original_timestamp
                 FROM raw_measurements
                 WHERE source_record_id = ? AND original_timestamp > ? AND original_timestamp <= ?
                 ORDER BY original_timestamp, metric'
            );
            $rows->execute([$session['source_record_id'], $session['last_processed_offset'], $position]);

            $state = json_decode((string) $session['engine_state'], true) ?: [];
            $missionId = (new MissionService())->primaryMissionId((int) $session['user_id']);
            $insert = $pdo->prepare(
                'INSERT IGNORE INTO processed_measurements
                    (raw_measurement_id, replay_session_id, user_id, metric, classification, threshold_version, replayed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );

            $count = 0;
            foreach ($rows as $sample) {
                $sample['replayed_at'] = $this->replayTime($session, (float) $sample['original_timestamp']);
                $classification = $this->analysis->classify($sample['metric'], (float) $sample['value']);
                $insert->execute([$sample['id'], $session['id'], $session['user_id'], $sample['metric'], $classification, $this->analysis->version(), $sample['replayed_at']]);
                $this->engine->observe($session, $sample, $classification, $state, $missionId, $isTestFixture);
                $count++;
            }

            $finished = $position >= (float) $session['duration_seconds'];
            $pdo->prepare(
                "UPDATE replay_sessions
                 SET last_processed_offset = ?, engine_state = ?, status = ?, completed_at = IF(? , ?, completed_at)
                 WHERE id = ?"
            )->execute([
                $position, json_encode($state), $finished ? 'completed' : 'running',
                (int) $finished, $finished ? $this->replayTime($session, (float) $session['duration_seconds']) : null,
                $session['id'],
            ]);

            if ($count > 0) {
                log_event('replay.advanced', ['session_id' => $session['id'], 'position' => round($position, 1), 'samples' => $count, 'status' => $finished ? 'completed' : 'running']);
            }

            $session['last_processed_offset'] = $position;
            $session['status'] = $finished ? 'completed' : 'running';
            return $session;
        });
    }

    private function parseTime(string $utc): float
    {
        return (float) (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('U.u');
    }

    private function formatTime(float $timestamp): string
    {
        return DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $timestamp), new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
