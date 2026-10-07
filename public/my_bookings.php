<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require __DIR__ . '/../includes/mailer.php';
require __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/booking_rules.php';
check_login();
$flash = flash_pull();
$message = $flash['success'];
$error = $flash['error'];
// ROLES & PERMISSIONS â€” hali za UI (guards za server zipo hapo chini)
$can_cancel   = has_permission($_SESSION['role'] ?? '', 'bookings.cancel_own');
$can_postpone = has_permission($_SESSION['role'] ?? '', 'bookings.postpone_own');
$can_delete   = has_permission($_SESSION['role'] ?? '', 'bookings.delete_own');
$can_report   = has_permission($_SESSION['role'] ?? '', 'bookings.report_issue');

// Cancel a booking (single day or entire series)
if (isset($_GET['cancel'])) {
    csrf_verify('BOOKING_CANCEL');
    require_permission('bookings.cancel_own', 'my_bookings.php');
    $booking_id = intval($_GET['cancel']);
    $scope = ($_GET['scope'] ?? 'day') === 'series' ? 'series' : 'day';

    if ($scope === 'series') {
        $sid_stmt = $conn->prepare("SELECT series_id FROM bookings WHERE id = ? AND user_id = ? AND series_id IS NOT NULL");
        $sid_stmt->bind_param("ii", $booking_id, $_SESSION['user_id']);
        $sid_stmt->execute();
        $sid_row = $sid_stmt->get_result()->fetch_assoc();
        if ($sid_row && $sid_row['series_id']) {
            $stmt = $conn->prepare("UPDATE bookings SET status = 'cancelled' WHERE series_id = ? AND user_id = ? AND status IN ('confirmed','postponed')");
            $stmt->bind_param("ii", $sid_row['series_id'], $_SESSION['user_id']);
            $stmt->execute();
            audit_log('BOOKING_CANCELLED', 'booking', $booking_id, "Scope=series | series_id=" . $sid_row['series_id']);
            flash_set('success', 'Entire continuous meeting series cancelled successfully.');
        } else {
            flash_set('error', 'Series not found for this booking.');
        }
    } else {
        $stmt = $conn->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $booking_id, $_SESSION['user_id']);
        $stmt->execute();
        audit_log('BOOKING_CANCELLED', 'booking', $booking_id, "Scope=day");
        flash_set('success', 'Booking cancelled successfully.');
    }
    header("Location: my_bookings.php");
    exit();
}

// Permanently delete a booking (only allowed once it is finished or cancelled)
if (isset($_GET['delete'])) {
    csrf_verify('BOOKING_DELETE');
    require_permission('bookings.delete_own', 'my_bookings.php');
    $booking_id = intval($_GET['delete']);
    $stmt = $conn->prepare("SELECT status, booking_date, end_time FROM bookings WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $booking_id, $_SESSION['user_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if ($row) {
        $end_datetime = $row['booking_date'] . " " . $row['end_time'];
        $now_datetime = date('Y-m-d H:i:s');
        $is_finished = ($row['status'] === 'confirmed' && $end_datetime < $now_datetime);
        $is_deletable = $is_finished || $row['status'] === 'cancelled' || $row['status'] === 'postponed';

        if ($is_deletable) {
            $del = $conn->prepare("DELETE FROM bookings WHERE id = ? AND user_id = ?");
            $del->bind_param("ii", $booking_id, $_SESSION['user_id']);
            $del->execute();
            audit_log('BOOKING_DELETED', 'booking', $booking_id, "Permanently deleted by owner");
            flash_set('success', 'Booking deleted successfully.');
        } else {
            flash_set('error', 'This booking cannot be deleted yet. Only finished, cancelled or postponed bookings can be removed.');
        }
    }
    header("Location: my_bookings.php");
    exit();
}

