<?php 
require_once "db.php";

// Maximum accounts in the system, admin included.
$MAX_USERS = 5;

if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['username']) && isset($_POST['password']) && isset($_POST['role'])) {
    $username = trim($_POST['username']);
    $fullname = trim($_POST['fullname'] ?? '');
    $password = trim($_POST['password']);
    $role = trim($_POST['role']);

    # Check if role is valid
    if (!in_array($role, ['admin', 'production_staff', 'inventory_staff'])) {
        header("Location: ../users.php?error=" . urlencode("Invalid role"));
        exit;
    }

    # Check if admin role already exists
    if ($role === "admin") {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE admin = 1");
        $stmt->execute();
        if ($stmt->fetchColumn() > 0) {
            header("Location: ../users.php?error=" . urlencode("Only one admin is allowed"));
            exit;
        }
        $is_admin = true;
    } // no need else
 // Note: Role bool to null, Cause the admin role check is unique.

    # Block when the system already holds the maximum number of users.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM accounts");
    $stmt->execute();
    if ($stmt->fetchColumn() >= $MAX_USERS) {
        header("Location: ../users.php?error=" . urlencode("Operation Count of 5 Users reached"));
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

    # Insert user
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