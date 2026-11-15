// Daily Check-in page: fills today's answers, saves them, and shows the backend's
// "What we noticed" insights, real 7-day trends and the privacy setting.
// Rules and thresholds live on the backend; this file only displays them.
import { api, t, LOCALE, escapeHtml, formatTime, clearDraft } from './api.js';

const form = document.querySelector('[data-checkin-form]');
const saveButton = form.querySelector('[data-save]');
const formError = form.querySelector('[data-form-error]');
const wellbeingBox = document.querySelector('[data-wellbeing-questions]');
const openWellbeing = document.querySelector('[data-wellbeing-open]');
let definition = null;      // labels and target from the backend
let showOriginal = false;   // Arabic only: show validated scales in their original English wording

const utcDate = (date, options) => new Date(`${date}T00:00:00Z`).toLocaleDateString(LOCALE, { ...options, timeZone: 'UTC' });

// ---------- Loading ----------

async function load() {
    try {
        render(await api.get('checkins/today'));
    } catch (error) {
        document.querySelector('[data-insights]').innerHTML = `<div class="state-block state-block--error"><p>${escapeHtml(error.message)}</p></div>`;
        document.querySelector('[data-saved-state]').textContent = t('checkin.load_failed');
    }
    loadPrivacy();
}

function render(data) {
    definition = data.form;
    document.querySelector('[data-mission-day]').textContent = utcDate(data.mission_day, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });

    const saved = document.querySelector('[data-saved-state]');
    if (data.checkin) {
        fill(data.checkin);
        saved.textContent = t('checkin.saved_at', { time: formatTime(data.checkin.saved_at) });
        saved.classList.add('is-saved');
        saveButton.textContent = t('checkin.update');
    } else {
        saved.textContent = t('checkin.not_yet');
    }

    renderWellbeing(data.wellbeing);
    renderRadiation(data.radiation);
    renderInsights(data.insights, Boolean(data.checkin));
    renderTrends(data.trends);
    updateProgress();
}

function fill(checkin) {
    form.elements.sleep_hours.value = checkin.sleep_hours;
    setRadio('sleep_quality', checkin.sleep_quality);
    setRadio('fatigue_level', checkin.fatigue_level);
    form.elements.resistance_minutes.value = checkin.resistance_minutes;
    form.elements.aerobic_minutes.value = checkin.aerobic_minutes;
    form.elements.radiation_dose_msv.value = checkin.radiation_dose_msv ?? '';
    if (checkin.notes && !form.elements.notes.value) form.elements.notes.value = checkin.notes;
    form.querySelectorAll('input[name="symptoms"]').forEach((box) => { box.checked = checkin.symptoms.includes(box.value); });
    updateFatigueLabel();
    updateExercise();
    updateNotesCount();
}

function setRadio(name, value) {
    const input = form.querySelector(`input[name="${name}"][value="${value}"]`);
    if (input) input.checked = true;
}

// ---------- Weekly wellbeing ----------

function renderWellbeing(wellbeing) {
    const badge = document.querySelector('[data-wellbeing-badge]');
    const status = document.querySelector('[data-wellbeing-status]');

    badge.textContent = wellbeing.due ? t('checkin.wellbeing_due') : t('checkin.wellbeing_next', { date: utcDate(wellbeing.next_due_on, { day: 'numeric', month: 'short' }) });
    badge.classList.toggle('is-due', wellbeing.due);
    status.textContent = wellbeing.last
        ? t('checkin.wellbeing_last', {
            date: utcDate(wellbeing.last.completed_on, { day: 'numeric', month: 'short' }),
            score: wellbeing.last.total_score,
            band: t(`checkin.band.${wellbeing.last.band}`),
        })
        : t('checkin.wellbeing_never');

    wellbeingBox.hidden = !wellbeing.due;
    openWellbeing.hidden = wellbeing.due;
    clearWellbeing();
}

function clearWellbeing() {
    wellbeingBox.querySelectorAll('input[type="radio"]').forEach((input) => { input.checked = false; });
}

openWellbeing.addEventListener('click', () => {
    wellbeingBox.hidden = false;
    openWellbeing.hidden = true;
    wellbeingBox.querySelector('input').focus();
});
document.querySelector('[data-wellbeing-clear]').addEventListener('click', clearWellbeing);

function wellbeingAnswers() {
    const answers = {};
    let answered = 0;
    for (let item = 1; item <= 4; item++) {
        const checked = form.querySelector(`input[name="wellbeing_${item}"]:checked`);
        if (checked) {
            answers[item] = Number(checked.value);
            answered++;
        }
    }
    return { answers, answered };
}

// ---------- Validated scales: unofficial Arabic translation ⇄ original English ----------

