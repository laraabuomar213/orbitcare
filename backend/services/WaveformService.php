<?php
// Real 125 Hz waveforms from the BIDMC recordings (ECG lead II, PPG, respiration), read in short
// windows that follow the same replay position as the 1 Hz numerics. Built by cli/build-waveforms.php.
//
// File format (data/processed/waveforms/<record>.bin): little-endian int16 frames [ecg, ppg, resp],
// one frame per sample at 125 Hz, value = round(published value × 10000), -32768 = missing.
declare(strict_types=1);

final class WaveformService
{
    public const FS = 125;
    public const CHANNELS = ['ecg' => 'II', 'ppg' => 'PLETH', 'resp' => 'RESP'];
    private const SCALE = 10000;
    private const MISSING = -32768;
    private const FRAME_BYTES = 6;

    public static function path(string $recordId): ?string
    {
        if (!preg_match('/^bidmc_\d{2}$/', $recordId)) {
            return null;
        }
        $file = dirname(__DIR__, 2) . "/data/processed/waveforms/{$recordId}.bin";
        return is_file($file) ? $file : null;
    }

    // Samples in [end − seconds, end) of the recording, as published (null where the source has no value).
    public function window(string $recordId, float $endSeconds, float $seconds): ?array
    {
        $file = self::path($recordId);
        if ($file === null) {
            return null;
        }
        $total = intdiv((int) filesize($file), self::FRAME_BYTES);
        $end = max(0, min($total, (int) floor($endSeconds * self::FS)));
        $start = max(0, $end - (int) round($seconds * self::FS));
        $count = $end - $start;

        $channels = array_fill_keys(array_keys(self::CHANNELS), []);
        if ($count > 0) {
            $handle = fopen($file, 'rb');
            fseek($handle, $start * self::FRAME_BYTES);
            $bytes = fread($handle, $count * self::FRAME_BYTES);
            fclose($handle);
            $values = array_values(unpack('v*', $bytes));
            $names = array_keys(self::CHANNELS);
            foreach ($values as $i => $unsigned) {
                $signed = $unsigned >= 32768 ? $unsigned - 65536 : $unsigned;
                $channels[$names[$i % 3]][] = $signed === self::MISSING ? null : round($signed / self::SCALE, 4);
            }
        }

        return [
            'record_id' => $recordId,
            'fs' => self::FS,
            'start_seconds' => round($start / self::FS, 3),
            'end_seconds' => round($end / self::FS, 3),
            'duration_seconds' => round($total / self::FS, 3),
            'source_columns' => self::CHANNELS,
            'channels' => $channels,
        ];
    }

    // Reads a published bidmc_NN_Signals.csv and writes the packed .bin. Columns are found by name.
    public static function pack(string $csvFile, string $binFile): array
    {
        $in = fopen($csvFile, 'rb') ?: throw new RuntimeException('cannot open CSV');
        $header = array_map('trim', fgetcsv($in) ?: []);
        if (($header[0] ?? '') !== 'Time [s]') {
            fclose($in);
            throw new RuntimeException('unexpected header');
        }
        $columns = [];
        foreach (self::CHANNELS as $key => $name) {
            $index = array_search($name, $header, true);
            $columns[$key] = $index === false ? null : $index;
        }

        $out = fopen($binFile . '.tmp', 'wb');
        $samples = 0;
        $missing = array_fill_keys(array_keys(self::CHANNELS), 0);
        while (($row = fgetcsv($in)) !== false) {
            if ($row === [null]) {
                continue;
            }
            $frame = '';
            foreach ($columns as $key => $index) {
                $raw = $index === null ? '' : trim((string) ($row[$index] ?? ''));
                if (is_numeric($raw)) {
                    $value = (int) round((float) $raw * self::SCALE);
                    $value = max(-32767, min(32767, $value));
                } else {
                    $value = self::MISSING;
                    $missing[$key]++;
                }
                $frame .= pack('v', $value & 0xFFFF);
            }
            fwrite($out, $frame);
            $samples++;
        }
        fclose($in);
        fclose($out);
        rename($binFile . '.tmp', $binFile);

        return ['samples' => $samples, 'missing' => $missing];
    }
}
