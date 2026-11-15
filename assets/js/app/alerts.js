// Alerts page: filter tabs, list and detail view with acknowledge / explain.
import {
    api, t, unitLabel, escapeHtml, formatNumber, formatOffset, formatDateTime, renderEmpty, renderProblem, severityLabel, poll,
} from './api.js';

const list = document.querySelector('[data-alert-list]');
const detail = document.querySelector('[data-alert-detail]');
const isAdmin = document.body.dataset.role === 'mission_admin';
let filter = 'all';
let selectedId = Number(new URLSearchParams(window.location.search).get('id')) || null;

const statusLabel = (status) => escapeHtml(t(`alerts.status.${status}`));

async function loadList() {
    try {
        const alerts = await api.get('alerts', { filter });
        if (!alerts.length) { renderEmpty(list, t('alerts.none')); return; }
        list.innerHTML = alerts.map((a) => `
            <button class="alert-row" type="button" data-id="${a.id}" aria-current="${a.id === selectedId}">
                <span class="severity-badge" data-severity="${a.severity}">${escapeHtml(severityLabel(a.severity))}</span>
                <span class="alert-row-text">
                    <strong>${escapeHtml(a.title)}${a.is_test_fixture ? ` <em>(${escapeHtml(t('alerts.test_fixture'))})</em>` : ''}</strong>
                    <span>${isAdmin ? `${escapeHtml(a.astronaut.name)} · ` : ''}${formatNumber(a.observed_value)} ${escapeHtml(unitLabel(a.unit))} · ${formatDateTime(a.occurred_at)}</span>
                </span>
                <span class="status-badge" data-status="${a.status}">${statusLabel(a.status)}</span>
            </button>`).join('');
    } catch (error) {
        renderProblem(list, error.message, loadList);
    }
}

