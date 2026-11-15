<?php
// Downloads open recorded data for the vest / headband sensors that BIDMC does not cover, and stores it
// in sensor_sources + sensor_samples (immutable). Downloaded files are kept unchanged under data/raw/.
//
//   php cli/ingest-open-sensors.php
//
// 1) NASA OSDR RadLab — ISS absorbed dose rate from the DosTel1 detector (Columbus module), one full UTC day.
//    → personal dosimeter. Values as published (µGy/h).
// 2) PhysioNet "A Non-EEG Dataset for Assessment of Neurological Status" v1.0.0 — 20 healthy volunteers,
//    wrist sensors at 8 Hz → skin temperature (°C, 1-s mean), skin conductance / EDA (as published, 1-s mean)
//    and movement (1-s standard deviation of the acceleration magnitude, derived).
// Missing or implausible values are never filled in; they are counted as rejected.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../backend/bootstrap.php';

const RADLAB_DAY = '2022-04-01';
const RADLAB_INSTRUMENT = 'DosTel1';
const NONEEG_URL = 'https://physionet.org/files/noneeg/1.0.0/';
const NONEEG_SUBJECTS = 20;
const WFDB_INVALID = -32768;

$raw = dirname(__DIR__) . '/data/raw';
foreach (["{$raw}/radlab", "{$raw}/noneeg"] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

function download(string $url, string $path): bool
{
    if (is_file($path) && filesize($path) > 0) {
        return true;
    }
    $context = stream_context_create(['http' => ['timeout' => 90, 'user_agent' => 'OrbitCare-data-loader']]);
    $content = @file_get_contents($url, false, $context);
    if ($content === false || $content === '') {
        fwrite(STDERR, "FAILED  download {$url}\n");
        return false;
    }
    file_put_contents($path, $content);
    echo 'saved   ' . basename($path) . "\n";
    return true;
}

function store_source(array $source, array $samples): void
{
    db_transaction(function (PDO $pdo) use ($source, $samples) {
        $pdo->prepare(
            'INSERT IGNORE INTO sensor_sources (source_id, dataset, dataset_url, licence, description, recording_start, duration_seconds, source_file, values_stored, values_rejected)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([$source['id'], $source['dataset'], $source['url'], $source['licence'], $source['description'],
            $source['start'], $source['duration'], $source['file'], count($samples), $source['rejected']]);
        $insert = $pdo->prepare('INSERT IGNORE INTO sensor_samples (source_id, sensor, offset_seconds, value, unit, method) VALUES (?,?,?,?,?,?)');
        $stored = 0;
        foreach ($samples as [$sensor, $offset, $value, $unit, $method]) {
            $insert->execute([$source['id'], $sensor, $offset, $value, $unit, $method]);
            $stored += $insert->rowCount();
        }
        // a repeated timestamp in the source is stored once; the count reflects what is actually in the table
        $total = $pdo->prepare('SELECT COUNT(*) FROM sensor_samples WHERE source_id = ?');
        $total->execute([$source['id']]);
        $pdo->prepare('UPDATE sensor_sources SET values_stored = ? WHERE source_id = ?')->execute([(int) $total->fetchColumn(), $source['id']]);
    });
}

$failures = 0;

