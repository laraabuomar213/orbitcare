<?php
// Mission Group: the crew chat of the astronaut's (or admin's) mission — crew status bar,
// messages, emergency cards (with the crew report and "On my way"), recovery cards, "seen by".
// Everything comes from /api/missions/{id}/chat (CrewChatService).
declare(strict_types=1);

require __DIR__ . '/../backend/bootstrap.php';
require __DIR__ . '/../frontend/components/layout.php';

$user = require_page_user();
if ($user['role'] === 'mission_admin' && !is_approved_mission_admin($user)) {
    render_forbidden_page($user);
}

render_app_start($user, 'mission', t('nav.mission'), ['scripts' => ['mission.js'], 'heading_in_page' => true]);
?>
<section class="card crew-chat" data-crew-chat aria-labelledby="crew-chat-title">
    <header class="crew-chat-head">
        <h1 class="crew-chat-title" id="crew-chat-title" data-mission-name><?= e(t('common.loading')) ?></h1>
        <div class="crew-chat-badges">
            <label class="visually-hidden" for="crew-mission-select"><?= e(t('shell.mission')) ?></label>
            <select class="select-small" id="crew-mission-select" data-mission-select hidden></select>
            <span class="crew-code" data-mission-code hidden></span>
            <span class="crew-status-chip" data-mission-status hidden></span>
        </div>
    </header>

    <div class="crew-chat-empty" data-no-mission hidden>
        <p class="crew-chat-empty-title"><?= e(t('mission.none_title')) ?></p>
        <p><?= e(t($user['role'] === 'mission_admin' ? 'chat.no_mission_admin' : 'chat.no_mission_astronaut')) ?></p>
    </div>

    <div class="crew-chat-body" data-chat-body hidden>
        <p class="crew-bar-label"><?= e(t('chat.crew_status')) ?></p>
        <ul class="crew-bar" data-crew-bar aria-label="<?= e(t('chat.crew_status')) ?>"></ul>

        <div class="crew-chat-main">
            <div class="crew-thread" data-thread aria-live="polite" tabindex="0" aria-label="<?= e(t('chat.thread')) ?>"></div>
            <aside class="crew-emergencies" data-emergency-column hidden aria-label="<?= e(t('emergency.title')) ?>"></aside>
        </div>

        <form class="crew-composer" data-composer>
            <label class="visually-hidden" for="crew-message"><?= e(t('mission.message_label')) ?></label>
            <input class="crew-input" id="crew-message" name="body" maxlength="1000" autocomplete="off" placeholder="<?= e(t('chat.placeholder')) ?>" data-draft="mission-message">
            <button class="crew-send" type="submit" aria-label="<?= e(t('ai_panel.send')) ?>">
                <svg class="flip-rtl" viewBox="0 0 24 24" aria-hidden="true"><path d="M3.4 11.2L20 4.4c.6-.2 1.1.4.9 1L14.6 21c-.2.6-1.1.7-1.4.1l-3-6.2-6.6-2.4c-.6-.2-.6-1.1-.2-1.3z" fill="currentColor"/></svg>
            </button>
        </form>
        <p class="form-error" data-chat-error role="alert" hidden></p>
    </div>
</section>
<?php render_app_end();
