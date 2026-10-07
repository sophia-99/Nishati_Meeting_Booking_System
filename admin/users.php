<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require __DIR__ . '/../includes/audit.php';
check_login();
check_admin('users.view');
require_permission('users.view');

$flash = flash_pull();
$message = $flash['success'];
$error = $flash['error'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    csrf_verify('USER_ADD');
    require_permission('users.create', 'users.php');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = in_array($_POST['role'] ?? 'staff', ['admin', 'staff'], true) ? $_POST['role'] : 'staff';
    $policy_error = password_policy_error($password);

    if ($full_name === '' || $email === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } elseif (!valid_email($email)) {
        $error = 'Please enter a valid email address.';
    } elseif (!is_official_email($email)) {
        $error = 'Only official email addresses are accepted. Email must end with '
               . official_email_domain() . '.';
    } elseif ($policy_error !== '') {
        $error = $policy_error;
    } else {
        $check = $conn->prepare('SELECT id FROM users WHERE email = ?');
        $check->bind_param('s', $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = 'This email address is already registered.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare(
                "INSERT INTO users (full_name, email, password, role, status)
                 VALUES (?, ?, ?, ?, 'active')"
            );
            $stmt->bind_param('ssss', $full_name, $email, $hash, $role);
            $stmt->execute();
            audit_log('USER_ADDED', 'user', $conn->insert_id,
                      "$full_name <$email> | role=$role | status=active (created by admin)");
            flash_set('success', 'New user added successfully.');
            header('Location: users.php');
            exit();
        }
    }
}

if (isset($_GET['toggle_status'])) {
    csrf_verify('USER_STATUS_CHANGE');
    require_permission('users.toggle_status', 'users.php');
    $target_id = (int)$_GET['toggle_status'];
    if ($target_id !== (int)$_SESSION['user_id']) {
        $stmt = $conn->prepare("UPDATE users SET status = IF(status='active','inactive','active') WHERE id = ?");
        $stmt->bind_param('i', $target_id);
        $stmt->execute();
        audit_log('USER_STATUS_CHANGED', 'user', $target_id, 'Toggled active/inactive status');
        flash_set('success', 'User status updated successfully.');
    } else {
        flash_set('error', 'You cannot change your own account status.');
    }
    header('Location: users.php');
    exit();
}

if (isset($_GET['delete_user'])) {
    csrf_verify('USER_DELETE');
    require_permission('users.delete', 'users.php');
    $target_id = (int)$_GET['delete_user'];
    if ($target_id === (int)($_SESSION['user_id'] ?? 0)) {
        flash_set('error', 'You cannot delete your own account.');
    } else {
        $stmt = $conn->prepare('SELECT full_name, email, role, status FROM users WHERE id = ?');
        $stmt->bind_param('i', $target_id);
        $stmt->execute();
        $victim = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$victim) {
            flash_set('error', 'User not found.');
        } elseif (is_last_active_admin($conn, $target_id)) {
            flash_set('error', 'Cannot delete the last active administrator.');
        } else {
            $count = $conn->prepare('SELECT COUNT(*) AS c FROM bookings WHERE user_id = ?');
            $count->bind_param('i', $target_id);
            $count->execute();
            $booking_count = (int)$count->get_result()->fetch_assoc()['c'];
            $count->close();

            $delete = $conn->prepare('DELETE FROM users WHERE id = ?');
            $delete->bind_param('i', $target_id);
            $delete->execute();
            $delete->close();

            audit_log('USER_DELETED', 'user', $target_id,
                      $victim['full_name'] . ' <' . $victim['email'] . '> | role=' . $victim['role']
                      . ' | status=' . $victim['status'] . " | bookings=$booking_count (deleted by admin)");
            flash_set('success', 'User deleted successfully.');
        }
    }
    header('Location: users.php');
    exit();
}