// Report an issue with a booked room
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_issue'])) {
    csrf_verify('ISSUE_REPORT');
    require_permission('bookings.report_issue', 'my_bookings.php');
    $booking_id = intval($_POST['booking_id']);
    $issue_message = trim($_POST['issue_message'] ?? '');
    if (mb_strlen($issue_message) > 2000) {
        $issue_message = mb_substr($issue_message, 0, 2000);
    }

    // Confirm this booking belongs to the logged-in user, and get room + meeting details
    $stmt = $conn->prepare("
        SELECT b.room_id, b.meeting_title, b.status, b.booking_date, b.start_time, b.end_time,
               b.postponed_date, b.postponed_start_time, b.postponed_end_time,
               r.room_name FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        WHERE b.id = ? AND b.user_id = ?
    ");
    $stmt->bind_param("ii", $booking_id, $_SESSION['user_id']);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();

    if ($info && $issue_message) {
        // REPORT ISSUE = kwenye dirisha la kikao PEKEE (UI na server kwa pamoja):
        // muhtasari wa muda wa kikao (postponed = tarehe/muda mpya), halali
        // CONFIRMED au POSTPONED tu.
        $eff_date  = $info['booking_date'];
        $eff_start = $info['start_time'];
        $eff_end   = $info['end_time'];
        if ($info['status'] === 'postponed' && $info['postponed_date']) {
            $eff_date  = $info['postponed_date'];
            $eff_start = $info['postponed_start_time'] ?: $info['start_time'];
            $eff_end   = $info['postponed_end_time']   ?: $info['end_time'];
        }
        $in_window = in_array($info['status'], ['confirmed', 'postponed'])
            && ($eff_date . ' ' . $eff_start) <= date('Y-m-d H:i:s')
            && ($eff_date . ' ' . $eff_end)   >  date('Y-m-d H:i:s');

        if (!$in_window) {
            $error = "You can only report an issue while the meeting session is in progress.";
        } else {
        $insert = $conn->prepare("INSERT INTO room_issues (booking_id, room_id, user_id, message) VALUES (?, ?, ?, ?)");
        $insert->bind_param("iiis", $booking_id, $info['room_id'], $_SESSION['user_id'], $issue_message);
        $insert->execute();
        audit_log('ISSUE_REPORTED', 'room_issue', $conn->insert_id, "Room: {$info['room_name']} | Booking #$booking_id | " . substr($issue_message, 0, 200));

        // Notify admin by email
        $cfg = require __DIR__ . '/../includes/mail_config.php';
        $admin_email = $cfg['admin_email'] ?? $cfg['from_email'];
        $body = "<h3>Room Issue Reported</h3>
            <p><b>Reported by:</b> " . htmlspecialchars($_SESSION['full_name']) . " (" . htmlspecialchars($_SESSION['email']) . ")</p>
            <p><b>Room:</b> " . htmlspecialchars($info['room_name']) . "</p>
            <p><b>Meeting:</b> " . htmlspecialchars($info['meeting_title']) . "</p>
            <p><b>Issue:</b> " . nl2br(htmlspecialchars($issue_message)) . "</p>";
        send_booking_email($admin_email, "Admin", "Room Issue Reported - " . $info['room_name'], $body);

        // SPRINT 3 (PRG): mafanikio = mara moja kwenye GET (refresh haitakariri
        // row wala barua pepe)
        flash_set('success', "Your issue has been reported to the admin.");
        header("Location: my_bookings.php");
        exit();
        }
    } else {
        $error = ($issue_message === '')
            ? "Please describe the issue before sending."
            : "Booking not found â€” the issue could not be reported.";
    }
}

