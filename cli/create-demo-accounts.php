<?php
// Creates the demo crew for the hackathon walkthrough (run once, after ingest-bidmc.php):
//   php cli/create-demo-accounts.php
//
// - 3 astronaut accounts in "Mars Expedition", each replaying a documented BIDMC record
//   (facts checked against the loaded data — see docs/DATA_PROVENANCE.md):
//     Astronaut 01 → bidmc_03 (every value inside the monitoring ranges)
//     Astronaut 02 → bidmc_21 (heart rate > 100 bpm from the start, > 120 bpm from second 35:
//                              a YELLOW alert that escalates to RED during the replay)
//     Astronaut 03 → bidmc_32 (SpO2 83–87 % for the whole recording: RED)
// - 1 approved mission admin for "Mars Expedition".
// Passwords are random and printed once. No health values are created — only accounts.
// Age / height / weight are left empty ("Not provided"): we don't invent personal data.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../backend/bootstrap.php';

$mission = db()->query("SELECT id, name FROM missions WHERE code = 'MARS-EXP'")->fetch();
if (!$mission) {
    fwrite(STDERR, "Mission MARS-EXP not found. Load backend/database/seed.sql first.\n");
    exit(1);
}

$accounts = [
    ['Astronaut 01', 'astronaut01@orbitcare.demo', 'astronaut', 'DEMO-AST-01', 'bidmc_03'],
    ['Astronaut 02', 'astronaut02@orbitcare.demo', 'astronaut', 'DEMO-AST-02', 'bidmc_21'],
    ['Astronaut 03', 'astronaut03@orbitcare.demo', 'astronaut', 'DEMO-AST-03', 'bidmc_32'],
    ['Mission Admin', 'admin@orbitcare.demo', 'mission_admin', null, null],
];

$missions = new MissionService();
echo "Demo accounts (passwords shown once — store them somewhere safe):\n\n";

foreach ($accounts as [$name, $email, $role, $code, $record]) {
    $exists = db()->prepare('SELECT id FROM users WHERE email = ?');
    $exists->execute([$email]);
    if ($exists->fetchColumn()) {
        echo "  exists   {$email}\n";
        continue;
    }

    $password = 'Orbit-' . bin2hex(random_bytes(4)) . '-' . random_int(10, 99);
    db_transaction(function (PDO $pdo) use ($name, $email, $role, $code, $record, $password, $mission, $missions) {
        $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, admin_approved_at, profile_completed_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $role === 'mission_admin' ? gmdate('Y-m-d H:i:s') : null]);
        $userId = (int) $pdo->lastInsertId();

        if ($role === 'astronaut') {
            $pdo->prepare('INSERT INTO astronauts (user_id, astronaut_code, data_source_record_id) VALUES (?, ?, ?)')->execute([$userId, $code, $record]);
        } else {
            $pdo->prepare('INSERT INTO admin_profiles (user_id, job_title, organization, requested_mission_id) VALUES (?, ?, ?, ?)')
                ->execute([$userId, 'Mission administrator (demo)', 'OrbitCare demo', $mission['id']]);
        }
        $missions->join($userId, (int) $mission['id'], $role);
    });

    printf("  %-14s %-28s %s%s\n", $name, $email, $password, $record ? "   (replays {$record})" : '');
}

audit('demo.accounts_created', null, 'mission', $mission['id']);
echo "\nAll demo accounts belong to {$mission['name']}.\n";
