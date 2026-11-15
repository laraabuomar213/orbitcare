<?php
// Health Analytics: history, trends, derived statistics, data quality and source of every value.
declare(strict_types=1);

require __DIR__ . '/../backend/bootstrap.php';
require __DIR__ . '/../frontend/components/layout.php';

$user = require_page_role('astronaut');

render_app_start($user, 'analytics', t('nav.analytics'), [
    'scripts' => ['analytics.js'],
    'subheading' => t('analytics.subheading'),
]);
?>
<div class="tabs" role="group" aria-label="<?= e(t('dashboard.measurement')) ?>" data-metric-tabs>
    <?php foreach (['heart_rate', 'spo2', 'pulse_rate', 'respiratory_rate'] as $index => $metricKey): ?>
        <button class="tab" type="button" data-metric="<?= e($metricKey) ?>" aria-pressed="<?= $index === 0 ? 'true' : 'false' ?>"><?= e(t('metric.short.' . $metricKey)) ?></button>
    <?php endforeach; ?>
</div>

<div class="page-grid page-grid--two">
    <section class="card" aria-labelledby="analytics-chart-title">
        <header class="card-header">
            <h2 class="card-title" id="analytics-chart-title" data-chart-title><?= e(t('analytics.trend')) ?></h2>
            <div class="segmented" role="group" aria-label="<?= e(t('dashboard.time_range')) ?>">
                <?php foreach (['1h', '6h', '24h'] as $index => $rangeKey): ?>
                    <button type="button" data-range="<?= e($rangeKey) ?>" aria-pressed="<?= $index === 0 ? 'true' : 'false' ?>"><?= e(t('range.' . $rangeKey)) ?></button>
                <?php endforeach; ?>
            </div>
        </header>
        <div class="chart-container" data-chart></div>
        <p class="chart-note" data-chart-note></p>
    </section>

    <div class="page-grid">
        <section class="card" aria-labelledby="stats-title">
            <h2 class="card-title" id="stats-title"><?= e(t('analytics.summary')) ?> <span class="derived-label"><?= e(t('analytics.derived')) ?></span></h2>
            <p class="muted section-gap analytics-note"><?= e(t('analytics.summary_hint')) ?></p>
            <dl class="stat-grid section-gap" data-stats></dl>
        </section>

        <section class="card" aria-labelledby="source-title">
            <h2 class="card-title" id="source-title"><?= e(t('analytics.data_source')) ?></h2>
            <dl class="definition-list section-gap" data-source><dt><?= e(t('common.loading')) ?></dt><dd></dd></dl>
        </section>
    </div>
</div>

<section class="card section-gap" aria-labelledby="table-title">
    <header class="card-header">
        <h2 class="card-title" id="table-title"><?= e(t('analytics.measurements')) ?> <span class="derived-label derived-label--original"><?= e(t('analytics.original_values')) ?></span></h2>
    </header>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <?php foreach (['replayed_at', 'value', 'status', 'record', 'row', 'recording_time', 'raw_id'] as $column): ?>
                        <th><?= e(t("analytics.col.{$column}")) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody data-rows><tr><td colspan="7"><?= e(t('common.loading')) ?></td></tr></tbody>
        </table>
    </div>
    <div class="form-actions"><button class="btn btn-ghost btn-small" type="button" data-load-more hidden><?= e(t('analytics.load_more')) ?></button></div>
</section>

<section class="card section-gap" aria-labelledby="history-title">
    <h2 class="card-title" id="history-title"><?= e(t('analytics.alert_history')) ?></h2>
    <div class="list-plain section-gap" data-alert-history></div>
</section>
<?php render_app_end();
