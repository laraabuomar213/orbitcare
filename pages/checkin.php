<?php
// Daily Check-in: self-reported indicators for the challenge's risk areas that sensors can't
// measure (sleep & fatigue, exercise countermeasures, immune-related symptoms, radiation
// readings, weekly PHQ-4). Scales come from config/checkin.php, texts from backend/lang, values from the API.
//
// PHQ-4 and the Samn-Perelli scale are validated instruments in English. In Arabic they are shown
// as an unofficial translation, with a switch to the original English wording (data-original).
declare(strict_types=1);

require __DIR__ . '/../backend/bootstrap.php';
require __DIR__ . '/../frontend/components/layout.php';

$user = require_page_role('astronaut');
$config = checkin_config();
$isArabic = current_language() === 'ar';

$source = function (string $key) use ($config): string {
    $info = checkin_source($config, $key);
    return '<a class="checkin-source" href="' . e($info['url']) . '" target="_blank" rel="noopener" title="' . e($info['label']) . '">' . e(t('checkin.source')) . '</a>';
};
// Text of a validated instrument: translated, remembering the English original for the switch.
$validated = fn (string $key) => '<span data-validated data-original="' . e(translate_in('en', $key)) . '">' . e(t($key)) . '</span>';

$minus = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 8h9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
$plus = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 8h9M8 3.5v9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';

// Number input with − / + buttons.
$stepper = function (string $name, string $label, float $min, float $max, float $step, string $unit, string $example) use ($minus, $plus): string {
    $id = 'checkin-' . str_replace('_', '-', $name);
    return '<div class="checkin-field">'
        . '<label class="checkin-label" for="' . e($id) . '">' . e($label) . '</label>'
        . '<div class="stepper">'
        . '<button class="stepper-button" type="button" data-step="-' . $step . '" aria-label="' . e(t('checkin.decrease', ['label' => $label])) . '">' . $minus . '</button>'
        . '<input class="stepper-input" id="' . e($id) . '" name="' . e($name) . '" type="number" inputmode="decimal" min="' . $min . '" max="' . $max . '" step="' . $step . '" placeholder="' . e($example) . '">'
        . '<span class="stepper-unit">' . e($unit) . '</span>'
        . '<button class="stepper-button" type="button" data-step="' . $step . '" aria-label="' . e(t('checkin.increase', ['label' => $label])) . '">' . $plus . '</button>'
        . '</div><p class="checkin-error" data-error-for="' . e($name) . '" hidden></p></div>';
};

$translationNote = $isArabic
    ? '<p class="translation-note">' . e(t('checkin.unofficial_translation')) . ' <button class="link-button" type="button" data-show-original aria-pressed="false">' . e(t('checkin.show_original')) . '</button></p>'
    : '';