$users = $conn->query(
    'SELECT u.id, u.full_name, u.email, u.role, u.status, u.created_at,
            (SELECT COUNT(*) FROM bookings b WHERE b.user_id = u.id) AS bookings_count
     FROM users u ORDER BY u.created_at DESC'
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Admin Panel</title>
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
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['password_reset'])): ?>
            <div class="alert alert-success">Password updated successfully.</div>
        <?php endif; ?>
        <?php if (isset($_GET['password_policy'])): ?>
            <div class="alert alert-error">The new password does not meet the policy: use at least 8 characters, including a letter, a number, and a special character.</div>
        <?php endif; ?>

        <?php if (has_permission($_SESSION['role'] ?? '', 'users.create')): ?>
            <section class="card">
                <h3>Add New User</h3>
                <br>
                <form method="POST" data-confirm="Create this user account?" data-confirm-title="Add New User" data-confirm-text="Add User">
                    <?php echo csrf_field(); ?>
                    <label for="new_full_name">Full Name</label>
                    <input id="new_full_name" type="text" name="full_name" required maxlength="100">

                    <label for="new_email">Email</label>
                    <input id="new_email" type="email" name="email" required maxlength="100"
                           placeholder="name@nishati.go.tz" pattern="[^@]+@nishati\.go\.tz"
                           title="Email must end with @nishati.go.tz">
                    <span class="field-hint">Only <strong>@nishati.go.tz</strong> addresses are accepted. Accounts created here are activated immediately without email verification.</span>

                    <label for="new_user_password">Password</label>
                    <div class="password-wrapper">
                        <input type="password" name="password" id="new_user_password" placeholder="At least 8 characters" required minlength="8">
                        <button type="button" class="password-toggle" onclick="togglePassword('new_user_password', this)">Show</button>
                    </div>

                    <label for="new_user_role">Role</label>
                    <select id="new_user_role" name="role">
                        <option value="staff">Staff</option>
                        <option value="admin">Admin</option>
                    </select>

                    <button type="submit" name="add_user" value="1" class="btn">Add User</button>
                </form>
            </section>
        <?php endif; ?>

        <h2>User Management</h2>
        <br>
        <section class="card">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Registered</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($user = $users->fetch_assoc()): ?>
                        <?php
                            $status = $user['status'];
                            $status_class = $status === 'active' ? 'status-available'
                                : ($status === 'pending' ? 'status-pending' : 'status-maintenance');
                            $display_date = date('d/m/Y', strtotime($user['created_at']));
                        ?>
                        <tr data-user-id="<?php echo (int)$user['id']; ?>"
                            data-name="<?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-email="<?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-role="<?php echo htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>"
                            data-created="<?php echo htmlspecialchars($display_date, ENT_QUOTES, 'UTF-8'); ?>"
                            data-bookings="<?php echo (int)$user['bookings_count']; ?>">
                            <td><?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars(ucfirst($user['role']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($display_date, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="status-tag <?php echo $status_class; ?>"><?php echo htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td>
                                <div class="action-buttons">
                                    <div class="mrs-kebab">
                                        <button type="button" class="mrs-kebab-toggle" aria-label="Open user actions" aria-haspopup="true" aria-expanded="false">&#8942;</button>
                                        <div class="mrs-kebab-menu">
                                            <button type="button" class="view-user" data-id="<?php echo (int)$user['id']; ?>">View</button>
                                            <?php if ((int)$user['id'] !== (int)$_SESSION['user_id']): ?>
                                                <?php if (has_permission($_SESSION['role'] ?? '', 'users.toggle_status')): ?>
                                                    <a href="<?php echo htmlspecialchars(csrf_url('users.php?toggle_status=' . (int)$user['id']), ENT_QUOTES, 'UTF-8'); ?>"
                                                       data-confirm="<?php echo $status === 'active' ? 'Deactivate' : 'Activate'; ?> this account?"
                                                       data-confirm-title="<?php echo $status === 'active' ? 'Deactivate Account?' : 'Activate Account?'; ?>"
                                                       data-confirm-text="<?php echo $status === 'active' ? 'Deactivate' : 'Activate'; ?>"
                                                       data-confirm-danger="<?php echo $status === 'active' ? '1' : '0'; ?>">
                                                        <?php echo $status === 'active' ? 'Deactivate' : 'Activate'; ?>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if (has_permission($_SESSION['role'] ?? '', 'users.delete')): ?>
                                                    <a class="danger-item"
                                                       href="<?php echo htmlspecialchars(csrf_url('users.php?delete_user=' . (int)$user['id']), ENT_QUOTES, 'UTF-8'); ?>"
                                                       data-confirm="Delete <?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?>? This permanently removes the account<?php echo (int)$user['bookings_count'] > 0 ? ', including ' . (int)$user['bookings_count'] . ' booking(s) and reports' : ''; ?>."
                                                       data-confirm-title="Delete User?"
                                                       data-confirm-text="Delete"
                                                       data-confirm-danger="1">Delete</a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <script>
    (function () {
        'use strict';

        var selectedUser = null;

        function escapeHtml(value) {
            return String(value).replace(/[&<>"']/g, function (char) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char];
            });
        }

        function csrfField() {
            return '<input type="hidden" name="csrf_token" value="' + escapeHtml(window.MRS_CSRF || '') + '">';
        }

        function modalActions(label) {
            return '<div class="mrs-modal-actions">' +
                '<button type="button" class="btn btn-secondary" data-modal-cancel>Cancel</button>' +
                '<button type="submit" class="btn">' + escapeHtml(label) + '</button>' +
                '</div>';
        }

        function confirmForm(form, options) {
            if (!form) return;
            form.addEventListener('submit', function (event) {
                if (form.dataset.submitting === '1') return;
                event.preventDefault();
                window.mrsModal({
                    title: options.title,
                    message: options.message,
                    confirmText: options.confirmText,
                    onConfirm: function () {
                        form.dataset.submitting = '1';
                        if (!form.isConnected) {
                            var holder = document.createElement('div');
                            holder.hidden = true;
                            document.body.appendChild(holder);
                            holder.appendChild(form);
                        }
                        HTMLFormElement.prototype.submit.call(form);
                    }
                });
            });
        }

        function userFromRow(row) {
            if (!row) return null;
            return {
                id: row.getAttribute('data-user-id'),
                name: row.getAttribute('data-name'),
                email: row.getAttribute('data-email'),
                role: row.getAttribute('data-role'),
                status: row.getAttribute('data-status'),
                created: row.getAttribute('data-created'),
                bookings: row.getAttribute('data-bookings')
            };
        }

        function findUser(id) {
            var rows = document.querySelectorAll('tr[data-user-id]');
            for (var i = 0; i < rows.length; i++) {
                if (rows[i].getAttribute('data-user-id') === String(id)) return userFromRow(rows[i]);
            }
            return null;
        }

        function showUserAfterModalCloses(user) {
            selectedUser = user;
            if (!document.querySelector('.mrs-modal-overlay')) {
                window.showUserDetails();
                return;
            }
            var tries = 0;
            var timer = window.setInterval(function () {
                tries++;
                if (!document.querySelector('.mrs-modal-overlay') || tries > 480) {
                    window.clearInterval(timer);
                    window.showUserDetails();
                }
            }, 250);
        }

        window.showUserDetails = function () {
            var user = selectedUser;
            if (!user) return;
            window.mrsFormModal({
                title: 'User Details',
                html:
                    '<div class="user-details">' +
                        '<p><strong>Full Name:</strong> ' + escapeHtml(user.name) + '</p>' +
                        '<p><strong>Email:</strong> ' + escapeHtml(user.email) + '</p>' +
                        '<p><strong>Role:</strong> ' + escapeHtml(user.role.charAt(0).toUpperCase() + user.role.slice(1)) + '</p>' +
                        '<p><strong>Status:</strong> ' + escapeHtml(user.status.charAt(0).toUpperCase() + user.status.slice(1)) + '</p>' +
                        '<p><strong>Registered On:</strong> ' + escapeHtml(user.created) + '</p>' +
                        '<p><strong>Total Bookings:</strong> ' + escapeHtml(user.bookings) + '</p>' +
                    '</div>' +
                    '<div class="mrs-modal-actions">' +
                        '<button type="button" class="btn btn-secondary" data-modal-cancel>Close</button>' +
                        '<button type="button" class="btn btn-secondary" onclick="editSelectedUser()">Edit User</button>' +
                        '<button type="button" class="btn" onclick="resetSelectedUserPassword()">Reset Password</button>' +
                    '</div>'
            });
        };

        window.editSelectedUser = function () {
            var user = selectedUser;
            if (!user) return;
            window.mrsFormModal({
                title: 'Edit User',
                html:
                    '<form method="POST" action="edit_user.php">' +
                        csrfField() +
                        '<input type="hidden" name="user_id" value="' + escapeHtml(user.id) + '">' +
                        '<input type="hidden" name="return_to" value="1">' +
                        '<input type="hidden" name="update_user" value="1">' +
                        '<label for="modal_full_name">Full Name</label>' +
                        '<input id="modal_full_name" type="text" name="full_name" value="' + escapeHtml(user.name) + '" required maxlength="100">' +
                        '<label for="modal_email">Email</label>' +
                        '<input id="modal_email" type="email" name="email" value="' + escapeHtml(user.email) + '" required maxlength="100" pattern="[^@]+@nishati\\.go\\.tz" title="Email must end with @nishati.go.tz">' +
                        '<span class="field-hint">Only <strong>@nishati.go.tz</strong> addresses are accepted.</span>' +
                        '<label for="modal_role">Role</label>' +
                        '<select id="modal_role" name="role">' +
                            '<option value="staff"' + (user.role === 'staff' ? ' selected' : '') + '>Staff</option>' +
                            '<option value="admin"' + (user.role === 'admin' ? ' selected' : '') + '>Admin</option>' +
                        '</select>' +
                        modalActions('Save Changes') +
                    '</form>',
                onOpen: function (overlay) {
                    confirmForm(overlay.querySelector('form'), {
                        title: 'Save Changes',
                        message: 'Save these user account changes?',
                        confirmText: 'Save Changes'
                    });
                }
            });
        };

        window.resetSelectedUserPassword = function () {
            var user = selectedUser;
            if (!user) return;
            window.mrsFormModal({
                title: 'Reset Password',
                html:
                    '<form method="POST" action="reset_user_password.php">' +
                        csrfField() +
                        '<input type="hidden" name="user_id" value="' + escapeHtml(user.id) + '">' +
                        '<label for="modal_new_password">New password for ' + escapeHtml(user.name) + '</label>' +
                        '<div class="password-wrapper">' +
                            '<input type="password" name="new_password" id="modal_new_password" placeholder="At least 8 characters" required minlength="8">' +
                            '<button type="button" class="password-toggle" onclick="togglePassword(\'modal_new_password\', this)">Show</button>' +
                        '</div>' +
                        modalActions('Save New Password') +
                    '</form>',
                onOpen: function (overlay) {
                    confirmForm(overlay.querySelector('form'), {
                        title: 'Reset Password',
                        message: 'Save this new password for the user?',
                        confirmText: 'Save Password'
                    });
                }
            });
        };

        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('.view-user');
            if (!trigger) return;
            event.preventDefault();
            var user = findUser(trigger.getAttribute('data-id'));
            if (user) showUserAfterModalCloses(user);
        }, true);

        document.addEventListener('DOMContentLoaded', function () {
            var match = /[?&]view=(\d+)/.exec(window.location.search);
            if (!match) return;
            var user = findUser(match[1]);
            if (user) showUserAfterModalCloses(user);
        });
    })();
    </script>
</body>
</html>
