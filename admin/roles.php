<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require __DIR__ . '/../includes/audit.php';
check_login();
check_admin('roles.view');
require_permission('roles.view');

$flash = flash_pull();
$message = $flash['success'];
$error = $flash['error'];
$roles = ['admin', 'staff'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_permissions'])) {
    csrf_verify('ROLES_SAVE');
    require_permission('roles.edit', 'roles.php');

    permissions_ensure_schema();
    $rows = permissions_rows();
    $posted = $_POST['permissions'] ?? [];
    $current = [];
    foreach ($roles as $role) {
        $map = permissions_allowed_map($role);
        $current[$role] = is_array($map) ? $map : [];
    }

    $upsert = $conn->prepare(
        'INSERT INTO role_permissions (role, permission_id, allowed)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)'
    );
    $changes = ['admin' => [], 'staff' => []];
    $total_changes = 0;

    foreach ($rows as $row) {
        $permission_id = (int)$row['id'];
        $code = $row['activity_code'];
        $staff_locked = (int)(permissions_registry_map()[$code]['lock_staff'] ?? 0);
        foreach ($roles as $role) {
            $new_value = !empty($posted[$permission_id][$role]) ? 1 : 0;
            if ($role === 'admin') {
                $new_value = 1;
            }
            if ($role === 'staff' && $staff_locked) {
                $new_value = 0;
            }
            $old_value = isset($current[$role][$code])
                ? (int)$current[$role][$code]
                : (permissions_default($code, $role) ? 1 : 0);

            if ($old_value !== $new_value) {
                $changes[$role][] = $code . '=' . ($new_value ? 'on' : 'off');
                $total_changes++;
            }
            $upsert->bind_param('sii', $role, $permission_id, $new_value);
            $upsert->execute();
        }
    }
    $upsert->close();

    if ($total_changes > 0) {
        $summary = [];
        foreach ($roles as $role) {
            $role_changes = $changes[$role];
            if ($role_changes) {
                $shown = array_slice($role_changes, 0, 8);
                $remaining = count($role_changes) - count($shown);
                $summary[] = $role . ': ' . count($role_changes) . ' change(s) ['
                    . implode(', ', $shown)
                    . ($remaining > 0 ? ", +$remaining more" : '') . ']';
            }
        }
        audit_log('PERMISSIONS_UPDATED', 'role', null, implode(' | ', $summary));
        flash_set('success', "Permissions updated — $total_changes changes applied.");
    } else {
        flash_set('success', 'No changes to save.');
    }
    header('Location: roles.php');
    exit();
}

$rows = permissions_rows();
$can_edit_roles = has_permission($_SESSION['role'] ?? '', 'roles.edit');
$allowed = [];
foreach ($roles as $role) {
    $map = permissions_allowed_map($role);
    $allowed[$role] = is_array($map) ? $map : [];
}
$effective = function ($role, $code, $default_admin, $default_staff) use ($allowed) {
    if (isset($allowed[$role][$code])) {
        return (int)$allowed[$role][$code];
    }
    return $role === 'admin' ? (int)$default_admin : (int)$default_staff;
};

function permission_display_group($code) {
    $prefix = explode('.', $code)[0];
    $module_map = [
        'auth' => 'CORE', 'profile' => 'CORE',
        'rooms' => 'MEETING ROOMS', 'issues' => 'MEETING ROOMS',
        'bookings' => 'BOOKINGS', 'book' => 'BOOKINGS',
        'schedule' => 'SCHEDULE', 'projectors' => 'PROJECTORS',
        'users' => 'USERS', 'reports' => 'REPORTS', 'audit' => 'AUDIT LOG',
        'mail' => 'MAIL', 'roles' => 'ROLES', 'admin' => 'DASHBOARD',
    ];
    $module = $module_map[$prefix] ?? 'OTHER';
    $submodule_map = [
        'auth' => 'Session', 'profile' => 'Profile',
        'rooms' => 'Rooms', 'issues' => 'Issues',
        'bookings' => 'Bookings', 'book' => 'Rooms',
        'schedule' => 'Calendar', 'projectors' => 'Inventory',
        'users' => 'Users', 'reports' => 'Reports', 'audit' => 'Log',
        'mail' => 'Configuration', 'roles' => 'Permissions', 'admin' => 'Admin',
    ];
    return [$module, $submodule_map[$prefix] ?? 'General'];
}

