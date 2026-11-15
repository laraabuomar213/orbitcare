<?php
// OrbitCare unit + integration tests. Uses a SEPARATE database (orbitcare_test) that is
// dropped and rebuilt on every run, so real data is never touched.
//   php tests/run.php
// Override the test database with TEST_DATABASE_URL (default: local XAMPP root).
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$testDatabaseUrl = getenv('TEST_DATABASE_URL') ?: 'mysql://root:@127.0.0.1:3306/orbitcare_test';
putenv("DATABASE_URL={$testDatabaseUrl}");
// Tests never call the internet, whatever .env says: no real AI provider, no online medical sources
// (tests that need them pass fakes).
putenv('AI_PROVIDER=none');
putenv('MEDICAL_SOURCES=off');
require __DIR__ . '/../backend/bootstrap.php';

// ---------- Fresh test database ----------
$parts = parse_url($testDatabaseUrl);
$testDbName = ltrim($parts['path'], '/');
if (!str_ends_with($testDbName, '_test')) {
    fwrite(STDERR, "Refusing to run: the test database name must end in _test (got {$testDbName}).\n");
    exit(1);
}
$server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $parts['host'], $parts['port'] ?? 3306), urldecode($parts['user'] ?? ''), urldecode($parts['pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `{$testDbName}`");
$schema = file_get_contents(__DIR__ . '/../backend/database/schema.sql');
$schema = preg_replace('/^(CREATE DATABASE IF NOT EXISTS|USE) orbitcare\b/m', "$1 `{$testDbName}`", $schema);
foreach (array_filter(array_map('trim', preg_split('/;\s*$/m', $schema))) as $statement) {
    if (!str_starts_with($statement, '--') || str_contains($statement, "\n")) {
        $server->exec($statement);
    }
}
db()->exec(file_get_contents(__DIR__ . '/../backend/database/seed.sql'));

// ---------- Tiny test runner ----------
$tests = [];
function test(string $name, callable $body): void { global $tests; $tests[$name] = $body; }
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function same(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("{$what}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function throws(callable $body, string $class, string $what): void
{
    try { $body(); } catch (Throwable $error) { if ($error instanceof $class) return; throw new RuntimeException("{$what}: expected {$class}, got " . get_class($error) . ': ' . $error->getMessage()); }
    throw new RuntimeException("{$what}: expected {$class}, nothing was thrown");
}
function make_user(string $name, string $role, ?string $record = null, bool $approved = false): array
{
    db()->prepare('INSERT INTO users (full_name, email, password_hash, role, admin_approved_at, profile_completed_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())')
        ->execute([$name, strtolower(str_replace(' ', '.', $name)) . '@test.local', password_hash('test1234', PASSWORD_DEFAULT), $role, $approved ? gmdate('Y-m-d H:i:s') : null]);
    $id = (int) db()->lastInsertId();
    if ($role === 'astronaut') {
        db()->prepare('INSERT INTO astronauts (user_id, astronaut_code, data_source_record_id) VALUES (?, ?, ?)')->execute([$id, 'T-' . $id, $record]);
    }
    return ['id' => $id, 'role' => $role, 'admin_approved_at' => $approved ? 'yes' : null, 'full_name' => $name];
}
$fixtures = __DIR__ . '/fixtures';
$missionMars = (int) db()->query("SELECT id FROM missions WHERE code = 'MARS-EXP'")->fetchColumn();
$missionLunar = (int) db()->query("SELECT id FROM missions WHERE code = 'LUNAR-GW'")->fetchColumn();

// ===================================================================== tests

test('database connection works', function () {
    same(1, (int) db()->query('SELECT 1')->fetchColumn(), 'SELECT 1');
});

test('validator: valid, missing and impossible values', function () {
    $v = new DataValidator();
    same('valid', $v->validateValue('SpO2', '97')['status'], 'SpO2 97');
    same('missing', $v->validateValue('SpO2', 'NaN')['status'], 'NaN');
    same('missing', $v->validateValue('HR', '')['status'], 'empty');
    same('invalid', $v->validateValue('SpO2', '150')['status'], 'SpO2 150');
    same('invalid', $v->validateValue('HR', 'abc')['status'], 'text');
    same(null, $v->parseTime('-1'), 'negative time');
});

test('dataset loading: stores only valid values, counts missing, never invents', function () use ($fixtures) {
    $report = (new DataIngestion(new DataValidator(), sys_get_temp_dir()))->ingestRecord("{$fixtures}/bidmc_90_Numerics.csv");
    same(40, $report['rows_read'], 'rows read');
    same(20, $report['values_missing'], 'missing PULSE values');
    same(140, $report['values_stored'], 'stored = 40 × 4 − 20');
    $pulseInGap = db()->query("SELECT COUNT(*) FROM raw_measurements WHERE source_record_id = 'bidmc_90' AND metric = 'pulse_rate' AND original_timestamp >= 20")->fetchColumn();
    same(0, (int) $pulseInGap, 'no pulse values stored where the source had NaN');
});

test('dataset loading is idempotent', function () use ($fixtures) {
    $report = (new DataIngestion(new DataValidator(), sys_get_temp_dir()))->ingestRecord("{$fixtures}/bidmc_90_Numerics.csv");
    same(0, $report['values_stored'], 'second run stores nothing new');
    same(140, $report['values_already_present'], 'already present');
});

test('invalid data never enters the database', function () use ($fixtures) {
    $report = (new DataIngestion(new DataValidator(), sys_get_temp_dir()))->ingestRecord("{$fixtures}/bidmc_91_Numerics.csv");
    same(7, $report['values_rejected'], 'rejected: SpO2 150, HR abc, HR 350, 4 values of the negative-time row');
    $impossible = db()->query("SELECT COUNT(*) FROM raw_measurements WHERE source_record_id = 'bidmc_91' AND ((metric = 'spo2' AND value > 100) OR (metric = 'heart_rate' AND value > 300))")->fetchColumn();
    same(0, (int) $impossible, 'impossible values stored');
});

test('invalid dataset file is refused', function () use ($fixtures) {
    throws(fn () => (new DataIngestion(new DataValidator(), sys_get_temp_dir()))->ingestRecord("{$fixtures}/bidmc_92_Numerics.csv"), InvalidArgumentException::class, 'bad header');
    throws(fn () => (new DataIngestion(new DataValidator(), sys_get_temp_dir()))->ingestRecord("{$fixtures}/missing_Numerics.csv"), InvalidArgumentException::class, 'bad name');
});

test('raw measurements are immutable', function () {
    throws(fn () => db()->exec("UPDATE raw_measurements SET value = 1 WHERE source_record_id = 'bidmc_90' LIMIT 1"), PDOException::class, 'UPDATE');
    throws(fn () => db()->exec("DELETE FROM raw_measurements WHERE source_record_id = 'bidmc_90' LIMIT 1"), PDOException::class, 'DELETE');
});

test('threshold classification is deterministic', function () {
    $a = new HealthAnalysis();
    same('GREEN', $a->classify('spo2', 95), 'SpO2 95');
    same('YELLOW', $a->classify('spo2', 94), 'SpO2 94');
    same('RED', $a->classify('spo2', 89), 'SpO2 89');
    same('GREEN', $a->classify('heart_rate', 100), 'HR 100');
    same('YELLOW', $a->classify('heart_rate', 101), 'HR 101');
    same('RED', $a->classify('heart_rate', 121), 'HR 121');
    same('RED', $a->classify('respiratory_rate', 7), 'RESP 7');
});

test('replay position follows the clock and stops at the end', function () {
    $replay = new DataReplay();
    $session = ['started_at' => gmdate('Y-m-d H:i:s', time() - 20) . '.000', 'speed' => '1', 'duration_seconds' => 39];
    $position = $replay->position($session, (float) time());
    check(abs($position - 20) < 0.01, "position after 20 s should be 20, got {$position}");
    same(39.0, $replay->position($session, (float) time() + 500), 'capped at the recording length');
});

test('alert engine: sustained RED SpO2 creates one traceable alert, recovery resolves it', function () use ($missionMars) {
    $user = make_user('Fixture Astronaut', 'astronaut', 'bidmc_90');
    (new MissionService())->join($user['id'], $missionMars, 'astronaut');

    $replay = new DataReplay();
    $session = $replay->start($user['id'], 'bidmc_90', microtime(true) - 100);
    $replay->advance((int) $session['id'], null, true);

    $alerts = db()->query("SELECT a.*, src.original_timestamp AS src_t, first.original_timestamp AS first_t
        FROM alerts a JOIN raw_measurements src ON src.id = a.source_measurement_id JOIN raw_measurements first ON first.id = a.first_measurement_id
        WHERE a.user_id = {$user['id']}")->fetchAll();

    same(1, count($alerts), 'number of alerts');
    $alert = $alerts[0];
    same('RED', $alert['severity'], 'severity');
    same('spo2', $alert['metric'], 'metric');
    same('14.000', $alert['src_t'], 'alert fires on the 10th consecutive RED sample (t = 14)');
    same('5.000', $alert['first_t'], 'condition started at t = 5');
    same('88.00', $alert['observed_value'], 'observed value');
    same('resolved', $alert['status'], 'resolved after 10 s back in range');
    same(1, (int) $alert['is_test_fixture'], 'marked as test fixture');
    same($missionMars, (int) $alert['mission_id'], 'linked to the mission feed');
    check(str_contains($alert['rule'], 'below 90 %'), 'rule text names the threshold');
});

test('alert engine is reproducible: same data → same alerts', function () {
    $a = make_user('Repeat One', 'astronaut', 'bidmc_90');
    $b = make_user('Repeat Two', 'astronaut', 'bidmc_90');
    $replay = new DataReplay();
    foreach ([$a, $b] as $user) {
        $session = $replay->start($user['id'], 'bidmc_90', microtime(true) - 100);
        $replay->advance((int) $session['id'], null, true);
    }
    $fingerprint = fn (int $userId) => db()->query("SELECT severity, metric, observed_value, rule, source_measurement_id, first_measurement_id, status FROM alerts WHERE user_id = {$userId} ORDER BY id")->fetchAll();
    same($fingerprint($a['id']), $fingerprint($b['id']), 'alerts for identical input');
});

test('processed measurements reference their raw measurement', function () {
    $orphans = db()->query('SELECT COUNT(*) FROM processed_measurements pm LEFT JOIN raw_measurements rm ON rm.id = pm.raw_measurement_id WHERE rm.id IS NULL')->fetchColumn();
    same(0, (int) $orphans, 'processed rows without a raw measurement');
});

test('missing data is reported as unavailable, never filled in', function () {
    $user = make_user('Gap Astronaut', 'astronaut', 'bidmc_90');
    $replay = new DataReplay();
    // 35 s into the 40 s recording: PULSE has been missing since t = 20 (last reading 16 s old).
    $replay->start($user['id'], 'bidmc_90', microtime(true) - 35);
    $current = (new HealthService($replay))->current($user['id']);
    $pulse = array_values(array_filter($current['metrics'], fn ($m) => $m['metric'] === 'pulse_rate'))[0];
    same(false, $pulse['available'], 'pulse available');
    same(null, $pulse['value'], 'pulse value');
    $spo2 = array_values(array_filter($current['metrics'], fn ($m) => $m['metric'] === 'spo2'))[0];
    same(true, $spo2['available'], 'SpO2 still available');
    same(97.0, $spo2['value'], 'SpO2 is the original recorded value');
});

test('health status text comes from real state', function () {
    $a = new HealthAnalysis();
    same('NO_DATA', $a->overallStatus(['spo2' => null], 0, 0)['level'], 'no data');
    same('GREEN', $a->overallStatus(['spo2' => 'GREEN', 'heart_rate' => 'GREEN'], 0, 0)['level'], 'all green');
    same('RED', $a->overallStatus(['spo2' => 'GREEN'], 1, 0)['level'], 'open RED alert');
});

test('mission permissions: who may see whose health data', function () use ($missionMars, $missionLunar) {
    $missions = new MissionService();
    $alice = make_user('Alice Perm', 'astronaut', 'bidmc_90');
    $bob = make_user('Bob Perm', 'astronaut', 'bidmc_90');
    $carol = make_user('Carol Perm', 'astronaut', 'bidmc_90');
    $admin = make_user('Admin Perm', 'mission_admin', null, true);
    $pending = make_user('Pending Perm', 'mission_admin', null, false);
    $missions->join($alice['id'], $missionMars, 'astronaut');
    $missions->join($bob['id'], $missionMars, 'astronaut');
    $missions->join($carol['id'], $missionLunar, 'astronaut');
    $missions->join($admin['id'], $missionMars, 'mission_admin');
    $missions->join($pending['id'], $missionMars, 'mission_admin');

    same(true, $missions->canViewAstronaut($alice, $alice['id']), 'astronaut sees self');
    same(false, $missions->canViewAstronaut($alice, $bob['id']), 'astronaut sees crewmate');
    same(true, $missions->canViewAstronaut($admin, $alice['id']), 'admin sees own mission crew');
    same(false, $missions->canViewAstronaut($admin, $carol['id']), 'admin sees other mission');
    same(false, $missions->canViewAstronaut($pending, $alice['id']), 'unapproved admin');
    same(false, $missions->canAccessMission($carol, $missionMars), 'astronaut accesses other mission');
    throws(fn () => (new AlertService())->get($bob, (int) db()->query("SELECT id FROM alerts WHERE user_id IN (SELECT user_id FROM astronauts) LIMIT 1")->fetchColumn()), ApiException::class, 'crewmate alert');
});

// ---------- AI Assistant consultations ----------

// Fake providers for tests only (the real default is NotConfiguredProvider).
function fake_provider(string $mode, ?array &$lastInput = null): AIProvider
{
    return new class ($mode, $lastInput) implements AIProvider {
        public function __construct(private string $mode, private ?array &$lastInput) {}
        public function name(): string { return 'test-fake'; }
        public function isConfigured(): bool { return true; }
        public function run(string $task, array $input): AIResult
        {
            $this->lastInput = $input;
            return match ($this->mode) {
                'timeout' => throw new RuntimeException('Simulated timeout'),
                'malformed' => new AIResult('success', ['reply' => 'Hi'], null, 'test-fake'),
                default => new AIResult('success', [
                    'reply' => 'Test reply from the fake provider.', 'interpretation' => 'Test interpretation.',
                    'recommended_next_step' => 'Test next step.', 'follow_up_questions' => ['Test question?'], 'insufficient_data' => false,
                ], null, 'test-fake'),
            };
        }
    };
}

function consultation_user(string $name, ?string $record, int $secondsIntoReplay = 16): array
{
    global $missionMars;
    $user = make_user($name, 'astronaut', $record) + ['role' => 'astronaut'];
    (new MissionService())->join($user['id'], $missionMars, 'astronaut');
    if ($record) {
        (new DataReplay())->start($user['id'], $record, microtime(true) - $secondsIntoReplay);
    }
    return $user;
}

$consultation = [];   // shared between the following tests

test('symptom detector ignores negated symptoms', function () {
    $found = (new SymptomDetector())->detect(['I have no chest pain, but I feel dizzy and short of breath']);
    same(['shortness_of_breath', 'dizziness'], array_keys($found), 'detected symptoms');
});

test('General Doctor without a provider: real data, honest "AI unavailable", rule-based specialist', function () use (&$consultation) {
    $user = consultation_user('Consult Astronaut', 'bidmc_90');
    $service = new ConsultationService(new NotConfiguredProvider());
    $open = $service->open($user, 'general');
    $result = $service->sendMessage($user, $open['conversation']['id'], 'I feel short of breath and a bit dizzy since last night.');

    same(['user', 'assistant'], array_column($result['messages'], 'sender'), 'senders');
    $payload = $result['messages'][1]['payload'];
    same('not_configured', $payload['interpretation']['status'], 'interpretation status');
    same('respiratory', $payload['recommendation']['specialist'], 'recommended specialist');
    same('urgent', $payload['next_step']['level'], 'SpO2 is RED in the fixture → urgent');
    check(str_contains($result['messages'][1]['body'], 'AI interpretation is unavailable'), 'body says AI is unavailable');

    $spo2 = array_values(array_filter($payload['observations'], fn ($o) => $o['metric'] === 'spo2'))[0];
    $stored = db()->query("SELECT value FROM raw_measurements WHERE id = {$spo2['raw_measurement_id']}")->fetchColumn();
    same((float) $stored, (float) $spo2['value'], 'observed SpO2 equals the stored raw value');
    same('not_configured', db()->query("SELECT status FROM ai_analysis WHERE user_id = {$user['id']} ORDER BY id DESC LIMIT 1")->fetchColumn(), 'ai_analysis status');
    $consultation = ['user' => $user, 'general_id' => $open['conversation']['id']];
});

test('specialist receives a structured handoff and continues with context', function () use (&$consultation) {
    $service = new ConsultationService(new NotConfiguredProvider());
    $opened = $service->open($consultation['user'], 'respiratory', $consultation['general_id']);
    $handoff = $opened['messages'][0]['payload']['handoff'];

    same('handoff', $opened['messages'][0]['payload']['kind'], 'first message is the handoff');
    check(str_contains($handoff['patient_concern'], 'short of breath'), 'concern carried over');
    check(in_array('Shortness of breath', $handoff['symptoms'], true), 'symptoms carried over');
    same(['spo2', 'respiratory_rate'], array_column($handoff['relevant_measurements'], 'metric'), 'only respiratory measurements');
    check(count($handoff['general_doctor_assessment']) > 0, 'General Doctor reasons included');
    check(str_contains($handoff['reason_for_referral'], 'Recommended'), 'reason for referral');

    $reply = $service->sendMessage($consultation['user'], $opened['conversation']['id'], 'It is worse when I exercise.');
    same('respiratory', $reply['messages'][1]['payload']['specialist'], 'answered by the specialist');
    $consultation['respiratory_id'] = $opened['conversation']['id'];
});

test('consultations persist, reopen, close and stay owned by their astronaut', function () use (&$consultation) {
    $service = new ConsultationService(new NotConfiguredProvider());
    $user = $consultation['user'];

    $list = (new ConversationService())->list($user['id']);
    same(2, count($list), 'two consultations listed');
    same($consultation['general_id'], $service->open($user, 'general')['conversation']['id'], 'General Doctor reopens the same consultation');

    (new ConversationService())->close($user['id'], $consultation['general_id']);
    throws(fn () => $service->sendMessage($user, $consultation['general_id'], 'hello'), ApiException::class, 'message to a closed consultation');
    check($service->open($user, 'general')['conversation']['id'] !== $consultation['general_id'], 'a new General Doctor consultation starts after closing');

    $intruder = consultation_user('Intruder Astronaut', 'bidmc_90');
    throws(fn () => $service->load($intruder, $consultation['respiratory_id']), ApiException::class, 'reading another astronaut\'s consultation');
    throws(fn () => $service->sendMessage($intruder, $consultation['respiratory_id'], 'hi'), ApiException::class, 'writing to another astronaut\'s consultation');
    throws(fn () => $service->open($intruder, 'respiratory', $consultation['general_id']), ApiException::class, 'referral from another astronaut\'s consultation');
});

test('no health data: nothing is invented', function () {
    $user = consultation_user('No Data Astronaut', null);
    $service = new ConsultationService(new NotConfiguredProvider());
    $open = $service->open($user, 'general');
    // "nauseous" maps only to the General Doctor (a non-routing bucket), so with no data there is no routing.
    $payload = $service->sendMessage($user, $open['conversation']['id'], 'I feel nauseous.')['messages'][1]['payload'];

    same('none', $payload['data_status'], 'data status');
    same([], array_filter($payload['observations'], fn ($o) => $o['available'] || $o['value'] !== null), 'no available values');
    same(null, $payload['recommendation'], 'no specialist without evidence');
});

test('invalid specialist and empty messages are rejected', function () use (&$consultation) {
    $service = new ConsultationService(new NotConfiguredProvider());
    throws(fn () => $service->open($consultation['user'], 'surgeon'), ApiException::class, 'unknown specialist');
    throws(fn () => $service->sendMessage($consultation['user'], $consultation['respiratory_id'], "   \n "), ApiException::class, 'empty message');
});

test('AI provider: success is shown, timeouts and malformed answers become retryable errors', function () use (&$consultation) {
    $user = $consultation['user'];
    $conversationId = $consultation['respiratory_id'];

    $ok = (new ConsultationService(fake_provider('ok')))->sendMessage($user, $conversationId, 'Any update?')['messages'][1];
    same('Test reply from the fake provider.', $ok['body'], 'AI reply used as the message');
    same('success', $ok['payload']['interpretation']['status'], 'interpretation status');
    same('urgent', $ok['payload']['next_step']['level'], 'urgency still decided by backend rules');

    $timeout = (new ConsultationService(fake_provider('timeout')))->sendMessage($user, $conversationId, 'And now?')['messages'][1];
    same('error', $timeout['payload']['interpretation']['status'], 'timeout → error');
    same(true, $timeout['payload']['retryable'], 'retry offered');
    $retried = (new ConsultationService(fake_provider('ok')))->retry($user, $conversationId)['messages'][0];
    same('success', $retried['payload']['interpretation']['status'], 'retry succeeds');

    $malformed = (new ConsultationService(fake_provider('malformed')))->sendMessage($user, $conversationId, 'Hello?')['messages'][1];
    same('error', $malformed['payload']['interpretation']['status'], 'incomplete AI answer rejected');
});

test('prompt has the required sections and only this astronaut\'s data', function () use (&$consultation) {
    $captured = null;
    $other = consultation_user('Other Prompt Astronaut', 'bidmc_90');
    (new ConsultationService(fake_provider('ok', $captured)))->sendMessage($consultation['user'], $consultation['respiratory_id'], 'Thanks.');

    $order = ['### SYSTEM INSTRUCTIONS', '### PATIENT PROFILE', '### HEALTH DATA', '### HISTORICAL TRENDS', '### CURRENT CONVERSATION', '### PREVIOUS RELEVANT CONVERSATIONS', '### TASK'];
    $text = $captured['system'] . "\n" . $captured['prompt'];
    $positions = array_map(fn ($heading) => strpos($text, $heading), $order);
    check(!in_array(false, $positions, true), 'all sections present');
    $sorted = $positions;
    sort($sorted);
    same($sorted, $positions, 'sections in the required order');
    check(!str_contains($text, 'T-' . $other['id'] . '"'), 'no other astronaut\'s ID in the prompt');
    check(str_contains($text, 'Never invent'), 'no-invention instruction present');
});

test('AI health analysis without a provider produces no output', function () {
    $user = make_user('Analysis Astronaut', 'astronaut', 'bidmc_90');
    $analysis = (new AIService(new NotConfiguredProvider()))->analyzeHealth($user['id']);
    same('not_configured', $analysis['status'], 'health analysis status');
    same([], $analysis['output'], 'no generated output');
});

test('AI agents reject malformed provider answers', function () {
    $provider = new class implements AIProvider {
        public function name(): string { return 'fake'; }
        public function isConfigured(): bool { return true; }
        public function run(string $task, array $input): AIResult { return new AIResult('success', ['findings' => 'x'], null, 'fake'); }
    };
    $result = (new CardiovascularAgent($provider))->run(['measurements' => []]);
    same('error', $result->status, 'incomplete answer becomes an error');
});

test('medical API refuses to run without configuration', function () {
    $service = new MedicalApiService(['api_key' => '', 'base_url' => '', 'timeout_seconds' => 5]);
    same(false, $service->isConfigured(), 'configured');
    throws(fn () => $service->request('GET', '/status'), MedicalApiException::class, 'request');
});

test('password policy', function () {
    check(password_problem('short1') !== null, 'too short accepted');
    check(password_problem('onlyletters') !== null, 'no digit accepted');
    check(password_problem('orbit2026pass') === null, 'good password refused');
});

// ---------- Daily check-in ----------

function checkin_day(string $date, float $sleep, int $fatigue, int $exercise, array $symptoms = ['none']): array
{
    return ['date' => $date, 'sleep_hours' => $sleep, 'sleep_quality' => 3, 'fatigue_level' => $fatigue, 'resistance_minutes' => $exercise,
        'aerobic_minutes' => 0, 'exercise_minutes' => $exercise, 'radiation_dose_msv' => null, 'notes' => null, 'symptoms' => $symptoms, 'saved_at' => null];
}

test('check-in rules: single day, 3-day patterns, and a missing day breaks a pattern', function () {
    $analysis = new CheckinAnalysis(checkin_config());
    $keys = fn (array $insights) => array_column($insights, 'key');

    $good = $analysis->insights(['2026-11-11' => checkin_day('2026-11-11', 7.5, 2, 95)], null, '2026-11-11');
    same(['all_clear'], $keys($good), 'nothing triggered');

    $today = $analysis->insights(['2026-11-11' => checkin_day('2026-11-11', 5, 6, 30, ['cold_sores', 'vision_changes'])], null, '2026-11-11');
    foreach (['fatigue_severe', 'sleep_short', 'exercise_below_target', 'symptom_cold_sores', 'symptom_vision'] as $expected) {
        check(in_array($expected, $keys($today), true), "expected {$expected}");
    }
    same('urgent', $today[0]['level'], 'urgent insights first');

    $three = [];
    foreach (['2026-11-11', '2026-11-10', '2026-11-09'] as $d) {
        $three[$d] = checkin_day($d, 5.5, 4, 20, ['cold_sores']);
    }
    $patterns = $keys($analysis->insights($three, null, '2026-11-11'));
    foreach (['sleep_pattern', 'fatigue_pattern', 'exercise_pattern', 'recurring_cold_sores'] as $expected) {
        check(in_array($expected, $patterns, true), "expected {$expected}");
    }

    unset($three['2026-11-10']);
    $broken = $keys($analysis->insights($three, null, '2026-11-11'));
    check(!in_array('sleep_pattern', $broken, true), 'a missing day must break the pattern (no assumptions)');
});

test('PHQ-4 screening flags follow the published cut-offs', function () {
    $analysis = new CheckinAnalysis(checkin_config());
    $severe = $analysis->insights([], ['total_score' => 10, 'depression_positive' => true, 'anxiety_positive' => true], '2026-11-11');
    same('wellbeing_severe', $severe[0]['key'], 'total ≥ 9');
    $positive = $analysis->insights([], ['total_score' => 4, 'depression_positive' => false, 'anxiety_positive' => true], '2026-11-11');
    same('wellbeing_positive', $positive[0]['key'], 'GAD-2 ≥ 3');
    same([], $analysis->insights([], ['total_score' => 2, 'depression_positive' => false, 'anxiety_positive' => false], '2026-11-11'), 'normal');
});

test('check-in saves, updates the same mission day, scores PHQ-4 and rejects bad input', function () {
    $user = make_user('Checkin Astronaut', 'astronaut', null);
    $service = new CheckinService();
    $valid = ['sleep_hours' => 6.5, 'sleep_quality' => 4, 'fatigue_level' => 3, 'resistance_minutes' => 40, 'aerobic_minutes' => 50,
        'symptoms' => ['none'], 'radiation_dose_msv' => '', 'notes' => '', 'wellbeing' => [1 => 0, 2 => 1, 3 => 2, 4 => 1]];

    $first = $service->save($user['id'], $valid);
    same(90, $first['checkin']['exercise_minutes'], 'stored exercise');
    same(['none'], $first['checkin']['symptoms'], 'stored symptoms');
    same(null, $first['checkin']['radiation_dose_msv'], 'empty radiation stays empty');
    same(1, $first['wellbeing']['last']['depression_score'], 'PHQ-2 = items 1+2');
    same(3, $first['wellbeing']['last']['anxiety_score'], 'GAD-2 = items 3+4');
    same(true, $first['wellbeing']['last']['anxiety_positive'], 'GAD-2 ≥ 3 positive');
    same('mild', $first['wellbeing']['last']['band'], 'total 4 → mild');
    same(false, $first['wellbeing']['due'], 'not due again right after');

    $updated = $service->save($user['id'], ['sleep_hours' => 7] + $valid);
    same(7.0, $updated['checkin']['sleep_hours'], 'same day updates');
    same(1, (int) db()->query("SELECT COUNT(*) FROM daily_checkins WHERE user_id = {$user['id']}")->fetchColumn(), 'still one row for the day');

    throws(fn () => $service->save($user['id'], ['sleep_hours' => 30] + $valid), ApiException::class, 'sleep over 24 h');
    throws(fn () => $service->save($user['id'], ['symptoms' => ['none', 'headache']] + $valid), ApiException::class, '"None" with a symptom');
    throws(fn () => $service->save($user['id'], ['symptoms' => ['telepathy']] + $valid), ApiException::class, 'unknown symptom');
    throws(fn () => $service->save($user['id'], ['wellbeing' => [1 => 0, 2 => 1]] + $valid), ApiException::class, 'partial PHQ-4');
    throws(fn () => $service->save($user['id'], ['fatigue_level' => 9] + $valid), ApiException::class, 'fatigue outside 1–7');

    $trends = $updated['trends'];
    same(7, count($trends), 'seven days');
    same(6, count(array_filter($trends, fn ($d) => $d['sleep_hours'] === null)), 'days without a check-in stay empty');
});

test('the AI sees the astronaut\'s own check-ins (and only theirs)', function () {
    $user = consultation_user('Checkin AI Astronaut', 'bidmc_90');
    (new CheckinService())->save($user['id'], ['sleep_hours' => 5, 'sleep_quality' => 2, 'fatigue_level' => 5, 'resistance_minutes' => 10,
        'aerobic_minutes' => 0, 'symptoms' => ['dizziness'], 'radiation_dose_msv' => '', 'notes' => '']);
    (new AlertnessService())->save($user['id'], ['reaction_times' => array_fill(0, 40, 300), 'anticipations' => 0, 'duration_seconds' => 180]);

    $captured = null;
    $service = new ConsultationService(fake_provider('ok', $captured));
    $open = $service->open($user, 'general');
    $reply = $service->sendMessage($user, $open['conversation']['id'], 'I feel a bit off today.')['messages'][1];

    check(str_contains(implode(' ', $reply['payload']['self_reported']), '5 h sleep'), 'self-reported sleep shown in the reply');
    check(str_contains($captured['prompt'], 'self_reported_daily_checkins'), 'check-ins included in the AI prompt');
    check(str_contains($captured['prompt'], 'Moderately tired'), 'fatigue description in the prompt');
    check(str_contains(implode(' ', $reply['payload']['self_reported']), 'Alertness Check: median reaction time 300'), 'latest alertness test shown in the reply');
    check(str_contains($captured['prompt'], 'latest_alertness_test'), 'alertness test included in the AI prompt');
});

// ---------- Alertness Check (PVT-B) ----------

test('PVT-B scoring follows the published rules (lapse ≥ 355 ms, false start < 100 ms)', function () {
    $service = new AlertnessService();
    // 250 ok · 400 lapse · 50 false start · 10000 no response (lapse) · 300 ok · 355 lapse (boundary)
    $score = $service->score([250, 400, 50, 10000, 300, 355], 2);
    same(6, $score['stimuli'], 'stimuli');
    same(4, $score['valid_responses'], 'valid responses');
    same(3, $score['lapses'], 'lapses (400, 355 and the unanswered number)');
    same(3, $score['false_starts'], 'false starts (2 without a number + 1 under 100 ms)');
    same(327.5, $score['median_rt_ms'], 'median of 250, 300, 355, 400');
    same(250.0, $score['fastest10_rt_ms'], 'fastest 10 %');
    same(null, $service->score([20, 10000], 0)['median_rt_ms'], 'no valid response → no median');
});

test('alertness results are saved, compared with the astronaut\'s own baseline, and validated', function () {
    $user = make_user('Alertness Astronaut', 'astronaut');
    $service = new AlertnessService();
    $test = fn (int $rt, ?array $camera = null) => ['reaction_times' => array_fill(0, 40, $rt), 'anticipations' => 0, 'duration_seconds' => 180] + ($camera ? ['camera' => $camera] : []);

    $first = $service->save($user['id'], $test(250));
    same('building', $first['status']['level'], 'no baseline after the first test');
    $service->save($user['id'], $test(252));
    $service->save($user['id'], $test(248));
    $normal = $service->save($user['id'], $test(255));
    same('normal', $normal['status']['level'], 'similar to usual');
    same(250.0, $normal['baseline']['median_rt_ms'], 'baseline = median of the earlier tests');

    $slow = $service->save($user['id'], $test(330, ['face_seconds' => 170, 'blinks' => 34, 'closed_seconds' => 8.5]));
    same('much_slower', $slow['status']['level'], '32 % slower than usual');
    same(12.0, $slow['latest']['blinks_per_minute'], 'blinks per minute from face time');
    same(5.0, $slow['latest']['perclos_pct'], 'PERCLOS from eyes-closed time');

    $short = $service->save($user['id'], $test(250, ['face_seconds' => 20, 'blinks' => 5, 'closed_seconds' => 1]));
    same(null, $short['latest']['blinks_per_minute'], 'too little camera time: no value is claimed');
    check(count(array_filter($short['history'], fn ($d) => $d['median_rt_ms'] !== null)) === 1, 'one day with tests in the 7-day history');

    throws(fn () => $service->save($user['id'], ['reaction_times' => [250], 'anticipations' => 0, 'duration_seconds' => 60]), ApiException::class, 'partial test refused');
    throws(fn () => $service->save($user['id'], ['reaction_times' => ['fast'], 'anticipations' => 0, 'duration_seconds' => 180]), ApiException::class, 'non-numeric times refused');
    throws(fn () => $service->save($user['id'], $test(250, ['face_seconds' => 10, 'blinks' => 1, 'closed_seconds' => 50])), ApiException::class, 'impossible camera summary refused');
});

// ---------- Crew emergency alarm ----------

test('crew emergency: rings for crewmates only, shows who responds, stops when resolved', function () use ($missionMars, $missionLunar) {
    $patient = consultation_user('Danger Astronaut', null);   // no recorded data: only the alert below
    $crewmate = make_user('Crewmate Astronaut', 'astronaut') + ['role' => 'astronaut'];
    (new MissionService())->join($crewmate['id'], $missionMars, 'astronaut');
    $outsider = make_user('Lunar Astronaut', 'astronaut') + ['role' => 'astronaut'];
    (new MissionService())->join($outsider['id'], $missionLunar, 'astronaut');
    $emergencies = new EmergencyService();

    // A critical SpO2 alert of the patient.
    db()->prepare("INSERT INTO alerts (user_id, mission_id, metric, observed_value, unit, rule, threshold_version, severity, title, explanation, recommended_action,
        source_measurement_id, first_measurement_id, occurred_at)
        SELECT ?, ?, 'spo2', 84, '%', 'r', 'v', 'RED', 't', 'e', 'a', id, id, UTC_TIMESTAMP() FROM raw_measurements LIMIT 1")->execute([$patient['id'], $missionMars]);
    $alertId = (int) db()->lastInsertId();
    $emergencies->raiseForAlert($patient['id'], $missionMars, $alertId);

    // Other tests' astronauts share this mission (and may have their own alarms): look at ours only.
    $ours = fn (array $list) => array_values(array_filter($list, fn ($e) => $e['astronaut']['id'] === $patient['id']));
    $seen = $ours($emergencies->forViewer($crewmate)['crew']);
    same(1, count($seen), 'crewmate is alarmed');
    same('Danger Astronaut', $seen[0]['astronaut']['name'], 'name is shared');
    same('Low blood oxygen (SpO₂)', $seen[0]['problem'], 'problem in plain words');
    same('Emergency. Danger Astronaut: Low blood oxygen (SpO₂).', $seen[0]['speech'], 'spoken sentence');
    same([], $emergencies->forViewer($crewmate)['mine'], 'not the crewmate\'s own emergency');
    same([], $ours($emergencies->forViewer($outsider)['crew']), 'another mission hears nothing');
    same(1, count($emergencies->forViewer($patient)['mine']), 'the patient sees that the crew was alerted');
    same([], $ours($emergencies->forViewer($patient)['crew']), 'the patient is not alarmed about themself');
    $arabic = in_language('ar', fn () => $ours($emergencies->forViewer($crewmate)['crew'])[0]['speech']);
    same('حالة طارئة. Danger Astronaut: انخفاض تشبّع الأكسجين (SpO₂).', $arabic, 'spoken in the listener\'s language');

    $id = $seen[0]['id'];
    same('on_my_way', $ours($emergencies->respond($crewmate, $id, 'on_my_way')['crew'])[0]['my_response'], 'answer stops the alarm for this member');
    same('Crewmate Astronaut', $emergencies->forViewer($patient)['mine'][0]['responses'][0]['name'], 'the patient sees who is coming');
    throws(fn () => $emergencies->respond($outsider, $id, 'seen'), ApiException::class, 'outsiders cannot answer');
    throws(fn () => $emergencies->respond($crewmate, $id, 'dance'), ApiException::class, 'unknown answer refused');

    $emergencies->resolveAlert($alertId);
    check($ours($emergencies->forViewer($crewmate)['crew'])[0]['resolved'], 'resolved when the alert resolves');
});

test('crew emergency from the AI chat: only dangerous symptoms in the latest message, no repeats', function () {
    $patient = consultation_user('Chest Pain Astronaut', 'bidmc_90');
    $service = new ConsultationService(fake_provider('ok'));
    $id = $service->open($patient, 'general')['conversation']['id'];
    // Symptom alarms only (this astronaut's recorded data also has a real critical SpO2 drop → a vital alarm).
    $count = fn () => (int) db()->query("SELECT COUNT(*) FROM crew_emergencies WHERE astronaut_id = {$patient['id']} AND kind = 'symptom'")->fetchColumn();

    $reply = $service->sendMessage($patient, $id, 'I have a headache.')['messages'][1];
    same(0, $count(), 'a headache is not an emergency');
    same(false, $reply['payload']['crew_alerted'], 'no alert flag');

    $reply = $service->sendMessage($patient, $id, 'Now I have chest pain.')['messages'][1];
    same(1, $count(), 'chest pain alarms the crew');
    same(true, $reply['payload']['crew_alerted'], 'the astronaut is told');

    $service->sendMessage($patient, $id, 'Still chest pain.');
    same(1, $count(), 'the same symptom does not ring again within 30 minutes');
    $service->sendMessage($patient, $id, 'Thanks, I am resting now.');
    same(1, $count(), 'an old message is not re-alarmed');
    $service->sendMessage($patient, $id, 'مش قادر اتنفس');
    same(2, $count(), 'Arabic "can\'t breathe" alarms the crew');
});

// ---------- Admin missions and crew chat ----------

test('admins create their own missions and choose the crew; an astronaut is in one mission only', function () {
    $missions = new MissionService();
    $admin = make_user('Creator Admin', 'mission_admin', null, true);
    $otherAdmin = make_user('Other Admin', 'mission_admin', null, true);
    $pending = make_user('Pending Admin', 'mission_admin');
    $astronaut = make_user('Free Astronaut', 'astronaut');

    $mission = $missions->create($admin, ['name' => 'Artemis Crew 3', 'description' => 'Lunar orbit']);
    check(str_starts_with($mission['code'], 'ARTEMISC-'), 'code made from the name');
    check($mission['can_manage'], 'the creator manages it');
    throws(fn () => $missions->create($pending, ['name' => 'Nope']), ApiException::class, 'unapproved admins cannot create missions');
    throws(fn () => $missions->create($admin, ['name' => '']), ApiException::class, 'name required');

    check(in_array($astronaut['id'], array_column($missions->unassignedAstronauts($admin, 'Free'), 'id'), true), 'astronaut listed as available');
    throws(fn () => $missions->addAstronaut($otherAdmin, $mission['id'], $astronaut['id']), ApiException::class, 'only the mission\'s admin adds crew');
    $members = $missions->addAstronaut($admin, $mission['id'], $astronaut['id']);
    check(in_array($astronaut['id'], array_column($members, 'id'), true), 'astronaut added');
    check(!in_array($astronaut['id'], array_column($missions->unassignedAstronauts($admin), 'id'), true), 'no longer listed as available');

    $second = $missions->create($otherAdmin, ['name' => 'Gateway']);
    throws(fn () => $missions->addAstronaut($otherAdmin, $second['id'], $astronaut['id']), ApiException::class, 'one mission per astronaut');

    $chat = (new CrewChatService())->chat($astronaut + ['role' => 'astronaut'], $mission['id']);
    same('system', $chat['messages'][0]['kind'], 'crew change posted in the chat');
    same('Free Astronaut', $chat['messages'][0]['subject'], 'names who joined');

    $missions->removeAstronaut($admin, $mission['id'], $astronaut['id']);
    throws(fn () => (new CrewChatService())->chat($astronaut + ['role' => 'astronaut'], $mission['id']), ApiException::class, 'removed astronauts lose access');
});

test('My Missions board: own missions only, crew table with check-in, emergency and attention count', function () {
    $missions = new MissionService();
    $board = new MissionBoardService($missions);
    $admin = make_user('Board Admin', 'mission_admin', null, true);
    $otherAdmin = make_user('Board Other', 'mission_admin', null, true);
    $mission = $missions->create($admin, ['name' => 'Board Mission', 'status' => 'planning']);
    $missions->create($otherAdmin, ['name' => 'Not Mine']);
    $done = make_user('Dana Done', 'astronaut');
    $sick = make_user('Sami Sick', 'astronaut');
    $missions->addAstronaut($admin, $mission['id'], $done['id']);
    $missions->addAstronaut($admin, $mission['id'], $sick['id']);

    db()->prepare('INSERT INTO daily_checkins (user_id, checkin_date, sleep_hours, sleep_quality, fatigue_level) VALUES (?, ?, 7, 4, 2)')
        ->execute([$done['id'], (new CheckinService())->missionDay()]);
    (new EmergencyService($missions))->raiseForSymptoms($sick['id'], ['chest_pain'], 'my chest hurts');

    $list = $board->missions($admin);
    same(['Board Mission'], array_column($list, 'name'), 'only the admin\'s own missions');
    same(2, $list[0]['astronauts'], 'crew size');
    same(1, $list[0]['emergencies'], 'open emergencies');

    $view = $board->mission($admin, $mission['id']);
    same(['astronauts' => 2, 'attention' => 1, 'checkins_today' => 1, 'active_alerts' => 0], $view['stats'], 'summary tiles');
    $rows = array_column($view['crew'], null, 'name');
    check($rows['Dana Done']['checkin_at'] !== null && !$rows['Dana Done']['emergency'], 'check-in done today');
    check($rows['Sami Sick']['checkin_at'] === null && $rows['Sami Sick']['emergency'], 'emergency shown, no check-in');
    same('NO_DATA', $rows['Sami Sick']['health'], 'no data source → no health status (not invented)');
    same(null, $rows['Sami Sick']['alertness'], 'no alertness test yet');
    throws(fn () => $board->mission($otherAdmin, $mission['id']), ApiException::class, 'other admins cannot open it');
});

test('vest device: token, live stream, duplicates, late/burst readings, critical alert → crew alarm → recovery', function () {
    $missions = new MissionService();
    $admin = make_user('Vest Admin', 'mission_admin', null, true);
    $mission = $missions->create($admin, ['name' => 'Vest Mission']);
    $wearer = make_user('Vest Wearer', 'astronaut');
    $buddy = make_user('Vest Buddy', 'astronaut') + ['role' => 'astronaut'];
    $missions->addAstronaut($admin, $mission['id'], $wearer['id']);
    $missions->addAstronaut($admin, $mission['id'], $buddy['id']);

    $now = microtime(true);    // fake clock moved forward by hand (pages advance with the real clock, which stays behind it)
    $devices = new DeviceService(clock: function () use (&$now): float { return $now; });
    $token = $devices->register($wearer['id'], 'vest_test_01')['token'];
    throws(fn () => $devices->authenticate('wrong-token'), ApiException::class, 'unknown token refused');
    $device = $devices->authenticate($token);
    same($wearer['id'], (int) $device['user_id'], 'token → its astronaut');

    $reading = fn (string $id, float $spo2, array $extra = []) => $extra + ['client_reading_id' => $id, 'astronaut_id' => 'T-' . $wearer['id'],
        'heart_rate_bpm' => 82, 'spo2_percent' => $spo2, 'respiratory_rate_bpm' => 16, 'bp_systolic_mmhg' => 120, 'co2_ppm' => 700, 'data_source' => 'wokwi_simulated'];

    $first = $devices->ingest($device, $reading('r1', 97));
    same('analysed', $first['status'], 'first reading analysed');
    same(['heart_rate', 'spo2', 'respiratory_rate'], $first['analysed'], 'only HR, SpO2 and RR are analysed');
    check(in_array('bp_systolic_mmhg', $first['stored_not_analysed'], true) && in_array('co2_ppm', $first['stored_not_analysed'], true), 'BP and CO2 kept, not analysed');
    same('duplicate', $devices->ingest($device, $reading('r1', 97))['status'], 'a retried reading is not stored twice');
    $now += 1;
    same('late', $devices->ingest($device, $reading('r2', 97))['status'], 'a burst from the queue is not analysed');
    same('late', $devices->ingest($device, $reading('r3', 97, ['timestamp' => gmdate('Y-m-d\TH:i:s\Z', (int) $now - 120)]))['status'], 'a minutes-old reading is not analysed');
    throws(fn () => $devices->ingest($device, $reading('r4', 97, ['astronaut_id' => 'SOMEONE-ELSE'])), ApiException::class, 'reading for another astronaut refused');
    $bad = $devices->ingest($device, ['client_reading_id' => 'r5', 'spo2_percent' => 150]);
    same('no_vitals', $bad['status'], 'impossible values are not analysed');
    same(['spo2_percent'], $bad['rejected'], 'impossible SpO2 rejected');

    $current = (new HealthService())->current($wearer['id']);
    same('live_device_simulated', $current['replay']['mode'], 'dashboard knows it is the vest simulation');
    same('vest_test_01', $current['replay']['device_id'], 'device named');

    // Two readings 5 s apart below 90 % = 10 s critical → RED alert → the crew is alarmed.
    foreach (['r6' => 86, 'r7' => 85] as $id => $spo2) {
        $now += 5;
        same('analysed', $devices->ingest($device, $reading($id, $spo2))['status'], "reading {$id} analysed");
    }
    $alert = db()->prepare("SELECT id, severity, status FROM alerts WHERE user_id = ? AND metric = 'spo2' ORDER BY id DESC LIMIT 1");
    $alert->execute([$wearer['id']]);
    $row = $alert->fetch();
    same('RED', $row['severity'] ?? null, 'critical SpO2 alert from the device');
    $processed = db()->prepare('SELECT COUNT(*) FROM processed_measurements WHERE user_id = ?');
    $processed->execute([$wearer['id']]);
    same(9, (int) $processed->fetchColumn(), 'every analysed reading processed right away (3 readings × 3 vitals)');
    $alarm = (new EmergencyService($missions))->forViewer($buddy);
    check(count(array_filter($alarm['crew'], fn ($e) => $e['astronaut']['id'] === $wearer['id'])) === 1, 'crewmate is alarmed');

    foreach (['r8' => 97, 'r9' => 98] as $id => $spo2) {
        $now += 5;
        $devices->ingest($device, $reading($id, $spo2));
    }
    $alert->execute([$wearer['id']]);
    same('resolved', $alert->fetch()['status'], 'back in range for 10 s → alert resolved');
    $cards = db()->prepare("SELECT kind FROM mission_messages WHERE mission_id = ? AND kind IN ('emergency', 'recovery') ORDER BY id");
    $cards->execute([$mission['id']]);
    same(['emergency', 'recovery'], $cards->fetchAll(PDO::FETCH_COLUMN), 'emergency card, then recovery card in the crew chat');

    // Recorded data keeps its 1-second rules: a 5-second gap there breaks the streak.
    same('1.00', db()->query("SELECT sample_interval_seconds FROM source_records WHERE source_file NOT LIKE 'device%' LIMIT 1")->fetchColumn() ?: '1.00', 'recorded data stays 1 Hz');
});

test('crew chat: messages, emergency card with crew report, recovery card, seen by, status bar', function () {
    $missions = new MissionService();
    $admin = make_user('Chat Admin', 'mission_admin', null, true);
    $mission = $missions->create($admin, ['name' => 'Chat Mission']);
    $patient = make_user('Nour Patient', 'astronaut') + ['role' => 'astronaut'];
    $buddy = make_user('Zaid Buddy', 'astronaut') + ['role' => 'astronaut'];
    $missions->addAstronaut($admin, $mission['id'], $patient['id']);
    $missions->addAstronaut($admin, $mission['id'], $buddy['id']);
    $chat = new CrewChatService();

    $sent = $chat->send($buddy, $mission['id'], 'Good morning crew', 0);
    $text = array_values(array_filter($sent['messages'], fn ($m) => $m['kind'] === 'text'))[0];
    same('Good morning crew', $text['body'], 'text message');

    // The online AI writes the crew report (fake provider, both languages).
    $ai = new class implements AIProvider {
        public array $input = [];
        public function name(): string { return 'fake'; }
        public function isConfigured(): bool { return true; }
        public function run(string $task, array $input): AIResult
        {
            $this->input = $input;
            return new AIResult('success', ['situation_en' => 'Short of breath now, oxygen low.', 'crew_actions_en' => ['Sit them upright', 'Call the medical officer'],
                'situation_ar' => 'يعاني الآن من ضيق تنفس.', 'crew_actions_ar' => ['ساعده على الجلوس', 'اتصل بطبيب المهمة']], null, 'fake');
        }
    };
    $emergencies = new EmergencyService($missions, $ai);
    $emergencies->raiseForSymptoms($patient['id'], ['cannot_breathe', 'chest_pain'], 'I cannot breathe and my chest hurts');

    $view = (new CrewChatService($missions, $emergencies))->chat($buddy, $mission['id']);
    $card = array_values(array_filter($view['messages'], fn ($m) => $m['kind'] === 'emergency'))[0];
    same($patient['id'], $card['author']['id'], 'posted from the patient\'s account');
    same(2, count($card['problems']), 'one card for both symptoms');
    same('ai', $card['report']['source'], 'AI crew report');
    same(['Sit them upright', 'Call the medical officer'], $card['report']['crew_actions'], 'what the crew should do');
    same(['I cannot breathe and my chest hurts'], $ai->input['astronaut_words_now'], 'the report uses what they feel now');
    check(!str_contains(json_encode($ai->input), 'Nour'), 'the AI never gets the name');
    $arabic = in_language('ar', fn () => (new CrewChatService($missions, $emergencies))->chat($buddy, $mission['id']));
    $arabicCard = array_values(array_filter($arabic['messages'], fn ($m) => $m['kind'] === 'emergency'))[0];
    same('يعاني الآن من ضيق تنفس.', $arabicCard['report']['situation'], 'report in the reader\'s language');

    $bar = array_column($view['crew'], null, 'id');
    check($bar[$patient['id']]['emergency'], 'status bar shows the emergency');
    check($bar[$buddy['id']]['online'], 'the reader is online');
    check(!array_key_exists('health', $bar[$patient['id']]), 'no medical details in the bar');

    // Seen by
    $last = max(array_column($view['messages'], 'id'));
    $reads = $chat->markRead($patient, $mission['id'], $last + 100)['reads'];
    same($last, array_column($reads, 'last_read', 'user_id')[$patient['id']], 'read pointer stops at the newest message');

    // Without the AI: OrbitCare's own first-response steps.
    $rules = (new EmergencyService($missions, new NotConfiguredProvider()))->load([$card['emergencies'][0]['id']], $buddy['id']);
    same('ai', $rules[0]['report']['source'], 'the stored AI report is reused (no new AI call needed)');
    db()->exec("UPDATE crew_emergencies SET report = NULL WHERE astronaut_id = {$patient['id']}");
    $rules = (new EmergencyService($missions, new NotConfiguredProvider()))->load([$card['emergencies'][0]['id']], $buddy['id']);
    same('rules', $rules[0]['report']['source'], 'rule-based steps without the AI');
    same(t('emergency.action.call_officer'), $rules[0]['report']['crew_actions'][array_key_last($rules[0]['report']['crew_actions']) - 1], 'always: contact the medical officer');
});

// ---------- Online AI (Gemini) and official medical sources — all with fakes, no internet ----------

test('Gemini provider: answers, no link, quota, bad model and malformed replies are all handled', function () {
    $settings = ['api_key' => 'test-key', 'model' => 'test-model', 'base_url' => 'https://example.invalid/v1beta', 'timeout_seconds' => 5];
    $sent = null;
    // (a normal closure, not fn(): fn() would copy $sent instead of sharing it)
    $reply = function (int $status, mixed $body) use (&$sent) {
        return function (string $url, array $headers, string $payload) use ($status, $body, &$sent) {
            $sent = ['url' => $url, 'headers' => $headers, 'payload' => json_decode($payload, true)];
            return ['status' => $status, 'body' => $body, 'error' => $status === 0 ? 'could not resolve host' : ''];
        };
    };
    $answer = json_encode(['candidates' => [['content' => ['parts' => [['text' => "```json\n{\"summary\": \"ok\", \"concern_level\": \"low\", \"missing_information\": []}\n```"]]], 'finishReason' => 'STOP']]]);

    $ok = (new GeminiProvider($settings, $reply(200, $answer)))->run('general_health', ['alert' => ['metric' => 'spo2'], 'instructions' => 'x', 'output_fields' => ['summary', 'concern_level', 'missing_information']]);
    same('success', $ok->status, 'success');
    same('ok', $ok->output['summary'], 'parsed JSON (code fence removed)');
    check(str_contains($sent['url'], '/models/test-model:generateContent'), 'model in the URL');
    check(in_array('x-goog-api-key: test-key', $sent['headers'], true), 'key sent in a header, not the URL');
    check(!str_contains($sent['url'], 'test-key'), 'key never in the URL');
    check(!str_contains(json_encode($sent['payload']), 'output_fields'), 'internal fields not sent as data');

    same('error', (new GeminiProvider($settings, $reply(0, false)))->run('x', [])->status, 'no link → error (rule-based fallback)');
    same(t('ai.error.offline'), (new GeminiProvider($settings, $reply(0, false)))->run('x', [])->message, 'no-link message');
    same(t('ai.error.quota'), (new GeminiProvider($settings, $reply(429, '{}')))->run('x', [])->message, 'quota message');
    same(t('ai.error.model'), (new GeminiProvider($settings, $reply(404, '{}')))->run('x', [])->message, 'unknown model message');
    $missing = json_encode(['candidates' => [['content' => ['parts' => [['text' => '{"summary": "only this"}']]]]]]);
    same('error', (new GeminiProvider($settings, $reply(200, $missing)))->run('x', ['output_fields' => ['summary', 'concern_level']])->status, 'missing fields rejected');
    same('error', (new GeminiProvider($settings, $reply(200, json_encode(['candidates' => [['finishReason' => 'SAFETY']]]))))->run('x', [])->status, 'blocked answer rejected');
    same('not_configured', (new GeminiProvider(['api_key' => '', 'model' => 'm'] + $settings))->run('x', [])->status, 'no key → not configured');
});

test('the AI never receives names or astronaut IDs', function () use (&$consultation) {
    $user = consultation_user('Private Name Astronaut', 'bidmc_90');
    $captured = null;
    $service = new ConsultationService(fake_provider('ok', $captured));
    $open = $service->open($user, 'general');
    $service->sendMessage($user, $open['conversation']['id'], 'I feel dizzy.');
    $code = db()->query("SELECT astronaut_code FROM astronauts WHERE user_id = {$user['id']}")->fetchColumn();
    check(!str_contains($captured['prompt'], 'Private Name Astronaut'), 'name not in the prompt');
    check($code === false || $code === null || !str_contains($captured['prompt'], (string) $code), 'astronaut ID not in the prompt');
});

test('medical sources: MedlinePlus pages, FDA label matches, cache, and no waiting when the link is down', function () {
    $xml = '<?xml version="1.0"?><nlmSearchResult><list><document rank="0" url="https://medlineplus.gov/dizzinessandvertigo.html">'
        . '<content name="title">&lt;span class="qt0"&gt;Dizziness&lt;/span&gt; and Vertigo</content>'
        . '<content name="FullSummary">What is it?&lt;p&gt;Dizziness can make you feel lightheaded.&lt;/p&gt;</content></document></list></nlmSearchResult>';
    $label = json_encode(['results' => [['set_id' => 'abc-123', 'openfda' => ['generic_name' => ['METOPROLOL']], 'adverse_reactions' => ['Tiredness and dizziness have occurred in about 10 of 100 patients.']]]]);
    $calls = 0;
    $online = function (string $url) use ($xml, $label, &$calls) {
        $calls++;
        return str_contains($url, 'nlm.nih.gov') ? ['status' => 200, 'body' => $xml] : ['status' => 200, 'body' => $label];
    };
    $settings = ['enabled' => true, 'openfda_api_key' => '', 'timeout_seconds' => 1, 'cache_hours' => 24];
    db()->exec('DELETE FROM medical_source_cache');
    MedicalSourcesService::resetLinkState();
    $sources = new MedicalSourcesService(null, $settings, $online);

    $references = $sources->references(['dizziness']);
    same('Dizziness and Vertigo', $references[0]['title'], 'title without markup');
    same('What is it? Dizziness can make you feel lightheaded.', $references[0]['summary'], 'summary keeps sentence breaks');

    $user = make_user('Medication Astronaut', 'astronaut');
    db()->prepare('INSERT INTO astronaut_medications (user_id, name, purpose) VALUES (?, ?, ?)')->execute([$user['id'], 'Metoprolol', 'blood pressure']);
    $matches = $sources->medicationMatches($user['id'], ['dizziness', 'cough']);
    same(1, count($matches), 'only dizziness is in the label');
    same('https://dailymed.nlm.nih.gov/dailymed/drugInfo.cfm?setid=abc-123', $matches[0]['url'], 'DailyMed link');

    $before = $calls;
    $sources->references(['dizziness']);
    same($before, $calls, 'second lookup comes from the cache');

    // Link down: the first failure stops further calls in the same request; cached answers still work.
    $offlineCalls = 0;
    $offline = function () use (&$offlineCalls) { $offlineCalls++; return ['status' => 0, 'body' => false]; };
    MedicalSourcesService::resetLinkState();
    $down = new MedicalSourcesService(null, $settings, $offline);
    same([], $down->references(['headache', 'nausea', 'cough']), 'nothing invented when offline');
    same(1, $offlineCalls, 'only one attempt while the link is down');
    same('Dizziness and Vertigo', $down->references(['dizziness'])[0]['title'], 'cached page still shown offline');
    MedicalSourcesService::resetLinkState();
});

// ---------- Arabic / English ----------

function in_language(string $language, callable $body): mixed
{
    language_state($language);
    try {
        return $body();
    } finally {
        language_state('en');
    }
}

test('translations: Arabic and English have the same keys and placeholders, none empty', function () {
    $en = translations('en');
    $ar = translations('ar');
    same([], array_keys(array_diff_key($en, $ar)), 'keys missing in Arabic');
    same([], array_keys(array_diff_key($ar, $en)), 'keys missing in English');
    foreach ($en as $key => $text) {
        check(trim($text) !== '' && trim($ar[$key]) !== '', "empty text for {$key}");
        preg_match_all('/\{(\w+)\}/', $text, $a);
        preg_match_all('/\{(\w+)\}/', $ar[$key], $b);
        sort($a[1]);
        sort($b[1]);
        same($a[1], $b[1], "placeholders of {$key}");
    }
    foreach (glob(__DIR__ . '/../backend/lang/en/*.php') as $file) {
        check(is_file(__DIR__ . '/../backend/lang/ar/' . basename($file)), 'Arabic file for ' . basename($file));
    }
});

test('translations: every key used in the code exists', function () {
    $known = translations('en');
    $root = realpath(__DIR__ . '/..');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $missing = [];
    foreach ($files as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if (!preg_match('/\.(php|js)$/', $path) || preg_match('~/(tests|data|vendor|logs|backend/lang)/~', $path) || str_ends_with($path, 'core/i18n.php')) {
            continue;
        }
        $code = file_get_contents($path);
        preg_match_all("/(?<![\\w>])(?:t|modalText|\\\$validated)\\(\\s*['\"]([a-z_]+\\.[a-zA-Z0-9_.\\/%-]*[a-zA-Z0-9_%])['\"]/", $code, $direct);
        preg_match_all("/\\?\\s*['\"]([a-z_]+\\.[a-z0-9_.]+)['\"]\\s*:\\s*['\"]([a-z_]+\\.[a-z0-9_.]+)['\"]/", $code, $ternary);
        foreach (array_merge($direct[1], $ternary[1], $ternary[2]) as $key) {
            if (!isset($known[$key])) {
                $missing[] = $key . ' (' . basename($path) . ')';
            }
        }
    }
    same([], array_values(array_unique($missing)), 'translation keys used in code');
});

test('validated questionnaires keep their exact English wording', function () {
    $config = checkin_config();
    foreach ($config['fatigue'] as $level => $label) {
        same($label, translate_in('en', "checkin.fatigue.{$level}"), "Samn-Perelli {$level}");
    }
    same($config['phq4']['stem'], translate_in('en', 'checkin.phq.stem'), 'PHQ-4 stem');
    foreach ($config['phq4']['items'] as $item => $text) {
        same($text, translate_in('en', "checkin.phq.item.{$item}"), "PHQ-4 item {$item}");
    }
    foreach ($config['phq4']['options'] as $score => $text) {
        same($text, translate_in('en', "checkin.phq.option.{$score}"), "PHQ-4 option {$score}");
    }
    foreach ($config['sources'] as $key => $source) {
        same($source['label'], translate_in('en', "checkin.source.{$key}"), "source {$key}");
    }
});

test('alerts are stored in English and shown in the reader\'s language', function () {
    $analysis = new HealthAnalysis();
    // English texts are exactly what the alert engine has always stored.
    same('Low blood oxygen (SpO₂)', $analysis->alertTitle('spo2', 'low'), 'English title');
    same('SpO₂ below 90 % for at least 10 s', str_replace('Blood oxygen (SpO₂)', 'SpO₂', $analysis->ruleText('spo2', 'RED', 'low')), 'English rule');
    same('Heart rate back in range for 10 s', $analysis->resolutionNote('heart_rate', 'back_in_range'), 'English note');

    $row = ['metric' => 'spo2', 'threshold_version' => $analysis->version(), 'observed_value' => 88.0, 'severity' => 'RED',
        'title' => $analysis->alertTitle('spo2', 'low'), 'explanation' => '', 'rule' => '', 'recommended_action' => '',
        'resolution_note' => $analysis->resolutionNote('spo2', 'escalated')];
    $arabic = in_language('ar', fn () => (new AlertService())->localize($row));
    same('انخفاض تشبّع الأكسجين (SpO₂)', $arabic['title'], 'Arabic title');
    check(str_contains($arabic['explanation'], '88') && str_contains($arabic['explanation'], '95–100'), 'Arabic explanation keeps the real values');
    same(translate_in('ar', 'alert.note.escalated'), $arabic['resolution_note'], 'Arabic resolution note');

    $english = (new AlertService())->localize($row);
    same($row['title'], $english['title'], 'English unchanged');

    $old = ['threshold_version' => 'old-version'] + $row;
    same($row['title'], in_language('ar', fn () => (new AlertService())->localize($old))['title'], 'alerts from older thresholds keep their stored text');
});

test('Arabic symptoms are detected, negations respected, quotes taken from the original text', function () {
    $detector = new SymptomDetector();
    $found = $detector->detect(['عندي ألم في الصدر ودوخة من الصبح']);
    check(isset($found['chest_pain'], $found['dizziness']), 'chest pain and dizziness found');
    same('ألم في الصدر', $found['chest_pain']['quote'], 'quote from the original spelling');

    $negated = $detector->detect(['ما عندي دوخة بس عندي صداع']);
    check(!isset($negated['dizziness']), 'negated dizziness ignored');
    check(isset($negated['headache']), 'headache found');

    $diacritics = $detector->detect(['أشعرُ بِصُداعٍ شديد']);
    check(isset($diacritics['headache']), 'found despite diacritics');
    same('صُداعٍ', $diacritics['headache']['quote'], 'quote keeps the diacritics');

    same([], array_keys($detector->detect(['لا يوجد ألم في الصدر'])), 'Arabic "there is no" negation');
    check(isset($detector->detect(['I have chest pain'])['chest_pain']), 'English still works');
    same([], array_keys($detector->detect(['no chest pain'])), 'English negation still works');
});

test('API messages and health status follow the interface language', function () {
    same('Please fix the highlighted fields.', ApiException::validation([])->getMessage(), 'English default');
    same(translate_in('ar', 'err.fix_fields'), in_language('ar', fn () => ApiException::validation([])->getMessage()), 'Arabic');
    $status = in_language('ar', fn () => (new HealthAnalysis())->overallStatus(['spo2' => 'RED', 'heart_rate' => 'GREEN'], 0, 0));
    same('RED', $status['level'], 'level is language-independent');
    check(str_contains($status['message'], 'تشبّع الأكسجين'), 'Arabic metric name in the status');
});

// ===================================================================== run
$failed = 0;
foreach ($tests as $name => $body) {
    try {
        $body();
        echo "PASS  {$name}\n";
    } catch (Throwable $error) {
        $failed++;
        echo "FAIL  {$name}\n      " . $error->getMessage() . "\n";
    }
}
echo "\n" . (count($tests) - $failed) . '/' . count($tests) . " tests passed.\n";
exit($failed ? 1 : 0);
