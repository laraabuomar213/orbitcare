// Mission Group: crew chat (design: crew status bar, thread, emergency card column, composer).
// All data from GET /api/missions/{id}/chat; refreshed every 3 s so "who is coming", "seen by"
// and the status bar stay live. Text from people is always escaped.
import { api, t, escapeHtml, formatTime, timeAgo, clearDraft } from './api.js';

const page = {
    name: document.querySelector('[data-mission-name]'),
    select: document.querySelector('[data-mission-select]'),
    code: document.querySelector('[data-mission-code]'),
    status: document.querySelector('[data-mission-status]'),
    empty: document.querySelector('[data-no-mission]'),
    body: document.querySelector('[data-chat-body]'),
    bar: document.querySelector('[data-crew-bar]'),
    thread: document.querySelector('[data-thread]'),
    column: document.querySelector('[data-emergency-column]'),
    composer: document.querySelector('[data-composer]'),
    error: document.querySelector('[data-chat-error]'),
};

let missionId = null;
let chat = null;            // last /chat response
let signature = '';         // re-render only when something changed
let lastReadSent = 0;
const pending = new Set();  // emergency ids with an answer being sent
const REFRESH_MS = 3000;

const initials = (name) => name.split(/\s+/).map((part) => part[0] ?? '').join('').slice(0, 1).toUpperCase();

// ---------- Loading ----------

async function init() {
    const missions = await api.get('missions');
    if (!missions.length) {
        page.name.textContent = t('nav.mission');
        page.empty.hidden = false;
        return;
    }
    // ?mission=<id> (from "Open crew chat" in My Missions) opens that mission's chat.
    const wanted = Number(new URLSearchParams(location.search).get('mission'));
    missionId = missions.find((m) => m.id === wanted)?.id ?? missions[0].id;
    if (missions.length > 1) {
        page.select.hidden = false;
        page.select.innerHTML = missions.map((m) => `<option value="${m.id}">${escapeHtml(m.name)}</option>`).join('');
        page.select.value = String(missionId);
        page.select.addEventListener('change', () => { missionId = Number(page.select.value); signature = ''; refresh(true); });
    }
    page.body.hidden = false;
    await refresh(true);
    setInterval(() => refresh(false), REFRESH_MS);
}

async function refresh(scrollToEnd) {
    if (!missionId) return;
    try {
        chat = await api.get(`missions/${missionId}/chat`);
        render(scrollToEnd);
        markRead();
    } catch (error) {
        showError(error.message);
    }
}

// ---------- Rendering ----------

function render(scrollToEnd) {
    const next = JSON.stringify([chat.mission, chat.messages, chat.reads, chat.crew, [...pending]]);
    if (next === signature) return;
    signature = next;

    const { mission } = chat;
    page.name.textContent = mission.name;
    page.code.hidden = false;
    page.code.textContent = mission.code;
    page.status.hidden = false;
    page.status.dataset.status = mission.status;
    page.status.textContent = t(`chat.mission_status.${mission.status}`);

    renderBar();
    const nearBottom = page.thread.scrollHeight - page.thread.scrollTop - page.thread.clientHeight < 80;
    renderThread();
    renderEmergencyColumn();
    if (scrollToEnd || nearBottom) page.thread.scrollTop = page.thread.scrollHeight;
}

function renderBar() {
    page.bar.innerHTML = chat.crew.map((member) => {
        const state = member.emergency ? 'emergency' : member.online ? 'online' : 'away';
        const label = member.emergency ? t('chat.emergency')
            : member.online ? t('chat.online')
            : member.last_seen_at ? t('chat.last_seen', { time: timeAgo(member.last_seen_at) }) : t('chat.away');
        const name = member.role === 'mission_admin' ? `${member.name} · ${t('chat.admin')}` : member.name;
        return `<li class="crew-member" data-state="${state}">
            <span class="crew-avatar" aria-hidden="true">${escapeHtml(initials(member.name))}</span>
            <span class="crew-member-text">
                <strong>${escapeHtml(name)}${member.is_you ? ` <small>(${escapeHtml(t('mission.you'))})</small>` : ''}</strong>
                <span class="crew-member-state"><span class="crew-dot" aria-hidden="true"></span>${escapeHtml(label)}</span>
            </span>
        </li>`;
    }).join('');
}