function permission_display_label($code) {
    $labels = [
        'auth.login' => 'Can log in to the system',
        'auth.logout' => 'Can log out of the system',
        'auth.reset_password' => 'Can reset a password by email',
        'profile.view' => 'Can view own profile',
        'profile.edit' => 'Can edit own profile',
        'profile.change_password' => 'Can change own password',
        'rooms.view' => 'Can view the meeting room list',
        'rooms.manage' => 'Can add, edit, and delete rooms',
        'rooms.toggle_status' => 'Can activate or deactivate rooms',
        'issues.resolve' => 'Can resolve reported room issues',
        'issues.delete' => 'Can delete resolved room issues',
        'bookings.create' => 'Can book a meeting room',
        'bookings.view_own' => 'Can view own bookings',
        'bookings.cancel_own' => 'Can cancel own bookings',
        'bookings.postpone_own' => 'Can postpone own bookings',
        'bookings.delete_own' => 'Can delete own completed bookings',
        'bookings.report_issue' => 'Can report a room issue',
        'bookings.manage_all' => 'Can cancel, postpone, or delete any booking',
        'schedule.view' => 'Can view the room schedule',
        'schedule.view_all' => 'Can view all users’ meetings on the schedule',
        'projectors.view' => 'Can view projector inventory',
        'projectors.manage' => 'Can add, edit, and delete projectors',
        'users.view' => 'Can view the user list',
        'users.create' => 'Can create users',
        'users.edit' => 'Can edit user details',
        'users.toggle_status' => 'Can activate or deactivate users',
        'users.reset_password' => 'Can reset user passwords',
        'users.delete' => 'Can delete users',
        'reports.view' => 'Can view reports and statistics',
        'reports.export' => 'Can export reports',
        'audit.view' => 'Can view audit log entries',
        'audit.clear' => 'Can clear audit log entries',
        'mail.view' => 'Can view mail configuration',
        'mail.edit' => 'Can save and test mail configuration',
        'roles.view' => 'Can view roles and permissions',
        'roles.edit' => 'Can edit role permissions',
        'admin.dashboard' => 'Can view the admin dashboard',
    ];
    if (isset($labels[$code])) {
        return $labels[$code];
    }
    if (strpos($code, 'book.room.') === 0) {
        return 'Can book room ' . substr($code, strlen('book.room.'));
    }
    return 'Can ' . ucwords(str_replace(['.', '_'], ' ', $code));
}

