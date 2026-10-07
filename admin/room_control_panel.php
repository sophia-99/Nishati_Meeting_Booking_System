<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/booking_rules.php';
check_login();
check_admin('admin.dashboard');
require_permission('admin.dashboard');

$flauh = flash_pull();
$message = $flauh['success'];
$error = $flauh['error'];

// Add a new room
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_room'])) {
    csrf_verify('ROOM_ADD');
    require_permission('rooms.manage', 'room_control_panel.php');
    $name = trim($_POST['room_name'] ?? '');
    $capacity = intval($_POST['capacity'] ?? 0);
    $location = trim($_POST['location'] ?? '');
    if (mb_strlen($name) > 100) $name = mb_substr($name, 0, 100);
    if (mb_strlen($location) > 150) $location = mb_substr($location, 0, 150);
    if ($capacity < 1 || $capacity > 10000) $capacity = 1;

    // SPRINT 3 (gating): jina la chumba lazima liwe unique (kuzuia duplicate
    // kwa double-submit pamoja na PRG hapa chini)
    $check = $conn->prepare("SELECT id FROM rooms WHERE room_name = ?");
    $check->bind_param("s", $name);
    $check->execute();
    $check->store_result();

    if ($name === '' || $check->num_rows > 0) {
        $error = ($name === '') ? "Room name is required." : "A room with this name already exists.";
    } else {
        $stmt = $conn->prepare("INSERT INTO rooms (room_name, capacity, location) VALUES (?, ?, ?)");
        $stmt->bind_param("sis", $name, $capacity, $location);
        $stmt->execute();
        audit_log('ROOM_ADDED', 'room', $conn->insert_id, "$name | capacity=$capacity | $location");
        // SPRINT 3 (PRG)
        flash_set('success', "Room added.");
        header("Location: room_control_panel.php");
        exit();
    }
}

// Toggle room status
if (isset($_GET['toggle_status'])) {
    csrf_verify('ROOM_STATUS_CHANGE');
    require_permission('rooms.toggle_status', 'room_control_panel.php');
    $room_id = intval($_GET['toggle_status']);
    $stmt = $conn->prepare("UPDATE rooms SET status = IF(status='available','maintenance','available') WHERE id = ?");
    $stmt->bind_param("i", $room_id);
    $stmt->execute();
    audit_log('ROOM_STATUS_CHANGED', 'room', $room_id, "Toggled availability");
    flash_set('success', 'Room status updated successfully.');
    header("Location: room_control_panel.php");
    exit();
}

// Edit an existing room
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_room'])) {
    csrf_verify('ROOM_EDIT');
    require_permission('rooms.manage', 'room_control_panel.php');
    $room_id = intval($_POST['room_id']);
    $name = trim($_POST['room_name'] ?? '');
    $capacity = intval($_POST['capacity'] ?? 0);
    $location = trim($_POST['location'] ?? '');
    if (mb_strlen($name) > 100) $name = mb_substr($name, 0, 100);
    if (mb_strlen($location) > 150) $location = mb_substr($location, 0, 150);
    if ($capacity < 1 || $capacity > 10000) $capacity = 1;

    // SPRINT 3 (gating): jina unique isipokuwa la chumba chenyewe
    $check = $conn->prepare("SELECT id FROM rooms WHERE room_name = ? AND id != ?");
    $check->bind_param("si", $name, $room_id);
    $check->execute();
    $check->store_result();

    if ($name === '' || $check->num_rows > 0) {
        $error = ($name === '') ? "Room name is required." : "A room with this name already exists.";
    } else {
        $stmt = $conn->prepare("UPDATE rooms SET room_name = ?, capacity = ?, location = ? WHERE id = ?");
        $stmt->bind_param("sisi", $name, $capacity, $location, $room_id);
        $stmt->execute();
        audit_log('ROOM_EDITED', 'room', $room_id, "$name | capacity=$capacity | $location");
        // SPRINT 3 (PRG)
        flash_set('success', "Room updated.");
        header("Location: room_control_panel.php");
        exit();
    }
}

