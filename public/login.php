<?php
require __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';
require __DIR__ . '/../includes/audit.php';

$error = "";
$notice = "";
$show_verify_link = false;

if (isset($_GET['expired'])) {
    $notice = "Your session has expired. Please sign in again.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify('LOGIN');

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!valid_email($email)) {
        $error = "Invalid email or password.";
        audit_log('LOGIN_FAILED', 'user', null, "Malformed email", null);
    } else {
        // --- Kikomo cha majaribio (brute-force protection) ---
        $remaining = login_attempts_remaining($email);
        if ($remaining <= 0) {
            $error = "Too many failed attempts. Please wait " . LOGIN_LOCKOUT_MINUTES
                   . " minutes and try again, or reset your password.";
            audit_log('LOGIN_LOCKED', 'user', null, "Lockout for: $email", null);
        } else {
            $stmt = $conn->prepare("SELECT id, full_name, email, password, role, status FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                $status = $user['status'];

                if ($status === 'inactive') {
                    // Ujumbe mmoja tu â€” hakuna user enumeration
                    $error = "Invalid email or password.";
                    login_attempt_record($email, false);
                    audit_log('LOGIN_FAILED', 'user', $user['id'], "Inactive account: $email", $user['id']);
                } elseif (!password_verify($password, $user['password'])) {
                    $error = "Invalid email or password.";
                    login_attempt_record($email, false);
                    audit_log('LOGIN_FAILED', 'user', $user['id'], "Wrong password: $email", $user['id']);
                } elseif ($status === 'pending') {
                    // >>> Akaunti haijathibitishwa email yake bado <<<
                    // Tunatoa ujumbe huu BAADA ya password kuwa sahihi tu,
                    // ili mtu asitambue akaunti zisizosajiliwa bila password.
                    $_SESSION['pending_verify_user'] = (int)$user['id'];
                    audit_log('LOGIN_BLOCKED_UNVERIFIED', 'user', $user['id'],
                              "Unverified account tried to sign in: $email", $user['id']);
                    $error = "Your account has not been verified yet. Please enter your verification code to finish registration.";
                    $show_verify_link = true;
                } else {
                    // Rekebisha hash kama algorithm imekuwa ya kisasa zaidi
                    if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                        $new_hash = password_hash($password, PASSWORD_DEFAULT);
                        $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $upd->bind_param("si", $new_hash, $user['id']);
                        $upd->execute();
                    }

                    // --- Session fixation protection ---
                    session_regenerate_id(true);
                    $_SESSION['created_at'] = time();
                    $_SESSION['last_regen']  = time();
                    $_SESSION['last_activity'] = time();

                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['login_at'] = time();

                    // Usafishe mabaki ya verification
                    unset($_SESSION['pending_verify_user']);

                    login_attempt_record($email, true);
                    audit_log('LOGIN_SUCCESS', 'user', $user['id'], $email, $user['id']);
                    session_write_close();
                    header("Location: index.php");
                    exit();
                }
            } else {
                $error = "Invalid email or password.";
                login_attempt_record($email, false);
                audit_log('LOGIN_FAILED', 'user', null, "Unknown email: $email", null);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Meeting Room Booking System</title>
    <script>(function(){try{var t=localStorage.getItem('mrs_theme');if(t!=='light'&&t!=='dark'){t=(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';}document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
    <link rel="stylesheet" href="../assets/css/style.css?v=49">
</head>
<body>
    <div class="auth-page-bg">
    <div style="width:200%; max-width:400px; margin:0 auto;">
        <div class="brand-title">
            <img class="auth-crest" data-theme-toggle role="switch" tabindex="0" aria-checked="false" aria-label="Switch to dark mode" title="Switch to dark mode" src="../assets/img/coat-of-arms-of-tanzania-logo-png_seeklogo-311608.png?v=1" alt="Coat of Arms of Tanzania">
            <span>NISHATI MEETING ROOM BOOKING SYSTEM</span>
        </div>
    <div class="auth-box">
        <h2>Sign In</h2>
        <br>
        <?php if (isset($_GET['verified'])): ?>
            <div class="alert alert-success">Your email has been verified and your account is now active. Please sign in.</div>
        <?php endif; ?>
        <?php if (isset($_GET['registered'])): ?>
            <div class="alert alert-success">Registration successful. Please sign in.</div>
        <?php endif; ?>
        <?php if (isset($_GET['reset'])): ?>
            <div class="alert alert-success">Your password has been reset. Please sign in with your new password.</div>
        <?php endif; ?>
        <?php if (isset($_GET['deactivated'])): ?>
            <div class="alert alert-error">Your account has been deactivated. Please contact the admin.</div>
        <?php endif; ?>
        <?php if ($notice): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($notice); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($show_verify_link): ?>
            <div class="auth-note">
                <a href="verify_email.php">Enter your verification code &rarr;</a>
            </div>
        <?php endif; ?>

        <form method="POST" id="loginForm">
            <?php echo csrf_field(); ?>
            <label>Email</label>
            <input type="email" name="email" placeholder="Enter your email address" required>

            <label>Password</label>
            <div class="password-wrapper">
                <input type="password" name="password" id="loginPassword" placeholder="Enter your password" required>
                <button type="button" class="password-toggle" onclick="togglePassword('loginPassword', this)">Show</button>
            </div>

            <button type="submit" class="btn">Sign In</button>
        </form>
        <p style="margin-top:12px"><a href="forgot_password.php">Forgot Password?</a></p>
        <p style="margin-top:8px">Don't have an account? <a href="register.php">Register here</a></p>
    </div>
    </div>
    </div>
    <script src="../assets/js/theme.js?v=43"></script>
    <script src="../assets/js/nav.js?v=43"></script>
    <script src="../assets/js/ui.js?v=50"></script>
    <script src="../assets/js/icons.js?v=43"></script>
</body>
</html>
