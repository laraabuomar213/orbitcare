<?php
// Astronaut dashboard: status, vital signs, trend, alerts, quick actions, AI assistant.
// All values come from the API (recorded data replay) — nothing on this page is hard-coded.
declare(strict_types=1);

require __DIR__ . '/../backend/bootstrap.php';
require __DIR__ . '/../frontend/components/layout.php';

$user = require_page_role('astronaut');
$alertsUrl = url('pages/alerts.php');

render_app_start($user, 'dashboard', t('nav.dashboard'),['greeting' => true, 'scripts' => ['dashboard.js', 'assistant.js']]);
?>
<div class="dashboard-grid" data-health-view>
    <div class="dashboard-main">
        <?php require __DIR__ . '/../frontend/components/health-status.php'; ?>
        <div class="dashboard-row dashboard-row--data">
            <?php require __DIR__ . '/../frontend/components/vital-signs.php'; ?>
            <?php require __DIR__ . '/../frontend/components/health-trend.php'; ?>
        </div>
        <div class="dashboard-row dashboard-row--actions">
            <?php require __DIR__ . '/../frontend/components/alerts-card.php'; ?>
            <?php require __DIR__ . '/../frontend/components/quick-actions.php'; ?>
        </div>
    </div>
    <?php require __DIR__ . '/../frontend/components/ai-assistant.php'; ?>
</div>
<?php render_app_end();
