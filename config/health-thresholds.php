<?php
// Monitoring thresholds used by the deterministic alert engine.
//
// These are general adult resting reference ranges chosen for this prototype's
// monitoring demo. They are NOT clinically validated for spaceflight and must be
// reviewed by a medical professional before any real use. See docs/DATA_PROVENANCE.md.
//
// For each metric:
//   green  — the range considered normal (inclusive)
//   yellow — attention range (inclusive); anything outside green AND yellow is RED
//   sustain_seconds — how many consecutive 1-second samples must be out of range
//                     before an alert is created (and back in range before it resolves)
// Labels shown to users, alert texts and recommended actions are in backend/lang/{en,ar}/alerts.php.
declare(strict_types=1);

return [
    'version' => '2026-11-08.1',

    'metrics' => [
        'heart_rate' => [
            'label'  => 'Heart rate',
            'unit'   => 'bpm',
            'green'  => [50, 100],
            'yellow' => [40, 120],
            'sustain_seconds' => 10,
        ],
        'pulse_rate' => [
            'label'  => 'Pulse rate',
            'unit'   => 'bpm',
            'green'  => [50, 100],
            'yellow' => [40, 120],
            'sustain_seconds' => 10,
        ],
        'spo2' => [
            'label'  => 'Blood oxygen (SpO₂)',
            'unit'   => '%',
            'green'  => [95, 100],
            'yellow' => [90, 100],
            'sustain_seconds' => 10,
        ],
        'respiratory_rate' => [
            'label'  => 'Respiratory rate',
            'unit'   => 'breaths/min',
            'green'  => [12, 20],
            'yellow' => [8, 24],
            'sustain_seconds' => 10,
        ],
    ],
];
