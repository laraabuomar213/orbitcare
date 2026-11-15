<?php
// Single entry point for /api/* (Apache rewrites /api/<path> to this file, see .htaccess).
declare(strict_types=1);

require __DIR__ . '/../backend/bootstrap.php';

$router = new Router();
foreach (['auth', 'health', 'alerts', 'missions', 'ai', 'checkins', 'alertness', 'emergencies', 'devices', 'settings'] as $routeFile) {
    (require __DIR__ . "/../backend/routes/{$routeFile}.php")($router);
}

$router->dispatch($_SERVER['REQUEST_METHOD'], '/' . trim((string) ($_GET['route'] ?? ''), '/'));
