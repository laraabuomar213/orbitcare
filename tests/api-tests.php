<?php
// End-to-end API tests over real HTTP (Apache must be running).
//   php tests/api-tests.php [base-url]        default: http://localhost/orbitcare
// Creates its own accounts (…@api-tests.local) and deletes them at the end.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../backend/bootstrap.php';

$base = rtrim($argv[1] ?? 'http://localhost/orbitcare', '/');
$stamp = bin2hex(random_bytes(3));
$results = [];

final class Client
{
    private string $jar;
    public string $csrf = '';

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'orbitcare-jar');
    }

    public function call(string $method, string $path, ?array $body = null, bool $withCsrf = true, ?string $raw = null, array $extraHeaders = []): array
    {
        $curl = curl_init("{$this->base}/api/{$path}");
        $headers = ['Accept: application/json', ...$extraHeaders];
        if ($method !== 'GET' && $withCsrf) {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        if ($body !== null || $raw !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 30,
            CURLOPT_POSTFIELDS => $raw ?? ($body !== null ? json_encode($body) : null),
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return ['status' => $status, 'json' => json_decode((string) $response, true) ?? []];
    }

    public function sessionId(): string
    {
        preg_match('/\torbitcare_session\t(\S+)/', (string) file_get_contents($this->jar), $match);
        return $match[1] ?? '';
    }

    public function refreshCsrf(): void
    {
        $this->csrf = $this->call('GET', 'auth/session')['json']['data']['csrf_token'];
    }
}

function expect(string $name, bool $condition, array $response = []): void
{
    global $results;
    $results[] = [$condition, $name, $condition ? '' : json_encode(['status' => $response['status'] ?? null, 'body' => $response['json'] ?? null])];
}

$missions = db()->query('SELECT id, code FROM missions')->fetchAll(PDO::FETCH_KEY_PAIR);
$lunar = array_search('LUNAR-GW', $missions, true);
$astroEmail = "astro-{$stamp}@api-tests.local";
$adminEmail = "admin-{$stamp}@api-tests.local";
$password = 'orbit2026pass';

try {
    // ---------- Astronaut sign-up ----------
    $astro = new Client($base);
    $astro->refreshCsrf();

    $r = $astro->call('POST', 'auth/signup', ['full_name' => 'API Astro', 'email' => $astroEmail, 'password' => $password, 'confirm_password' => $password, 'role' => 'astronaut'], false);
    expect('write without CSRF token is refused (403 csrf_failed)', $r['status'] === 403 && $r['json']['error']['code'] === 'csrf_failed', $r);

    // Worn-device input: off by default (live data = PhysioNet recordings) → the endpoint does not exist.
    // When DEVICE_INPUT=on it has no CSRF (no browser session) but needs a valid device token.
    $device = new Client($base);
    $reading = ['client_reading_id' => "api-{$stamp}", 'heart_rate_bpm' => 80, 'spo2_percent' => 97];
    if (!config()['devices']['enabled']) {
        $r = $device->call('POST', 'device/readings', $reading, false, null, ['X-Device-Token: ocd_not-a-real-token']);
        expect('device input is off: no device endpoint (404)', $r['status'] === 404, $r);
    } else {
        $r = $device->call('POST', 'device/readings', $reading, false);
        expect('device reading without a token is refused (401 device_unauthorized)', $r['status'] === 401 && $r['json']['error']['code'] === 'device_unauthorized', $r);
        $r = $device->call('POST', 'device/readings', $reading, false, null, ['X-Device-Token: ocd_not-a-real-token']);
        expect('device reading with an unknown token is refused (401)', $r['status'] === 401, $r);
        $r = $device->call('GET', 'device/readings');
        expect('device endpoint only accepts POST (405)', $r['status'] === 405, $r);
    }

    $r = $astro->call('POST', 'auth/signup', ['full_name' => '', 'email' => 'nope', 'password' => 'short', 'confirm_password' => 'x', 'role' => 'pilot']);
    expect('invalid sign-up returns 422 with field errors', $r['status'] === 422 && count($r['json']['error']['fields']) === 5, $r);

    $r = $astro->call('POST', 'auth/signup', null, true, '{not json');
    expect('malformed JSON body returns 400', $r['status'] === 400 && $r['json']['error']['code'] === 'invalid_json', $r);

    $r = $astro->call('POST', 'auth/signup', ['full_name' => 'API Astro', 'email' => $astroEmail, 'password' => $password, 'confirm_password' => $password, 'role' => 'astronaut']);
    expect('valid sign-up creates the account', $r['status'] === 200 && $r['json']['data']['role'] === 'astronaut', $r);
    $astro->refreshCsrf();

    $r = $astro->call('GET', 'health/current');
    expect('health data is blocked until the profile is complete', $r['status'] === 403 && $r['json']['error']['code'] === 'profile_incomplete', $r);

    $r = $astro->call('POST', 'auth/profile', ['astronaut_code' => '!', 'age' => 5]);
    expect('invalid profile returns 422', $r['status'] === 422 && isset($r['json']['error']['fields']['astronaut_code'], $r['json']['error']['fields']['age']), $r);

    $r = $astro->call('POST', 'auth/profile', [
        'astronaut_code' => "API-{$stamp}", 'age' => 34, 'height_cm' => 178, 'weight_kg' => 74,
        'has_chronic_conditions' => 'no', 'takes_medications' => 'no', 'has_allergies' => 'no',
    ]);
    expect('valid profile completes registration → astronaut dashboard', $r['status'] === 200 && str_ends_with($r['json']['data']['redirect'], 'pages/dashboard.php'), $r);
    $r = $astro->call('GET', 'missions');
    expect('a new astronaut is in no mission until an admin adds them', $r['status'] === 200 && $r['json']['data'] === [], $r);

    $r = $astro->call('GET', 'health/current');
    $metrics = array_column($r['json']['data']['metrics'] ?? [], 'metric');
    sort($metrics);
    expect('current health: replay of a dataset record, only real metrics (no temperature)', $r['status'] === 200
        && $r['json']['data']['replay']['mode'] === 'recorded_data_replay'
        && str_starts_with($r['json']['data']['replay']['record_id'], 'bidmc_')
        && $metrics == ['heart_rate', 'pulse_rate', 'respiratory_rate', 'spo2'], $r);
    $astroId = (int) db()->query("SELECT id FROM users WHERE email = " . db()->quote($astroEmail))->fetchColumn();

    $otherAstronaut = (int) db()->query("SELECT id FROM users WHERE role = 'astronaut' AND id <> {$astroId} ORDER BY id LIMIT 1")->fetchColumn();
    $r = $astro->call('GET', "health/current?astronaut_id={$otherAstronaut}");
    expect("astronaut cannot read another astronaut's health data (403)", $r['status'] === 403, $r);

    $r = $astro->call('GET', 'health/waveform?seconds=12');
    $w = $r['json']['data'] ?? [];
    $lengths = array_map('count', $w['channels'] ?? []);
    expect('waveform: real 125 Hz ECG/PPG/RESP from the same record, ending at the replay position', $r['status'] === 200
        && ($w['available'] ?? false) === true && $w['fs'] === 125
        && $w['record_id'] === $w['replay']['record_id']
        && array_keys($lengths) === ['ecg', 'ppg', 'resp'] && count(array_unique($lengths)) === 1 && $lengths['ecg'] <= 12 * 125
        && $w['end_seconds'] <= $w['replay']['position_seconds'] + 0.051, $r);   // position_seconds is rounded to 0.1 s
    $r = $astro->call('GET', "health/waveform?astronaut_id={$otherAstronaut}");
    expect("astronaut cannot read another astronaut's waveform (403)", $r['status'] === 403, $r);

    $r = $astro->call('GET', 'health/sensors');
    $sens = $r['json']['data']['sensors'] ?? [];
    $dose = $sens['radiation_dose_rate'] ?? [];
    expect('open sensors: skin temperature, skin conductance, movement (PhysioNet) and ISS dose rate (NASA RadLab), each with its source', $r['status'] === 200
        && isset($sens['skin_temperature'], $sens['skin_conductance'], $sens['movement'])
        && str_starts_with($sens['skin_temperature']['source']['id'], 'noneeg:Subject')
        && $sens['skin_temperature']['value'] >= 20 && $sens['skin_temperature']['value'] <= 45
        && ($dose['source']['id'] ?? '') === OpenSensorService::RADIATION_SOURCE && ($dose['unit'] ?? '') === 'µGy/h'
        && $sens['skin_temperature']['offset_seconds'] <= $r['json']['data']['replay']['position_seconds'] + 0.051
        && array_key_exists('self_reported', $r['json']['data']), $r);
    $r = $astro->call('GET', "health/sensors?astronaut_id={$otherAstronaut}");
    expect("astronaut cannot read another astronaut's sensors (403)", $r['status'] === 403, $r);

    $r = $astro->call('GET', 'health/trends?metric=heart_rate&range=7h');
    expect('unsupported trend range returns 422', $r['status'] === 422, $r);

    $r = $astro->call('GET', 'health/trends?metric=temperature&range=1h');
    expect('unknown metric is a 422 validation error, never invented data', $r['status'] === 422 && empty($r['json']['data']), $r);

    $r = $astro->call('GET', 'admin/overview');
    expect('astronaut cannot open the admin overview (403)', $r['status'] === 403, $r);

    // ---------- AI Assistant consultations ----------
    $r = $astro->call('GET', 'ai/specialists');
    expect('specialists come from the backend', $r['status'] === 200 && array_column($r['json']['data'], 'key') === ['general', 'respiratory', 'cardiology', 'neurology', 'sleep', 'mental_health'], $r);

    $r = $astro->call('POST', 'ai/conversations', ['specialist' => 'general']);
    $generalId = $r['json']['data']['conversation']['id'] ?? 0;
    expect('General Doctor consultation opens', $r['status'] === 200 && $generalId > 0, $r);

    $r = $astro->call('POST', "ai/conversations/{$generalId}/messages", ['message' => 'I feel short of breath and dizzy since last night.']);
    $reply = $r['json']['data']['messages'][1] ?? [];
    $observedIds = array_filter(array_column($reply['payload']['observations'] ?? [], 'raw_measurement_id'));
    $storedCount = $observedIds ? (int) db()->query('SELECT COUNT(*) FROM raw_measurements WHERE id IN (' . implode(',', array_map('intval', $observedIds)) . ')')->fetchColumn() : 0;
    // With an AI key in .env the interpretation comes from the AI; without one (or with no link,
    // or the free quota used up) it is honestly marked as unavailable. Both are valid here.
    $aiConfigured = (bool) ($astro->call('GET', 'ai/status')['json']['data']['configured'] ?? false);
    $interpretation = $reply['payload']['interpretation']['status'] ?? null;
    expect('message is analysed with real stored data and stored', $r['status'] === 200
        && $reply['sender'] === 'assistant'
        && ($aiConfigured ? in_array($interpretation, ['success', 'error'], true) : $interpretation === 'not_configured')
        && array_column($reply['payload']['symptoms'], 'key') === ['shortness_of_breath', 'dizziness']
        && count($observedIds) > 0 && $storedCount === count($observedIds), $r);

    $r = $astro->call('POST', 'ai/conversations', ['specialist' => 'respiratory', 'referral_from' => $generalId]);
    $handoff = $r['json']['data']['messages'][0]['payload']['handoff'] ?? [];
    $respiratoryId = $r['json']['data']['conversation']['id'] ?? 0;
    expect('specialist opens with the General Doctor context (no need to repeat)', $r['status'] === 200
        && str_contains($handoff['patient_concern'] ?? '', 'short of breath')
        && array_column($handoff['relevant_measurements'] ?? [], 'metric') === ['spo2', 'respiratory_rate'], $r);

    $r = $astro->call('GET', 'ai/conversations');
    expect('consultation history lists both consultations', $r['status'] === 200 && count($r['json']['data']) === 2, $r);

    $r = $astro->call('GET', "ai/conversations/{$generalId}");
    expect('reopening a consultation returns its messages (survives refresh)', $r['status'] === 200 && count($r['json']['data']['messages']) === 2, $r);

    $r = $astro->call('POST', 'ai/conversations', ['specialist' => 'surgeon']);
    expect('unknown specialist is rejected (422)', $r['status'] === 422, $r);

    $r = $astro->call('POST', "ai/conversations/{$respiratoryId}/messages", ['message' => '   ']);
    expect('empty message is rejected (422)', $r['status'] === 422, $r);

    $r = $astro->call('POST', 'ai/chat', ['message' => 'How am I doing?']);
    $last = end($r['json']['data']);
    expect('dashboard AI panel talks to the same General Doctor consultation', $r['status'] === 200 && $last['sender'] === 'assistant'
        && trim($last['body']) !== '' && ($aiConfigured || str_contains($last['body'], 'AI interpretation is unavailable'))
        && count(array_filter($r['json']['data'], fn ($m) => $m['sender'] === 'user')) >= 2, $r);

    $r = $astro->call('POST', "ai/conversations/{$respiratoryId}/close");
    expect('consultation can be closed', $r['status'] === 200 && $r['json']['data']['status'] === 'closed', $r);
    $r = $astro->call('POST', "ai/conversations/{$respiratoryId}/messages", ['message' => 'Hello?']);
    expect('closed consultation refuses new messages (409)', $r['status'] === 409, $r);

    // ---------- Daily check-in ----------
    $r = $astro->call('GET', 'checkins/today');
    expect('check-in page data loads (empty before the first check-in)', $r['status'] === 200 && $r['json']['data']['checkin'] === null && $r['json']['data']['wellbeing']['due'] === true, $r);

    $r = $astro->call('PUT', 'checkins/today', ['sleep_hours' => 40, 'symptoms' => []]);
    expect('invalid check-in returns 422 with field errors', $r['status'] === 422 && isset($r['json']['error']['fields']['sleep_hours'], $r['json']['error']['fields']['symptoms']), $r);

    $r = $astro->call('PUT', 'checkins/today', ['sleep_hours' => 5.5, 'sleep_quality' => 2, 'fatigue_level' => 5, 'resistance_minutes' => 20, 'aerobic_minutes' => 25,
        'symptoms' => ['dizziness'], 'radiation_dose_msv' => '0.42', 'notes' => 'API test', 'wellbeing' => ['1' => 1, '2' => 1, '3' => 2, '4' => 1]]);
    $insightKeys = array_column($r['json']['data']['insights'] ?? [], 'key');
    expect('check-in saves and returns rule-based insights', $r['status'] === 200
        && $r['json']['data']['checkin']['exercise_minutes'] === 45
        && in_array('fatigue_moderate', $insightKeys, true) && in_array('symptom_dizziness', $insightKeys, true)
        && $r['json']['data']['radiation']['total_msv'] === 0.42, $r);

    $r = $astro->call('GET', 'settings');
    expect('check-ins are private by default', $r['status'] === 200 && $r['json']['data']['privacy']['share_checkins_with_mission'] === false, $r);

    // ---------- Alertness Check ----------
    $r = $astro->call('POST', 'alertness', ['reaction_times' => [260, 280, 400, 50], 'anticipations' => 1, 'duration_seconds' => 180,
        'camera' => ['face_seconds' => 175, 'blinks' => 35, 'closed_seconds' => 3.5]]);
    expect('alertness test is scored on the server (PVT-B rules)', $r['status'] === 200
        && $r['json']['data']['latest']['lapses'] === 1 && $r['json']['data']['latest']['false_starts'] === 2
        && $r['json']['data']['latest']['median_rt_ms'] === 280.0 && $r['json']['data']['latest']['perclos_pct'] === 2.0, $r);
    $r = $astro->call('POST', 'alertness', ['reaction_times' => [260], 'anticipations' => 0, 'duration_seconds' => 30]);
    expect('a partial alertness test is refused (422)', $r['status'] === 422 && isset($r['json']['error']['fields']['duration_seconds']), $r);

    // ---------- Language ----------
    $r = $astro->call('GET', 'settings');
    expect('interface language defaults to English', ($r['json']['data']['display']['language'] ?? null) === 'en', $r);
    $r = $astro->call('PUT', 'settings', ['display' => ['language' => 'xx']]);
    expect('unknown language is rejected (422)', $r['status'] === 422 && isset($r['json']['error']['fields']['language']), $r);
    $r = $astro->call('PUT', 'settings', ['display' => ['language' => 'ar']]);
    expect('language is saved to the account', $r['status'] === 200 && $r['json']['data']['display']['language'] === 'ar', $r);
    $r = $astro->call('PUT', 'checkins/today', ['sleep_hours' => 40, 'symptoms' => []]);
    expect('after switching, API messages are in Arabic', $r['status'] === 422
        && $r['json']['error']['fields']['sleep_hours'] === translate_in('ar', 'err.checkin.sleep_hours'), $r);
    $r = $astro->call('GET', 'health/current');
    expect('health status text is in Arabic, codes stay the same', $r['status'] === 200
        && preg_match('/\p{Arabic}/u', $r['json']['data']['status']['title']) === 1
        && in_array($r['json']['data']['status']['level'], ['GREEN', 'YELLOW', 'RED', 'NO_DATA'], true), $r);
    $r = $astro->call('PUT', 'settings', ['display' => ['language' => 'en']]);
    expect('switching back to English works', $r['status'] === 200 && $r['json']['data']['display']['language'] === 'en', $r);

    // A second astronaut must not see or use the first one's consultations.
    $other = new Client($base);
    $other->refreshCsrf();
    $other->call('POST', 'auth/signup', ['full_name' => 'API Other', 'email' => "other-{$stamp}@api-tests.local", 'password' => $password, 'confirm_password' => $password, 'role' => 'astronaut']);
    $other->refreshCsrf();
    $other->call('POST', 'auth/profile', ['astronaut_code' => "OTH-{$stamp}", 'age' => 30, 'height_cm' => 170, 'weight_kg' => 65,
        'has_chronic_conditions' => 'no', 'takes_medications' => 'no', 'has_allergies' => 'no']);
    $r = $other->call('GET', "ai/conversations/{$generalId}");
    expect("another astronaut cannot read a consultation (404)", $r['status'] === 404, $r);
    $r = $other->call('POST', "ai/conversations/{$generalId}/messages", ['message' => 'hi']);
    expect("another astronaut cannot write to a consultation (404)", $r['status'] === 404, $r);
    $r = $other->call('POST', 'ai/conversations', ['specialist' => 'respiratory', 'referral_from' => $generalId]);
    expect("another astronaut cannot use a consultation as referral (404)", $r['status'] === 404, $r);
    $r = $other->call('GET', 'ai/conversations');
    expect("another astronaut's history is empty", $r['status'] === 200 && $r['json']['data'] === [], $r);
    $r = $other->call('GET', 'checkins/today');
    expect("another astronaut only sees their own (empty) check-in", $r['status'] === 200 && $r['json']['data']['checkin'] === null, $r);

    $r = $astro->call('PUT', 'health/current');
    expect('wrong HTTP method returns 405', $r['status'] === 405, $r);

    $r = $astro->call('GET', 'does-not-exist');
    expect('unknown endpoint returns 404 JSON', $r['status'] === 404 && $r['json']['ok'] === false, $r);

    // ---------- Duplicate account ----------
    $dupe = new Client($base);
    $dupe->refreshCsrf();
    $r = $dupe->call('POST', 'auth/signup', ['full_name' => 'Dupe', 'email' => strtoupper($astroEmail), 'password' => $password, 'confirm_password' => $password, 'role' => 'astronaut']);
    expect('duplicate email returns 409 on the email field', $r['status'] === 409 && isset($r['json']['error']['fields']['email']), $r);

    // ---------- Mission admin ----------
    $admin = new Client($base);
    $admin->refreshCsrf();
    $admin->call('POST', 'auth/signup', ['full_name' => 'API Admin', 'email' => $adminEmail, 'password' => $password, 'confirm_password' => $password, 'role' => 'mission_admin']);
    $admin->refreshCsrf();
    $r = $admin->call('POST', 'auth/profile', ['job_title' => 'Flight surgeon', 'organization' => 'Test']);
    expect('admin profile completes as pending approval', $r['status'] === 200 && $r['json']['data']['pending_approval'] === true, $r);

    $r = $admin->call('GET', 'admin/overview');
    expect('choosing "Admin" alone grants nothing (403 until approved)', $r['status'] === 403, $r);
    $r = $admin->call('POST', 'missions', ['name' => 'Not yet']);
    expect('an unapproved admin cannot create a mission (403)', $r['status'] === 403, $r);

    $r = $admin->call('GET', 'ai/conversations');
    expect('AI consultations are for astronauts only (403)', $r['status'] === 403, $r);
    $r = $admin->call('GET', 'checkins/today');
    expect('daily check-in is for astronauts only (403)', $r['status'] === 403, $r);
    $r = $admin->call('GET', 'alertness');
    expect('alertness check is for astronauts only (403)', $r['status'] === 403, $r);

    exec(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../cli/approve-admin.php') . ' ' . escapeshellarg($adminEmail), $output, $exitCode);
    expect('admin approval via CLI', $exitCode === 0);

    // ---------- Admin creates a mission and chooses the crew ----------
    $r = $admin->call('POST', 'missions', ['name' => "API Mission {$stamp}", 'description' => 'API test']);
    $missionId = $r['json']['data']['id'] ?? 0;
    expect('approved admin creates a mission', $r['status'] === 200 && $r['json']['data']['can_manage'] === true, $r);
    $r = $admin->call('GET', 'admin/astronauts?q=' . rawurlencode("API-{$stamp}"));
    expect('admin sees astronauts not yet in a mission', $r['status'] === 200 && in_array($astroId, array_column($r['json']['data'], 'id'), true), $r);
    $r = $admin->call('POST', "missions/{$missionId}/members", ['astronaut_id' => $astroId]);
    expect('admin adds the astronaut to the crew', $r['status'] === 200 && in_array($astroId, array_column($r['json']['data'], 'id'), true), $r);
    $r = $astro->call('POST', "missions/{$missionId}/members", ['astronaut_id' => $astroId]);
    expect('astronauts cannot change a crew (403)', $r['status'] === 403, $r);
    $r = $admin->call('GET', 'admin/missions');
    $listed = array_column($r['json']['data'] ?? [], null, 'id')[$missionId] ?? null;
    expect('My Missions lists the new mission with its crew size', $r['status'] === 200 && ($listed['astronauts'] ?? 0) === 1, $r);
    $r = $admin->call('GET', "admin/missions/{$missionId}");
    expect('My Missions: crew table and tiles for the mission', $r['status'] === 200 && ($r['json']['data']['stats']['astronauts'] ?? 0) === 1
        && ($r['json']['data']['crew'][0]['id'] ?? 0) === $astroId, $r);
    $r = $astro->call('GET', "admin/missions/{$missionId}");
    expect('astronauts cannot open My Missions (403)', $r['status'] === 403, $r);

    $r = $astro->call('POST', "missions/{$missionId}/chat", ['body' => 'Hello crew', 'after_id' => 0]);
    $texts = array_column(array_filter($r['json']['data']['messages'] ?? [], fn ($m) => $m['kind'] === 'text'), 'body');
    expect('crew chat: the astronaut writes to the mission chat', $r['status'] === 200 && in_array('Hello crew', $texts, true), $r);
    $r = $admin->call('GET', "missions/{$missionId}/chat");
    $kinds = array_column($r['json']['data']['messages'] ?? [], 'kind');
    expect('crew chat: the admin sees the message, the crew change and the status bar', $r['status'] === 200 && in_array('system', $kinds, true) && in_array('text', $kinds, true) && count($r['json']['data']['crew']) === 2, $r);
    $lastId = max(array_column($r['json']['data']['messages'], 'id'));
    $r = $admin->call('POST', "missions/{$missionId}/read", ['message_id' => $lastId]);
    expect('crew chat: seen by is recorded', $r['status'] === 200 && in_array($lastId, array_column($r['json']['data']['reads'], 'last_read'), true), $r);
    $r = $other->call('GET', "missions/{$missionId}/chat");
    expect('an astronaut outside the crew cannot read the chat (403)', $r['status'] === 403, $r);

    $r = $admin->call('GET', 'admin/overview');
    $crewIds = array_column($r['json']['data']['crew'] ?? [], 'id');
    expect('approved admin sees their mission crew', $r['status'] === 200 && in_array($astroId, $crewIds, true), $r);

    $r = $admin->call('GET', "health/current?astronaut_id={$astroId}");
    expect("admin can open a crew member's health status", $r['status'] === 200, $r);
    $r = $admin->call('GET', "health/waveform?astronaut_id={$astroId}");
    expect("admin can open a crew member's waveform", $r['status'] === 200 && isset($r['json']['data']['available']), $r);

    $marsAstronaut = (int) db()->query("SELECT mm.user_id FROM mission_members mm JOIN missions m ON m.id = mm.mission_id WHERE m.code = 'MARS-EXP' AND mm.member_role = 'astronaut' LIMIT 1")->fetchColumn();
    if ($marsAstronaut) {
        $r = $admin->call('GET', "health/current?astronaut_id={$marsAstronaut}");
        expect('admin cannot see astronauts of a mission they do not manage', $r['status'] === 403, $r);
    }

    // ---------- Logout / login ----------
    $r = $astro->call('POST', 'auth/logout');
    expect('logout succeeds', $r['status'] === 200, $r);
    $r = $astro->call('GET', 'health/current');
    expect('after logout protected endpoints return 401', $r['status'] === 401 && $r['json']['error']['code'] === 'not_authenticated', $r);

    $astro->refreshCsrf();
    $r = $astro->call('POST', 'auth/login', ['email' => $astroEmail, 'password' => 'wrong-pass-1']);
    expect('wrong password returns 401 with a generic message', $r['status'] === 401 && $r['json']['error']['message'] === 'Incorrect email or password.', $r);
    $r = $astro->call('POST', 'auth/login', ['email' => "nobody-{$stamp}@api-tests.local", 'password' => 'whatever1']);
    expect('unknown email gets the same message', $r['status'] === 401 && $r['json']['error']['message'] === 'Incorrect email or password.', $r);
    $r = $astro->call('POST', 'auth/login', ['email' => $astroEmail, 'password' => $password]);
    expect('correct login redirects to the astronaut dashboard', $r['status'] === 200 && str_ends_with($r['json']['data']['redirect'], 'pages/dashboard.php'), $r);

    // Session expiry: age the server-side session file past the idle limit, then call the API.
    $r = $astro->call('GET', 'ai/conversations');
    expect('consultations are still there after logging out and in again', $r['status'] === 200 && count($r['json']['data']) === 2, $r);
    $sessionFile = rtrim(session_save_path() ?: sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $astro->sessionId();
    if (is_file($sessionFile)) {
        file_put_contents($sessionFile, preg_replace('/last_activity\|i:\d+;/', 'last_activity|i:1;', file_get_contents($sessionFile)));
        $r = $astro->call('GET', 'ai/conversations');
        expect('expired session returns 401 session_expired (frontend redirects to login)', $r['status'] === 401 && $r['json']['error']['code'] === 'session_expired', $r);
    } else {
        expect('session file found for the expiry test', false, ['status' => 0, 'json' => ['file' => $sessionFile]]);
    }
} finally {
    // Missions created by test admins first (their creator link would otherwise just become empty).
    db()->exec("DELETE FROM missions WHERE created_by IN (SELECT id FROM users WHERE email LIKE '%@api-tests.local')");
    $deleted = db()->exec("DELETE FROM users WHERE email LIKE '%@api-tests.local'");
    db()->exec("DELETE FROM rate_limit_hits");
}

$failed = 0;
foreach ($results as [$ok, $name, $detail]) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . ($ok ? '' : "\n      {$detail}") . "\n";
    $failed += $ok ? 0 : 1;
}
echo "\n" . (count($results) - $failed) . '/' . count($results) . " API checks passed. Cleaned up {$deleted} test account(s).\n";
exit($failed ? 1 : 0);
