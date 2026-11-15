// Alertness Check page: runs the 3-minute PVT-B, optional on-device eye signs, and shows results
// compared with the astronaut's own baseline (computed by the backend).
//
// PVT-B (Basner, Mollicone & Dinges 2011, rules in config/alertness.php):
//   a millisecond counter appears every 1–4 s (including 1 s of feedback after each response);
//   the astronaut taps / presses Space as fast as possible; the counter stops and shows the time.
// Timing: the number's onset is the animation frame that draws it; the response time is the input
// event's own timestamp (not when the handler happened to run).
import { api, t, escapeHtml, formatNumber } from './api.js';
import { EyeTracker } from './eye-tracker.js';

const page = {
    steps: document.querySelectorAll('[data-steps] [data-step]'),
    panel: document.querySelector('[data-panel]'),
    instruction: document.querySelector('[data-instruction]'),
    stimulus: document.querySelector('[data-stimulus]'),
    message: document.querySelector('[data-message]'),
    timer: document.querySelector('[data-timer]'),
    timerText: document.querySelector('[data-timer-text]'),
    start: document.querySelector('[data-start]'),
    startLabel: document.querySelector('[data-start-label]'),
    stop: document.querySelector('[data-stop]'),
    error: document.querySelector('[data-test-error]'),
    cameraToggle: document.querySelector('[data-camera-toggle]'),
    cameraView: document.querySelector('[data-camera-view]'),
    cameraState: document.querySelector('[data-camera-state]'),
    video: document.querySelector('[data-camera-video]'),
    blinks: document.querySelector('[data-blinks]'),
    blinksUsual: document.querySelector('[data-blinks-usual]'),
    perclos: document.querySelector('[data-perclos]'),
    perclosUsual: document.querySelector('[data-perclos-usual]'),
    statusChip: document.querySelector('[data-status-chip]'),
    statusNote: document.querySelector('[data-status-note]'),
    history: document.querySelector('[data-history]'),
};

let overview = null;   // GET /api/alertness
let tracker = null;    // EyeTracker while the camera is on
let run = null;        // the test in progress

// ---------- Loading and results ----------

async function load() {
    try {
        overview = await api.get('alertness');
        renderResults();
    } catch (error) {
        showError(error.message);
        page.start.disabled = true;
    }
}

function setStep(step) {
    const order = ['prepare', 'test', 'results'];
    page.steps.forEach((item) => {
        if (item.dataset.step === step) item.setAttribute('aria-current', 'step');
        else item.removeAttribute('aria-current');
        item.classList.toggle('is-done', order.indexOf(item.dataset.step) < order.indexOf(step));
    });
}

function renderResults() {
    const { latest, baseline, status } = overview;
    const value = (key, fallback = '—') => latest && latest[key] !== null ? formatNumber(latest[key]) : fallback;
    const base = (key) => (baseline.ready && baseline[key] !== null ? t('alertness.baseline', { value: formatNumber(baseline[key]) }) : t('alertness.baseline_none'));

    document.querySelector('[data-result="median"]').textContent = value('median_rt_ms');
    document.querySelector('[data-result="lapses"]').textContent = value('lapses');
    document.querySelector('[data-result="false"]').textContent = value('false_starts');
    document.querySelector('[data-result-base="median"]').textContent = latest ? base('median_rt_ms') : '';
    document.querySelector('[data-result-base="lapses"]').textContent = latest ? base('lapses') : '';
    document.querySelector('[data-result-base="false"]').textContent = latest ? base('false_starts') : '';

    page.statusChip.hidden = !status;
    if (status) {
        page.statusChip.dataset.level = status.level;
        page.statusChip.textContent = t(`alertness.status.${status.level}`);
        page.statusNote.textContent = status.level === 'building'
            ? t('alertness.building', { n: status.tests, needed: status.needed })
            : t('alertness.compared');
    } else {
        page.statusNote.textContent = t('alertness.no_tests');
    }

    if (!run) renderEyeValues(latest?.blinks_per_minute ?? null, latest?.perclos_pct ?? null);
    page.blinksUsual.textContent = baseline.blinks_per_minute !== null ? t('alertness.usual', { value: formatNumber(baseline.blinks_per_minute, 1) }) : '';
    page.perclosUsual.textContent = baseline.perclos_pct !== null ? t('alertness.usual', { value: `${formatNumber(baseline.perclos_pct, 1)}%` }) : '';
    if (latest?.camera_used && latest.blinks_per_minute === null && !run) {
        page.blinksUsual.textContent = t('alertness.camera_too_short');
    }
    renderHistory(overview.history, baseline.median_rt_ms);
}

function renderEyeValues(blinksPerMinute, perclos) {
    page.blinks.textContent = blinksPerMinute === null ? '—' : formatNumber(blinksPerMinute, 1);
    page.perclos.textContent = perclos === null ? '—' : `${formatNumber(perclos, 1)}%`;
}