render_app_start($user, 'checkin', t('nav.checkin'), ['scripts' => ['checkin.js'], 'heading_in_page' => true]);
?>
<div class="checkin-layout">
    <form class="checkin-form" data-checkin-form novalidate>
        <header class="checkin-head">
            <div>
                <h1 class="checkin-title"><?= e(t('nav.checkin')) ?></h1>
                <p class="checkin-subtitle"><?= e(t('checkin.subtitle')) ?></p>
            </div>
            <div class="checkin-day">
                <span class="checkin-day-label"><?= e(t('checkin.mission_day')) ?></span>
                <strong data-mission-day>—</strong>
                <span class="checkin-saved" data-saved-state><?= e(t('common.loading')) ?></span>
            </div>
        </header>

        <section class="card checkin-card" aria-labelledby="sleep-title">
            <div class="checkin-card-head">
                <h2 class="checkin-card-title" id="sleep-title"><?= e(t('checkin.sleep_title')) ?></h2>
                <p class="checkin-why"><?= e(t('checkin.sleep_why')) ?> <?= $source('nasa_sleep') ?></p>
            </div>

            <div class="checkin-row">
                <?= $stepper('sleep_hours', t('checkin.hours_slept'), 0, 24, 0.5, t('unit.h'), '7') ?>
                <fieldset class="checkin-fieldset">
                    <legend class="checkin-label"><?= e(t('checkin.sleep_quality')) ?></legend>
                    <div class="choice-row">
                        <?php foreach (array_keys($config['sleep_quality']) as $value): ?>
                            <label class="choice"><input type="radio" name="sleep_quality" value="<?= (int) $value ?>"><span><?= e(t("checkin.sleep_quality.{$value}")) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <p class="checkin-error" data-error-for="sleep_quality" hidden></p>
                </fieldset>
            </div>

            <fieldset class="checkin-fieldset checkin-fatigue">
                <legend class="checkin-label"><?= e(t('checkin.fatigue_question')) ?></legend>
                <div class="scale-row">
                    <?php foreach (array_keys($config['fatigue']) as $value): ?>
                        <label class="scale-step" title="<?= e(t("checkin.fatigue.{$value}")) ?>" data-original-title="<?= e(translate_in('en', "checkin.fatigue.{$value}")) ?>"><input type="radio" name="fatigue_level" value="<?= (int) $value ?>"><span><?= (int) $value ?></span></label>
                    <?php endforeach; ?>
                </div>
                <div class="scale-ends"><?= $validated('checkin.fatigue_end_low') ?><?= $validated('checkin.fatigue_end_high') ?></div>
                <p class="scale-selected" data-fatigue-label aria-live="polite"><?= e(t('checkin.fatigue_choose')) ?></p>
                <p class="checkin-hint"><?= e(t('checkin.fatigue_hint')) ?> <?= $source('samn_perelli') ?></p>
                <?= $translationNote ?>
                <p class="checkin-error" data-error-for="fatigue_level" hidden></p>
            </fieldset>
        </section>

        <section class="card checkin-card" aria-labelledby="exercise-title">
            <div class="checkin-card-head">
                <h2 class="checkin-card-title" id="exercise-title"><?= e(t('checkin.exercise_title')) ?></h2>
                <p class="checkin-why"><?= e(t('checkin.exercise_why')) ?> <?= $source('nasa_exercise') ?></p>
            </div>
            <div class="checkin-row">
                <?= $stepper('resistance_minutes', t('checkin.resistance'), 0, 600, 5, t('unit.min'), '0') ?>
                <?= $stepper('aerobic_minutes', t('checkin.aerobic'), 0, 600, 5, t('unit.min'), '0') ?>
                <div class="exercise-progress">
                    <div class="exercise-progress-text"><?= str_replace('{done}', '<strong data-exercise-total>0</strong>', e(t('checkin.exercise_progress', ['target' => (int) $config['exercise_target_minutes']]))) ?></div>
                    <div class="progress-track"><span class="progress-fill" data-exercise-bar></span></div>
                </div>
            </div>
        </section>

        <section class="card checkin-card" aria-labelledby="symptoms-title">
            <div class="checkin-card-head">
                <h2 class="checkin-card-title" id="symptoms-title"><?= e(t('checkin.symptoms_title')) ?></h2>
                <p class="checkin-why"><?= e(t('checkin.symptoms_why')) ?> <?= $source('nasa_immune') ?></p>
            </div>
            <div class="chip-row" data-symptoms>
                <?php foreach (array_keys($config['symptoms']) as $key): ?>
                    <label class="chip-toggle"><input type="checkbox" name="symptoms" value="<?= e($key) ?>"><span><?= e(t("checkin.symptom.{$key}")) ?></span></label>
                <?php endforeach; ?>
                <label class="chip-toggle chip-toggle--none"><input type="checkbox" name="symptoms" value="none"><span><?= e(t('checkin.symptom.none')) ?></span></label>
            </div>
            <p class="checkin-error" data-error-for="symptoms" hidden></p>
        </section>

        <section class="card checkin-card" aria-labelledby="radiation-title">
            <div class="checkin-card-head">
                <h2 class="checkin-card-title" id="radiation-title"><?= e(t('checkin.radiation_title')) ?> <span class="checkin-optional"><?= e(t('checkin.optional')) ?></span></h2>
                <p class="checkin-why"><?= e(t('checkin.radiation_why')) ?> <?= $source('nasa_radiation') ?></p>
            </div>
            <div class="checkin-row checkin-row--center">
                <div class="checkin-field">
                    <label class="checkin-label" for="checkin-radiation"><?= e(t('checkin.dosimeter')) ?></label>
                    <div class="unit-input"><input id="checkin-radiation" name="radiation_dose_msv" type="number" inputmode="decimal" min="0" max="1000" step="0.001" placeholder="<?= e(t('checkin.dosimeter_placeholder')) ?>"><span><?= e(t('unit.mSv')) ?></span></div>
                    <p class="checkin-error" data-error-for="radiation_dose_msv" hidden></p>
                </div>
                <p class="checkin-hint" data-radiation-total></p>
            </div>
        </section>

        <section class="card checkin-card" aria-labelledby="wellbeing-title" data-wellbeing>
            <div class="checkin-card-head checkin-card-head--row">
                <div>
                    <h2 class="checkin-card-title" id="wellbeing-title"><?= e(t('checkin.wellbeing_title')) ?></h2>
                    <p class="checkin-why"><?= e(t('checkin.wellbeing_why')) ?> <?= $source('phq4') ?></p>
                </div>
                <span class="checkin-badge" data-wellbeing-badge></span>
            </div>
            <p class="checkin-hint" data-wellbeing-status></p>
            <button class="link-button" type="button" data-wellbeing-open hidden><?= e(t('checkin.wellbeing_open')) ?></button>

            <div class="wellbeing-questions" data-wellbeing-questions hidden>
                <?= $translationNote ?>
                <p class="wellbeing-stem"><?= $validated('checkin.phq.stem') ?></p>
                <?php foreach (array_keys($config['phq4']['items']) as $item): ?>
                    <fieldset class="wellbeing-item">
                        <legend><?= $validated("checkin.phq.item.{$item}") ?></legend>
                        <div class="choice-row choice-row--compact">
                            <?php foreach (array_keys($config['phq4']['options']) as $score): ?>
                                <label class="choice"><input type="radio" name="wellbeing_<?= (int) $item ?>" value="<?= (int) $score ?>"><span><?= $validated("checkin.phq.option.{$score}") ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                <?php endforeach; ?>
                <button class="link-button link-button--muted" type="button" data-wellbeing-clear><?= e(t('checkin.wellbeing_clear')) ?></button>
                <p class="checkin-error" data-error-for="wellbeing" hidden></p>
            </div>
        </section>

        <details class="card checkin-card checkin-notes">
            <summary><?= e(t('checkin.add_note')) ?> <span class="checkin-optional"><?= e(t('checkin.optional')) ?></span></summary>
            <textarea class="checkin-textarea" name="notes" maxlength="500" rows="3" placeholder="<?= e(t('checkin.note_placeholder')) ?>" data-draft="checkin-notes"></textarea>
            <p class="checkin-hint"><bdi><span data-notes-count>0</span> / 500</bdi></p>
            <p class="checkin-error" data-error-for="notes" hidden></p>
        </details>

        <div class="checkin-savebar">
            <div>
                <p class="checkin-progress" data-progress><?= e(t('checkin.progress', ['done' => 0])) ?></p>
                <p class="checkin-error" data-form-error role="alert" hidden></p>
            </div>
            <button class="btn btn-primary checkin-save" type="submit" data-save><?= e(t('checkin.save')) ?></button>
        </div>
    </form>

    <aside class="checkin-side">
        <section class="card" aria-labelledby="insights-title">
            <h2 class="card-title" id="insights-title"><?= e(t('checkin.insights_title')) ?></h2>
            <div class="insight-list" data-insights aria-live="polite"><div class="state-block"><p><?= e(t('common.loading')) ?></p></div></div>
            <a class="link-arrow checkin-ai-link" href="<?= e(url('pages/assistant.php?ask=' . rawurlencode(t('checkin.ask_ai_question')))) ?>"><?= e(t('checkin.talk_to_ai')) ?> <span class="flip-rtl" aria-hidden="true">→</span></a>
        </section>

        <section class="card" aria-labelledby="trends-title">
            <h2 class="card-title" id="trends-title"><?= e(t('checkin.trends_title')) ?></h2>
            <div class="mini-trends" data-trends></div>
            <p class="checkin-hint" data-trends-note></p>
        </section>

        <section class="card" aria-labelledby="privacy-title">
            <h2 class="card-title" id="privacy-title"><?= e(t('settings.privacy')) ?></h2>
            <label class="toggle-row">
                <span><?= e(t('checkin.share_label')) ?><small><?= e(t('checkin.share_hint')) ?></small></span>
                <input class="switch" type="checkbox" data-share-toggle>
            </label>
            <p class="checkin-hint" data-share-state aria-live="polite"></p>
        </section>
    </aside>
</div>
<?php render_app_end();
