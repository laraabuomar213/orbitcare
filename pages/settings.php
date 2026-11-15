<?php
// Settings: account, language, notifications, privacy, display, security.
declare(strict_types=1);

require __DIR__ . '/../backend/bootstrap.php';
require __DIR__ . '/../frontend/components/layout.php';

$user = require_page_user();
render_app_start($user, 'settings', t('nav.settings'), ['scripts' => ['settings.js']]);
?>
<div class="page-grid page-grid--two">
    <div class="page-grid">
        <section class="card" aria-labelledby="account-title">
            <h2 class="card-title" id="account-title"><?= e(t('settings.account')) ?></h2>
            <dl class="definition-list section-gap">
                <dt><?= e(t('settings.name')) ?></dt><dd><?= e($user['full_name']) ?></dd>
                <dt><?= e(t('settings.email')) ?></dt><dd><bdi><?= e($user['email']) ?></bdi></dd>
                <dt><?= e(t('settings.role')) ?></dt><dd><?= e(t($user['role'] === 'mission_admin' ? 'role.mission_admin' : 'role.astronaut')) ?></dd>
            </dl>
        </section>

        <section class="card" aria-labelledby="language-title">
            <h2 class="card-title" id="language-title"><?= e(t('settings.language')) ?></h2>
            <p class="muted section-gap"><?= e(t('settings.language_hint')) ?></p>
            <div class="language-choice section-gap" role="radiogroup" aria-labelledby="language-title" data-language-form>
                <?php foreach (SUPPORTED_LANGUAGES as $code => $name): ?>
                    <label class="language-option">
                        <input type="radio" name="language" value="<?= e($code) ?>"<?= current_language() === $code ? ' checked' : '' ?>>
                        <span lang="<?= e($code) ?>" dir="<?= $code === 'ar' ? 'rtl' : 'ltr' ?>"><?= e($name) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="form-success" data-language-message aria-live="polite"></p>
        </section>

        <section class="card" aria-labelledby="prefs-title">
            <h2 class="card-title" id="prefs-title"><?= e(t('settings.notifications_display')) ?></h2>
            <form class="section-gap" data-settings-form>
                <label class="toggle-row"><span><?= e(t('settings.in_app_alerts')) ?><small><?= e(t('settings.in_app_alerts_hint')) ?></small></span><input class="switch" type="checkbox" name="notifications.in_app_alerts"></label>
                <label class="toggle-row"><span><?= e(t('settings.alert_sound')) ?><small><?= e(t('settings.alert_sound_hint')) ?></small></span><input class="switch" type="checkbox" name="notifications.alert_sound"></label>
                <label class="toggle-row"><span><?= e(t('settings.reduce_motion')) ?><small><?= e(t('settings.reduce_motion_hint')) ?></small></span><input class="switch" type="checkbox" name="display.reduced_motion"></label>
            </form>
            <p class="form-success" data-settings-message aria-live="polite"></p>
        </section>
    </div>

    <div class="page-grid">
        <section class="card" aria-labelledby="privacy-title">
            <h2 class="card-title" id="privacy-title"><?= e(t('settings.privacy')) ?></h2>
            <p class="section-gap"><?= e(t('settings.privacy_text')) ?></p>
            <p class="muted section-gap settings-small"><?= e(t('settings.privacy_audit')) ?></p>
        </section>

        <section class="card" aria-labelledby="security-title">
            <h2 class="card-title" id="security-title"><?= e(t('settings.security')) ?></h2>
            <form class="section-gap" data-password-form novalidate>
                <div class="form-grid">
                    <div class="field"><label for="s-current"><?= e(t('settings.current_password')) ?></label><input class="input" id="s-current" name="current_password" type="password" autocomplete="current-password" required><p class="field-error" data-error="current_password"></p></div>
                    <div class="field"><label for="s-new"><?= e(t('settings.new_password')) ?></label><input class="input" id="s-new" name="new_password" type="password" autocomplete="new-password" required><p class="field-error" data-error="new_password"></p></div>
                </div>
                <div class="form-actions">
                    <button class="btn btn-primary" type="submit"><?= e(t('settings.change_password')) ?></button>
                    <p data-password-message aria-live="polite"></p>
                </div>
            </form>
        </section>
    </div>
</div>
<?php render_app_end();
