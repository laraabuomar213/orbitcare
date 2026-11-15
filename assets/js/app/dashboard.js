// Health view: status card, vital signs, trend chart and alerts card.
// Used by the astronaut dashboard and by the mission admin's crew-member page
// (which sets data-astronaut-id). Polls only GET /api/health/current every 2 s and
// appends new chart points; nothing is reloaded wholesale.
import {
    api, t, unitLabel, ApiError, METRICS, METRIC_ORDER, icon, escapeHtml, formatNumber, formatOffset,
    formatTime, timeAgo, formatDateTime, renderEmpty, renderProblem, poll, appUrl, severityLabel,
} from './api.js';
import { setLastUpdate, setAlertBadge } from './shell.js';
import { LineChart } from './line-chart.js';

const view = document.querySelector('[data-health-view]');
const astronautId = view.dataset.astronautId || null;
const scope = astronautId ? { astronaut_id: astronautId } : {};

// ---------- Health status ----------

const statusCard = view.querySelector('[data-health-status]');
const STATUS_ICONS = { GREEN: 'check', YELLOW: 'warning', RED: 'warning', NO_DATA: 'clock', ERROR: 'warning' };

function renderStatus(status) {
    statusCard.dataset.level = status.level;
    statusCard.querySelector('[data-status-icon]').innerHTML = icon(STATUS_ICONS[status.level] ?? 'clock');
    statusCard.querySelector('[data-status-title]').textContent = status.title;
    statusCard.querySelector('[data-status-message]').textContent = status.message;
}

// ---------- Vital signs ----------

const vitalsGrid = view.querySelector('[data-vitals]');
const replayNote = view.querySelector('[data-replay-note]');

// Which vest / headband sensor would measure each value (ids match prototypes/vest-3d SENSORS).
const SENSOR_FOR = { heart_rate: 'ecg', spo2: 'ppg', pulse_rate: 'ppg', respiratory_rate: 'breath' };

function sensorLink(key) {
    const sensor = SENSOR_FOR[key];
    const query = new URLSearchParams({ sensor, lang: document.documentElement.lang === 'ar' ? 'ar' : 'en', ...scope });
    return `<a class="vital-source" href="${appUrl(`prototypes/vest-3d/index.html?${query}`)}" title="${escapeHtml(t('dashboard.sensor_hint'))}">${escapeHtml(t(`dashboard.sensor.${sensor}`))}</a>`;
}

// live: readings from the vest (or its simulation) — show when they were measured, not a recording offset.
function renderVitals(metrics, live) {
    const byMetric = Object.fromEntries(metrics.map((m) => [m.metric, m]));
    vitalsGrid.innerHTML = METRIC_ORDER.map((key) => {
        const m = byMetric[key];
        const meta = METRICS[key];
        if (!m) return '';
        const p = m.provenance;
        const source = p ? t('dashboard.provenance', { id: p.raw_measurement_id, record: p.source_record_id, row: p.source_row, seconds: p.original_timestamp_seconds }) : '';
        return `
            <div class="vital" data-classification="${m.available ? m.classification : 'NONE'}">
                <span class="vital-icon tone-${meta.tone}">${icon(meta.icon)}</span>
                <p class="vital-value">${m.available ? formatNumber(m.value) : '—'}</p>
                <p class="vital-unit">${escapeHtml(m.available ? unitLabel(m.unit) : t('dashboard.data_unavailable'))}</p>
                <p class="vital-label"><span class="classification-dot"></span>${escapeHtml(meta.label)}</p>
                <p class="vital-meta" title="${escapeHtml(source)}">${p ? escapeHtml(live
                    ? t('dashboard.measured', { time: formatTime(m.measured_at, true) })
                    : t('dashboard.rec_offset', { time: formatOffset(p.original_timestamp_seconds) })) : escapeHtml(m.reason ?? '')}</p>
                ${sensorLink(key)}
            </div>`;
    }).join('');
}

function renderReplayNote(replay) {
    if (!replay) {
        replayNote.innerHTML = `<p>${escapeHtml(t('dashboard.no_source'))}</p>`;
        return;
    }
    const pill = view.querySelector('[data-replay-pill]');
    if (replay.mode !== 'recorded_data_replay') {
        // Live readings from the vest; the simulation is always labelled as simulated values.
        const simulated = replay.mode === 'live_device_simulated';
        if (pill) {
            pill.lastChild.textContent = t(simulated ? 'dashboard.device_pill_simulated' : 'dashboard.device_pill');
            pill.title = t(simulated ? 'dashboard.device_hint_simulated' : 'dashboard.device_hint');
            pill.classList.remove('is-finished');
        }
        replayNote.innerHTML = `<p>${icon('database')} ${escapeHtml(t('dashboard.device_source'))} · <bdi>${escapeHtml(replay.device_id)}</bdi>${simulated
            ? ` · <strong>${escapeHtml(t('dashboard.device_simulated'))}</strong>` : ''}</p>`;
        return;
    }
    const finished = replay.status === 'completed';
    replayNote.innerHTML = `
        <p>
            ${icon('database')} ${escapeHtml(t('dashboard.replay_source'))} · <bdi>${escapeHtml(replay.source.replace(' v1.0.0', ''))}</bdi> · ${escapeHtml(t('dashboard.record'))} <strong>${escapeHtml(replay.record_id)}</strong>
            · <bdi>${finished ? escapeHtml(t('dashboard.replay_complete')) : `${formatOffset(replay.position_seconds)} / ${formatOffset(replay.duration_seconds)}`}</bdi>
        </p>
        ${finished && !astronautId ? `<button class="btn btn-ghost btn-small" type="button" data-restart-replay>${escapeHtml(t('dashboard.restart_replay'))}</button>` : ''}`;
    view.querySelector('[data-replay-pill]')?.classList.toggle('is-finished', finished);
    replayNote.querySelector('[data-restart-replay]')?.addEventListener('click', restartReplay);
}

