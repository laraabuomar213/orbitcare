<?php
// الجهاز القابل للارتداء (سترة OrbitCare ومحاكاتها على Wokwi): أسماء مصدر البيانات وأخطاء الواجهة البرمجية.
return [
    'dashboard.measured' => 'وقت القياس {time}',
    'dashboard.device_pill' => 'جهاز مباشر',
    'dashboard.device_pill_simulated' => 'جهاز محاكاة',
    'dashboard.device_hint' => 'قراءات مباشرة من سترة OrbitCare',
    'dashboard.device_hint_simulated' => 'قراءات مباشرة من محاكاة السترة (Wokwi) — قيم محاكاة، وليست قياسات حقيقية',
    'dashboard.device_source' => 'قراءات مباشرة من الجهاز',
    'dashboard.device_simulated' => 'قيم محاكاة — ليست قياسات حقيقية',

    'analytics.device' => 'الجهاز',
    'analytics.mode_device' => 'بث مباشر من حساسات السترة',
    'analytics.mode_device_simulated' => 'بث مباشر من محاكاة السترة — قيم محاكاة، وليست قياسات حقيقية',
    'analytics.stream_started' => 'بدأ البث',

    'profile.data_source_device' => 'قراءات مباشرة من سترة OrbitCare ‏({device})',
    'profile.data_source_device_simulated' => 'قراءات مباشرة من محاكاة السترة ‏({device}) — قيم محاكاة',

    'err.device_unauthorized' => 'رمز الجهاز غير معروف أو أُلغي.',
    'err.device_reading_id' => 'الحقل client_reading_id مطلوب: من 1 إلى 80 حرفًا أو رقمًا أو _ - . :',
    'err.device_astronaut_mismatch' => 'هذا الجهاز مسجَّل لرائد فضاء آخر.',
];
