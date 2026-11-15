// Mission admin dashboard: crew status table and active mission alerts (polled).
import {
    api, t, unitLabel, appUrl, escapeHtml, formatNumber, formatTime, timeAgo, renderEmpty, renderProblem, severityLabel, poll,
} from './api.js';
import { setLastUpdate, setAlertBadge } from './shell.js';

const select = document.querySelector('[data-admin-mission]');
const crew = document.querySelector('[data-crew]');
const alertsBox = document.querySelector('[data-mission-alerts]');
let missionId = null;

function metricCell(metrics, key) {
    const m = metrics.find((item) => item.metric === key);
    if (!m || !m.available) return '<td class="muted">—</td>';
    return `<td><span class="severity-badge" data-severity="${m.classification}">${formatNumber(m.value)} ${escapeHtml(unitLabel(m.unit))}</span></td>`;
}

async function refresh() {
    try {
        const data = await api.get('admin/overview', { mission_id: missionId });
        if (!data.mission) {
            document.querySelector('[data-mission-name]').textContent = t('admin.no_mission');
            crew.innerHTML = `<tr><td colspan="8">${escapeHtml(t('admin.no_mission_hint'))}</td></tr>`;
            return;
        }
        missionId = data.mission.id;
        document.querySelector('[data-mission-name]').textContent = data.mission.name;
        if (data.missions.length > 1 && select.hidden) {
            select.hidden = false;
            select.innerHTML = data.missions.map((m) => `<option value="${m.id}">${escapeHtml(m.name)}</option>`).join('');
            select.addEventListener('change', () => { missionId = Number(select.value); refresh(); });
        }

        const red = data.alerts.filter((a) => a.severity === 'RED').length;
        const tile = (key, value, tone = '') => `<div class="card summary-tile"><dt>${escapeHtml(t(`admin.tile.${key}`))}</dt><dd${tone ? ` class="${tone}"` : ''}>${value}</dd></div>`;
        document.querySelector('[data-summary]').innerHTML = [
            tile('astronauts', data.crew.length),
            tile('critical', red, 'summary-red'),
            tile('warnings', data.alerts.length - red, 'summary-yellow'),
            tile('status', escapeHtml(t(`mission.status.${data.mission.status}`)), 'summary-text'),
        ].join('');
        setAlertBadge(data.alerts.length);

        crew.innerHTML = data.crew.length ? data.crew.map((member) => `
            <tr>
                <td><span class="crew-name"><strong>${escapeHtml(member.name)}</strong><span><bdi>${escapeHtml(member.astronaut_code ?? '')}</bdi>${member.replay ? ` · <bdi>${escapeHtml(member.replay.record_id)}</bdi>` : ` · ${escapeHtml(t('admin.replay_not_started'))}`}</span></span></td>
                <td><span class="severity-badge" data-severity="${member.status.level === 'NO_DATA' ? '' : member.status.level}">${escapeHtml(member.status.level === 'NO_DATA' ? t('admin.no_data') : severityLabel(member.status.level))}</span></td>
                ${metricCell(member.metrics, 'heart_rate')}${metricCell(member.metrics, 'spo2')}${metricCell(member.metrics, 'pulse_rate')}${metricCell(member.metrics, 'respiratory_rate')}
                <td>${member.last_update ? formatTime(member.last_update, true) : '—'}</td>
                <td><a class="btn btn-ghost btn-small" href="${appUrl(`pages/crew.php?id=${member.id}`)}">${escapeHtml(t('mission.open'))}</a></td>
            </tr>`).join('') : `<tr><td colspan="8">${escapeHtml(t('admin.no_crew'))}</td></tr>`;

        const newest = data.crew.map((m) => m.last_update).filter(Boolean).sort().at(-1);
        if (newest) setLastUpdate(newest);

        if (!data.alerts.length) { renderEmpty(alertsBox, t('admin.no_active_alerts')); return; }
        alertsBox.innerHTML = data.alerts.map((a) => `
            <div class="alert-row" data-severity="${a.severity}">
                <span class="severity-badge" data-severity="${a.severity}">${escapeHtml(severityLabel(a.severity))}</span>
                <a class="alert-row-text alert-row-link" href="${appUrl(`pages/alerts.php?id=${a.id}`)}">
                    <strong>${escapeHtml(a.astronaut.name)} — ${escapeHtml(a.title)}</strong>
                    <span>${formatNumber(a.observed_value)} ${escapeHtml(unitLabel(a.unit))} · ${escapeHtml(timeAgo(a.occurred_at))}</span>
                </a>
                ${a.status === 'active'
                    ? `<button class="btn btn-ghost btn-small" type="button" data-ack="${a.id}">${escapeHtml(t('alerts.acknowledge'))}</button>`
                    : `<span class="status-badge">${escapeHtml(t('alerts.status.acknowledged'))}</span>`}
            </div>`).join('');
    } catch (error) {
        renderProblem(crew.closest('.table-wrap'), error.message, refresh);
        throw error;
    }
}

alertsBox.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-ack]');
    if (!button) return;
    button.disabled = true;
    try { await api.post(`alerts/${button.dataset.ack}/acknowledge`); await refresh(); } catch (error) { button.disabled = false; }
});

poll(refresh, 3000);
