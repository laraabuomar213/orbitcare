<?php
// Loaded first by every page, API request and CLI script.
declare(strict_types=1);

require __DIR__ . '/core/env.php';
require __DIR__ . '/core/helpers.php';
require __DIR__ . '/core/i18n.php';
require __DIR__ . '/core/logger.php';
require __DIR__ . '/core/database.php';
require __DIR__ . '/core/audit.php';
require __DIR__ . '/core/http.php';
require __DIR__ . '/core/router.php';
require __DIR__ . '/middleware/auth.php';
require __DIR__ . '/middleware/security.php';
require __DIR__ . '/middleware/access.php';

// Service classes (backend/services/**) load automatically when first used.
spl_autoload_register(function (string $class): void {
    foreach (['', 'ai/', 'ai/agents/', 'ai/providers/'] as $folder) {
        $file = __DIR__ . '/services/' . $folder . $class . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});

define('BASE_URL', rtrim(config()['base_url'], '/'));

date_default_timezone_set('UTC');
error_reporting(E_ALL);
ini_set('display_errors', config()['debug'] ? '1' : '0');
ini_set('log_errors', '1');

if (PHP_SAPI !== 'cli') {
    start_secure_session();
    // Language switch on public pages: any page link with ?lang=ar|en remembers the choice.
    if (is_string($_GET['lang'] ?? null)) {
        set_language($_GET['lang']);
    }
}
