<?php // Current vital signs — only metrics that exist in the data source. Filled by dashboard.js. ?>
<section class="card vitals-card" aria-labelledby="vitals-title">
    <header class="card-header">
        <h2 class="card-title" id="vitals-title">
            <svg class="icon icon-accent" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l2-5 3.5 10 2.5-7 1.5 2H21"/></svg>
            <?= e(t('dashboard.vitals')) ?>
        </h2>
        <span class="replay-pill" data-replay-pill title="<?= e(t('dashboard.replay_hint')) ?>"><span class="status-dot"></span><?= e(t('dashboard.replay')) ?></span>
    </header>
    <div class="vitals-grid" data-vitals><div class="state-block"><p><?= e(t('dashboard.loading_measurements')) ?></p></div></div>
    <div class="data-source-note" data-replay-note></div>
</section>
