<?php
// Read side for health data: current values, history and trends for one astronaut.
// Everything returned carries its provenance (source, record, raw measurement ID,
// original timestamp, unit) and is clearly marked as recorded data being replayed.
declare(strict_types=1);

final class HealthService
{
    public const RANGES = ['1h' => 3600, '6h' => 21600, '24h' => 86400];

    public function __construct(
        private DataReplay $replay = new DataReplay(),
        private HealthAnalysis $analysis = new HealthAnalysis()
    ) {
    }

    // Loads the astronaut and makes sure their replay has caught up with the clock.
    public function astronaut(int $userId): array
    {
        $statement = db()->prepare(
            "SELECT u.id, u.full_name, a.astronaut_code, a.data_source_record_id
             FROM users u JOIN astronauts a ON a.user_id = u.id
             WHERE u.id = ? AND u.role = 'astronaut'"
        );
        $statement->execute([$userId]);
        return $statement->fetch() ?: throw ApiException::notFound(t('err.astronaut_not_found'));
    }

    public function syncedSession(array $astronaut): ?array
    {
        $session = $this->replay->ensureSession((int) $astronaut['id'], $astronaut['data_source_record_id']);
        return $session ? $this->replay->advance((int) $session['id']) + $session : null;
    }

    public function current(int $userId): array
    {
        $astronaut = $this->astronaut($userId);
        $session = $this->syncedSession($astronaut);

        $metrics = [];
        foreach ($this->analysis->metrics() as $metric => $rule) {
            $metrics[$metric] = ['metric' => $metric, 'label' => $this->analysis->label($metric, current_language()), 'unit' => $rule['unit'], 'available' => false, 'reason' => t('health.no_data_yet')];
        }

        if ($session === null) {
            return [
                'replay' => null,
                'metrics' => array_values($metrics),
                'status' => $this->analysis->overallStatus(array_fill_keys(array_keys($metrics), null), 0, 0),
                'active_alerts' => ['red' => 0, 'yellow' => 0],
                'last_update' => null,
            ];
        }

        $position = (float) $session['last_processed_offset'];
        // At least three samples' worth, so a 5-second device stream is not shown as stale between readings.
        $staleAfter = max(config()['replay']['stale_after_seconds'], 3 * (float) ($session['sample_interval_seconds'] ?? 1));

        $latest = db()->prepare(
            'SELECT pm.metric, pm.classification, pm.replayed_at, rm.id AS raw_id, rm.value, rm.unit,
                    rm.original_timestamp, rm.source, rm.source_record_id, rm.source_row
             FROM processed_measurements pm
             JOIN raw_measurements rm ON rm.id = pm.raw_measurement_id
             WHERE pm.id IN (SELECT MAX(id) FROM processed_measurements WHERE replay_session_id = ? GROUP BY metric)'
        );
        $latest->execute([$session['id']]);

        $classifications = array_fill_keys(array_keys($metrics), null);
        $lastUpdate = null;
        foreach ($latest as $row) {
            // Shown only if recent relative to the replay position — also after the replay ends,
            // so a sensor that stopped early in the recording is not presented as current.
            $age = $position - (float) $row['original_timestamp'];
            $fresh = $age <= $staleAfter;
            $metrics[$row['metric']] = array_merge($metrics[$row['metric']], [
                'available' => $fresh,
                'reason' => $fresh ? null : t('health.no_reading_for', ['seconds' => (int) $age]),
                'value' => $fresh ? (float) $row['value'] : null,
                'classification' => $fresh ? $row['classification'] : null,
                'measured_at' => iso_time($row['replayed_at']),
                'provenance' => $this->provenance($row),
            ]);
            if ($fresh) {
                $classifications[$row['metric']] = $row['classification'];
            }
            $lastUpdate = max($lastUpdate ?? '', $row['replayed_at']);
        }

        $active = db()->prepare(
            "SELECT severity, COUNT(*) AS total FROM alerts WHERE user_id = ? AND status <> 'resolved' GROUP BY severity"
        );
        $active->execute([$userId]);
        // Real counts first: with "+", keys from the left-hand array win.
        $counts = array_column($active->fetchAll(), 'total', 'severity') + ['RED' => 0, 'YELLOW' => 0];

        return [
            'replay' => $this->replayInfo($session),
            'metrics' => array_values($metrics),
            'status' => $this->analysis->overallStatus($classifications, (int) $counts['RED'], (int) $counts['YELLOW']),
            'active_alerts' => ['red' => (int) $counts['RED'], 'yellow' => (int) $counts['YELLOW']],
            'last_update' => iso_time($lastUpdate),
        ];
    }

