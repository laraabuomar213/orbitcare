// Sign-up flow: Step 1 creates the account, Step 2 saves the role-specific profile, then a short
// confirmation says what happens next (an admin adds astronauts to a mission crew).
// Every check here is repeated on the server; these only give faster feedback.
// Server replies: { ok: true, data } or { ok: false, error: { code, message, fields } }.

const signupAccountForm = document.getElementById('signup-account-form');
const signupProfileForm = document.getElementById('signup-profile-form');
const signupConfirmation = document.getElementById('signup-confirmation');
const signupProgress = document.querySelector('.signup-progress');
const signupTitle = document.getElementById('signup-title');
const signupSubtitle = document.getElementById('signup-subtitle');
const signupHeaderLogin = document.querySelector('.signup-header-login');

// Texts come from the server in the page language (assets/js/i18n.js).
const { t } = window.OrbitI18n;


// ---------- Errors and loading ----------

function setFieldError(form, field, message) {
    const error = form.querySelector(`[data-error-for="${field}"]`);
    if (error) {
        error.textContent = message;
        error.hidden = false;
    }
    form.querySelectorAll(`[data-field="${field}"]`).forEach((input) => input.setAttribute('aria-invalid', 'true'));
}

function clearFormErrors(form) {
    form.querySelectorAll('[data-error-for]').forEach((error) => {
        error.hidden = true;
        error.textContent = '';
    });
    form.querySelectorAll('[aria-invalid]').forEach((input) => input.removeAttribute('aria-invalid'));
    const formError = form.querySelector('.signup-form-error');
    formError.hidden = true;
    formError.textContent = '';
}

function showFormError(form, message) {
    const formError = form.querySelector('.signup-form-error');
    formError.textContent = message;
    formError.hidden = false;
}

// Shows every error from the client or the server, then moves focus to the first problem.
function showErrors(form, errors, message) {
    Object.entries(errors).forEach(([field, text]) => setFieldError(form, field, text));
    if (message) {
        showFormError(form, message);
    }
    const firstInvalid = form.querySelector('[aria-invalid="true"]:not([type="hidden"])');
    if (firstInvalid) {
        firstInvalid.focus();
    }
}

function setLoading(form, isLoading) {
    const button = form.querySelector('.signup-submit');
    const label = button.querySelector('.signup-submit-text');
    if (isLoading) {
        button.dataset.idleText = label.textContent;
        label.textContent = button.dataset.loadingText;
    } else if (button.dataset.idleText) {
        label.textContent = button.dataset.idleText;
    }
    button.disabled = isLoading;
    form.setAttribute('aria-busy', String(isLoading));
}

// POSTs a form to its API endpoint. Returns the data, or throws { message, fields }.
async function submitToServer(form) {
    let response;
    try {
        response = await fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
    } catch (error) {
        throw { message: t('err.network'), fields: {} };
    }

    let payload;
    try {
        payload = await response.json();
    } catch (error) {
        throw { message: t('err.server_error'), fields: {} };
    }
    if (!response.ok || !payload.ok) {
        throw { message: payload.error?.message ?? t('err.request_failed'), fields: payload.error?.fields ?? {}, code: payload.error?.code };
    }
    return payload.data;
}


// ---------- Step 1 — Account ----------

const signupRoleNote = document.getElementById('signup-role-note');

// Explain the approval step as soon as "Admin" is chosen.
signupAccountForm.addEventListener('change', (event) => {
    if (event.target.name === 'role') {
        signupRoleNote.hidden = event.target.value !== 'mission_admin';
    }
});

function validateAccountForm(form) {
    const errors = {};
    const fullName = form.elements.full_name.value.trim();
    const emailInput = form.elements.email;
    const password = form.elements.password.value;
    const confirmPassword = form.elements.confirm_password.value;

    if (!form.querySelector('input[name="role"]:checked')) {
        errors.role = t('err.choose_role');
    }
    if (!fullName) {
        errors.full_name = t('err.full_name');
    }
    if (!emailInput.value.trim()) {
        errors.email = t('err.email');
    } else if (!emailInput.checkValidity()) {
        errors.email = t('err.email_invalid');
    }
    if (password.length < 8) {
        errors.password = t('err.password_short');
    } else if (!/\p{L}/u.test(password) || !/\d/.test(password)) {
        errors.password = t('err.password_mix');
    }
    if (!confirmPassword) {
        errors.confirm_password = t('err.confirm_password');
    } else if (confirmPassword !== password) {
        errors.confirm_password = t('err.password_mismatch');
    }
    return errors;
}

signupAccountForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearFormErrors(signupAccountForm);

    const errors = validateAccountForm(signupAccountForm);
    if (Object.keys(errors).length > 0) {
        showErrors(signupAccountForm, errors);
        return;
    }

    setLoading(signupAccountForm, true);
    try {
        const result = await submitToServer(signupAccountForm);
        showProfileStep(result.role);
    } catch (problem) {
        showErrors(signupAccountForm, problem.fields, problem.message);
    } finally {
        setLoading(signupAccountForm, false);
    }
});

function setProgress(step) {
    signupProgress.dataset.step = step;
    signupProgress.querySelectorAll('[data-progress-step]').forEach((item) => {
        if (item.dataset.progressStep === step) {
            item.setAttribute('aria-current', 'step');
        } else {
            item.removeAttribute('aria-current');
        }
    });
}

