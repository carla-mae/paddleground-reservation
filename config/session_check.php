<?php
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
/**
 * session_check.php
 *
 * Include this file at the very top of every page that requires a logged-in
 * user (before any HTML/output). It:
 *   - Starts the session securely
 *   - Redirects to login if the user is not authenticated
 *   - Automatically logs the user out after a period of inactivity
 *   - Refreshes the "last activity" timestamp on every valid request
 *
 * Usage (from a page one folder below the project root, e.g. customer/dashboard.php):
 *     require_once '../config/session_check.php';
 *
 * Optional: restrict a page to specific roles:
 *     require_once '../config/session_check.php';
 *     require_role(['staff', 'admin']); // customer will be redirected away
 */

// How long (in seconds) a session can sit idle before it's forced to expire.
// 1800 = 30 minutes. Adjust to taste.
define('SESSION_INACTIVITY_LIMIT', 1800);

// Only start the session if one isn't already active (avoids notices if this
// file is ever included twice, or after session_start() already ran).
if (session_status() === PHP_SESSION_NONE) {

    // Make PHP's server-side session storage last as long as the cookie
    // itself. Without this, PHP's default gc_maxlifetime (often 1440s /
    // 24 min on XAMPP) silently deletes the session data on the server
    // long before the cookie expires — so the browser still has a valid
    // cookie, but session_start() finds nothing behind it and $_SESSION
    // comes back empty, which looks exactly like being logged out.
    ini_set('session.gc_maxlifetime', 86400);

    // Harden the session cookie before starting the session.
    // NOTE: these params MUST match what login.php uses when it starts the
    // session — cookie attributes are locked in the moment the cookie is
    // first issued, so a mismatch between login.php and this file can cause
    // the session to appear to "not stick" across pages.
    session_set_cookie_params([
        'lifetime' => 86400,    // 1 day. (Was 0 = "expires when browser closes",
                                 // but some mobile browsers/OSes treat backgrounding
                                 // or killing the app as "closing the browser,"
                                 // wiping the session unexpectedly.)
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,     // JS can't read the cookie
        'samesite' => 'Lax',
    ]);

    session_start();
}

// Path back to the login page, relative to pages one level below project root
// (customer/, staff/, admin/, etc.), matching the existing folder layout.
const LOGIN_REDIRECT_PATH = '../auth/login.php';

/**
 * Send the user to login and stop execution.
 */
function redirect_to_login($reason = '') {
    $suffix = $reason ? ('?' . $reason) : '';
    header('Location: ' . LOGIN_REDIRECT_PATH . $suffix);
    exit();
}

// --- 1. Not logged in at all ---
if (empty($_SESSION['user_id'])) {
    redirect_to_login();
}

// --- 2. Logged in, but idle too long ---
if (!empty($_SESSION['last_activity']) &&
    (time() - $_SESSION['last_activity']) > SESSION_INACTIVITY_LIMIT) {

    // Clear session data and destroy the session (expired due to inactivity).
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }

    session_destroy();
    redirect_to_login('timeout=1');
}

// --- 3. Still valid: refresh the activity timestamp ---
$_SESSION['last_activity'] = time();

/**
 * Optional helper: restrict the current page to specific roles.
 * Call this AFTER requiring session_check.php on any page that needs
 * role-based restriction (e.g. admin-only pages).
 *
 * @param array $allowedRoles e.g. ['admin'] or ['staff', 'admin']
 */
function require_role(array $allowedRoles) {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowedRoles, true)) {
        // Logged in, but not permitted here.
        header('Location: ' . LOGIN_REDIRECT_PATH . '?denied=1');
        exit();
    }
}