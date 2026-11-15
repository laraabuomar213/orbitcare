// Central API client. Every call to the backend goes through request():
// it adds the CSRF token, times out slow requests, turns failures into ApiError,
// and sends the user to log in again if the session has ended (saving drafts first).

const BASE = document.querySelector('meta[name="app-base"]')?.content ?? '';
const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

// Translations (assets/js/i18n.js loads first and fills window.OrbitI18n).
const i18n = window.OrbitI18n ?? { lang: 'en', dir: 'ltr', locale: 'en-US', t: (key) => key, unit: (unit) => unit };
export const t = i18n.t;
export const LANG = i18n.lang;
export const LOCALE = i18n.locale;
export const unitLabel = i18n.unit;

export class ApiError extends Error {
    constructor(status, code, message, fields = {}) {
        super(message);
        this.status = status;
        this.code = code;
        this.fields = fields;
    }
}

// ---------- Connection status (shown in the header) ----------

const connectionListeners = new Set();
let isOnline = null;

export function onConnectionChange(listener) {
    connectionListeners.add(listener);
    if (isOnline !== null) listener(isOnline);
}

function setOnline(online) {
    if (online === isOnline) return;
    isOnline = online;
    connectionListeners.forEach((listener) => listener(online));
}

// ---------- Requests ----------

export async function request(method, path, { query, body, timeout = 15000 } = {}) {
    const url = new URL(`${BASE}/api/${path}`, window.location.origin);
    Object.entries(query ?? {}).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, value);
    });

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeout);
    const headers = { Accept: 'application/json' };
    if (method !== 'GET') headers['X-CSRF-Token'] = csrfToken();
    if (body !== undefined) headers['Content-Type'] = 'application/json';

    let response;
    try {
        response = await fetch(url, {
            method,
            headers,
            credentials: 'same-origin',
            signal: controller.signal,
            body: body !== undefined ? JSON.stringify(body) : undefined,
        });
    } catch (error) {
        setOnline(false);
        const timedOut = error.name === 'AbortError';
        throw new ApiError(0, timedOut ? 'timeout' : 'network_error',
            timedOut ? t('err.timeout') : t('err.network'));
    } finally {
        clearTimeout(timer);
    }

    setOnline(true);

    let payload;
    try {
        payload = await response.json();
    } catch (error) {
        throw new ApiError(response.status, 'malformed_response', t('err.malformed'));
    }

    if (!response.ok || !payload?.ok) {
        const problem = payload?.error ?? {};
        if (response.status === 401) handleSignedOut(problem.code);
        throw new ApiError(response.status, problem.code ?? 'error', problem.message ?? t('err.request_failed'), problem.fields ?? {});
    }
    return payload.data;
}

export const api = {
    get: (path, query) => request('GET', path, { query }),
    post: (path, body = {}) => request('POST', path, { body }),
    put: (path, body = {}) => request('PUT', path, { body }),
};

export function appUrl(path) {
    return `${BASE}/${path.replace(/^\//, '')}`;
}

function handleSignedOut(code) {
    saveDrafts();
    window.location.href = appUrl(`index.php?login=${code === 'session_expired' ? 'expired' : 'required'}`);
}

// ---------- Drafts: text in [data-draft] fields survives a forced re-login ----------

export function saveDrafts() {
    document.querySelectorAll('[data-draft]').forEach((field) => {
        try { sessionStorage.setItem(`orbitcare-draft:${field.dataset.draft}`, field.value); } catch (error) { /* storage unavailable */ }
    });
}

export function restoreDrafts() {
    document.querySelectorAll('[data-draft]').forEach((field) => {
        try {
            const saved = sessionStorage.getItem(`orbitcare-draft:${field.dataset.draft}`);
            if (saved && !field.value) field.value = saved;
        } catch (error) { /* storage unavailable */ }
        field.addEventListener('input', saveDrafts);
    });
}

export function clearDraft(key) {
    try { sessionStorage.removeItem(`orbitcare-draft:${key}`); } catch (error) { /* storage unavailable */ }
}

// ---------- Display helpers ----------

