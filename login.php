<?php
# PHP register
require_once "php_backend/db.php";
$stmt = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE admin = :adminbool");
$stmt->execute(['adminbool' => 1]);
$count = $stmt->fetchColumn();
if($count == 0) {
    require_once __DIR__ . "/php_backend/.private/account.php";
    $account = PrivateAccount::getInstance();
    $myUser = $account->getUserAdmin();
    $myPassword = $account->getPasswordAdmin();
    $myName = $account->getAdminName();

    function adminExists($pdo) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE admin = 1");
        $stmt->execute();
        $count = $stmt->fetchColumn();
        return $count > 0;
    }

    if (!adminExists($pdo)) {
        $stmt = $pdo->prepare("INSERT INTO accounts (role, user, name, pass, admin) VALUES (:role, :user, :name, :pass, :admin)");
        $stmt->execute(['role' => 'admin', 'user' => $myUser, 'name'=> $myName, 'pass' => password_hash($myPassword, PASSWORD_ARGON2ID), 'admin' => 1]);
    }
}
if ((isset($_POST['rusername'])) && (isset($_POST['rpassword'])) && $_SERVER['REQUEST_METHOD'] == "POST") {
    # init fetch    
    $username = trim($_POST['rusername']);
    $password = trim($_POST['rpassword']);
    $role = trim($_POST['role']);


    # Check if role is valid
    if (!in_array($role, ['admin', 'production_staff', 'inventory_staff'])) {
        header("Location: login.php?error=" . urlencode("Role is invalid"));
        exit;
    }

    #Check if admin role:
    if($role=="admin") {
        $stmt = $pdo->prepare("SELECT admin FROM accounts WHERE admin = :adminbool");
        $stmt->execute(['adminbool' => true]);
        $result = $stmt->fetch();
        if($result) #if true.
        {
            header("Location: login.php?error=" . urlencode("Only one admin is allowed!"));
            exit;
        }
        $is_admin = true;
    }

    
    # Check if user exists already
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE user  = :username");
    $stmt->execute([':username' => $username]);
    $count = $stmt->fetchColumn();

    if ($count > 0) {
        header("Location: login.php?error=" . urlencode("Username already exists!"));
        exit;
    }

    # Hashing password
    $hashedpassword = password_hash($password, PASSWORD_ARGON2ID);

    # Register user with exception
    try {
        $stmt = $pdo->prepare("INSERT INTO accounts (role, user, pass, admin) VALUES (:roles, :username, :hashedpassword, :administ)");
    } catch (Throwable $e) {
        header("Location: login.php?error=" . urlencode("Something went wrong: " . $e->getMessage()));
        exit;
    }
    $stmt->bindValue(':roles', $role);
    $stmt->bindValue(':username', $username);
    $stmt->bindValue(':hashedpassword', $hashedpassword);
    $stmt->bindValue(':administ', $is_admin);
    if ($stmt->execute()) {
        $stmt = null;
        header("Location: login.php?registered=1");
        exit;
    }
}

