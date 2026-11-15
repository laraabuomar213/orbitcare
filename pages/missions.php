<?php
// My Missions (approved mission admins): create missions, set their status, choose the crew,
// and see who needs attention. Data: /api/admin/missions, /api/admin/missions/{id},
// /api/admin/astronauts and the mission member/status endpoints (MissionService, MissionBoardService).
declare(strict_types=1);

require __DIR__ . '/../backend/bootstrap.php';
require __DIR__ . '/../frontend/components/layout.php';

$user = require_page_user();
if ($user['role'] !== 'mission_admin') {
    render_forbidden_page($user);
}
if (!is_approved_mission_admin($user)) {
    render_app_start($user, 'missions', t('nav.my_missions'), ['heading' => t('admin.pending_heading')]);
    ?>
    <section class="card">
        <h2 class="card-title"><?= e(t('shell.awaiting_approval')) ?></h2>
        <p class="section-gap"><?= e(t('admin.pending_text')) ?></p>
    </section>
    <?php
    render_app_end();
    exit;
}

$plus = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';

render_app_start($user, 'missions', t('nav.my_missions'), ['scripts' => ['missions-admin.js'], 'heading_in_page' => true]);
?>
<div class="missions-page">
    <header class="missions-head">
        <div>
            <h1 class="missions-title"><?= e(t('missions.title')) ?></h1>
            <p class="missions-subtitle"><?= e(t('missions.subtitle')) ?></p>
        </div>
        <button class="missions-new" type="button" data-new-mission><?= $plus ?><span><?= e(t('missions.new')) ?></span></button>
    </header>

    <p class="form-error" data-board-error role="alert" hidden></p>

    <section class="card missions-empty" data-missions-empty hidden>
        <p class="missions-empty-title"><?= e(t('missions.none_title')) ?></p>
        <p><?= e(t('missions.none_text')) ?></p>
    </section>

    <div class="missions-layout" data-missions-layout hidden>
        <nav class="missions-list" aria-label="<?= e(t('missions.list_label')) ?>" data-mission-list></nav>

        <div class="missions-detail">
            <section class="card mission-panel" aria-labelledby="mission-panel-title">
                <header class="mission-panel-head">
                    <h2 class="mission-panel-title" id="mission-panel-title" data-detail-name dir="auto"></h2>
                    <span class="crew-code" data-detail-code></span>
                    <div class="mission-panel-tools">
                        <div class="mission-status-switch" role="group" aria-label="<?= e(t('missions.status_label')) ?>" data-status-switch>
                            <?php foreach (['planning', 'active', 'completed'] as $status): ?>
                                <button type="button" data-status="<?= $status ?>" aria-pressed="false"><?= e(t("chat.mission_status.{$status}")) ?></button>
                            <?php endforeach; ?>
                        </div>
                        <a class="mission-chat-link" href="#" data-chat-link><?= e(t('missions.open_chat')) ?></a>
                    </div>
                </header>

                <dl class="mission-tiles" data-tiles></dl>

                <div class="mission-crew-wrap">
                    <table class="mission-crew-table">
                        <thead><tr>
                            <th scope="col"><?= e(t('missions.col.astronaut')) ?></th>
                            <th scope="col"><?= e(t('missions.col.health')) ?></th>
                            <th scope="col"><?= e(t('missions.col.checkin')) ?></th>
                            <th scope="col"><?= e(t('missions.col.alertness')) ?></th>
                            <th scope="col"><?= e(t('missions.col.last_seen')) ?></th>
                            <th scope="col" class="mission-col-actions"><?= e(t('missions.col.actions')) ?></th>
                        </tr></thead>
                        <tbody data-crew-rows><tr><td colspan="6"><?= e(t('common.loading')) ?></td></tr></tbody>
                    </table>
                </div>
            </section>

            <section class="mission-add" aria-labelledby="mission-add-title">
                <h2 class="mission-add-title" id="mission-add-title"><?= e(t('missions.add_title')) ?></h2>
                <div class="mission-search">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M16 16l4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <label class="visually-hidden" for="astronaut-search"><?= e(t('missions.search')) ?></label>
                    <input type="search" id="astronaut-search" maxlength="60" autocomplete="off" placeholder="<?= e(t('missions.search')) ?>" data-astronaut-search>
                </div>
                <ul class="mission-add-list" data-unassigned aria-live="polite"></ul>
                <p class="mission-add-note"><?= e(t('missions.add_note')) ?></p>
            </section>
        </div>
    </div>
</div>

<dialog class="mission-dialog" data-mission-dialog aria-labelledby="mission-dialog-title">
    <form class="mission-form" data-mission-form novalidate>
        <h2 class="mission-form-title" id="mission-dialog-title"><?= e(t('missions.new')) ?></h2>

        <label class="mission-form-label" for="mission-name"><?= e(t('missions.form.name')) ?></label>
        <input class="mission-form-input" id="mission-name" name="name" maxlength="120" required autocomplete="off" aria-describedby="mission-name-error">
        <p class="mission-form-error" id="mission-name-error" data-error-for="name" hidden></p>

        <label class="mission-form-label" for="mission-description"><?= e(t('missions.form.description')) ?> <span><?= e(t('missions.form.optional')) ?></span></label>
        <textarea class="mission-form-input" id="mission-description" name="description" maxlength="500" rows="4" aria-describedby="mission-description-error"></textarea>
        <p class="mission-form-error" id="mission-description-error" data-error-for="description" hidden></p>

        <fieldset class="mission-form-status">
            <legend class="mission-form-label"><?= e(t('missions.form.status')) ?></legend>
            <div class="mission-form-segments">
                <?php foreach (['planning', 'active'] as $i => $status): ?>
                    <label><input type="radio" name="status" value="<?= $status ?>"<?= $i === 0 ? ' checked' : '' ?>><span><?= e(t("chat.mission_status.{$status}")) ?></span></label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <p class="mission-form-note"><?= e(t('missions.form.note')) ?></p>
        <p class="mission-form-error" data-error-for="form" role="alert" hidden></p>

        <div class="mission-form-actions">
            <button class="mission-form-create" type="submit"><?= e(t('missions.form.create')) ?></button>
            <button class="mission-form-cancel" type="button" data-cancel><?= e(t('common.cancel')) ?></button>
        </div>
    </form>
</dialog>
<?php render_app_end();