export const METRICS = {
    heart_rate: { label: t('metric.short.heart_rate'), icon: 'heart', tone: 'rose' },
    spo2: { label: t('metric.short.spo2'), icon: 'drop', tone: 'blue' },
    pulse_rate: { label: t('metric.short.pulse_rate'), icon: 'pulse', tone: 'amber' },
    respiratory_rate: { label: t('metric.short.respiratory_rate'), icon: 'lungs', tone: 'violet' },
};

export const METRIC_ORDER = ['heart_rate', 'spo2', 'pulse_rate', 'respiratory_rate'];

export const ICONS = {
    heart: '<path d="M12 20s-7-4.4-8.6-8.9C2.3 8 4.3 5 7.4 5c1.9 0 3.4 1 4.6 2.7C13.2 6 14.7 5 16.6 5c3.1 0 5.1 3 4 6.1C19 15.6 12 20 12 20z"/>',
    drop: '<path d="M12 3.5s6 6.4 6 10.6a6 6 0 0 1-12 0C6 9.9 12 3.5 12 3.5z"/><path d="M9.5 14.5a2.5 2.5 0 0 0 2.5 2.5"/>',
    pulse: '<path d="M3 12h4l2-5 3.5 10 2.5-7 1.5 2H21"/>',
    lungs: '<path d="M12 4v7M12 11c-1.5 1-2.5 1.2-3.5 1.2"/><path d="M12 11c1.5 1 2.5 1.2 3.5 1.2"/><path d="M8.5 6.5C6 7 4 11 4 15.5c0 2.5 1.2 4 3 4 2 0 3-1.2 3-3.5V9c0-1.6-.6-2.7-1.5-2.5zM15.5 6.5C18 7 20 11 20 15.5c0 2.5-1.2 4-3 4-2 0-3-1.2-3-3.5V9c0-1.6.6-2.7 1.5-2.5z"/>',
    check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    warning: '<path d="M12 4L2.5 20h19z"/><path d="M12 10v4.5M12 17.3v.1"/>',
    clock: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
    star: '<path d="M12 2.5c.5 5.6 3.4 8.5 9 9-5.6.5-8.5 3.4-9 9-.5-5.6-3.4-8.5-9-9 5.6-.5 8.5-3.4 9-9z"/>',
    send: '<path d="M20.5 3.5L10 14M20.5 3.5L14 20.5l-4-6.5-6.5-4z"/>',
    trend: '<path d="M4 20h16M6.5 20v-6M10.5 20V9M14.5 20v-8M18.5 20V5"/>',
    bolt: '<path d="M13 2.5L5 13.5h6l-1 8 8-11h-6z"/>',
    stethoscope: '<path d="M6 3.5v5a4 4 0 0 0 8 0v-5M10 12.5v2a4.5 4.5 0 0 0 9 0v-2"/><circle cx="19" cy="10.5" r="2"/>',
    users: '<circle cx="9" cy="8" r="3"/><path d="M3.5 19c.6-3.2 2.8-5 5.5-5s4.9 1.8 5.5 5"/><circle cx="16.5" cy="9" r="2.4"/><path d="M15.5 14.2c2.6-.3 4.6 1.3 5 4.3"/>',
    gear: '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v2.2M12 19.3v2.2M4.6 4.6l1.6 1.6M17.8 17.8l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.6 19.4l1.6-1.6M17.8 6.2l1.6-1.6"/>',
    database: '<ellipse cx="12" cy="6" rx="7" ry="2.5"/><path d="M5 6v12c0 1.4 3.1 2.5 7 2.5s7-1.1 7-2.5V6M5 12c0 1.4 3.1 2.5 7 2.5s7-1.1 7-2.5"/>',
    brain: '<path d="M9 4.5a3 3 0 0 0-3 3 3 3 0 0 0-1.5 5.3A3 3 0 0 0 7 17.5a2.5 2.5 0 0 0 5 .5V5.5a2 2 0 0 0-3-1z"/><path d="M15 4.5a3 3 0 0 1 3 3 3 3 0 0 1 1.5 5.3 3 3 0 0 1-2.5 4.7 2.5 2.5 0 0 1-5 .5"/><path d="M9 9.5c1 0 1.5.5 2 1M15 9.5c-1 0-1.5.5-2 1M8.5 14c1 .3 2 .2 3.5-.5M15.5 14c-1 .3-2 .2-3.5-.5"/>',
    plus: '<rect x="4" y="4" width="16" height="16" rx="4.5"/><path d="M12 8.5v7M8.5 12h7" stroke-width="2.2"/>',
    moon: '<path d="M19.5 14.5A8 8 0 0 1 9.5 4.5a8 8 0 1 0 10 10z"/>',
    robot: '<rect x="5" y="8" width="14" height="10" rx="3"/><path d="M12 4.5V8M9.5 12.5h.01M14.5 12.5h.01" stroke-width="2.2"/><path d="M10 15.5h4M3 12v3M21 12v3"/><circle cx="12" cy="4" r="1"/>',
    user: '<circle cx="12" cy="8.5" r="3.5"/><path d="M5 19.5c1-3.5 3.7-5.5 7-5.5s6 2 7 5.5"/>',
    history: '<path d="M4 12a8 8 0 1 0 2.4-5.7"/><path d="M4 4.5v3.5h3.5M12 8v4l2.5 2"/>',
    arrowLeft: '<path d="M19 12H5M11 6l-6 6 6 6"/>',
    arrowRight: '<path d="M5 12h14M13 6l6 6-6 6"/>',
    chevron: '<path d="M9 6l6 6-6 6"/>',
};