async function restartReplay(event) {
    event.target.disabled = true;
    try {
        await api.post('health/replay/restart');
        await loadTrend();
        await refreshCurrent();
    } catch (error) {
        replayNote.insertAdjacentHTML('beforeend', `<p class="form-error">${escapeHtml(error.message)}</p>`);
        event.target.disabled = false;
    }
}

// ---------- Trend chart ----------

const trendContainer = view.querySelector('[data-trend-chart]');
const trendNote = view.querySelector('[data-trend-note]');
const chart = trendContainer ? new LineChart(trendContainer, { height: 200 }) : null;
let trend = { metric: 'heart_rate', range: '1h', lastId: 0, points: [] };

async function loadTrend() {
    if (!chart) return;
    try {
        const data = await api.get('health/trends', { ...scope, metric: trend.metric, range: trend.range });
        trend.points = data.points;
        trend.lastId = data.points.at(-1)?.id ?? 0;
        trend.data = data;
        chart.setData(data.points, { unit: data.unit, green: data.green });
        updateTrendNote();
    } catch (error) {
        trendNote.textContent = t('dashboard.trend_failed', { message: error.message });
    }
}

async function appendTrend() {
    if (!chart || !trend.data) return;
    const data = await api.get('health/trends', { ...scope, metric: trend.metric, range: trend.range, after_id: trend.lastId });
    if (data.points.length) {
        trend.points.push(...data.points);
        trend.lastId = data.points.at(-1).id;
        chart.append(data.points);
        updateTrendNote();
    }
}

// Says exactly how much real data the chart shows, instead of stretching or inventing data.
function updateTrendNote() {
    const { points, data } = trend;
    const range = t(`range_long.${data.range}`);
    if (!points.length) {
        trendNote.textContent = t('dashboard.trend_empty', { metric: data.label, range });
        return;
    }
    const covered = (new Date(points.at(-1).t) - new Date(points[0].t)) / 1000;
    const shown = covered < 120 ? t('time.seconds', { n: Math.round(covered) }) : t('time.minutes', { n: Math.round(covered / 60) });
    if (!data.live) {
        // The replay has finished: the chart shows the last recorded data, not "now".
        trendNote.textContent = t('dashboard.trend_finished', { shown, time: formatDateTime(data.window_end) });
        return;
    }
    trendNote.textContent = covered < data.range_seconds * 0.9
        ? t('dashboard.trend_partial', { shown, range })
        : t('dashboard.trend_full', { n: points.length, range });
}

view.querySelector('[data-trend-metric]')?.addEventListener('change', (event) => {
    trend = { ...trend, metric: event.target.value, lastId: 0, points: [] };
    loadTrend();
});
view.querySelectorAll('[data-range]').forEach((button) => {
    button.addEventListener('click', () => {
        view.querySelectorAll('[data-range]').forEach((b) => b.setAttribute('aria-pressed', String(b === button)));
        trend = { ...trend, range: button.dataset.range, lastId: 0, points: [] };
        loadTrend();
    });
});

// ---------- Alerts card ----------

const alertsCard = view.querySelector('[data-alerts-card]');
const alertsList = view.querySelector('[data-alerts-list]');

async function loadAlerts() {
    if (!alertsCard) return;
    try {
        const alerts = await api.get('alerts', { ...scope, filter: 'active', limit: 3 });
        alertsCard.dataset.tone = alerts.some((a) => a.severity === 'RED') ? 'red' : alerts.length ? 'yellow' : 'none';
        if (!alerts.length) {
            renderEmpty(alertsList, t('dashboard.no_alerts'));
            return;
        }
        alertsList.innerHTML = alerts.slice(0, 2).map((alert) => `
            <a class="alert-item" data-severity="${alert.severity}" href="${appUrl(`pages/alerts.php?id=${alert.id}`)}">
                <span class="severity-dot" aria-label="${severityLabel(alert.severity)}"></span>
                <span class="alert-item-text">
                    <strong>${escapeHtml(alert.title)}</strong>
                    <span>${formatNumber(alert.observed_value)} ${escapeHtml(unitLabel(alert.unit))} · ${escapeHtml(t('dashboard.detected', { when: timeAgo(alert.occurred_at) }))}</span>
                </span>
                <span class="alert-item-icon tone-${METRICS[alert.metric]?.tone ?? 'rose'}">${icon(METRICS[alert.metric]?.icon ?? 'warning')}</span>
            </a>`).join('');
    } catch (error) {
        renderProblem(alertsList, error.message, loadAlerts);
    }
}

// ---------- Live loop ----------

let lastAlertSignature = null;

async function refreshCurrent() {
    try {
        const data = await api.get('health/current', scope);
        renderStatus(data.status);
        renderVitals(data.metrics, Boolean(data.replay) && data.replay.mode !== 'recorded_data_replay');
        renderReplayNote(data.replay);
        if (data.last_update) setLastUpdate(data.last_update);

        const signature = `${data.active_alerts.red}/${data.active_alerts.yellow}`;
        if (signature !== lastAlertSignature) {
            lastAlertSignature = signature;
            loadAlerts();
            if (!astronautId) setAlertBadge(data.active_alerts.red + data.active_alerts.yellow);
        }
        await appendTrend();
    } catch (error) {
        if (error instanceof ApiError && error.status === 401) return;   // redirecting to login
        renderStatus({ level: 'ERROR', title: t('dashboard.health_unavailable'), message: `${error.message} ${t('dashboard.retrying')}` });
        throw error;
    }
}

await loadTrend();
poll(refreshCurrent, 2000);
