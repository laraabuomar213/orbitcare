<?php // A few shortcuts. Kept deliberately short. ?>
<section class="card actions-card" aria-labelledby="actions-title">
    <header class="card-header">
        <h2 class="card-title" id="actions-title">
            <svg class="icon icon-accent" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2.5L5 13.5h6l-1 8 8-11h-6z"/></svg>
            <?= e(t('dashboard.quick_actions')) ?>
        </h2>
    </header>
    <div class="actions-grid">
        <a class="action-tile" href="<?= e(url('pages/assistant.php')) ?>">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3.5v5a4 4 0 0 0 8 0v-5M10 12.5v2a4.5 4.5 0 0 0 9 0v-2"/><circle cx="19" cy="10.5" r="2"/></svg>
            <span><?= e(t('dashboard.action_guidance')) ?></span>
        </a>
        <a class="action-tile" href="<?= e(url('pages/analytics.php')) ?>">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h16M6.5 20v-6M10.5 20V9M14.5 20v-8M18.5 20V5"/></svg>
            <span><?= e(t('dashboard.action_data')) ?></span>
        </a>
        <a class="action-tile" href="<?= e(url('pages/mission.php')) ?>">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3"/><path d="M3.5 19c.6-3.2 2.8-5 5.5-5s4.9 1.8 5.5 5"/><circle cx="16.5" cy="9" r="2.4"/><path d="M15.5 14.2c2.6-.3 4.6 1.3 5 4.3"/></svg>
            <span><?= e(t('nav.mission')) ?></span>
        </a>
        <a class="action-tile" href="<?= e(url('pages/settings.php')) ?>">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2.5v2.2M12 19.3v2.2M4.6 4.6l1.6 1.6M17.8 17.8l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.6 19.4l1.6-1.6M17.8 6.2l1.6-1.6"/></svg>
            <span><?= e(t('nav.settings')) ?></span>
        </a>
    </div>
</section>