// Delete a room only when no bookings are associated with it.
if (isset($_GET['delete_room'])) {
    csrf_verify('ROOM_DELETE');
    require_permission('rooms.manage', 'room_control_panel.php');
    $room_id = intval($_GET['delete_room']);

    $check = $conn->prepare("SELECT COUNT(*) AS total FROM bookings WHERE room_id = ?");
    $check->bind_param("i", $room_id);
    $check->execute();
    $count = $check->get_result()->fetch_assoc()['total'];

    if ($count > 0) {
        flash_set('error', "Cannot delete this room: it has $count booking(s) on record. Delete those bookings first (finished or cancelled bookings can be removed from My Bookings).");
    } else {
        $stmt = $conn->prepare("DELETE FROM rooms WHERE id = ?");
        $stmt->bind_param("i", $room_id);
        $stmt->execute();
        audit_log('ROOM_DELETED', 'room', $room_id, "Room deleted (no bookings on record)");
        flash_set('success', 'Room deleted successfully.');
    }
    header("Location: room_control_panel.php");
    exit();
}

// Cancel an administrator-managed booking for one day or an entire series.
if (isset($_GET['cancel_booking'])) {
    csrf_verify('BOOKING_CANCEL_ADMIN');
    require_permission('bookings.manage_all', 'room_control_panel.php');
    $booking_id = intval($_GET['cancel_booking']);
    $scope = ($_GET['scope'] ?? 'day') === 'series' ? 'series' : 'day';

    if ($scope === 'series') {
        $sid_stmt = $conn->prepare("SELECT series_id FROM bookings WHERE id = ? AND series_id IS NOT NULL");
        $sid_stmt->bind_param("i", $booking_id);
        $sid_stmt->execute();
        $sid_row = $sid_stmt->get_result()->fetch_assoc();
        if ($sid_row && $sid_row['series_id']) {
            $stmt = $conn->prepare("UPDATE bookings SET status = 'cancelled' WHERE series_id = ? AND status IN ('confirmed','postponed')");
            $stmt->bind_param("i", $sid_row['series_id']);
            $stmt->execute();
            audit_log('BOOKING_CANCELLED', 'booking', $booking_id, "Admin scope=series | series_id=" . $sid_row['series_id']);
            flash_set('success', 'Entire continuous meeting series cancelled successfully.');
        } else {
            flash_set('error', 'Series not found for this booking.');
        }
    } else {
        $stmt = $conn->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        audit_log('BOOKING_CANCELLED', 'booking', $booking_id, "Admin scope=day");
        flash_set('success', 'Booking cancelled successfully.');
    }
    header("Location: room_control_panel.php");
    exit();
}

// Permanently delete any booking (admin) - only allowed once finished or cancelled
if (isset($_GET['delete_booking'])) {
    csrf_verify('BOOKING_DELETE_ADMIN');
    require_permission('bookings.manage_all', 'room_control_panel.php');
    $booking_id = intval($_GET['delete_booking']);
    $check = $conn->prepare("SELECT status, booking_date, end_time FROM bookings WHERE id = ?");
    $check->bind_param("i", $booking_id);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();

    if ($row) {
        $end_datetime = $row['booking_date'] . " " . $row['end_time'];
        $is_finished = ($row['status'] === 'confirmed' && $end_datetime < date('Y-m-d H:i:s'));
        $is_deletable = $is_finished || $row['status'] === 'cancelled' || $row['status'] === 'postponed';

        if ($is_deletable) {
            $del = $conn->prepare("DELETE FROM bookings WHERE id = ?");
            $del->bind_param("i", $booking_id);
            $del->execute();
            audit_log('BOOKING_DELETED', 'booking', $booking_id, "Admin permanently deleted");
            flash_set('success', 'Booking deleted successfully.');
        } else {
            flash_set('error', 'This booking cannot be deleted yet. Only finished, cancelled or postponed bookings can be removed.');
        }
    }
    header("Location: room_control_panel.php");
    exit();
}

