<?php
// Mission Admin dashboard. Pending (unapproved) admins only see a waiting notice;
// approval is enforced on the server for every admin page and API call.
declare(strict_types=1);

require __DIR__ . '/../backend/bootstrap.php';
require __DIR__ . '/../frontend/components/layout.php';

$user = require_page_user();
if ($user['role'] !== 'mission_admin') {
    render_forbidden_page($user);
}

if (!is_approved_mission_admin($user)) {
    render_app_start($user, 'admin', t('nav.mission_dashboard'), ['heading' => t('admin.pending_heading')]);
    ?>
    <section class="card">
        <h2 class="card-title"><?= e(t('shell.awaiting_approval')) ?></h2>
        <p class="section-gap"><?= e(t('admin.pending_text')) ?></p>
    </section>
    <?php
    render_app_end();
    exit;
}

render_app_start($user, 'admin', t('nav.mission_dashboard'), ['greeting' => true, 'scripts' => ['admin.js']]);
?>
<div class="mission-banner card" data-admin-banner>
    <div><p class="eyebrow"><?= e(t('admin.mission_control')) ?></p><h2 data-mission-name><?= e(t('common.loading')) ?></h2></div>
    <label class="visually-hidden" for="admin-mission"><?= e(t('shell.mission')) ?></label>
    <select class="select-small" id="admin-mission" data-admin-mission hidden></select>
</div>

<dl class="summary-grid section-gap" data-summary></dl>

<div class="page-grid">
    <section class="card" aria-labelledby="crew-title">
        <header class="card-header"><h2 class="card-title" id="crew-title"><?= e(t('admin.crew_status')) ?></h2><span class="replay-pill"><span class="status-dot"></span><?= e(t('dashboard.replay_source')) ?></span></header>
        <div class="table-wrap">
            <table class="data-table crew-table">
                <thead><tr>
                    <th><?= e(t('alerts.astronaut')) ?></th><th><?= e(t('alerts.status')) ?></th>
                    <th><?= e(t('metric.short.heart_rate')) ?></th><th><?= e(t('metric.short.spo2')) ?></th>
                    <th><?= e(t('metric.short.pulse_rate')) ?></th><th><?= e(t('metric.short.respiratory_rate')) ?></th>
                    <th><?= e(t('admin.last_update')) ?></th><th><span class="visually-hidden"><?= e(t('mission.open')) ?></span></th>
                </tr></thead>
                <tbody data-crew><tr><td colspan="8"><?= e(t('common.loading')) ?></td></tr></tbody>
            </table>
        </div>
    </section>

    <section class="card" aria-labelledby="mission-alerts-title">
        <header class="card-header">
            <h2 class="card-title" id="mission-alerts-title"><?= e(t('admin.active_alerts')) ?></h2>
            <a class="link-arrow" href="<?= e(url('pages/alerts.php')) ?>"><?= e(t('mission.history')) ?> <span class="flip-rtl" aria-hidden="true">→</span></a>
        </header>
        <div class="list-plain" data-mission-alerts></div>
    </section>
</div>
<?php render_app_end();
