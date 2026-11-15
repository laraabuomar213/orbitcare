<?php
// Validates BIDMC numerics rows before they may enter the database.
//
// Rules:
//  - Units are fixed by the PhysioNet documentation and never converted.
//  - "NaN"/empty cells are MISSING: they are counted and skipped, never filled in.
//  - Values outside physically possible limits (e.g. SpO2 > 100 %) are REJECTED.
//    These limits are sanity checks on the data, not clinical thresholds.
declare(strict_types=1);

final class DataValidator
{
    public const SOURCE = 'PhysioNet BIDMC PPG and Respiration Dataset v1.0.0';

    // Numerics CSV column → stored metric.
    public const COLUMNS = [
        'HR'    => ['metric' => 'heart_rate',       'unit' => 'bpm',         'min' => 0, 'max' => 300],
        'PULSE' => ['metric' => 'pulse_rate',       'unit' => 'bpm',         'min' => 0, 'max' => 300],
        'RESP'  => ['metric' => 'respiratory_rate', 'unit' => 'breaths/min', 'min' => 0, 'max' => 120],
        'SpO2'  => ['metric' => 'spo2',             'unit' => '%',           'min' => 0, 'max' => 100],
    ];

    // Maps column positions to known columns. Throws if the file is not a BIDMC numerics file.
    public function validateHeader(array $header): array
    {
        $header = array_map(fn ($name) => trim((string) $name), $header);

        if (($header[0] ?? '') !== 'Time [s]') {
            throw new InvalidArgumentException('Invalid dataset: first column must be "Time [s]".');
        }

        $columns = [];
        foreach ($header as $index => $name) {
            if (isset(self::COLUMNS[$name])) {
                $columns[$index] = $name;
            }
        }
        if (!$columns) {
            throw new InvalidArgumentException('Invalid dataset: no known measurement columns (HR, PULSE, RESP, SpO2).');
        }
        return $columns;
    }

    // Returns the time offset in seconds, or null if the cell is not a valid non-negative number.
    public function parseTime(mixed $cell): ?float
    {
        $cell = trim((string) $cell);
        if (!is_numeric($cell) || (float) $cell < 0) {
            return null;
        }
        return (float) $cell;
    }

    // Returns ['status' => 'valid'|'missing'|'invalid', 'value' => ?float, 'reason' => ?string].
    public function validateValue(string $column, mixed $cell): array
    {
        $rule = self::COLUMNS[$column] ?? null;
        if ($rule === null) {
            return ['status' => 'invalid', 'value' => null, 'reason' => "Unknown column {$column}"];
        }

        $cell = trim((string) $cell);
        if ($cell === '' || strcasecmp($cell, 'nan') === 0) {
            return ['status' => 'missing', 'value' => null, 'reason' => null];
        }
        if (!is_numeric($cell)) {
            return ['status' => 'invalid', 'value' => null, 'reason' => "Not a number: {$cell}"];
        }

        $value = (float) $cell;
        if ($value < $rule['min'] || $value > $rule['max']) {
            return ['status' => 'invalid', 'value' => null, 'reason' => "Outside possible range {$rule['min']}–{$rule['max']} {$rule['unit']}"];
        }
        return ['status' => 'valid', 'value' => $value, 'reason' => null];
    }
}
