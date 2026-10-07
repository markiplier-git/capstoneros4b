<?php
// Self-service password reset (logged-out users). JSON in/out, no session
// gate: session.php would bounce logged-out callers, so this boots only
// db.php. All anti-spam lives here: per-user cooldown, hourly cap, hashed
// single-use 10-minute codes bound to the requester IP.
require_once "db.php";
require_once "csrf.php";
require_once "mailer.php";

header('Content-Type: application/json');

$op = $_POST['op'] ?? '';
if (!in_array($op, ['request', 'verify', 'lastresort'], true)) {
    fail("Invalid request.");
}
csrf_check();

$ip = $_SERVER['REMOTE_ADDR'] ?? '';

if ($op === 'lastresort') {
    $acct = adminTarget($pdo);
    lastResort($pdo, $acct['user'], $ip);
}
if ($op === 'request') {
    $acct = adminTarget($pdo);
    requestCode($pdo, $acct, $ip);
}
// $op === 'verify'
$acct = adminTarget($pdo);
verifyCode($pdo, $acct, $ip);
exit;

function fail($msg) {
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// Forgot password is admin-only, so no username is asked: the single admin
// row is the target. Fails when there is no active admin.
function adminTarget($pdo) {
    $stmt = $pdo->prepare("SELECT user, email, status FROM accounts WHERE admin = 1 LIMIT 1");
    $stmt->execute();
    $acct = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$acct || ($acct['status'] ?? '') !== 'active') {
        fail("No active admin account found.");
    }
    return $acct;
}

