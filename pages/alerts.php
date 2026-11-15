<?php
// Alerts: filterable list and a detail view (what, when, which measurement, which rule,
// recommendation, source data, status). Astronauts see their own; mission admins their crew's.
declare(strict_types=1);

require __DIR__ . '/../backend/bootstrap.php';
require __DIR__ . '/../frontend/components/layout.php';

$user = require_page_user();
if ($user['role'] === 'mission_admin' && !is_approved_mission_admin($user)) {
    render_forbidden_page($user);
}

render_app_start($user, 'alerts', t('nav.alerts'), [
    'scripts' => ['alerts.js'],
    'subheading' => t($user['role'] === 'mission_admin' ? 'alerts.subheading_admin' : 'alerts.subheading'),
]);
?>
<div class="tabs" role="group" aria-label="<?= e(t('alerts.filter')) ?>" data-filters>
    <?php foreach (['all', 'active', 'resolved', 'critical', 'warning'] as $index => $filter): ?>
        <button class="tab" type="button" data-filter="<?= e($filter) ?>" aria-pressed="<?= $index === 0 ? 'true' : 'false' ?>"><?= e(t("alerts.filter.{$filter}")) ?></button>
    <?php endforeach; ?>
</div>

<div class="page-grid page-grid--split">
    <section class="card" aria-labelledby="alert-list-title">
        <h2 class="visually-hidden" id="alert-list-title"><?= e(t('alerts.list')) ?></h2>
        <div class="list-plain" data-alert-list><div class="state-block"><p><?= e(t('dashboard.loading_alerts')) ?></p></div></div>
    </section>

    <section class="card" aria-labelledby="alert-detail-title" data-alert-detail>
        <h2 class="card-title" id="alert-detail-title"><?= e(t('alerts.details')) ?></h2>
        <p class="muted section-gap"><?= e(t('alerts.select_hint')) ?></p>
    </section>
</div>
<?php render_app_end();
