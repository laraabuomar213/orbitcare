// AI Assistant page: consultations with the General Doctor AI and specialists.
// This file only displays what the backend returns — which specialist is recommended,
// how urgent things are, and every number shown come from /api/ai/*.
import { api, t, unitLabel, LOCALE, request, ApiError, icon, escapeHtml, formatTime, formatNumber, appUrl, clearDraft } from './api.js';
import { setLastUpdate } from './shell.js';

const thread = document.querySelector('[data-thread]');
const composer = document.querySelector('[data-composer]');
const input = composer.querySelector('input');
const sendButton = composer.querySelector('button');
const composerError = document.querySelector('[data-composer-error]');
const sessionBar = document.querySelector('[data-session-bar]');
const consultationsBox = document.querySelector('[data-consultations]');
const viewAllButton = document.querySelector('[data-view-all]');

const state = {
    specialists: {},       // key → { name, focus, icon, tone }
    conversation: null,    // the open consultation
    messages: [],
    consultations: [],
    showAll: false,
    busy: false,
};

// ---------- Loading ----------

async function init() {
    try {
        const [specialists, consultations] = await Promise.all([api.get('ai/specialists'), api.get('ai/conversations')]);
        specialists.forEach((s) => { state.specialists[s.key] = s; });
        state.consultations = consultations;
        renderConsultations();

        const params = new URLSearchParams(window.location.search);
        const requested = Number(params.get('c'));
        // "Ask the AI" links from other pages (?ask=…) only fill in the question; the astronaut sends it.
        const ask = params.get('ask');
        const activeGeneral = consultations.find((c) => c.specialist === 'general' && c.status === 'active');
        if (requested) {
            await openConsultation(requested);
        } else if (activeGeneral) {
            await openConsultation(activeGeneral.id);
        } else {
            renderWelcome();
        }
        if (ask && !input.value) {
            input.value = ask.slice(0, 500);
            input.focus();
        }
    } catch (error) {
        showThreadProblem(error.message, init);
    }
}

async function openConsultation(id) {
    try {
        const data = await api.get(`ai/conversations/${id}`);
        setConversation(data.conversation, data.messages);
    } catch (error) {
        if (error.status === 404) {
            history.replaceState(null, '', window.location.pathname);
            renderWelcome(t('assistant.not_found'));
            return;
        }
        showThreadProblem(error.message, () => openConsultation(id));
    }
}

// Opens (or reopens) a consultation with a specialist. When coming from the General Doctor,
// the backend passes the context on, so nothing needs to be repeated.
async function openSpecialist(key) {
    if (state.busy) return;
    setBusy(true, key === 'general' ? t('assistant.opening_general') : t('assistant.sharing_with', { name: state.specialists[key]?.name ?? t('assistant.specialist') }));
    try {
        const referral = state.conversation && state.conversation.specialist === 'general' && state.conversation.message_count > 0 ? state.conversation.id : undefined;
        const data = await api.post('ai/conversations', { specialist: key, referral_from: referral });
        setConversation(data.conversation, data.messages);
        await refreshConsultations();
    } catch (error) {
        showComposerError(error.message);
    } finally {
        setBusy(false);
    }
}

async function refreshConsultations() {
    try {
        state.consultations = await api.get('ai/conversations');
        renderConsultations();
    } catch (error) { /* list stays as it was; the thread shows its own errors */ }
}

function setConversation(conversation, messages) {
    state.conversation = conversation;
    state.messages = messages;
    history.replaceState(null, '', `?c=${conversation.id}`);
    renderSession();
    renderThread();
    renderConsultations();
    renderSpecialistBadges();
}

// ---------- Sending ----------

composer.addEventListener('submit', async (event) => {
    event.preventDefault();
    const text = input.value.trim();
    if (!text || state.busy) {
        if (!text) showComposerError(t('assistant.write_first'));
        return;
    }
    hideComposerError();

    // The thread shows the message and an honest "analyzing" state until the backend answers.
    const pending = appendPending(text);
    setBusy(true);
    try {
        if (!state.conversation || state.conversation.status !== 'active') {
            const opened = await api.post('ai/conversations', { specialist: state.conversation?.specialist ?? 'general' });
            state.conversation = opened.conversation;
            state.messages = opened.messages;
        }
        const data = await request('POST', `ai/conversations/${state.conversation.id}/messages`, { body: { message: text }, timeout: 60000 });
        input.value = '';
        clearDraft('ai-consultation');
        setConversation(data.conversation, [...state.messages, ...data.messages]);
        await refreshConsultations();
    } catch (error) {
        pending.remove();
        if (error instanceof ApiError && error.code === 'consultation_closed') {
            showComposerError(t('assistant.closed_not_sent'));
        } else {
            showComposerError(error.code === 'timeout' ? t('assistant.too_long') : error.message);
        }
        if (state.conversation) renderThread();
    } finally {
        setBusy(false);
        input.focus();
    }
});