# PHP Login
# Anti-spam: failures are logged per username+IP in login_attempts. Five
# failures inside 5 minutes locks further tries until the window passes.
# A successful login clears that pair's count. The table may not exist on
# old DBs yet - login must never fatal, so misses fall back to no limit.
if ((isset($_POST['username'])) && (isset($_POST['password'])) && $_SERVER['REQUEST_METHOD'] == "POST") {
    // Use #PDO, htmlspecialchars, trim?,
    $user = trim($_POST['username']);
    $pass = trim($_POST['password']);
    $loginIp = $_SERVER['REMOTE_ADDR'] ?? '';

    require_once "php_backend/db.php";

    $loginFails = 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*), MAX(created_at) FROM login_attempts WHERE user = :username AND ip = :ip AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
        $stmt->execute([':username' => $user, ':ip' => $loginIp]);
        $loginRow = $stmt->fetch(PDO::FETCH_NUM);
        $loginFails = (int)($loginRow[0] ?? 0);
        $loginLast = $loginRow[1] ?? null;
    } catch (Exception $e) {
        $loginFails = 0;
        $loginLast = null;
    }
    if ($loginFails >= 5) {
        $loginWait = $loginLast ? max(1, 300 - (time() - strtotime($loginLast))) : 300;
        header("Location: login.php?error=" . urlencode("Too many failed attempts. Try again in $loginWait second(s)."));
        exit;
    }
    $loginFail = function () use ($pdo, $user, $loginIp) {
        try {
            $stmt = $pdo->prepare("INSERT INTO login_attempts (user, ip) VALUES (:username, :ip)");
            $stmt->execute([':username' => $user, ':ip' => $loginIp]);
        } catch (Exception $e) {
            // Table missing - login continues unthrottled.
        }
    };

    # Check if user exists first
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE user = :username");
    $stmt->execute([':username' => $user]);
    $count = $stmt->fetchColumn();
    if ($count !=1) {
        $loginFail();
        header("Location: login.php?error=" . urlencode("Username not found!"));
        exit;
    }

    # Fetch the password
    $stmt = $pdo->prepare("SELECT id,role,pass FROM accounts WHERE user = :username");
    $stmt->bindValue(':username', $user);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $password = $row['pass'] ?? '';

    # Verify the password
    if(password_verify($pass, $password)) {
        try {
            $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE user = :username AND ip = :ip");
            $stmt->execute([':username' => $user, ':ip' => $loginIp]);
        } catch (Exception $e) {
            // Table missing - nothing to clear.
        }
        session_start();
        session_regenerate_id(true);
        # Use the username, id, role in session.
        $_SESSION['user_id'] = $row['id'];
        $_SESSION['user_name'] = $user;
        $_SESSION['user_role'] = $row['role'];
        header("Location: index.php");         
        exit;
    } else {
        $loginFail();
        header("Location: login.php?error=" . urlencode("Password is incorrect!"));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EcoAgri - Login</title>
    <?php require_once "php_backend/head_assets.php"; ?>
    <link rel="stylesheet" href="style.css?v=<?= filemtime('style.css') ?>">
</head>

<body class="login-body">
    <div class="login-page">
        <!--Login page START-->
        <!--Login Card Container-->
        <div class="login-card">
            <!--Card Header-->
            <div class="login-card-header">
                <div class="header-logo">
                    <!--<i class="fa-solid fa-leaf"></i>-->
                </div>
                <img src="assets/img/logo.png" id="logo">
                <p>Login Page</p>
            </div>

            <!--Card Body-->
            <div class="login-card-body">
                <form method="POST" class="login-form">
                    <div class="input-group">
                        <label for="username"><i class="fa-solid fa-user"></i> Username</label>
                        <input type="text" id="username" name="username" placeholder="Enter username" autocomplete="off"
                            required>
                    </div>

                    <div class="input-group">
                        <label for="password"><i class="fa-solid fa-lock"></i> Password</label>
                        <div class="password-wrap">
                            <input type="password" id="password" name="password" placeholder="Enter password"
                                autocomplete="off" autocorrect="off" required>
                            <button type="button" class="toggle-password" id="toggle-password" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-primary">
                            <i class="fa-solid fa-right-to-bracket"></i> Login
                        </button>
                    </div>

                    <div class="account-register-prompt" style="text-align: center; margin-top: 10px;">
                        <a href="#" id="forgot-link" style="display: none; font-size: 0.9rem;">Forgot Password?</a>
                    </div>
                </form>
            </div>

<!--
            <div class="login-card-footer">
                <div class="demo-title">Register to get started</div>
                <div class="demo-cred"><button commandfor="register-diag" command="show-modal">Register</button>
                </div>
            </div>


            Register Form in a dialog container
            <dialog id="register-diag">
                <div class="dialog-header">
                    <img src="assets/img/logo.png" id="logo">
                    <h2>Register Account</h2>
                </div>
                <div class="dialog-body">
                    <form method="POST">
                        <div class="input-group">
                            <label for="rusername"><i class="fa-solid fa-user"></i> Username</label>
                            <input type="text" id="rusername" name="rusername" placeholder="Create Username"
                                autocomplete="on" required>
                        </div>
                        <div class="input-group">
                            <label for="rpassword"><i class="fa-solid fa-lock"></i> Password</label>
                            <input type="password" id="rpassword" name="rpassword" placeholder="Create Password"
                                autocomplete="off" autocorrect="off" required>
                        </div>
                        <div class="input-group">
                            <label for="role"><i class="fa-solid fa-user-tag"></i> Role</label>
                            <select id="role" name="role">
                                <option value="inventory_staff">Inventory Staff</option>
                                <option value="production_staff">Production Staff</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="dialog-actions">
                            <button type="button" class="btn-secondary" id="close-register-btn" command="close"
                                commandfor="register-diag">Cancel</button>
                            <button type="submit" class="btn-primary"><i class="fa-solid fa-user-plus"></i>
                                Register</button>
                        </div>
                    </form>
                </div>
            </dialog>-->

            <!--Forgot password dialog (needs internet: hidden link when offline)-->
            <dialog id="forgot-diag">
                <div class="dialog-header">
                    <h2>Reset Password</h2>
                </div>
                <div class="dialog-body">
                    <p id="fp-msg" class="section-desc" style="margin: 0 0 12px;">A reset code will be sent to the admin email address.</p>
                    <div id="fp-step1">
                        <div class="dialog-actions">
                            <button type="button" class="btn-secondary" command="close" commandfor="forgot-diag">Cancel</button>
                            <button type="button" class="btn-primary" id="fp-send-btn"><i class="fa-solid fa-paper-plane"></i> Send Code</button>
                        </div>
                        <div style="text-align: center; margin-top: 10px;">
                            <a href="#" id="fp-lastresort" style="display: none; font-size: 0.85rem;">Can't access your email?</a>
                        </div>
                    </div>
                    <div id="fp-step2" style="display: none;">
                        <div class="form-group" style="margin-bottom: 12px;">
                            <label for="fp-code"><i class="fa-solid fa-key"></i> 6-digit Code</label>
                            <input type="text" id="fp-code" placeholder="Enter code" autocomplete="off" maxlength="6" inputmode="numeric" style="width: 100%;">
                        </div>
                        <div class="form-group" style="margin-bottom: 12px;">
                            <label for="fp-newpass"><i class="fa-solid fa-lock"></i> New Password</label>
                            <div class="password-wrap">
                                <input type="password" id="fp-newpass" placeholder="Enter new password" autocomplete="off">
                                <button type="button" class="toggle-password" id="fp-toggle-new" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                            </div>
                        </div>
                        <div class="form-group" style="margin-bottom: 12px;">
                            <label for="fp-newpass-confirm"><i class="fa-solid fa-lock"></i> Confirm Password</label>
                            <div class="password-wrap">
                                <input type="password" id="fp-newpass-confirm" placeholder="Repeat new password" autocomplete="off">
                                <button type="button" class="toggle-password" id="fp-toggle-confirm" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                            </div>
                        </div>
                        <div class="dialog-actions">
                            <button type="button" class="btn-secondary" id="fp-back-btn">Back</button>
                            <button type="button" class="btn-primary" id="fp-reset-btn"><i class="fa-solid fa-check"></i> Reset Password</button>
                        </div>
                        <div class="dialog-actions" id="fp-done-actions" style="display: none; justify-content: center; margin-top: 10px;">
                            <button type="button" class="btn-primary" command="close" commandfor="forgot-diag">Close</button>
                        </div>
                    </div>
                </div>
            </dialog>

            <!--Dialog feedback-->
            <dialog id="message-diag">
                <div class="dialog-header">
                    <h2>Notice</h2>
                </div>
                <div class="dialog-body">
                    <p id="message">Nothing to see here..</p>
                    <div class="dialog-actions" style="justify-content: center;">
                        <button type="button" class="btn-primary" id="close-msg-btn" command="close"
                            commandfor="message-diag">OK</button>
                    </div>
                </div>
            </dialog>
        <!--Login page END-->
        <script>
        // Show/hide password toggle (always present; Edge's native eye is hidden in CSS).
        const pwInput = document.getElementById('password');
        const pwToggle = document.getElementById('toggle-password');
        if (pwInput && pwToggle) {
            pwToggle.addEventListener('click', () => {
                const icon = pwToggle.querySelector('i');
                if (pwInput.type === 'password') {
                    pwInput.type = 'text';
                    if (icon) icon.className = 'fa-solid fa-eye-slash';
                    pwToggle.setAttribute('aria-label', 'Hide password');
                } else {
                    pwInput.type = 'password';
                    if (icon) icon.className = 'fa-solid fa-eye';
                    pwToggle.setAttribute('aria-label', 'Show password');
                }
            });
        }
        const message = document.getElementById('message');
        const feedbackdiag = document.getElementById('message-diag');
        const registerdiag = document.getElementById('register-diag');
        const openRegisterBtn = document.getElementById('open-register-btn');
        const closeRegisterBtn = document.getElementById('close-register-btn');
        const closeMsgBtn = document.getElementById('close-msg-btn');

        if (openRegisterBtn && registerdiag) {
            openRegisterBtn.addEventListener('click', () => registerdiag.showModal());
        }
        if (closeRegisterBtn && registerdiag) {
            closeRegisterBtn.addEventListener('click', () => registerdiag.close());
        }
        if (closeMsgBtn && feedbackdiag) {
            closeMsgBtn.addEventListener('click', () => feedbackdiag.close());
        }



        // Show "Registered successfully!" after the redirect from register
        if (new URLSearchParams(window.location.search).has('registered')) {
            message.textContent = 'Registered successfully! You can now login.';
            feedbackdiag.showModal();
        }

        // Show login/register errors passed back via ?error=...
        const urlError = new URLSearchParams(window.location.search).get('error');
        if (urlError) {
            message.textContent = urlError;
            feedbackdiag.showModal();
        }

        // Forgot password: link exists only while online (reset needs mail).
        const forgotLink = document.getElementById('forgot-link');
        const forgotDiag = document.getElementById('forgot-diag');
        const updateOnline = () => {
            if (forgotLink) forgotLink.style.display = navigator.onLine ? '' : 'none';
        };
        window.addEventListener('online', updateOnline);
        window.addEventListener('offline', updateOnline);
        updateOnline();
        if (forgotLink && forgotDiag) {
            forgotLink.addEventListener('click', (e) => {
                e.preventDefault();
                if (!navigator.onLine) return;
                document.getElementById('fp-step1').style.display = '';
                document.getElementById('fp-step2').style.display = 'none';
                document.getElementById('fp-done-actions').style.display = 'none';
                document.getElementById('fp-lastresort').style.display = 'none';
                fpSent = false;
                document.getElementById('fp-msg').textContent = 'A reset code will be sent to the admin email address.';
                forgotDiag.showModal();
            });
        }
        // Last-resort is offered only after Send Code was attempted (never first).
        let fpSent = false;
        const fpRevealLastResort = () => {
            fpSent = true;
            const lr = document.getElementById('fp-lastresort');
            if (lr) lr.style.display = '';
        };
        const fpPost = (data) => fetch('php_backend/password_reset.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data).toString()
        }).then(r => r.json());
        const fpSendBtn = document.getElementById('fp-send-btn');
        if (fpSendBtn) {
            fpSendBtn.addEventListener('click', () => {
                const msg = document.getElementById('fp-msg');
                // Anti-spam wait: 5s countdown before the request leaves.
                let left = 5;
                fpSendBtn.disabled = true;
                const tick = () => {
                    if (left > 0) {
                        fpSendBtn.textContent = 'Wait ' + left + 's...';
                        left--;
                        setTimeout(tick, 1000);
                    } else {
                        fpSendBtn.textContent = 'Sending...';
                        msg.textContent = 'Sending code...';
                        fpPost({ op: 'request' }).then(res => {
                            fpSendBtn.disabled = false;
                            fpSendBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Send Code';
                            fpRevealLastResort();
                            if (res.ok) {
                                msg.textContent = res.message;
                                document.getElementById('fp-step1').style.display = 'none';
                                document.getElementById('fp-step2').style.display = '';
                            } else {
                                msg.textContent = res.error || 'Could not send code.';
                            }
                        }).catch(() => {
                            fpSendBtn.disabled = false;
                            fpSendBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Send Code';
                            fpRevealLastResort();
                            msg.textContent = 'No connection. Connect to the internet and try again.';
                        });
                    }
                };
                tick();
            });
        }
        const fpResetBtn = document.getElementById('fp-reset-btn');
        if (fpResetBtn) {
            fpResetBtn.addEventListener('click', () => {
                const msg = document.getElementById('fp-msg');
                const data = {
                    op: 'verify',
                    code: document.getElementById('fp-code').value.trim(),
                    new_password: document.getElementById('fp-newpass').value,
                    new_password_confirm: document.getElementById('fp-newpass-confirm').value
                };
                msg.textContent = 'Verifying...';
                fpPost(data).then(res => {
                    msg.textContent = res.ok ? res.message : (res.error || 'Reset failed.');
                    // Success closes the form; failures keep it open so a typo
                    // can be fixed and retried without requesting a new code.
                    if (res.ok) {
                        document.getElementById('fp-step2').style.display = 'none';
                    }
                    document.getElementById('fp-done-actions').style.display = '';
                }).catch(() => {
                    msg.textContent = 'No connection. Connect to the internet and try again.';
                    document.getElementById('fp-done-actions').style.display = '';
                });
            });
        }
        const fpBackBtn = document.getElementById('fp-back-btn');
        if (fpBackBtn) {
            fpBackBtn.addEventListener('click', () => {
                document.getElementById('fp-step2').style.display = 'none';
                document.getElementById('fp-done-actions').style.display = 'none';
                document.getElementById('fp-step1').style.display = '';
            });
        }
        const fpLastResort = document.getElementById('fp-lastresort');
        if (fpLastResort) {
            fpLastResort.addEventListener('click', (e) => {
                e.preventDefault();
                if (!fpSent) return;
                const msg = document.getElementById('fp-msg');
                msg.textContent = 'Sending recovery request...';
                fpPost({ op: 'lastresort' }).then(res => {
                    msg.textContent = res.ok ? res.message : (res.error || 'Request failed.');
                }).catch(() => {
                    msg.textContent = 'No connection. Connect to the internet and try again.';
                });
            });
        }
        // Show/hide toggles for the reset password fields (same as login).
        [['fp-newpass', 'fp-toggle-new'], ['fp-newpass-confirm', 'fp-toggle-confirm']].forEach(([inputId, btnId]) => {
            const input = document.getElementById(inputId);
            const btn = document.getElementById(btnId);
            if (input && btn) {
                btn.addEventListener('click', () => {
                    const icon = btn.querySelector('i');
                    if (input.type === 'password') {
                        input.type = 'text';
                        if (icon) icon.className = 'fa-solid fa-eye-slash';
                        btn.setAttribute('aria-label', 'Hide password');
                    } else {
                        input.type = 'password';
                        if (icon) icon.className = 'fa-solid fa-eye';
                        btn.setAttribute('aria-label', 'Show password');
                    }
                });
            }
        });
        </script>
</body>

</html>