async function showDetail(id) {
    selectedId = id;
    list.querySelectorAll('[data-id]').forEach((row) => row.setAttribute('aria-current', String(Number(row.dataset.id) === id)));
    history.replaceState(null, '', `?id=${id}`);

    try {
        const a = await api.get(`alerts/${id}`);
        const s = a.source_measurement;
        const term = (key) => escapeHtml(t(key));
        detail.innerHTML = `
            <header class="card-header">
                <h2 class="card-title">${escapeHtml(a.title)}</h2>
                <span class="severity-badge" data-severity="${a.severity}">${escapeHtml(severityLabel(a.severity))}</span>
            </header>
            ${a.is_test_fixture ? `<p class="state-block">${term('alerts.fixture_note')}</p>` : ''}
            <div class="detail-section"><h3>${term('alerts.what_happened')}</h3><p>${escapeHtml(a.explanation)}</p></div>
            <div class="detail-section"><h3>${term('alerts.when')}</h3>
                <dl class="definition-list">
                    <dt>${term('alerts.detected')}</dt><dd>${escapeHtml(t('alerts.replay_time', { time: formatDateTime(a.occurred_at) }))}</dd>
                    <dt>${term('alerts.condition_began')}</dt><dd>${escapeHtml(t('alerts.into_recording', { time: formatOffset(s.condition_started_at_seconds) }))}</dd>
                    <dt>${term('alerts.astronaut')}</dt><dd>${escapeHtml(a.astronaut.name)}${a.astronaut.code ? ` (<bdi>${escapeHtml(a.astronaut.code)}</bdi>)` : ''}</dd>
                    ${a.mission ? `<dt>${term('shell.mission')}</dt><dd>${escapeHtml(a.mission.name)}</dd>` : ''}
                </dl>
            </div>
            <div class="detail-section"><h3>${term('alerts.measurement')}</h3>
                <dl class="definition-list">
                    <dt>${term('alerts.value')}</dt><dd><strong>${formatNumber(s.value, 1)} ${escapeHtml(unitLabel(s.unit))}</strong></dd>
                    <dt>${term('alerts.raw_measurement')}</dt><dd>${escapeHtml(t('alerts.raw_ids', { id: s.raw_measurement_id, first: s.first_measurement_id }))}</dd>
                    <dt>${term('alerts.source')}</dt><dd><bdi>${escapeHtml(s.source)}</bdi></dd>
                    <dt>${term('alerts.record_row')}</dt><dd>${escapeHtml(t('alerts.record_row_value', { record: s.source_record_id, row: s.source_row, seconds: s.original_timestamp_seconds }))}</dd>
                </dl>
            </div>
            <div class="detail-section"><h3>${term('alerts.rule')}</h3><p>${escapeHtml(a.rule)}</p><p class="muted">${escapeHtml(t('alerts.threshold_version', { version: a.threshold_version }))}</p></div>
            <div class="detail-section"><h3>${term('alerts.recommended_action')}</h3><p>${escapeHtml(a.recommended_action)}</p></div>
            <div class="detail-section"><h3>${term('alerts.status')}</h3>
                <p><span class="status-badge" data-status="${a.status}">${statusLabel(a.status)}</span>
                ${a.acknowledged ? ` · ${escapeHtml(t('alerts.acknowledged_by', { name: a.acknowledged.by, time: formatDateTime(a.acknowledged.at) }))}` : ''}
                ${a.resolved ? ` · ${escapeHtml(t('alerts.resolved_at', { time: formatDateTime(a.resolved.at) }))} — ${escapeHtml(a.resolved.note ?? '')}` : ''}</p>
            </div>
            <div class="detail-actions">
                ${a.status === 'active' ? `<button class="btn btn-primary btn-small" type="button" data-acknowledge>${term('alerts.acknowledge')}</button>` : ''}
                <button class="btn btn-ghost btn-small" type="button" data-explain>${term('alerts.explain')}</button>
            </div>
            <p class="muted section-gap" data-detail-message aria-live="polite"></p>`;

        detail.querySelector('[data-acknowledge]')?.addEventListener('click', async (event) => {
            event.target.disabled = true;
            try { await api.post(`alerts/${id}/acknowledge`); await loadList(); await showDetail(id); } catch (error) { setMessage(error.message); event.target.disabled = false; }
        });
        detail.querySelector('[data-explain]').addEventListener('click', async (event) => {
            event.target.disabled = true;
            try {
                const result = await api.post(`ai/alerts/${id}/explain`);
                if (result.status === 'success') renderExplanation(result.output);
                else setMessage(result.message ?? t('ai.status.not_configured'));
            } catch (error) { setMessage(error.message); } finally { event.target.disabled = false; }
        });
    } catch (error) {
        renderProblem(detail, error.message, () => showDetail(id));
    }
}

function setMessage(text) {
    detail.querySelector('[data-detail-message]').textContent = text;
}

// The AI's explanation of the alert (text from the provider is always escaped).
function renderExplanation(output) {
    const box = detail.querySelector('[data-detail-message]');
    const missing = Array.isArray(output.missing_information) ? output.missing_information.filter((item) => typeof item === 'string') : [];
    box.innerHTML = `<span class="ai-explanation">
        <strong>${escapeHtml(t('alerts.ai_explanation'))}</strong>
        <span dir="auto">${escapeHtml(String(output.summary ?? ''))}</span>
        ${output.concern_level ? `<span>${escapeHtml(t('alerts.ai_concern', { level: String(output.concern_level) }))}</span>` : ''}
        ${missing.length ? `<span>${escapeHtml(t('alerts.ai_missing'))}</span><ul>${missing.map((item) => `<li dir="auto">${escapeHtml(item)}</li>`).join('')}</ul>` : ''}
        <small>${escapeHtml(t('ai_panel.disclaimer'))}</small>
    </span>`;
}

list.addEventListener('click', (event) => {
    const row = event.target.closest('[data-id]');
    if (row) showDetail(Number(row.dataset.id));
});

document.querySelectorAll('[data-filter]').forEach((tab) => tab.addEventListener('click', () => {
    document.querySelectorAll('[data-filter]').forEach((other) => other.setAttribute('aria-pressed', String(other === tab)));
    filter = tab.dataset.filter;
    loadList();
}));

if (selectedId) showDetail(selectedId);
poll(loadList, 10000);
