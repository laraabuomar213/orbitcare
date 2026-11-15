<?php // Overall health status card. Text and colour come from GET /api/health/current (never hard-coded). ?>
<section class="card status-card" data-health-status data-level="LOADING" aria-live="polite">
    <span class="status-icon" data-status-icon aria-hidden="true"></span>
    <div class="status-text">
        <h2 class="status-title" data-status-title><?= e(t('dashboard.status_loading')) ?></h2>
        <p class="status-message" data-status-message></p>
    </div>
    <svg class="status-pulse" viewBox="0 0 160 40" aria-hidden="true"><path d="M0 22h52l8-14 10 26 9-20 6 8h75" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
</section>
