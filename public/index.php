<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require __DIR__ . '/../includes/mailer.php';
require __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/booking_rules.php';
check_login();
$can_book = has_permission($_SESSION['role'] ?? '', 'bookings.create');

$day_names = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$today_day = $day_names[date('w')];
$today_date = date('d/m/Y');

$flash = flash_pull();
$message = $flash['success'];
$error = $flash['error'];

function mrsWeekdayDates($start, $end, $weekday) {
    $dates = [];
    $d = new DateTime($start);
    $endD = new DateTime($end);
    while ($d <= $endD) {
        if ((int)$d->format('w') === (int)$weekday) {
            $dates[] = $d->format('Y-m-d');
        }
        $d->modify('+1 day');
    }
    return $dates;
}

// Create a new booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_room'])) {
    csrf_verify('BOOKING_CREATE');
    // ROLES & PERMISSIONS â€” mabadiliko ya server-side (siyo UI tu)
    $room_id = intval($_POST['room_id']);
    if (!has_permission($_SESSION['role'] ?? '', 'bookings.create')) {
        audit_log('ACCESS_DENIED', 'booking', null,
            "Booking blocked: no permission for bookings.create (role=" . ($_SESSION['role'] ?? '') . ")");
        $error = "You do not have permission to book meeting rooms. Please contact the administrator.";
    } elseif (!has_room_book_permission($_SESSION['role'] ?? '', $room_id)) {
        // PER-ROOM: admin akakataa chumba husika hii hapa â€” hata POST ya moja
        // kwa moja haikubaliki (audit + ujumbe sawa na wa UI modal).
        audit_log('ACCESS_DENIED', 'booking', $room_id,
            "Booking blocked: room permission denied (role=" . ($_SESSION['role'] ?? '') . ")");
        $error = "ACCESS DENIED TO BOOK THIS ROOM";
    } else {
    $title = trim($_POST['meeting_title'] ?? '');
    $date = $_POST['booking_date'] ?? '';
    $start_hour = $_POST['start_time_hour'] ?? '';
    $start_minute = $_POST['start_time_minute'] ?? '';
    $end_hour = $_POST['end_time_hour'] ?? '';
    $end_minute = $_POST['end_time_minute'] ?? '';
    $start = ($start_hour !== '' && $start_minute !== '') ? "$start_hour:$start_minute" : '';
    $end = ($end_hour !== '' && $end_minute !== '') ? "$end_hour:$end_minute" : '';
    $Accessories = in_array($_POST['Accessories'] ?? 'none', ['none', 'tv', 'projector']) ? $_POST['Accessories'] : 'none';
    $projector_id = ($Accessories === 'projector') ? intval($_POST['projector_id'] ?? 0) : null;
    $booking_type = ($_POST['booking_type'] ?? 'single') === 'continuous' ? 'continuous' : 'single';
    $end_date = trim($_POST['booking_end_date'] ?? '');
    $meeting_weekday = isset($_POST['meeting_weekday']) ? intval($_POST['meeting_weekday']) : -1;

    // Ukaguzi wa format: siyo SQL injection lakini pia siyo data isiyo sahihi
    if (!valid_date($date)) {
        $date = '';
    }
    if ($end_date !== '' && !valid_date($end_date)) {
        $end_date = '';
    }
    if (!valid_time($start)) { $start = ''; }
    if (!valid_time($end))   { $end = ''; }
    if (mb_strlen($title) > 150) { $title = mb_substr($title, 0, 150); }

    if (!$title || !$date || !$start || !$end) {
        $error = "Please fill in all fields.";
    } else {
        // Chumba: kipo na kipo hali ya kuweza kubook? (siyo UI tu â€” server guard)
        $rstmt = $conn->prepare("SELECT status FROM rooms WHERE id = ?");
        $rstmt->bind_param("i", $room_id);
        $rstmt->execute();
        $room_state = $rstmt->get_result()->fetch_assoc();
        $projector_state = null;
        if ($Accessories === 'projector' && $projector_id) {
            $psel = $conn->prepare("SELECT id FROM projectors WHERE id = ? AND status = 'active'");
            $psel->bind_param("i", $projector_id);
            $psel->execute();
            $projector_state = $psel->get_result()->fetch_assoc();
        }
        if ($date < date('Y-m-d')) {
            // KALENDA: leo + future tu â€” hata POST ya moja kwa moja haiwezi kubook tarehe ya zamani
            $error = "Booking date cannot be in the past.";
        } elseif ($start >= $end) {
            $error = "Start time must be before end time.";
        } elseif ($Accessories === 'projector' && !$projector_id) {
            $error = "Please select a projector.";
        } elseif (!$room_state) {
            $error = "This room does not exist.";
        } elseif ($room_state['status'] !== 'available') {
            $error = "This room is under maintenance and cannot be booked right now.";
        } elseif ($Accessories === 'projector' && !$projector_state) {
            $error = "The selected projector is not available.";
        } elseif ($booking_type === 'continuous') {
            if ($meeting_weekday < 1 || $meeting_weekday > 5) {
                $error = "Please select a day of the week (Mondayâ€“Friday) for your continuous meeting.";
            } elseif (!$end_date) {
                $error = "Please choose an end date for the continuous meeting.";
            } elseif ($end_date < $date) {
                $error = "End date must be on or after the start date.";
            } else {
                $span = (new DateTime($date))->diff(new DateTime($end_date))->days + 1;
                if ($span > 30) {
                    $error = "Continuous meetings cannot exceed 30 days. Please choose a shorter range.";
                } elseif ((int)(new DateTime($date))->format('w') !== $meeting_weekday) {
                    $wd_name = $day_names[$meeting_weekday];
                    $error = "Start date must be a $wd_name. Please choose a $wd_name or change the meeting day.";
                } else {
                    $session_dates = mrsWeekdayDates($date, $end_date, $meeting_weekday);
                    $wd_name = $day_names[$meeting_weekday];
                    $wd_plural = $wd_name . 's';
                    if (count($session_dates) < 2) {
                        $error = "Range must include at least 2 $wd_plural â€” extend the end date.";
                    } else {
                        // RACE (#6): transaction + lock KABLA ya kupima mgongano wa sessions
                        $lock_proj = ($Accessories === 'projector' && $projector_id) ? $projector_id : 0;
                        $locked = mrs_lock_slot($conn, $room_id, $lock_proj);

                        $conflict_date = '';
                        $room_conflict = false;
                        $projector_conflict = false;
                        $projector_busy_label = "";

                        if ($locked) foreach ($session_dates as $sd) {
                            if (mrs_room_slot_conflicts($conn, $room_id, $sd, $start, $end) > 0) {
                                $room_conflict = true;
                                $conflict_date = $sd;
                                break;
                            }
                            if ($Accessories === 'projector' && $projector_id && !$projector_conflict) {
                                $pbusy = mrs_projector_slot_busy($conn, $projector_id, $sd, $start, $end);
                                if ($pbusy) {
                                    $projector_conflict = true;
                                    $conflict_date = $sd;
                                    $projector_busy_label = $pbusy['meeting_title'] . ", " . substr($pbusy['eff_start'], 0, 5) . "-" . substr($pbusy['eff_end'], 0, 5) . " (" . $pbusy['full_name'] . ")";
                                }
                            }
                        }

                        if (!$locked) {
                            $error = "This room is no longer available. Please refresh the page and try again.";
                        } elseif ($room_conflict) {
                            $error = "This room is already booked on " . htmlspecialchars($conflict_date) . " for that time. Continuous booking cancelled â€” please choose another range or room.";
                            $conn->rollback();
                        } elseif ($projector_conflict) {
                            $error = "This projector is already taken on " . htmlspecialchars($conflict_date) . ": " . htmlspecialchars($projector_busy_label) . ". Continuous booking cancelled â€” please choose another projector or range.";
                            $conn->rollback();
                        } else {
                            $insert = $conn->prepare("INSERT INTO bookings (room_id, user_id, meeting_title, booking_date, start_time, end_time, Accessories, projector_id, series_end) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $insert->bind_param("iisssssis", $room_id, $_SESSION['user_id'], $title, $session_dates[0], $start, $end, $Accessories, $projector_id, $end_date);
                            $insert->execute();
                            $series_id = $conn->insert_id;

                            $update_series = $conn->prepare("UPDATE bookings SET series_id = ? WHERE id = ?");
                            $update_series->bind_param("ii", $series_id, $series_id);
                            $update_series->execute();

                            $multi = $conn->prepare("INSERT INTO bookings (room_id, user_id, meeting_title, booking_date, start_time, end_time, Accessories, projector_id, series_id, series_end) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            foreach ($session_dates as $i => $sd) {
                                if ($i === 0) continue;
                                $multi->bind_param("iisssssiis", $room_id, $_SESSION['user_id'], $title, $sd, $start, $end, $Accessories, $projector_id, $series_id, $end_date);
                                $multi->execute();
                            }
                            $conn->commit();

                            $message = count($session_dates) . " continuous sessions booked successfully.";

                            $room_stmt = $conn->prepare("SELECT room_name FROM rooms WHERE id = ?");
                            $room_stmt->bind_param("i", $room_id);
                            $room_stmt->execute();
                            $room_name = $room_stmt->get_result()->fetch_assoc()['room_name'];

                            audit_log('BOOKING_CREATED', 'booking', $series_id, "Continuous: $title | $room_name | " . $session_dates[0] . " to $end_date | " . count($session_dates) . " sessions");

                            $projector_line = "";
                            if ($Accessories === 'projector' && $projector_id) {
                                $pname_stmt = $conn->prepare("SELECT name, model FROM projectors WHERE id = ?");
                                $pname_stmt->bind_param("i", $projector_id);
                                $pname_stmt->execute();
                                $prow = $pname_stmt->get_result()->fetch_assoc();
                                if ($prow) {
                                    $projector_line = "<li><b>Projector:</b> " . htmlspecialchars($prow['name'] . ($prow['model'] ? " (" . $prow['model'] . ")" : "")) . "</li>";
                                }
                            }

                            $session_items = "";
                            foreach ($session_dates as $sd) {
                                $session_items .= "<li>" . htmlspecialchars(date('D d/m/Y', strtotime($sd))) . " â€” " . htmlspecialchars($start) . " - " . htmlspecialchars($end) . "</li>";
                            }

                            $body = "<h3>Continuous Booking Confirmed</h3>
                                <p>Hi " . htmlspecialchars($_SESSION['full_name']) . ",</p>
                                <p>Your continuous meeting has been saved in the system:</p>
                                <ul>
                                    <li><b>Room:</b> " . htmlspecialchars($room_name) . "</li>
                                    <li><b>Meeting:</b> " . htmlspecialchars($title) . "</li>
                                    <li><b>Day:</b> " . htmlspecialchars($day_names[$meeting_weekday] ?? '') . "</li>
                                    <li><b>Time:</b> " . htmlspecialchars($start) . " - " . htmlspecialchars($end) . "</li>
                                    <li><b>Period:</b> " . htmlspecialchars($date) . " to " . htmlspecialchars($end_date) . "</li>
                                    " . $projector_line . "
                                </ul>
                                <p><b>Sessions:</b></p>
                                <ul>" . $session_items . "</ul>";
                            send_booking_email($_SESSION['email'] ?? '', $_SESSION['full_name'], "Continuous Booking Confirmation - " . $room_name, $body);

                            // SPRINT 3 (PRG): mafanikio = mara moja kwenye GET (refresh haitakariri)
                            flash_set('success', $message);
                            header('Location: index.php');
                            exit();
                        }
                    }
                }
            }
        } else {
            // RACE (#6): transaction + lock ya chumba KABLA ya kuona mgongano
            $lock_proj = ($Accessories === 'projector' && $projector_id) ? $projector_id : 0;
            $locked = mrs_lock_slot($conn, $room_id, $lock_proj);

            // Kagua mgongano â€” booked ILIYOSOGOEZWA pia inashika nafasi yake mpya
            $room_has_conflict = $locked && mrs_room_slot_conflicts($conn, $room_id, $date, $start, $end) > 0;

            $projector_conflict = false;
            $projector_busy_label = "";

            if ($locked && !$room_has_conflict && $Accessories === 'projector' && $projector_id) {
                $pbusy = mrs_projector_slot_busy($conn, $projector_id, $date, $start, $end);
                if ($pbusy) {
                    $projector_conflict = true;
                    $projector_busy_label = $pbusy['meeting_title'] . ", " . substr($pbusy['eff_start'], 0, 5) . "-" . substr($pbusy['eff_end'], 0, 5) . " (" . $pbusy['full_name'] . ")";
                }
            }

            if (!$locked) {
                $error = "This room is no longer available. Please refresh the page and try again.";
            } elseif ($room_has_conflict) {
                $error = "This room is already booked for that time. Please choose another time.";
                $conn->rollback();
            } elseif ($projector_conflict) {
                $error = "This projector is already taken for that time: " . htmlspecialchars($projector_busy_label) . ". Please choose another projector or time.";
                $conn->rollback();
            } else {
                $stmt = $conn->prepare("INSERT INTO bookings (room_id, user_id, meeting_title, booking_date, start_time, end_time, Accessories, projector_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iisssssi", $room_id, $_SESSION['user_id'], $title, $date, $start, $end, $Accessories, $projector_id);
                $stmt->execute();
                $new_booking_id = $conn->insert_id;
                $conn->commit();
                $message = "Room booked successfully.";

                $room_stmt = $conn->prepare("SELECT room_name FROM rooms WHERE id = ?");
                $room_stmt->bind_param("i", $room_id);
                $room_stmt->execute();
                $room_name = $room_stmt->get_result()->fetch_assoc()['room_name'];

                audit_log('BOOKING_CREATED', 'booking', $new_booking_id, "Single: $title | $room_name | $date $start-$end");

                $projector_line = "";
                if ($Accessories === 'projector' && $projector_id) {
                    $pname_stmt = $conn->prepare("SELECT name, model FROM projectors WHERE id = ?");
                    $pname_stmt->bind_param("i", $projector_id);
                    $pname_stmt->execute();
                    $prow = $pname_stmt->get_result()->fetch_assoc();
                    if ($prow) {
                        $projector_line = "<li><b>Projector:</b> " . htmlspecialchars($prow['name'] . ($prow['model'] ? " (" . $prow['model'] . ")" : "")) . "</li>";
                    }
                }

                $body = "<h3>Booking Confirmed</h3>
                    <p>Hi " . htmlspecialchars($_SESSION['full_name']) . ",</p>
                    <p>Your booking has been saved in the system:</p>
                    <ul>
                        <li><b>Room:</b> " . htmlspecialchars($room_name) . "</li>
                        <li><b>Meeting:</b> " . htmlspecialchars($title) . "</li>
                        <li><b>Date:</b> " . htmlspecialchars($date) . "</li>
                        <li><b>Time:</b> " . htmlspecialchars($start) . " - " . htmlspecialchars($end) . "</li>
                        " . $projector_line . "
                    </ul>";
                send_booking_email($_SESSION['email'] ?? '', $_SESSION['full_name'], "Booking Confirmation - " . $room_name, $body);

                // SPRINT 3 (PRG): mafanikio = mara moja kwenye GET (refresh haitakariri)
                flash_set('success', $message);
                header('Location: index.php');
                exit();
            }
        }
    }
    } // end: bookings.create permission
}

