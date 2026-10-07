<?php
require __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';
require __DIR__ . '/../includes/mailer.php';
require __DIR__ . '/../includes/audit.php';

$message = "";
$error = "";
$flash = flash_pull();
if ($flash['success'] !== '') $message = $flash['success'];
if ($flash['error'] !== '') $error = $flash['error'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify('PASSWORD_RESET_REQUEST');

    $email = trim($_POST['email'] ?? '');
    $generic = "If that email exists in our system, a reset link has been sent.";

    if (!valid_email($email)) {
        // Ujumbe uleule kwa kila hali â€” hakuna user enumeration
        $message = $generic;
    } elseif (!reset_request_allowed($email)) {
        $error = "Too many reset requests. Please wait about an hour and try again.";
        audit_log('PASSWORD_RESET_THROTTLED', 'user', null, "Throttled: $email");
    } elseif (!mail_enabled()) {
        // Mtiririko wa barua pepe umezimwa â€” usidanganye mtumiaji (Sprint 1 #5)
        $error = "Password reset by email is currently disabled. Please contact your system administrator to have your password reset.";
        audit_log('PASSWORD_RESET_MAIL_OFF', 'user', null, "mail_enabled()=false | $email");
    } else {
        $stmt = $conn->prepare("SELECT id, full_name FROM users WHERE email = ? AND status = 'active'");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $token = bin2hex(random_bytes(32));
            // >>> USALAMA: hifadhi HASH ya token, siyo token yenyewe <<<
            // Kama DB itaibwa, attacker hawezi kutumia token hizo.
            $token_hash = hash('sha256', $token);
            $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Zima token zote za zamani za mtu huyu kabla ya kuziweza mpya
            $invalidate = $conn->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0");
            $invalidate->bind_param("i", $user['id']);
            $invalidate->execute();

            $insert = $conn->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
            $insert->bind_param("iss", $user['id'], $token_hash, $expires_at);
            $insert->execute();

            audit_log('PASSWORD_RESET_REQUESTED', 'user', $user['id'], "Reset link requested for $email");

            // USALAMA (#8): msingi wa link HAUtegemei Host header ya ombi
            $reset_link = app_base_url() . "/reset_password.php?token=" . $token;

            $body = "<h3>Password Reset Request</h3>
                <p>Hi " . htmlspecialchars($user['full_name']) . ",</p>
                <p>We received a request to reset your password. Click the link below to set a new password. This link expires in 1 hour.</p>
                <p><a href=\"" . $reset_link . "\">Reset My Password</a></p>
                <p>If you did not request this, you can safely ignore this email. Your password will not change.</p>";

            $sent = send_booking_email($email, $user['full_name'], "Password Reset - Meeting Room Booking System", $body);

            if ($sent) {
                $message = "A password reset link has been sent to your email.";
            } else {
                // Barua pepe haijatumwa â€” zima token zote mpya za mtu huyu
                $kill = $conn->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0");
                $kill->bind_param("i", $user['id']);
                $kill->execute();
                audit_log('PASSWORD_RESET_MAIL_FAILED', 'user', $user['id'], "send_booking_email() failed for $email");
                $message = "";
                $error = "Could not send the reset email. Please try again later or contact your system administrator.";
            }
        } else {
            // Haturuhusu mtu kujua kama email ipo au haipo, kwa usalama
            $message = $generic;
        }
    }

    // SPRINT 3 (PRG): kila POST ya forgot hurejea GET mara moja â€”
    // refresh haiwezi kurejeshwa, na ujumbe (jibu jepesi la kazi halisi)
    // unaonekana kwa flash.
    if ($error !== '') {
        flash_set('error', $error);
    } elseif ($message !== '') {
        flash_set('success', $message);
    }
    header('Location: forgot_password.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Meeting Room Booking System</title>
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
        <h2>Forgot Password</h2>
        <br>
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <p style="margin-bottom:15px">Enter your registered email address, and we'll send you a link to reset your password.</p>
        <form method="POST" data-confirm="Send a password reset link to this email address?" data-confirm-title="Send Reset Link" data-confirm-text="Send Link">
            <?php echo csrf_field(); ?>
            <label>Email</label>
            <input type="email" name="email" placeholder="Enter your email address" required>

            <button type="submit" class="btn">Send Reset Link</button>
        </form>
        <p style="margin-top:15px"><a href="login.php">Back to Sign In</a></p>
    </div>
    </div>
    </div>
<script src="../assets/js/theme.js?v=43"></script>
<script src="../assets/js/ui.js?v=50"></script>
<script src="../assets/js/icons.js?v=43"></script>
</body>
</html>
