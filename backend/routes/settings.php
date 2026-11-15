<?php
// /api/settings — simple per-user preferences (notifications, display, language, privacy).
declare(strict_types=1);

const DEFAULT_SETTINGS = [
    'notifications' => ['in_app_alerts' => true, 'alert_sound' => false],
    'display' => ['reduced_motion' => false, 'language' => 'en'],
    // Daily check-in answers stay private unless the astronaut chooses to share them.
    'privacy' => ['share_checkins_with_mission' => false],
];

return function (Router $router): void {
    // Any signed-in user (including admins still awaiting approval) has preferences.
    $router->get('/settings', function (array $params, array $body, array $user): array {
        return load_user_settings((int) $user['id']);
    });

    $router->put('/settings', function (array $params, array $body, array $user): array {
        $settings = load_user_settings((int) $user['id']);
        // Only known settings are accepted; anything else is ignored.
        foreach (DEFAULT_SETTINGS as $group => $values) {
            foreach ($values as $key => $default) {
                if (!is_bool($default) || !isset($body[$group][$key])) {
                    continue;
                }
                $settings[$group][$key] = filter_var($body[$group][$key], FILTER_VALIDATE_BOOLEAN);
            }
        }
        // The interface language also switches this device right away (cookie).
        if (isset($body['display']['language'])) {
            $language = $body['display']['language'];
            if (!is_string($language) || !isset(SUPPORTED_LANGUAGES[$language])) {
                throw ApiException::validation(['language' => t('err.unknown_language')]);
            }
            $settings['display']['language'] = $language;
            set_language($language);
        }
        db()->prepare('INSERT INTO user_settings (user_id, settings) VALUES (?, ?) ON DUPLICATE KEY UPDATE settings = VALUES(settings)')
            ->execute([$user['id'], json_encode($settings)]);
        audit('settings.update', (int) $user['id'], 'user_settings', $user['id']);
        return $settings;
    });
};

function load_user_settings(int $userId): array
{
    $statement = db()->prepare('SELECT settings FROM user_settings WHERE user_id = ?');
    $statement->execute([$userId]);
    $stored = json_decode((string) $statement->fetchColumn(), true) ?: [];
    return array_replace_recursive(DEFAULT_SETTINGS, $stored);
}
