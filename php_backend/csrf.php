<?php
// CSRF protection + session cookie hardening. Single source: session.php and
// sessionless endpoints (addUser, editUsers, password_reset, login) all load
// this instead of calling session_start() directly.
if (session_status() == PHP_SESSION_NONE) {
    $csrfSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $csrfSecure,
    ]);
    session_start();
}

// Token for the current session (created lazily, rotated on login).
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Die 403 unless the request carries this session's token. Empty never matches.
function csrf_check() {
    $sent = (string)($_POST['csrf_token'] ?? '');
    $want = (string)($_SESSION['csrf_token'] ?? '');
    if ($sent === '' || $want === '' || !hash_equals($want, $sent)) {
        http_response_code(403);
        die("Access denied: invalid request token. <a href='../login.php'>Continue</a>");
    }
}

// Hidden input for plain HTML forms.
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}
?>
