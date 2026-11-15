<?php
// Daily check-in: scales, symptoms, targets and the rules behind "What we noticed".
// Each item says where it comes from. Thresholds marked "OrbitCare rule" are this
// prototype's configurable choices, not clinical or NASA standards.
declare(strict_types=1);

return [
    'version' => '2026-11-11.1',

    'sources' => [
        'nasa_hazards' => ['label' => 'NASA Human Research Program – five hazards of human spaceflight', 'url' => 'https://www.nasa.gov/hrp/hazards/'],
        'nasa_exercise' => ['label' => 'NASA – Risk of reduced cardiorespiratory and musculoskeletal fitness (resistance and aerobic exercise 1.5 h/day, 6–7 days/week, protects against muscle and bone loss)', 'url' => 'https://www.nasa.gov/reference/risk-of-reduced-cardiorespiratory-and-musculoskeletal-fitness/'],
        'nasa_bone' => ['label' => 'NASA – Gravity fields hazard (weight-bearing bones lose on average 1%–1.5% of mineral density per month)', 'url' => 'https://www.nasa.gov/hrp/hazard-gravity-fields/'],
        'nasa_immune' => ['label' => 'NASA – Risk of altered immune system responses (crew have reported cold sores, rashes, atypical allergies and infections)', 'url' => 'https://www.nasa.gov/reference/risk-of-altered-immune-system-responses/'],
        'nasa_sans' => ['label' => 'NASA – Spaceflight Associated Neuro-ocular Syndrome (eye and brain changes from fluid shifts)', 'url' => 'https://www.nasa.gov/reference/risk-of-spaceflight-associated-neuro-ocular-syndrome-sans/'],
        'nasa_sleep' => ['label' => 'NASA – Risk from inadequate sleep and irregular schedules (chronic sleep loss is a spaceflight stressor)', 'url' => 'https://www.nasa.gov/reference/risk-from-inadequate-sleep-and-irregular-schedules/'],
        'nasa_radiation' => ['label' => 'NASA – Space radiation hazard (instruments aboard the ISS measure exposure)', 'url' => 'https://www.nasa.gov/hrp/hazard-space-radiation/'],
        'samn_perelli' => ['label' => 'Samn-Perelli 7-point fatigue scale (Samn & Perelli, 1982); labels as reproduced in Rose et al., 2023', 'url' => 'https://europepmc.org/article/PMC/PMC10623805'],
        'phq4' => ['label' => 'PHQ-4 (Kroenke et al., 2009; Löwe et al., 2010); items, options, bands and cut-offs as reported in Dimitrov et al., 2026 (open access)', 'url' => 'https://europepmc.org/article/PMC/PMC13525647'],
    ],

    'sleep_quality' => [1 => 'Very poor', 2 => 'Poor', 3 => 'Fair', 4 => 'Good', 5 => 'Excellent'],

    // Samn-Perelli fatigue scale (labels verified from an open-access source, see 'samn_perelli').
    'fatigue' => [
        1 => 'Fully alert, wide awake',
        2 => 'Very lively, responsive, but not at peak',
        3 => 'Okay, somewhat fresh',
        4 => 'A little tired, less than fresh',
        5 => 'Moderately tired, let down',
        6 => 'Extremely tired, very difficult to concentrate',
        7 => 'Completely exhausted, unable to function effectively',
    ],

    // NASA: 1.5 h/day of resistance + aerobic exercise on the ISS (source 'nasa_exercise').
    'exercise_target_minutes' => 90,

    'symptoms' => [
        'headache' => ['label' => 'Headache', 'area' => 'general'],
        'congestion' => ['label' => 'Congestion', 'area' => 'general'],
        'dizziness' => ['label' => 'Dizziness', 'area' => 'gravity', 'source' => 'nasa_bone'],
        'skin_rash' => ['label' => 'Skin rash', 'area' => 'immune', 'source' => 'nasa_immune'],
        'cold_sores' => ['label' => 'Cold sores', 'area' => 'immune', 'source' => 'nasa_immune'],
        'allergy' => ['label' => 'Allergy symptoms', 'area' => 'immune', 'source' => 'nasa_immune'],
        'infection' => ['label' => 'Signs of infection', 'area' => 'immune', 'source' => 'nasa_immune'],
        'vision_changes' => ['label' => 'Vision changes', 'area' => 'sans', 'source' => 'nasa_sans'],
        'nausea' => ['label' => 'Nausea', 'area' => 'general'],
        'back_pain' => ['label' => 'Back pain', 'area' => 'general'],
    ],

    // PHQ-4 — exact item wording, 0–3 options, bands and cut-offs (source 'phq4').
    'phq4' => [
        'stem' => 'Over the last 2 weeks, how often have you been bothered by the following problems?',
        'options' => [0 => 'Not at all', 1 => 'Several days', 2 => 'More than half the days', 3 => 'Nearly every day'],
        'items' => [
            1 => 'Little interest or pleasure in doing things',          // PHQ-2
            2 => 'Feeling down, depressed, or hopeless',                 // PHQ-2
            3 => 'Feeling nervous, anxious, or on edge',                 // GAD-2
            4 => 'Not being able to stop or control worrying',           // GAD-2
        ],
        'bands' => [[0, 2, 'normal'], [3, 5, 'mild'], [6, 8, 'moderate'], [9, 12, 'severe']],
        'subscale_cutoff' => 3,
        'repeat_after_days' => 7,     // OrbitCare rule: offered weekly
    ],

    // OrbitCare rules for "What we noticed" (configurable, not clinical standards).
    'rules' => [
        'sleep_short_hours' => 6.0,
        'pattern_days' => 3,           // consecutive check-in days for a pattern
        'fatigue_moderate' => 4,       // Samn-Perelli ≥ 4 used as "moderate fatigue" in research (Rose et al., 2023)
        'fatigue_severe' => 6,         // Samn-Perelli ≥ 6 used as "severe fatigue" in research
        'repeat_symptom_days' => 3,    // same symptom on ≥ 3 of the last 7 days
    ],
];
