<?php
// Alertness Check (3-minute PVT-B + optional camera eye signs).
//
// The browser runs the test and sends the raw reaction times; the server recomputes every result
// (lapses, false starts, median, fastest 10 %) from them with the published PVT-B rules, so the
// stored numbers never depend on browser-side arithmetic. Camera values are on-device estimates:
// only blink count, eyes-closed time and face time arrive here — never images or video.
// Each result is compared with the astronaut's OWN earlier tests (OrbitCare rule), not a population norm.
declare(strict_types=1);

final class AlertnessService
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? alertness_config();
    }

    public function overview(int $userId): array
    {
        $tests = $this->recentTests($userId, $this->config['baseline']['days'] + 1);
        $latest = $tests[0] ?? null;
        $earlier = $latest ? array_slice($tests, 1) : [];
        $baseline = $this->baseline($earlier, $latest['taken_at_raw'] ?? null);

        return [
            'test' => $this->config['test'],
            'camera_rules' => $this->config['camera'],
            'sources' => $this->config['sources'],
            'latest' => $latest ? $this->publicTest($latest) : null,
            'baseline' => $baseline,
            'status' => $latest ? $this->status($latest, $baseline) : null,
            'history' => $this->history($tests),
            'tests_total' => $this->countTests($userId),
        ];
    }

    public function save(int $userId, array $body): array
    {
        $test = $this->config['test'];
        $errors = [];

        $times = $body['reaction_times'] ?? null;
        if (!is_array($times) || count($times) < 1 || count($times) > 400) {
            $errors['reaction_times'] = t('err.alertness.reaction_times');
        } else {
            foreach ($times as $time) {
                if (!is_int($time) && !(is_string($time) && ctype_digit($time)) || (int) $time < 0 || (int) $time > $test['no_response_ms']) {
                    $errors['reaction_times'] = t('err.alertness.reaction_times');
                    break;
                }
            }
        }
        $anticipations = $body['anticipations'] ?? null;
        if (!is_numeric($anticipations) || (int) $anticipations < 0 || (int) $anticipations > 400) {
            $errors['anticipations'] = t('err.alertness.anticipations');
        }
        $duration = $body['duration_seconds'] ?? null;
        if (!is_numeric($duration) || abs((float) $duration - $test['duration_seconds']) > 10) {
            $errors['duration_seconds'] = t('err.alertness.duration', ['seconds' => $test['duration_seconds']]);
        }
        $camera = $this->validateCamera($body['camera'] ?? null, (float) $duration, $errors);
        if ($errors) {
            throw ApiException::validation($errors);
        }

        $result = $this->score(array_map('intval', $times), (int) $anticipations);
        db()->prepare(
            'INSERT INTO alertness_tests
                (user_id, taken_at, test_version, duration_seconds, stimuli, valid_responses, lapses, false_starts,
                 median_rt_ms, fastest10_rt_ms, reaction_times, camera_used, face_seconds, blinks_per_minute, perclos_pct)
             VALUES (?, UTC_TIMESTAMP(3), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId, $this->config['version'], (int) round((float) $duration), $result['stimuli'], $result['valid_responses'],
            $result['lapses'], $result['false_starts'], $result['median_rt_ms'], $result['fastest10_rt_ms'],
            json_encode(array_map('intval', $times)), (int) ($camera !== null), $camera['face_seconds'] ?? null,
            $camera['blinks_per_minute'] ?? null, $camera['perclos_pct'] ?? null,
        ]);
        $id = (int) db()->lastInsertId();

        audit('alertness.save', $userId, 'alertness_test', $id, ['camera' => $camera !== null]);
        log_event('alertness.saved', ['user_id' => $userId, 'test_id' => $id, 'status' => 'success']);
        return $this->overview($userId);
    }

    /**
     * PVT-B scoring (Basner et al. 2011): responses < 100 ms and responses without a number are
     * false starts; valid responses ≥ 355 ms are lapses; a number left unanswered until the
     * no-response limit counts as a lapse.
     * @param int[] $times response time to each number shown, in ms (the limit = no response)
     */
    public function score(array $times, int $anticipations): array
    {
        $test = $this->config['test'];
        $valid = array_values(array_filter($times, fn ($t) => $t >= $test['false_start_ms'] && $t < $test['no_response_ms']));
        $tooFast = count(array_filter($times, fn ($t) => $t < $test['false_start_ms']));
        $unanswered = count(array_filter($times, fn ($t) => $t >= $test['no_response_ms']));
        sort($valid);

        $fastest = $valid ? array_slice($valid, 0, max(1, (int) ceil(count($valid) * 0.1))) : [];
        return [
            'stimuli' => count($times),
            'valid_responses' => count($valid),
            'lapses' => count(array_filter($valid, fn ($t) => $t >= $test['lapse_ms'])) + $unanswered,
            'false_starts' => $anticipations + $tooFast,
            'median_rt_ms' => $valid ? round($this->median($valid), 1) : null,
            'fastest10_rt_ms' => $fastest ? round(array_sum($fastest) / count($fastest), 1) : null,
        ];
    }

    // Camera summary from the browser: face time, number of blinks, time with eyes ≥ 80 % closed.
    private function validateCamera(mixed $camera, float $duration, array &$errors): ?array
    {
        if ($camera === null) {
            return null;
        }
        $face = $camera['face_seconds'] ?? null;
        $blinks = $camera['blinks'] ?? null;
        $closed = $camera['closed_seconds'] ?? null;
        if (!is_array($camera) || !is_numeric($face) || !is_numeric($blinks) || !is_numeric($closed)
            || $face < 0 || $face > $duration + 5 || $blinks < 0 || $blinks > 2000 || $closed < 0 || $closed > $face) {
            $errors['camera'] = t('err.alertness.camera');
            return null;
        }
        $face = (float) $face;
        // Too little time with the eyes visible: the camera was on, but no values are claimed.
        if ($face < $this->config['camera']['min_face_seconds']) {
            return ['face_seconds' => round($face, 1), 'blinks_per_minute' => null, 'perclos_pct' => null];
        }
        return [
            'face_seconds' => round($face, 1),
            'blinks_per_minute' => round((float) $blinks / ($face / 60), 1),
            'perclos_pct' => round((float) $closed / $face * 100, 2),
        ];
    }

    // ---------- Comparison with the astronaut's own earlier tests ----------

    private function baseline(array $earlier, ?string $latestAt): array
    {
        $rules = $this->config['baseline'];
        if ($latestAt !== null) {
            $from = gmdate('Y-m-d H:i:s', strtotime($latestAt . ' UTC') - $rules['days'] * 86400);
            $earlier = array_values(array_filter($earlier, fn ($t) => $t['taken_at_raw'] >= $from));
        }
        $values = fn (string $key) => array_values(array_filter(array_column($earlier, $key), fn ($v) => $v !== null));
        $median = fn (array $list) => $list ? round($this->median($list), 1) : null;

        return [
            'tests' => count($earlier),
            'needed' => $rules['min_tests'],
            'ready' => count($earlier) >= $rules['min_tests'],
            'median_rt_ms' => $median($values('median_rt_ms')),
            'lapses' => $median($values('lapses')),
            'false_starts' => $median($values('false_starts')),
            'blinks_per_minute' => $median($values('blinks_per_minute')),
            'perclos_pct' => $median($values('perclos_pct')),
        ];
    }

    private function status(array $latest, array $baseline): array
    {
        $rules = $this->config['baseline'];
        if (!$baseline['ready'] || $latest['median_rt_ms'] === null || $baseline['median_rt_ms'] === null) {
            return ['level' => 'building', 'tests' => $baseline['tests'], 'needed' => $rules['min_tests']];
        }
        $ratio = $latest['median_rt_ms'] / $baseline['median_rt_ms'];
        $extraLapses = $latest['lapses'] - $baseline['lapses'];
        $level = 'normal';
        if ($ratio >= $rules['much_slower_ratio'] || $extraLapses >= $rules['much_slower_extra_lapses']) {
            $level = 'much_slower';
        } elseif ($ratio >= $rules['slower_ratio'] || $extraLapses >= $rules['slower_extra_lapses']) {
            $level = 'slower';
        }
        return ['level' => $level, 'rt_change_pct' => (int) round(($ratio - 1) * 100), 'extra_lapses' => $extraLapses];
    }

    // The last 7 mission days (UTC), oldest first; the last test of each day, or null.
    private function history(array $tests): array
    {
        $byDay = [];
        foreach ($tests as $test) {   // newest first → keep the first seen per day
            $byDay[substr($test['taken_at_raw'], 0, 10)] ??= $test;
        }
        $series = [];
        for ($offset = 6; $offset >= 0; $offset--) {
            $date = gmdate('Y-m-d', strtotime("-{$offset} days"));
            $day = $byDay[$date] ?? null;
            $series[] = ['date' => $date, 'median_rt_ms' => $day['median_rt_ms'] ?? null, 'lapses' => $day['lapses'] ?? null];
        }
        return $series;
    }

    // ---------- Data ----------

    private function recentTests(int $userId, int $days): array
    {
        $statement = db()->prepare(
            'SELECT * FROM alertness_tests WHERE user_id = ? AND taken_at >= UTC_TIMESTAMP() - INTERVAL ? DAY ORDER BY taken_at DESC, id DESC LIMIT 200'
        );
        $statement->execute([$userId, $days]);
        return array_map(fn ($row) => [
            'id' => (int) $row['id'],
            'taken_at_raw' => $row['taken_at'],
            'stimuli' => (int) $row['stimuli'],
            'valid_responses' => (int) $row['valid_responses'],
            'lapses' => (int) $row['lapses'],
            'false_starts' => (int) $row['false_starts'],
            'median_rt_ms' => $row['median_rt_ms'] === null ? null : (float) $row['median_rt_ms'],
            'fastest10_rt_ms' => $row['fastest10_rt_ms'] === null ? null : (float) $row['fastest10_rt_ms'],
            'camera_used' => (bool) $row['camera_used'],
            'face_seconds' => $row['face_seconds'] === null ? null : (float) $row['face_seconds'],
            'blinks_per_minute' => $row['blinks_per_minute'] === null ? null : (float) $row['blinks_per_minute'],
            'perclos_pct' => $row['perclos_pct'] === null ? null : (float) $row['perclos_pct'],
        ], $statement->fetchAll());
    }

    private function countTests(int $userId): int
    {
        $statement = db()->prepare('SELECT COUNT(*) FROM alertness_tests WHERE user_id = ?');
        $statement->execute([$userId]);
        return (int) $statement->fetchColumn();
    }

    private function publicTest(array $test): array
    {
        $test['taken_at'] = iso_time($test['taken_at_raw']);
        unset($test['taken_at_raw']);
        return $test;
    }

    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);
        return $count % 2 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
