<?php
// AI Assistant specialists and the backend rules used to route between them.
//
// Specialists exist only where OrbitCare has matching data:
//   respiratory — SpO2 and respiratory rate (recorded)
//   cardiology  — heart rate and pulse rate (recorded)
//   neurology   — NO neurological measurements are recorded; works from reported symptoms only
//                 (heart rate and SpO2 are given as context because dizziness can relate to them)
//   general     — sees everything and coordinates
//
// Symptom detection is keyword-based (English and Arabic) and only used to suggest a specialist —
// never to diagnose. Phrases preceded by a negation ("no chest pain", "ما عندي دوخة") are ignored.
// Names, descriptions and questions shown to users are translated in backend/lang/*/ai.php;
// the English ones here are used in the AI prompt.
declare(strict_types=1);

return [
    'version' => '2026-11-15.1',

    // Each specialist carries its own clinical identity used to build its AI prompt (PromptBuilder):
    //   role        — how this specialist thinks and what it focuses on
    //   red_flags   — danger signs it must actively look for and escalate to the mission medical officer
    //   space_notes — spaceflight-specific knowledge the specialist should reason with (verified facts)
    // These are English (the prompt language). Names/focus/descriptions shown to users are translated.
    'specialists' => [
        'general' => [
            'name' => 'General Doctor AI',
            'focus' => 'General Health',
            'icon' => 'plus',
            'tone' => 'green',
            'metrics' => ['heart_rate', 'pulse_rate', 'spo2', 'respiratory_rate'],
            'context_metrics' => [],
            'description' => 'Your starting point. Reviews your symptoms and all recorded data and suggests the right specialist.',
            'intake_questions' => [],
            'role' => 'You are the first point of contact. Take a broad view across heart, breathing, oxygen, sleep, fatigue, mood and reported symptoms, decide what matters most right now, and route to the right specialist when one is clearly more relevant. You coordinate; you do not go deep into one system yourself.',
            'red_flags' => [
                'chest pain, fainting, or an inability to breathe',
                'confusion, difficulty speaking, sudden weakness or numbness',
                'any vital sign recorded in the critical (RED) range',
                'any mention of wanting to harm oneself',
            ],
            'space_notes' => 'On long missions the body is under constant stress from radiation, isolation, altered gravity and a closed cabin. Changes are often slow and silent. Help from the ground can be up to ~20 minutes away each way, so your judgement has to stand on its own on board.',
        ],
        'respiratory' => [
            'name' => 'Respiratory AI',
            'focus' => 'Breathing & Oxygen Levels',
            'icon' => 'lungs',
            'tone' => 'blue',
            'metrics' => ['spo2', 'respiratory_rate'],
            'context_metrics' => [],
            'description' => 'Focuses on blood oxygen (SpO₂), breathing rate and breathing-related symptoms.',
            'intake_questions' => [
                'When did the breathing difficulty start, and is it getting better or worse?',
                'Is it worse during exercise, lying down, or also at rest?',
                'Do you also have a cough, wheezing or chest tightness?',
            ],
            'role' => 'You focus on breathing and blood oxygen. Reason about SpO₂ and respiratory rate together and over time: a low SpO₂ with a fast respiratory rate is more concerning than either alone. Separate breathlessness on exertion from breathlessness at rest.',
            'red_flags' => [
                'SpO₂ below 90% at rest, or falling',
                'severe breathlessness at rest, or an inability to speak a full sentence',
                'breathlessness with chest pain, blue lips, or confusion',
            ],
            'space_notes' => 'Cabin CO₂ can build up in a closed spacecraft and cause headache and fast breathing — it is a property of the cabin air, measured separately, not of the astronaut. Fluid shifts toward the head in microgravity can also affect breathing when lying down.',
        ],
        'cardiology' => [
            'name' => 'Cardiologist AI',
            'focus' => 'Heart Health',
            'icon' => 'heart',
            'tone' => 'rose',
            'metrics' => ['heart_rate', 'pulse_rate'],
            'context_metrics' => [],
            'description' => 'Focuses on heart rate, pulse rate and heart-related symptoms.',
            'intake_questions' => [
                'Do you feel your heart racing, pounding, fluttering or skipping beats?',
                'Did it start during exercise or while resting?',
                'Have you had any chest pain, fainting or near-fainting?',
            ],
            'role' => 'You focus on the heart. Reason about heart rate and pulse rate together (a large, sustained gap between them can be a measurement or rhythm issue), distinguish rate from rhythm, and separate exertion and anxiety from a rate change at rest.',
            'red_flags' => [
                'chest pain or pressure, especially with sweating, nausea or breathlessness',
                'fainting or near-fainting',
                'a very fast or very slow heart rate at rest, or a persistently irregular pulse',
            ],
            'space_notes' => 'In microgravity, body fluids shift toward the head and the heart and blood vessels adapt; resting heart rate and how the circulation is regulated can change over a mission. Deconditioning without the daily ~1.5 h of exercise also affects the cardiovascular system.',
        ],
        'neurology' => [
            'name' => 'Neurology AI',
            'focus' => 'Neurological Symptoms',
            'icon' => 'brain',
            'tone' => 'violet',
            'metrics' => [],
            'context_metrics' => ['heart_rate', 'spo2'],
            'description' => 'Focuses on symptoms you report such as dizziness, headache or vision changes. OrbitCare records no neurological measurements.',
            'intake_questions' => [
                'Is the dizziness constant, or does it come and go? Does the room spin?',
                'Do you have a headache, vision changes, numbness, weakness or difficulty speaking?',
                'Did you hit your head, or did it start after a change in activity or position?',
            ],
            'role' => 'You work mainly from the symptoms the astronaut describes — OrbitCare records no brain measurements. Heart rate and SpO₂ are context only, because dizziness can come from the heart or from low oxygen. Separate a benign headache or dizziness from the warning signs below.',
            'red_flags' => [
                'sudden severe headache, or the worst headache of their life',
                'difficulty speaking, sudden weakness or numbness on one side, or facial droop',
                'confusion, fainting, new vision loss, or a seizure',
                'symptoms after a head injury',
            ],
            'space_notes' => 'Spaceflight-Associated Neuro-ocular Syndrome (SANS) can change vision on long missions. Space motion sickness (dizziness, nausea) is common early in flight. Fluid shifts toward the head affect pressure around the brain and eyes.',
        ],
        'sleep' => [
            'name' => 'Sleep & Fatigue AI',
            'focus' => 'Sleep & Fatigue',
            'icon' => 'moon',
            'tone' => 'indigo',
            'metrics' => [],
            'context_metrics' => ['heart_rate'],
            'description' => 'Focuses on sleep quality, fatigue and alertness, using your daily check-ins and Alertness Check. OrbitCare records no brain-wave data.',
            'intake_questions' => [
                'How long does it take you to fall asleep, and do you wake during the night?',
                'How many hours did you sleep, and how rested do you feel?',
                'What is your light and work schedule like before bed?',
            ],
            'role' => 'You focus on sleep and daytime alertness. Work from the astronaut\'s own daily check-ins (sleep hours, sleep quality, fatigue) and Alertness Check, compared with their own baseline, and resting heart rate as recovery context. Offer concrete, evidence-based sleep habits (a wind-down routine, offloading worries before bed, a consistent schedule, dimmer warmer light in the hours before sleep). You measure and support sleep; you never prescribe or adjust sleep medication.',
            'red_flags' => [
                'severe, worsening fatigue affecting safety-critical work (docking, EVA, repairs)',
                'falling asleep during tasks, or alertness far below the astronaut\'s own baseline',
                'persistent insomnia alongside low mood or anxiety',
            ],
            'space_notes' => 'ISS crews sleep about 6 hours a night on average and about three in four have used sleep medication (Barger et al., 2014). NASA\'s HERA lighting study showed warmer, much dimmer light in the hours before bed supports the sleep cycle. Sounds timed to deep-sleep brain waves may strengthen deep sleep, but this is a research direction, not tested in space.',
        ],
        'mental_health' => [
            'name' => 'Wellbeing Support AI',
            'focus' => 'Mental Wellbeing',
            'icon' => 'heart',
            'tone' => 'teal',
            'metrics' => [],
            'context_metrics' => ['heart_rate'],
            'description' => 'A warm, supportive space for stress, mood, isolation and motivation, using your weekly wellbeing check. It offers coping strategies and connects you with the mission medical officer — it is not a diagnosis.',
            'intake_questions' => [
                'What has been weighing on you most, and for how long?',
                'How is it affecting your sleep, appetite, focus or motivation?',
                'What has helped you cope before, and who on the crew or ground do you feel connected to?',
            ],
            'role' => 'You are a warm, supportive wellbeing companion for an astronaut far from home. Listen first and reflect what you hear. Offer practical, evidence-based coping (paced breathing, structure and routine, physical activity, staying connected with crew and family, breaking big worries into small steps, light and sleep). Use the weekly PHQ-4 wellbeing check as supportive context, never as a label. You are not a therapist and you do not diagnose; when distress is significant, gently and clearly encourage reaching out to the mission medical officer and trusted crew.',
            'red_flags' => [
                'any thought of self-harm or of not wanting to be alive',
                'hopelessness, or feeling unable to go on',
                'severe distress that stops them functioning or sleeping',
            ],
            'space_notes' => 'Isolation and confinement are a NASA-recognized behavioral-health risk on long missions, affecting mood, sleep and performance. Distance from family, a closed environment and a demanding schedule all add to the load. Peer support and routine are protective.',
        ],
    ],

    // Symptom keyword → which specialists it points to. 'red_flag' symptoms always lead to
    // "contact the mission medical officer now". Labels shown to users are in backend/lang/*/ai.php.
    // 'phrases' are English; 'phrases_ar' are Arabic (Modern Standard and common Levantine wording),
    // written naturally — SymptomDetector normalises spelling variants (أ/ا, ة/ه, ى/ي, diacritics).
    'symptoms' => [
        'shortness_of_breath' => ['label' => 'Shortness of breath', 'specialists' => ['respiratory'], 'red_flag' => false,
            'phrases' => ['short of breath', 'shortness of breath', 'breathless', 'out of breath', 'hard to breathe', 'difficulty breathing', 'trouble breathing', 'struggling to breathe'],
            'phrases_ar' => ['ضيق في التنفس', 'ضيق بالتنفس', 'ضيق تنفس', 'ضيق نفس', 'صعوبة في التنفس', 'صعوبة بالتنفس', 'التنفس صعب', 'نفسي قصير', 'نهجان', 'لاهث']],
        'cannot_breathe' => ['label' => 'Unable to breathe', 'specialists' => ['respiratory'], 'red_flag' => true,
            'phrases' => ["can't breathe", 'cannot breathe', 'can not breathe', 'unable to breathe', 'choking'],
            'phrases_ar' => ['لا أستطيع التنفس', 'لا استطيع ان اتنفس', 'مش قادر اتنفس', 'مش قادرة اتنفس', 'ما بقدر اتنفس', 'ما عم بقدر اتنفس', 'عاجز عن التنفس', 'اختناق', 'بختنق']],
        'cough' => ['label' => 'Cough', 'specialists' => ['respiratory'], 'red_flag' => false,
            'phrases' => ['cough'],
            'phrases_ar' => ['سعال', 'كحة', 'بكح', 'اكح', 'أسعل']],
        'wheezing' => ['label' => 'Wheezing', 'specialists' => ['respiratory'], 'red_flag' => false,
            'phrases' => ['wheez'],
            'phrases_ar' => ['صفير', 'وزيز', 'أزيز']],
        'chest_tightness' => ['label' => 'Chest tightness', 'specialists' => ['respiratory', 'cardiology'], 'red_flag' => false,
            'phrases' => ['chest tightness', 'tight chest', 'chest feels tight', 'tightness in my chest'],
            'phrases_ar' => ['ضيق في الصدر', 'ضيق بالصدر', 'ضيق في صدري', 'ضيق بصدري', 'صدري ضيق', 'ضغط على صدري', 'ثقل في الصدر', 'ثقل في صدري', 'ثقل على صدري']],
        'chest_pain' => ['label' => 'Chest pain', 'specialists' => ['cardiology', 'respiratory'], 'red_flag' => true,
            'phrases' => ['chest pain', 'pain in my chest', 'chest hurts', 'chest ache'],
            'phrases_ar' => ['ألم في الصدر', 'ألم بالصدر', 'ألم في صدري', 'ألم بصدري', 'ألم الصدر', 'وجع في صدري', 'وجع بصدري', 'وجع في الصدر', 'صدري بوجعني', 'صدري يوجعني', 'صدري يؤلمني']],
        'palpitations' => ['label' => 'Palpitations', 'specialists' => ['cardiology'], 'red_flag' => false,
            'phrases' => ['palpitation', 'heart racing', 'racing heart', 'heart is racing', 'heart pounding', 'pounding heart', 'irregular heartbeat', 'skipped beat', 'skipping beats', 'heart fluttering', 'fluttering'],
            'phrases_ar' => ['خفقان', 'قلبي بدق بسرعة', 'قلبي يدق بسرعة', 'قلبي يخفق', 'قلبي سريع', 'دقات قلبي سريعة', 'تسارع ضربات القلب', 'تسارع في ضربات القلب', 'تسارع نبضات القلب', 'ضربات قلب غير منتظمة', 'نبض غير منتظم', 'رفرفة']],
        'fainting' => ['label' => 'Fainting', 'specialists' => ['cardiology', 'neurology'], 'red_flag' => true,
            'phrases' => ['fainted', 'fainting', 'passed out', 'blacked out', 'blackout', 'lost consciousness'],
            'phrases_ar' => ['إغماء', 'أغمي علي', 'اغمى علي', 'غميت', 'فقدت الوعي', 'فقدان الوعي', 'غبت عن الوعي']],
        'dizziness' => ['label' => 'Dizziness', 'specialists' => ['neurology', 'cardiology'], 'red_flag' => false,
            'phrases' => ['dizzy', 'dizziness', 'lightheaded', 'light-headed', 'light headed', 'vertigo', 'room is spinning', 'feel faint'],
            'phrases_ar' => ['دوخة', 'دوخان', 'دايخ', 'دايخة', 'دوار', 'الدنيا بتلف', 'الغرفة تدور', 'راسي بلف', 'رأسي يدور', 'أشعر بالدوار']],
        'headache' => ['label' => 'Headache', 'specialists' => ['neurology'], 'red_flag' => false,
            'phrases' => ['headache', 'head ache', 'migraine', 'head hurts'],
            'phrases_ar' => ['صداع', 'شقيقة', 'وجع راس', 'وجع في راسي', 'ألم في الرأس', 'ألم في رأسي', 'راسي بوجعني', 'رأسي يؤلمني', 'راسي يوجعني']],
        'vision_changes' => ['label' => 'Vision changes', 'specialists' => ['neurology'], 'red_flag' => false,
            'phrases' => ['blurred vision', 'blurry vision', 'double vision', 'vision change', 'seeing spots', 'trouble seeing'],
            'phrases_ar' => ['زغللة', 'تشوش الرؤية', 'تشوش في الرؤية', 'رؤية ضبابية', 'رؤية مزدوجة', 'تغير في الرؤية', 'تغير في النظر', 'تغيرات في الرؤية', 'نظري ضعف', 'عيوني مغبشة', 'غباش', 'مش شايف منيح']],
        'confusion' => ['label' => 'Confusion', 'specialists' => ['neurology'], 'red_flag' => true,
            'phrases' => ['confused', 'confusion', 'disoriented', "can't think straight", 'trouble concentrating'],
            'phrases_ar' => ['مشوش', 'مشوشة', 'تشوش ذهني', 'ارتباك', 'مرتبك', 'مش قادر أركز', 'ما بقدر أركز', 'صعوبة في التركيز', 'لا أستطيع التركيز']],
        'numbness' => ['label' => 'Numbness or tingling', 'specialists' => ['neurology'], 'red_flag' => false,
            'phrases' => ['numb', 'tingling', 'pins and needles', 'weakness in my'],
            'phrases_ar' => ['تنميل', 'منمل', 'نمنمة', 'خدر', 'خدران', 'ضعف في يدي', 'ضعف في إيدي', 'ضعف في رجلي']],
        'speech_difficulty' => ['label' => 'Difficulty speaking', 'specialists' => ['neurology'], 'red_flag' => true,
            'phrases' => ['slurred speech', 'trouble speaking', 'difficulty speaking', "can't speak"],
            'phrases_ar' => ['صعوبة في الكلام', 'صعوبة بالكلام', 'ثقل في اللسان', 'لساني ثقيل', 'مش قادر أحكي', 'ما بقدر أحكي', 'كلامي مش واضح', 'لا أستطيع الكلام']],
        'fatigue' => ['label' => 'Fatigue', 'specialists' => ['sleep'], 'red_flag' => false,
            'phrases' => ['tired', 'fatigue', 'exhausted', 'no energy'],
            'phrases_ar' => ['تعبان', 'تعبانة', 'إرهاق', 'مرهق', 'مرهقة', 'منهك', 'منهكة', 'خمول', 'ما في طاقة', 'مافي طاقة', 'بلا طاقة', 'متعب']],
        'insomnia' => ['label' => 'Trouble sleeping', 'specialists' => ['sleep'], 'red_flag' => false,
            'phrases' => ['can\'t sleep', 'cannot sleep', 'trouble sleeping', 'not sleeping', 'insomnia', 'keep waking', 'poor sleep', 'bad sleep', 'didn\'t sleep', 'hard to sleep', 'sleepless'],
            'phrases_ar' => ['ما بقدر أنام', 'مش قادر أنام', 'مش قادرة أنام', 'ما بنام', 'أرق', 'بصحى بالليل', 'بصحى كتير', 'نومي مش منيح', 'نومي سيء', 'ما نمت', 'صعوبة في النوم', 'صعوبة بالنوم', 'سهران', 'سهرانة']],
        'low_mood' => ['label' => 'Low mood', 'specialists' => ['mental_health'], 'red_flag' => false,
            'phrases' => ['depressed', 'sad', 'down', 'low mood', 'hopeless', 'empty', 'lonely', 'homesick', 'unmotivated', 'no motivation', 'crying', 'miss my family', 'miss home'],
            'phrases_ar' => ['حزين', 'حزينة', 'مكتئب', 'مكتئبة', 'اكتئاب', 'مزاجي سيء', 'مزاجي تعبان', 'زهقان', 'زهقانة', 'يائس', 'يائسة', 'فقدت الأمل', 'وحيد', 'وحيدة', 'وحدة', 'مشتاق لأهلي', 'مشتاقة لأهلي', 'مشتاق للبيت', 'ما إلي نفس', 'فاقد الحافز', 'بعيط', 'ببكي']],
        'anxiety' => ['label' => 'Anxiety or stress', 'specialists' => ['mental_health'], 'red_flag' => false,
            'phrases' => ['anxious', 'anxiety', 'stressed', 'stress', 'worried', 'nervous', 'panic', 'overwhelmed', 'on edge', 'can\'t relax', 'tense'],
            'phrases_ar' => ['قلقان', 'قلقانة', 'قلق', 'توتر', 'متوتر', 'متوترة', 'مضغوط', 'مضغوطة', 'ضغط نفسي', 'خايف', 'خايفة', 'هلع', 'نوبة هلع', 'مش قادر أهدى', 'مش مرتاح', 'مخنوق', 'مخنوقة']],
        'self_harm' => ['label' => 'Thoughts of self-harm', 'specialists' => ['mental_health'], 'red_flag' => true,
            'phrases' => ['hurt myself', 'harm myself', 'kill myself', 'end my life', 'end it all', "don't want to live", 'want to die', 'no reason to live', 'better off dead', 'suicidal', 'suicide'],
            'phrases_ar' => ['أذي نفسي', 'أؤذي نفسي', 'آذي حالي', 'أنهي حياتي', 'أقتل نفسي', 'ما بدي أعيش', 'ما بدي أكمل', 'بدي أموت', 'ما إلي قيمة', 'الموت أحسن', 'أفكر أنهي حياتي', 'ما في فايدة من حياتي']],
        'nausea' => ['label' => 'Nausea', 'specialists' => ['general'], 'red_flag' => false,
            'phrases' => ['nausea', 'nauseous', 'vomit', 'space sick', 'motion sick'],
            'phrases_ar' => ['غثيان', 'لعيان نفس', 'نفسي لاعية', 'بدي أستفرغ', 'استفراغ', 'تقيؤ', 'ترجيع', 'دوار الحركة', 'دوار الفضاء']],
    ],

    // Which recorded metric is evidence for which specialist.
    'metric_specialists' => [
        'spo2' => 'respiratory',
        'respiratory_rate' => 'respiratory',
        'heart_rate' => 'cardiology',
        'pulse_rate' => 'cardiology',
    ],

    // Trend window used when summarising recent data for a consultation.
    'trend_window_seconds' => 600,

    'safety_notice' => 'OrbitCare supports decisions; it does not diagnose. If symptoms are severe or getting worse, contact your mission medical officer now.',
];
