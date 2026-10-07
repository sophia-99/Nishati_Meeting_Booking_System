<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/audit.php';
check_login();

$hub_boxes = [
    ['room_control_panel.php', 'admin.dashboard', 'Room Control Panel', 'Rooms, bookings &amp; reported issues',
        '<path d="M4 21V8l8-5 8 5v13"/><path d="M9 21v-6h6v6"/>', 'rcp'],
    ['users.php', 'users.view', 'User Management', 'Add, edit &amp; manage system users',
        '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 19c.8-3 3.4-5 6.5-5s5.7 2 6.5 5"/><circle cx="17" cy="9" r="2.5"/><path d="M16 14.5c2.4.3 4.3 1.9 5 4.5"/>', 'users'],
    ['projectors.php', 'projectors.view', 'Projector Inventory', 'Projector records &amp; availability',
        '<rect x="2" y="7" width="14" height="10" rx="2"/><path d="M16 11l6-3v8l-6-3"/><circle cx="8" cy="12" r="2.2"/><path d="M5 17v2M13 17v2"/>', 'projectors'],
    ['reports.php', 'reports.view', 'Reports &amp; Stats', 'Statistics &amp; system reports',
        '<path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-6"/><path d="M22 20H2"/>', 'reports'],
    ['mail_config.php', 'mail.view', 'Mail Configuration', 'SMTP &amp; notification settings',
        '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="m3.5 7 8.5 6 8.5-6"/><circle cx="18" cy="16.5" r="3.2"/><path d="M18 15v1.6l1.1.8"/>', 'mail'],
    ['audit_log.php', 'audit.view', 'Audit Log', 'Every action tracked in the system',
        '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/>', 'audit'],
    ['roles.php', 'roles.view', 'Roles &amp; Permissions', 'Role matrix &amp; access control',
        '<path d="M21 2l-2 2"/><path d="M11.39 11.61a5.5 5.5 0 1 1-7.78 7.78 5.5 5.5 0 0 1 7.78-7.78z"/><path d="M11.39 11.61 15.5 7.5"/><path d="m15.5 7.5 3 3L22 7l-3-3-3.5 3.5z"/>', 'roles'],
];

$hub_role = $_SESSION['role'] ?? '';
$visible_boxes = [];
foreach ($hub_boxes as $box) {
    if ($hub_role === 'admin' || has_permission($hub_role, $box[1])) {
        $visible_boxes[] = $box;
    }
}

if (!$visible_boxes) {
    audit_log('ACCESS_DENIED', 'security', null, 'Admin hub denied (no admin-area permission) | role=' . $hub_role);
    flash_set('error', 'You do not have permission to open this page. Ask an administrator on the Roles & Permissions page.');
    header('Location: ../public/index.php');
    exit();
}

$flash = flash_pull();
$message = $flash['success'];
$error = $flash['error'];

$count = function ($sql) use ($conn) {
    $result = $conn->query($sql);
    if (!$result) {
        error_log('Admin dashboard count query failed: ' . $conn->error);
        return 0;
    }
    $row = $result->fetch_assoc();
    return (int)($row['c'] ?? 0);
};

$bookings_today = $count("SELECT COUNT(*) c FROM bookings WHERE (status='confirmed' AND booking_date=CURDATE()) OR (status='postponed' AND postponed_date=CURDATE())");
$rooms_in_use = $count("SELECT COUNT(DISTINCT room_id) c FROM bookings WHERE (status='confirmed' AND booking_date=CURDATE() AND start_time<=CURTIME() AND end_time>=CURTIME()) OR (status='postponed' AND postponed_date=CURDATE() AND postponed_start_time<=CURTIME() AND postponed_end_time>=CURTIME())");
$rooms_total = $count("SELECT COUNT(*) c FROM rooms");
$issues_open = $count("SELECT COUNT(*) c FROM room_issues WHERE status='open'");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>
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
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <section class="card admin-welcome">
            <div class="aw-top">
                <span class="admin-welcome-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/>
                        <path d="M9.5 12l1.8 1.8L15 10"/>
                    </svg>
                </span>
                <div class="aw-body">
                    <div class="aw-title">
                        <h2>Welcome back, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Administrator'); ?></h2>
                        <span class="aw-badge <?php echo $hub_role === 'admin' ? 'aw-badge--admin' : 'aw-badge--staff'; ?>">
                            <span class="aw-badge-role"><?php echo $hub_role === 'admin' ? 'Administrator' : 'Staff Member'; ?></span>
                            <span class="aw-badge-note"><?php echo $hub_role === 'admin' ? 'Full access to all sections' : 'Access assigned by your administrator'; ?></span>
                        </span>
                    </div>
                    <p class="aw-lead">Everything you manage in one place &mdash; rooms, bookings, people, reports and system activity. Pick a section below to start.</p>
                    <p class="aw-tip">Tip: each card below opens a full workspace. You can return here anytime from the <strong>Admin</strong> button in the menu.</p>
                </div>
            </div>

            <div class="aw-stats">
                <div class="aw-stat aw-stat--info">
                    <strong><?php echo $bookings_today; ?></strong>
                    <span class="aw-stat-name">Bookings today</span>
                    <span class="aw-stat-desc">Reservations confirmed across all rooms for the current day</span>
                </div>
                <div class="aw-stat aw-stat--ok">
                    <strong><?php echo $rooms_in_use; ?></strong>
                    <span class="aw-stat-name">Rooms in use</span>
                    <span class="aw-stat-desc">Rooms currently reserved &mdash; live occupancy right now</span>
                </div>
                <div class="aw-stat aw-stat--gold">
                    <strong><?php echo $rooms_total; ?></strong>
                    <span class="aw-stat-name">Total rooms</span>
                    <span class="aw-stat-desc">Meeting rooms registered and available for booking in the system</span>
                </div>
                <div class="aw-stat aw-stat--danger">
                    <strong><?php echo $issues_open; ?></strong>
                    <span class="aw-stat-name">Open issues</span>
                    <span class="aw-stat-desc">Reported problems waiting to be reviewed and resolved</span>
                </div>
            </div>
            <p class="aw-foot">Data shown is live from the system and refreshed every time this page loads. You only see what your role allows.</p>
        </section>

        <section class="admin-hub-grid" aria-label="Admin sections">
            <?php foreach ($visible_boxes as $box): ?>
                <a class="card admin-hub-card hub-ico-<?php echo htmlspecialchars($box[5], ENT_QUOTES, 'UTF-8'); ?>" href="<?php echo htmlspecialchars($box[0], ENT_QUOTES, 'UTF-8'); ?>">
                    <span class="admin-hub-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?php echo $box[4]; ?></svg>
                    </span>
                    <h3><?php echo $box[2]; ?></h3>
                    <p><?php echo $box[3]; ?></p>
                </a>
            <?php endforeach; ?>
        </section>
    </main>
</body>
</html>
