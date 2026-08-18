<?php
session_start();

// Clear all session data.
$_SESSION = [];

// Remove the session cookie itself so the browser doesn't try to reuse it.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

// Destroy the session data on the server.
session_destroy();

header("Location: login.php");
exit();