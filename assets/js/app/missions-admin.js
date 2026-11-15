// My Missions (mission admin): missions list, mission status, crew table ("who needs attention"),
// add / remove astronauts, and the New mission dialog. Refreshed every 5 s; re-drawn only when the
// data changed. Names and texts typed by people are always escaped.
import { api, t, appUrl, escapeHtml, formatTime, formatDate, timeAgo, severityLabel } from './api.js';
import { setLastUpdate } from './shell.js';

const $ = (selector) => document.querySelector(selector);
const page = {
    error: $('[data-board-error]'),
    empty: $('[data-missions-empty]'),
    layout: $('[data-missions-layout]'),
    list: $('[data-mission-list]'),
    name: $('[data-detail-name]'),
    code: $('[data-detail-code]'),
    statusSwitch: $('[data-status-switch]'),
    chatLink: $('[data-chat-link]'),
    tiles: $('[data-tiles]'),
    rows: $('[data-crew-rows]'),
    search: $('[data-astronaut-search]'),
    unassigned: $('[data-unassigned]'),
    dialog: $('[data-mission-dialog]'),
    form: $('[data-mission-form]'),
};

const REFRESH_MS = 5000;
const AVATAR_TONES = ['blue', 'violet', 'teal', 'rose', 'amber', 'green'];
let missions = [];
let selectedId = Number(new URLSearchParams(location.search).get('id')) || null;
let detail = null;
let listSignature = '';
let detailSignature = '';
let searchTimer = null;
let busy = false;   // a change is being saved — skip polling meanwhile

const initials = (name) => name.trim().split(/\s+/).slice(0, 2).map((part) => part[0] ?? '').join('').toUpperCase();
const tone = (id) => AVATAR_TONES[id % AVATAR_TONES.length];
const avatar = (person) => `<span class="mission-avatar" data-tone="${tone(person.id)}" aria-hidden="true">${escapeHtml(initials(person.name))}</span>`;
const plural = (n, one, many) => (n === 1 ? t(one) : t(many, { n }));
const isToday = (iso) => new Date(iso).toDateString() === new Date().toDateString();

// ---------- Loading ----------

async function refresh() {
    if (busy) return;
    try {
        missions = await api.get('admin/missions');
        if (!missions.length) {
            page.layout.hidden = true;
            page.empty.hidden = false;
            return;
        }
        page.empty.hidden = true;
        page.layout.hidden = false;
        if (!missions.some((m) => m.id === selectedId)) selectedId = missions[0].id;
        renderList();
        detail = await api.get(`admin/missions/${selectedId}`);
        renderDetail();
        hideError();
        setLastUpdate(new Date().toISOString());
    } catch (error) {
        showError(error.message);
    }
}

async function select(id) {
    selectedId = id;
    history.replaceState(null, '', `?id=${id}`);
    detailSignature = '';
    listSignature = '';
    page.search.value = '';
    await refresh();
    loadUnassigned();
}

// ---------- Missions list ----------

function renderList() {
    const signature = JSON.stringify([missions, selectedId]);
    if (signature === listSignature) return;
    listSignature = signature;
    page.list.innerHTML = missions.map((m) => `
        <button class="mission-card${m.id === selectedId ? ' is-selected' : ''}" type="button" data-mission="${m.id}" aria-pressed="${m.id === selectedId}">
            <span class="mission-card-top">
                <strong><bdi>${escapeHtml(m.name)}</bdi></strong>
                <span class="crew-code">${escapeHtml(m.code)}</span>
            </span>
            <span class="mission-card-status"><span class="mission-status-chip" data-status="${m.status}">${escapeHtml(t(`chat.mission_status.${m.status}`))}</span></span>
            <span class="mission-card-bottom">
                <span>${escapeHtml(plural(m.astronauts, 'missions.astronauts_one', 'missions.astronauts_many'))}</span>
                ${m.emergencies ? `<span class="mission-emergency-badge"><span class="crew-dot" aria-hidden="true"></span>${escapeHtml(plural(m.emergencies, 'missions.emergency_one', 'missions.emergency_many'))}</span>` : ''}
            </span>
        </button>`).join('');
}

