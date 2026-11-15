<?php
// AI assistant panel (dashboard right column and the full AI Assistant page).
// Filled by assistant.js. Until a provider is configured it says so — no canned medical advice.
$assistantClass = $assistantClass ?? 'ai-panel';
?>
<aside class="card <?= e($assistantClass) ?>" data-ai-panel aria-labelledby="ai-title">
    <header class="card-header">
        <h2 class="card-title" id="ai-title">
            <svg class="icon icon-accent" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"><path d="M12 2.5c.5 5.6 3.4 8.5 9 9-5.6.5-8.5 3.4-9 9-.5-5.6-3.4-8.5-9-9 5.6-.5 8.5-3.4 9-9z"/></svg>
            <?= e(t('nav.assistant')) ?>
        </h2>
        <span class="ai-status" data-ai-status title="<?= e(t('ai_panel.checking_provider')) ?>"><span class="status-dot"></span><span class="visually-hidden" data-ai-status-label><?= e(t('ai_panel.checking')) ?></span></span>
    </header>

    <div class="ai-messages" data-ai-messages aria-live="polite"><div class="state-block"><p><?= e(t('common.loading')) ?></p></div></div>

    <p class="ai-disclaimer"><?= e(t('ai_panel.disclaimer')) ?></p>

    <form class="ai-form" data-ai-form>
        <label class="visually-hidden" for="ai-input"><?= e(t('ai_panel.input_label')) ?></label>
        <input class="ai-input" id="ai-input" name="message" type="text" placeholder="<?= e(t('ai_panel.placeholder')) ?>" maxlength="2000" autocomplete="off" data-draft="ai-chat">
        <button class="ai-send" type="submit" aria-label="<?= e(t('ai_panel.send')) ?>">
            <svg class="flip-rtl" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 3.5L10 14M20.5 3.5L14 20.5l-4-6.5-6.5-4z"/></svg>
        </button>
    </form>
</aside>