// ---------- 1) NASA OSDR RadLab: ISS dose rate ----------
$day = RADLAB_DAY;
$file = "{$raw}/radlab/ISS_" . RADLAB_INSTRUMENT . "_{$day}.csv";
$next = (new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
$url = 'https://visualization.osdr.nasa.gov/radlab/api/?spacecraft=ISS&instrument=' . RADLAB_INSTRUMENT
    . "&timestamp%3E={$day}T00%3A00&timestamp%3C{$next}T00%3A00&absorbed_dose_rate&module&format=csv";
if (download($url, $file)) {
    $in = fopen($file, 'rb');
    $header = fgetcsv($in);
    $col = array_flip($header ?: []);
    if (!isset($col['timestamp'], $col['absorbed_dose_rate'])) {
        fwrite(STDERR, "FAILED  RadLab: unexpected header\n");
        $failures++;
    } else {
        $start = new DateTimeImmutable("{$day}T00:00:00", new DateTimeZone('UTC'));
        $samples = [];
        $rejected = 0;
        $module = '';
        while (($row = fgetcsv($in)) !== false) {
            $rate = $row[$col['absorbed_dose_rate']] ?? '';
            $ts = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', (string) ($row[$col['timestamp']] ?? ''), new DateTimeZone('UTC'));
            if (!$ts || !is_numeric($rate) || (float) $rate < 0 || (float) $rate > 1000) {
                $rejected++;
                continue;
            }
            $module = $row[$col['module']] ?? $module;
            $samples[] = ['radiation_dose_rate', $ts->getTimestamp() - $start->getTimestamp(), round((float) $rate, 4), 'µGy/h', 'as recorded'];
        }
        $id = 'radlab:ISS:' . RADLAB_INSTRUMENT . ":{$day}";
        store_source([
            'id' => $id, 'dataset' => 'NASA OSDR RadLab — ISS radiation telemetry', 'url' => 'https://visualization.osdr.nasa.gov/radlab/',
            'licence' => 'NASA open data (OSDR terms and conditions)',
            'description' => 'ISS ' . ($module ?: 'Columbus') . ' module, ' . RADLAB_INSTRUMENT . " absorbed dose rate, {$day} (UTC)",
            'start' => $start->format('Y-m-d H:i:s'), 'duration' => 86400, 'file' => basename($file), 'rejected' => $rejected,
        ], $samples);
        echo "stored  {$id}: " . count($samples) . " samples, {$rejected} rejected\n";
    }
    fclose($in);
} else {
    $failures++;
}

// ---------- 2) PhysioNet noneeg: skin temperature, EDA, movement ----------
for ($n = 1; $n <= NONEEG_SUBJECTS; $n++) {
    $name = "Subject{$n}_AccTempEDA";
    $hea = "{$raw}/noneeg/{$name}.hea";
    $dat = "{$raw}/noneeg/{$name}.dat";
    if (!download(NONEEG_URL . "{$name}.hea", $hea) || !download(NONEEG_URL . "{$name}.dat", $dat)) {
        $failures++;
        continue;
    }

    // Header: "<record> <signals> <fs> <samples>", then one line per signal: "<file> 16 <gain>(<baseline>)/<unit> … <name>"
    $lines = array_values(array_filter(array_map('trim', file($hea)), fn ($l) => $l !== '' && $l[0] !== '#'));
    [, $nsig, $fs, $nsamp] = preg_split('/\s+/', $lines[0]);
    [$nsig, $fs, $nsamp] = [(int) $nsig, (int) $fs, (int) $nsamp];
    $signals = [];
    for ($i = 1; $i <= $nsig; $i++) {
        $f = preg_split('/\s+/', $lines[$i]);
        if (($f[1] ?? '') !== '16' || !preg_match('/^([\d.]+)\((-?\d+)\)\/(.*)$/', $f[2], $m)) {
            fwrite(STDERR, "FAILED  {$name}: unsupported signal line\n");
            $failures++;
            continue 2;
        }
        $signals[end($f)] = ['index' => $i - 1, 'gain' => (float) $m[1], 'baseline' => (int) $m[2], 'unit' => $m[3]];
    }
    if (!isset($signals['ax'], $signals['ay'], $signals['az'], $signals['temp'], $signals['EDA']) || $fs < 1) {
        fwrite(STDERR, "FAILED  {$name}: expected ax, ay, az, temp, EDA\n");
        $failures++;
        continue;
    }

    $values = unpack('s*', (string) file_get_contents($dat));   // little-endian int16, frames of $nsig samples
    $frames = intdiv(count($values), $nsig);
    $phys = function (int $frame, string $sig) use ($values, $nsig, $signals): ?float {
        $s = $signals[$sig];
        $d = $values[$frame * $nsig + $s['index'] + 1];
        return $d === WFDB_INVALID ? null : ($d - $s['baseline']) / $s['gain'];
    };

    $samples = [];
    $rejected = 0;
    $seconds = intdiv(min($frames, $nsamp), $fs);
    for ($sec = 0; $sec < $seconds; $sec++) {
        $temps = $edas = $mags = [];
        for ($k = 0; $k < $fs; $k++) {
            $fr = $sec * $fs + $k;
            $t = $phys($fr, 'temp');
            $e = $phys($fr, 'EDA');
            [$x, $y, $z] = [$phys($fr, 'ax'), $phys($fr, 'ay'), $phys($fr, 'az')];
            if ($t !== null && $t >= 20 && $t <= 45) { $temps[] = $t; }
            if ($e !== null && $e >= 0) { $edas[] = $e; }
            if ($x !== null && $y !== null && $z !== null) { $mags[] = sqrt($x * $x + $y * $y + $z * $z); }
        }
        // a second is stored only if every one of its samples was valid
        if (count($temps) === $fs) {
            $samples[] = ['skin_temperature', $sec, round(array_sum($temps) / $fs, 3), '°C', "1-s mean of {$fs} Hz"];
        } else { $rejected++; }
        if (count($edas) === $fs) {
            $samples[] = ['skin_conductance', $sec, round(array_sum($edas) / $fs, 4), $signals['EDA']['unit'], "1-s mean of {$fs} Hz"];
        } else { $rejected++; }
        if (count($mags) === $fs) {
            $mean = array_sum($mags) / $fs;
            $sd = sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $mags)) / $fs);
            $samples[] = ['movement', $sec, round($sd, 4), $signals['ax']['unit'], '1-s SD of |acceleration|'];
        } else { $rejected++; }
    }

    $age = preg_match('/# age: (\d+)/', (string) file_get_contents($hea), $am) ? $am[1] : '?';
    store_source([
        'id' => "noneeg:Subject{$n}", 'dataset' => 'PhysioNet — A Non-EEG Dataset for Assessment of Neurological Status v1.0.0',
        'url' => 'https://physionet.org/content/noneeg/1.0.0/', 'licence' => 'Open Data Commons Attribution License v1.0',
        'description' => "Healthy volunteer {$n} (age {$age}), wrist-worn sensors, {$fs} Hz",
        'start' => null, 'duration' => $seconds, 'file' => "{$name}.dat", 'rejected' => $rejected,
    ], $samples);
    echo "stored  noneeg:Subject{$n}: " . count($samples) . " samples ({$seconds} s), {$rejected} rejected\n";
}

log_event('data.open_sensors', ['failures' => $failures, 'status' => $failures ? 'partial' : 'success']);
echo $failures ? "Done with {$failures} failure(s).\n" : "Done.\n";
exit($failures ? 1 : 0);
