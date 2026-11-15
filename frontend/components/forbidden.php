<?php
// 403 page for signed-in users who open a page their role may not use.
// Expects $homeUrl. Sent with HTTP status 403 by render_forbidden_page().
?>
<!DOCTYPE html>
<html <?= html_language_attributes() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(t('forbidden.title')) ?> — OrbitCare</title>
    <link rel="stylesheet" href="<?= e(url('assets/css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="app-page app-page--centered">
    <main class="card notice-card">
        <p class="eyebrow"><?= e(t('forbidden.eyebrow')) ?></p>
        <h1 class="notice-title"><?= e(t('forbidden.heading')) ?></h1>
        <p class="muted"><?= e(t('forbidden.text')) ?></p>
        <a class="btn btn-primary" href="<?= e($homeUrl) ?>"><?= e(t('forbidden.home')) ?></a>
    </main>
</body>
</html>