// Postpone an administrator-managed booking for one day or an entire series.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['postpone_booking'])) {
    csrf_verify('BOOKING_POSTPONE_ADMIN');
    require_permission('bookings.manage_all', 'room_control_panel.php');
    $booking_id = intval($_POST['booking_id']);
    $series_id = intval($_POST['series_id'] ?? 0);
    $scope = ($_POST['postpone_scope'] ?? 'day') === 'series' ? 'series' : 'day';
    $new_date = ($_POST['postponed_date'] ?? '') !== '' ? $_POST['postponed_date'] : null;
    $new_start = ($_POST['postponed_start_time'] ?? '') !== '' ? $_POST['postponed_start_time'] : null;
    $new_end = ($_POST['postponed_end_time'] ?? '') !== '' ? $_POST['postponed_end_time'] : null;
    $reason = trim($_POST['postponed_reason'] ?? '');
    if (mb_strlen($reason) > 255) $reason = mb_substr($reason, 0, 255);
    if ($new_date !== null && !valid_date($new_date)) $new_date = null;
    if ($new_start !== null && !valid_time($new_start)) $new_start = null;
    if ($new_end !== null && !valid_time($new_end)) $new_end = null;

    if ($scope === 'series' && $series_id) {
        if ($new_start !== null && $new_end !== null && $new_start >= $new_end) {
            flash_set('error', 'Start time must be before end time.');
            header("Location: room_control_panel.php");
            exit();
        }
        $set_parts = [];
        $types = "";
        $params = [];
        if ($new_start !== null) { $set_parts[] = "start_time = ?"; $types .= "s"; $params[] = $new_start; }
        if ($new_end !== null) { $set_parts[] = "end_time = ?"; $types .= "s"; $params[] = $new_end; }
        if ($reason !== '') { $set_parts[] = 'postponed_reason = ?'; $types .= 's'; $params[] = $reason; }
        if ($set_parts) {
            // Check every affected booking for conflicts before changing its time.
            if ($new_start !== null || $new_end !== null) {
                $sres = $conn->prepare("SELECT room_id, booking_date, start_time, end_time FROM bookings WHERE series_id = ? AND status = 'confirmed'");
                $sres->bind_param("i", $series_id);
                $sres->execute();
                $sres_rows = $sres->get_result();
                $conflict_msg = "";
                while ($sr = $sres_rows->fetch_assoc()) {
                    $t1 = $new_start !== null ? $new_start : substr($sr['start_time'], 0, 8);
                    $t2 = $new_end !== null ? $new_end : substr($sr['end_time'], 0, 8);
                    if (mrs_room_slot_conflicts($conn, $sr['room_id'], $sr['booking_date'], $t1, $t2, 0, $series_id) > 0) {
                        $conflict_msg = 'The new time conflicts with another booking on ' . $sr['booking_date'] . '. Please choose a different time.';
                        break;
                    }
                }
                if ($conflict_msg !== "") {
                    flash_set('error', $conflict_msg);
                    header("Location: room_control_panel.php");
                    exit();
                }
            }
            $sql = "UPDATE bookings SET " . implode(", ", $set_parts) . " WHERE series_id = ? AND status = 'confirmed'";
            $types .= "i";
            $params[] = $series_id;
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            audit_log('BOOKING_POSTPONED', 'booking', $booking_id, "Admin scope=series | series_id=$series_id | times only");
            flash_set('success', 'Continuous series postponed successfully.');
        } else {
            flash_set('error', 'Enter a new start time, end time, or both to postpone the series.');
        }
    } else {
        // A single booking requires a valid new date and start and end times.
        if (!$new_date || !valid_date($new_date)) {
            flash_set('error', 'Please choose a valid new date for the postponed booking.');
            header("Location: room_control_panel.php");
            exit();
        }
        if (!$new_start || !$new_end || !valid_time($new_start) || !valid_time($new_end)) {
            flash_set('error', 'Please choose valid new start and end times.');
            header("Location: room_control_panel.php");
            exit();
        }
        if ($new_start >= $new_end) {
            flash_set('error', 'Start time must be before end time.');
            header("Location: room_control_panel.php");
            exit();
        }

        $own = $conn->prepare("SELECT room_id FROM bookings WHERE id = ? AND status IN ('confirmed','postponed')");
        $own->bind_param("i", $booking_id);
        $own->execute();
        $own_row = $own->get_result()->fetch_assoc();

        if (!$own_row) {
            flash_set('error', 'This booking cannot be postponed.');
        } elseif (mrs_room_slot_conflicts($conn, $own_row['room_id'], $new_date, $new_start, $new_end, $booking_id) > 0) {
            flash_set('error', "That room is already booked on $new_date from $new_start to $new_end. Please choose a different date or time.");
        } else {
            $stmt = $conn->prepare("UPDATE bookings SET status = 'postponed', postponed_date = ?, postponed_start_time = ?, postponed_end_time = ?, postponed_reason = ? WHERE id = ? AND status IN ('confirmed','postponed')");
            $stmt->bind_param('ssssi', $new_date, $new_start, $new_end, $reason, $booking_id);
            $stmt->execute();
            audit_log('BOOKING_POSTPONED', 'booking', $booking_id, "Admin scope=day | new_date=$new_date | $new_start-$new_end | $reason");
            flash_set('success', 'Booking postponed successfully.');
        }
    }
    header("Location: room_control_panel.php");
    exit();
}