async function retryLast() {
    setBusy(true, t('assistant.analyzing'));
    try {
        const data = await request('POST', `ai/conversations/${state.conversation.id}/retry`, { timeout: 60000 });
        setConversation(data.conversation, [...state.messages, ...data.messages]);
    } catch (error) {
        showComposerError(error.message);
    } finally {
        setBusy(false);
    }
}

// ---------- Rendering: thread ----------

function renderWelcome(note) {
    state.conversation = null;
    state.messages = [];
    renderSession();
    renderSpecialistBadges();
    thread.setAttribute('aria-busy', 'false');
    thread.innerHTML = `
        ${note ? `<div class="state-block"><p>${escapeHtml(note)}</p></div>` : ''}
        <div class="message message--assistant">
            <span class="message-avatar message-avatar--bot">${icon('robot')}</span>
            <div class="message-bubble">
                <p class="message-title">${escapeHtml(t('assistant.welcome_title'))}</p>
                <p>${escapeHtml(t('assistant.welcome_text'))}</p>
            </div>
        </div>`;
}

function renderThread() {
    thread.setAttribute('aria-busy', 'false');
    if (!state.messages.length) {
        renderWelcomeInto();
        return;
    }
    thread.innerHTML = state.messages.map(renderMessage).join('');
    // Show the start of the newest message (replies can be long), not the very bottom.
    const newest = thread.lastElementChild;
    thread.scrollTop = newest ? newest.offsetTop - 8 : 0;   // offsetTop is relative to the thread (position: relative)

    const latest = [...state.messages].reverse().find((m) => m.payload?.data_last_updated);
    if (latest) setLastUpdate(latest.payload.data_last_updated);
}

function renderWelcomeInto() {
    const name = state.specialists[state.conversation?.specialist]?.name ?? t('ai.specialist.general.name');
    thread.innerHTML = `
        <div class="message message--assistant">
            <span class="message-avatar message-avatar--bot">${icon('robot')}</span>
            <div class="message-bubble">
                <p class="message-title">${escapeHtml(t('assistant.hello_name', { name }))}</p>
                <p>${escapeHtml(t('assistant.hello_text'))}</p>
            </div>
        </div>`;
}

function renderMessage(message) {
    const time = `<time class="message-time" datetime="${message.created_at}">${formatTime(message.created_at)}</time>`;

    if (message.sender === 'user') {
        return `<div class="message message--user">
            <div class="message-bubble"><p dir="auto">${escapeHtml(message.body)}</p>${time}</div>
            <span class="message-avatar message-avatar--user">${icon('user')}</span>
        </div>`;
    }
    if (message.sender === 'system' || !message.payload) {
        return `<div class="message message--assistant">
            <span class="message-avatar message-avatar--bot">${icon('robot')}</span>
            <div class="message-bubble message-bubble--notice"><p dir="auto">${escapeHtml(message.body)}</p>${time}</div>
        </div>`;
    }

    const p = message.payload;
    if (p.kind === 'consultation_reply' && p.interpretation?.status === 'success') return renderAiReply(message, p, time);
    const sections = p.kind === 'consultation_reply' ? renderReply(p) : renderHandoff(p);
    return `<div class="message message--assistant">
        <span class="message-avatar message-avatar--bot">${icon('robot')}</span>
        <div class="message-bubble">
            <p class="message-title">${escapeHtml(p.specialist_name)}</p>
            ${p.crew_alerted ? `<p class="message-crew-alerted" role="status">${escapeHtml(t('ai.crew_alerted_note'))}</p>` : ''}
            <p>${escapeHtml(p.lead)}</p>
            ${sections}
            <p class="message-safety">${escapeHtml(p.safety_notice ?? '')}</p>
            ${time}
        </div>
    </div>`;
}