export function icon(name, className = '') {
    return `<svg class="icon ${className}" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">${ICONS[name] ?? ''}</svg>`;
}

export function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
}

export function formatNumber(value, decimals = 0) {
    return value === null || value === undefined ? '—' : Number(value).toFixed(decimals).replace(/\.0+$/, '');
}

// Dates and times use the interface language (Arabic month names, Western digits).
export function formatTime(iso, withSeconds = false) {
    if (!iso) return '—';
    return new Date(iso).toLocaleTimeString(LOCALE, { hour: 'numeric', minute: '2-digit', ...(withSeconds && { second: '2-digit' }) });
}

export function formatDateTime(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(LOCALE, { dateStyle: 'medium', timeStyle: 'short' });
}

export function formatDate(iso, options = { dateStyle: 'medium' }) {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString(LOCALE, options);
}

export function timeAgo(iso) {
    if (!iso) return '';
    const seconds = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000));
    if (seconds < 60) return t('time.seconds_ago', { n: seconds });
    if (seconds < 3600) return t('time.minutes_ago', { n: Math.round(seconds / 60) });
    if (seconds < 86400) return t('time.hours_ago', { n: Math.round(seconds / 3600) });
    return formatDateTime(iso);
}

// Position inside the original recording, e.g. 134 → "02:14"
export function formatOffset(seconds) {
    const total = Math.max(0, Math.floor(seconds ?? 0));
    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}

export function severityLabel(severity) {
    return { RED: t('severity.red'), YELLOW: t('severity.yellow'), GREEN: t('severity.green') }[severity] ?? severity;
}

// Standard error/empty block with an optional Retry button.
export function renderProblem(container, message, onRetry) {
    container.innerHTML = `<div class="state-block state-block--error"><p>${escapeHtml(message)}</p>${onRetry ? `<button class="btn btn-ghost btn-small" type="button">${escapeHtml(t('common.retry'))}</button>` : ''}</div>`;
    container.querySelector('button')?.addEventListener('click', onRetry);
}

export function renderEmpty(container, message) {
    container.innerHTML = `<div class="state-block"><p>${escapeHtml(message)}</p></div>`;
}

// Runs fn every `interval` ms while the tab is visible; stops polling when hidden.
export function poll(fn, interval) {
    let timer = null;
    const tick = async () => {
        try { await fn(); } catch (error) { /* each caller shows its own errors */ }
        timer = document.hidden ? null : setTimeout(tick, interval);
    };
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && timer === null) tick();
    });
    tick();
}
