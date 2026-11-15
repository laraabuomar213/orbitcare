<?php
// Wearable device (the OrbitCare vest and its Wokwi simulation): data-source labels and API errors.
return [
    'dashboard.measured' => 'measured {time}',
    'dashboard.device_pill' => 'Live device',
    'dashboard.device_pill_simulated' => 'Simulated device',
    'dashboard.device_hint' => 'Live readings from the OrbitCare vest',
    'dashboard.device_hint_simulated' => 'Live readings from the vest simulation (Wokwi) — simulated values, not real measurements',
    'dashboard.device_source' => 'Live readings from device',
    'dashboard.device_simulated' => 'simulated values — not real measurements',

    'analytics.device' => 'Device',
    'analytics.mode_device' => 'Live stream from the vest sensors',
    'analytics.mode_device_simulated' => 'Live stream from the vest simulation — simulated values, not real measurements',
    'analytics.stream_started' => 'Stream started',

    'profile.data_source_device' => 'Live readings from the OrbitCare vest ({device})',
    'profile.data_source_device_simulated' => 'Live readings from the vest simulation ({device}) — simulated values',

    'err.device_unauthorized' => 'Unknown or revoked device token.',
    'err.device_reading_id' => 'client_reading_id is required: 1–80 letters, digits or _ - . :',
    'err.device_astronaut_mismatch' => 'This device is registered to a different astronaut.',
];
