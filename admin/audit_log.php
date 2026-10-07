<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/audit.php';
check_login();
check_admin('audit.view');
require_permission('audit.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_audit_log'])) {
    csrf_verify('AUDIT_CLEAR_LOG');
    require_permission('audit.clear', 'audit_log.php');
    $conn->query('DELETE FROM audit_log');
    audit_log('AUDIT_LOG_CLEARED', 'audit_log', null, 'All audit log entries were deleted by an administrator.');
    flash_set('success', 'Audit log cleared. All entries have been deleted.');
    header('Location: audit_log.php');
    exit;
}

$flash = flash_pull();
$message = $flash['success'];
$error = $flash['error'];

$filter_action = trim($_GET['action'] ?? '');
$filter_user = (int)($_GET['user'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 100;
$offset = ($page - 1) * $per_page;

$where = '1=1';
$types = '';
$params = [];
if ($filter_action !== '') {
    $where .= ' AND a.action = ?';
    $types .= 's';
    $params[] = $filter_action;
}
if ($filter_user > 0) {
    $where .= ' AND a.user_id = ?';
    $types .= 'i';
    $params[] = $filter_user;
}

$count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM audit_log a WHERE $where");
if ($params) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_rows = (int)$count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $per_page;
}

$sql = "SELECT a.*, u.full_name, u.email
        FROM audit_log a
        LEFT JOIN users u ON a.user_id = u.id
        WHERE $where
        ORDER BY a.created_at DESC, a.id DESC
        LIMIT $per_page OFFSET $offset";
$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$logs = $stmt->get_result();

$action_result = $conn->query('SELECT DISTINCT action FROM audit_log ORDER BY action');
$actions = [];
while ($row = $action_result->fetch_assoc()) {
    $actions[] = $row['action'];
}
$users = $conn->query('SELECT id, full_name FROM users ORDER BY full_name');
$pagination_query = http_build_query(['action' => $filter_action, 'user' => $filter_user]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log - Admin Panel</title>
    <script>(function(){try{var t=localStorage.getItem('mrs_theme');if(t!=='light'&&t!=='dark'){t=(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';}document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
    <link rel="stylesheet" href="../assets/css/style.css?v=49">
</head>
<body>
    <?php require __DIR__ . '/../includes/navbar.php'; ?>
    <script src="../assets/js/theme.js?v=43"></script>
    <script src="../assets/js/nav.js?v=43"></script>
    <script src="../assets/js/ui.js?v=50"></script>
    <script src="../assets/js/icons.js?v=43"></script>

    <main class="container">
        <header style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:6px;">
            <div>
                <h2>Audit Log</h2>
                <p style="color:var(--text-muted); font-size:14px; margin:0;">System activity, including logins, bookings, and administrator actions.</p>
            </div>
            <div class="action-buttons">
                <a href="export.php?type=audit" class="btn btn-secondary"
                   data-confirm="Download the full audit log as an Excel file?"
                   data-confirm-title="Export audit log" data-confirm-text="Download">Export Excel</a>
                <form method="POST" style="margin:0;"
                      data-confirm="Delete all audit log entries? This permanently removes every record and cannot be undone."
                      data-confirm-title="Clear audit log" data-confirm-text="Delete all" data-confirm-danger="1">
                    <?php echo csrf_field(); ?>
                    <button type="submit" name="clear_audit_log" class="btn btn-danger">Clear Log</button>
                </form>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <section class="card">
            <form method="GET" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:0;">
                <div style="min-width:180px;">
                    <label for="filter-action">Action</label>
                    <select id="filter-action" name="action">
                        <option value="">All actions</option>
                        <?php foreach ($actions as $action): ?>
                            <option value="<?php echo htmlspecialchars($action); ?>" <?php echo $filter_action === $action ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($action); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="min-width:180px;">
                    <label for="filter-user">User</label>
                    <select id="filter-user" name="user">
                        <option value="">All users</option>
                        <?php while ($user = $users->fetch_assoc()): ?>
                            <option value="<?php echo (int)$user['id']; ?>" <?php echo $filter_user === (int)$user['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['full_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div style="display:flex; gap:8px;">
                    <button type="submit" class="btn">Filter</button>
                    <a href="audit_log.php" class="btn btn-secondary">Clear Filters</a>
                </div>
            </form>
        </section>

        <section class="card">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>Details</th><th>IP Address</th></tr>
                    </thead>
                    <tbody>
                    <?php if ($logs->num_rows === 0): ?>
                        <tr><td colspan="6">No audit entries found.</td></tr>
                    <?php endif; ?>
                    <?php while ($log = $logs->fetch_assoc()): ?>
                        <tr>
                            <td style="white-space:nowrap;"><?php echo htmlspecialchars(date('d/m/Y H:i:s', strtotime($log['created_at']))); ?></td>
                            <td>
                                <?php if ($log['full_name']): ?>
                                    <?php echo htmlspecialchars($log['full_name']); ?>
                                    <br><small style="color:var(--text-muted);"><?php echo htmlspecialchars($log['email']); ?></small>
                                <?php elseif ($log['user_id']): ?>
                                    User #<?php echo (int)$log['user_id']; ?> (deleted)
                                <?php else: ?>
                                    <em>System</em>
                                <?php endif; ?>
                            </td>
                            <td><span class="status-tag audit-action"><?php echo htmlspecialchars($log['action']); ?></span></td>
                            <td>
                                <?php if ($log['entity_type']): ?>
                                    <?php echo htmlspecialchars($log['entity_type']); ?><?php echo $log['entity_id'] !== null ? ' #' . (int)$log['entity_id'] : ''; ?>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                            <td><?php echo $log['details'] ? nl2br(htmlspecialchars($log['details'])) : '&mdash;'; ?></td>
                            <td style="white-space:nowrap;"><?php echo htmlspecialchars($log['ip'] ?? '&mdash;'); ?></td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <nav class="pagination" aria-label="Audit log pages">
                    <?php if ($page > 1): ?>
                        <a href="audit_log.php?page=<?php echo $page - 1; ?>&amp;<?php echo htmlspecialchars($pagination_query); ?>" class="btn btn-secondary">&laquo; Previous</a>
                    <?php endif; ?>
                    <span style="padding:8px 14px; font-weight:600;">Page <?php echo $page; ?> of <?php echo $total_pages; ?> (<?php echo $total_rows; ?> entries)</span>
                    <?php if ($page < $total_pages): ?>
                        <a href="audit_log.php?page=<?php echo $page + 1; ?>&amp;<?php echo htmlspecialchars($pagination_query); ?>" class="btn btn-secondary">Next &raquo;</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