page.list.addEventListener('click', (event) => {
    const card = event.target.closest('[data-mission]');
    if (card && Number(card.dataset.mission) !== selectedId) select(Number(card.dataset.mission));
});

// ---------- Selected mission ----------

function renderDetail() {
    const signature = JSON.stringify(detail);
    if (signature === detailSignature) return;
    detailSignature = signature;
    const { mission, stats, crew } = detail;

    page.name.textContent = mission.name;
    page.code.textContent = mission.code;
    page.statusSwitch.querySelectorAll('[data-status]').forEach((button) => {
        button.setAttribute('aria-pressed', String(button.dataset.status === mission.status));
    });
    page.chatLink.href = appUrl(`pages/mission.php?mission=${mission.id}`);

    const tile = (key, value, toneName = '', hint = '') => `
        <div class="mission-tile"${hint ? ` title="${escapeHtml(hint)}"` : ''}>
            <dt>${escapeHtml(t(`missions.tile.${key}`))}</dt>
            <dd${toneName ? ` data-tone="${toneName}"` : ''}>${value}</dd>
        </div>`;
    page.tiles.innerHTML = [
        tile('astronauts', stats.astronauts),
        tile('attention', stats.attention, stats.attention ? 'red' : '', t('missions.tile.attention_hint')),
        tile('checkins', `${stats.checkins_today}/${stats.astronauts}`),
        tile('alerts', stats.active_alerts, stats.active_alerts ? 'amber' : ''),
    ].join('');

    page.rows.innerHTML = crew.length ? crew.map(renderRow).join('')
        : `<tr><td colspan="6" class="mission-crew-empty">${escapeHtml(t('missions.no_crew'))}</td></tr>`;
}

function renderRow(member) {
    const health = member.health === 'NO_DATA'
        ? `<span class="mission-health" data-level="none">${escapeHtml(t('admin.no_data'))}</span>`
        : `<span class="mission-health" data-level="${member.health}">${escapeHtml(severityLabel(member.health))}</span>`;
    const checkin = member.checkin_at
        ? `<span class="mission-checkin is-done"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>${escapeHtml(t('missions.checkin_done', { time: formatTime(member.checkin_at) }))}</span>`
        : `<span class="mission-checkin">${escapeHtml(t('missions.checkin_not_yet'))}</span>`;
    const alertness = member.alertness
        ? `<span class="mission-alertness" data-level="${member.alertness.level}">${escapeHtml(t(`missions.alertness.${member.alertness.level}`))}</span>
           <small>${escapeHtml(isToday(member.alertness.taken_at) ? t('missions.today') : formatDate(member.alertness.taken_at))}</small>`
        : `<span class="mission-alertness" data-level="none">${escapeHtml(t('missions.alertness.none'))}</span>`;
    const seen = member.online
        ? `<span class="mission-seen is-online"><span class="crew-dot" aria-hidden="true"></span>${escapeHtml(t('chat.online'))}</span>`
        : `<span class="mission-seen"><span class="crew-dot" aria-hidden="true"></span>${escapeHtml(member.last_seen_at ? timeAgo(member.last_seen_at) : t('missions.never_seen'))}</span>`;

    return `<tr class="${member.emergency ? 'is-emergency' : ''}">
        <th scope="row">
            <span class="mission-person">
                ${avatar(member)}
                <span class="mission-person-text">
                    <strong><bdi>${escapeHtml(member.name)}</bdi></strong>
                    ${member.emergency
                        ? `<span class="mission-emergency-chip">${escapeHtml(t('chat.emergency'))}</span>`
                        : `<small><bdi>${escapeHtml(member.astronaut_code ?? '')}</bdi></small>`}
                </span>
            </span>
        </th>
        <td>${health}</td>
        <td>${checkin}</td>
        <td><span class="mission-alertness-cell">${alertness}</span></td>
        <td>${seen}</td>
        <td class="mission-col-actions">
            <span class="mission-actions">
                <a class="mission-btn-view" href="${appUrl(`pages/crew.php?id=${member.id}`)}">${escapeHtml(t('missions.view_health'))}</a>
                <button class="mission-btn-remove" type="button" data-remove="${member.id}" data-name="${escapeHtml(member.name)}">${escapeHtml(t('missions.remove'))}</button>
            </span>
        </td>
    </tr>`;
}