    // Replayed samples for one metric within a time range (by replay time), oldest first.
    // Pass $afterId to receive only samples newer than the last one the client already has.
    public function trend(int $userId, string $metric, string $range, int $afterId = 0): array
    {
        $this->requireKnownMetric($metric);
        $seconds = self::RANGES[$range] ?? throw ApiException::validation(['range' => t('err.range')]);

        $astronaut = $this->astronaut($userId);
        $session = $this->syncedSession($astronaut);

        // The range ends at the newest replayed sample. While a replay runs that is "now"; after it
        // has finished, the chart keeps showing the last recorded data instead of going empty.
        $latest = db()->prepare('SELECT MAX(replayed_at) FROM processed_measurements WHERE user_id = ? AND metric = ?');
        $latest->execute([$userId, $metric]);
        $windowEnd = $latest->fetchColumn() ?: null;

        $statement = db()->prepare(
            'SELECT pm.id, pm.replayed_at, pm.classification, rm.id AS raw_id, rm.value, rm.original_timestamp
             FROM processed_measurements pm
             JOIN raw_measurements rm ON rm.id = pm.raw_measurement_id
             WHERE pm.user_id = ? AND pm.metric = ? AND pm.id > ?
               AND pm.replayed_at >= ? - INTERVAL ? SECOND
             ORDER BY pm.replayed_at, pm.id'
        );
        $statement->execute([$userId, $metric, $afterId, $windowEnd ?? gmdate('Y-m-d H:i:s'), $seconds]);
        $isLive = $session !== null && $session['status'] === 'running';

        $points = array_map(fn ($row) => [
            'id' => (int) $row['id'],
            't' => iso_time($row['replayed_at']),
            'value' => (float) $row['value'],
            'classification' => $row['classification'],
            'raw_id' => (int) $row['raw_id'],
            'offset' => (float) $row['original_timestamp'],
        ], $statement->fetchAll());

        $rule = $this->analysis->metric($metric);
        return [
            'metric' => $metric, 'label' => $this->analysis->label($metric, current_language()), 'unit' => $rule['unit'], 'range' => $range,
            'range_seconds' => $seconds,
            'window_end' => iso_time($windowEnd),
            'live' => $isLive,
            'green' => $rule['green'], 'yellow' => $rule['yellow'],
            'points' => $points,
            'replay' => $session ? $this->replayInfo($session) : null,
        ];
    }

    // Paged raw history with provenance, newest first.
    public function history(int $userId, string $metric, int $limit, int $beforeId = 0): array
    {
        $this->requireKnownMetric($metric);
        $limit = max(1, min(200, $limit));
        $this->syncedSession($this->astronaut($userId));

        $statement = db()->prepare(
            'SELECT pm.id, pm.replayed_at, pm.classification, pm.threshold_version,
                    rm.id AS raw_id, rm.value, rm.unit, rm.original_timestamp, rm.source, rm.source_record_id, rm.source_row
             FROM processed_measurements pm
             JOIN raw_measurements rm ON rm.id = pm.raw_measurement_id
             WHERE pm.user_id = ? AND pm.metric = ? AND (? = 0 OR pm.id < ?)
             ORDER BY pm.id DESC LIMIT ' . $limit
        );
        $statement->execute([$userId, $metric, $beforeId, $beforeId]);

        $rows = array_map(fn ($row) => [
            'id' => (int) $row['id'],
            'measured_at' => iso_time($row['replayed_at']),
            'value' => (float) $row['value'],
            'unit' => $row['unit'],
            'classification' => $row['classification'],
            'threshold_version' => $row['threshold_version'],
            'provenance' => $this->provenance($row),
        ], $statement->fetchAll());

        // Derived summary over everything replayed so far (labelled as derived in the UI).
        $summary = db()->prepare(
            'SELECT COUNT(*) AS samples, MIN(rm.value) AS min, MAX(rm.value) AS max, AVG(rm.value) AS mean,
                    SUM(pm.classification = "GREEN") AS green, SUM(pm.classification = "YELLOW") AS yellow, SUM(pm.classification = "RED") AS red
             FROM processed_measurements pm JOIN raw_measurements rm ON rm.id = pm.raw_measurement_id
             WHERE pm.user_id = ? AND pm.metric = ?'
        );
        $summary->execute([$userId, $metric]);
        $stats = $summary->fetch();

        return [
            'metric' => $metric,
            'rows' => $rows,
            'next_before_id' => count($rows) === $limit ? end($rows)['id'] : null,
            'summary' => [
                'samples' => (int) $stats['samples'],
                'min' => $stats['min'] === null ? null : (float) $stats['min'],
                'max' => $stats['max'] === null ? null : (float) $stats['max'],
                'mean' => $stats['mean'] === null ? null : round((float) $stats['mean'], 1),
                'by_classification' => ['GREEN' => (int) $stats['green'], 'YELLOW' => (int) $stats['yellow'], 'RED' => (int) $stats['red']],
            ],
        ];
    }

