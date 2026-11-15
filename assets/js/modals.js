// Shared on every page: modal behavior and the login popup.
// Texts come from the server in the page language (assets/js/i18n.js loads first).
const modalText = (key) => window.OrbitI18n.t(key);

// Modals: shared open/close behavior for every <dialog class="modal-overlay">.
// ×, Escape or a click outside the card closes any of them.
let scrollPositionBeforeModal = 0;

function openModal(modal) {
    if (!document.querySelector('.modal-overlay[open]')) {
        scrollPositionBeforeModal = window.scrollY;
        document.documentElement.classList.add('has-modal-open');
    }
    modal.showModal();
}

// Plays the closing animation first, then actually closes the dialog.
function closeModal(modal) {
    if (!modal.open || modal.classList.contains('is-closing')) {
        return;
    }
    modal.classList.add('is-closing');
    modal.querySelector('.modal-content').addEventListener('animationend', () => modal.close(), { once: true });
}

document.querySelectorAll('.modal-overlay').forEach((modal) => {
    modal.querySelector('.modal-close').addEventListener('click', () => closeModal(modal));

    // The dialog fills the screen, so a click directly on it is a click outside the card.
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal(modal);
        }
    });

    // Escape fires "cancel": stop the instant close so the animation can play.
    modal.addEventListener('cancel', (event) => {
        event.preventDefault();
        closeModal(modal);
    });

    // Runs however the dialog ends up closed. Once no modal is left open,
    // the page scrolls again from exactly where the user left it.
    modal.addEventListener('close', () => {
        modal.classList.remove('is-closing');
        if (!document.querySelector('.modal-overlay[open]')) {
            document.documentElement.classList.remove('has-modal-open');
            window.scrollTo(0, scrollPositionBeforeModal);
        }
    });
});


// Login modal: one shared popup, opened by any element with data-open-login —
// including Log In buttons added in the future.
const loginModal = document.getElementById('login-modal');
const loginForm = document.getElementById('login-form');
const loginMessage = loginModal.querySelector('.login-message');
const loginSubmitButton = loginForm.querySelector('.login-submit');
const loginPasswordInput = document.getElementById('login-password');
const loginPasswordToggle = loginModal.querySelector('.login-password-toggle');

function openLoginModal() {
    // If another modal (e.g. Welcome) is open, swap it for the login popup.
    document.querySelectorAll('.modal-overlay[open]').forEach((modal) => {
        if (modal !== loginModal) {
            modal.close();
        }
    });
    if (!loginModal.open) {
        openModal(loginModal);
    }
}

document.addEventListener('click', (event) => {
    if (event.target.closest('[data-open-login]')) {
        event.preventDefault();
        openLoginModal();
    }
});

function showLoginMessage(text, tone = 'error') {
    loginMessage.textContent = text;
    loginMessage.dataset.tone = tone;
    loginMessage.hidden = false;
}

function clearLoginMessage() {
    loginMessage.hidden = true;
    loginMessage.textContent = '';
}

function setLoginPasswordVisible(isVisible) {
    loginPasswordInput.type = isVisible ? 'text' : 'password';
    loginPasswordToggle.setAttribute('aria-pressed', String(isVisible));
    loginPasswordToggle.setAttribute('aria-label', modalText(isVisible ? 'login.hide_password' : 'login.show_password'));
}

loginPasswordToggle.addEventListener('click', () => {
    setLoginPasswordVisible(loginPasswordInput.type === 'password');
});

// Protected pages send signed-out visitors here with ?login=required or ?login=expired.
const loginReason = new URLSearchParams(window.location.search).get('login');
if (loginReason) {
    openLoginModal();
    if (loginReason === 'expired') {
        showLoginMessage(modalText('login.expired'), 'info');
    } else {
        showLoginMessage(modalText('err.not_authenticated'), 'info');
    }
}

// Sends the credentials to /api/auth/login, which answers with JSON:
// { ok: true, data: { redirect } } or { ok: false, error: { message } }.
loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearLoginMessage();

    const emailInput = loginForm.elements.email;
    if (!emailInput.value.trim() || !emailInput.checkValidity()) {
        showLoginMessage(modalText('err.email_invalid'));
        emailInput.focus();
        return;
    }
    if (!loginPasswordInput.value) {
        showLoginMessage(modalText('login.enter_password'));
        loginPasswordInput.focus();
        return;
    }

    loginSubmitButton.disabled = true;
    try {
        const response = await fetch(loginForm.action, {
            method: 'POST',
            body: new FormData(loginForm),
            headers: { Accept: 'application/json' },
        });
        const result = await response.json();

        if (response.ok && result.ok) {
            window.location.href = result.data.redirect;
            return;
        }
        showLoginMessage(result.error?.message || modalText('err.invalid_credentials'));
    } catch (error) {
        // The server couldn't be reached or sent back something unexpected.
        showLoginMessage(modalText('login.unavailable'));
    } finally {
        loginSubmitButton.disabled = false;
    }
});

// Buttons for features that aren't connected yet say so honestly.
loginModal.querySelectorAll('[data-auth-pending]').forEach((button) => {
    button.addEventListener('click', () => showLoginMessage(button.dataset.authPending, 'info'));
});

// Each time the popup closes: clear messages and the password, hide the password again.
loginModal.addEventListener('close', () => {
    clearLoginMessage();
    loginPasswordInput.value = '';
    setLoginPasswordVisible(false);
});