// The account now exists and the user is signed in, so Step 1 is replaced by Step 2.
function showProfileStep(role) {
    signupAccountForm.hidden = true;
    signupAccountForm.reset();
    signupProfileForm.hidden = false;
    signupHeaderLogin.hidden = true;
    setProgress('profile');

    // Only the sections for this role are shown — and submitted (disabled fieldsets aren't sent).
    signupProfileForm.querySelectorAll('[data-profile-role]').forEach((section) => {
        const isActive = section.dataset.profileRole === role;
        section.hidden = !isActive;
        section.disabled = !isActive;
    });

    signupTitle.textContent = t('signup.profile_title');
    signupSubtitle.textContent = t(`signup.profile_subtitle.${role}`);
    window.scrollTo(0, 0);
    signupTitle.focus();
}


// ---------- Step 2 — Profile ----------

// "Yes" reveals the follow-up field for that question.
signupProfileForm.addEventListener('change', (event) => {
    const reveal = signupProfileForm.querySelector(`[data-reveal-when="${event.target.name}"]`);
    if (reveal) {
        reveal.hidden = event.target.value !== 'yes';
    }
});

const medicationList = document.getElementById('signup-medication-list');
const medicationRowTemplate = document.getElementById('signup-medication-row-template');
const addMedicationButton = document.getElementById('signup-add-medication');
const MAX_MEDICATIONS = 10;

addMedicationButton.addEventListener('click', () => {
    medicationList.append(medicationRowTemplate.content.cloneNode(true));
    medicationList.lastElementChild.querySelector('input').focus();
    addMedicationButton.hidden = medicationList.children.length >= MAX_MEDICATIONS;
});

medicationList.addEventListener('click', (event) => {
    if (event.target.closest('.signup-remove-button')) {
        event.target.closest('.signup-medication-row').remove();
        addMedicationButton.hidden = false;
        addMedicationButton.focus();
    }
});

function selectedAnswer(form, name) {
    const checked = form.querySelector(`input[name="${name}"]:checked`);
    return checked ? checked.value : null;
}

function checkRange(errors, form, field, min, max) {
    const value = Number(form.elements[field].value);
    if (form.elements[field].value === '' || Number.isNaN(value) || value < min || value > max) {
        errors[field] = t('err.number_range', { label: t(`field.${field}`), min, max });
    }
}

function validateProfileForm(form) {
    const errors = {};
    const role = form.querySelector('[data-profile-role]:not([disabled])').dataset.profileRole;

    if (role === 'astronaut') {
        if (!/^[A-Za-z0-9][A-Za-z0-9_-]{1,39}$/.test(form.elements.astronaut_code.value.trim())) {
            errors.astronaut_code = t('err.astronaut_code');
        }
        checkRange(errors, form, 'age', 18, 80);
        checkRange(errors, form, 'height_cm', 120, 230);
        checkRange(errors, form, 'weight_kg', 35, 200);

        ['has_chronic_conditions', 'takes_medications', 'has_allergies'].forEach((question) => {
            if (!selectedAnswer(form, question)) {
                errors[question] = t('err.yes_no');
            }
        });
        if (selectedAnswer(form, 'has_chronic_conditions') === 'yes' && !form.elements.chronic_conditions.value.trim()) {
            errors.chronic_conditions = t('err.conditions');
        }
        if (selectedAnswer(form, 'has_allergies') === 'yes' && !form.elements.allergies.value.trim()) {
            errors.allergies = t('err.allergies');
        }
        if (selectedAnswer(form, 'takes_medications') === 'yes') {
            const rows = [...medicationList.querySelectorAll('.signup-medication-row')]
                .map((row) => [...row.querySelectorAll('input')].map((input) => input.value.trim()))
                .filter(([name, purpose]) => name || purpose);
            if (rows.length === 0) {
                errors.medications = t('err.medication_none');
            } else if (rows.some(([name, purpose]) => !name || !purpose)) {
                errors.medications = t('err.medication_pair');
            }
        }
    } else {
        if (!form.elements.job_title.value.trim()) {
            errors.job_title = t('err.job_title');
        }
        if (!form.elements.organization.value.trim()) {
            errors.organization = t('err.organization');
        }
    }
    return errors;
}

signupProfileForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearFormErrors(signupProfileForm);

    const errors = validateProfileForm(signupProfileForm);
    if (Object.keys(errors).length > 0) {
        showErrors(signupProfileForm, errors, t('err.complete_fields'));
        return;
    }

    setLoading(signupProfileForm, true);
    try {
        showConfirmation(await submitToServer(signupProfileForm));
    } catch (problem) {
        if (problem.code === 'not_authenticated' || problem.code === 'session_expired') {
            window.location.href = '../index.php?login=expired';
            return;
        }
        showErrors(signupProfileForm, problem.fields, problem.message);
    } finally {
        setLoading(signupProfileForm, false);
    }
});


// ---------- Mission confirmation ----------

function showConfirmation(result) {
    signupProfileForm.hidden = true;
    signupConfirmation.hidden = false;
    signupProgress.dataset.step = 'done';

    signupTitle.textContent = t('signup.done_title');
    signupSubtitle.textContent = t('signup.done_subtitle');
    signupConfirmation.querySelector('[data-confirm-text]').textContent = t(result.pending_approval ? 'signup.done_pending' : 'signup.done_added');

    const next = signupConfirmation.querySelector('[data-confirm-continue]');
    next.href = result.redirect;
    signupTitle.focus();
}
