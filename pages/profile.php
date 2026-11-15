<?php
// Profile. Astronauts can edit name/age/height/weight; medical background is not shown here.
declare(strict_types=1);

require __DIR__ . '/../backend/bootstrap.php';
require __DIR__ . '/../frontend/components/layout.php';

$user = require_page_user();
$isAstronaut = $user['role'] === 'astronaut';

$adminProfile = null;
if (!$isAstronaut) {
    $statement = db()->prepare('SELECT ap.job_title, ap.organization, m.name AS requested_mission FROM admin_profiles ap LEFT JOIN missions m ON m.id = ap.requested_mission_id WHERE ap.user_id = ?');
    $statement->execute([$user['id']]);
    $adminProfile = $statement->fetch() ?: null;
}

render_app_start($user, 'profile', t('nav.profile'), ['scripts' => $isAstronaut ? ['profile.js'] : []]);
?>
<?php if ($isAstronaut): ?>
<div class="page-grid page-grid--two">
    <section class="card" aria-labelledby="profile-form-title">
        <h2 class="card-title" id="profile-form-title"><?= e(t('profile.your_details')) ?></h2>
        <form class="section-gap" data-profile-form novalidate>
            <div class="form-grid">
                <div class="field"><label for="p-name"><?= e(t('profile.full_name')) ?></label><input class="input" id="p-name" name="full_name" maxlength="120" required><p class="field-error" data-error="full_name"></p></div>
                <div class="field"><label for="p-email"><?= e(t('settings.email')) ?></label><input class="input" id="p-email" dir="ltr" readonly></div>
                <div class="field"><label for="p-code"><?= e(t('profile.astronaut_id')) ?></label><input class="input" id="p-code" dir="ltr" readonly></div>
                <div class="field"><label for="p-age"><?= e(t('profile.age')) ?></label><input class="input" id="p-age" name="age" type="number" min="18" max="80" required><p class="field-error" data-error="age"></p></div>
                <div class="field"><label for="p-height"><?= e(t('profile.height')) ?></label><input class="input" id="p-height" name="height_cm" type="number" min="120" max="230" step="0.1" required><p class="field-error" data-error="height_cm"></p></div>
                <div class="field"><label for="p-weight"><?= e(t('profile.weight')) ?></label><input class="input" id="p-weight" name="weight_kg" type="number" min="35" max="200" step="0.1" required><p class="field-error" data-error="weight_kg"></p></div>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= e(t('profile.save')) ?></button>
                <p data-profile-message aria-live="polite"></p>
            </div>
        </form>
    </section>

    <section class="card" aria-labelledby="profile-info-title">
        <h2 class="card-title" id="profile-info-title"><?= e(t('profile.mission_data')) ?></h2>
        <dl class="definition-list section-gap" data-profile-info><dt><?= e(t('common.loading')) ?></dt><dd></dd></dl>
    </section>
</div>
<?php else: ?>
<section class="card">
    <h2 class="card-title"><?= e(t('role.mission_admin')) ?></h2>
    <dl class="definition-list section-gap">
        <dt><?= e(t('settings.name')) ?></dt><dd><?= e($user['full_name']) ?></dd>
        <dt><?= e(t('settings.email')) ?></dt><dd><bdi><?= e($user['email']) ?></bdi></dd>
        <dt><?= e(t('profile.job_title')) ?></dt><dd><?= e($adminProfile['job_title'] ?? '—') ?></dd>
        <dt><?= e(t('profile.organization')) ?></dt><dd><?= e($adminProfile['organization'] ?? '—') ?></dd>
        <dt><?= e(t('profile.access')) ?></dt><dd><?= e(t(is_approved_mission_admin($user) ? 'profile.approved' : 'profile.pending')) ?></dd>
        <dt><?= e(t('profile.missions_managed')) ?></dt><dd><?= e(implode(t('common.list_separator'), array_column((new MissionService())->missionsFor($user), 'name')) ?: '—') ?></dd>
    </dl>
</section>
<?php endif; ?>
<?php render_app_end();
