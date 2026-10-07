<?php
// WASIFU WANGU â€” mtumiaji yeyote aliyedingia anaweza kuona/kuhariri
// taarifa zake mwenyewe na kubadilisha password yake (CSRF + audit zote).
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require __DIR__ . '/../includes/audit.php';
check_login();

$me_id = intval($_SESSION['user_id'] ?? 0);

// ------------------------------------------------------------------
// 1. HARIRI WASIFU (jina + email) â€” kutoka modal ya "Edit Profile"
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    csrf_verify('PROFILE_EDIT');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($full_name === '' || $email === '') {
        flash_set('error', 'Please fill in all fields.');
    } elseif (mb_strlen($full_name) > 100) {
        flash_set('error', 'Full name is too long (max 100 characters).');
    } elseif (!valid_email($email)) {
        flash_set('error', 'Please enter a valid email address.');
    } elseif (!is_official_email($email)) {
        // >>> SHERIA MPYA: email lazima iwe ya NISHATI <<<
        flash_set('error', 'Only official emails are accepted. Email must end with '
                 . official_email_domain() . '.');
    } else {
        $check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check->bind_param("si", $email, $me_id);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            flash_set('error', 'That email is already used by another account.');
        } else {
            $update = $conn->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?");
            $update->bind_param("ssi", $full_name, $email, $me_id);
            $update->execute();

            // Sasisha session mara moja (jina/ email huonekana navbar)
            $_SESSION['full_name'] = $full_name;
            $_SESSION['email'] = $email;

            audit_log('PROFILE_UPDATED', 'user', $me_id,
                      "Self-edit: name=[$full_name] email=[$email]");
            flash_set('success', 'Your profile has been updated successfully.');
            header('Location: profile.php?updated=1');
            exit;
        }
    }
    header('Location: profile.php');
    exit;
}

// ------------------------------------------------------------------
// 2. BADILISHA PASSWORD YAKO MWENYEWI â€” kutoka modal ya "Change Password"
//    Lazima ujue ya sasa; siyo kama reset ya admin.
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    csrf_verify('PROFILE_PASSWORD_CHANGE');
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';

    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $me_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row || $current === '' || !password_verify($current, $row['password'])) {
        audit_log('PASSWORD_CHANGE_REJECTED', 'user', $me_id,
                  'Rejected: current password did not match');
        flash_set('error', 'Your current password is incorrect.');
    } elseif (($policy_error = password_policy_error($new)) !== '') {
        flash_set('error', $policy_error);
    } elseif ($new === $current) {
        flash_set('error', 'The new password must be different from your current one.');
    } else {
        $hashed = password_hash($new, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update->bind_param("si", $hashed, $me_id);
        $update->execute();

        // Zima reset token zote (hakuna njia ya zamani ya kuingia)
        $kill = $conn->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ?");
        $kill->bind_param("i", $me_id);
        $kill->execute();

        audit_log('PASSWORD_CHANGED_SELF', 'user', $me_id, 'User changed their own password');
        flash_set('success', 'Your password has been changed successfully.');
        header('Location: profile.php?pwchanged=1');
        exit;
    }
    header('Location: profile.php');
    exit;
}

// ------------------------------------------------------------------
// 3. DATA ZA UKURASA
// ------------------------------------------------------------------
$flash = flash_pull();
$message = $flash['success'];
$error = $flash['error'];

$stmt = $conn->prepare("SELECT id, full_name, email, role, status, created_at FROM users WHERE id = ?");
$stmt->bind_param("i", $me_id);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();

if (!$me) {
    // Session ya mtu aliyefutwa
    header("Location: logout.php");
    exit;
}

