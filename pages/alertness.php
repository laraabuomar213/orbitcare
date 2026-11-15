<?php
// Alertness Check: 3-minute PVT-B reaction test (the test NASA crews use on the ISS as the
// "Reaction Self Test") with optional eye signs from the camera, analysed on this device only.
// Test rules: config/alertness.php. Results and comparisons come from /api/alertness.
declare(strict_types=1);

require __DIR__ . '/../backend/bootstrap.php';
require __DIR__ . '/../frontend/components/layout.php';

$user = require_page_role('astronaut');
$config = alertness_config();
$svg = fn (string $paths, string $class = 'icon') => '<svg class="' . $class . '" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';

render_app_start($user, 'alertness', t('nav.alertness'), [
    'scripts' => ['alertness.js'],
    'subheading' => t('alertness.subtitle'),
]);
?>
<div class="alertness-layout">
    <section class="card alertness-test" aria-labelledby="alertness-test-title">
        <h2 class="visually-hidden" id="alertness-test-title"><?= e(t('alertness.test_title')) ?></h2>
        <ol class="alertness-steps" data-steps>
            <?php foreach (['prepare', 'test', 'results'] as $index => $step): ?>
                <li class="alertness-step" data-step="<?= e($step) ?>"<?= $index === 0 ? ' aria-current="step"' : '' ?>>
                    <span class="alertness-step-number"><?= $index + 1 ?></span>
                    <span class="alertness-step-label"><?= e(t("alertness.step.{$step}")) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>

        <div class="alertness-panel" data-panel tabindex="0" role="button" aria-describedby="alertness-instruction" data-state="idle">
            <p class="alertness-instruction" id="alertness-instruction" data-instruction><?= e(t('alertness.instruction')) ?></p>
            <p class="alertness-stimulus" data-stimulus aria-live="off"></p>
            <p class="alertness-message" data-message aria-live="polite"><?= e(t('alertness.ready')) ?></p>
            <p class="alertness-timer" data-timer hidden><?= $svg('<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>') ?><span data-timer-text></span></p>
        </div>

        <div class="alertness-actions">
            <button class="btn btn-primary alertness-start" type="button" data-start>
                <?= $svg('<path d="M8 5.5v13l10-6.5z" fill="currentColor" stroke="none"/>', 'icon flip-rtl') ?>
                <span data-start-label><?= e(t('alertness.start')) ?></span>
            </button>
            <button class="link-button link-button--muted" type="button" data-stop hidden><?= e(t('alertness.stop')) ?></button>
        </div>
        <p class="alertness-error" data-test-error role="alert" hidden></p>
    </section>

    <div class="alertness-side">
        <section class="card alertness-camera" aria-labelledby="camera-title">
            <header class="alertness-card-head">
                <h2 class="card-title" id="camera-title">
                    <?= $svg('<rect x="3" y="7" width="13" height="10" rx="2"/><path d="M16 10.5l5-3v9l-5-3"/><circle cx="9.5" cy="12" r="2.2"/>', 'icon icon-accent') ?>
                    <?= e(t('alertness.camera_title')) ?>
                </h2>
                <label class="alertness-toggle">
                    <span><?= e(t('alertness.use_camera')) ?></span>
                    <input class="switch" type="checkbox" data-camera-toggle>
                </label>
            </header>

            <div class="camera-view" data-camera-view data-state="off">
                <video class="camera-video" data-camera-video playsinline muted></video>
                <span class="camera-face-guide" aria-hidden="true"></span>
                <p class="camera-state" data-camera-state><?= e(t('alertness.camera_off')) ?></p>
            </div>
            <p class="camera-privacy">
                <?= $svg('<path d="M12 3l7.5 3v5.5c0 4.6-3.2 8.1-7.5 9.5-4.3-1.4-7.5-4.9-7.5-9.5V6z"/><path d="M8.8 12.2l2.3 2.3 4.3-4.6"/>') ?>
                <?= e(t('alertness.camera_privacy')) ?>
            </p>

            <div class="eye-metrics">
                <div class="eye-metric">
                    <p class="eye-metric-label"><?= e(t('alertness.blinks')) ?></p>
                    <p class="eye-metric-value" data-blinks>—</p>
                    <p class="eye-metric-usual" data-blinks-usual></p>
                </div>
                <div class="eye-metric">
                    <p class="eye-metric-label"><?= e(t('alertness.perclos')) ?></p>
                    <p class="eye-metric-value" data-perclos>—</p>
                    <p class="eye-metric-usual" data-perclos-usual></p>
                </div>
            </div>
            <p class="alertness-note">
                <?= $svg('<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5M12 8v.1"/>') ?>
                <?= e(t('alertness.camera_note')) ?>
            </p>
        </section>

        <section class="card alertness-results" aria-labelledby="results-title">
            <header class="alertness-card-head">
                <h2 class="card-title" id="results-title">
                    <?= $svg('<path d="M4 20h16M6.5 20v-6M10.5 20V9M14.5 20v-8M18.5 20V5"/>', 'icon icon-accent') ?>
                    <?= e(t('alertness.results_title')) ?>
                </h2>
                <div class="alertness-status">
                    <span class="status-chip" data-status-chip hidden></span>
                    <span class="alertness-status-note" data-status-note></span>
                </div>
            </header>

            <div class="result-tiles">
                <?php foreach (['median' => t('alertness.median'), 'lapses' => t('alertness.lapses', ['ms' => $config['test']['lapse_ms']]), 'false' => t('alertness.false_starts')] as $key => $label): ?>
                    <div class="result-tile">
                        <p class="result-tile-label"><?= e($label) ?></p>
                        <p class="result-tile-value" data-result="<?= e($key) ?>">—</p>
                        <p class="result-tile-base" data-result-base="<?= e($key) ?>"></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="alertness-history">
                <div class="alertness-history-head">
                    <span><?= e(t('alertness.last7')) ?></span>
                    <span class="alertness-legend">
                        <span class="legend-item"><span class="legend-line"></span><?= e(t('alertness.legend_you')) ?></span>
                        <span class="legend-item"><span class="legend-line legend-line--dashed"></span><?= e(t('alertness.legend_usual')) ?></span>
                    </span>
                </div>
                <div class="alertness-chart" data-history></div>
            </div>

            <a class="link-arrow alertness-ask" href="<?= e(url('pages/assistant.php?ask=' . rawurlencode(t('alertness.ask_ai_question')))) ?>"><?= e(t('alertness.ask_ai')) ?> <span class="flip-rtl" aria-hidden="true">→</span></a>

            <details class="alertness-learn">
                <summary><?= e(t('alertness.learn_more')) ?></summary>
                <ul>
                    <li><?= e(t('alertness.learn_median')) ?></li>
                    <li><?= e(t('alertness.learn_lapses', ['ms' => $config['test']['lapse_ms']])) ?></li>
                    <li><?= e(t('alertness.learn_false', ['ms' => $config['test']['false_start_ms']])) ?></li>
                    <li><?= e(t('alertness.learn_perclos')) ?></li>
                    <li><?= e(t('alertness.learn_baseline')) ?></li>
                </ul>
                <p class="alertness-learn-sources">
                    <a href="<?= e($config['sources']['pvt_b']) ?>" target="_blank" rel="noopener"><?= e(t('alertness.source_pvt')) ?></a> ·
                    <a href="<?= e($config['sources']['perclos']) ?>" target="_blank" rel="noopener"><?= e(t('alertness.source_perclos')) ?></a>
                </p>
            </details>
        </section>
    </div>

    <section class="card alertness-why" aria-labelledby="why-title">
        <?= $svg('<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.7v.1"/>', 'icon alertness-why-icon') ?>
        <div>
            <h2 class="alertness-why-title" id="why-title"><?= e(t('alertness.why_title')) ?></h2>
            <p><?= e(t('alertness.why_1')) ?></p>
            <p><?= e(t('alertness.why_2')) ?></p>
        </div>
        <a class="alertness-source" href="<?= e($config['sources']['nasa_rst']) ?>" target="_blank" rel="noopener">
            <?= e(t('checkin.source')) ?>
            <?= $svg('<path d="M14 5h5v5M19 5l-8 8M17 13.5V19H5V7h5.5"/>', 'icon flip-rtl') ?>
        </a>
    </section>
</div>
<?php render_app_end();
