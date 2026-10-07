<?php
// =====================================================================
//  verify_email.php â€” UKURASA WA THIBITISHO YA BARUA PEPE
//
//  Hatua ya 2 ya usajili. Mtumiaji anaingiza code ya tarakimu 6
//  iliyotumwa kwenye email yake ya @nishati.go.tz.
//  Baada ya code sahihi, akaunti inakuwa "active" na anaenda login.
// =====================================================================
require __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';
require __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/verification.php';

$error   = "";
$message = "";

// Flash kutoka register.php (kama code haikuweza kutumwa)
if (!empty($_SESSION['verify_flash_error'])) {
    $error = $_SESSION['verify_flash_error'];
    unset($_SESSION['verify_flash_error']);
}
if (!empty($_SESSION['verify_flash_msg'])) {
    $message = $_SESSION['verify_flash_msg'];
    unset($_SESSION['verify_flash_msg']);
}

// Ombi la kuanza upya (kuachana na akaunti hii kwa muda)
if (isset($_GET['restart'])) {
    csrf_verify('EMAIL_VERIFY_RESTART');
    verification_clear_session();
}

// --- Ni akaunti gani inasubiri thibitisho? ---
$pid = (int)($_SESSION['pending_verify_user'] ?? 0);
$pending = $pid > 0 ? verification_pending_user($pid) : null;
if (!$pending) {
    verification_clear_session();
    $pid = 0;
}

// Kama mtu amefika hapa (mf. kutoka login) lakini hakuna code hai,
// tengeneza moja mapya â€” kupunguza usumbufu.
if ($pending && !verification_row($pid)) {
    $res = verification_send($pid, $pending['full_name'], $pending['email']);
    if (!$res['ok']) {
        $error = $res['error'];
    }
}

if (isset($_GET['sent']) && $message === '' && $error === '') {
    // Ujumbe uleule kwa kila hali â€” hakuna user enumeration
    $message = "If that account is awaiting verification, a new code has been sent to it.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify('EMAIL_VERIFY');
    $action = $_POST['action'] ?? 'verify';

    if ($action === 'verify') {
        if (!$pending) {
            $error = "Your verification session has expired. Please start again.";
        } else {
            $res = verification_verify($pid, $_POST['code'] ?? '');
            if ($res['ok']) {
                verification_clear_session();
                header("Location: login.php?verified=1");
                exit();
            }
            $error = $res['error'];
        }
    } elseif ($action === 'resend') {
        // SPRINT 3 (PRG): tumia flash kisha redirect (refresh haitatuma tena)
        if (!$pending) {
            $_SESSION['verify_flash_error'] = "Your verification session has expired. Please start again.";
        } else {
            $res = verification_send($pid, $pending['full_name'], $pending['email']);
            if ($res['ok']) {
                $_SESSION['verify_flash_msg'] = "A new code has been sent to " . mask_email($pending['email']) . ".";
            } else {
                $_SESSION['verify_flash_error'] = $res['error'];
            }
        }
        header("Location: verify_email.php");
        exit();
    } elseif ($action === 'lookup') {
        $email   = trim($_POST['email'] ?? '');
        $generic = "If that account is awaiting verification, a new code has been sent to it.";
        $target  = (valid_email($email) && is_official_email($email))
                 ? verification_lookup($email) : null;

        if ($target) {
            $_SESSION['pending_verify_user'] = (int)$target['id'];
            verification_send((int)$target['id'], $target['full_name'], $target['email']);
        }
        // Rudisha ujumbe uleule bila kujza email ipo au haipo
        header("Location: verify_email.php?sent=1");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email - Meeting Room Booking System</title>
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
        <h2>Verify Your Email</h2>
        <br>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php elseif ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($pending): ?>
            <p class="auth-sub">
                We sent a 6-digit verification code to
                <strong><?php echo htmlspecialchars(mask_email($pending['email'])); ?></strong>.
                Enter it below to activate your account. The code expires in
                <?php echo VERIFY_TTL_MINUTES; ?> minutes.
            </p>

            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="verify">
                <label>Verification Code</label>
                <input type="text" class="verify-code" name="code" inputmode="numeric"
                       autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}"
                       placeholder="000000" required autofocus>
                <button type="submit" class="btn">Verify &amp; Finish</button>
            </form>

            <div class="resend-row">
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="resend">
                    <button type="submit" class="link-btn">Resend code</button>
                </form>
                <a href="<?php echo csrf_url('verify_email.php?restart=1'); ?>">Use a different email</a>
            </div>

            <p style="margin-top:15px">Already verified? <a href="login.php">Sign in here</a></p>
        <?php else: ?>
            <p class="auth-sub">
                Enter the official email address you registered with, and we'll send you
                a 6-digit verification code.
            </p>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="lookup">
                <label>Email</label>
                <input type="email" name="email" placeholder="Enter your official email address"
                       required maxlength="100">
                <button type="submit" class="btn">Send Code</button>
            </form>
            <p style="margin-top:15px"><a href="register.php">Back to registration</a></p>
        <?php endif; ?>
    </div>
    </div>
    </div>
    <script src="../assets/js/theme.js?v=43"></script>
    <script src="../assets/js/nav.js?v=43"></script>
    <script src="../assets/js/ui.js?v=50"></script>
    <script src="../assets/js/icons.js?v=43"></script>
</body>
</html>