// Small 7-day chart: last test of each day (solid) and the astronaut's usual (dashed).
function renderHistory(days, usual) {
    const width = page.history.clientWidth || 460;
    const height = 120;
    const pad = { top: 8, right: 8, bottom: 22, left: 34 };
    const values = days.map((day) => day.median_rt_ms).filter((v) => v !== null);
    const max = Math.max(600, ...values, usual ?? 0);
    const x = (i) => pad.left + (i / (days.length - 1)) * (width - pad.left - pad.right);
    const y = (v) => pad.top + (1 - v / max) * (height - pad.top - pad.bottom);

    const grid = [0, max / 2, max].map((v) => `
        <line class="chart-grid" x1="${pad.left}" x2="${width - pad.right}" y1="${y(v)}" y2="${y(v)}"/>
        <text class="chart-label" x="${pad.left - 6}" y="${y(v) + 4}" text-anchor="end">${Math.round(v)}</text>`).join('');
    const labels = days.map((day, i) => {
        const ago = days.length - 1 - i;
        const label = ago === 0 ? t('assistant.today') : t('alertness.days_ago', { n: ago });
        return `<text class="chart-label" x="${x(i)}" y="${height - 4}" text-anchor="middle">${escapeHtml(label)}</text>`;
    }).join('');

    // Lines connect only consecutive days that both have a test (missing days stay empty).
    let path = '';
    days.forEach((day, i) => {
        if (day.median_rt_ms === null) return;
        path += `${i > 0 && days[i - 1].median_rt_ms !== null ? 'L' : 'M'}${x(i).toFixed(1)},${y(day.median_rt_ms).toFixed(1)}`;
    });
    const dots = days.map((day, i) => (day.median_rt_ms === null ? '' : `<circle class="history-dot" cx="${x(i)}" cy="${y(day.median_rt_ms)}" r="3.5"><title>${escapeHtml(`${day.date}: ${formatNumber(day.median_rt_ms)} ms`)}</title></circle>`)).join('');
    const usualLine = usual === null ? '' : `<line class="history-usual" x1="${pad.left}" x2="${width - pad.right}" y1="${y(usual)}" y2="${y(usual)}"/>`;

    page.history.innerHTML = `<svg viewBox="0 0 ${width} ${height}" role="img" aria-label="${escapeHtml(t('alertness.chart_aria', { n: values.length }))}">
        ${grid}${usualLine}<path class="history-line" d="${path}"/>${dots}${labels}</svg>
        ${values.length ? '' : `<p class="alertness-chart-empty">${escapeHtml(t('alertness.chart_empty'))}</p>`}`;
}

// ---------- Camera ----------

const CAMERA_STATES = { off: 'camera_off', starting: 'camera_starting', loading: 'camera_loading', searching: 'camera_searching', calibrating: 'camera_calibrating', no_face: 'camera_no_face', tracking: 'camera_tracking', error: 'camera_error' };

function onCameraUpdate(update) {
    page.cameraView.dataset.state = update.state;
    page.cameraState.textContent = t(`alertness.${CAMERA_STATES[update.state]}`);
    if (run && 'blinksPerMinute' in update) renderEyeValues(update.blinksPerMinute, update.perclos);
    if (update.state === 'error') stopCamera(t('alertness.camera_error'));
}

async function startCamera() {
    if (!navigator.mediaDevices?.getUserMedia) {
        stopCamera(t('alertness.camera_unsupported'));
        return;
    }
    tracker = new EyeTracker(page.video, overview?.camera_rules ?? { perclos_closure: 0.8, blink_closure: 0.5, blink_max_ms: 500 }, onCameraUpdate);
    try {
        await tracker.start();
    } catch (error) {
        stopCamera(t(error?.name === 'NotAllowedError' ? 'alertness.camera_denied' : 'alertness.camera_unavailable'));
    }
}

function stopCamera(reason) {
    tracker?.stop();
    tracker = null;
    page.cameraToggle.checked = false;
    page.cameraView.dataset.state = 'off';
    page.cameraState.textContent = reason ?? t('alertness.camera_off');
}

page.cameraToggle.addEventListener('change', () => (page.cameraToggle.checked ? startCamera() : stopCamera()));

// ---------- The test ----------

const randomIsi = () => {
    const { isi_min_ms: min, isi_max_ms: max } = overview.test;
    return min + Math.random() * (max - min);
};

function startTest() {
    if (run || !overview) return;
    hideError();
    run = { phase: 'countdown', times: [], anticipations: 0, stimulusOn: null, nextAt: null, frame: null, timeouts: [] };
    setStep('test');
    page.panel.dataset.state = 'countdown';
    page.instruction.textContent = t('alertness.instruction');
    page.start.hidden = true;
    page.stop.hidden = false;
    page.cameraToggle.disabled = true;
    page.panel.focus();

    let count = 3;
    const tick = () => {
        if (!run) return;
        if (count === 0) {
            beginTrials();
            return;
        }
        page.stimulus.textContent = count;
        page.message.textContent = t('alertness.get_ready');
        count--;
        run.timeouts.push(setTimeout(tick, 1000));
    };
    tick();
}

