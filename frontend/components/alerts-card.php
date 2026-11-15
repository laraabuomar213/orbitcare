<?php // Most important active alerts. Filled by dashboard.js. Expects $alertsUrl. ?>
<section class="card alerts-card" data-alerts-card data-tone="none" aria-labelledby="alerts-card-title">
    <header class="card-header">
        <h2 class="card-title" id="alerts-card-title">
            <svg class="icon icon-alert" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4L2.5 20h19z"/><path d="M12 10v4.5M12 17.3v.1"/></svg>
            <?= e(t('nav.alerts')) ?>
        </h2>
        <a class="link-arrow" href="<?= e($alertsUrl) ?>"><?= e(t('dashboard.view_all')) ?> <span class="flip-rtl" aria-hidden="true">→</span></a>
    </header>
    <div class="alerts-card-list" data-alerts-list><div class="state-block"><p><?= e(t('dashboard.loading_alerts')) ?></p></div></div>
</section>