$rooms = $conn->query("SELECT * FROM rooms ORDER BY room_name");

$now_time = date('H:i:s');
$now_date = date('Y-m-d');
$eff_date = mrs_eff_date_sql();
$eff_start = mrs_eff_start_sql();
$eff_end = mrs_eff_end_sql();
$ongoing_check = $conn->prepare("SELECT id FROM bookings WHERE room_id = ? AND status IN ('confirmed','postponed') AND $eff_date = ? AND $eff_start <= ? AND $eff_end > ?");
$upcoming_check = $conn->prepare("SELECT $eff_date AS booking_date, $eff_start AS start_time, $eff_end AS end_time, Accessories FROM bookings WHERE room_id = ? AND status IN ('confirmed','postponed') AND ($eff_date > ? OR ($eff_date = ? AND $eff_end > ?)) ORDER BY $eff_date ASC, $eff_start ASC LIMIT 20");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meeting Room Booking System</title>
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
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="card today-card" style="text-align:center; font-weight:600;">
            <span id="mrsTodayDate">Today is <?php echo $today_day . ", " . $today_date; ?></span>
            <span class="live-clock" title="Live clock">
                <svg class="clock-ico" width="16" height="16" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.2" stroke-linecap="round"
                     stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"/>
                    <path d="M12 7v5.3l3.2 1.9"/>
                </svg>
                <span class="live-clock-time" id="mrsClockTime">--:--:--</span>
                <span class="live-clock-zone" id="mrsClockZone"></span>
            </span>
        </div>
        <script>
        /* Saa halisi (real-time clock) ndani ya box la tarehe:
           - inasomea KILA SEKUNDI kutoka saa ya kompyuta/phone ya mtumiaji
           - seconds zinajibadilisha wenyewe; tarehe pia hudondoka ikifika usiku */
        (function () {
            var DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            function pad(n) { return (n < 10 ? '0' : '') + n; }
            var timeEl = document.getElementById('mrsClockTime');
            var dateEl = document.getElementById('mrsTodayDate');
            var zoneEl = document.getElementById('mrsClockZone');
            /* Jina la timezone (mf. GMT+3 / EAT) kutoka kwenye browser */
            try {
                var parts = new Intl.DateTimeFormat(undefined, { timeZoneName: 'short' }).formatToParts(new Date());
                for (var i = 0; i < parts.length; i++) {
                    if (parts[i].type === 'timeZoneName') { if (zoneEl) zoneEl.textContent = parts[i].value; break; }
                }
            } catch (e) { if (zoneEl) zoneEl.textContent = ''; }
            function tick() {
                var d = new Date();
                if (timeEl) {
                    timeEl.textContent = pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
                    /* forced reflow = animation ya "tick" inarudia kila sekundi */
                    timeEl.classList.remove('tick');
                    void timeEl.offsetWidth;
                    timeEl.classList.add('tick');
                }
                if (dateEl) {
                    dateEl.textContent = 'Today is ' + DAYS[d.getDay()] + ', '
                        + pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear();
                }
            }
            tick();
            setInterval(tick, 1000);
        })();
        </script>

        <h2>Available Rooms</h2>
        <p style="margin:-8px 0 15px; color:var(--text); font-size:14px;">Book your meeting space in seconds.</p>
        <div class="room-grid">
            <?php while ($room = $rooms->fetch_assoc()):
                $is_ongoing = false;
                if ($room['status'] === 'available') {
                    $ongoing_check->bind_param("isss", $room['id'], $now_date, $now_time, $now_time);
                    $ongoing_check->execute();
                    $ongoing_check->store_result();
                    $is_ongoing = $ongoing_check->num_rows > 0;
                }

                // Bookings zijazo za chumba hiki (kutoka DB, si hardcoded)
                $upcoming_check->bind_param("isss", $room['id'], $now_date, $now_date, $now_time);
                $upcoming_check->execute();
                $upcoming_result = $upcoming_check->get_result();
                $upcoming_rows = [];
                while ($u = $upcoming_result->fetch_assoc()) {
                    $upcoming_rows[] = $u;
                }
            ?>
                <div class="room-col">
                <div class="card room-card">
                    <h3><?php echo htmlspecialchars($room['room_name']); ?></h3>
                    <p>Location: <?php echo htmlspecialchars($room['location']); ?></p>
                    <p>Capacity: <?php echo $room['capacity']; ?> people</p>
                    <br>
                    <?php if ($room['status'] !== 'available'): ?>
                        <span class="status-tag status-maintenance">Under Maintenance</span>
                    <?php elseif ($is_ongoing): ?>
                        <span class="status-tag status-ongoing">In a Meeting Now</span>
                    <?php else: ?>
                        <span class="status-tag status-available">Available</span>
                    <?php endif; ?>
                    <br><br>
                    <?php if ($room['status'] === 'available' && $can_book): ?>
                        <?php $room_can_book = has_room_book_permission($_SESSION['role'] ?? '', $room['id']); ?>
                        <button type="button" class="btn mrs-book-room" data-template="book-form-<?php echo $room['id']; ?>" data-room-name="<?php echo htmlspecialchars($room['room_name'], ENT_QUOTES); ?>" data-allowed="<?php echo $room_can_book ? '1' : '0'; ?>">Book</button>

                        <template id="book-form-<?php echo $room['id']; ?>">
                            <form method="POST">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                                <label>Meeting Title</label>
                                <input type="text" name="meeting_title" required>

                                <label>Booking Type</label>
                                <div class="booking-type-row">
                                    <label class="booking-type-option">
                                        <input type="radio" name="booking_type" value="single" checked> Single meeting
                                    </label>
                                    <label class="booking-type-option">
                                        <input type="radio" name="booking_type" value="continuous"> Continuous meeting
                                    </label>
                                </div>

                                <div class="continuous-options" style="display:none;">
                                    <label>Meeting Day (Mondayâ€“Friday)</label>
                                    <div class="weekday-row">
                                        <label class="weekday-option"><input type="radio" name="meeting_weekday" value="1"> Mon</label>
                                        <label class="weekday-option"><input type="radio" name="meeting_weekday" value="2"> Tue</label>
                                        <label class="weekday-option"><input type="radio" name="meeting_weekday" value="3"> Wed</label>
                                        <label class="weekday-option"><input type="radio" name="meeting_weekday" value="4"> Thu</label>
                                        <label class="weekday-option"><input type="radio" name="meeting_weekday" value="5"> Fri</label>
                                    </div>

                                    <div id="continuous-start-slot"></div>

                                    <div id="end-date-group">
                                        <label id="booking-end-label">Ends on (max 30 days)</label>
                                        <input type="date" name="booking_end_date">
                                    </div>
                                    <p class="series-preview" id="series-preview"></p>
                                </div>

                                <div id="date-field-group">
                                    <label id="booking-date-label">Date</label>
                                    <input type="date" name="booking_date" min="<?php echo date('Y-m-d'); ?>" required>
                                </div>

                                <label>Start Time</label>
                                <div style="display:flex; gap:8px; align-items:center; margin-bottom:15px;">
                                    <select name="start_time_hour" style="margin-bottom:0;" required>
                                        <option value="">HH</option>
                                        <?php for ($h = 0; $h < 24; $h++): $hh = str_pad($h, 2, '0', STR_PAD_LEFT); ?>
                                            <option value="<?php echo $hh; ?>"><?php echo $hh; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <span>:</span>
                                    <select name="start_time_minute" style="margin-bottom:0;" required>
                                        <option value="">MM</option>
                                        <?php foreach (['00','15','30','45'] as $mm): ?>
                                            <option value="<?php echo $mm; ?>"><?php echo $mm; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span style="font-size:12px; color:var(--text-muted);">(24hr)</span>
                                </div>

                                <label>End Time</label>
                                <div style="display:flex; gap:8px; align-items:center; margin-bottom:15px;">
                                    <select name="end_time_hour" style="margin-bottom:0;" required>
                                        <option value="">HH</option>
                                        <?php for ($h = 0; $h < 24; $h++): $hh = str_pad($h, 2, '0', STR_PAD_LEFT); ?>
                                            <option value="<?php echo $hh; ?>"><?php echo $hh; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <span>:</span>
                                    <select name="end_time_minute" style="margin-bottom:0;" required>
                                        <option value="">MM</option>
                                        <?php foreach (['00','15','30','45'] as $mm): ?>
                                            <option value="<?php echo $mm; ?>"><?php echo $mm; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span style="font-size:12px; color:var(--text-muted);">(24hr)</span>
                                </div>

                                <label>Accessories</label>
                                <div style="display:flex; gap:16px; margin-bottom:15px;">
                                    <label style="display:flex; align-items:center; gap:6px; font-weight:400;">
                                        <input type="radio" name="Accessories" value="none" style="width:auto; margin-bottom:0;" checked> None
                                    </label>
                                    <label style="display:flex; align-items:center; gap:6px; font-weight:400;">
                                        <input type="radio" name="Accessories" value="tv" style="width:auto; margin-bottom:0;"> TV
                                    </label>
                                    <label style="display:flex; align-items:center; gap:6px; font-weight:400;">
                                        <input type="radio" name="Accessories" value="projector" style="width:auto; margin-bottom:0;"> Projector
                                    </label>
                                </div>

                                <div id="projector-picker" class="projector-picker" style="display:none;">
                                    <label>Select Projector</label>
                                    <select name="projector_id" id="projector-select" required>
                                        <option value="">Choose date &amp; time first...</option>
                                    </select>
                                    <p id="projector-hint" class="projector-hint"></p>
                                </div>

                                <div class="mrs-modal-actions">
                                    <button type="button" class="btn btn-secondary" data-modal-cancel>Cancel</button>
                                    <button type="submit" name="book_room" class="btn">Confirm Booking</button>
                                </div>
                            </form>
                        </template>
                    <?php elseif ($room['status'] === 'available'): ?>
                        <span class="perm-note">Booking is not permitted for your role.</span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($upcoming_rows)): ?>
                <div class="card upcoming-card">
                    <h4 class="upcoming-title"><?php echo htmlspecialchars($room['room_name']); ?> &middot; Upcoming Bookings <span class="upcoming-count"><?php echo count($upcoming_rows); ?></span></h4>
                    <ul class="upcoming-list">
                        <?php foreach ($upcoming_rows as $u):
                            $acc_label = '';
                            if (($u['Accessories'] ?? 'none') === 'tv') {
                                $acc_label = ' (TV)';
                            } elseif (($u['Accessories'] ?? 'none') === 'projector') {
                                $acc_label = ' (Projector)';
                            }
                        ?>
                            <li><span class="up-day"><?php echo date('D', strtotime($u['booking_date'])); ?></span> <?php echo date('d/m/Y', strtotime($u['booking_date'])); ?>, <?php echo substr($u['start_time'],0,5); ?> - <?php echo substr($u['end_time'],0,5); ?><?php echo htmlspecialchars($acc_label); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                </div>
            <?php endwhile; ?>
        </div>
    </div>

    <div class="site-footer">
        <div class="footer-title">NISHATI MEETING ROOM BOOKING SYSTEM</div>
        <p>Developed by the ICT Unit</p>
        <p>&copy; <?php echo date('Y'); ?> All Rights Reserved</p>
    </div>
</body>
</html>
