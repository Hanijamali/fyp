<?php
/**
 * Per-role PHP sessions so tutor + student can stay logged in together (separate tabs).
 * Each role uses its own cookie: TUTORFIND_SESS_tutor, TUTORFIND_SESS_student, etc.
 *
 * Call tf_session_start_role('tutor') on tutor pages, or tf_session_start_any([...]) on shared pages.
 */
if (!defined('TF_SESSION_LIFETIME')) {
    define('TF_SESSION_LIFETIME', 60 * 60 * 24 * 30);
}

function tf_session_roles(): array
{
    return ['student', 'parent', 'tutor', 'admin'];
}

function tf_session_cookie_path(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    $path = '/';
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $appRoot = realpath(dirname(__DIR__)) ?: '';
    if ($docRoot !== '' && $appRoot !== '' && strpos($appRoot, $docRoot) === 0) {
        $base = str_replace('\\', '/', substr($appRoot, strlen($docRoot)));
        if ($base !== '' && $base !== '/') {
            $path = rtrim($base, '/') . '/';
        }
    }
    return $path;
}

function tf_session_save_dir(): string
{
    $sessionDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0700, true);
    }
    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        session_save_path($sessionDir);
    }
    return $sessionDir;
}

function tf_session_name_for_role(string $role): string
{
    $role = strtolower(trim($role));
    if (!in_array($role, tf_session_roles(), true)) {
        $role = 'student';
    }
    return 'TUTORFIND_SESS_' . $role;
}

function tf_session_apply_ini(): void
{
    $lifetime = TF_SESSION_LIFETIME;
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $path = tf_session_cookie_path();

    tf_session_save_dir();

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => $path,
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.gc_maxlifetime', (string) $lifetime);
    ini_set('session.cookie_lifetime', (string) $lifetime);
    ini_set('session.use_strict_mode', '1');
}

function tf_session_refresh_cookie(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $lifetime = TF_SESSION_LIFETIME;
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie(session_name(), session_id(), [
        'expires' => time() + $lifetime,
        'path' => tf_session_cookie_path(),
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function tf_session_close_if_active(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

/** Start (or resume) the session for one role only. */
function tf_session_start_role(string $role): void
{
    tf_session_close_if_active();
    tf_session_apply_ini();
    session_name(tf_session_name_for_role($role));
    session_start();
    tf_session_refresh_cookie();
}

/** Prefer role that matches the page the user came from (two accounts open in different tabs). */
function tf_session_preferred_role(array $roles): ?string
{
    $ref = strtolower($_SERVER['HTTP_REFERER'] ?? '');
    $hints = [
        'tutor-dashboard' => 'tutor',
        'student-dashboard' => 'student',
        'parent-dashboard' => 'parent',
        'admin-dashboard' => 'admin',
        'quiz-take' => 'student',
        'quiz-tutor-review' => 'tutor',
        'payment' => 'student',
    ];
    foreach ($hints as $needle => $role) {
        if (strpos($ref, $needle) !== false && in_array($role, $roles, true)) {
            return $role;
        }
    }
    return null;
}

/** Role from ?as=student|parent|… (set by dashboard links). */
function tf_session_forced_role(array $allowed): ?string
{
    $as = strtolower(trim((string) ($_GET['as'] ?? '')));
    if ($as !== '' && in_array($as, $allowed, true)) {
        return $as;
    }
    return null;
}

/** Build a URL path with query string, keeping ?as= when present or from session. */
function tf_build_url(string $path, array $params = []): string
{
    $as = strtolower(trim((string) ($_GET['as'] ?? '')));
    if ($as === '' && session_status() === PHP_SESSION_ACTIVE) {
        $as = strtolower((string) ($_SESSION['role'] ?? ''));
    }
    if ($as !== '' && !isset($params['as']) && in_array($as, tf_session_roles(), true)) {
        $params['as'] = $as;
    }
    $q = http_build_query($params);
    return $path . ($q !== '' ? '?' . $q : '');
}

/** Append ?as=role or &as=role for links (uses ?as= from URL or active session). */
function tf_session_url_as(string $joiner = '?'): string
{
    $as = strtolower(trim((string) ($_GET['as'] ?? '')));
    if ($as === '' && session_status() === PHP_SESSION_ACTIVE) {
        $as = strtolower((string) ($_SESSION['role'] ?? ''));
    }
    if ($as === '' || !in_array($as, tf_session_roles(), true)) {
        return '';
    }
    return $joiner . 'as=' . rawurlencode($as);
}

/**
 * Try roles in order; resume the first that is logged in.
 * If ?as= is set, only that role is used (no switching to another account).
 */
function tf_session_start_any(array $roles): bool
{
    $roles = array_values(array_filter($roles, fn($r) => in_array($r, tf_session_roles(), true)));
    if (empty($roles)) {
        $roles = ['student'];
    }

    $forced = tf_session_forced_role($roles);
    if ($forced !== null) {
        tf_session_start_role($forced);
        if (!empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === $forced) {
            return true;
        }
        return false;
    }

    $preferred = tf_session_preferred_role($roles);
    if ($preferred !== null) {
        $roles = array_values(array_unique(array_merge([$preferred], $roles)));
    }

    foreach ($roles as $role) {
        tf_session_close_if_active();
        tf_session_apply_ini();
        session_name(tf_session_name_for_role($role));
        session_start();
        if (!empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === $role) {
            tf_session_refresh_cookie();
            return true;
        }
        session_write_close();
    }

    tf_session_start_role($roles[0]);
    return false;
}

/** End one role's session and clear its cookie (other roles stay logged in). */
function tf_session_destroy_role(string $role): void
{
    if (!in_array($role, tf_session_roles(), true)) {
        return;
    }
    tf_session_start_role($role);
    $params = session_get_cookie_params();
    $name = session_name();
    session_unset();
    session_destroy();
    setcookie($name, '', [
        'expires' => time() - 3600,
        'path' => $params['path'] ?? tf_session_cookie_path(),
        'domain' => $params['domain'] ?? '',
        'secure' => $params['secure'] ?? false,
        'httponly' => $params['httponly'] ?? true,
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
    tf_session_close_if_active();
}

/** Log out every role (all cookies). */
function tf_session_destroy_all(): void
{
    foreach (tf_session_roles() as $role) {
        tf_session_destroy_role($role);
    }
}