// Postpone a booking (single day or entire series)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['postpone_booking'])) {
    csrf_verify('BOOKING_POSTPONE');
    require_permission('bookings.postpone_own', 'my_bookings.php');
    $booking_id = intval($_POST['booking_id']);
    $series_id = intval($_POST['series_id'] ?? 0);
    $scope = ($_POST['postpone_scope'] ?? 'day') === 'series' ? 'series' : 'day';
    $new_date = ($_POST['postponed_date'] ?? '') !== '' ? $_POST['postponed_date'] : null;
    $new_start = ($_POST['postponed_start_time'] ?? '') !== '' ? $_POST['postponed_start_time'] : null;
    $new_end = ($_POST['postponed_end_time'] ?? '') !== '' ? $_POST['postponed_end_time'] : null;
    $reason = trim($_POST['postponed_reason'] ?? '');

    if ($scope === 'series' && $series_id) {
        if ($new_start !== null && $new_end !== null && $new_start >= $new_end) {
            flash_set('error', 'Start time must be before end time.');
            header("Location: my_bookings.php");
            exit();
        }
        if (mb_strlen($reason) > 255) { $reason = mb_substr($reason, 0, 255); }
        $set_parts = [];
        $types = "";
        $params = [];
        if ($new_start !== null) { $set_parts[] = "start_time = ?"; $types .= "s"; $params[] = $new_start; }
        if ($new_end !== null) { $set_parts[] = "end_time = ?"; $types .= "s"; $params[] = $new_end; }
        if ($reason !== "") { $set_parts[] = "postponed_reason = ?"; $types .= "s"; $params[] = $reason; }
        if ($set_parts) {
            // Kagua mgongano kwa kila session kabla ya kubadilisha muda
            if ($new_start !== null || $new_end !== null) {
                $sres = $conn->prepare("SELECT room_id, booking_date, start_time, end_time FROM bookings WHERE series_id = ? AND user_id = ? AND status = 'confirmed'");
                $sres->bind_param("ii", $series_id, $_SESSION['user_id']);
                $sres->execute();
                $sres_rows = $sres->get_result();
                $conflict_msg = "";
                while ($sr = $sres_rows->fetch_assoc()) {
                    $t1 = $new_start !== null ? $new_start : substr($sr['start_time'], 0, 8);
                    $t2 = $new_end !== null ? $new_end : substr($sr['end_time'], 0, 8);
                    if (mrs_room_slot_conflicts($conn, $sr['room_id'], $sr['booking_date'], $t1, $t2, 0, $series_id) > 0) {
                        $conflict_msg = "The new time clashes with another booking on " . $sr['booking_date'] . ". Please choose a different time.";
                        break;
                    }
                }
                if ($conflict_msg !== "") {
                    flash_set('error', $conflict_msg);
                    header("Location: my_bookings.php");
                    exit();
                }
            }
            $sql = "UPDATE bookings SET " . implode(", ", $set_parts) . " WHERE series_id = ? AND user_id = ? AND status = 'confirmed'";
            $types .= "ii";
            $params[] = $series_id;
            $params[] = $_SESSION['user_id'];
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            audit_log('BOOKING_POSTPONED', 'booking', $booking_id, "Scope=series | series_id=$series_id | times only");
            flash_set('success', 'Continuous series postponed successfully.');
        } else {
            flash_set('error', 'Please provide a new start or end time to postpone the series.');
        }
    } else {
        // POSTPONE (siku): tarehe + muda NI LAZIMA, na hupimwa vema (Sprint 1)
        if (!$new_date || !valid_date($new_date)) {
            flash_set('error', 'Please choose a valid new date for the postponed booking.');
            header("Location: my_bookings.php");
            exit();
        }
        if (!$new_start || !$new_end || !valid_time($new_start) || !valid_time($new_end)) {
            flash_set('error', 'Please choose valid new start and end times.');
            header("Location: my_bookings.php");
            exit();
        }
        if ($new_start >= $new_end) {
            flash_set('error', 'Start time must be before end time.');
            header("Location: my_bookings.php");
            exit();
        }
        if (mb_strlen($reason) > 255) { $reason = mb_substr($reason, 0, 255); }

        $own = $conn->prepare("SELECT room_id FROM bookings WHERE id = ? AND user_id = ? AND status IN ('confirmed','postponed')");
        $own->bind_param("ii", $booking_id, $_SESSION['user_id']);
        $own->execute();
        $own_row = $own->get_result()->fetch_assoc();

        if (!$own_row) {
            flash_set('error', 'This booking cannot be postponed.');
        } elseif (mrs_room_slot_conflicts($conn, $own_row['room_id'], $new_date, $new_start, $new_end, $booking_id) > 0) {
            flash_set('error', "That room is already booked on $new_date for $new_start - $new_end. Please choose a different date or time.");
        } else {
            $stmt = $conn->prepare("UPDATE bookings SET status = 'postponed', postponed_date = ?, postponed_start_time = ?, postponed_end_time = ?, postponed_reason = ? WHERE id = ? AND user_id = ? AND status IN ('confirmed','postponed')");
            $stmt->bind_param("ssssii", $new_date, $new_start, $new_end, $reason, $booking_id, $_SESSION['user_id']);
            $stmt->execute();
            audit_log('BOOKING_POSTPONED', 'booking', $booking_id, "Scope=day | new_date=$new_date | $new_start-$new_end | $reason");
            flash_set('success', 'Booking postponed successfully.');
        }
    }
    header("Location: my_bookings.php");
    exit();
}

