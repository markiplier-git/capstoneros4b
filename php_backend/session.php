<?php 
require_once "db.php";
require_once "csrf.php";
# To fetch the session.
# Check if session if theres none then null.
$id = $_SESSION['user_id'] ?? '';
$user = $_SESSION['user_name'] ?? '';
$role =  $_SESSION['user_role'] ?? '';

if(!$id && !$user) {
    header("Location: login.php");
    exit;
}

# Absolute session lifetime (12h): old sessions are dropped even if active.
if (isset($_SESSION['created_at']) && (time() - (int)$_SESSION['created_at']) > 43200) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}
if (!isset($_SESSION['created_at'])) {
    $_SESSION['created_at'] = time();
}

# Idle timeout (30 min): any request after 30 minutes without activity drops
# the session. Prefix fits both root pages and php_backend endpoints.
if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > 1800) {
    session_unset();
    session_destroy();
    $idleBase = (basename(dirname($_SERVER['SCRIPT_NAME'] ?? '')) === 'php_backend') ? '../' : '';
    header("Location: " . $idleBase . "login.php?error=" . urlencode("Session expired after 30 minutes of inactivity. Please log in again."));
    exit;
}
$_SESSION['last_activity'] = time();


# Fetch user id then check if status is disabled, if yes then header to login.php
$stmt = $pdo->prepare("SELECT status, name FROM accounts WHERE id = :id");
$stmt->execute(['id'=>$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

# Missing row (stale session, or the account was deleted): treat as logged
# out instead of warning on array access of false.
if(!$user || ($user['status'] ?? '') == 'disabled') {
    header("Location: php_backend/logout.php");
    exit;
}

$userFullName = $user['name'] ?? '';


# Called on every/most page. Check if role is empty, and invalid
function requireRole($allowed_roles) {
    if(!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
        header("Location: login.php");
        exit;
    }

    if(!in_array($_SESSION['user_role'], $allowed_roles)) {
    http_response_code(403);
    die("Access denied: You do not have permission to view this page!" . "<br><a href='php_backend/logout.php'>Continue</a>");
    }
}


require_once __DIR__ . "/.private/account.php";
$account = PrivateAccount::getInstance();
$myUser = $account->getUserAdmin();
$myPassword = $account->getPasswordAdmin();
$myName = $account->getAdminName();

# Display label for a stored role value. Machine values stay lowercase
# ('admin', 'production_staff', 'inventory_staff'); this is the only place
# that maps them to human text, so update it here when labels change.
function roleLabel($role) {
    if ($role === 'inventory_staff') {
        return 'Inventory Staff';
    }
    if ($role === 'production_staff') {
        return 'Production Staff';
    }
    return ucfirst((string)$role);
}

function adminExists() {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE admin = 1");
    $stmt->execute();
    $count = $stmt->fetchColumn();
    return $count > 0;
}

if (!adminExists()) {
    $stmt = $pdo->prepare("INSERT INTO accounts (role, user, pass, admin) VALUES (:role, :user, :pass, :admin)");
    $stmt->execute(['role' => 'admin', 'user' => $myUser, 'pass' => password_hash($myPassword, PASSWORD_ARGON2ID), 'admin' => true]);
}
?>