// Takwimu zangu: jumla, zinazokuja, zilizokamilika, zilizoghairiwa
$stats_stmt = $conn->prepare(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(status IN ('confirmed','postponed')
                AND (COALESCE(postponed_date, booking_date) > CURDATE()
                     OR (COALESCE(postponed_date, booking_date) = CURDATE()
                         AND COALESCE(postponed_end_time, end_time) >= CURTIME()))), 0) AS upcoming,
            COALESCE(SUM(status = 'confirmed'
                AND (booking_date < CURDATE()
                     OR (booking_date = CURDATE() AND end_time < CURTIME()))), 0) AS completed,
            COALESCE(SUM(status = 'cancelled'), 0) AS cancelled
     FROM bookings WHERE user_id = ?"
);
$stats_stmt->bind_param("i", $me_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();

// Kikao kijacho
$next_stmt = $conn->prepare(
    "SELECT b.meeting_title, r.room_name,
            COALESCE(b.postponed_date, b.booking_date) AS day,
            COALESCE(b.postponed_start_time, b.start_time) AS begins,
            COALESCE(b.postponed_end_time, b.end_time) AS ends
     FROM bookings b
     JOIN rooms r ON r.id = b.room_id
     WHERE b.user_id = ? AND b.status IN ('confirmed','postponed')
       AND (COALESCE(b.postponed_date, b.booking_date) > CURDATE()
            OR (COALESCE(b.postponed_date, b.booking_date) = CURDATE()
                AND COALESCE(b.postponed_end_time, b.end_time) >= CURTIME()))
     ORDER BY day ASC, begins ASC
     LIMIT 1"
);
$next_stmt->bind_param("i", $me_id);
$next_stmt->execute();
$next = $next_stmt->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile</title>
    <script>(function(){try{var t=localStorage.getItem('mrs_theme');if(t!=='light'&&t!=='dark'){t=(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';}document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
    <link rel="stylesheet" href="../assets/css/style.css?v=49">
</head>
<body>
    <?php require __DIR__ . '/../includes/navbar.php'; ?>
    <script src="../assets/js/theme.js?v=43"></script>
    <script src="../assets/js/nav.js?v=43"></script>
    <script src="../assets/js/ui.js?v=50"></script>
    <script src="../assets/js/icons.js?v=43"></script>

    <div class="container">
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <h2>My Profile</h2>
        <br>
        <div class="card profile-card">
            <div class="profile-card-head">
                <div class="profile-info">
                    <p><strong>Full Name:</strong> <?php echo htmlspecialchars($me['full_name']); ?></p>
                    <p><strong>Email:</strong> <?php echo htmlspecialchars($me['email']); ?></p>
                    <p><strong>Role:</strong> <?php echo ucfirst($me['role']); ?></p>
                    <p><strong>Status:</strong>
                        <?php
                        $st_class = $me['status'] === 'active' ? 'status-available'
                                  : ($me['status'] === 'pending' ? 'status-pending' : 'status-maintenance');
                        ?>
                        <span class="status-tag <?php echo $st_class; ?>"><?php echo ucfirst($me['status']); ?></span>
                    </p>
                    <p><strong>Member Since:</strong> <?php echo date('d/m/Y', strtotime($me['created_at'])); ?></p>
                </div>
                <div class="mrs-kebab">
                    <button type="button" class="mrs-kebab-toggle" aria-label="Open profile actions" aria-haspopup="true">&#8942;</button>
                    <div class="mrs-kebab-menu">
                        <button type="button" onclick="mrsEditProfile()">Edit Profile</button>
                        <button type="button" onclick="mrsChangePassword()">Change Password</button>
                    </div>
                </div>
            </div>
        </div>

        <h2>My Activity</h2>
        <br>
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo (int)$stats['total']; ?></div>
                <div class="stat-label">Total Bookings</div>
            </div>
            <div class="stat-card stat-ok">
                <div class="stat-value"><?php echo (int)$stats['upcoming']; ?></div>
                <div class="stat-label">Upcoming</div>
            </div>
            <div class="stat-card stat-accent">
                <div class="stat-value"><?php echo (int)$stats['completed']; ?></div>
                <div class="stat-label">Completed</div>
            </div>
            <div class="stat-card stat-danger">
                <div class="stat-value"><?php echo (int)$stats['cancelled']; ?></div>
                <div class="stat-label">Cancelled</div>
            </div>
        </div>

        <?php if ($next): ?>
        <div class="card">
            <p><strong>Next Meeting:</strong> <?php echo htmlspecialchars($next['meeting_title']); ?></p>
            <p><strong>Room:</strong> <?php echo htmlspecialchars($next['room_name']); ?></p>
            <p><strong>When:</strong>
                <?php echo date('d/m/Y', strtotime($next['day'])); ?>,
                <?php echo date('H:i', strtotime($next['begins'])); ?>â€“<?php echo date('H:i', strtotime($next['ends'])); ?>
            </p>
            <br>
            <a href="my_bookings.php" class="btn btn-secondary">View My Bookings</a>
        </div>
        <?php endif; ?>
    </div>

    <script>
    (function () {
        'use strict';

        var ME = {
            id: <?php echo (int)$me['id']; ?>,
            name: <?php echo json_encode($me['full_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            email: <?php echo json_encode($me['email'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
        };

        function esc(value) {
            return String(value).replace(/[&<>"']/g, function (char) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char];
            });
        }

        function csrfInput() {
            return '<input type="hidden" name="csrf_token" value="' + esc(window.MRS_CSRF || '') + '">';
        }

        function closeProfileKebab() {
            document.querySelectorAll('.mrs-kebab-menu.open').forEach(function (menu) {
                menu.classList.remove('open');
            });
        }

        function actionsRow(submitLabel) {
            return '<div class="mrs-modal-actions">' +
                        '<button type="button" class="btn btn-secondary" data-modal-cancel>Cancel</button>' +
                        '<button type="submit" class="btn">' + submitLabel + '</button>' +
                    '</div>';
        }

        // Fomu hutengenezwa na JS: ongeza thibitisho kabla ya kutuma.
        // Kumbuka: openModal hufunga kabla ya onConfirm, hivyo fomu huondolewa
        // DOM â€” tuirudishe kwenye <div> ya sirini ili browser isubmit.
        function confirmSubmit(form, opts) {
            if (!form) return;
            form.addEventListener('submit', function (event) {
                if (form.dataset.mrsSubmitting === '1') return;
                event.preventDefault();
                window.mrsModal({
                    title: opts.title,
                    message: opts.message,
                    confirmText: opts.confirmText,
                    onConfirm: function () {
                        form.dataset.mrsSubmitting = '1';
                        if (!form.isConnected) {
                            var holder = document.createElement('div');
                            holder.style.display = 'none';
                            document.body.appendChild(holder);
                            holder.appendChild(form);
                        }
                        form.submit();
                    }
                });
            });
        }

        /* 1. EDIT PROFILE â€” jina + email (email lazima iwe @nishati.go.tz) */
        window.mrsEditProfile = function () {
            closeProfileKebab();
            window.mrsFormModal({
                title: 'Edit Profile',
                html:
                    '<form method="POST" action="profile.php">' +
                        csrfInput() +
                        '<input type="hidden" name="update_profile" value="1">' +
                        '<label>Full Name</label>' +
                        '<input type="text" name="full_name" value="' + esc(ME.name) + '" required maxlength="100">' +
                        '<label>Email</label>' +
                        '<input type="email" name="email" value="' + esc(ME.email) + '" required maxlength="100"' +
                            ' pattern="[^@\\s]+@nishati\\.go\\.tz" title="Email must end with @nishati.go.tz">' +
                        '<span class="field-hint">Only <strong>@nishati.go.tz</strong> addresses are accepted.</span>' +
                        actionsRow('Save Changes') +
                    '</form>',
                onOpen: function (overlay) {
                    confirmSubmit(overlay.querySelector('form'), {
                        title: 'Save Changes',
                        message: 'Save these profile changes?',
                        confirmText: 'Save Changes'
                    });
                }
            });
        };

        /* 2. CHANGE PASSWORD â€” lazima ujue password ya sasa */
        window.mrsChangePassword = function () {
            closeProfileKebab();
            window.mrsFormModal({
                title: 'Change Password',
                html:
                    '<form method="POST" action="profile.php">' +
                        csrfInput() +
                        '<input type="hidden" name="change_password" value="1">' +
                        '<label>Current Password</label>' +
                        '<div class="password-wrapper">' +
                            '<input type="password" name="current_password" id="profCurPw" required>' +
                            '<button type="button" class="password-toggle" onclick="togglePassword(\'profCurPw\', this)">Show</button>' +
                        '</div>' +
                        '<label>New Password</label>' +
                        '<div class="password-wrapper">' +
                            '<input type="password" name="new_password" id="profNewPw" required minlength="8"' +
                                ' placeholder="At least 8 characters">' +
                            '<button type="button" class="password-toggle" onclick="togglePassword(\'profNewPw\', this)">Show</button>' +
                        '</div>' +
                        '<span class="field-hint">At least 8 characters with an uppercase letter, a lowercase letter, a number and a special character.</span>' +
                        actionsRow('Save New Password') +
                    '</form>',
                onOpen: function (overlay) {
                    confirmSubmit(overlay.querySelector('form'), {
                        title: 'Change Password',
                        message: 'Save this new password for your account?',
                        confirmText: 'Save Password'
                    });
                }
            });
        };
    })();
    </script>
</body>
</html>
