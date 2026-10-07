<?php
require __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';
require __DIR__ . '/../includes/audit.php';

$error = "";
$message = "";
$valid_token = false;
$token = $_GET['token'] ?? ($_POST['token'] ?? '');

// Salama: token inapaswa kuwa herufi 64 (hex) pekee
if ($token !== '' && !preg_match('/^[a-f0-9]{64}$/', $token)) {
    $token = '';
    $error = "Invalid reset link.";
}

if ($token) {
    // >>> USALAMA: linganisha kwa HASH ya token (siyo plaintext) <<<
    $token_hash = hash('sha256', $token);
    $stmt = $conn->prepare("SELECT pr.id, pr.user_id, pr.expires_at, pr.used FROM password_resets pr WHERE pr.token = ?");
    $stmt->bind_param("s", $token_hash);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $reset = $result->fetch_assoc();
        if ($reset['used'] == 1) {
            $error = "This reset link has already been used. Please request a new one.";
        } elseif (strtotime($reset['expires_at']) < time()) {
            $error = "This reset link has expired. Please request a new one.";
        } else {
            $valid_token = true;
        }
    } else {
        $error = "Invalid reset link.";
    }
} elseif ($error === '') {
    $error = "No reset token provided.";
}

if ($valid_token && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    csrf_verify('PASSWORD_RESET');

    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $policy_error = password_policy_error($password);

    if ($policy_error !== '') {
        $error = $policy_error;
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update->bind_param("si", $hashed, $reset['user_id']);
        $update->execute();

        // Zima token ZOTE za mtu huyu (siyo hii pekee) baada ya mafanikio
        $mark_used = $conn->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ?");
        $mark_used->bind_param("i", $reset['user_id']);
        $mark_used->execute();

        audit_log('PASSWORD_RESET_COMPLETED', 'user', $reset['user_id'],
            "Password reset via emailed link", $reset['user_id']);

        header("Location: login.php?reset=1");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Meeting Room Booking System</title>
    <script>(function(){try{var t=localStorage.getItem('mrs_theme');if(t!=='light'&&t!=='dark'){t=(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';}document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
    <link rel="stylesheet" href="../assets/css/style.css?v=49">
</head>
<body>
    <div class="auth-page-bg">
    <div style="width:100%; max-width:400px; margin:0 auto;">
        <div class="brand-title">
            <img class="auth-crest" data-theme-toggle role="switch" tabindex="0" aria-checked="false" aria-label="Switch to dark mode" title="Switch to dark mode" src="../assets/img/coat-of-arms-of-tanzania-logo-png_seeklogo-311608.png?v=1" alt="Coat of Arms of Tanzania">
            <span>NISHATI MEETING ROOM BOOKING SYSTEM</span>
        </div>
    <div class="auth-box">
        <h2>Reset Password</h2>
        <br>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <p style="margin-top:10px"><a href="forgot_password.php">Request a new reset link</a></p>
        <?php elseif ($valid_token): ?>
            <form method="POST" data-confirm="Set this as your new account password?" data-confirm-title="Reset Password" data-confirm-text="Reset Password">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <label>New Password</label>
                <div class="password-wrapper">
                    <input type="password" name="password" id="newPassword" placeholder="At least 8 characters" required minlength="8">
                    <button type="button" class="password-toggle" onclick="togglePassword('newPassword', this)">Show</button>
                </div>

                <label>Confirm New Password</label>
                <div class="password-wrapper">
                    <input type="password" name="confirm_password" id="confirmPassword" placeholder="Re-enter new password" required>
                    <button type="button" class="password-toggle" onclick="togglePassword('confirmPassword', this)">Show</button>
                </div>

                <button type="submit" name="reset_password" class="btn">Reset Password</button>
            </form>
        <?php endif; ?>
        <p style="margin-top:15px"><a href="login.php">Back to Sign In</a></p>
    </div>
    </div>
    </div>
    <script src="../assets/js/theme.js?v=43"></script>
    <script src="../assets/js/nav.js?v=43"></script>
<script src="../assets/js/ui.js?v=50"></script>
<script src="../assets/js/icons.js?v=43"></script>
</body>
</html>
