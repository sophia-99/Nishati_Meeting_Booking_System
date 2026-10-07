<?php
// =====================================================================
//  schedule.php â€” RATIBA YA CHUMBA (kalenda ya mwezi mzima)
//
//  Kuona bookings zote (confirmed + postponed kwa tarehe halisi) kwa
//  mwezi mmoja, kwa chumba kimoja au vyumba vyote. Kwa mtumiaji yeyote
//  aliye logged-in (soma tu â€” hakuna kitendo cha kubadilisha).
//
//  Sprint 4 â€” README: "Kalenda ya kuona ratiba ya chumba kwa mwezi mzima"
// =====================================================================
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/booking_rules.php';
check_login();
require_permission('schedule.view');

/* ---------- Ruhusa: kuona mikutano ya wote vs. ya mwenyewe ----------
   schedule.view_all = 0 â†’ mtumiaji anaona bookings ZAKE pekee kwenye kalenda */
$can_view_all = has_permission($_SESSION['role'] ?? '', 'schedule.view_all');

/* ---------- Mwezi wa kuonyesha (?y= & ?m=, kikomo salama) ---------- */
$y = intval($_GET['y'] ?? date('Y'));
$m = intval($_GET['m'] ?? date('n'));
if ($m < 1)  { $m = 12; $y--; }
if ($m > 12) { $m = 1;  $y++; }
if ($y < 2020 || $y > 2100) { $y = (int)date('Y'); $m = (int)date('n'); }

$room_filter = intval($_GET['room'] ?? 0);
$month_names = [1=>'January','February','March','April','May','June','July',
                'August','September','October','November','December'];
$month_label = $month_names[$m] . ' ' . $y;

/* ---------- Chumba kilichochaguliwa + orodha ya vyumba ---------- */
$rooms_res = $conn->query("SELECT id, room_name, status FROM rooms ORDER BY room_name");
$rooms = [];
while ($r = $rooms_res->fetch_assoc()) $rooms[] = $r;
$room_label = 'All Rooms';
foreach ($rooms as $r) {
    if ((int)$r['id'] === $room_filter) { $room_label = $r['room_name']; break; }
}

/* ---------- Bookings za mwezi (tarehe HALISI = eff date) ----------
   Postponed inaonyeshwa kwenye postponed_date yake (siyo booking_date
   ya zamani) â€” kanuni MOJA mrs_eff_date_sql kutoka booking_rules.   */
$first = sprintf('%04d-%02d-01', $y, $m);
$last  = date('Y-m-t', strtotime($first));
$eff_d = mrs_eff_date_sql('b');
$sql = "SELECT b.id, b.meeting_title, b.status, b.room_id,
               r.room_name, u.full_name,
               $eff_d AS eff_date,
               " . mrs_eff_start_sql('b') . " AS eff_start,
               " . mrs_eff_end_sql('b') . " AS eff_end
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        JOIN users u ON b.user_id = u.id
        WHERE b.status IN ('confirmed','postponed')
          AND $eff_d >= ? AND $eff_d <= ?";
$types = 'ss';
$params = [$first, $last];
if ($room_filter > 0) {
    $sql .= " AND b.room_id = ?";
    $types .= 'i';
    $params[] = $room_filter;
}
if (!$can_view_all) {
    $sql .= " AND b.user_id = ?";
    $types .= 'i';
    $params[] = (int)$_SESSION['user_id'];
}
/* ---- Kikao TISICHO bado kufika: fifua kikao kilichokwisha kuanza ----
   Kalenda huliwa na kikao pekee ambacho bado hakijaanza kwa sasa huu
   (kikao kilichokwisha au kiko kazini sasa huondolewa kwenye orodha).  */
$sql .= " AND CONCAT($eff_d, ' ', " . mrs_eff_start_sql('b') . ") > ?";
$types .= 's';
$params[] = date('Y-m-d H:i:s'); // app TZ (Africa/Dar_es_Salaam kupitia config.php)
$sql .= " ORDER BY eff_date ASC, eff_start ASC, r.room_name ASC";
$st = $conn->prepare($sql);
$st->bind_param($types, ...$params);
$st->execute();
$res = $st->get_result();

$by_date = [];
while ($b = $res->fetch_assoc()) {
    $by_date[$b['eff_date']][] = $b;
}

/* ---------- Grid ya kalenda: Jumatatu = siku ya kwanza ---------- */
$days_in_month = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
$lead_blanks   = (int)date('N', mktime(0, 0, 0, $m, 1, $y)) - 1; // 1=Mon..7=Sun
$today = date('Y-m-d');

