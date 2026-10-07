<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/auth.php';
check_login();
check_admin('reports.view');
require_permission('reports.view');

$flash = flash_pull();
$message = $flash['success'];
$error = $flash['error'];

$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$room_filter = (int)($_GET['room'] ?? 0);
$status_filter = trim($_GET['status'] ?? '');

$where = '1=1';
$types = '';
$params = [];
if ($from !== '') {
    $where .= ' AND b.booking_date >= ?';
    $types .= 's';
    $params[] = $from;
}
if ($to !== '') {
    $where .= ' AND b.booking_date <= ?';
    $types .= 's';
    $params[] = $to;
}
if ($room_filter > 0) {
    $where .= ' AND b.room_id = ?';
    $types .= 'i';
    $params[] = $room_filter;
}
if (in_array($status_filter, ['confirmed', 'postponed', 'cancelled'], true)) {
    $where .= ' AND b.status = ?';
    $types .= 's';
    $params[] = $status_filter;
} else {
    $status_filter = '';
}

$month_start = date('Y-m-01');

// Summary figures remain system-wide, independent of the detail filters.
$stats = [];
$result = $conn->query('SELECT COUNT(*) AS c FROM bookings');
$stats['total'] = (int)$result->fetch_assoc()['c'];
$result = $conn->query("SELECT COUNT(*) AS c FROM bookings WHERE status='confirmed'");
$stats['confirmed'] = (int)$result->fetch_assoc()['c'];
$result = $conn->query("SELECT COUNT(*) AS c FROM bookings WHERE status='cancelled'");
$stats['cancelled'] = (int)$result->fetch_assoc()['c'];
$result = $conn->query("SELECT COUNT(*) AS c FROM bookings WHERE status='postponed'");
$stats['postponed'] = (int)$result->fetch_assoc()['c'];
$result = $conn->query("SELECT COUNT(*) AS c FROM rooms WHERE status='available'");
$stats['rooms'] = (int)$result->fetch_assoc()['c'];
$result = $conn->query("SELECT COUNT(*) AS c FROM users WHERE status='active'");
$stats['users'] = (int)$result->fetch_assoc()['c'];
$result = $conn->query("SELECT COUNT(*) AS c FROM projectors WHERE status='active'");
$stats['projectors'] = (int)$result->fetch_assoc()['c'];
$result = $conn->query("SELECT COUNT(*) AS c FROM bookings WHERE booking_date >= '$month_start'");
$stats['month'] = (int)$result->fetch_assoc()['c'];

