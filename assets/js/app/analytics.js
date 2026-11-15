// Health Analytics: chart, derived summary, data source, measurement table (with
// provenance) and alert history for the chosen measurement.
import {
    api, t, unitLabel, appUrl, escapeHtml, formatNumber, formatOffset, formatDateTime, formatTime, renderEmpty, renderProblem, severityLabel,
} from './api.js';
import { LineChart } from './line-chart.js';

const chart = new LineChart(document.querySelector('[data-chart]'), { height: 280 });
const note = document.querySelector('[data-chart-note]');
const rows = document.querySelector('[data-rows]');
const loadMore = document.querySelector('[data-load-more]');
let state = { metric: 'heart_rate', range: '1h', nextBefore: null };

async function loadChart() {
    try {
        const data = await api.get('health/trends', { metric: state.metric, range: state.range });
        const unit = unitLabel(data.unit);
        const range = t(`range_long.${data.range}`);
        document.querySelector('[data-chart-title]').textContent = `${data.label} (${unit})`;
        chart.setData(data.points, { unit: data.unit, green: data.green });
        const params = { n: data.points.length, range, min: data.green[0], max: data.green[1], unit, time: formatDateTime(data.window_end) };
        note.textContent = !data.points.length
            ? t('analytics.chart_empty', { range })
            : t(data.live ? 'analytics.chart_note' : 'analytics.chart_note_finished', params);

        if (data.replay) await loadSource();
    } catch (error) {
        note.textContent = t('analytics.chart_failed', { message: error.message });
    }
}

async function loadSource() {
    const box = document.querySelector('[data-source]');
    const term = (key) => escapeHtml(t(key));
    const profile = await api.get('astronaut');
    const s = profile.data_source;
    if (!s) { box.innerHTML = `<dt>${term('alerts.source')}</dt><dd>${term('analytics.not_assigned')}</dd>`; return; }
    if (s.mode !== 'recorded_data_replay') {
        // Live stream from the vest (or its Wokwi simulation): no dataset page, no fixed length.
        box.innerHTML = `
            <dt>${term('analytics.dataset')}</dt><dd><bdi>${escapeHtml(s.source)}</bdi></dd>
            <dt>${term('analytics.device')}</dt><dd><bdi>${escapeHtml(s.device_id)}</bdi></dd>
            <dt>${term('analytics.mode')}</dt><dd>${term(s.mode === 'live_device_simulated' ? 'analytics.mode_device_simulated' : 'analytics.mode_device')}</dd>
            <dt>${term('analytics.quality')}</dt><dd>${escapeHtml(t('analytics.quality_value', { stored: s.values_stored, missing: s.values_missing, rejected: s.values_rejected }))}</dd>
            <dt>${term('analytics.stream_started')}</dt><dd>${formatDateTime(s.ingested_at)}</dd>`;
        return;
    }
    box.innerHTML = `
        <dt>${term('analytics.dataset')}</dt><dd><a href="${escapeHtml(s.source_url)}" target="_blank" rel="noopener"><bdi>${escapeHtml(s.source)}</bdi></a></dd>
        <dt>${term('analytics.record')}</dt><dd><bdi>${escapeHtml(s.record_id)} (${escapeHtml(s.source_file)})</bdi></dd>
        <dt>${term('analytics.mode')}</dt><dd>${term('analytics.mode_value')}</dd>
        <dt>${term('analytics.length')}</dt><dd>${escapeHtml(t('analytics.length_value', { duration: formatOffset(s.duration_seconds) }))}</dd>
        <dt>${term('analytics.quality')}</dt><dd>${escapeHtml(t('analytics.quality_value', { stored: s.values_stored, missing: s.values_missing, rejected: s.values_rejected }))}</dd>
        <dt>${term('analytics.loaded')}</dt><dd>${formatDateTime(s.ingested_at)}</dd>`;
}