$prev_y = $m === 1 ? $y - 1 : $y;
$prev_m = $m === 1 ? 12 : $m - 1;
$next_y = $m === 12 ? $y + 1 : $y;
$next_m = $m === 12 ? 1 : $m + 1;
$nav = function ($ny, $nm) use ($room_filter) {
    return 'schedule.php?y=' . $ny . '&m=' . $nm . ($room_filter ? '&room=' . $room_filter : '');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Schedule - Meeting Room Booking System</title>
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
        <div class="ratiba-head">
            <div>
                <h2>Room Schedule</h2>
                <p class="ratiba-sub"><?php echo htmlspecialchars($month_label); ?> &middot;
                    <?php echo htmlspecialchars($room_label); ?></p>
            </div>
            <form method="get" class="ratiba-room-filter">
                <?php if ($y !== (int)date('Y') || $m !== (int)date('n')): ?>
                    <input type="hidden" name="y" value="<?php echo $y; ?>">
                    <input type="hidden" name="m" value="<?php echo $m; ?>">
                <?php endif; ?>
                <label for="ratibaRoom" class="visually-hidden">Room</label>
                <select id="ratibaRoom" name="room" onchange="this.form.submit()">
                    <option value="0">All rooms</option>
                    <?php foreach ($rooms as $r): ?>
                        <option value="<?php echo (int)$r['id']; ?>"
                            <?php echo $room_filter === (int)$r['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($r['room_name']); ?>
                            <?php echo $r['status'] !== 'available' ? '(maintenance)' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="ratiba-toolbar">
            <a class="btn btn-secondary" href="<?php echo $nav($prev_y, $prev_m); ?>">&larr; Prev</a>
            <a class="btn btn-secondary" href="schedule.php<?php echo $room_filter ? '?room=' . $room_filter : ''; ?>">Today</a>
            <a class="btn btn-secondary" href="<?php echo $nav($next_y, $next_m); ?>">Next &rarr;</a>
        </div>

        <div class="ratiba-legend">
            <span><i class="legend-dot is-confirmed"></i> Confirmed</span>
            <span><i class="legend-dot is-postponed"></i> Postponed (new date/time)</span>
            <span><i class="legend-dot is-today"></i> Today</span>
        </div>

        <div class="mrs-cal-grid">
            <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d): ?>
                <div class="mrs-cal-head"><?php echo $d; ?></div>
            <?php endforeach; ?>

            <?php for ($i = 0; $i < $lead_blanks; $i++): ?>
                <div class="mrs-cal-cell is-empty"></div>
            <?php endfor; ?>

            <?php for ($day = 1; $day <= $days_in_month; $day++):
                $ds = sprintf('%04d-%02d-%02d', $y, $m, $day);
                $is_today = ($ds === $today);
                $events = $by_date[$ds] ?? [];
            ?>
                <div class="mrs-cal-cell<?php echo $is_today ? ' is-today' : ''; ?>"
                     data-date="<?php echo $ds; ?>">
                    <span class="cal-daynum"><?php echo $day; ?></span>
                    <?php foreach ($events as $ev): ?>
                        <?php $cls = $ev['status'] === 'postponed' ? 'is-postponed' : 'is-confirmed'; ?>
                        <div class="cal-event <?php echo $cls; ?>"
                             title="<?php echo htmlspecialchars(
                                 $ev['meeting_title'] . ' â€” ' . $ev['room_name']
                                 . ' (' . substr($ev['eff_start'], 0, 5) . '-' . substr($ev['eff_end'], 0, 5) . ')'
                                 . ' â€” ' . $ev['full_name']
                                 . ($ev['status'] === 'postponed' ? ' [Postponed]' : '')
                             ); ?>">
                            <span class="cal-time"><?php echo htmlspecialchars(substr($ev['eff_start'], 0, 5)); ?></span>
                            <span class="cal-title"><?php echo htmlspecialchars($ev['meeting_title']); ?></span>
                            <?php if ($room_filter === 0): ?>
                                <span class="cal-room"><?php echo htmlspecialchars($ev['room_name']); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endfor; ?>
        </div>

        <?php if (!$by_date): ?>
            <p class="ratiba-empty">No bookings scheduled this month<?php
                echo $room_filter ? ' for this room' : ''; ?>.</p>
        <?php endif; ?>
    </div>
</body>
</html>
