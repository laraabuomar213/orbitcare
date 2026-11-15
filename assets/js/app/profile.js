// Astronaut profile: load and save editable details.
import { api, t, escapeHtml, formatOffset, renderProblem } from './api.js';

const form = document.querySelector('[data-profile-form]');
const info = document.querySelector('[data-profile-info]');
const message = document.querySelector('[data-profile-message]');

async function load() {
    try {
        const p = await api.get('astronaut');
        form.elements.full_name.value = p.name;
        form.querySelector('#p-email').value = p.email ?? '';
        form.querySelector('#p-code').value = p.astronaut_code;
        form.elements.age.value = p.age ?? '';
        form.elements.height_cm.value = p.height_cm ?? '';
        form.elements.weight_kg.value = p.weight_kg ?? '';

        const s = p.data_source;
        const missions = p.missions.map((m) => `${escapeHtml(m.name)} (${escapeHtml(t(`mission.status.${m.status}`))})`).join(escapeHtml(t('common.list_separator')));
        info.innerHTML = `
            <dt>${escapeHtml(t('shell.mission'))}</dt><dd>${missions || escapeHtml(t('profile.none'))}</dd>
            <dt>${escapeHtml(t('profile.health_data'))}</dt><dd>${escapeHtml(!s ? t('profile.no_data_source')
                : s.mode === 'live_device_simulated' ? t('profile.data_source_device_simulated', { device: s.device_id })
                : s.mode === 'live_device' ? t('profile.data_source_device', { device: s.device_id })
                : t('profile.data_source', { record: s.record_id, source: s.source, duration: formatOffset(s.duration_seconds) }))}</dd>
            <dt>${escapeHtml(t('profile.medical_background'))}</dt><dd>${escapeHtml(t(p.medical_background_on_file ? 'profile.background_on_file' : 'profile.background_missing'))}</dd>`;
    } catch (error) {
        renderProblem(info, error.message, load);
    }
}

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    form.querySelectorAll('[data-error]').forEach((el) => { el.textContent = ''; });
    form.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));
    message.className = '';
    message.textContent = '';

    const button = form.querySelector('button');
    button.disabled = true;
    try {
        await api.put('astronaut', {
            full_name: form.elements.full_name.value,
            age: form.elements.age.value,
            height_cm: form.elements.height_cm.value,
            weight_kg: form.elements.weight_kg.value,
        });
        message.className = 'form-success';
        message.textContent = t('common.saved');
    } catch (error) {
        Object.entries(error.fields ?? {}).forEach(([field, text]) => {
            form.querySelector(`[data-error="${field}"]`).textContent = text;
            form.elements[field]?.setAttribute('aria-invalid', 'true');
        });
        message.className = 'form-error';
        message.textContent = error.message;
    } finally {
        button.disabled = false;
    }
});

load();
