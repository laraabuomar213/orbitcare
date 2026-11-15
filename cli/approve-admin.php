<?php
// Approves a pending mission admin. After approval the admin creates their own missions and
// chooses each mission's crew in OrbitCare. Command line only:
//   php cli/approve-admin.php person@example.com [MISSION-CODE]
// The optional mission code also makes them admin of an existing mission (e.g. MARS-EXP).
// Until this runs, an account that signed up as "Admin" has no admin rights.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../backend/bootstrap.php';

$email = strtolower(trim($argv[1] ?? ''));
$missionCode = trim($argv[2] ?? '');
if ($email === '') {
    fwrite(STDERR, "Usage: php cli/approve-admin.php <email> [MISSION-CODE]\n");
    exit(1);
}

$statement = db()->prepare("SELECT id FROM users WHERE email = ? AND role = 'mission_admin'");
$statement->execute([$email]);
$adminId = $statement->fetchColumn();
if (!$adminId) {
    fwrite(STDERR, "No mission admin account found for {$email}.\n");
    exit(1);
}

$missionId = null;
if ($missionCode !== '') {
    $mission = db()->prepare('SELECT id FROM missions WHERE code = ?');
    $mission->execute([$missionCode]);
    $missionId = $mission->fetchColumn() ?: null;
    if (!$missionId) {
        fwrite(STDERR, "Mission {$missionCode} not found.\n");
        exit(1);
    }
}

db()->prepare('UPDATE users SET admin_approved_at = COALESCE(admin_approved_at, UTC_TIMESTAMP()) WHERE id = ?')->execute([$adminId]);
if ($missionId) {
    (new MissionService())->join((int) $adminId, (int) $missionId, 'mission_admin');
    db()->prepare('UPDATE missions SET created_by = COALESCE(created_by, ?) WHERE id = ?')->execute([$adminId, $missionId]);
}
audit('admin.approve', null, 'user', $adminId, ['mission_id' => $missionId ? (int) $missionId : null, 'via' => 'cli']);

echo "Approved {$email} as mission admin" . ($missionId ? " (also admin of mission #{$missionId})" : '') . ". They can now create missions and choose their crew.\n";