    // The last few seconds of the recording's real waveforms (ECG, PPG, respiration), ending at the
    // same replay position as the numbers on the dashboard. Only for PhysioNet replays.
    public function waveform(int $userId, float $seconds): array
    {
        $seconds = max(2.0, min(12.0, $seconds));
        $session = $this->syncedSession($this->astronaut($userId));
        $none = ['available' => false, 'replay' => $session ? $this->replayInfo($session) : null];
        if ($session === null || self::sourceMode($session['source_file'] ?? null) !== 'recorded_data_replay') {
            return $none;
        }
        $window = (new WaveformService())->window((string) $session['source_record_id'], (float) $session['last_processed_offset'], $seconds);
        return $window === null ? $none : ['available' => true, 'replay' => $this->replayInfo($session)] + $window;
    }

    // Open recorded data for the other vest / headband sensors, at the same replay position, plus the latest check-in.
    public function sensors(int $userId): array
    {
        $astronaut = $this->astronaut($userId);
        $session = $this->syncedSession($astronaut);
        $position = $session ? (float) $session['last_processed_offset'] : 0.0;
        $recordId = $session && self::sourceMode($session['source_file'] ?? null) === 'recorded_data_replay'
            ? (string) $session['source_record_id'] : null;
        return ['replay' => $session ? $this->replayInfo($session) : null]
            + (new OpenSensorService())->snapshot($userId, $recordId, $position);
    }

    public function sourceRecord(string $recordId): ?array
    {
        $statement = db()->prepare('SELECT * FROM source_records WHERE record_id = ?');
        $statement->execute([$recordId]);
        $record = $statement->fetch();
        if (!$record) {
            return null;
        }
        $mode = self::sourceMode($record['source_file']);
        return [
            'record_id' => $record['record_id'],
            'mode' => $mode,
            'device_id' => $mode === 'recorded_data_replay' ? null : self::deviceName($record['source_file']),
            'source' => $record['source'],
            'source_file' => $record['source_file'],
            'source_url' => $mode === 'recorded_data_replay' ? 'https://physionet.org/content/bidmc/1.0.0/' : null,
            'duration_seconds' => (int) $record['duration_seconds'],
            'rows_read' => (int) $record['rows_read'],
            'values_stored' => (int) $record['values_stored'],
            'values_missing' => (int) $record['values_missing'],
            'values_rejected' => (int) $record['values_rejected'],
            'ingested_at' => iso_time($record['ingested_at']),
        ];
    }

    // recorded_data_replay (PhysioNet) | live_device_simulated (vest simulation) | live_device (vest sensors)
    public static function sourceMode(?string $sourceFile): string
    {
        return match (true) {
            str_starts_with((string) $sourceFile, 'device-sim:') => 'live_device_simulated',
            str_starts_with((string) $sourceFile, 'device:') => 'live_device',
            default => 'recorded_data_replay',
        };
    }

    private static function deviceName(string $sourceFile): string
    {
        return substr($sourceFile, strpos($sourceFile, ':') + 1);
    }

    public function replayInfo(array $session): array
    {
        $mode = self::sourceMode($session['source_file'] ?? null);
        return [
            'session_id' => (int) $session['id'],
            'mode' => $mode,
            'device_id' => $mode === 'recorded_data_replay' ? null : self::deviceName((string) $session['source_file']),
            'source' => $session['source'],
            'record_id' => $session['source_record_id'],
            'status' => $session['status'],
            'position_seconds' => round((float) $session['last_processed_offset'], 1),
            'duration_seconds' => (int) $session['duration_seconds'],
            'speed' => (float) $session['speed'],
            'started_at' => iso_time($session['started_at']),
        ];
    }

    // Only metrics that exist in the data source can be requested (e.g. no "temperature").
    private function requireKnownMetric(string $metric): void
    {
        if (!isset($this->analysis->metrics()[$metric])) {
            throw ApiException::validation(['metric' => t('err.unknown_metric')]);
        }
    }

    private function provenance(array $row): array
    {
        return [
            'raw_measurement_id' => (int) $row['raw_id'],
            'source' => $row['source'],
            'source_record_id' => $row['source_record_id'],
            'source_row' => (int) $row['source_row'],
            'original_timestamp_seconds' => (float) $row['original_timestamp'],
        ];
    }
}
