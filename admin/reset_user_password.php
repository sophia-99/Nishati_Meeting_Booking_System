<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require __DIR__ . '/../includes/audit.php';
check_login();
check_admin('users.reset_password');
require_permission('users.reset_password');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify('USER_PASSWORD_RESET_BY_ADMIN');
    $user_id = intval($_POST['user_id']);
    $new_password = $_POST['new_password'] ?? '';
    $policy_error = password_policy_error($new_password);

    // Self-service password changes must go through the Forgot Password flow.
    if ((int)$user_id === (int)($_SESSION['user_id'] ?? 0)) {
        $policy_error = 'You cannot reset your own password here. Use Forgot Password instead.';
    }

    if ($policy_error !== '') {
        audit_log('PASSWORD_RESET_BY_ADMIN', 'user', $user_id, 'Rejected: ' . $policy_error);
        header("Location: users.php?view=" . $user_id . "&password_policy=1");
        exit();
    }

    $hash = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
    $stmt->bind_param('si', $hash, $user_id);
    $stmt->execute();

    // Invalidate any outstanding reset tokens after an administrator changes the password.
    $kill = $conn->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ?");
    $kill->bind_param("i", $user_id);
    $kill->execute();

    audit_log('PASSWORD_RESET_BY_ADMIN', 'user', $user_id, "Administrator reset the password for user #$user_id");
    header("Location: users.php?view=" . $user_id . "&password_reset=1");
    exit();
}
header("Location: users.php");
exit();

