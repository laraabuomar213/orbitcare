<?php
// Loads BIDMC numerics CSV files into raw_measurements.
//
// Only validated values are stored, exactly as recorded, with their record ID, file,
// row number, time offset and unit. Missing and rejected values are counted and
// reported (data/processed/<record>_ingestion.json) but never stored or invented.
// Re-running is safe: rows already present are skipped (unique key).
declare(strict_types=1);

final class DataIngestion
{
    private string $reportDir;

    public function __construct(private DataValidator $validator = new DataValidator(), ?string $reportDir = null)
    {
        $this->reportDir = $reportDir ?? dirname(__DIR__, 2) . '/data/processed';
    }

    public function ingestRecord(string $numericsPath, ?string $metadataPath = null): array
    {
        $recordId = $this->recordIdFromPath($numericsPath);
        $file = basename($numericsPath);

        $handle = @fopen($numericsPath, 'r');
        if ($handle === false) {
            throw new InvalidArgumentException("Dataset file not found or unreadable: {$file}");
        }

        try {
            $columns = $this->validator->validateHeader(fgetcsv($handle) ?: []);
            $metadata = $metadataPath ? $this->readMetadata($metadataPath) : [];

            $report = [
                'record_id' => $recordId, 'source' => DataValidator::SOURCE, 'file' => $file,
                'rows_read' => 0, 'values_stored' => 0, 'values_already_present' => 0,
                'values_missing' => 0, 'values_rejected' => 0, 'rejections' => [], 'duration_seconds' => 0,
            ];
            $samples = [];
            $rowNumber = 1;   // header is row 1

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                if ($row === [null]) {
                    continue;   // blank line
                }
                $report['rows_read']++;

                $offset = $this->validator->parseTime($row[0] ?? '');
                if ($offset === null) {
                    $report['values_rejected'] += count($columns);
                    $report['rejections'][] = ['row' => $rowNumber, 'reason' => 'Invalid time value'];
                    continue;
                }
                $report['duration_seconds'] = max($report['duration_seconds'], (int) floor($offset));

                foreach ($columns as $index => $column) {
                    $check = $this->validator->validateValue($column, $row[$index] ?? '');
                    if ($check['status'] === 'missing') {
                        $report['values_missing']++;
                    } elseif ($check['status'] === 'invalid') {
                        $report['values_rejected']++;
                        $report['rejections'][] = ['row' => $rowNumber, 'column' => $column, 'reason' => $check['reason']];
                    } else {
                        $rule = DataValidator::COLUMNS[$column];
                        $samples[] = [$recordId, $file, $rowNumber, $offset, $rule['metric'], $check['value'], $rule['unit']];
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        if ($report['rows_read'] === 0) {
            throw new InvalidArgumentException("Invalid dataset: {$file} has no data rows.");
        }

        $stored = db_transaction(function (PDO $pdo) use ($report, $metadata, $samples): int {
            $pdo->prepare(
                'INSERT INTO source_records
                    (record_id, source, source_file, duration_seconds, subject_age, subject_gender, care_unit,
                     rows_read, values_stored, values_missing, values_rejected)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
                 ON DUPLICATE KEY UPDATE duration_seconds = VALUES(duration_seconds), rows_read = VALUES(rows_read),
                    values_missing = VALUES(values_missing), values_rejected = VALUES(values_rejected)'
            )->execute([
                $report['record_id'], DataValidator::SOURCE, $report['file'], $report['duration_seconds'],
                $metadata['age'] ?? null, $metadata['gender'] ?? null, $metadata['location'] ?? null,
                $report['rows_read'], $report['values_missing'], $report['values_rejected'],
            ]);

            $insert = $pdo->prepare(
                'INSERT IGNORE INTO raw_measurements
                    (source, source_record_id, source_file, source_row, original_timestamp, metric, value, unit)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stored = 0;
            foreach ($samples as [$recordId, $file, $row, $offset, $metric, $value, $unit]) {
                $insert->execute([DataValidator::SOURCE, $recordId, $file, $row, $offset, $metric, $value, $unit]);
                $stored += $insert->rowCount();
            }

            $total = $pdo->prepare('SELECT COUNT(*) FROM raw_measurements WHERE source_record_id = ?');
            $total->execute([$report['record_id']]);
            $pdo->prepare('UPDATE source_records SET values_stored = ? WHERE record_id = ?')
                ->execute([(int) $total->fetchColumn(), $report['record_id']]);

            return $stored;
        });

        $report['values_stored'] = $stored;
        $report['values_already_present'] = count($samples) - $stored;
        $report['rejections'] = array_slice($report['rejections'], 0, 50);
        $report['ingested_at'] = gmdate('Y-m-d\TH:i:s\Z');

        $this->writeReport($report);
        log_event('data.ingestion', [
            'record_id' => $recordId, 'status' => 'success', 'stored' => $report['values_stored'],
            'missing' => $report['values_missing'], 'rejected' => $report['values_rejected'],
        ]);
        return $report;
    }

    // bidmc_01_Numerics.csv → bidmc_01
    private function recordIdFromPath(string $path): string
    {
        if (!preg_match('/^(bidmc_\d{2})_Numerics\.csv$/', basename($path), $match)) {
            throw new InvalidArgumentException('Invalid dataset file name: expected bidmc_NN_Numerics.csv.');
        }
        return $match[1];
    }

    // Reads Age / Gender / Location from the record's _Fix.txt file (as published).
    private function readMetadata(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $metadata = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^(Age|Gender|Location):\s*(.+)$/', trim($line), $match)) {
                $metadata[strtolower($match[1])] = substr(trim($match[2]), 0, 10);
            }
        }
        return $metadata;
    }

    private function writeReport(array $report): void
    {
        if (!is_dir($this->reportDir)) {
            @mkdir($this->reportDir, 0775, true);
        }
        @file_put_contents("{$this->reportDir}/{$report['record_id']}_ingestion.json", json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
