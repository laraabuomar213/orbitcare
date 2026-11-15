<?php
// Official, free medical sources used next to the AI (online only):
//   MedlinePlus (US National Library of Medicine) — trusted explanations for a reported symptom.
//     Health Topics web service, free, no registration; NLM asks for ≤ 85 requests/min per IP and
//     caching results for 12–24 h (https://medlineplus.gov/about/developers/webservices/).
//   openFDA drug labels (US FDA) — the official label of each medication the astronaut entered,
//     to check whether a reported symptom is listed among its warnings / side effects.
//     Free; without a key 1,000 requests/day per IP, with a free key 120,000/day
//     (https://open.fda.gov/apis/authentication/).
// Symptom keys are those of SymptomDetector (config/ai-specialists.php).
declare(strict_types=1);

return [
    // symptom => [MedlinePlus search term, words to look for in a drug label]
    'symptoms' => [
        'shortness_of_breath' => ['term' => 'shortness of breath', 'label_terms' => ['shortness of breath', 'dyspnea', 'difficulty breathing', 'trouble breathing']],
        'cannot_breathe' => ['term' => 'breathing problems', 'label_terms' => ['trouble breathing', 'difficulty breathing', 'dyspnea']],
        'cough' => ['term' => 'cough', 'label_terms' => ['cough']],
        'wheezing' => ['term' => 'wheezing', 'label_terms' => ['wheezing', 'bronchospasm']],
        'chest_tightness' => ['term' => 'chest pain', 'label_terms' => ['chest tightness', 'tightness in the chest']],
        'chest_pain' => ['term' => 'chest pain', 'label_terms' => ['chest pain']],
        'palpitations' => ['term' => 'palpitations', 'label_terms' => ['palpitations', 'tachycardia', 'fast heartbeat', 'irregular heartbeat']],
        'fainting' => ['term' => 'fainting', 'label_terms' => ['fainting', 'syncope', 'loss of consciousness']],
        'dizziness' => ['term' => 'dizziness', 'label_terms' => ['dizziness', 'dizzy', 'vertigo', 'lightheadedness']],
        'headache' => ['term' => 'headache', 'label_terms' => ['headache']],
        'vision_changes' => ['term' => 'vision problems', 'label_terms' => ['blurred vision', 'vision changes', 'visual disturbance', 'double vision']],
        'confusion' => ['term' => 'confusion', 'label_terms' => ['confusion', 'disorientation']],
        'numbness' => ['term' => 'numbness', 'label_terms' => ['numbness', 'tingling', 'paresthesia']],
        'speech_difficulty' => ['term' => 'speech disorders', 'label_terms' => ['slurred speech', 'difficulty speaking']],
        'fatigue' => ['term' => 'fatigue', 'label_terms' => ['fatigue', 'drowsiness', 'somnolence', 'tiredness']],
        'nausea' => ['term' => 'nausea and vomiting', 'label_terms' => ['nausea', 'vomiting']],
    ],

    // Label sections searched for the words above (openFDA field names).
    'label_sections' => ['boxed_warning', 'warnings', 'warnings_and_cautions', 'adverse_reactions', 'when_using', 'stop_use', 'precautions'],

    'max_references' => 3,
];