page.statusSwitch.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-status]');
    if (!button || button.getAttribute('aria-pressed') === 'true') return;
    await change(() => api.post(`missions/${selectedId}/status`, { status: button.dataset.status }));
});

page.rows.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-remove]');
    if (!button) return;
    if (!window.confirm(t('missions.remove_confirm', { name: button.dataset.name, mission: detail.mission.name }))) return;
    button.disabled = true;
    await change(() => api.post(`missions/${selectedId}/members/${button.dataset.remove}/remove`));
    loadUnassigned();
});

// Saves one change, then redraws everything from the server.
async function change(action) {
    busy = true;
    hideError();
    try {
        await action();
    } catch (error) {
        showError(error.message);
    } finally {
        busy = false;
        detailSignature = '';
        listSignature = '';
        await refresh();
    }
}

// ---------- Add astronauts ----------

async function loadUnassigned() {
    const query = page.search.value.trim();
    try {
        const people = await api.get('admin/astronauts', { q: query });
        if (query !== page.search.value.trim()) return;   // a newer search is on its way
        page.unassigned.innerHTML = people.length ? people.map((person) => `
            <li class="mission-add-row">
                ${avatar(person)}
                <span class="mission-person-text">
                    <strong><bdi>${escapeHtml(person.name)}</bdi></strong>
                    <small><bdi>${escapeHtml(person.astronaut_code ?? '')}</bdi></small>
                </span>
                <button class="mission-btn-add" type="button" data-add="${person.id}">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    <span>${escapeHtml(t('missions.add'))}</span><span class="visually-hidden"> ${escapeHtml(person.name)}</span>
                </button>
            </li>`).join('')
            : `<li class="mission-add-empty">${escapeHtml(t(query ? 'missions.no_results' : 'missions.no_unassigned'))}</li>`;
    } catch (error) {
        showError(error.message);
    }
}

page.search.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(loadUnassigned, 250);
});

page.unassigned.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-add]');
    if (!button) return;
    button.disabled = true;
    await change(() => api.post(`missions/${selectedId}/members`, { astronaut_id: Number(button.dataset.add) }));
    loadUnassigned();
});

// ---------- New mission dialog ----------

function formError(field, message) {
    const box = page.form.querySelector(`[data-error-for="${field}"]`);
    box.textContent = message ?? '';
    box.hidden = !message;
    const input = page.form.elements[field];
    if (input instanceof HTMLElement) input.toggleAttribute('aria-invalid', Boolean(message));
}

$('[data-new-mission]').addEventListener('click', () => {
    page.form.reset();
    ['name', 'description', 'form'].forEach((field) => formError(field, null));
    page.dialog.showModal();
    page.form.elements.name.focus();
});

page.form.querySelector('[data-cancel]').addEventListener('click', () => page.dialog.close());

page.form.addEventListener('submit', async (event) => {
    event.preventDefault();
    ['name', 'description', 'form'].forEach((field) => formError(field, null));
    const data = new FormData(page.form);
    const name = String(data.get('name') ?? '').trim();
    if (!name) {
        formError('name', t('err.mission_name'));
        page.form.elements.name.focus();
        return;
    }
    const button = page.form.querySelector('[type="submit"]');
    button.disabled = true;
    try {
        const mission = await api.post('missions', { name, description: String(data.get('description') ?? ''), status: data.get('status') });
        page.dialog.close();
        await select(mission.id);
    } catch (error) {
        if (error.fields && Object.keys(error.fields).length) {
            Object.entries(error.fields).forEach(([field, message]) => formError(['name', 'description'].includes(field) ? field : 'form', message));
        } else {
            formError('form', error.message);
        }
    } finally {
        button.disabled = false;
    }
});

// ---------- Errors ----------

function showError(message) {
    page.error.textContent = message;
    page.error.hidden = false;
}

function hideError() {
    page.error.hidden = true;
}

// ---------- Start ----------

refresh().then(loadUnassigned);
setInterval(() => { if (!document.hidden) refresh(); }, REFRESH_MS);
