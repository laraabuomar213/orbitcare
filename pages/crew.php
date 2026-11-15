<?php
// A mission admin's view of one astronaut's authorized health status.
// Access is checked on the server: the astronaut must be in a mission this admin manages.
declare(strict_types=1);

require __DIR__ . '/../backend/bootstrap.php';
require __DIR__ . '/../frontend/components/layout.php';

$user = require_page_role('mission_admin');
$astronautId = (int) ($_GET['id'] ?? 0);

if (!(new MissionService())->canViewAstronaut($user, $astronautId)) {
    render_forbidden_page($user);
}

$astronaut = db()->prepare('SELECT u.full_name, a.astronaut_code FROM users u JOIN astronauts a ON a.user_id = u.id WHERE u.id = ?');
$astronaut->execute([$astronautId]);
$astronaut = $astronaut->fetch() ?: render_forbidden_page($user);
audit('crew.view_health', (int) $user['id'], 'astronaut', $astronautId);

$alertsUrl = url('pages/alerts.php');
render_app_start($user, 'admin', $astronaut['full_name'], [
    'scripts' => ['dashboard.js'],
    'subheading' => t('admin.crew_subheading', ['code' => $astronaut['astronaut_code']]),
]);
?>
<div class="dashboard-main" data-health-view data-astronaut-id="<?= $astronautId ?>">
    <?php require __DIR__ . '/../frontend/components/health-status.php'; ?>
    <div class="dashboard-row dashboard-row--data">
        <?php require __DIR__ . '/../frontend/components/vital-signs.php'; ?>
        <?php require __DIR__ . '/../frontend/components/health-trend.php'; ?>
    </div>
    <?php require __DIR__ . '/../frontend/components/alerts-card.php'; ?>
</div>
<?php render_app_end();