$groups = [];
foreach ($rows as $row) {
    $code = $row['activity_code'];
    [$module, $submodule] = permission_display_group($code);
    $key = $module . '|' . $submodule;
    if (!isset($groups[$key])) {
        $groups[$key] = ['module' => $module, 'submodule' => $submodule, 'rows' => []];
    }
    $groups[$key]['rows'][] = $row;
}
$group_number = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roles &amp; Permissions - Admin Panel</title>
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

        <h2>Roles &amp; Permissions</h2>
        <p style="margin:-8px 0 15px; color:var(--text); font-size:14px;">
            Grant or revoke system permissions for each role. Changes apply immediately. Staff members need
            <strong>Can book a meeting room</strong> permission to make bookings.
            <span class="perm-lock-note">
                <svg class="perm-lock-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="4" y="10.5" width="16" height="10.5" rx="2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>
                </svg>
                The Admin column is read-only: administrators retain full access for system security. Only Staff permissions can be changed.
            </span>
        </p>

        <section class="card">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="table-scroll perm-scroll">
                    <table class="perm-table">
                        <thead>
                            <tr>
                                <th class="perm-sn">No.</th>
                                <th>Module</th>
                                <th>Area</th>
                                <th class="perm-activity">Permission</th>
                                <th class="perm-role-col">
                                    <label class="perm-col-head perm-col-locked" title="Admin permissions are locked for system security">
                                        <svg class="perm-role-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/>
                                            <path d="M9.5 12l1.8 1.8L15 10"/>
                                        </svg>
                                        <span>Admin</span>
                                        <svg class="perm-lock perm-col-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <rect x="4" y="10.5" width="16" height="10.5" rx="2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>
                                        </svg>
                                    </label>
                                </th>
                                <th class="perm-role-col">
                                    <label class="perm-col-head">
                                        <svg class="perm-role-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <circle cx="9" cy="8" r="3.5"/>
                                            <path d="M2.5 19c.8-3 3.4-5 6.5-5s5.7 2 6.5 5"/>
                                            <circle cx="17" cy="9" r="2.5"/>
                                            <path d="M16 14.5c2.4.3 4.3 1.9 5 4.5"/>
                                        </svg>
                                        <span>Staff</span>
                                        <input type="checkbox" class="perm-check perm-col-toggle" data-role="staff" title="Toggle all Staff permissions">
                                    </label>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php $number = 0; foreach ($groups as $group): $group_number++; ?>
                            <?php $group_rows = $group['rows']; $group_count = count($group_rows); ?>
                            <?php foreach ($group_rows as $index => $row): $number++; ?>
                                <?php
                                    $code = $row['activity_code'];
                                    $staff_locked = !empty(permissions_registry_map()[$code]['lock_staff']);
                                    $stripe = 'perm-tint-' . ((($group_number - 1) % 9) + 1);
                                ?>
                                <tr>
                                    <td class="perm-sn <?php echo $stripe; ?>"><?php echo $number; ?></td>
                                    <?php if ($index === 0): ?>
                                        <td class="perm-mod <?php echo $stripe; ?>" rowspan="<?php echo $group_count; ?>"><?php echo htmlspecialchars($group['module'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php endif; ?>
                                    <?php if ($index === 0): ?>
                                        <td class="perm-sub <?php echo $stripe; ?>" rowspan="<?php echo $group_count; ?>"><?php echo htmlspecialchars($group['submodule'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php endif; ?>
                                    <td class="perm-activity"><?php echo htmlspecialchars(permission_display_label($code), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="perm-cell">
                                        <input type="checkbox" class="perm-check"
                                               name="permissions[<?php echo (int)$row['id']; ?>][admin]" value="1"
                                               data-role="admin" checked disabled
                                               title="Locked: administrators always have full access">
                                    </td>
                                    <td class="perm-cell">
                                        <?php $staff_value = $effective('staff', $code, $row['default_admin'], $row['default_staff']); ?>
                                        <input type="checkbox" class="perm-check"
                                               name="permissions[<?php echo (int)$row['id']; ?>][staff]" value="1"
                                               data-role="staff"
                                               <?php echo $staff_value && !$staff_locked ? 'checked' : ''; ?>
                                               <?php if (!$can_edit_roles || $staff_locked): ?>disabled<?php endif; ?>
                                               <?php if ($staff_locked): ?>title="Locked: Staff cannot have this permission"<?php elseif (!$can_edit_roles): ?>title="View-only access"<?php endif; ?>>
                                        <?php if ($staff_locked): ?>
                                            <svg class="perm-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <rect x="4" y="10.5" width="16" height="10.5" rx="2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>
                                            </svg>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="perm-footer">
                    <?php if ($can_edit_roles): ?>
                        <span class="perm-hint">Select the permissions each role can use, then save. Changes take effect immediately for every user with that role.</span>
                        <button type="submit" name="save_permissions" value="1" class="btn"
                                data-confirm="Save these permission changes? They apply immediately to every user in the role."
                                data-confirm-title="Save Permissions" data-confirm-text="Save Changes">Save Changes</button>
                    <?php else: ?>
                        <span class="perm-hint">You have view-only access. Ask an administrator to change permissions.</span>
                    <?php endif; ?>
                </div>
            </form>
        </section>
    </main>

    <script>
    (function () {
        'use strict';
        document.querySelectorAll('.perm-col-toggle').forEach(function (toggle) {
            toggle.addEventListener('change', function () {
                var role = toggle.getAttribute('data-role');
                document.querySelectorAll('.perm-check[data-role="' + role + '"]:not(.perm-col-toggle)').forEach(function (checkbox) {
                    if (!checkbox.disabled) checkbox.checked = toggle.checked;
                });
            });

            var role = toggle.getAttribute('data-role');
            var enabled = document.querySelectorAll('.perm-check[data-role="' + role + '"]:not(.perm-col-toggle):not(:disabled)');
            var checked = document.querySelectorAll('.perm-check[data-role="' + role + '"]:not(.perm-col-toggle):not(:disabled):checked');
            toggle.checked = enabled.length > 0 && checked.length === enabled.length;
        });
    })();
    </script>
</body>
</html>
