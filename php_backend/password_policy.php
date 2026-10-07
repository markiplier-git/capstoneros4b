<?php
// Shared password standard: minimum 8 characters, at least 1 letter and
// 1 number, plus a short common-password denylist. Returns '' when the
// password passes, otherwise the user-facing error message.
// Used by addUser, editUsers and password_reset - change the rule here once.
function passwordStandardError($pw) {
    if (strlen($pw) < 8 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/[0-9]/', $pw)) {
        return "Password must be at least 8 characters with at least 1 letter and 1 number";
    }
    $common = ['password', 'password1', 'password123', 'admin123', '12345678', '123456789', 'qwerty', 'qwerty123', 'letmein', 'welcome', 'welcome123', 'abc123', '1q2w3e4r', 'vermicast', 'ecoagri'];
    if (in_array(strtolower($pw), $common, true)) {
        return "That password is too common. Choose a different one";
    }
    return '';
}
?>