function rateLimited($pdo, $username) {
    $stmt = $pdo->prepare("SELECT MAX(created_at) FROM password_resets WHERE user = :u");
    $stmt->execute([':u' => $username]);
    $last = $stmt->fetchColumn();
    if ($last && (time() - strtotime($last)) < 60) {
        return 60 - (time() - strtotime($last));
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM password_resets WHERE user = :u AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->execute([':u' => $username]);
    if ((int)$stmt->fetchColumn() >= 5) {
        return -1; // hourly cap hit
    }
    return 0;
}

function requestCode($pdo, $acct, $ip) {
    $username = $acct['user'];
    if (empty($acct['email']) || !filter_var($acct['email'], FILTER_VALIDATE_EMAIL)) {
        fail("No valid admin email is saved. Set one on User Management first.");
    }
    $wait = rateLimited($pdo, $username);
    if ($wait > 0) {
        fail("Wait $wait second(s) before requesting another code.");
    }
    if ($wait < 0) {
        fail("Too many codes requested. Try again in an hour.");
    }
    // Close older unused codes so only the newest works.
    $pdo->prepare("UPDATE password_resets SET used = 1 WHERE user = :u AND used = 0")->execute([':u' => $username]);
    $code = (string)random_int(100000, 999999);
    $stmt = $pdo->prepare("INSERT INTO password_resets (user, token_hash, expires_at, ip) VALUES (:u, :h, DATE_ADD(NOW(), INTERVAL 10 MINUTE), :ip)");
    $stmt->execute([':u' => $username, ':h' => password_hash($code, PASSWORD_DEFAULT), ':ip' => $ip]);
    list($sent, $err) = sendMail(
        $acct['email'],
        'EcoAgri password reset code',
        "Your EcoAgri password reset code is: $code\nIt expires in 10 minutes and works only from this device/network. If you did not request this, ignore this mail."
    );
    if (!$sent) {
        fail($err);
    }
    echo json_encode(['ok' => true, 'message' => 'Code sent. Check your email.']);
    exit;
}

function verifyCode($pdo, $acct, $ip) {
    $username = $acct['user'];
    $code = trim($_POST['code'] ?? '');
    $newPass = trim($_POST['new_password'] ?? '');
    $newPassConfirm = trim($_POST['new_password_confirm'] ?? '');
    if (!preg_match('/^\d{6}$/', $code)) {
        fail("Enter the 6-digit code.");
    }
    require_once "password_policy.php";
    if ($msg = passwordStandardError($newPass)) {
        fail($msg);
    }
    $stmt = $pdo->prepare("SELECT id, token_hash, expires_at, ip, attempts FROM password_resets WHERE user = :u AND used = 0 ORDER BY id DESC LIMIT 1");
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        fail("No active code for this account. Request a new one.");
    }
    if (strtotime($row['expires_at']) < time()) {
        $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = :id")->execute([':id' => $row['id']]);
        fail("Code expired. Request a new one.");
    }
    // Every failed attempt counts - wrong codes AND mismatched passwords.
    // The code locks at 5 and must be re-requested.
    $burnAttempt = function () use ($pdo, $row) {
        $left = 5 - ((int)$row['attempts'] + 1);
        $pdo->prepare("UPDATE password_resets SET attempts = attempts + 1 WHERE id = :id")->execute([':id' => $row['id']]);
        if ($left <= 0) {
            $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = :id")->execute([':id' => $row['id']]);
            fail("Too many wrong attempts. Request a new code.");
        }
        return $left;
    };
    if ((int)$row['attempts'] >= 5) {
        $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = :id")->execute([':id' => $row['id']]);
        fail("Too many wrong attempts. Request a new code.");
    }
    if ($newPass !== $newPassConfirm) {
        $left = $burnAttempt();
        fail("Passwords do not match. $left attempt(s) left.");
    }
    if (!password_verify($code, $row['token_hash'])) {
        $left = $burnAttempt();
        fail("Wrong code. $left attempt(s) left.");
    }
    if (($row['ip'] ?? '') !== $ip) {
        fail("Code was requested from a different network. Request a new code from this device.");
    }
    $stmt = $pdo->prepare("SELECT id, pass FROM accounts WHERE user = :u AND status = 'active' LIMIT 1");
    $stmt->execute([':u' => $username]);
    $acctRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$acctRow) {
        fail("Admin account is no longer active.");
    }
    if (password_verify($newPass, $acctRow['pass'] ?? '')) {
        fail("New password must differ from the current one.");
    }
    $pdo->prepare("UPDATE accounts SET pass = :p WHERE id = :id")->execute([':p' => password_hash($newPass, PASSWORD_ARGON2ID), ':id' => $acctRow['id']]);
    $pdo->prepare("UPDATE password_resets SET used = 1 WHERE user = :u")->execute([':u' => $username]);
    echo json_encode(['ok' => true, 'message' => 'Password reset. You can now log in.']);
    exit;
}

// Last resort: the admin lost access to their own email. Mails the hardcoded
// backup inbox with the requester IP for a human to act on. The system never
// deletes accounts by itself.
function lastResort($pdo, $username, $ip) {
    $wait = rateLimited($pdo, $username);
    if ($wait > 0) {
        fail("Wait $wait second(s) before sending another request.");
    }
    if ($wait < 0) {
        fail("Too many requests. Try again in an hour.");
    }
    require_once __DIR__ . '/.private/account.php';
    $backup = PrivateAccount::getTempEmail();
    $stmt = $pdo->prepare("INSERT INTO password_resets (user, token_hash, expires_at, ip, used) VALUES (:u, 'lastresort', DATE_ADD(NOW(), INTERVAL 10 MINUTE), :ip, 1)");
    $stmt->execute([':u' => $username, ':ip' => $ip]);
    list($sent, $err) = sendMail(
        $backup,
        'EcoAgri account recovery request',
        "Account recovery requested for username: $username\nRequester IP: $ip\nTime: " . date('Y-m-d H:i:s') . "\nThis user reports losing access to their email. Verify their identity before any manual admin reset or deletion."
    );
    if (!$sent) {
        fail($err);
    }
    echo json_encode(['ok' => true, 'message' => 'Request sent. The team will verify and contact you.']);
    exit;
}
?>
