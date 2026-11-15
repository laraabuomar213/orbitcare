// Settings page: language, preference toggles (saved immediately) and password change.
import { api, t } from './api.js';

const form = document.querySelector('[data-settings-form]');
const message = document.querySelector('[data-settings-message]');

function applySettings(settings) {
    form.querySelectorAll('input[type="checkbox"]').forEach((input) => {
        const [group, key] = input.name.split('.');
        input.checked = Boolean(settings[group]?.[key]);
    });
}

async function load() {
    try {
        applySettings(await api.get('settings'));
    } catch (error) {
        message.className = 'form-error';
        message.textContent = error.message;
        form.querySelectorAll('input').forEach((input) => { input.disabled = true; });
    }
}

form.addEventListener('change', async (event) => {
    const [group, key] = event.target.name.split('.');
    try {
        applySettings(await api.put('settings', { [group]: { [key]: event.target.checked } }));
        message.className = 'form-success';
        message.textContent = t('common.saved');
    } catch (error) {
        event.target.checked = !event.target.checked;
        message.className = 'form-error';
        message.textContent = error.message;
    }
});

// Language: saved to the account and this device, then the page reloads in the new language.
const languageForm = document.querySelector('[data-language-form]');
const languageMessage = document.querySelector('[data-language-message]');
languageForm.addEventListener('change', async (event) => {
    const inputs = languageForm.querySelectorAll('input');
    inputs.forEach((input) => { input.disabled = true; });
    try {
        await api.put('settings', { display: { language: event.target.value } });
        window.location.reload();
    } catch (error) {
        languageMessage.className = 'form-error';
        languageMessage.textContent = error.message;
        inputs.forEach((input) => {
            input.disabled = false;
            input.checked = input.defaultChecked;
        });
    }
});

const passwordForm = document.querySelector('[data-password-form]');
passwordForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const result = document.querySelector('[data-password-message]');
    passwordForm.querySelectorAll('[data-error]').forEach((el) => { el.textContent = ''; });
    result.textContent = '';
    try {
        await api.post('auth/password', {
            current_password: passwordForm.elements.current_password.value,
            new_password: passwordForm.elements.new_password.value,
        });
        passwordForm.reset();
        result.className = 'form-success';
        result.textContent = t('settings.password_changed');
    } catch (error) {
        Object.entries(error.fields ?? {}).forEach(([field, text]) => {
            passwordForm.querySelector(`[data-error="${field}"]`).textContent = text;
        });
        result.className = 'form-error';
        result.textContent = error.fields ? '' : error.message;
    }
});

load();
