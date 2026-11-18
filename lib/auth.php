<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off';
    session_name('tidewatch');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
    // idle timeout: 8 hours
    if (isset($_SESSION['seen']) && time() - $_SESSION['seen'] > 8 * 3600) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['seen'] = time();
}

function is_admin(): bool
{
    session_boot();
    return !empty($_SESSION['admin']);
}

function require_admin(): void
{
    if (!is_admin()) {
        header('Location: login.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? ''));
        exit;
    }
}

/** Throttle by client IP: after 5 failures, lock for 5 minutes. */
function login_attempt(string $password): bool
{
    session_boot();
    $key = 'fail_' . substr(hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '-'), 0, 16);
    [$n, $ts] = array_map('intval', explode('|', (string)(db_val('SELECT v FROM meta WHERE k=?', [$key]) ?: '0|0')));
    if ($n >= 5 && time() - $ts < 300) {
        return false;
    }
    $hash = (string)config('admin_hash');
    if ($hash !== '' && password_verify($password, $hash)) {
        db_exec('DELETE FROM meta WHERE k=?', [$key]);
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return true;
    }
    usleep(500000);
    db_upsert('meta', ['k' => $key, 'v' => ($n >= 5 ? 1 : $n + 1) . '|' . time()], ['k']);
    log_event('warn', 'Failed admin login from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    return false;
}

function logout(): void
{
    session_boot();
    $_SESSION = [];
    session_regenerate_id(true);
}

function csrf_token(): string
{
    session_boot();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

/** Validate CSRF on every POST (form field or X-CSRF header for fetch()). */
function csrf_check(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    $sent = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
}
