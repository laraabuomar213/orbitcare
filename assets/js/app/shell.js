// App shell behaviour shared by every signed-in page: greeting, mobile sidebar,
// connection status, "last update" time, alert badge and draft restore.
import { api, t, onConnectionChange, formatTime, restoreDrafts, poll } from './api.js';
import './emergency.js';   // crew emergency alarm on every signed-in page

// Greeting from the viewer's own clock.
const greeting = document.querySelector('[data-greeting]');
if (greeting) {
    const hour = new Date().getHours();
    greeting.textContent = t(hour < 12 ? 'shell.good_morning' : hour < 18 ? 'shell.good_afternoon' : 'shell.good_evening');
}

// Sidebar opens as a drawer on small screens.
const menuButton = document.querySelector('.app-menu-button');
const sidebar = document.getElementById('app-sidebar');
menuButton?.addEventListener('click', () => {
    const open = menuButton.getAttribute('aria-expanded') !== 'true';
    menuButton.setAttribute('aria-expanded', String(open));
    sidebar.classList.toggle('is-open', open);
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && sidebar?.classList.contains('is-open')) {
        sidebar.classList.remove('is-open');
        menuButton.setAttribute('aria-expanded', 'false');
        menuButton.focus();
    }
});

// "Online" means the backend answered the last request.
const statusBox = document.querySelector('[data-connection-status]');
const statusLabel = document.querySelector('[data-connection-label]');
onConnectionChange((online) => {
    statusBox?.classList.toggle('is-offline', !online);
    if (statusLabel) statusLabel.textContent = t(online ? 'shell.online' : 'shell.offline');
});

// Pages with live health data set this to the newest measurement time;
// other pages show when their data was last refreshed.
const lastUpdate = document.querySelector('[data-last-update]');
let pageOwnsLastUpdate = false;
export function setLastUpdate(iso, fromHealthData = true) {
    if (fromHealthData) pageOwnsLastUpdate = true;
    if (lastUpdate && iso) {
        lastUpdate.textContent = formatTime(iso);
        lastUpdate.dateTime = iso;
    }
}

// User preferences from Settings.
let preferences = { notifications: { in_app_alerts: true, alert_sound: false }, display: { reduced_motion: false, language: 'en' } };
api.get('settings').then((settings) => {
    preferences = settings;
    document.documentElement.classList.toggle('reduce-motion', Boolean(settings.display?.reduced_motion));
}).catch(() => { /* defaults stay */ });

// Active alert count in the sidebar (and an optional short tone when it goes up).
const badge = document.querySelector('[data-alert-badge]');
let lastAlertCount = null;
export function setAlertBadge(count) {
    if (lastAlertCount !== null && count > lastAlertCount && preferences.notifications?.alert_sound) playAlertTone();
    lastAlertCount = count;
    if (!badge) return;
    badge.hidden = !count || !preferences.notifications?.in_app_alerts;
    badge.textContent = count > 99 ? '99+' : String(count);
    badge.setAttribute('aria-label', t(count === 1 ? 'shell.active_alerts_one' : 'shell.active_alerts', { n: count }));
}

function playAlertTone() {
    try {
        const audio = new AudioContext();
        const tone = audio.createOscillator();
        const gain = audio.createGain();
        tone.frequency.value = 880;
        gain.gain.setValueAtTime(0.08, audio.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + 0.35);
        tone.connect(gain).connect(audio.destination);
        tone.start();
        tone.stop(audio.currentTime + 0.35);
    } catch (error) { /* audio not available */ }
}

poll(async () => {
    const alerts = await api.get('alerts', { filter: 'active', limit: 200 });
    setAlertBadge(alerts.length);
    if (!pageOwnsLastUpdate) setLastUpdate(new Date().toISOString(), false);
}, 20000);

restoreDrafts();
