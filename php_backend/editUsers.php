<?php 
require_once "db.php";
require_once "csrf.php";
# Admin-only endpoint (users.php is admin-only, but this URL is directly
# POSTable - re-check here with a backend-correct redirect target).
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit;
}
csrf_check();

if($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['admin_email'])) {
    require_once "db.php";
    $admin_email = trim($_POST['admin_email'] ?? '');
    $admin_email_confirm = trim($_POST['admin_email_confirm'] ?? '');
    if ($admin_email === '' || !filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
        header("Location: ../users.php?error=" . urlencode("Invalid email address"));
        exit;
    }
    if ($admin_email !== $admin_email_confirm) {
        header("Location: ../users.php?error=" . urlencode("Email addresses do not match"));
        exit;
    }
    $stmt = $pdo->prepare("UPDATE accounts SET email = :email WHERE admin = 1");
    $stmt->execute([':email' => $admin_email]);
    header("Location: ../users.php?success=" . urlencode("Admin email updated successfully"));
    exit;
}

# Step 1: verify the current admin password. Sets a 5-minute session flag
# that unlocks the change form (no dialog needed).
if($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['admin_verify'])) {
    require_once "db.php";
    $admin_verify = $_POST['admin_verify'] ?? '';
    $stmt = $pdo->prepare("SELECT id, pass FROM accounts WHERE admin = 1 LIMIT 1");
    $stmt->execute();
    $adminRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$adminRow || !password_verify($admin_verify, $adminRow['pass'] ?? '')) {
        header("Location: ../users.php?error=" . urlencode("Current password is incorrect"));
        exit;
    }
    $_SESSION['ap_verified'] = time();
    header("Location: ../users.php?success=" . urlencode("Identity verified - set the new password below"));
    exit;
}

# Cancel the verified change flow.
if($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['ap_cancel'])) {
    unset($_SESSION['ap_verified']);
    header("Location: ../users.php");
    exit;
}

# Step 2: save the new password. Requires a fresh verify flag, consumed here.
if($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['admin_save'])) {
    require_once "db.php";
    if (!isset($_SESSION['ap_verified']) || (time() - (int)$_SESSION['ap_verified']) > 300) {
        unset($_SESSION['ap_verified']);
        header("Location: ../users.php?error=" . urlencode("Verify your current password first"));
        exit;
    }
    $admin_new = trim($_POST['admin_new'] ?? '');
    $admin_new_confirm = trim($_POST['admin_new_confirm'] ?? '');
    $stmt = $pdo->prepare("SELECT id, pass FROM accounts WHERE admin = 1 LIMIT 1");
    $stmt->execute();
    $adminRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$adminRow) {
        unset($_SESSION['ap_verified']);
        header("Location: ../users.php?error=" . urlencode("No admin account found"));
        exit;
    }
    require_once "password_policy.php";
    if ($msg = passwordStandardError($admin_new)) {
        header("Location: ../users.php?error=" . urlencode($msg));
        exit;
    }
    if ($admin_new !== $admin_new_confirm) {
        header("Location: ../users.php?error=" . urlencode("Passwords do not match"));
        exit;
    }
    if (password_verify($admin_new, $adminRow['pass'] ?? '')) {
        header("Location: ../users.php?error=" . urlencode("New password must differ from the current one"));
        exit;
    }
    $stmt = $pdo->prepare("UPDATE accounts SET pass = :pass WHERE id = :id");
    $stmt->execute([':pass' => password_hash($admin_new, PASSWORD_ARGON2ID), ':id' => $adminRow['id']]);
    unset($_SESSION['ap_verified']);
    header("Location: ../users.php?success=" . urlencode("Admin password changed successfully"));
    exit;
}

if($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['disable_id'])) {
    require_once "db.php";
    $fetch_id = $_POST['disable_id'];
    $find_id = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE id = :id");
    
    $find_id->execute([':id'=> $fetch_id]);
    if($find_id->fetchColumn() > 0) {
        $stmt = $pdo->prepare("UPDATE accounts SET status = :status WHERE id = :id");
        $stmt->execute([':status' => 'disabled', ':id'=> $fetch_id]);
    }
    $find_id = null;
    $stmt = null;
    header("Location: ../users.php");
}

if($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['enable_id'])) {
    require_once "db.php";
    $fetch_id = $_POST['enable_id'];
    $find_id = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE id = :id");
    
    $find_id->execute([':id'=> $fetch_id]);
    if($find_id->fetchColumn() > 0) {
        $stmt = $pdo->prepare("UPDATE accounts SET status = :status WHERE id = :id");
        $stmt->execute([':status' => 'active', ':id'=> $fetch_id]);
    }
    $find_id = null;
    $stmt = null;
    header("Location: ../users.php");
}

if($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['reset_id']) && isset($_POST['new_password'])) {
    require_once "db.php";
    $reset_id = $_POST['reset_id'];
    $new_password = trim($_POST['new_password']);
    $new_password_confirm = trim($_POST['new_password_confirm'] ?? '');
    require_once "password_policy.php";
    if ($msg = passwordStandardError($new_password)) {
        header("Location: ../users.php?error=" . urlencode($msg));
        exit;
    }
    if ($new_password !== $new_password_confirm) {
        header("Location: ../users.php?error=" . urlencode("Passwords do not match"));
        exit;
    }
    $find_id = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE id = :id");
    $find_id->execute([':id' => $reset_id]);
    if ($find_id->fetchColumn() > 0) {
        $stmt = $pdo->prepare("UPDATE accounts SET pass = :pass WHERE id = :id");
        $stmt->execute([':pass' => password_hash($new_password, PASSWORD_ARGON2ID), ':id' => $reset_id]);
        header("Location: ../users.php?success=" . urlencode("Password reset successfully"));
    } else {
        header("Location: ../users.php?error=" . urlencode("User not found"));
    }
    exit;
}
?>