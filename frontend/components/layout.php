<?php
// App shell for signed-in pages: <head>, sidebar, header. Pages call
// render_app_start(...) then print their content, then render_app_end().
declare(strict_types=1);

function app_icon(string $name): string
{
    $paths = [
        'dashboard' => '<path d="M3.5 11.5L12 4l8.5 7.5"/><path d="M5.5 10v9.5h4.5v-5.5h4v5.5h4.5V10"/>',
        'assistant' => '<path d="M5 5.5h14a1.5 1.5 0 0 1 1.5 1.5v8.5A1.5 1.5 0 0 1 19 17H11l-4.5 3.5V17H5a1.5 1.5 0 0 1-1.5-1.5V7A1.5 1.5 0 0 1 5 5.5z"/><path d="M8.5 11.25h.01M12 11.25h.01M15.5 11.25h.01" stroke-width="2.2"/>',
        'analytics' => '<path d="M4 20h16M6.5 20v-6M10.5 20V9M14.5 20v-8M18.5 20V5"/>',
        'mission' => '<circle cx="9" cy="8" r="3"/><path d="M3.5 19c.6-3.2 2.8-5 5.5-5s4.9 1.8 5.5 5"/><circle cx="16.5" cy="9" r="2.4"/><path d="M15.5 14.2c2.6-.3 4.6 1.3 5 4.3"/>',
        'alerts' => '<path d="M6 16v-5a6 6 0 0 1 12 0v5l1.5 2h-15z"/><path d="M10 20.5a2 2 0 0 0 4 0"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v2.2M12 19.3v2.2M4.6 4.6l1.6 1.6M17.8 17.8l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.6 19.4l1.6-1.6M17.8 6.2l1.6-1.6"/>',
        'profile' => '<circle cx="12" cy="8" r="4"/><path d="M4.5 20.5c.8-4 3.8-6.5 7.5-6.5s6.7 2.5 7.5 6.5"/>',
        'checkin' => '<rect x="5" y="4.5" width="14" height="16" rx="2.5"/><path d="M9 4.5V3.5h6v1M8.5 12.5l2.5 2.5 4.5-5"/>',
        'alertness' => '<circle cx="12" cy="12" r="7.5"/><circle cx="12" cy="12" r="2.5"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3"/>',
        'vest' => '<path d="M9 3.5L5 6v14.5h14V6l-4-2.5"/><path d="M9 3.5c.5 2 1.5 3 3 3s2.5-1 3-3M12 6.5v14"/><path d="M8 11.5h1.5M14.5 11.5H16"/>',
        'sleep' => '<path d="M19.5 14.5A8 8 0 0 1 9.5 4.5a8 8 0 1 0 10 10z"/><path d="M15 4.5h3.5L15 8.5h3.5"/>',
        'rocket' =>'<path d="M14.5 4.5c2.5-1 4.5-1 5-.5s.5 2.5-.5 5l-6 6-4-4z"/><path d="M9 11l-3.5.5L4 14l3.5.5M13 15l-.5 3.5L10 20l-.5-3.5M6.5 17.5l-2 2"/><circle cx="15.5" cy="8.5" r="1.3"/>',
        'logout' => '<path d="M14 4.5H6.5A1.5 1.5 0 0 0 5 6v12a1.5 1.5 0 0 0 1.5 1.5H14"/><path d="M10.5 12H20M16.5 8.5L20 12l-3.5 3.5"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
    ];
    return '<svg class="app-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
        . ($paths[$name] ?? '') . '</svg>';
}