// Mark a reported room issue as resolved.
if (isset($_GET['resolve_issue'])) {
    csrf_verify('ISSUE_RESOLVE');
    require_permission('issues.resolve', 'room_control_panel.php');
    $issue_id = intval($_GET['resolve_issue']);
    $stmt = $conn->prepare("UPDATE room_issues SET status = 'resolved', resolved_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $issue_id);
    $stmt->execute();
    audit_log('ISSUE_RESOLVED', 'room_issue', $issue_id, "Marked resolved");
    flash_set('success', 'Issue marked as resolved.');
    header("Location: room_control_panel.php");
    exit();
}

// Delete a reported room issue only after it has been resolved.
if (isset($_GET['delete_issue'])) {
    csrf_verify('ISSUE_DELETE');
    require_permission('issues.delete', 'room_control_panel.php');
    $issue_id = intval($_GET['delete_issue']);

    $stmt = $conn->prepare("SELECT id, status FROM room_issues WHERE id = ?");
    $stmt->bind_param("i", $issue_id);
    $stmt->execute();
    $iss_row = $stmt->get_result()->fetch_assoc();

    if (!$iss_row) {
        flash_set('error', 'Issue not found.');
    } elseif ($iss_row['status'] !== 'resolved') {
        // Enforce the resolved status on the server, even if the client is bypassed.
        flash_set('error', 'Only resolved issues can be deleted.');
        audit_log('ISSUE_DELETE_DENIED', 'room_issue', $issue_id, "Rejected: status=" . $iss_row['status']);
    } else {
        $del = $conn->prepare("DELETE FROM room_issues WHERE id = ?");
        $del->bind_param("i", $issue_id);
        $del->execute();
        audit_log('ISSUE_DELETED', 'room_issue', $issue_id, "Deleted resolved issue");
        flash_set('success', 'Issue deleted.');
    }
    header("Location: room_control_panel.php");
    exit();
}

$rooms = $conn->query("SELECT * FROM rooms ORDER BY room_name");
$bookings = $conn->query("
    SELECT b.*, r.room_name, u.full_name, p.name AS projector_name, p.model AS projector_model
    FROM bookings b
    JOIN rooms r ON b.room_id = r.id
    JOIN users u ON b.user_id = u.id
    LEFT JOIN projectors p ON b.projector_id = p.id
    ORDER BY b.booking_date DESC, b.start_time DESC
    LIMIT 50
");
$now_datetime = date('Y-m-d H:i:s');
$issues = $conn->query("
    SELECT ri.*, r.room_name, u.full_name, b.meeting_title FROM room_issues ri
    JOIN rooms r ON ri.room_id = r.id
    JOIN users u ON ri.user_id = u.id
    JOIN bookings b ON ri.booking_id = b.id
    ORDER BY ri.status ASC, ri.created_at DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Control Panel</title>
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
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="card">
            <h3>Reported Issues</h3>
            <br>
            <div class="table-scroll">
                <table>
                <tr>
                    <th>Room</th>
                    <th>Reported By</th>
                    <th>Meeting</th>
                    <th>Message</th>
                    <th>Date Reported</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
                <?php if ($issues->num_rows === 0): ?>
                <tr><td colspan="7">No issues have been reported.</td></tr>
                <?php endif; ?>
                <?php while ($iss = $issues->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($iss['room_name']); ?></td>
                    <td><?php echo htmlspecialchars($iss['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($iss['meeting_title']); ?></td>
                    <td><?php echo nl2br(htmlspecialchars($iss['message'])); ?></td>
                    <td><?php echo date('d/m/Y H:i', strtotime($iss['created_at'])); ?></td>
                    <td>
                        <span class="status-tag <?php echo $iss['status'] === 'open' ? 'status-maintenance' : 'status-available'; ?>">
                            <?php echo ucfirst($iss['status']); ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($iss['status'] === 'open'): ?>
                            <a href="<?php echo csrf_url('room_control_panel.php?resolve_issue=' . $iss['id']); ?>" class="btn"
                               data-confirm="Mark this issue as resolved?"
                               data-confirm-title="Resolve Reported Issue?"
                               data-confirm-text="Mark Resolved">Mark Resolved</a>
                        <?php elseif (has_permission($_SESSION['role'] ?? '', 'issues.delete')): ?>
                            <a href="<?php echo csrf_url('room_control_panel.php?delete_issue=' . $iss['id']); ?>"
                               class="btn btn-danger"
                               data-confirm="This resolved issue will be permanently removed."
                               data-confirm-title="Delete Issue?"
                               data-confirm-text="Delete"
                               data-confirm-danger="1">Delete</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
                </table>
            </div>
        </div>

        <div class="card">
            <h3>Add New Room</h3>
            <br>
            <form method="POST" data-confirm="Add this meeting room?" data-confirm-title="Add New Room" data-confirm-text="Add Room">
                <?php echo csrf_field(); ?>
                <label>Room Name</label>
                <input type="text" name="room_name" required>

                <label>Capacity (number of people)</label>
                <input type="number" name="capacity" required>

                <label>Location</label>
                <input type="text" name="location" required>

                <button type="submit" name="add_room" class="btn">Add Room</button>
            </form>
        </div>

        <div class="card">
            <h3>All Rooms</h3>
            <br>
            <div class="table-scroll">
                <table>
                <tr>
                    <th>Name</th>
                    <th>Capacity</th>
                    <th>Location</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
                <?php while ($r = $rooms->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['room_name']); ?></td>
                    <td><?php echo $r['capacity']; ?></td>
                    <td><?php echo htmlspecialchars($r['location']); ?></td>
                    <td>
                        <span class="status-tag status-<?php echo $r['status']; ?>">
                            <?php echo $r['status'] === 'available' ? 'Available' : 'Under Maintenance'; ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <div class="mrs-kebab">
                                <button type="button" class="mrs-kebab-toggle" aria-label="Open room actions">&#8942;</button>
                                <div class="mrs-kebab-menu">
                                    <a href="<?php echo csrf_url('room_control_panel.php?toggle_status=' . $r['id']); ?>"
                                       data-confirm="Change this room's availability status?"
                                       data-confirm-title="Change Room Status?"
                                       data-confirm-text="Continue">Change Status</a>
                                    <button type="button" class="mrs-edit-room"
                                            data-id="<?php echo $r['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($r['room_name'], ENT_QUOTES); ?>"
                                            data-capacity="<?php echo $r['capacity']; ?>"
                                            data-location="<?php echo htmlspecialchars($r['location'], ENT_QUOTES); ?>">Edit Room</button>
                                    <a class="danger-item" href="<?php echo csrf_url('room_control_panel.php?delete_room=' . $r['id']); ?>"
                                       data-confirm="Delete this room permanently? This only works if it has no bookings on record."
                                       data-confirm-title="Delete Room?"
                                       data-confirm-text="Delete"
                                       data-confirm-danger="1">Delete Room</a>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                </table>
            </div>
        </div>

        <div class="card">
            <h3>All Bookings (50 Most Recent)</h3>
            <br>
            <div class="table-scroll">
                <table>
                <tr>
                    <th>Room</th>
                    <th>Staff Member</th>
                    <th>Meeting</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Accessories</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
                <?php while ($b = $bookings->fetch_assoc()):
                    $end_datetime = $b['booking_date'] . " " . $b['end_time'];
                    $is_finished = ($b['status'] === 'confirmed' && $end_datetime < $now_datetime);
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($b['room_name']); ?></td>
                    <td><?php echo htmlspecialchars($b['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($b['meeting_title']); ?><?php if (!empty($b['series_id'])): ?><span class="series-tag">Continuous</span><?php endif; ?></td>
                    <td><?php echo $b['booking_date']; ?></td>
                    <td><?php echo substr($b['start_time'],0,5) . " - " . substr($b['end_time'],0,5); ?></td>
                    <td><?php
                        if ($b['Accessories'] === 'none') {
                            echo '&mdash;';
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
                        <?php elseif ($b['status'] === 'confirmed'): ?>
                            Confirmed
                        <?php elseif ($b['status'] === 'postponed'): ?>
                            Postponed
                            <?php if ($b['postponed_date']): ?>
                                <br><small>New time: <?php echo $b['postponed_date'] . " " . substr($b['postponed_start_time'],0,5) . "-" . substr($b['postponed_end_time'],0,5); ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            Cancelled
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (($b['status'] === 'confirmed' || $b['status'] === 'postponed') && !$is_finished): ?>
                            <div class="mrs-kebab">
                                <button type="button" class="mrs-kebab-toggle" aria-label="Open booking actions">&#8942;</button>
                                <div class="mrs-kebab-menu">
                                    <?php if (!empty($b['series_id'])): ?>
                                        <a class="danger-item" href="<?php echo csrf_url('room_control_panel.php?cancel_booking=' . $b['id'] . '&scope=day'); ?>"
                                           data-confirm="Cancel this day only? Other continuous sessions will remain."
                                           data-confirm-title="Cancel This Day?"
                                           data-confirm-text="OK"
                                           data-confirm-danger="1">Cancel this day</a>
                                        <a class="danger-item" href="<?php echo csrf_url('room_control_panel.php?cancel_booking=' . $b['id'] . '&scope=series'); ?>"
                                           data-confirm="Cancel all remaining sessions of this continuous meeting?"
                                           data-confirm-title="Cancel Entire Series?"
                                           data-confirm-text="OK"
                                           data-confirm-danger="1">Cancel entire series</a>
                                        <button type="button" class="mrs-postpone-booking" data-id="<?php echo $b['id']; ?>" data-series="1" data-series-id="<?php echo $b['series_id']; ?>">Postpone</button>
                                    <?php else: ?>
                                        <a class="danger-item" href="<?php echo csrf_url('room_control_panel.php?cancel_booking=' . $b['id']); ?>"
                                           data-confirm="Cancel this booking?"
                                           data-confirm-title="Cancel Booking?"
                                           data-confirm-text="OK"
                                           data-confirm-danger="1">Cancel</a>
                                        <button type="button" class="mrs-postpone-booking" data-id="<?php echo $b['id']; ?>">Postpone</button>
                                    <?php endif; ?>
                                    <?php if ($b['status'] === 'postponed'): ?>
                                        <a class="danger-item" href="<?php echo csrf_url('room_control_panel.php?delete_booking=' . $b['id']); ?>"
                                           data-confirm="Delete this postponed booking permanently? This cannot be undone."
                                           data-confirm-title="Delete Booking?"
                                           data-confirm-text="Delete"
                                           data-confirm-danger="1">Delete</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php elseif ($is_finished || $b['status'] === 'cancelled'): ?>
                            <?php /* Completed or cancelled bookings use a direct delete action. */ ?>
                            <a href="<?php echo csrf_url('room_control_panel.php?delete_booking=' . $b['id']); ?>"
                               class="btn btn-danger"
                               data-confirm="Delete this booking permanently? This cannot be undone."
                               data-confirm-title="Delete Booking?"
                               data-confirm-text="Delete"
                               data-confirm-danger="1">Delete</a>
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
