<?php
// Downloads the BIDMC numerics + metadata files from PhysioNet into data/raw/.
//   php cli/download-bidmc.php            (all 53 records)
//   php cli/download-bidmc.php 3 22 21    (only these records)
// Files are saved unchanged; existing files are skipped.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../backend/bootstrap.php';

const BIDMC_BASE_URL = 'https://physionet.org/files/bidmc/1.0.0/bidmc_csv/';

$records = array_slice($argv, 1) ?: range(1, 53);
$target = dirname(__DIR__) . '/data/raw';
if (!is_dir($target)) {
    mkdir($target, 0775, true);
}

$failures = 0;
foreach ($records as $number) {
    $id = sprintf('bidmc_%02d', (int) $number);
    foreach (["{$id}_Numerics.csv", "{$id}_Fix.txt"] as $file) {
        $path = "{$target}/{$file}";
        if (is_file($path) && filesize($path) > 0) {
            continue;
        }
        $context = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'OrbitCare-data-loader']]);
        $content = @file_get_contents(BIDMC_BASE_URL . $file, false, $context);
        if ($content === false || $content === '') {
            fwrite(STDERR, "FAILED  {$file}\n");
            log_event('data.download', ['file' => $file, 'status' => 'error'], 'error');
            $failures++;
            continue;
        }
        file_put_contents($path, $content);
        echo "saved   {$file}\n";
    }
}

log_event('data.download', ['records' => count($records), 'failures' => $failures, 'status' => $failures ? 'partial' : 'success']);
echo $failures ? "Done with {$failures} failure(s).\n" : "Done.\n";
exit($failures ? 1 : 0);