document.querySelectorAll('[data-show-original]').forEach((button) => {
    button.addEventListener('click', () => {
        showOriginal = !showOriginal;
        document.querySelectorAll('[data-validated]').forEach((element) => {
            const shown = element.textContent;
            element.textContent = element.dataset.original;
            element.dataset.original = shown;
            element.closest('legend, p, span')?.setAttribute('dir', showOriginal ? 'ltr' : 'rtl');
        });
        document.querySelectorAll('[data-original-title]').forEach((element) => {
            const shown = element.title;
            element.title = element.dataset.originalTitle;
            element.dataset.originalTitle = shown;
        });
        document.querySelectorAll('[data-show-original]').forEach((other) => {
            other.textContent = t(showOriginal ? 'checkin.show_translation' : 'checkin.show_original');
            other.setAttribute('aria-pressed', String(showOriginal));
        });
        updateFatigueLabel();
    });
});

// ---------- Live feedback while filling in ----------

function updateFatigueLabel() {
    const checked = form.querySelector('input[name="fatigue_level"]:checked');
    const label = document.querySelector('[data-fatigue-label]');
    const labels = definition ? (showOriginal ? definition.fatigue_original : definition.fatigue) : null;
    label.textContent = checked && labels ? `${checked.value} — ${labels[checked.value]}` : t('checkin.fatigue_choose');
    label.classList.toggle('is-chosen', Boolean(checked));
}

function updateExercise() {
    const total = (Number(form.elements.resistance_minutes.value) || 0) + (Number(form.elements.aerobic_minutes.value) || 0);
    const target = definition?.exercise_target_minutes ?? 90;
    document.querySelector('[data-exercise-total]').textContent = total;
    document.querySelector('[data-exercise-bar]').style.width = `${Math.min(100, (total / target) * 100)}%`;
}

function updateNotesCount() {
    document.querySelector('[data-notes-count]').textContent = form.elements.notes.value.length;
}

function requiredAnswers() {
    const filled = (name) => form.elements[name].value !== '';
    return [
        filled('sleep_hours'),
        Boolean(form.querySelector('input[name="sleep_quality"]:checked')),
        Boolean(form.querySelector('input[name="fatigue_level"]:checked')),
        filled('resistance_minutes') && filled('aerobic_minutes'),
        Boolean(form.querySelector('input[name="symptoms"]:checked')),
    ];
}

function updateProgress() {
    const done = requiredAnswers().filter(Boolean).length;
    document.querySelector('[data-progress]').textContent = done === 5 ? t('checkin.progress_done') : t('checkin.progress', { done });
}

// "None" and other symptoms exclude each other.
document.querySelector('[data-symptoms]').addEventListener('change', (event) => {
    if (!event.target.checked) return;
    const isNone = event.target.value === 'none';
    form.querySelectorAll('input[name="symptoms"]').forEach((box) => {
        if (box !== event.target && (isNone || box.value === 'none')) box.checked = false;
    });
});

// − / + buttons.
form.addEventListener('click', (event) => {
    const button = event.target.closest('[data-step]');
    if (!button) return;
    const input = button.parentElement.querySelector('input');
    const step = Number(button.dataset.step);
    const next = Math.min(Number(input.max), Math.max(Number(input.min), (Number(input.value) || 0) + step));
    input.value = Number.isInteger(next) ? next : next.toFixed(1);
    input.dispatchEvent(new Event('input', { bubbles: true }));
});

form.addEventListener('input', () => {
    updateFatigueLabel();
    updateExercise();
    updateNotesCount();
    updateProgress();
});
form.addEventListener('change', updateProgress);

// ---------- Saving ----------

function clearErrors() {
    form.querySelectorAll('[data-error-for]').forEach((el) => { el.hidden = true; el.textContent = ''; });
    formError.hidden = true;
}

function showErrors(fields, message) {
    Object.entries(fields).forEach(([field, text]) => {
        const el = form.querySelector(`[data-error-for="${field}"]`);
        if (el) { el.textContent = text; el.hidden = false; }
    });
    formError.textContent = message;
    formError.hidden = false;
    form.querySelector('[data-error-for]:not([hidden])')?.closest('.checkin-card')?.scrollIntoView({ block: 'center' });
}

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearErrors();

    const missing = {};
    const [sleepHours, sleepQuality, fatigue, exercise, symptoms] = requiredAnswers();
    if (!sleepHours) missing.sleep_hours = t('checkin.missing.sleep_hours');
    if (!sleepQuality) missing.sleep_quality = t('err.checkin.sleep_quality');
    if (!fatigue) missing.fatigue_level = t('err.checkin.fatigue');
    if (!exercise) missing.resistance_minutes = t('checkin.missing.exercise');
    if (!symptoms) missing.symptoms = t('err.checkin.symptoms');
    const wellbeing = wellbeingAnswers();
    if (wellbeing.answered > 0 && wellbeing.answered < 4) missing.wellbeing = t('checkin.missing.wellbeing');
    if (Object.keys(missing).length) {
        showErrors(missing, t('checkin.missing.summary'));
        return;
    }

    saveButton.disabled = true;
    saveButton.textContent = t('common.saving');
    try {
        const data = await api.put('checkins/today', {
            sleep_hours: form.elements.sleep_hours.value,
            sleep_quality: form.querySelector('input[name="sleep_quality"]:checked').value,
            fatigue_level: form.querySelector('input[name="fatigue_level"]:checked').value,
            resistance_minutes: form.elements.resistance_minutes.value,
            aerobic_minutes: form.elements.aerobic_minutes.value,
            symptoms: [...form.querySelectorAll('input[name="symptoms"]:checked')].map((box) => box.value),
            radiation_dose_msv: form.elements.radiation_dose_msv.value,
            notes: form.elements.notes.value,
            wellbeing: wellbeing.answered === 4 ? wellbeing.answers : null,
        });
        clearDraft('checkin-notes');
        render(data);
        document.querySelector('[data-insights]').scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (error) {
        showErrors(error.fields ?? {}, error.message);
        saveButton.textContent = document.querySelector('[data-saved-state]').classList.contains('is-saved') ? t('checkin.update') : t('checkin.save');
    } finally {
        saveButton.disabled = false;
    }
});

