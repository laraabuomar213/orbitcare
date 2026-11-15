<?php
// Registers a wearable (the OrbitCare vest, or its Wokwi simulation) for one astronaut and prints
// the device token ONCE — put it in the firmware's DEVICE_TOKEN. Only its SHA-256 hash is stored.
// Command line only:
//   php cli/register-device.php <astronaut email or astronaut ID> <device-id> [simulated|hardware]
//   php cli/register-device.php --revoke <device-id>
// From the first reading on, the device's live stream becomes that astronaut's data source
// (their recorded-data replay stops).
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../backend/bootstrap.php';

if (($argv[1] ?? '') === '--revoke') {
    $statement = db()->prepare('UPDATE devices SET revoked_at = UTC_TIMESTAMP() WHERE device_id = ? AND revoked_at IS NULL');
    $statement->execute([$argv[2] ?? '']);
    echo $statement->rowCount() ? "Revoked {$argv[2]}: its token no longer works.\n" : "No active device named " . ($argv[2] ?? '') . ".\n";
    exit($statement->rowCount() ? 0 : 1);
}

$who = trim($argv[1] ?? '');
$deviceId = trim($argv[2] ?? '');
$kind = trim($argv[3] ?? 'simulated');
if ($who === '' || $deviceId === '') {
    fwrite(STDERR, "Usage: php cli/register-device.php <astronaut email or astronaut ID> <device-id> [simulated|hardware]\n");
    exit(1);
}

$statement = db()->prepare(
    "SELECT u.id, u.full_name, a.astronaut_code FROM users u JOIN astronauts a ON a.user_id = u.id
     WHERE u.role = 'astronaut' AND (u.email = ? OR a.astronaut_code = ?)"
);
$statement->execute([strtolower($who), $who]);
$astronaut = $statement->fetch();
if (!$astronaut) {
    fwrite(STDERR, "No astronaut found for {$who}.\n");
    exit(1);
}

try {
    $result = (new DeviceService())->register((int) $astronaut['id'], $deviceId, $kind);
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} catch (PDOException $error) {
    fwrite(STDERR, "Could not register: is the device ID {$deviceId} already registered?\n");
    exit(1);
}

echo "Registered {$deviceId} ({$kind}) for {$astronaut['full_name']} ({$astronaut['astronaut_code']}).\n";
if (!config()['devices']['enabled']) {
    echo "Note: device input is OFF (DEVICE_INPUT=off) — readings are refused until it is set to on in .env.\n";
}
echo "Firmware settings:\n";
echo "  DEVICE_ID    = \"{$deviceId}\"\n";
echo "  ASTRONAUT_ID = \"{$astronaut['astronaut_code']}\"\n";
echo "  DEVICE_TOKEN = \"{$result['token']}\"\n";
echo "The token is shown only now. Keep it out of public code; revoke with --revoke {$deviceId}.\n";
