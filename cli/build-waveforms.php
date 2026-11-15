<?php
// Downloads the BIDMC 125 Hz waveform files (bidmc_NN_Signals.csv) from PhysioNet into data/raw/
// and packs three of their channels into data/processed/waveforms/bidmc_NN.bin for the live views:
//   II    → ECG lead II        (shown on the vest's chest electrodes)
//   PLETH → PPG pulse wave     (shown on the headband's pulse sensor)
//   RESP  → respiration signal (shown on the vest's breathing band)
//
//   php cli/build-waveforms.php            (the records in config replay.record_pool)
//   php cli/build-waveforms.php 3 21 32    (only these records)
//
// Values are kept as published (normalised units), stored as int16 = round(value × 10000).
// A missing or non-numeric value is stored as -32768 and returned as null — never filled in.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../backend/bootstrap.php';

const SIGNALS_BASE_URL = 'https://physionet.org/files/bidmc/1.0.0/bidmc_csv/';

$records = array_slice($argv, 1)
    ? array_map(fn ($n) => sprintf('bidmc_%02d', (int) $n), array_slice($argv, 1))
    : config()['replay']['record_pool'];

$rawDir = dirname(__DIR__) . '/data/raw';
$outDir = dirname(__DIR__) . '/data/processed/waveforms';
if (!is_dir($outDir)) {
    mkdir($outDir, 0775, true);
}

$failures = 0;
foreach ($records as $id) {
    if (!preg_match('/^bidmc_\d{2}$/', $id)) {
        fwrite(STDERR, "SKIPPED {$id} (not a BIDMC record id)\n");
        $failures++;
        continue;
    }
    $csv = "{$rawDir}/{$id}_Signals.csv";
    if (!is_file($csv) || filesize($csv) === 0) {
        $context = stream_context_create(['http' => ['timeout' => 60, 'user_agent' => 'OrbitCare-data-loader']]);
        $content = @file_get_contents(SIGNALS_BASE_URL . "{$id}_Signals.csv", false, $context);
        if ($content === false || $content === '') {
            fwrite(STDERR, "FAILED  download {$id}_Signals.csv\n");
            $failures++;
            continue;
        }
        file_put_contents($csv, $content);
        echo "saved   {$id}_Signals.csv\n";
    }

    try {
        $summary = WaveformService::pack($csv, "{$outDir}/{$id}.bin");
        echo "packed  {$id}.bin  ({$summary['samples']} samples, missing: ecg {$summary['missing']['ecg']}, ppg {$summary['missing']['ppg']}, resp {$summary['missing']['resp']})\n";
    } catch (RuntimeException $e) {
        fwrite(STDERR, "FAILED  pack {$id}: {$e->getMessage()}\n");
        $failures++;
    }
}

log_event('data.waveforms', ['records' => count($records), 'failures' => $failures, 'status' => $failures ? 'partial' : 'success']);
echo $failures ? "Done with {$failures} failure(s).\n" : "Done.\n";
exit($failures ? 1 : 0);
