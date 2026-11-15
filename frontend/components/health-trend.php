<?php // Interactive trend chart of replayed measurements (1h / 6h / 24h). Filled by dashboard.js. ?>
<section class="card trend-card" aria-labelledby="trend-title">
    <header class="card-header">
        <h2 class="card-title" id="trend-title">
            <svg class="icon icon-accent" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h16M6.5 20v-6M10.5 20V9M14.5 20v-8M18.5 20V5"/></svg>
            <?= e(t('dashboard.trend')) ?>
        </h2>
        <div class="trend-controls">
            <label class="visually-hidden" for="trend-metric"><?= e(t('dashboard.measurement')) ?></label>
            <select class="select-small" id="trend-metric" data-trend-metric>
                <?php foreach (['heart_rate', 'spo2', 'pulse_rate', 'respiratory_rate'] as $metricKey): ?>
                    <option value="<?= e($metricKey) ?>"><?= e(t('metric.short.' . $metricKey)) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="segmented" role="group" aria-label="<?= e(t('dashboard.time_range')) ?>">
                <?php foreach (['1h', '6h', '24h'] as $index => $rangeKey): ?>
                    <button type="button" data-range="<?= e($rangeKey) ?>" aria-pressed="<?= $index === 0 ? 'true' : 'false' ?>"><?= e(t('range.' . $rangeKey)) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
    </header>
    <div class="chart-container" data-trend-chart></div>
    <p class="chart-note" data-trend-note></p>
</section>
