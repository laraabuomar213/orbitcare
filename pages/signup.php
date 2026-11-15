<?php
// Sign-up flow:
//   Step 1 (Account)  → POST /api/auth/signup  — creates the user and signs in
//   Step 2 (Profile)  → POST /api/auth/profile — role-specific profile
//   Confirmation      → what happens next (an admin adds astronauts to a mission crew; admins wait for approval)
// If a signed-in user hasn't finished Step 2 (refresh, or first login), they land straight
// on Step 2 — nothing from Step 1 is lost because it is already saved.
declare(strict_types=1);

require __DIR__ . '/../backend/bootstrap.php';

send_no_cache_headers();
$user = current_user();

if ($user !== null && $user['profile_completed_at'] !== null) {
    redirect(home_path_for($user));
}

$isProfileStep = $user !== null;
$profileRole = $user['role'] ?? 'astronaut';

// Shared markup for a Yes/No question in Step 2.
function render_yes_no(string $name, string $question): void
{
    ?>
    <fieldset class="signup-question">
        <legend class="signup-question-text"><?= e($question) ?></legend>
        <div class="signup-segmented signup-segmented--small">
            <input class="signup-segmented-input" type="radio" id="<?= e($name) ?>-yes" name="<?= e($name) ?>" value="yes" data-field="<?= e($name) ?>">
            <label class="signup-segmented-option" for="<?= e($name) ?>-yes"><?= e(t('common.yes')) ?></label>
            <input class="signup-segmented-input" type="radio" id="<?= e($name) ?>-no" name="<?= e($name) ?>" value="no" data-field="<?= e($name) ?>">
            <label class="signup-segmented-option" for="<?= e($name) ?>-no"><?= e(t('common.no')) ?></label>
        </div>
        <p class="signup-field-error" data-error-for="<?= e($name) ?>" hidden></p>
    </fieldset>
    <?php
}

function render_input(string $name, string $label, string $type = 'text', array $attributes = []): void
{
    $id = 'signup-' . str_replace('_', '-', $name);
    $extra = '';
    foreach ($attributes as $key => $value) {
        $extra .= ' ' . $key . '="' . e((string) $value) . '"';
    }
    ?>
    <div class="signup-field">
        <label class="signup-label" for="<?= e($id) ?>"><?= e($label) ?></label>
        <input class="signup-input" id="<?= e($id) ?>" name="<?= e($name) ?>" type="<?= e($type) ?>" data-field="<?= e($name) ?>"<?= $extra ?>>
        <p class="signup-field-error" data-error-for="<?= e($name) ?>" hidden></p>
    </div>
    <?php
}

// Mirrors in Arabic (arrow points left).
$arrowIcon = '<svg class="signup-submit-icon flip-rtl" viewBox="0 0 16 16" aria-hidden="true"><path d="M3 8h10M9 4l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$medicationRow = '<input class="signup-input" name="medication_name[]" type="text" placeholder="' . e(t('signup.medication_name')) . '" aria-label="' . e(t('signup.medication_name')) . '" maxlength="150" data-field="medications">'
    . '<input class="signup-input" name="medication_purpose[]" type="text" placeholder="' . e(t('signup.purpose')) . '" aria-label="' . e(t('signup.purpose_label')) . '" maxlength="255" data-field="medications">';
?>
<!DOCTYPE html>
<html <?= html_language_attributes() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(t('signup.page_title')) ?> — OrbitCare</title>
    <meta name="description" content="<?= e(t('signup.meta_description')) ?>">

    <link rel="stylesheet" href="../assets/css/fonts.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/signup.css">

    <script>document.documentElement.classList.add('js');</script>
    <?= i18n_script_tag() ?>
    <script src="../assets/js/i18n.js"></script>
    <script src="../assets/js/modals.js" defer></script>
    <script src="../assets/js/signup.js" defer></script>