// Utilization: confirmed booked hours this month / room capacity (weekdays, 08:00-18:00).
$result = $conn->query("SELECT SUM(TIMESTAMPDIFF(MINUTE, start_time, end_time))/60 AS hours
    FROM bookings WHERE status='confirmed' AND booking_date >= '$month_start'");
$row = $result->fetch_assoc();
$booked_hours = $row['hours'] !== null ? (float)$row['hours'] : 0;
$room_count = max(1, $stats['rooms']);
$days_in_month = (int)date('t');
$weekdays_in_month = 0;
for ($day = 1; $day <= $days_in_month; $day++) {
    $weekday = (int)date('w', mktime(0, 0, 0, (int)date('m'), $day, (int)date('Y')));
    if ($weekday >= 1 && $weekday <= 5) {
        $weekdays_in_month++;
    }
}
$total_hours = $room_count * $weekdays_in_month * 10;
$utilization = $total_hours > 0 ? min(100, round(($booked_hours / $total_hours) * 100)) : 0;

$room_sql = "SELECT r.id, r.room_name,
    COUNT(b.id) AS total_bookings,
    COALESCE(SUM(CASE WHEN b.status='confirmed' THEN TIMESTAMPDIFF(MINUTE, b.start_time, b.end_time)/60 ELSE 0 END),0) AS booked_hours,
    SUM(CASE WHEN b.status='cancelled' THEN 1 ELSE 0 END) AS cancelled,
    SUM(CASE WHEN b.status='postponed' THEN 1 ELSE 0 END) AS postponed
    FROM rooms r LEFT JOIN bookings b ON b.room_id = r.id AND $where
    GROUP BY r.id, r.room_name ORDER BY total_bookings DESC";
$room_stmt = $conn->prepare($room_sql);
if ($params) {
    $room_stmt->bind_param($types, ...$params);
}
$room_stmt->execute();
$room_usage = $room_stmt->get_result();

$staff_sql = "SELECT u.id, u.full_name, u.email,
    COUNT(b.id) AS total_bookings,
    SUM(CASE WHEN b.status='confirmed' THEN 1 ELSE 0 END) AS confirmed,
    SUM(CASE WHEN b.status='cancelled' THEN 1 ELSE 0 END) AS cancelled,
    SUM(CASE WHEN b.status='postponed' THEN 1 ELSE 0 END) AS postponed
    FROM users u JOIN bookings b ON b.user_id = u.id AND $where
    GROUP BY u.id, u.full_name, u.email ORDER BY total_bookings DESC";
$staff_stmt = $conn->prepare($staff_sql);
if ($params) {
    $staff_stmt->bind_param($types, ...$params);
}
$staff_stmt->execute();
$staff_usage = $staff_stmt->get_result();

$projector_sql = "SELECT p.id, p.name, p.model,
    COUNT(b.id) AS total_bookings,
    COALESCE(SUM(CASE WHEN b.status='confirmed' THEN TIMESTAMPDIFF(MINUTE, b.start_time, b.end_time)/60 ELSE 0 END),0) AS booked_hours
    FROM projectors p LEFT JOIN bookings b ON b.projector_id = p.id AND $where
    GROUP BY p.id, p.name, p.model ORDER BY total_bookings DESC";
$projector_stmt = $conn->prepare($projector_sql);
if ($params) {
    $projector_stmt->bind_param($types, ...$params);
}
$projector_stmt->execute();
$projector_usage = $projector_stmt->get_result();

$monthly = [];
$result = $conn->query("SELECT DATE_FORMAT(booking_date,'%Y-%m') AS ym, COUNT(*) AS c
    FROM bookings GROUP BY ym ORDER BY ym DESC LIMIT 12");
while ($row = $result->fetch_assoc()) {
    $monthly[$row['ym']] = (int)$row['c'];
}
$monthly = array_reverse($monthly, true);
$max_monthly = max(1, max($monthly ?: [1]));
$rooms = $conn->query('SELECT id, room_name FROM rooms ORDER BY room_name');

$export_query = http_build_query(array_filter([
    'from' => $from,
    'to' => $to,
    'room' => $room_filter ?: '',
    'status' => $status_filter,
]));
$export_suffix = $export_query !== '' ? '&' . $export_query : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports &amp; Statistics - Admin Panel</title>
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
                <h2>Reports &amp; Statistics</h2>
                <p style="color:var(--text-muted); font-size:14px; margin:0;">System activity and booking statistics. Export a full report to Excel.</p>
            </div>
            <div class="action-buttons">
                <a href="export.php?type=all<?php echo htmlspecialchars($export_suffix, ENT_QUOTES, 'UTF-8'); ?>"
                   class="btn" data-confirm="Download all reports as one Excel file?"
                   data-confirm-title="Export full report" data-confirm-text="Download Excel">Export Full Excel</a>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <section class="card">
            <form method="GET" class="report-filters">
                <div>
                    <label for="filter-from">From</label>
                    <input id="filter-from" type="date" name="from" value="<?php echo htmlspecialchars($from); ?>">
                </div>
                <div>
                    <label for="filter-to">To</label>
                    <input id="filter-to" type="date" name="to" value="<?php echo htmlspecialchars($to); ?>">
                </div>
                <div style="min-width:160px;">
                    <label for="filter-room">Room</label>
                    <select id="filter-room" name="room">
                        <option value="">All rooms</option>
                        <?php while ($room = $rooms->fetch_assoc()): ?>
                            <option value="<?php echo (int)$room['id']; ?>" <?php echo $room_filter === (int)$room['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($room['room_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div style="min-width:140px;">
                    <label for="filter-status">Status</label>
                    <select id="filter-status" name="status">
                        <option value="">All statuses</option>
                        <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                        <option value="postponed" <?php echo $status_filter === 'postponed' ? 'selected' : ''; ?>>Postponed</option>
                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="report-filters-actions">
                    <button type="submit" class="btn">Apply</button>
                    <a href="reports.php" class="btn btn-secondary">Clear</a>
                </div>
            </form>
        </section>

        <section class="stat-grid stat-grid-row9">
            <div class="stat-card"><div class="stat-value"><?php echo $stats['total']; ?></div><div class="stat-label">Total Bookings</div></div>
            <div class="stat-card stat-ok"><div class="stat-value"><?php echo $stats['confirmed']; ?></div><div class="stat-label">Confirmed</div></div>
            <div class="stat-card stat-warn"><div class="stat-value"><?php echo $stats['postponed']; ?></div><div class="stat-label">Postponed</div></div>
            <div class="stat-card stat-danger"><div class="stat-value"><?php echo $stats['cancelled']; ?></div><div class="stat-label">Cancelled</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['month']; ?></div><div class="stat-label">This Month</div></div>
            <div class="stat-card stat-accent"><div class="stat-value"><?php echo $utilization; ?>%</div><div class="stat-label">Monthly Utilization</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['rooms']; ?></div><div class="stat-label">Available Rooms</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['users']; ?></div><div class="stat-label">Active Users</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['projectors']; ?></div><div class="stat-label">Active Projectors</div></div>
        </section>

        <section class="card">
            <h3>Room Usage</h3>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Room</th><th>Total Bookings</th><th>Booked Hours</th><th>Cancelled</th><th>Postponed</th></tr></thead>
                    <tbody>
                    <?php while ($row = $room_usage->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['room_name']); ?></td>
                            <td><?php echo (int)$row['total_bookings']; ?></td>
                            <td><?php echo round((float)$row['booked_hours'], 1); ?> h</td>
                            <td><?php echo (int)$row['cancelled']; ?></td>
                            <td><?php echo (int)$row['postponed']; ?></td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <h3>Bookings by Staff</h3>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Staff Member</th><th>Email</th><th>Total</th><th>Confirmed</th><th>Postponed</th><th>Cancelled</th></tr></thead>
                    <tbody>
                    <?php while ($row = $staff_usage->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['email']); ?></td>
                            <td><?php echo (int)$row['total_bookings']; ?></td>
                            <td><?php echo (int)$row['confirmed']; ?></td>
                            <td><?php echo (int)$row['postponed']; ?></td>
                            <td><?php echo (int)$row['cancelled']; ?></td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <h3>Projector Usage</h3>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Projector</th><th>Model</th><th>Total Bookings</th><th>Booked Hours</th></tr></thead>
                    <tbody>
                    <?php while ($row = $projector_usage->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                            <td><?php echo $row['model'] ? htmlspecialchars($row['model']) : '&mdash;'; ?></td>
                            <td><?php echo (int)$row['total_bookings']; ?></td>
                            <td><?php echo round((float)$row['booked_hours'], 1); ?> h</td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <h3>Monthly Trend (Last 12 Months)</h3>
            <?php if (!$monthly): ?>
                <p>No booking data yet.</p>
            <?php else: ?>
                <div class="trend-bars">
                    <?php foreach ($monthly as $year_month => $count):
                        $height = max(4, round(($count / $max_monthly) * 100));
                        $label = date('M y', strtotime($year_month . '-01'));
                    ?>
                        <div class="trend-col">
                            <div class="trend-count"><?php echo $count; ?></div>
                            <div class="trend-bar" style="height:<?php echo $height; ?>%"></div>
                            <div class="trend-label"><?php echo htmlspecialchars($label); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
