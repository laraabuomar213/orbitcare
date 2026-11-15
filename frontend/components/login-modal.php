<?php
// Login popup, shared by every page. Any element with data-open-login opens it (assets/js/modals.js).
?>
    <!-- ===================== Login modal ===================== -->
    <dialog class="modal-overlay login-modal" id="login-modal" aria-labelledby="login-modal-title" aria-describedby="login-modal-text">
        <div class="modal-content login-modal-content">
            <button class="modal-close" type="button" aria-label="<?= e(t('common.close')) ?>">
                <svg viewBox="0 0 16 16" aria-hidden="true">
                    <path d="M3 3l10 10M13 3L3 13" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                </svg>
            </button>

            <div class="login-modal-brand">
                <svg class="login-modal-logo" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 0C12.7 7.6 16.4 11.3 24 12C16.4 12.7 12.7 16.4 12 24C11.3 16.4 7.6 12.7 0 12C7.6 11.3 11.3 7.6 12 0Z" fill="currentColor"/>
                </svg>
                <span>OrbitCare</span>
            </div>

            <h2 class="login-modal-title" id="login-modal-title"><?= e(t('login.title')) ?></h2>
            <p class="login-modal-text" id="login-modal-text"><?= e(t('login.text')) ?></p>

            <form class="login-form" id="login-form" action="<?= e(url('api/auth/login')) ?>" method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="login-field">
                    <label class="visually-hidden" for="login-email"><?= e(t('signup.email')) ?></label>
                    <svg class="login-field-icon" viewBox="0 0 24 24" aria-hidden="true">
                        <rect x="3" y="5.5" width="18" height="13" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/>
                        <path d="M3.5 7l8.5 6 8.5-6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                    </svg>
                    <input class="login-input" id="login-email" name="email" type="email" placeholder="<?= e(t('signup.email')) ?>" autocomplete="email" dir="ltr" required autofocus>
                </div>

                <div class="login-field">
                    <label class="visually-hidden" for="login-password"><?= e(t('signup.password')) ?></label>
                    <svg class="login-field-icon" viewBox="0 0 24 24" aria-hidden="true">
                        <rect x="5" y="10.5" width="14" height="10" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/>
                        <path d="M8 10.5V8a4 4 0 0 1 8 0v2.5M12 14.5v2" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    <input class="login-input" id="login-password" name="password" type="password" placeholder="<?= e(t('signup.password')) ?>" autocomplete="current-password" required>
                    <button class="login-password-toggle" type="button" aria-label="<?= e(t('login.show_password')) ?>" aria-pressed="false" aria-controls="login-password">
                        <!-- Eye with a slash: shown while the password is hidden -->
                        <svg class="icon-password-hidden" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M3 12s3.5-6.5 9-6.5S21 12 21 12s-3.5 6.5-9 6.5S3 12 3 12z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                            <circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.5"/>
                            <path d="M4 4l16 16" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                        <!-- Open eye: shown while the password is visible -->
                        <svg class="icon-password-visible" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M3 12s3.5-6.5 9-6.5S21 12 21 12s-3.5 6.5-9 6.5S3 12 3 12z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                            <circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.5"/>
                        </svg>
                    </button>
                </div>

                <!-- Errors and status messages appear here; hidden until needed. -->
                <p class="login-message" role="alert" hidden></p>

                <button class="button button-large login-submit" type="submit">
                    <?= e(t('login.submit')) ?>
                    <svg class="button-icon flip-rtl" viewBox="0 0 16 16" aria-hidden="true">
                        <path d="M3 8h10M9 4l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </form>

            <p class="login-divider"><?= e(t('login.or')) ?></p>

            <!-- data-auth-pending: features not connected yet show this message instead of faking a result. -->
            <div class="login-social">
                <button class="login-social-button" type="button" data-auth-pending="<?= e(t('login.google_pending')) ?>">
                    <svg class="login-social-icon" viewBox="0 0 48 48" aria-hidden="true">
                        <path fill="#FBBC05" d="M43.611 20.083H42V20H24v8h11.303c-1.649 4.657-6.08 8-11.303 8-6.627 0-12-5.373-12-12s5.373-12 12-12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 12.955 4 4 12.955 4 24s8.955 20 20 20 20-8.955 20-20c0-1.341-.138-2.65-.389-3.917z"/>
                        <path fill="#EA4335" d="M6.306 14.691l6.571 4.819C14.655 15.108 18.961 12 24 12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 16.318 4 9.656 8.337 6.306 14.691z"/>
                        <path fill="#34A853" d="M24 44c5.166 0 9.86-1.977 13.409-5.192l-6.19-5.238C29.211 35.091 26.715 36 24 36c-5.202 0-9.619-3.317-11.283-7.946l-6.522 5.025C9.505 39.556 16.227 44 24 44z"/>
                        <path fill="#4285F4" d="M43.611 20.083H42V20H24v8h11.303c-.792 2.237-2.231 4.166-4.087 5.571l6.19 5.238C36.971 39.205 44 34 44 24c0-1.341-.138-2.65-.389-3.917z"/>
                    </svg>
                    <?= e(t('login.google')) ?>
                </button>
                <button class="login-social-button" type="button" data-auth-pending="<?= e(t('login.apple_pending')) ?>">
                    <svg class="login-social-icon" viewBox="0 0 24 24" aria-hidden="true">
                        <path fill="currentColor" d="M12.152 6.896c-.948 0-2.415-1.078-3.96-1.04-2.04.027-3.91 1.183-4.961 3.014-2.117 3.675-.546 9.103 1.519 12.09 1.013 1.454 2.208 3.09 3.792 3.039 1.52-.065 2.09-.987 3.935-.987 1.831 0 2.35.987 3.96.948 1.637-.026 2.676-1.48 3.676-2.948 1.156-1.688 1.636-3.325 1.662-3.415-.039-.013-3.182-1.221-3.22-4.857-.026-3.04 2.48-4.494 2.597-4.559-1.429-2.09-3.623-2.324-4.39-2.376-2-.156-3.675 1.09-4.61 1.09zM15.53 3.83c.843-1.012 1.4-2.427 1.245-3.83-1.207.052-2.662.805-3.532 1.818-.78.896-1.454 2.338-1.273 3.714 1.338.104 2.715-.688 3.559-1.701"/>
                    </svg>
                    <?= e(t('login.apple')) ?>
                </button>
            </div>

            <div class="login-footer">
                <button class="login-link" type="button" data-auth-pending="<?= e(t('login.reset_pending')) ?>"><?= e(t('login.forgot')) ?></button>
                <span class="login-footer-divider" aria-hidden="true"></span>
                <p class="login-signup">
                    <?= e(t('login.no_account')) ?>
                    <a class="login-link login-link--strong" href="<?= e(url('pages/signup.php')) ?>"><?= e(t('login.sign_up')) ?></a>
                </p>
            </div>
        </div>
    </dialog>
