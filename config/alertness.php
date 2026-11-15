<?php
// Alertness Check: a 3-minute psychomotor vigilance test (PVT-B) plus optional eye signs from the
// camera. Test parameters follow the published PVT-B (source 'pvt_b'); the "compared with your
// own baseline" rules are OrbitCare's configurable choices, not clinical or NASA standards.
declare(strict_types=1);

return [
    'version' => '2026-11-12.1',

    'sources' => [
        // Basner, Mollicone & Dinges (2011), Acta Astronautica 69:949–959. Verified in the paper:
        // 3-min test; inter-stimulus interval 1–4 s including a 1 s RT feedback; lapse = RT ≥ 355 ms;
        // a response without a stimulus or RT < 100 ms is a false start.
        'pvt_b' => 'https://pmc.ncbi.nlm.nih.gov/articles/PMC3197786/',
        // NASA's on-board Reaction Self Test (RST) is the 3-minute PVT-B, used on the ISS since 2009.
        'nasa_rst' => 'https://spinoff.nasa.gov/Spinoff2013/hm_1.html',
        // PERCLOS: proportion of time the eyes are at least 80 % closed; reflects slow eyelid
        // closures rather than blinks (FHWA/FMCSA tech brief, Dinges & Grace 1998).
        'perclos' => 'https://rosap.ntl.bts.gov/view/dot/113',
    ],

    // PVT-B (source 'pvt_b').
    'test' => [
        'duration_seconds' => 180,
        'isi_min_ms' => 1000,             // includes the 1 s feedback after each response
        'isi_max_ms' => 4000,
        'feedback_ms' => 1000,
        'lapse_ms' => 355,
        'false_start_ms' => 100,
        // OrbitCare rule: a stimulus without any response for 10 s ends that trial (counted as a lapse).
        'no_response_ms' => 10000,
    ],

    // Camera eye signs (estimates). Eye closure = 1 − (eye opening ÷ the astronaut's own open-eye
    // opening during the same test). PERCLOS counts time with closure ≥ 80 % (P80).
    'camera' => [
        'perclos_closure' => 0.8,
        'blink_closure' => 0.5,           // a blink: closure ≥ 50 % that lasts at most 500 ms
        'blink_max_ms' => 500,
        'min_face_seconds' => 60,         // less face time than this → no camera values are saved
    ],

    // OrbitCare rules: today's test compared with the astronaut's own earlier tests.
    'baseline' => [
        'days' => 14,                     // earlier tests from the last 14 days form the baseline
        'min_tests' => 3,                 // fewer → "building your baseline"
        'slower_ratio' => 1.10,           // median RT ≥ 110 % of usual, or …
        'slower_extra_lapses' => 2,       // … 2+ more lapses than usual → "slower than usual"
        'much_slower_ratio' => 1.25,
        'much_slower_extra_lapses' => 5,
    ],
];
