// AI assistant panel / page. Shows the provider status honestly and keeps the
// conversation. With no provider configured, the backend stores the message and
// answers with a clear "AI provider not configured" notice.
import { api, t, escapeHtml, formatTime, renderProblem, clearDraft } from './api.js';

const panel = document.querySelector('[data-ai-panel]');
const messages = panel.querySelector('[data-ai-messages]');
const form = panel.querySelector('[data-ai-form]');
const input = form.querySelector('input');
const statusDot = panel.querySelector('[data-ai-status]');
const statusLabel = panel.querySelector('[data-ai-status-label]');
let providerStatus = null;

function renderConversation(conversation) {
    if (!conversation.length) {
        messages.innerHTML = `<div class="ai-bubble ai-bubble--system">
                <p>${escapeHtml(t('ai_panel.intro'))}</p>
                ${providerStatus && !providerStatus.configured ? `<p><strong>${escapeHtml(providerStatus.message)}</strong> — ${escapeHtml(t('ai_panel.rule_based'))}</p>` : ''}
            </div>`;
        return;
    }
    messages.innerHTML = conversation.map((message) => `
        <div class="ai-bubble ai-bubble--${message.sender}">
            <p dir="auto">${escapeHtml(message.body)}</p>
            ${message.payload?.action_plan?.length ? `<ol class="ai-bubble-plan">${message.payload.action_plan.map((step) => `<li dir="auto">${escapeHtml(step)}</li>`).join('')}</ol>` : ''}
            <time datetime="${message.created_at}">${formatTime(message.created_at)}</time>
        </div>`).join('');
    messages.scrollTop = messages.scrollHeight;
}

async function load() {
    try {
        providerStatus = await api.get('ai/status');
        statusDot.classList.toggle('is-ready', providerStatus.configured);
        statusDot.title = providerStatus.message;
        statusLabel.textContent = providerStatus.message;
        renderConversation(await api.get('ai/conversation'));
    } catch (error) {
        renderProblem(messages, error.message, load);
    }
}

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const text = input.value.trim();
    if (!text) return;

    const button = form.querySelector('button');
    button.disabled = true;
    try {
        renderConversation(await api.post('ai/chat', { message: text }));
        input.value = '';
        clearDraft('ai-chat');
    } catch (error) {
        messages.insertAdjacentHTML('beforeend', `<div class="ai-bubble ai-bubble--system"><p>${escapeHtml(error.message)}</p></div>`);
    } finally {
        button.disabled = false;
        input.focus();
    }
});

load();