// A reply written by the online AI: its own answer first (it remembers the conversation),
// then the action plan. Urgent advice, specialist suggestions and medication notes stay visible;
// the data it was based on is one tap away instead of repeated in every message.
function renderAiReply(message, p, time) {
    const paragraphs = message.body.split(/\n{2,}/).map((text) => text.trim()).filter(Boolean)
        .map((text) => `<p dir="auto">${escapeHtml(text)}</p>`).join('');
    const plan = (p.action_plan ?? []).length ? `
        <div class="message-section message-plan">
            <h3>${escapeHtml(t('assistant.action_plan'))}</h3>
            <ol>${p.action_plan.map((step) => `<li dir="auto">${escapeHtml(step)}</li>`).join('')}</ol>
        </div>` : '';
    const showNextStep = p.next_step.level === 'urgent' || p.recommendation;
    const nextStep = showNextStep ? `
        <div class="message-section" data-level="${escapeHtml(p.next_step.level)}">
            <h3>${escapeHtml(t('assistant.next_step'))}</h3>
            <p>${escapeHtml(p.next_step.text)}</p>
            ${renderRecommendation(p)}
        </div>` : '';
    const questions = p.follow_up_questions?.length
        ? `<div class="message-section"><h3>${escapeHtml(t('assistant.questions'))}</h3><ul>${p.follow_up_questions.map((q) => `<li dir="auto">${escapeHtml(q)}</li>`).join('')}</ul></div>` : '';

    return `<div class="message message--assistant">
        <span class="message-avatar message-avatar--bot">${icon('robot')}</span>
        <div class="message-bubble message-bubble--ai">
            <p class="message-title">${escapeHtml(p.specialist_name)}</p>
            ${p.crew_alerted ? `<p class="message-crew-alerted" role="status">${escapeHtml(t('ai.crew_alerted_note'))}</p>` : ''}
            <div class="message-answer">${paragraphs}</div>
            ${plan}
            ${nextStep}
            ${renderMedicationNotes(p.medication_notes)}
            ${questions}
            <details class="message-details message-basis">
                <summary>${escapeHtml(t('assistant.basis'))}</summary>
                ${p.symptoms?.length ? `<p class="message-symptoms">${escapeHtml(t('assistant.you_mentioned'))} ${p.symptoms.map((s) => `<span class="chip${s.red_flag ? ' chip--alert' : ''}">${escapeHtml(s.label)}</span>`).join(' ')}</p>` : ''}
                <div class="message-section"><h3>${escapeHtml(t('assistant.observed'))}</h3>${renderObservations(p)}</div>
                <div class="message-section"><h3>${escapeHtml(t('assistant.interpretation'))}</h3><p dir="auto">${escapeHtml(p.interpretation.text)}</p></div>
                ${showNextStep ? '' : `<div class="message-section"><h3>${escapeHtml(t('assistant.next_step'))}</h3><p>${escapeHtml(p.next_step.text)}</p></div>`}
                ${renderReferences(p.references)}
            </details>
            <p class="message-safety">${escapeHtml(p.safety_notice ?? '')}</p>
            ${time}
        </div>
    </div>`;
}

function renderRecommendation(p) {
    if (!p.recommendation) return '';
    return `<div class="recommendation">
        <p><strong>${escapeHtml(t('assistant.suggested', { name: p.recommendation.name }))}</strong> <span class="muted">${escapeHtml(t('assistant.confidence', { confidence: t(`ai.confidence.${p.recommendation.confidence}`) }))}</span></p>
        <details class="message-details">
            <summary>${escapeHtml(p.recommendation.reasons.length === 1 ? t('assistant.why_one') : t('assistant.why', { n: p.recommendation.reasons.length }))}</summary>
            <ul>${p.recommendation.reasons.map((r) => `<li>${escapeHtml(r)}</li>`).join('')}</ul>
        </details>
        <button class="btn btn-primary btn-small" type="button" data-open-specialist="${escapeHtml(p.recommendation.specialist)}">${escapeHtml(t('assistant.open_specialist', { name: p.recommendation.name }))} ${icon('arrowRight', 'flip-rtl')}</button>
    </div>`;
}