function beginTrials() {
    run.phase = 'running';
    run.startedAt = performance.now();
    run.endsAt = run.startedAt + overview.test.duration_seconds * 1000;
    page.panel.dataset.state = 'waiting';
    page.stimulus.textContent = '';
    page.message.textContent = '';
    page.timer.hidden = false;
    tracker?.startRecording();
    run.nextAt = run.startedAt + randomIsi();
    run.frame = requestAnimationFrame(frame);
}

// One loop drives everything: the countdown clock, showing the number, the counter, timeouts.
function frame(now) {
    if (!run || run.phase !== 'running') return;
    const left = Math.max(0, run.endsAt - now);
    page.timerText.textContent = t('alertness.time_left', { time: clock(left) });

    if (run.stimulusOn === null && now >= run.endsAt) {
        finishTest(now);
        return;
    }
    if (run.stimulusOn === null && run.nextAt !== null && now >= run.nextAt) {
        run.stimulusOn = now;                       // drawn in this frame
        run.nextAt = null;
        page.panel.dataset.state = 'stimulus';
        page.message.textContent = '';
    }
    if (run.stimulusOn !== null) {
        const elapsed = now - run.stimulusOn;
        page.stimulus.textContent = Math.floor(elapsed);
        if (elapsed >= overview.test.no_response_ms) {   // no response: counted as a lapse
            run.times.push(overview.test.no_response_ms);
            endStimulus(now, t('alertness.missed'));
        }
    }
    run.frame = requestAnimationFrame(frame);
}

function endStimulus(at, message) {
    run.stimulusOn = null;
    page.panel.dataset.state = 'feedback';
    if (message) page.message.textContent = message;
    // Feedback is shown for 1 s; the next number comes 1–4 s after the response (feedback included).
    run.timeouts.push(setTimeout(() => {
        if (run && run.stimulusOn === null) {
            page.panel.dataset.state = 'waiting';
            page.stimulus.textContent = '';
            page.message.textContent = '';
        }
    }, overview.test.feedback_ms));
    run.nextAt = at + Math.max(overview.test.feedback_ms, randomIsi());
}

function respond(event) {
    if (!run || run.phase !== 'running') return;
    event.preventDefault();
    const at = event.timeStamp;
    if (run.stimulusOn !== null && at >= run.stimulusOn) {
        const rt = Math.round(at - run.stimulusOn);
        run.times.push(rt);
        page.stimulus.textContent = rt;
        endStimulus(at, rt < overview.test.false_start_ms ? t('alertness.too_early') : '');
    } else {
        run.anticipations++;                         // pressed with no number: a false start
        page.message.textContent = t('alertness.too_early');
    }
}

async function finishTest(now) {
    const durationSeconds = Math.round((now - run.startedAt) / 1000);
    const result = { reaction_times: run.times, anticipations: run.anticipations, duration_seconds: durationSeconds };
    const camera = tracker?.stopRecording();
    if (camera) result.camera = camera;
    clearRun();
    page.panel.dataset.state = 'done';
    page.stimulus.textContent = '';
    page.message.textContent = t('alertness.saving');

    if (!result.reaction_times.length) {
        resetPanel(t('alertness.no_responses'));
        return;
    }
    try {
        overview = await api.post('alertness', result);
        setStep('results');
        page.message.textContent = t('alertness.done');
        renderResults();
        document.querySelector('.alertness-results').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (error) {
        showError(error.message);
        page.message.textContent = '';
    }
    page.startLabel.textContent = t('alertness.again');
}

function clearRun() {
    if (!run) return;
    cancelAnimationFrame(run.frame);
    run.timeouts.forEach(clearTimeout);
    run = null;
    page.timer.hidden = true;
    page.start.hidden = false;
    page.stop.hidden = true;
    page.cameraToggle.disabled = false;
}

// Stopping (or leaving the tab) cancels the test: nothing is saved from a partial test.
function abortTest(message) {
    if (!run) return;
    tracker?.stopRecording();
    clearRun();
    resetPanel(message);
}

function resetPanel(message) {
    setStep('prepare');
    page.panel.dataset.state = 'idle';
    page.stimulus.textContent = '';
    page.message.textContent = message ?? t('alertness.ready');
    if (overview) renderResults();
}

function clock(ms) {
    const total = Math.ceil(ms / 1000);
    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}

function showError(message) {
    page.error.textContent = message;
    page.error.hidden = false;
}

function hideError() {
    page.error.hidden = true;
    page.error.textContent = '';
}

page.start.addEventListener('click', startTest);
page.stop.addEventListener('click', () => abortTest(t('alertness.stopped')));
page.panel.addEventListener('pointerdown', respond);
// Space anywhere on the page responds during the test (and never scrolls or toggles a control).
document.addEventListener('keydown', (event) => {
    if (!run || event.code !== 'Space' || event.repeat) return;
    if (run.phase === 'running') respond(event);
    else event.preventDefault();
}, true);
document.addEventListener('visibilitychange', () => {
    if (document.hidden) abortTest(t('alertness.aborted_hidden'));
});
window.addEventListener('pagehide', () => tracker?.stop());

load();
