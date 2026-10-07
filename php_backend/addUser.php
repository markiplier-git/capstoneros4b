<?php 
require_once "db.php";
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
# Admin-only endpoint - re-checked here because this URL is directly POSTable.
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// One active account per role. Deactivated accounts free their slot.
$MAX_PER_ROLE = 1;
$ROLE_LABELS = ['admin' => 'Admin', 'production_staff' => 'Production Staff', 'inventory_staff' => 'Inventory Staff'];

if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['username']) && isset($_POST['password']) && isset($_POST['role'])) {
    $username = trim($_POST['username']);
    $fullname = trim($_POST['fullname'] ?? '');
    $password = trim($_POST['password']);
    $password_confirm = trim($_POST['password_confirm'] ?? '');
    $role = trim($_POST['role']);

    # Check if role is valid
    if (!in_array($role, ['admin', 'production_staff', 'inventory_staff'])) {
        header("Location: ../users.php?error=" . urlencode("Invalid role"));
        exit;
    }

    if ($password !== $password_confirm) {
        header("Location: ../users.php?error=" . urlencode("Passwords do not match"));
        exit;
    }
    require_once "password_policy.php";
    if ($msg = passwordStandardError($password)) {
        header("Location: ../users.php?error=" . urlencode($msg));
        exit;
    }

    # Check if admin role already exists (active only - a deactivated
    # admin frees the slot like any other role).
    if ($role === "admin") {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE admin = 1 AND status = 'active'");
        $stmt->execute();
        if ($stmt->fetchColumn() > 0) {
            header("Location: ../users.php?error=" . urlencode("Only one admin is allowed"));
            exit;
        }
        $is_admin = true;
    } // no need else
 // Note: Role bool to null, Cause the admin role check is unique.

    # Block when this role already has its active account. Deactivated
    # accounts are not counted.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE role = :role AND status = 'active'");
    $stmt->execute([':role' => $role]);
    if ($stmt->fetchColumn() >= $MAX_PER_ROLE) {
        $roleLabel = $ROLE_LABELS[$role] ?? $role;
        header("Location: ../users.php?error=" . urlencode("Only 1 active $roleLabel account is allowed"));
        exit;
    }
    

    # Check if user exists already
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE user = :username");
    $stmt->execute([':username' => $username]);
    if ($stmt->fetchColumn() > 0) {
        header("Location: ../users.php?error=" . urlencode("Username already exists"));
        exit;
    }

    # Hashing password
    if (empty($password)) {
        header("Location: ../users.php?error=" . urlencode("Password is required"));
        exit;
    }
    $hashedpassword = password_hash($password, PASSWORD_ARGON2ID);

    # Insert user (emails are admin-only - staff passwords are reset here).
    $stmt = $pdo->prepare("INSERT INTO accounts (role, user, name, pass, admin) VALUES (:roles, :username, :fullname, :hashedpassword, :administ)");
    $stmt->bindValue(':roles', $role);
    $stmt->bindValue(':username', $username);
    $stmt->bindValue(':fullname', $fullname !== '' ? $fullname : null);
    $stmt->bindValue(':hashedpassword', $hashedpassword);
    $stmt->bindValue(':administ', $is_admin);

    if ($stmt->execute()) {
        header("Location: ../users.php?success=" . urlencode("User added successfully"));
    } else {
        header("Location: ../users.php?error=" . urlencode("Failed to add user"));
    }
    exit;
}
?>