function render_app_start(array $user, string $active, string $title, array $options = []): void
{
    $isAdmin = $user['role'] === 'mission_admin';
    $nav = $isAdmin
        ? [
            ['admin', t('nav.mission_dashboard'), 'pages/admin.php', 'dashboard'],
            ['missions', t('nav.my_missions'), 'pages/missions.php', 'rocket'],
            ['mission', t('nav.mission'), 'pages/mission.php', 'mission'],
            ['alerts', t('nav.alerts'), 'pages/alerts.php', 'alerts'],
            ['settings', t('nav.settings'), 'pages/settings.php', 'settings'],
            ['profile', t('nav.profile'), 'pages/profile.php', 'profile'],
        ]
        : [
            ['dashboard', t('nav.dashboard'), 'pages/dashboard.php', 'dashboard'],
            // 3D vest + headband: shows this astronaut's recorded signals on the sensor that would measure them.
            ['vest', t('nav.vest'), 'prototypes/vest-3d/index.html?lang=' . current_language(), 'vest'],
            ['checkin', t('nav.checkin'), 'pages/checkin.php', 'checkin'],
            ['alertness', t('nav.alertness'), 'pages/alertness.php', 'alertness'],
            ['sleep', t('nav.sleep'), 'prototypes/sleep-sim/index.html?lang=' . current_language(), 'sleep'],
            ['assistant', t('nav.assistant'), 'pages/assistant.php', 'assistant'],
            ['analytics', t('nav.analytics'), 'pages/analytics.php', 'analytics'],
            ['mission', t('nav.mission'), 'pages/mission.php', 'mission'],
            ['alerts', t('nav.alerts'), 'pages/alerts.php', 'alerts'],
            ['settings', t('nav.settings'), 'pages/settings.php', 'settings'],
            ['profile', t('nav.profile'), 'pages/profile.php', 'profile'],
        ];

    $missions = (new MissionService())->missionsFor($user);
    $currentMission = $missions[0] ?? null;
    $scripts = $options['scripts'] ?? [];
    $greeting = $options['greeting'] ?? false;
    ?>
<!DOCTYPE html>
<html <?= html_language_attributes() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> — OrbitCare</title>
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="app-base" content="<?= e(BASE_URL) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
    <?= i18n_script_tag() ?>
    <script src="<?= e(url('assets/js/i18n.js')) ?>"></script>
    <script type="module" src="<?= e(url('assets/js/app/shell.js')) ?>"></script>
<?php foreach ($scripts as $script): ?>
    <script type="module" src="<?= e(url('assets/js/app/' . $script)) ?>"></script>
<?php endforeach; ?>
</head>
<body class="app-page" data-role="<?= e($user['role']) ?>" data-user-id="<?= (int) $user['id'] ?>">
    <a class="skip-link" href="#app-content"><?= e(t('shell.skip')) ?></a>

    <div class="app-shell">
        <aside class="app-sidebar" id="app-sidebar">
            <a class="sidebar-brand" href="<?= e(home_path_for($user)) ?>">
                <svg class="sidebar-brand-star" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 0C12.7 7.6 16.4 11.3 24 12C16.4 12.7 12.7 16.4 12 24C11.3 16.4 7.6 12.7 0 12C7.6 11.3 11.3 7.6 12 0Z" fill="currentColor"/></svg>
                <span class="sidebar-brand-name">OrbitCare</span>
                <span class="sidebar-brand-tagline"><?= e(t('shell.tagline')) ?></span>
            </a>

            <nav class="sidebar-nav" aria-label="<?= e(t('shell.main_nav')) ?>">
                <?php foreach ($nav as [$key, $label, $href, $icon]): ?>
                    <a class="sidebar-link<?= $key === $active ? ' is-active' : '' ?>" href="<?= e(url($href)) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>>
                        <?= app_icon($icon) ?>
                        <span><?= e($label) ?></span>
                        <?php if ($key === 'alerts'): ?><span class="sidebar-badge" data-alert-badge hidden></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <a class="sidebar-mission" href="<?= e(url('pages/mission.php')) ?>">
                <?= app_icon('rocket') ?>
                <span>
                    <span class="sidebar-mission-label"><?= e(t('shell.mission')) ?></span>
                    <span class="sidebar-mission-name" dir="auto"><?= e($currentMission['name'] ?? ($isAdmin ? t('shell.awaiting_approval') : t('shell.no_mission'))) ?></span>
                </span>
                <svg class="sidebar-mission-chevron flip-rtl" viewBox="0 0 16 16" aria-hidden="true"><path d="M6 3.5L10.5 8 6 12.5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            </a>
        </aside>

        <div class="app-main">
            <header class="app-header">
                <button class="app-menu-button" type="button" aria-label="<?= e(t('shell.open_nav')) ?>" aria-controls="app-sidebar" aria-expanded="false"><?= app_icon('menu') ?></button>

                <div class="app-heading">
                    <?php if (!empty($options['heading_in_page'])): ?>
                        <?php /* The page renders its own <h1> inside its main card. */ ?>
                    <?php elseif ($greeting): ?>
                        <p class="app-greeting" data-greeting><?= e(t('shell.hello')) ?></p>
                        <h1 class="app-title app-title--large"><?= e($options['heading'] ?? $user['full_name']) ?></h1>
                    <?php else: ?>
                        <h1 class="app-title"><?= e($options['heading'] ?? $title) ?></h1>
                        <?php if (!empty($options['subheading'])): ?><p class="app-subtitle"><?= e($options['subheading']) ?></p><?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="app-header-tools">
                    <span class="connection-status" data-connection-status><span class="status-dot"></span><span data-connection-label><?= e(t('shell.connecting')) ?></span></span>
                    <span class="header-divider" aria-hidden="true"></span>
                    <span class="last-update"><?= e(t('shell.last_update')) ?> <time data-last-update>—</time></span>
                    <form class="logout-form" action="<?= e(url('api/auth/logout')) ?>" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <button class="icon-button" type="submit" aria-label="<?= e(t('shell.logout')) ?>" title="<?= e(t('shell.logout')) ?>"><?= app_icon('logout') ?></button>
                    </form>
                </div>
            </header>

            <main class="app-content" id="app-content" tabindex="-1">
    <?php
}

function render_app_end(): void
{
    ?>
            </main>
        </div>
    </div>
</body>
</html>
    <?php
}