</head>
<body class="signup-page">
    <a class="skip-link" href="#signup-title"><?= e(t('signup.skip')) ?></a>

    <!-- Decorative space window (temporary crop from the design reference). -->
    <div class="signup-visual" aria-hidden="true">
        <img class="signup-visual-image" src="../assets/images/signup-window.jpg" width="760" height="816" alt="">
    </div>

    <header class="signup-header">
        <a class="brand-logo signup-logo" href="../index.php">
            <svg class="brand-logo-icon" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M12 0C12.7 7.6 16.4 11.3 24 12C16.4 12.7 12.7 16.4 12 24C11.3 16.4 7.6 12.7 0 12C7.6 11.3 11.3 7.6 12 0Z" fill="currentColor"/>
            </svg>
            <span class="brand-logo-text">OrbitCare</span>
        </a>
        <div class="signup-header-end">
            <?= language_switch_link() ?>
            <p class="signup-header-login" <?= $isProfileStep ? 'hidden' : '' ?>>
                <?= e(t('signup.have_account')) ?> <a href="#login-modal" data-open-login aria-haspopup="dialog"><?= e(t('signup.log_in')) ?></a>
            </p>
        </div>
    </header>

    <main class="signup-main">
        <div class="signup-panel">
            <h1 class="signup-title" id="signup-title" tabindex="-1"><?= e(t($isProfileStep ? 'signup.profile_title' : 'signup.title')) ?></h1>
            <p class="signup-subtitle" id="signup-subtitle"><?= e(t($isProfileStep ? "signup.profile_subtitle.{$profileRole}" : 'signup.subtitle')) ?></p>

            <div class="signup-progress" data-step="<?= $isProfileStep ? 'profile' : 'account' ?>">
                <ol class="signup-progress-steps" aria-label="<?= e(t('signup.progress')) ?>">
                    <li class="signup-progress-step" data-progress-step="account" <?= $isProfileStep ? '' : 'aria-current="step"' ?>>
                        <span class="signup-progress-number">01</span>
                        <span class="signup-progress-label"><?= e(t('settings.account')) ?></span>
                    </li>
                    <li class="signup-progress-step" data-progress-step="profile" <?= $isProfileStep ? 'aria-current="step"' : '' ?>>
                        <span class="signup-progress-number">02</span>
                        <span class="signup-progress-label"><?= e(t('nav.profile')) ?></span>
                    </li>
                </ol>
                <div class="signup-progress-track" aria-hidden="true">
                    <span class="signup-progress-fill"></span>
                </div>
            </div>

            <!-- ===================== Step 1 — Account ===================== -->
            <form class="signup-form" id="signup-account-form" action="<?= e(url('api/auth/signup')) ?>" method="post" novalidate <?= $isProfileStep ? 'hidden' : '' ?>>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <fieldset class="signup-role">
                    <legend class="signup-label signup-role-legend"><?= e(t('signup.signing_up_as')) ?></legend>
                    <div class="signup-segmented">
                        <input class="signup-segmented-input" type="radio" id="role-astronaut" name="role" value="astronaut" data-field="role" checked>
                        <label class="signup-segmented-option" for="role-astronaut"><?= e(t('role.astronaut')) ?></label>
                        <input class="signup-segmented-input" type="radio" id="role-admin" name="role" value="mission_admin" data-field="role">
                        <label class="signup-segmented-option" for="role-admin"><?= e(t('signup.admin')) ?></label>
                    </div>
                    <p class="signup-role-note" id="signup-role-note" hidden><?= e(t('signup.admin_note')) ?></p>
                    <p class="signup-field-error" data-error-for="role" hidden></p>
                </fieldset>

                <?php
                render_input('full_name', t('profile.full_name'), 'text', ['placeholder' => t('signup.full_name_placeholder'), 'autocomplete' => 'name', 'maxlength' => 120, 'required' => 'required']);
                render_input('email', t('signup.email'), 'email', ['placeholder' => 'Example@mail.com', 'autocomplete' => 'email', 'maxlength' => 254, 'required' => 'required', 'dir' => 'ltr']);
                render_input('password', t('signup.password'), 'password', ['placeholder' => t('signup.password_placeholder'), 'autocomplete' => 'new-password', 'required' => 'required', 'aria-describedby' => 'signup-password-hint']);
                ?>
                <p class="visually-hidden" id="signup-password-hint"><?= e(t('signup.password_hint')) ?></p>
                <?php render_input('confirm_password', t('signup.confirm_password'), 'password', ['placeholder' => t('signup.confirm_placeholder'), 'autocomplete' => 'new-password', 'required' => 'required']); ?>

                <p class="signup-form-error" role="alert" hidden></p>

                <button class="signup-submit" type="submit" data-loading-text="<?= e(t('signup.creating')) ?>">
                    <span class="signup-submit-text"><?= e(t('signup.continue')) ?></span>
                    <?= $arrowIcon ?>
                </button>
            </form>

            <!-- ===================== Step 2 — Profile ===================== -->
            <form class="signup-form" id="signup-profile-form" action="<?= e(url('api/auth/profile')) ?>" method="post" novalidate <?= $isProfileStep ? '' : 'hidden' ?>>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <!-- Astronaut details. Disabled sections are not submitted. -->
                <fieldset class="signup-profile-section" data-profile-role="astronaut" <?= $profileRole === 'astronaut' ? '' : 'hidden disabled' ?>>
                    <legend class="signup-label"><?= e(t('signup.astronaut_details')) ?></legend>
                    <?php render_input('astronaut_code', t('profile.astronaut_id'), 'text', ['placeholder' => t('signup.code_placeholder'), 'maxlength' => 40, 'autocomplete' => 'off', 'dir' => 'ltr']); ?>
                    <div class="signup-field-row">
                        <?php
                        render_input('age', t('profile.age'), 'number', ['min' => 18, 'max' => 80, 'inputmode' => 'numeric']);
                        render_input('height_cm', t('profile.height'), 'number', ['min' => 120, 'max' => 230, 'step' => '0.1', 'inputmode' => 'decimal']);
                        render_input('weight_kg', t('profile.weight'), 'number', ['min' => 35, 'max' => 200, 'step' => '0.1', 'inputmode' => 'decimal']);
                        ?>
                    </div>
                </fieldset>

                <!-- Admin: who is requesting access. -->
                <fieldset class="signup-profile-section" data-profile-role="mission_admin" <?= $profileRole === 'mission_admin' ? '' : 'hidden disabled' ?>>
                    <legend class="visually-hidden"><?= e(t('signup.admin_details')) ?></legend>
                    <?php
                    render_input('job_title', t('profile.job_title'), 'text', ['placeholder' => t('signup.job_placeholder'), 'autocomplete' => 'organization-title', 'maxlength' => 120]);
                    render_input('organization', t('profile.organization'), 'text', ['placeholder' => t('signup.organization_placeholder'), 'autocomplete' => 'organization', 'maxlength' => 150]);
                    ?>
                </fieldset>

                <!-- Astronaut: short medical background. -->
                <fieldset class="signup-profile-section" data-profile-role="astronaut" <?= $profileRole === 'astronaut' ? '' : 'hidden disabled' ?>>
                    <legend class="signup-label"><?= e(t('profile.medical_background')) ?></legend>

                    <?php render_yes_no('has_chronic_conditions', t('signup.q_conditions')); ?>
                    <div class="signup-reveal" data-reveal-when="has_chronic_conditions" hidden>
                        <label class="signup-label" for="signup-conditions"><?= e(t('signup.specify')) ?></label>
                        <textarea class="signup-input signup-textarea" id="signup-conditions" name="chronic_conditions" rows="2" maxlength="1000" placeholder="<?= e(t('signup.conditions_placeholder')) ?>" data-field="chronic_conditions"></textarea>
                        <p class="signup-field-error" data-error-for="chronic_conditions" hidden></p>
                    </div>

                    <?php render_yes_no('takes_medications', t('signup.q_medications')); ?>
                    <div class="signup-reveal" data-reveal-when="takes_medications" hidden>
                        <div class="signup-medication-list" id="signup-medication-list">
                            <div class="signup-medication-row"><?= $medicationRow ?></div>
                        </div>
                        <button class="signup-add-button" type="button" id="signup-add-medication"><?= e(t('signup.add_medication')) ?></button>
                        <p class="signup-field-error" data-error-for="medications" hidden></p>
                    </div>

                    <?php render_yes_no('has_allergies', t('signup.q_allergies')); ?>
                    <div class="signup-reveal" data-reveal-when="has_allergies" hidden>
                        <label class="signup-label" for="signup-allergies"><?= e(t('signup.specify')) ?></label>
                        <textarea class="signup-input signup-textarea" id="signup-allergies" name="allergies" rows="2" maxlength="1000" placeholder="<?= e(t('signup.allergies_placeholder')) ?>" data-field="allergies"></textarea>
                        <p class="signup-field-error" data-error-for="allergies" hidden></p>
                    </div>

                    <div class="signup-field">
                        <label class="signup-label" for="signup-history"><?= e(t('signup.history')) ?> <span class="signup-optional">(<?= e(t('checkin.optional')) ?>)</span></label>
                        <textarea class="signup-input signup-textarea" id="signup-history" name="medical_history_notes" rows="3" maxlength="2000" placeholder="<?= e(t('signup.history_placeholder')) ?>" data-field="medical_history_notes"></textarea>
                        <p class="signup-field-error" data-error-for="medical_history_notes" hidden></p>
                    </div>
                </fieldset>

                <p class="signup-form-error" role="alert" hidden></p>

                <button class="signup-submit" type="submit" data-loading-text="<?= e(t('common.saving')) ?>">
                    <span class="signup-submit-text"><?= e(t('signup.complete')) ?></span>
                    <?= $arrowIcon ?>
                </button>
            </form>

            <!-- ===================== Confirmation ===================== -->
            <div class="signup-confirmation" id="signup-confirmation" hidden>
                <p class="signup-confirmation-text" data-confirm-text></p>
                <a class="signup-submit" href="#" data-confirm-continue>
                    <span class="signup-submit-text"><?= e(t('signup.continue')) ?></span>
                    <?= $arrowIcon ?>
                </a>
            </div>
        </div>
    </main>

    <template id="signup-medication-row-template">
        <div class="signup-medication-row">
            <?= $medicationRow ?>
            <button class="signup-remove-button" type="button" aria-label="<?= e(t('signup.remove_medication')) ?>">×</button>
        </div>
    </template>

<?php require __DIR__ . '/../frontend/components/login-modal.php'; ?>
</body>
</html>