$stmt = $conn->prepare("
    SELECT b.*, r.room_name, p.name AS projector_name, p.model AS projector_model
    FROM bookings b
    JOIN rooms r ON b.room_id = r.id
    LEFT JOIN projectors p ON b.projector_id = p.id
    WHERE b.user_id = ?
    ORDER BY b.booking_date DESC, b.start_time DESC
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$bookings = $stmt->get_result();

$now_datetime = date('Y-m-d H:i:s');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings</title>
    <script>(function(){try{var t=localStorage.getItem('mrs_theme');if(t!=='light'&&t!=='dark'){t=(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';}document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
    <link rel="stylesheet" href="../assets/css/style.css?v=49">
</head>
<body>
    <?php require __DIR__ . '/../includes/navbar.php'; ?>
    <script src="../assets/js/theme.js?v=43"></script>
    <script src="../assets/js/nav.js?v=43"></script>
    <script src="../assets/js/ui.js?v=51"></script>
    <script src="../assets/js/icons.js?v=43"></script>

    <div class="container">
        <h2>My Bookings</h2>
        <br>
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <div class="card">
            <div class="table-scroll">
                <table>
                <tr>
                    <th>Room</th>
                    <th>Meeting</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Accessory</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
                <?php while ($b = $bookings->fetch_assoc()):
                    $start_datetime = $b['booking_date'] . " " . $b['start_time'];
                    $end_datetime = $b['booking_date'] . " " . $b['end_time'];
                    $is_finished = ($b['status'] === 'confirmed' && $end_datetime < $now_datetime);
                    $is_ongoing = ($b['status'] === 'confirmed' && $start_datetime <= $now_datetime && $end_datetime > $now_datetime);
                    // REPORT ISSUE: ionekane DIRISHA LA KIKAO pekee (muda
                    // umefika na haujaisha). Postponed = tarehe/muda mpya.
                    $eff_date = $b['booking_date'];
                    $eff_start = $b['start_time'];
                    $eff_end = $b['end_time'];
                    if ($b['status'] === 'postponed' && !empty($b['postponed_date'])) {
                        $eff_date = $b['postponed_date'];
                        $eff_start = !empty($b['postponed_start_time']) ? $b['postponed_start_time'] : $b['start_time'];
                        $eff_end = !empty($b['postponed_end_time']) ? $b['postponed_end_time'] : $b['end_time'];
                    }
                    $in_session_window = in_array($b['status'], ['confirmed', 'postponed'])
                        && ($eff_date . ' ' . $eff_start) <= $now_datetime
                        && ($eff_date . ' ' . $eff_end) > $now_datetime;
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($b['room_name']); ?></td>
                    <td><?php echo htmlspecialchars($b['meeting_title']); ?><?php if (!empty($b['series_id'])): ?><span class="series-tag">Continuous</span><?php endif; ?></td>
                    <td><?php echo $b['booking_date']; ?></td>
                    <td><?php echo substr($b['start_time'],0,5) . " - " . substr($b['end_time'],0,5); ?></td>
                    <td><?php
                        if ($b['Accessories'] === 'none') {
                            echo 'â€”';
                        } elseif ($b['Accessories'] === 'projector') {
                            $plabel = 'Projector';
                            if (!empty($b['projector_name'])) {
                                $plabel .= ': ' . htmlspecialchars($b['projector_name']);
                                if (!empty($b['projector_model'])) {
                                    $plabel .= ' (' . htmlspecialchars($b['projector_model']) . ')';
                                }
                            }
                            echo $plabel;
                        } else {
                            echo ucfirst($b['Accessories']);
                        }
                    ?></td>
                    <td>
                        <?php if ($is_finished): ?>
                            Finished
                        <?php elseif ($is_ongoing): ?>
                            <span class="status-tag status-ongoing">In a Meeting Now</span>
                        <?php elseif ($b['status'] === 'confirmed'): ?>
                            Confirmed
                        <?php elseif ($b['status'] === 'postponed'): ?>
                            Postponed
                            <?php if ($b['postponed_date']): ?>
                                <br><small>New time: <?php echo $b['postponed_date'] . " " . substr($b['postponed_start_time'],0,5) . "-" . substr($b['postponed_end_time'],0,5); ?></small>
                            <?php endif; ?>
                            <?php if ($b['postponed_reason']): ?>
                                <br><small>Reason: <?php echo htmlspecialchars($b['postponed_reason']); ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            Cancelled
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (($b['status'] === 'confirmed' || $b['status'] === 'postponed') && !$is_finished
                                  && ($can_cancel || $can_postpone || ($b['status'] === 'postponed' && $can_delete))): ?>
                            <div class="mrs-kebab">
                                <button type="button" class="mrs-kebab-toggle" aria-label="Open booking actions">&#8942;</button>
                                <div class="mrs-kebab-menu">
                                    <?php if (!empty($b['series_id'])): ?>
                                        <?php if ($can_cancel): ?>
                                        <a class="danger-item" href="<?php echo csrf_url('my_bookings.php?cancel=' . $b['id'] . '&scope=day'); ?>"
                                           data-confirm="Cancel this day only? Other continuous sessions will remain."
                                           data-confirm-title="Cancel This Day?"
                                           data-confirm-text="OK"
                                           data-confirm-danger="1">Cancel this day</a>
                                        <a class="danger-item" href="<?php echo csrf_url('my_bookings.php?cancel=' . $b['id'] . '&scope=series'); ?>"
                                           data-confirm="Cancel all remaining sessions of this continuous meeting?"
                                           data-confirm-title="Cancel Entire Series?"
                                           data-confirm-text="OK"
                                           data-confirm-danger="1">Cancel entire series</a>
                                        <?php endif; ?>
                                        <?php if ($can_postpone): ?>
                                        <button type="button" class="mrs-postpone-booking" data-id="<?php echo $b['id']; ?>" data-series="1" data-series-id="<?php echo $b['series_id']; ?>">Postpone</button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if ($can_cancel): ?>
                                        <a class="danger-item" href="<?php echo csrf_url('my_bookings.php?cancel=' . $b['id']); ?>"
                                           data-confirm="Are you sure you want to cancel this booking?"
                                           data-confirm-title="Cancel Booking?"
                                           data-confirm-text="OK"
                                           data-confirm-danger="1">Cancel</a>
                                        <?php endif; ?>
                                        <?php if ($can_postpone): ?>
                                        <button type="button" class="mrs-postpone-booking" data-id="<?php echo $b['id']; ?>">Postpone</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($b['status'] === 'postponed' && $can_delete): ?>
                                        <a class="danger-item" href="<?php echo csrf_url('my_bookings.php?delete=' . $b['id']); ?>"
                                           data-confirm="Delete this postponed booking permanently? This cannot be undone."
                                           data-confirm-title="Delete Booking?"
                                           data-confirm-text="Delete"
                                           data-confirm-danger="1">Delete</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php elseif (($is_finished || $b['status'] === 'cancelled') && $can_delete): ?>
                            <?php /* Imekwisha: KEBAB imeondolewa â€” button MOJA ya Delete
                                    inayoonekana moja kwa moja (kama Reported Issues) */ ?>
                            <a href="<?php echo csrf_url('my_bookings.php?delete=' . $b['id']); ?>"
                               class="btn btn-danger"
                               data-confirm="Delete this booking permanently? This cannot be undone."
                               data-confirm-title="Delete Booking?"
                               data-confirm-text="Delete"
                               data-confirm-danger="1">Delete</a>
                        <?php endif; ?>

                        <?php if ($can_report && $b['status'] !== 'cancelled' && $in_session_window): ?>
                            <button type="button" class="btn mrs-report-issue" data-id="<?php echo $b['id']; ?>">Report Issue</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
