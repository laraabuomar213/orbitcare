<?php
// Validates and loads every data/raw/bidmc_NN_Numerics.csv into raw_measurements.
//   php cli/ingest-bidmc.php
// Safe to run again: values already stored are skipped. A report per record is written
// to data/processed/<record>_ingestion.json.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../backend/bootstrap.php';

$files = glob(dirname(__DIR__) . '/data/raw/bidmc_*_Numerics.csv') ?: [];
if (!$files) {
    fwrite(STDERR, "No dataset files in data/raw/. Run: php cli/download-bidmc.php\n");
    exit(1);
}

$ingestion = new DataIngestion();
$failed = 0;
printf("%-10s %6s %8s %8s %8s\n", 'record', 'rows', 'stored', 'missing', 'rejected');

foreach ($files as $file) {
    try {
        $report = $ingestion->ingestRecord($file, str_replace('_Numerics.csv', '_Fix.txt', $file));
        printf("%-10s %6d %8d %8d %8d\n", $report['record_id'], $report['rows_read'], $report['values_stored'], $report['values_missing'], $report['values_rejected']);
    } catch (Throwable $error) {
        $failed++;
        fwrite(STDERR, basename($file) . ': ' . $error->getMessage() . "\n");
        log_event('data.ingestion', ['file' => basename($file), 'status' => 'error', 'error' => $error->getMessage()], 'error');
    }
}

echo $failed ? "Finished with {$failed} failed file(s).\n" : "All files loaded.\n";
exit($failed ? 1 : 0);
