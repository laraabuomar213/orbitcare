<?php
// AI Assistant: General Doctor AI (entry point) + specialists, with persistent consultations.
// Everything shown comes from /api/ai/* for the signed-in astronaut — no values in this page.
declare(strict_types=1);

require __DIR__ . '/../backend/bootstrap.php';
require __DIR__ . '/../frontend/components/layout.php';

$user = require_page_role('astronaut');

// Specialist cards come from the backend configuration (General Doctor last, as in the design).
$specialists = ai_specialists_config()['specialists'];
$cardOrder = ['respiratory', 'cardiology', 'neurology', 'sleep', 'mental_health', 'general'];
$icons = [
    'lungs' => '<path d="M12 4v7M12 11c-1.5 1-2.5 1.2-3.5 1.2"/><path d="M12 11c1.5 1 2.5 1.2 3.5 1.2"/><path d="M8.5 6.5C6 7 4 11 4 15.5c0 2.5 1.2 4 3 4 2 0 3-1.2 3-3.5V9c0-1.6-.6-2.7-1.5-2.5zM15.5 6.5C18 7 20 11 20 15.5c0 2.5-1.2 4-3 4-2 0-3-1.2-3-3.5V9c0-1.6.6-2.7 1.5-2.5z"/>',
    'heart' => '<path d="M12 20s-7-4.4-8.6-8.9C2.3 8 4.3 5 7.4 5c1.9 0 3.4 1 4.6 2.7C13.2 6 14.7 5 16.6 5c3.1 0 5.1 3 4 6.1C19 15.6 12 20 12 20z"/><path d="M6 12h3l1.5-2.5 2 4 1.5-2.5H18"/>',
    'brain' => '<path d="M9 4.5a3 3 0 0 0-3 3 3 3 0 0 0-1.5 5.3A3 3 0 0 0 7 17.5a2.5 2.5 0 0 0 5 .5V5.5a2 2 0 0 0-3-1z"/><path d="M15 4.5a3 3 0 0 1 3 3 3 3 0 0 1 1.5 5.3 3 3 0 0 1-2.5 4.7 2.5 2.5 0 0 1-5 .5"/><path d="M9 9.5c1 0 1.5.5 2 1M15 9.5c-1 0-1.5.5-2 1"/>',
    'plus' => '<rect x="4" y="4" width="16" height="16" rx="4.5"/><path d="M12 8.5v7M8.5 12h7" stroke-width="2.2"/>',
    'moon' => '<path d="M19.5 14.5A8 8 0 0 1 9.5 4.5a8 8 0 1 0 10 10z"/>',
];
$svg = fn (string $paths, string $class = 'icon') => '<svg class="' . $class . '" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';

render_app_start($user, 'assistant', t('nav.assistant'), ['scripts' => ['assistant-page.js'], 'heading_in_page' => true]);
?>
<div class="assistant-layout">
    <section class="card assistant-main" aria-labelledby="assistant-title">
        <header class="assistant-header">
            <svg class="assistant-star" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 1.5c.6 6.3 3.9 9.6 10.5 10.5-6.6.9-9.9 4.2-10.5 10.5-.6-6.3-3.9-9.6-10.5-10.5 6.6-.9 9.9-4.2 10.5-10.5z" fill="currentColor"/></svg>
            <div class="assistant-heading">
                <h1 class="assistant-title" id="assistant-title"><?= e(t('nav.assistant')) ?></h1>
                <p class="assistant-subtitle"><?= e(t('assistant.subtitle')) ?></p>
            </div>
            <a class="pill-button" href="<?= e(url('pages/dashboard.php')) ?>"><?= $svg('<path d="M19 12H5M11 6l-6 6 6 6"/>', 'icon flip-rtl') ?><?= e(t('assistant.back')) ?></a>
        </header>

        <div class="session-bar" data-session-bar hidden>
            <span class="session-pill" data-session-pill></span>
            <span class="session-actions">
                <button class="link-button" type="button" data-return-general hidden><span class="flip-rtl" aria-hidden="true">←</span> <?= e(t('assistant.return_general')) ?></button>
                <button class="link-button link-button--muted" type="button" data-close-consultation><?= e(t('assistant.end')) ?></button>
            </span>
        </div>

        <div class="assistant-thread" data-thread aria-live="polite" aria-busy="true">
            <div class="state-block"><p><?= e(t('assistant.loading')) ?></p></div>
        </div>

        <section class="specialist-picker" aria-labelledby="specialist-title">
            <h2 class="specialist-picker-title" id="specialist-title">
                <?= $svg('<path d="M6 3.5v5a4 4 0 0 0 8 0v-5M10 12.5v2a4.5 4.5 0 0 0 9 0v-2"/><circle cx="19" cy="10.5" r="2"/>', 'icon icon-accent') ?>
                <?= e(t('assistant.choose')) ?>
            </h2>
            <div class="specialist-grid" data-specialists>
                <?php foreach ($cardOrder as $key): $s = $specialists[$key]; ?>
                    <button class="specialist-card" type="button" data-specialist="<?= e($key) ?>" title="<?= e(t("ai.specialist.{$key}.description")) ?>">
                        <span class="specialist-badge" data-badge hidden></span>
                        <span class="specialist-icon tone-<?= e($s['tone']) ?>"><?= $svg($icons[$s['icon']]) ?></span>
                        <span class="specialist-name"><?= e(t("ai.specialist.{$key}.name")) ?></span>
                        <span class="specialist-focus"><?= e(t("ai.specialist.{$key}.focus")) ?></span>
                        <?= $svg('<path d="M9 6l6 6-6 6"/>', 'icon specialist-chevron flip-rtl') ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </section>

        <form class="assistant-composer" data-composer novalidate>
            <label class="visually-hidden" for="assistant-input"><?= e(t('assistant.input_label')) ?></label>
            <input class="assistant-input" id="assistant-input" name="message" type="text" maxlength="2000" autocomplete="off"
                   placeholder="<?= e(t('assistant.placeholder')) ?>" data-draft="ai-consultation">
            <button class="assistant-send" type="submit" aria-label="<?= e(t('assistant.send')) ?>">
                <?= $svg('<path d="M20.5 3.5L10 14M20.5 3.5L14 20.5l-4-6.5-6.5-4z"/>', 'icon flip-rtl') ?>
            </button>
        </form>
        <p class="assistant-error" data-composer-error role="alert" hidden></p>
    </section>

    <aside class="card consultations-card" aria-labelledby="consultations-title">
        <header class="consultations-header">
            <h2 class="card-title" id="consultations-title">
                <?= $svg('<path d="M4 12a8 8 0 1 0 2.4-5.7"/><path d="M4 4.5v3.5h3.5M12 8v4l2.5 2"/>', 'icon icon-accent') ?>
                <?= e(t('assistant.my_consultations')) ?>
            </h2>
            <button class="link-arrow link-button" type="button" data-view-all aria-expanded="false"><?= e(t('dashboard.view_all')) ?> <span class="flip-rtl" aria-hidden="true">→</span></button>
        </header>
        <div class="consultation-list" data-consultations></div>
    </aside>
</div>
<?php render_app_end();