function renderReply(p) {
    const interpretation = p.interpretation.status === 'success'
        ? `<p>${escapeHtml(p.interpretation.text)}</p>`
        : `<p class="muted">${escapeHtml(p.interpretation.message)}</p>${p.retryable ? `<button class="btn btn-ghost btn-small" type="button" data-retry>${escapeHtml(t('common.retry'))}</button>` : ''}`;

    const recommendation = renderRecommendation(p);

    return `
        ${p.symptoms?.length ? `<p class="message-symptoms">${escapeHtml(t('assistant.you_mentioned'))} ${p.symptoms.map((s) => `<span class="chip${s.red_flag ? ' chip--alert' : ''}">${escapeHtml(s.label)}</span>`).join(' ')}</p>` : ''}
        <div class="message-section">
            <h3>${escapeHtml(t('assistant.observed'))}</h3>
            ${renderObservations(p)}
        </div>
        <div class="message-section">
            <h3>${escapeHtml(t('assistant.interpretation'))}</h3>
            ${interpretation}
        </div>
        <div class="message-section" data-level="${escapeHtml(p.next_step.level)}">
            <h3>${escapeHtml(t('assistant.next_step'))}</h3>
            <p>${escapeHtml(p.next_step.text)}</p>
            ${p.next_step.ai_text ? `<p class="muted" dir="auto">${escapeHtml(t('assistant.ai_prefix', { text: p.next_step.ai_text }))}</p>` : ''}
            ${recommendation}
        </div>
        ${renderMedicationNotes(p.medication_notes)}
        ${p.follow_up_questions?.length ? `<div class="message-section"><h3>${escapeHtml(t('assistant.questions'))}</h3><ul>${p.follow_up_questions.map((q) => `<li>${escapeHtml(q)}</li>`).join('')}</ul></div>` : ''}
        ${renderReferences(p.references)}`;
}

// A reported symptom found in the official FDA label of one of the astronaut's medications.
function renderMedicationNotes(notes) {
    if (!notes?.length) return '';
    return `<div class="message-section message-section--medication"><h3>${escapeHtml(t('assistant.medications'))}</h3>
        <ul>${notes.map((n) => `<li>${escapeHtml(n.text)} <a href="${escapeHtml(n.url)}" target="_blank" rel="noopener">${escapeHtml(t('assistant.label_link', { name: n.label_name }))}</a></li>`).join('')}</ul></div>`;
}

// MedlinePlus pages (U.S. National Library of Medicine) for the symptoms in the message.
function renderReferences(references) {
    if (!references?.length) return '';
    return `<div class="message-section message-references"><h3>${escapeHtml(t('assistant.sources'))}</h3>
        <ul>${references.map((r) => `<li><a href="${escapeHtml(r.url)}" target="_blank" rel="noopener" dir="ltr">${escapeHtml(r.title)}</a><span class="muted" dir="ltr">${escapeHtml(r.summary)}</span></li>`).join('')}</ul>
        <p class="message-meta">${escapeHtml(t('assistant.sources_note'))}</p></div>`;
}

function renderHandoff(p) {
    const h = p.handoff;
    return `
        ${h?.patient_concern ? `<blockquote class="message-quote">${escapeHtml(h.patient_concern)}</blockquote>` : ''}
        ${h?.reason_for_referral ? `<p class="muted">${escapeHtml(h.reason_for_referral)}</p>` : ''}
        ${h?.general_doctor_assessment?.length ? `<details class="message-details"><summary>${escapeHtml(t('assistant.gd_assessment', { n: h.general_doctor_assessment.length }))}</summary><ul>${h.general_doctor_assessment.map((r) => `<li>${escapeHtml(r)}</li>`).join('')}</ul></details>` : ''}
        <div class="message-section"><h3>${escapeHtml(t('assistant.relevant_data'))}</h3>${renderObservations(p)}</div>
        ${p.follow_up_questions?.length ? `<div class="message-section"><h3>${escapeHtml(t('assistant.understand_better'))}</h3><ul>${p.follow_up_questions.map((q) => `<li>${escapeHtml(q)}</li>`).join('')}</ul></div>` : ''}`;
}

