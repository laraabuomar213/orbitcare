<?php require __DIR__ . '/backend/bootstrap.php'; ?>
<!DOCTYPE html>
<html <?= html_language_attributes() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OrbitCare — <?= e(t('landing.eyebrow')) ?></title>
    <meta name="description" content="<?= e(t('landing.meta_description')) ?>">

    <link rel="stylesheet" href="assets/css/fonts.css">
    <link rel="stylesheet" href="assets/css/style.css">

    <!-- Lets the CSS know JavaScript is available (used by the scroll-reveal effect). -->
    <script>document.documentElement.classList.add('js');</script>
    <?= i18n_script_tag() ?>
    <script src="assets/js/i18n.js"></script>
    <script src="assets/js/modals.js" defer></script>
    <script src="assets/js/main.js" defer></script>
</head>
<body>
    <a class="skip-link" href="#main-content"><?= e(t('landing.skip')) ?></a>

    <!-- ===================== Navbar ===================== -->
    <header class="site-header">
        <nav class="navbar container" aria-label="<?= e(t('shell.main_nav')) ?>">
            <a class="brand-logo" href="index.php">
                <svg class="brand-logo-icon" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 0C12.7 7.6 16.4 11.3 24 12C16.4 12.7 12.7 16.4 12 24C11.3 16.4 7.6 12.7 0 12C7.6 11.3 11.3 7.6 12 0Z" fill="currentColor"/>
                </svg>
                <span class="brand-logo-text"><?= e(t('brand.name')) ?></span>
            </a>

            <button class="navbar-toggle" type="button" aria-label="<?= e(t('landing.menu')) ?>" aria-expanded="false" aria-controls="navbar-menu">
                <span class="navbar-toggle-bar"></span>
                <span class="navbar-toggle-bar"></span>
                <span class="navbar-toggle-bar"></span>
            </button>

            <div class="navbar-menu" id="navbar-menu">
                <ul class="navbar-links">
                    <!-- Temporary: these point to landing-page sections until the real pages exist. -->
                    <li><a class="navbar-link is-active" href="#overview"><?= e(t('landing.nav_home')) ?></a></li>
                    <li><a class="navbar-link" href="#how-it-works"><?= e(t('landing.nav_analytics')) ?></a></li>
                    <li><a class="navbar-link" href="#advanced-care"><?= e(t('landing.nav_about')) ?></a></li>
                    <li><a class="navbar-link" href="#get-started"><?= e(t('landing.nav_support')) ?></a></li>
                </ul>

                <div class="navbar-actions">
                    <?= language_switch_link('lang-switch lang-switch--nav') ?>
                    <a class="button button-outline" href="#login-modal" data-open-login aria-haspopup="dialog"><?= e(t('login.submit')) ?></a>
                    <a class="button button-dark" href="pages/signup.php">
                        <?= e(t('landing.sign_up')) ?>
                        <svg class="button-icon flip-rtl" viewBox="0 0 16 16" aria-hidden="true">
                            <path d="M3 8h10M9 4l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                </div>
            </div>
        </nav>
    </header>

    <main id="main-content">

        <!-- ===================== Hero ===================== -->
        <section class="hero-section" id="overview" aria-labelledby="hero-title">
            <div class="hero-content container">
                <div class="hero-text">
                    <p class="section-eyebrow"><?= e(t('landing.eyebrow')) ?></p>
                    <h1 class="hero-title" id="hero-title">
                        <?= e(t('landing.hero_title')) ?>
                        <span class="hero-title-accent"><?= e(t('landing.hero_accent')) ?></span>
                    </h1>
                    <p class="hero-description"><?= e(t('landing.hero_text')) ?></p>

                    <div class="hero-actions">
                        <button class="button button-dark button-large get-started-btn" type="button" aria-haspopup="dialog" aria-controls="welcome-modal">
                            <?= e(t('landing.get_started')) ?>
                            <svg class="button-icon flip-rtl" viewBox="0 0 16 16" aria-hidden="true">
                                <path d="M3 8h10M9 4l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                        <a class="button button-outline button-large" href="#how-it-works">
                            <?= e(t('landing.learn_more')) ?>
                            <svg class="button-icon" viewBox="0 0 16 16" aria-hidden="true">
                                <path d="M5 3.5v9l7-4.5z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                            </svg>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Temporary images cut from the design references (English and Arabic versions; the Arabic
                 one has Arabic labels on the monitor). Replace the files with the original high-resolution
                 artwork (same names) — no code changes needed. -->
            <?php $heroImage = current_language() === 'ar' ? ['hero-station-ar.jpg', 620, 394] : ['hero-station.jpg', 594, 385]; ?>
            <div class="hero-visual">
                <img class="hero-visual-image" src="assets/images/<?= $heroImage[0] ?>" width="<?= $heroImage[1] ?>" height="<?= $heroImage[2] ?>"
                     alt="<?= e(t('landing.hero_alt')) ?>"
                     fetchpriority="high">
            </div>

            <a class="hero-scroll" href="#how-it-works" aria-label="<?= e(t('landing.scroll')) ?>">
                <svg class="hero-scroll-icon" viewBox="0 0 12 12" aria-hidden="true">
                    <path d="M3 4.5l3 3 3-3" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </a>

            <!-- ===================== Feature bar ===================== -->
            <div class="container">
                <section class="feature-bar" id="features" aria-labelledby="features-title">
                    <h2 class="visually-hidden" id="features-title"><?= e(t('landing.features')) ?></h2>
                    <ul class="feature-list">
                        <li class="feature-item">
                            <svg class="feature-icon" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M12 20.5s-7.5-4.6-9.3-9.4C1.5 7.8 3.6 4.5 7 4.5c2 0 3.6 1.1 5 3 1.4-1.9 3-3 5-3 3.4 0 5.5 3.3 4.3 6.6-1.8 4.8-9.3 9.4-9.3 9.4z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/>
                                <path d="M4.5 12h3.5l1.5-2.5 2.5 5 2-4 1.2 1.5H19.5" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <div>
                                <h3 class="feature-title"><?= e(t('landing.f1_title')) ?></h3>
                                <p class="feature-text"><?= e(t('landing.f1_text')) ?></p>
                            </div>
                        </li>
                        <li class="feature-item">
                            <svg class="feature-icon" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M3.5 20.5h17M7 20.5v-6M11 20.5v-9M15 20.5v-7M19 20.5V9" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                                <path d="M5 11l4.5-4 4 2.5L19.5 4M16.5 4h3v3" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <div>
                                <h3 class="feature-title"><?= e(t('landing.f2_title')) ?></h3>
                                <p class="feature-text"><?= e(t('landing.f2_text')) ?></p>
                            </div>
                        </li>
                        <li class="feature-item">
                            <svg class="feature-icon" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M12 3l7.5 3v5.5c0 4.6-3.2 8.1-7.5 9.5-4.3-1.4-7.5-4.9-7.5-9.5V6z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/>
                                <path d="M8.8 12.2l2.3 2.3 4.3-4.6" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <div>
                                <h3 class="feature-title"><?= e(t('landing.f3_title')) ?></h3>
                                <p class="feature-text"><?= e(t('landing.f3_text')) ?></p>
                            </div>
                        </li>
                        <li class="feature-item">
                            <svg class="feature-icon" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M10.5 8.5l5 5-2.5 2.5-5-5z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/>
                                <path d="M9 9.5L5.5 6 7 4.5l3.5 3.5M14.5 15l3.5 3.5 1.5-1.5-3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/>
                                <path d="M4.5 14.5a5 5 0 0 0 5 5M3 14.5a6.5 6.5 0 0 0 6.5 6.5" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                            </svg>
                            <div>
                                <h3 class="feature-title"><?= e(t('landing.f4_title')) ?></h3>
                                <p class="feature-text"><?= e(t('landing.f4_text')) ?></p>
                            </div>
                        </li>
                    </ul>
                </section>
            </div>
        </section>

        <!-- ===================== How It Works ===================== -->
        <section class="workflow-section" id="how-it-works" aria-labelledby="workflow-title">
            <div class="workflow-layout container">
                <div class="workflow-intro" data-reveal>
                    <p class="section-eyebrow"><?= e(t('landing.how_eyebrow')) ?></p>
                    <h2 class="section-title" id="workflow-title">
                        <?= e(t('landing.how_title')) ?>
                        <span class="section-title-accent"><?= e(t('landing.how_accent')) ?></span>
                    </h2>
                    <p class="workflow-description"><?= e(t('landing.how_text')) ?></p>
                    <a class="button button-dark button-large" href="#advanced-care">
                        <?= e(t('landing.explore')) ?>
                        <svg class="button-icon flip-rtl" viewBox="0 0 16 16" aria-hidden="true">
                            <path d="M3 8h10M9 4l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                </div>

                <ol class="workflow-steps" data-reveal>
                    <li class="workflow-step">
                        <div class="workflow-icon">
                            <span class="workflow-number" aria-hidden="true">1</span>
                            <!-- Smart vest -->
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M8 3h2.2c.4 1.2 1 1.8 1.8 1.8S13.4 4.2 13.8 3H16l.5 3.5c0 1.2 1.5 1.8 1.5 3.5V20a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V10c0-1.7 1.5-2.3 1.5-3.5z" fill="currentColor" fill-opacity="0.14" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/>
                                <path d="M8.5 13.5h2l1-2 1.5 4 1-2h1.5" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <div class="workflow-step-body">
                            <h3 class="workflow-step-title"><?= e(t('landing.s1_title')) ?></h3>
                            <p class="workflow-step-text"><?= e(t('landing.s1_text')) ?></p>
                        </div>
                    </li>
                    <li class="workflow-step">
                        <div class="workflow-icon">
                            <span class="workflow-number" aria-hidden="true">2</span>
                            <!-- Processor chip with a signal line -->
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <rect x="6.5" y="6.5" width="11" height="11" rx="1.5" fill="currentColor" fill-opacity="0.14" stroke="currentColor" stroke-width="1.3"/>
                                <path d="M9.5 3.5v3M12 3.5v3M14.5 3.5v3M9.5 17.5v3M12 17.5v3M14.5 17.5v3M3.5 9.5h3M3.5 12h3M3.5 14.5h3M17.5 9.5h3M17.5 12h3M17.5 14.5h3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                                <path d="M8.5 12.5h1.5l1-2 1.5 3 1-1.5h2" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <div class="workflow-step-body">
                            <h3 class="workflow-step-title"><?= e(t('landing.s2_title')) ?></h3>
                            <p class="workflow-step-text"><?= e(t('landing.s2_text')) ?></p>
                        </div>
                    </li>
                    <li class="workflow-step">
                        <div class="workflow-icon">
                            <span class="workflow-number" aria-hidden="true">3</span>
                            <!-- Alert bell -->
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M6 16v-5a6 6 0 0 1 12 0v5l1.5 2h-15z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/>
                                <path d="M10 20.5a2 2 0 0 0 4 0" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div class="workflow-step-body">
                            <h3 class="workflow-step-title"><?= e(t('landing.s3_title')) ?></h3>
                            <p class="workflow-step-text"><?= e(t('landing.s3_text')) ?></p>
                        </div>
                    </li>
                </ol>
            </div>
        </section>

        <!-- ===================== Advanced Care ===================== -->
        <section class="care-section" id="advanced-care" aria-labelledby="care-title">
            <div class="container">
                <div class="care-content" data-reveal>
                    <p class="section-eyebrow"><?= e(t('landing.why_eyebrow')) ?></p>
                    <h2 class="section-title section-title--accent" id="care-title">
                        <?= e(t('landing.why_title_1')) ?><br><?= e(t('landing.why_title_2')) ?>
                    </h2>
                    <p class="care-tagline"><?= e(t('landing.why_tagline')) ?></p>
                    <ul class="care-list">
                        <li class="care-list-item">
                            <svg class="care-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <?= e(t('landing.c1')) ?>
                        </li>
                        <li class="care-list-item">
                            <svg class="care-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <?= e(t('landing.c2')) ?>
                        </li>
                        <li class="care-list-item">
                            <svg class="care-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <?= e(t('landing.c3')) ?>
                        </li>
                        <li class="care-list-item">
                            <svg class="care-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <?= e(t('landing.c4')) ?>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Temporary image cut from the design reference (same replacement rule as the hero). -->
            <div class="care-visual">
                <img src="assets/images/astronaut.jpg" width="664" height="305"
                     alt="<?= e(t('landing.care_alt')) ?>" loading="lazy">
            </div>
        </section>

        <!-- ===================== Testimonials ===================== -->
        <!-- Placeholder content: no real testimonials exist yet. Do not replace with invented people. -->
        <section class="testimonials-section" id="testimonials" aria-labelledby="testimonials-title">
            <div class="container">
                <div data-reveal>
                    <p class="section-eyebrow section-eyebrow--centered"><?= e(t('landing.testimonials')) ?></p>
                    <h2 class="testimonials-title" id="testimonials-title"><?= e(t('landing.testimonials_title')) ?></h2>
                </div>

                <div class="testimonial-track" data-reveal>
                    <figure class="testimonial-card is-placeholder">
                        <div class="testimonial-avatar" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="9" r="4" fill="currentColor"/><path d="M4.5 20.5c1-4 4-6 7.5-6s6.5 2 7.5 6" fill="currentColor"/></svg>
                        </div>
                        <div>
                            <blockquote class="testimonial-quote"><?= e(t('landing.testimonial_placeholder')) ?></blockquote>
                            <figcaption>
                                <span class="testimonial-name"><?= e(t('settings.name')) ?></span>
                                <span class="testimonial-role"><?= e(t('settings.role')) ?></span>
                            </figcaption>
                        </div>
                    </figure>
                    <figure class="testimonial-card is-placeholder">
                        <div class="testimonial-avatar" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="9" r="4" fill="currentColor"/><path d="M4.5 20.5c1-4 4-6 7.5-6s6.5 2 7.5 6" fill="currentColor"/></svg>
                        </div>
                        <div>
                            <blockquote class="testimonial-quote"><?= e(t('landing.testimonial_placeholder')) ?></blockquote>
                            <figcaption>
                                <span class="testimonial-name"><?= e(t('settings.name')) ?></span>
                                <span class="testimonial-role"><?= e(t('settings.role')) ?></span>
                            </figcaption>
                        </div>
                    </figure>
                    <figure class="testimonial-card is-placeholder">
                        <div class="testimonial-avatar" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="9" r="4" fill="currentColor"/><path d="M4.5 20.5c1-4 4-6 7.5-6s6.5 2 7.5 6" fill="currentColor"/></svg>
                        </div>
                        <div>
                            <blockquote class="testimonial-quote"><?= e(t('landing.testimonial_placeholder')) ?></blockquote>
                            <figcaption>
                                <span class="testimonial-name"><?= e(t('settings.name')) ?></span>
                                <span class="testimonial-role"><?= e(t('settings.role')) ?></span>
                            </figcaption>
                        </div>
                    </figure>
                </div>

                <div class="testimonial-dots">
                    <button class="testimonial-dot is-active" type="button" aria-label="<?= e(t('landing.show_testimonial', ['n' => 1])) ?>" aria-current="true"></button>
                    <button class="testimonial-dot" type="button" aria-label="<?= e(t('landing.show_testimonial', ['n' => 2])) ?>" aria-current="false"></button>
                    <button class="testimonial-dot" type="button" aria-label="<?= e(t('landing.show_testimonial', ['n' => 3])) ?>" aria-current="false"></button>
                </div>
            </div>
        </section>

        <!-- ===================== CTA banner ===================== -->
        <section class="cta-section" id="get-started" aria-labelledby="cta-title">
            <div class="container">
                <div class="cta-banner" data-reveal>
                    <svg class="cta-star" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M12 0C12.7 7.6 16.4 11.3 24 12C16.4 12.7 12.7 16.4 12 24C11.3 16.4 7.6 12.7 0 12C7.6 11.3 11.3 7.6 12 0Z" fill="currentColor"/>
                    </svg>
                    <div class="cta-text-group">
                        <h2 class="cta-title" id="cta-title"><?= e(t('landing.cta_title')) ?></h2>
                        <p class="cta-text"><?= e(t('landing.cta_text')) ?></p>
                    </div>
                    <a class="button button-light button-large" href="pages/signup.php">
                        <?= e(t('landing.sign_up')) ?>
                        <svg class="button-icon flip-rtl" viewBox="0 0 16 16" aria-hidden="true">
                            <path d="M3 8h10M9 4l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                </div>
            </div>
        </section>
    </main>

    <!-- ===================== Footer ===================== -->
    <footer class="site-footer">
        <div class="container">
            <div class="footer-main">
                <a class="brand-logo brand-logo--light" href="index.php">
                    <svg class="brand-logo-icon" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M12 0C12.7 7.6 16.4 11.3 24 12C16.4 12.7 12.7 16.4 12 24C11.3 16.4 7.6 12.7 0 12C7.6 11.3 11.3 7.6 12 0Z" fill="currentColor"/>
                    </svg>
                    <span class="brand-logo-text"><?= e(t('brand.name')) ?></span>
                </a>

                <nav aria-label="<?= e(t('landing.footer_nav')) ?>">
                    <ul class="footer-links">
                        <li><a class="footer-link" href="#overview"><?= e(t('landing.nav_home')) ?></a></li>
                        <li><a class="footer-link" href="#how-it-works"><?= e(t('landing.nav_analytics')) ?></a></li>
                        <li><a class="footer-link" href="#advanced-care"><?= e(t('landing.nav_about')) ?></a></li>
                        <li><a class="footer-link" href="#get-started"><?= e(t('landing.nav_support')) ?></a></li>
                    </ul>
                </nav>

                <!-- Replace each # with the real profile URL once the accounts exist. -->
                <ul class="footer-social">
                    <li>
                        <a class="footer-social-link" href="#" aria-label="<?= e(t('landing.social_x')) ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 4h4L19.5 20h-4zM19 4l-6.2 7M11.2 13L5 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </a>
                    </li>
                    <li>
                        <a class="footer-social-link" href="#" aria-label="<?= e(t('landing.social_linkedin')) ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="3.5" width="17" height="17" rx="3" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M8 10.5V16M8 7.75v.1M11.5 16v-5.5M11.5 13c0-1.6 1-2.6 2.3-2.6S16 11.3 16 13v3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                        </a>
                    </li>
                    <li>
                        <a class="footer-social-link" href="#" aria-label="<?= e(t('landing.social_youtube')) ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 8.5c0-1.8 1.2-3 3-3.2 2-.2 4-.3 6-.3s4 .1 6 .3c1.8.2 3 1.4 3 3.2v7c0 1.8-1.2 3-3 3.2-2 .2-4 .3-6 .3s-4-.1-6-.3c-1.8-.2-3-1.4-3-3.2z" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M10 9.3v5.4l4.5-2.7z" fill="currentColor"/></svg>
                        </a>
                    </li>
                    <li>
                        <a class="footer-social-link" href="#" aria-label="<?= e(t('landing.social_web')) ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M12 3.5c2.3 2.4 3.5 5.2 3.5 8.5s-1.2 6.1-3.5 8.5c-2.3-2.4-3.5-5.2-3.5-8.5s1.2-6.1 3.5-8.5zM3.5 12h17" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
                        </a>
                    </li>
                </ul>
            </div>

            <p class="footer-bottom">&copy; <?php echo date('Y'); ?> OrbitCare. <?= e(t('landing.rights')) ?></p>
        </div>
    </footer>

    <!-- ===================== Welcome modal (opened by Get Started) ===================== -->
    <dialog class="modal-overlay welcome-modal" id="welcome-modal" aria-labelledby="welcome-modal-title" aria-describedby="welcome-modal-text">
        <div class="modal-content">
            <button class="modal-close" type="button" aria-label="<?= e(t('common.close')) ?>">
                <svg viewBox="0 0 16 16" aria-hidden="true">
                    <path d="M4 4l8 8M12 4l-8 8" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
            </button>

            <svg class="modal-logo" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M12 0C12.7 7.6 16.4 11.3 24 12C16.4 12.7 12.7 16.4 12 24C11.3 16.4 7.6 12.7 0 12C7.6 11.3 11.3 7.6 12 0Z" fill="currentColor"/>
            </svg>
            <h2 class="modal-title" id="welcome-modal-title"><?= e(t('landing.welcome_title')) ?></h2>
            <p class="modal-text" id="welcome-modal-text"><?= e(t('landing.welcome_text')) ?></p>

            <div class="modal-actions">
                <button class="button button-outline button-large" type="button" data-open-login aria-haspopup="dialog">
                    <?= e(t('login.submit')) ?>
                    <svg class="button-icon flip-rtl" viewBox="0 0 16 16" aria-hidden="true">
                        <path d="M3 8h10M9 4l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <a class="button button-dark button-large" href="pages/signup.php">
                    <?= e(t('landing.sign_up')) ?>
                    <svg class="button-icon flip-rtl" viewBox="0 0 16 16" aria-hidden="true">
                        <path d="M3 8h10M9 4l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
            </div>
        </div>
    </dialog>

<?php require __DIR__ . '/frontend/components/login-modal.php'; ?>
</body>
</html>