// ---------- Side column ----------

function renderInsights(insights, hasCheckin) {
    const box = document.querySelector('[data-insights]');
    if (!hasCheckin && !insights.length) {
        box.innerHTML = `<div class="state-block"><p>${escapeHtml(t('checkin.insights_empty'))}</p></div>`;
        return;
    }
    box.innerHTML = insights.map((i) => `
        <article class="insight" data-level="${escapeHtml(i.level)}">
            <h3>${escapeHtml(i.title)}</h3>
            <p>${escapeHtml(i.text)}</p>
            <p class="insight-meta">${escapeHtml(t('checkin.rule', { rule: i.rule }))}${i.source ? ` · <a href="${escapeHtml(i.source.url)}" target="_blank" rel="noopener" title="${escapeHtml(i.source.label)}">${escapeHtml(t('checkin.source'))}</a>` : ''}</p>
        </article>`).join('');
}

function renderRadiation(radiation) {
    document.querySelector('[data-radiation-total]').textContent = radiation.readings
        ? t(radiation.readings === 1 ? 'checkin.radiation_total_one' : 'checkin.radiation_total', { n: radiation.readings, total: radiation.total_msv })
        : t('checkin.radiation_none');
}

// Simple bar strips. Days without a check-in stay empty — nothing is filled in.
function renderTrends(trends) {
    const target = definition?.exercise_target_minutes ?? 90;
    const rows = [
        { key: 'sleep_hours', label: t('checkin.trend.sleep'), unit: t('unit.h'), max: 12 },
        { key: 'fatigue_level', label: t('checkin.trend.fatigue'), unit: '/7', max: 7 },
        { key: 'exercise_minutes', label: t('checkin.trend.exercise'), unit: t('unit.min'), max: Math.max(target, ...trends.map((day) => day.exercise_minutes ?? 0)) },
    ];
    const days = trends.map((day) => utcDate(day.date, { weekday: 'narrow' }));

    document.querySelector('[data-trends]').innerHTML = rows.map((row) => {
        const values = trends.map((day) => day[row.key]);
        const known = values.filter((v) => v !== null);
        const average = known.length ? (known.reduce((a, b) => a + b, 0) / known.length).toFixed(1).replace(/\.0$/, '') : '—';
        return `<div class="mini-trend">
            <div class="mini-trend-head"><span>${escapeHtml(row.label)}</span><strong>${average}${known.length ? ` <small>${escapeHtml(t('checkin.trend.avg', { unit: row.unit }))}</small>` : ''}</strong></div>
            <div class="mini-bars" role="img" aria-label="${escapeHtml(t('checkin.trend.aria', { label: row.label, n: known.length }))}">
                ${values.map((v, i) => `<span class="mini-bar${v === null ? ' is-empty' : ''}" style="--h:${v === null ? 0 : Math.max(6, (v / row.max) * 100)}%" title="${escapeHtml(`${trends[i].date}: ${v === null ? t('checkin.trend.no_checkin') : `${v} ${row.unit}`}`)}"></span>`).join('')}
            </div>
            <div class="mini-days">${days.map((d) => `<span>${escapeHtml(d)}</span>`).join('')}</div>
        </div>`;
    }).join('');

    const recorded = trends.filter((day) => day.sleep_hours !== null).length;
    document.querySelector('[data-trends-note]').textContent = t(recorded < 3 ? 'checkin.trend.few' : 'checkin.trend.enough', { n: recorded });
}

async function loadPrivacy() {
    const toggle = document.querySelector('[data-share-toggle]');
    const state = document.querySelector('[data-share-state]');
    try {
        const settings = await api.get('settings');
        toggle.checked = Boolean(settings.privacy?.share_checkins_with_mission);
    } catch (error) {
        toggle.disabled = true;
        state.textContent = error.message;
    }
    toggle.addEventListener('change', async () => {
        try {
            const settings = await api.put('settings', { privacy: { share_checkins_with_mission: toggle.checked } });
            toggle.checked = Boolean(settings.privacy.share_checkins_with_mission);
            state.textContent = t(toggle.checked ? 'checkin.share_on' : 'checkin.share_off');
        } catch (error) {
            toggle.checked = !toggle.checked;
            state.textContent = error.message;
        }
    });
}

load();