function renderObservations(p) {
    const note = p.data_scope_note ? `<p class="muted">${escapeHtml(p.data_scope_note)}</p>` : '';
    // What the astronaut entered in their daily check-ins (shown even without sensor data).
    const selfReported = (p.self_reported ?? []).length
        ? `<ul class="observation-notes observation-notes--self">${p.self_reported.map((line) => `<li>${escapeHtml(line)}</li>`).join('')}</ul>`
        : '';
    if (p.data_status === 'none') {
        return `${note}<p class="muted">${escapeHtml(t('assistant.no_sensor_data'))}</p>${selfReported}`;
    }
    const chips = (p.observations ?? []).map((o) => o.available
        ? `<span class="observation" data-classification="${escapeHtml(o.classification)}" title="${escapeHtml(t('assistant.observation_title', { id: o.raw_measurement_id, record: o.source_record_id ?? '', time: formatTime(o.measured_at, true) }))}">
               ${escapeHtml(o.label)} <strong>${formatNumber(o.value)} ${escapeHtml(unitLabel(o.unit))}</strong>
           </span>`
        : `<span class="observation" data-classification="NONE">${escapeHtml(t('assistant.data_unavailable', { label: o.label }))}</span>`).join('');
    const trends = (p.trends ?? []).map((trend) => `<li><strong>${escapeHtml(trend.label)}:</strong> ${escapeHtml(trend.text)}</li>`).join('');
    const patterns = (p.patterns ?? []).map((text) => `<li>${escapeHtml(text)}</li>`).join('');
    const alerts = (p.alerts ?? []).map((a) => `<li><a href="${appUrl(`pages/alerts.php?id=${a.id}`)}">${escapeHtml(t(`assistant.alert_link.${a.severity === 'RED' ? 'RED' : 'YELLOW'}`, { title: a.title }))}</a></li>`).join('');

    const noteCount = (p.trends ?? []).length + (p.patterns ?? []).length + (p.alerts ?? []).length;
    return `${note}
        ${chips ? `<div class="observation-list">${chips}</div>` : ''}
        ${selfReported}
        ${noteCount ? `<details class="message-details"><summary>${escapeHtml(t('assistant.notes', { n: noteCount }))}</summary><ul class="observation-notes">${trends}${patterns}${alerts}</ul></details>` : ''}
        ${p.data_last_updated ? `<p class="message-meta">${escapeHtml(t('assistant.replay_meta', { time: formatTime(p.data_last_updated, true) }))}</p>` : ''}`;
}

function appendPending(text) {
    thread.insertAdjacentHTML('beforeend', `
        <div class="message message--user" data-pending>
            <div class="message-bubble"><p>${escapeHtml(text)}</p></div>
            <span class="message-avatar message-avatar--user">${icon('user')}</span>
        </div>
        <div class="message message--assistant" data-pending>
            <span class="message-avatar message-avatar--bot">${icon('robot')}</span>
            <div class="message-bubble message-bubble--pending"><span class="typing-dots" aria-hidden="true"><i></i><i></i><i></i></span> ${escapeHtml(t('assistant.analyzing'))}</div>
        </div>`);
    thread.scrollTop = thread.scrollHeight;
    return { remove: () => thread.querySelectorAll('[data-pending]').forEach((node) => node.remove()) };
}

function showThreadProblem(message, retry) {
    thread.setAttribute('aria-busy', 'false');
    thread.innerHTML = `<div class="state-block state-block--error"><p>${escapeHtml(message || t('assistant.something_wrong'))}</p><button class="btn btn-ghost btn-small" type="button">${escapeHtml(t('common.retry'))}</button></div>`;
    thread.querySelector('button').addEventListener('click', retry);
}

thread.addEventListener('click', (event) => {
    const specialistButton = event.target.closest('[data-open-specialist]');
    if (specialistButton) openSpecialist(specialistButton.dataset.openSpecialist);
    if (event.target.closest('[data-retry]')) retryLast();
});

// ---------- Rendering: session bar, specialist cards, consultation list ----------

function renderSession() {
    const c = state.conversation;
    sessionBar.hidden = !c;
    if (!c) return;
    const specialist = state.specialists[c.specialist];
    sessionBar.querySelector('[data-session-pill]').innerHTML = `
        <span class="session-icon tone-${escapeHtml(specialist?.tone ?? 'green')}">${icon(specialist?.icon ?? 'plus')}</span>
        <strong>${escapeHtml(c.specialist_name)}</strong>
        <span class="muted">${escapeHtml(t('assistant.consultation_meta', { id: c.id, status: t(`assistant.status.${c.status === 'active' ? 'active' : 'closed'}`) }))}</span>`;
    sessionBar.querySelector('[data-return-general]').hidden = c.specialist === 'general';
    sessionBar.querySelector('[data-close-consultation]').hidden = c.status !== 'active';

    const closed = c.status !== 'active';
    input.placeholder = t(closed ? 'assistant.placeholder_closed' : (state.messages.length ? 'assistant.placeholder_more' : 'assistant.placeholder'));
}

// "Recommended" comes from the latest General Doctor reply (decided by the backend).
function renderSpecialistBadges() {
    const latestReply = [...state.messages].reverse().find((m) => m.payload?.kind === 'consultation_reply');
    const recommended = latestReply?.payload?.recommendation?.specialist ?? null;
    document.querySelectorAll('[data-specialist]').forEach((card) => {
        const key = card.dataset.specialist;
        const isCurrent = state.conversation?.specialist === key && state.conversation?.status === 'active';
        const badge = card.querySelector('[data-badge]');
        card.classList.toggle('is-recommended', key === recommended && !isCurrent);
        card.classList.toggle('is-current', isCurrent);
        badge.hidden = !(isCurrent || key === recommended);
        badge.textContent = t(isCurrent ? 'assistant.badge.current' : 'assistant.badge.recommended');
        card.setAttribute('aria-pressed', String(isCurrent));
    });
}

