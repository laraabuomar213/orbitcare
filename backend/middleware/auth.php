<?php
// Sessions, the signed-in user, and access guards for pages and the API.
// Roles are always read from the database, never from anything the browser sends.
declare(strict_types=1);

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) (config()['session_idle_minutes'] * 60));
    session_name('orbitcare_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => BASE_URL . '/',
        'secure'   => $isHttps,
        'httponly' => true,     // JavaScript can't read the session cookie
        'samesite' => 'Lax',
    ]);
    session_start();

    // Idle timeout: a signed-in session that has been unused for too long ends,
    // and we remember why so the user sees "session expired" instead of a vague error.
    $now = time();
    if (!empty($_SESSION['user_id']) && $now - ($_SESSION['last_activity'] ?? $now) > config()['session_idle_minutes'] * 60) {
        $_SESSION = ['session_expired' => true];
        session_regenerate_id(true);
    }
    $_SESSION['last_activity'] = $now;
}

// A fresh session ID on sign-in prevents session fixation attacks.
function log_in_user(int $userId): void
{
    session_regenerate_id(true);
    unset($_SESSION['session_expired']);
    $_SESSION['user_id'] = $userId;
    $_SESSION['last_activity'] = time();
    current_user(true);
}

function log_out_user(): void
{
    $_SESSION = [];
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 3600,
        'path'     => $cookie['path'],
        'secure'   => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => $cookie['samesite'],
    ]);
    session_destroy();
}

function current_user(bool $refresh = false): ?array
{
    static $user = null;
    static $loaded = false;

    if ($loaded && !$refresh) {
        return $user;
    }
    $loaded = true;
    $user = null;

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $statement = db()->prepare(
        'SELECT u.id, u.full_name, u.email, u.role, u.admin_approved_at, u.profile_completed_at,
                a.astronaut_code, a.data_source_record_id
         FROM users u
         LEFT JOIN astronauts a ON a.user_id = u.id
         WHERE u.id = ?'
    );
    $statement->execute([$_SESSION['user_id']]);
    $user = $statement->fetch() ?: null;

    if ($user === null) {
        unset($_SESSION['user_id']);   // account no longer exists
    }
    return $user;
}

// Mission admin rights need BOTH the role and an approval by an existing administrator.
function is_approved_mission_admin(array $user): bool
{
    return $user['role'] === 'mission_admin' && $user['admin_approved_at'] !== null;
}

// Where a signed-in user belongs right now.
function home_path_for(array $user): string
{
    if ($user['profile_completed_at'] === null) {
        return url('pages/signup.php');
    }
    return $user['role'] === 'mission_admin' ? url('pages/admin.php') : url('pages/dashboard.php');
}


// ---------- Page guards (HTML pages) ----------

// Signed-in pages must never be served from the browser cache, so "Back" after
// logging out can't show them again.
function send_no_cache_headers(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function require_page_user(): array
{
    send_no_cache_headers();
    $user = current_user();
    if ($user === null) {
        redirect(url('index.php?login=' . (!empty($_SESSION['session_expired']) ? 'expired' : 'required')));
    }
    if ($user['profile_completed_at'] === null) {
        redirect(url('pages/signup.php'));
    }
    return $user;
}

// Pages for one role. Anyone else gets a real 403 page, not a silent redirect.
function require_page_role(string $role): array
{
    $user = require_page_user();
    $allowed = $role === 'mission_admin' ? is_approved_mission_admin($user) : $user['role'] === $role;
    if (!$allowed) {
        render_forbidden_page($user);
    }
    return $user;
}

function render_forbidden_page(array $user): never
{
    http_response_code(403);
    $homeUrl = home_path_for($user);
    require dirname(__DIR__, 2) . '/frontend/components/forbidden.php';
    exit;
}


// ---------- API guards ----------

function api_require_user(?array $roles = null): array
{
    $user = current_user();

    if ($user === null) {
        if (!empty($_SESSION['session_expired'])) {
            throw new ApiException(401, 'session_expired', t('err.session_expired'));
        }
        throw new ApiException(401, 'not_authenticated', t('err.not_authenticated'));
    }

    if ($roles === null) {
        return $user;
    }
    if ($user['profile_completed_at'] === null) {
        throw new ApiException(403, 'profile_incomplete', t('err.profile_incomplete'));
    }

    foreach ($roles as $role) {
        if ($role === 'astronaut' && $user['role'] === 'astronaut') {
            return $user;
        }
        if ($role === 'mission_admin' && is_approved_mission_admin($user)) {
            return $user;
        }
    }
    throw ApiException::forbidden();
}
