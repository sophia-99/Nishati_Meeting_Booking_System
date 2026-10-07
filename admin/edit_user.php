<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require __DIR__ . '/../includes/audit.php';
check_login();
check_admin('users.edit');
require_permission('users.edit');

$user_id = (int)($_GET['id'] ?? ($_POST['user_id'] ?? 0));
$error = '';
$message = '';
$flash = flash_pull();
if ($flash['success'] !== '') $message = $flash['success'];
if ($flash['error'] !== '') $error = $flash['error'];

$stmt = $conn->prepare('SELECT id, full_name, email, role FROM users WHERE id = ?');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    header('Location: users.php');
    exit();
}
$user = $result->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    csrf_verify('USER_EDIT');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = in_array($_POST['role'] ?? 'staff', ['admin', 'staff'], true) ? $_POST['role'] : 'staff';

    if ($full_name === '' || $email === '') {
        $error = 'Please fill in all fields.';
    } elseif (mb_strlen($full_name) > 100) {
        $error = 'Full name is too long (maximum 100 characters).';
    } elseif (!valid_email($email)) {
        $error = 'Please enter a valid email address.';
    } elseif ($role !== $user['role'] && (int)($_SESSION['user_id'] ?? 0) === $user_id) {
        $error = 'You cannot change your own role. Ask another administrator to do it.';
    } elseif (role_admin_demote_blocked($conn, $user_id, $role)) {
        $error = 'This is the last active administrator. Create another administrator before changing this role.';
    }

    if ($error === '') {
        if (!is_official_email($email)) {
            $error = 'Only official email addresses are accepted. Email must end with '
                   . official_email_domain() . '.';
        } else {
            $check = $conn->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
            $check->bind_param('si', $email, $user_id);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                $error = 'That email address is already used by another account.';
            } else {
                $update = $conn->prepare('UPDATE users SET full_name = ?, email = ?, role = ? WHERE id = ?');
                $update->bind_param('sssi', $full_name, $email, $role, $user_id);
                $update->execute();
                audit_log('USER_EDITED', 'user', $user_id, "$full_name <$email> | role=$role");

                $message = 'User details updated successfully.';
                $user['full_name'] = $full_name;
                $user['email'] = $email;
                $user['role'] = $role;

                if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $user_id) {
                    $_SESSION['role'] = $role;
                    $_SESSION['full_name'] = $full_name;
                    $_SESSION['email'] = $email;
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    if ($error !== '') {
        flash_set('error', $error);
    } elseif ($message !== '') {
        flash_set('success', $message);
    }
    $destination = (isset($_POST['return_to']) && (int)$_POST['return_to'] === 1)
        ? 'users.php?view=' . $user_id
        : 'edit_user.php?id=' . $user_id;
    header('Location: ' . $destination);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - Admin Panel</title>
    <script>
    (function () {
        try {
            var theme = localStorage.getItem('mrs_theme');
            if (theme !== 'light' && theme !== 'dark') {
                theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-theme', theme);
        } catch (error) {
            document.documentElement.setAttribute('data-theme', 'light');
        }
    })();
    </script>
    <link rel="stylesheet" href="../assets/css/style.css?v=49">
</head>
<body>
    <?php require __DIR__ . '/../includes/navbar.php'; ?>
    <script src="../assets/js/theme.js?v=43"></script>
    <script src="../assets/js/nav.js?v=43"></script>
    <script src="../assets/js/ui.js?v=50"></script>
    <script src="../assets/js/icons.js?v=43"></script>

    <main class="container">
        <h2>Edit User</h2>
        <br>
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <div class="card">
            <form method="POST" data-confirm="Save these user account changes?" data-confirm-title="Save Changes" data-confirm-text="Save Changes">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>">

                <label for="full_name">Full Name</label>
                <input id="full_name" type="text" name="full_name" value="<?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?>" required maxlength="100">

                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="<?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?>" required maxlength="100"
                       pattern="[^@]+@nishati\.go\.tz" title="Email must end with @nishati.go.tz">
                <span class="field-hint">Only <strong>@nishati.go.tz</strong> addresses are accepted.</span>

                <label for="role">Role</label>
                <select id="role" name="role">
                    <option value="staff" <?php echo $user['role'] === 'staff' ? 'selected' : ''; ?>>Staff</option>
                    <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                </select>

                <button type="submit" name="update_user" value="1" class="btn">Save Changes</button>
            </form>
            <br>
            <a href="users.php" class="btn">Back to User Management</a>
        </div>
    </main>
</body>
</html>