async function loadTable(append = false) {
    try {
        const data = await api.get('health/history', { metric: state.metric, limit: 50, before_id: append ? state.nextBefore : undefined });
        state.nextBefore = data.next_before_id;
        loadMore.hidden = !data.next_before_id;

        const html = data.rows.map((r) => `
            <tr>
                <td>${formatTime(r.measured_at, true)}</td>
                <td><strong>${formatNumber(r.value, 1)}</strong> ${escapeHtml(unitLabel(r.unit))}</td>
                <td><span class="severity-badge" data-severity="${r.classification}">${escapeHtml(severityLabel(r.classification))}</span></td>
                <td><bdi>${escapeHtml(r.provenance.source_record_id)}</bdi></td>
                <td>${r.provenance.source_row}</td>
                <td>${formatOffset(r.provenance.original_timestamp_seconds)}</td>
                <td>#${r.provenance.raw_measurement_id}</td>
            </tr>`).join('');
        if (append) rows.insertAdjacentHTML('beforeend', html);
        else rows.innerHTML = html || `<tr><td colspan="7">${escapeHtml(t('analytics.no_rows'))}</td></tr>`;

        if (!append) renderStats(data.summary, data.rows[0]?.unit ?? '');
    } catch (error) {
        rows.innerHTML = `<tr><td colspan="7">${escapeHtml(error.message)}</td></tr>`;
    }
}

function renderStats(summary, unitCode) {
    const c = summary.by_classification;
    const unit = escapeHtml(unitCode ? unitLabel(unitCode) : '');
    const stat = (key, value) => `<div class="stat"><dt>${escapeHtml(t(`analytics.stat.${key}`))}</dt><dd>${value}</dd></div>`;
    document.querySelector('[data-stats]').innerHTML = [
        stat('samples', summary.samples),
        stat('min', `${formatNumber(summary.min, 1)} <small>${unit}</small>`),
        stat('max', `${formatNumber(summary.max, 1)} <small>${unit}</small>`),
        stat('mean', `${formatNumber(summary.mean, 1)} <small>${unit}</small>`),
        stat('in_range', `${summary.samples ? Math.round((c.GREEN / summary.samples) * 100) : 0}%`),
        stat('warning_critical', `<bdi>${c.YELLOW} / ${c.RED}</bdi>`),
    ].join('');
}

async function loadAlertHistory() {
    const box = document.querySelector('[data-alert-history]');
    try {
        const alerts = (await api.get('alerts', { filter: 'all' })).filter((a) => a.metric === state.metric);
        if (!alerts.length) { renderEmpty(box, t('analytics.no_alerts')); return; }
        box.innerHTML = alerts.map((a) => `
            <a class="alert-row" href="${appUrl(`pages/alerts.php?id=${a.id}`)}">
                <span class="severity-badge" data-severity="${a.severity}">${escapeHtml(severityLabel(a.severity))}</span>
                <span class="alert-row-text"><strong>${escapeHtml(a.title)}</strong><span>${formatNumber(a.observed_value)} ${escapeHtml(unitLabel(a.unit))} · ${formatDateTime(a.occurred_at)}</span></span>
                <span class="status-badge" data-status="${a.status}">${escapeHtml(t(`alerts.status.${a.status}`))}</span>
            </a>`).join('');
    } catch (error) {
        renderProblem(box, error.message, loadAlertHistory);
    }
}

function loadAll() {
    loadChart();
    loadTable();
    loadAlertHistory();
}

document.querySelectorAll('[data-metric]').forEach((tab) => tab.addEventListener('click', () => {
    document.querySelectorAll('[data-metric]').forEach((other) => other.setAttribute('aria-pressed', String(other === tab)));
    state = { ...state, metric: tab.dataset.metric, nextBefore: null };
    loadAll();
}));
document.querySelectorAll('[data-range]').forEach((button) => button.addEventListener('click', () => {
    document.querySelectorAll('[data-range]').forEach((b) => b.setAttribute('aria-pressed', String(b === button)));
    state.range = button.dataset.range;
    loadChart();
}));
loadMore.addEventListener('click', () => loadTable(true));

loadAll();
