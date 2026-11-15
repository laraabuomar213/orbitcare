<?php
// Readings for the vest / headband sensors that the BIDMC recordings do not cover, from open recorded data
// stored by cli/ingest-open-sensors.php (sensor_sources / sensor_samples), plus the astronaut's own check-in.
//
//   skin temperature, skin conductance (GSR), movement → PhysioNet noneeg (healthy volunteer, wrist sensors)
//   personal dosimeter                                  → NASA OSDR RadLab, ISS DosTel1 dose rate
//   exercise (EMG strap), logged dose, sleep           → the astronaut's latest daily check-in (self-reported)
//
// Each recording is read at the same replay position as the dashboard (seconds from its start).
// The noneeg volunteer is a different person from the BIDMC patient — every reading says where it comes from.
declare(strict_types=1);

final class OpenSensorService
{
    public const RADIATION_SOURCE = 'radlab:ISS:DosTel1:2022-04-01';
    private const NONEEG_SUBJECTS = 20;
    private const BODY_SENSORS = ['skin_temperature', 'skin_conductance', 'movement'];
    private const SERIES_SECONDS = 60;          // body trend shown on the vest
    private const RADIATION_SERIES_SECONDS = 3600;

    // The same BIDMC record always gets the same volunteer (bidmc_NN → Subject ((NN-1) mod 20) + 1).
    public static function bodySourceFor(?string $recordId): ?string
    {
        return $recordId !== null && preg_match('/^bidmc_(\d{2})$/', $recordId, $m)
            ? 'noneeg:Subject' . (((int) $m[1] - 1) % self::NONEEG_SUBJECTS + 1)
            : null;
    }

    public function snapshot(int $userId, ?string $recordId, float $position): array
    {
        $sensors = [];
        $body = self::bodySourceFor($recordId);
        if ($body !== null) {
            foreach (self::BODY_SENSORS as $sensor) {
                $sensors[$sensor] = $this->reading($body, $sensor, $position, self::SERIES_SECONDS);
            }
        }
        $sensors['radiation_dose_rate'] = $this->reading(self::RADIATION_SOURCE, 'radiation_dose_rate', $position, self::RADIATION_SERIES_SECONDS, true);

        return ['sensors' => array_filter($sensors), 'self_reported' => $this->latestCheckin($userId)];
    }

    // Newest sample at or before the position, the samples of the last $window seconds, and the source.
    private function reading(string $sourceId, string $sensor, float $position, int $window, bool $withDayStats = false): ?array
    {
        $source = $this->source($sourceId);
        if ($source === null) {
            return null;
        }
        $statement = db()->prepare(
            'SELECT offset_seconds, value, unit, method FROM sensor_samples
             WHERE source_id = ? AND sensor = ? AND offset_seconds <= ? AND offset_seconds > ?
             ORDER BY offset_seconds'
        );
        $statement->execute([$sourceId, $sensor, $position, $position - $window]);
        $rows = $statement->fetchAll();
        if (!$rows) {
            // radiation is sparse (about one reading a minute): fall back to the newest one before the window
            $last = db()->prepare('SELECT offset_seconds, value, unit, method FROM sensor_samples WHERE source_id = ? AND sensor = ? AND offset_seconds <= ? ORDER BY offset_seconds DESC LIMIT 1');
            $last->execute([$sourceId, $sensor, $position]);
            $rows = $last->fetchAll();
        }
        if (!$rows) {
            return null;
        }
        $latest = end($rows);
        $reading = [
            'value' => (float) $latest['value'],
            'unit' => $latest['unit'],
            'method' => $latest['method'],
            'offset_seconds' => (float) $latest['offset_seconds'],
            'series' => array_map(fn ($r) => [(float) $r['offset_seconds'], (float) $r['value']], $rows),
            'source' => $source,
        ];
        if ($source['recording_start'] !== null) {
            $reading['measured_at'] = iso_time(
                (new DateTimeImmutable($source['recording_start'], new DateTimeZone('UTC')))->modify('+' . (int) $latest['offset_seconds'] . ' seconds')->format('Y-m-d H:i:s')
            );
        }
        if ($withDayStats) {
            $stats = db()->prepare('SELECT AVG(value) AS mean, COUNT(*) AS n FROM sensor_samples WHERE source_id = ? AND sensor = ?');
            $stats->execute([$sourceId, $sensor]);
            $s = $stats->fetch();
            // Derived: mean of the recorded dose rates over the whole recording, and that mean × 24 h.
            $reading['recording_mean'] = round((float) $s['mean'], 2);
            $reading['recording_mean_daily_mgy'] = round((float) $s['mean'] * 24 / 1000, 3);
            $reading['recording_samples'] = (int) $s['n'];
        }
        return $reading;
    }

    private function source(string $sourceId): ?array
    {
        static $cache = [];
        if (!array_key_exists($sourceId, $cache)) {
            $statement = db()->prepare('SELECT source_id, dataset, dataset_url, licence, description, recording_start FROM sensor_sources WHERE source_id = ?');
            $statement->execute([$sourceId]);
            $cache[$sourceId] = $statement->fetch() ?: null;
        }
        $s = $cache[$sourceId];
        return $s === null ? null : [
            'id' => $s['source_id'], 'dataset' => $s['dataset'], 'url' => $s['dataset_url'], 'licence' => $s['licence'],
            'description' => $s['description'], 'recording_start' => $s['recording_start'],
        ];
    }

    // The astronaut's most recent check-in, exactly as entered (no values are carried forward or estimated).
    private function latestCheckin(int $userId): ?array
    {
        $statement = db()->prepare(
            'SELECT checkin_date, sleep_hours, sleep_quality, resistance_minutes, aerobic_minutes, radiation_dose_msv
             FROM daily_checkins WHERE user_id = ? ORDER BY checkin_date DESC LIMIT 1'
        );
        $statement->execute([$userId]);
        $row = $statement->fetch();
        return $row ? [
            'date' => $row['checkin_date'],
            'sleep_hours' => (float) $row['sleep_hours'],
            'sleep_quality' => (int) $row['sleep_quality'],
            'resistance_minutes' => (int) $row['resistance_minutes'],
            'aerobic_minutes' => (int) $row['aerobic_minutes'],
            'radiation_dose_msv' => $row['radiation_dose_msv'] === null ? null : (float) $row['radiation_dose_msv'],
        ] : null;
    }
}