function renderConsultations() {
    const list = state.showAll ? state.consultations : state.consultations.slice(0, 4);
    viewAllButton.hidden = state.consultations.length <= 4;
    if (!list.length) {
        consultationsBox.innerHTML = `<div class="state-block"><p>${escapeHtml(t('assistant.no_consultations'))}</p></div>`;
        return;
    }
    consultationsBox.innerHTML = list.map((c) => {
        const s = state.specialists[c.specialist] ?? {};
        const selected = state.conversation?.id === c.id;
        return `<button class="consultation-item${selected ? ' is-selected' : ''}" type="button" data-consultation="${c.id}" aria-current="${selected}">
            <span class="consultation-icon tone-${escapeHtml(s.tone ?? 'green')}">${icon(s.icon ?? 'plus')}</span>
            <span class="consultation-text">
                <strong>${escapeHtml(c.specialist_name)}</strong>
                <span>${escapeHtml(relativeDay(c.updated_at))} • ${formatTime(c.updated_at)} · #${c.id}</span>
            </span>
            ${c.status === 'active'
                ? `<span class="consultation-status">${escapeHtml(t('assistant.status.active'))}</span>`
                : `<span class="consultation-status consultation-status--closed">${escapeHtml(t('assistant.status.closed'))}</span>`}
            ${icon('chevron', 'consultation-chevron flip-rtl')}
        </button>`;
    }).join('');
}

function relativeDay(iso) {
    const date = new Date(iso);
    const today = new Date();
    const yesterday = new Date(today.getTime() - 86400000);
    if (date.toDateString() === today.toDateString()) return t('assistant.today');
    if (date.toDateString() === yesterday.toDateString()) return t('assistant.yesterday');
    return date.toLocaleDateString(LOCALE, { month: 'short', day: 'numeric' });
}

consultationsBox.addEventListener('click', (event) => {
    const item = event.target.closest('[data-consultation]');
    if (item && !state.busy) openConsultation(Number(item.dataset.consultation));
});

viewAllButton.addEventListener('click', () => {
    state.showAll = !state.showAll;
    viewAllButton.setAttribute('aria-expanded', String(state.showAll));
    viewAllButton.innerHTML = state.showAll
        ? escapeHtml(t('assistant.show_less'))
        : `${escapeHtml(t('dashboard.view_all'))} <span class="flip-rtl" aria-hidden="true">→</span>`;
    renderConsultations();
});

document.querySelectorAll('[data-specialist]').forEach((card) => {
    card.addEventListener('click', () => openSpecialist(card.dataset.specialist));
});

sessionBar.querySelector('[data-return-general]').addEventListener('click', () => openSpecialist('general'));

sessionBar.querySelector('[data-close-consultation]').addEventListener('click', async (event) => {
    if (!state.conversation) return;
    event.target.disabled = true;
    try {
        const conversation = await api.post(`ai/conversations/${state.conversation.id}/close`);
        setConversation(conversation, state.messages);
        await refreshConsultations();
    } catch (error) {
        showComposerError(error.message);
    } finally {
        event.target.disabled = false;
    }
});

// ---------- Helpers ----------

function setBusy(busy, label) {
    state.busy = busy;
    input.disabled = busy;
    sendButton.disabled = busy;
    document.querySelectorAll('[data-specialist]').forEach((card) => { card.disabled = busy; });
    if (busy && label) {
        thread.insertAdjacentHTML('beforeend', `<div class="message message--assistant" data-pending><span class="message-avatar message-avatar--bot">${icon('robot')}</span><div class="message-bubble message-bubble--pending"><span class="typing-dots" aria-hidden="true"><i></i><i></i><i></i></span> ${escapeHtml(label)}</div></div>`);
        thread.scrollTop = thread.scrollHeight;
    }
    if (!busy) thread.querySelectorAll('[data-pending]').forEach((node) => node.remove());
}

function showComposerError(message) {
    composerError.textContent = message || t('assistant.something_wrong');
    composerError.hidden = false;
}

function hideComposerError() {
    composerError.hidden = true;
    composerError.textContent = '';
}

init();
