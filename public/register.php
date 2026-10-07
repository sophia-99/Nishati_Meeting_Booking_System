<?php
require __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';
require __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/verification.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify('REGISTER');

    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($full_name === '' || $email === '' || $password === '' || $confirm_password === '') {
        $error = "Please fill in all fields.";
    } elseif (mb_strlen($full_name) > 100) {
        $error = "Full name is too long (max 100 characters).";
    } elseif (!valid_email($email)) {
        $error = "Please enter a valid email address.";
    } elseif (!is_official_email($email)) {
        // >>> KIPENGELE KIPYA: email lazima iwe ya shirika letu <<<
        $error = "Only official NISHATI emails are accepted. Your email must end with "
               . official_email_domain() . ".";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (($policy_error = password_policy_error($password)) !== '') {
        $error = $policy_error;
    } else {
        $check = $conn->prepare("SELECT id, status FROM users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        $result = $check->get_result();
        $existing = $result->fetch_assoc();

        if ($existing && $existing['status'] !== 'pending') {
            $error = "This email is already registered.";
        } else {
            if ($existing) {
                // Akaunti ya zamani iliyosimama "pending" â€” endelea na verification
                $new_id = (int)$existing['id'];
                $res = verification_send($new_id, $full_name, $email);
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                // >>> Akaunti mpya huanza "pending" â€” haijathibitishwa bado <<<
                $stmt = $conn->prepare(
                    "INSERT INTO users (full_name, email, password, role, status)
                     VALUES (?, ?, ?, 'staff', 'pending')"
                );
                $stmt->bind_param("sss", $full_name, $email, $hashed);
                $stmt->execute();
                $new_id = (int)$conn->insert_id;

                audit_log('USER_REGISTERED', 'user', $new_id,
                          "$full_name <$email> | status=pending (awaiting email verification)",
                          $new_id);

                $res = verification_send($new_id, $full_name, $email);
            }

            $_SESSION['pending_verify_user'] = $new_id;
            if (isset($_SESSION['user_id'])) unset($_SESSION['user_id']);

            if (!$res['ok']) {
                $_SESSION['verify_flash_error'] = $res['error'];
            }

            header("Location: verify_email.php");
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Meeting Room Booking System</title>
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
        <h2>Create Account</h2>
        <br>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="POST" data-confirm="Create your MRS account with these details?" data-confirm-title="Confirm Registration" data-confirm-text="Register">
            <?php echo csrf_field(); ?>
            <label>Full Name</label>
            <input type="text" name="full_name" placeholder="Enter your full name" required maxlength="100">

            <label>Email</label>
            <input type="email" name="email" placeholder="Your official email (e.g. juma@nishati.go.tz)"
                   required maxlength="100" pattern="[^@\s]+@nishati\.go\.tz"
                   title="Email must end with @nishati.go.tz">
            <span class="field-hint">Only <strong>@nishati.go.tz</strong> emails can register. You'll receive a 6-digit verification code.</span>

            <label>Password</label>
            <div class="password-wrapper">
                <input type="password" name="password" id="regPassword" placeholder="Create a password" required minlength="8">
                <button type="button" class="password-toggle" onclick="togglePassword('regPassword', this)">Show</button>
            </div>
            <ul class="password-rules" id="passwordRules">
                <li id="rule-length">At least 8 characters</li>
                <li id="rule-letter">Contains a letter</li>
                <li id="rule-number">Contains a number</li>
                <li id="rule-special">Contains a special character (e.g. ! @ # $ %)</li>
            </ul>

            <label>Confirm Password</label>
            <div class="password-wrapper">
                <input type="password" name="confirm_password" id="regConfirmPassword" placeholder="Re-enter your password" required>
                <button type="button" class="password-toggle" onclick="togglePassword('regConfirmPassword', this)">Show</button>
            </div>
            <ul class="password-rules" id="confirmRules">
                <li id="rule-match">Passwords match</li>
            </ul>

            <button type="submit" class="btn">Register</button>
        </form>
        <p style="margin-top:15px">Already have an account? <a href="login.php">Sign in here</a></p>
    </div>
    </div>
    </div>
    <script src="../assets/js/theme.js?v=43"></script>
    <script src="../assets/js/nav.js?v=43"></script>
    <script>
        const regPassword = document.getElementById('regPassword');
        const regConfirmPassword = document.getElementById('regConfirmPassword');

        function checkPasswordRules() {
            const val = regPassword.value;

            toggleRule('rule-length', val.length >= 8);
            toggleRule('rule-letter', /[A-Za-z]/.test(val));
            toggleRule('rule-number', /[0-9]/.test(val));
            toggleRule('rule-special', /[^A-Za-z0-9]/.test(val));

            checkPasswordsMatch();
        }

        function checkPasswordsMatch() {
            const match = regConfirmPassword.value.length > 0 && regPassword.value === regConfirmPassword.value;
            toggleRule('rule-match', match);
        }

        function toggleRule(id, met) {
            const el = document.getElementById(id);
            if (met) {
                el.classList.add('rule-met');
            } else {
                el.classList.remove('rule-met');
            }
        }

        regPassword.addEventListener('input', checkPasswordRules);
        regConfirmPassword.addEventListener('input', checkPasswordsMatch);
    </script>
<script src="../assets/js/ui.js?v=50"></script>
<script src="../assets/js/icons.js?v=43"></script>
</body>
</html>
