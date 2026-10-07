<?php
// Minimal .env loader (no composer dependency). Reads KEY=VALUE lines from
// php_backend/.private/.env into $_ENV. Never commit or print the file.
function loadMailEnv() {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;
    $file = __DIR__ . '/.private/.env';
    if (!is_readable($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        if ($key !== '' && !array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $val;
        }
    }
}

function mailConfig() {
    loadMailEnv();
    // App Passwords are shown with spaces (xxxx xxxx xxxx xxxx) - Gmail
    // wants them spaceless, so strip all whitespace here.
    $pw = preg_replace('/\s+/', '', $_ENV['MAIL_PASSWORD'] ?? '');
    return [
        'username' => $_ENV['MAIL_USERNAME'] ?? '',
        'password' => $pw,
        'from_name' => $_ENV['MAIL_FROM_NAME'] ?? 'EcoAgri',
    ];
}
?>