// Names of members (other than the author) who have read up to this message.
function seenBy(message) {
    return chat.reads.filter((r) => r.user_id !== message.author.id && r.last_read >= message.id).map((r) => r.name);
}

function renderThread() {
    const messages = chat.messages;
    if (!messages.length) {
        page.thread.innerHTML = `<p class="crew-thread-empty">${escapeHtml(t('mission.no_messages'))}</p>`;
        return;
    }
    const lastMine = [...messages].reverse().find((m) => m.is_you && m.kind === 'text');
    page.thread.innerHTML = messages.map((message) => {
        if (message.kind === 'system') {
            const text = message.event === 'removed' ? t('chat.removed', { name: message.subject ?? '' }) : t('chat.joined', { name: message.subject ?? '' });
            return `<p class="crew-system">${escapeHtml(text)}</p>`;
        }
        if (message.kind === 'recovery') {
            return `<div class="crew-recovery">
                <span class="crew-recovery-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                <p>${escapeHtml(t('chat.recovered', { name: message.author.name, problem: message.problems.join(t('common.list_separator')) }))}</p>
                <time datetime="${message.created_at}">${formatTime(message.created_at)}</time>
            </div>`;
        }
        if (message.kind === 'health_note') {
            // A calm crew report for a sustained warning — not an emergency, no alarm.
            return `<div class="crew-note">
                <span class="crew-note-badge">${escapeHtml(t('chat.note_badge'))}</span>
                <p class="crew-note-head">${escapeHtml(t('chat.note_head', { name: message.author.name, title: message.title }))}</p>
                ${message.detail ? `<p class="crew-note-line">${escapeHtml(message.detail)}</p>` : ''}
                ${message.support ? `<p class="crew-note-line crew-note-support"><span>${escapeHtml(t('chat.note_support'))}</span> ${escapeHtml(message.support)}</p>` : ''}
                <time datetime="${message.created_at}">${formatTime(message.created_at)}</time>
            </div>`;
        }
        if (message.kind === 'emergency') {
            // Active emergencies are shown in the side column; the thread keeps a short line.
            const active = !message.resolved;
            return `<p class="crew-emergency-line${active ? ' is-active' : ''}">
                <span class="crew-dot" aria-hidden="true"></span>
                ${escapeHtml(t(active ? 'chat.emergency_line' : 'chat.emergency_line_resolved', { name: message.author.name, problem: message.problems.join(t('common.list_separator')) }))}
                · <time datetime="${message.created_at}">${formatTime(message.created_at)}</time>
            </p>`;
        }
        const seen = message === lastMine ? seenBy(message) : [];
        return `<div class="crew-message${message.is_you ? ' is-you' : ''}">
            <span class="crew-author">${escapeHtml(message.is_you ? t('chat.me') : message.author.name)}</span>
            <p class="crew-bubble" dir="auto">${escapeHtml(message.body)}</p>
            <span class="crew-meta"><time datetime="${message.created_at}">${formatTime(message.created_at)}</time></span>
            ${seen.length ? `<span class="crew-seen">${escapeHtml(t('chat.seen_by', { names: seen.join(t('common.list_separator')) }))}</span>` : ''}
        </div>`;
    }).join('');
}

