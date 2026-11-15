<?php
// Application settings. Secrets come from environment variables or the private .env file
// (see .env.example) — never from source code, HTML or JavaScript.
declare(strict_types=1);

$projectRoot = dirname(__DIR__);

return [
    'base_url' => env('APP_BASE_URL', '/orbitcare'),
    'debug'    => env('APP_DEBUG', 'false') === 'true',

    'database_url' => env('DATABASE_URL'),
    'auth_secret'  => env('AUTH_SECRET', ''),

    'session_idle_minutes' => (int) env('SESSION_IDLE_MINUTES', '120'),
    'cors_allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', ''))))),

    'log_file' => $projectRoot . '/logs/app.log',

    'replay' => [
        'speed' => max(0.1, (float) env('REPLAY_SPEED', '1')),
        // Records handed to new astronaut accounts, in order (see docs/DATA_PROVENANCE.md).
        'record_pool' => ['bidmc_03', 'bidmc_21', 'bidmc_32', 'bidmc_20', 'bidmc_36', 'bidmc_22', 'bidmc_02', 'bidmc_49'],
        // A metric shown on the dashboard is "unavailable" if its newest sample is older than this.
        'stale_after_seconds' => 10,
    ],

    // Online AI, used whenever a link to the ground is available; without it OrbitCare's
    // rule-based analysis answers on its own. Check a key with: php cli/check-ai.php
    'ai' => [
        'provider'        => env('AI_PROVIDER', ''),                 // 'gemini' or empty
        'api_key'         => env('AI_API_KEY', ''),
        'base_url'        => env('AI_API_BASE_URL', ''),             // empty = the provider's default
        'model'           => env('AI_MODEL', 'gemini-flash-lite-latest'),
        'timeout_seconds' => 20,
    ],

    // Official medical sources (US government, free): MedlinePlus (NLM) explanations and openFDA
    // medication labels. Used when online; answers are cached for 24 h as NLM asks.
    'medical_sources' => [
        'enabled'         => env('MEDICAL_SOURCES', 'on') !== 'off',
        'openfda_api_key' => env('OPENFDA_API_KEY', ''),             // optional; raises the daily limit
        'timeout_seconds' => 6,
        'cache_hours'     => 24,
    ],

    'medical_api' => [
        'api_key'         => env('MEDICAL_API_KEY', ''),
        'base_url'        => env('MEDICAL_API_BASE_URL', ''),
        'timeout_seconds' => 15,
    ],

    // Readings from a worn device (POST /api/device/readings). OFF by default: OrbitCare's live data is
    // the PhysioNet BIDMC recordings (real patient measurements). With it off the endpoint does not exist.
    'devices' => [
        'enabled' => env('DEVICE_INPUT', 'off') === 'on',
    ],
];