// Unresolved emergency cards (newest first): report, steps, who is coming, On my way / Seen.
function renderEmergencyColumn() {
    const cards = chat.messages.filter((m) => m.kind === 'emergency' && !m.resolved).reverse();
    page.column.hidden = !cards.length;
    page.body.classList.toggle('has-emergency', cards.length > 0);
    page.column.innerHTML = cards.map((card) => {
        const ids = card.emergencies.map((e) => e.id);
        const responses = card.emergencies.flatMap((e) => e.responses)
            .filter((r, index, all) => all.findIndex((other) => other.name === r.name) === index);
        const mine = card.emergencies.find((e) => e.my_response)?.my_response ?? null;
        const busy = ids.some((id) => pending.has(id));
        const report = card.report ?? { situation: '', crew_actions: [], source: 'rules' };
        const actions = card.is_you
            ? `<p class="crew-emergency-self">${escapeHtml(t('emergency.crew_alerted'))}</p>`
            : `<div class="crew-emergency-actions">
                <button class="btn crew-btn-go${mine === 'on_my_way' ? ' is-chosen' : ''}" type="button" data-respond="on_my_way" data-ids="${ids.join(',')}" ${busy ? 'disabled' : ''}>${escapeHtml(t('emergency.on_my_way'))}</button>
                <button class="btn crew-btn-seen${mine === 'seen' ? ' is-chosen' : ''}" type="button" data-respond="seen" data-ids="${ids.join(',')}" ${busy ? 'disabled' : ''}>${escapeHtml(t('emergency.seen'))}</button>
            </div>`;
        return `<article class="crew-emergency" aria-labelledby="emergency-${card.id}">
            <header class="crew-emergency-head">
                <h2 id="emergency-${card.id}">${escapeHtml(t('chat.emergency_title', { name: card.author.name }))}</h2>
                <p class="crew-emergency-time"><span class="crew-dot" aria-hidden="true"></span><time datetime="${card.created_at}">${formatTime(card.created_at)}</time></p>
            </header>
            <p class="crew-emergency-chips">${card.problems.map((p) => `<span>${escapeHtml(p)}</span>`).join('')}</p>
            <h3>${escapeHtml(t('chat.how_now'))}</h3>
            <p dir="auto">${escapeHtml(report.situation)}</p>
            <h3>${escapeHtml(t('chat.what_to_do'))}</h3>
            <ol>${report.crew_actions.map((step) => `<li dir="auto">${escapeHtml(step)}</li>`).join('')}</ol>
            <p class="crew-emergency-note">${escapeHtml(t(report.source === 'ai' ? 'chat.report_ai' : 'chat.report_rules'))}</p>
            <p class="crew-emergency-responders">${escapeHtml(responses.length
                ? responses.map((r) => t(`emergency.response.${r.response}`, { name: r.name })).join(' · ')
                : t('emergency.no_response_yet'))}</p>
            ${actions}
        </article>`;
    }).join('');
}

// ---------- Actions ----------

async function markRead() {
    const newest = chat.messages.at(-1)?.id ?? 0;
    if (!newest || newest <= lastReadSent || document.hidden) return;
    lastReadSent = newest;
    try {
        chat.reads = (await api.post(`missions/${missionId}/read`, { message_id: newest })).reads;
    } catch (error) { lastReadSent = 0; }
}

page.column.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-respond]');
    if (!button) return;
    const ids = button.dataset.ids.split(',').map(Number);
    ids.forEach((id) => pending.add(id));
    signature = '';
    render(false);
    try {
        for (const id of ids) await api.post(`emergencies/${id}/respond`, { response: button.dataset.respond });
    } catch (error) {
        showError(error.message);
    }
    ids.forEach((id) => pending.delete(id));
    signature = '';
    await refresh(false);
});

page.composer.addEventListener('submit', async (event) => {
    event.preventDefault();
    const input = page.composer.elements.body;
    const text = input.value.trim();
    if (!text) return;
    const button = page.composer.querySelector('button');
    button.disabled = true;
    hideError();
    try {
        chat = await api.post(`missions/${missionId}/chat`, { body: text, after_id: 0 });
        input.value = '';
        clearDraft('mission-message');
        signature = '';
        render(true);
        markRead();
    } catch (error) {
        showError(error.fields?.body ?? error.message);
    } finally {
        button.disabled = false;
        input.focus();
    }
});

document.addEventListener('visibilitychange', () => { if (!document.hidden) markRead(); });

function showError(message) {
    page.error.textContent = message;
    page.error.hidden = false;
}

function hideError() {
    page.error.hidden = true;
}

init().catch((error) => showError(error.message));
