<?php
// Cron: Tuma email za kumbusho kwa bookings za kesho.
// Endesha: php cron/send_reminders.php
// Windows Task Scheduler: run PHP with this file's full path as its argument.
// Linux cron:              0 18 * * * php /path/to/project/cron/send_reminders.php
//
// Sprint 4:
//  - Tumia tarehe HALISI (eff date): booking ya "postponed" inatumea
//    kumbusho kwenye postponed_date yake (siku mpya), siyo tarehe ya zamani.
//  - Kuzuia marudio: kila (booking, sifa ya kumbusho) marudio MOJA tu —
//    reminder_log UNIQUE (booking_id, for_date). Booking iliyosogozwa
//    tena baada ya kumbusho kwa siku nyingine hupata kumbusho jipya.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/mailer.php';
require __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/booking_rules.php';

$tomorrow = date('Y-m-d', strtotime('+1 day'));
echo "Sending reminders for bookings on $tomorrow...\n";

$eff_d = mrs_eff_date_sql('b');
$sql = "
    SELECT b.id, b.status, b.meeting_title, b.booking_date, b.start_time, b.end_time,
           b.postponed_date, b.postponed_start_time, b.postponed_end_time,
           r.room_name, u.full_name, u.email,
           " . mrs_eff_start_sql('b') . " AS eff_start,
           " . mrs_eff_end_sql('b') . " AS eff_end
    FROM bookings b
    JOIN rooms r ON b.room_id = r.id
    JOIN users u ON b.user_id = u.id
    WHERE b.status IN ('confirmed','postponed')
      AND $eff_d = ?
      AND u.status = 'active'
      AND NOT EXISTS (
          SELECT 1 FROM reminder_log rl
          WHERE rl.booking_id = b.id AND rl.for_date = ?
      )
    ORDER BY eff_start ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $tomorrow, $tomorrow);
$stmt->execute();
$result = $stmt->get_result();

$count = 0;
while ($b = $result->fetch_assoc()) {
    $is_postponed = ($b['status'] === 'postponed');
    $body = "<h3>Meeting Reminder</h3>
        <p>Hi " . htmlspecialchars($b['full_name']) . ",</p>
        <p>This is a reminder that you have a meeting <b>tomorrow</b>:</p>
        <ul>
            <li><b>Meeting:</b> " . htmlspecialchars($b['meeting_title']) . "</li>
            <li><b>Room:</b> " . htmlspecialchars($b['room_name']) . "</li>
            <li><b>Date:</b> " . htmlspecialchars(date('l, d F Y', strtotime($tomorrow))) . "</li>
            <li><b>Time:</b> " . htmlspecialchars(substr($b['eff_start'], 0, 5) . " - " . substr($b['eff_end'], 0, 5)) . "</li>"
            . ($is_postponed ? "<li><b>Status:</b> This meeting was postponed — the date/time above is the new schedule.</li>" : "")
            . "</ul>
        <p>Please arrive on time.</p>
        <p>— NISHATI Meeting Room Booking System</p>";

    $subject = ($is_postponed ? "Reminder (new time): " : "Reminder: ") . $b['meeting_title'] . " tomorrow";
    $sent = send_booking_email($b['email'], $b['full_name'], $subject, $body);

    if ($sent) {
        $ins = $conn->prepare("INSERT IGNORE INTO reminder_log (booking_id, for_date) VALUES (?, ?)");
        $ins->bind_param("is", $b['id'], $tomorrow);
        $ins->execute();
        $count++;
        echo "  [OK] #{$b['id']} {$b['full_name']} <{$b['email']}> — {$b['meeting_title']}"
           . ($is_postponed ? " (postponed to $tomorrow)" : "") . "\n";
    } else {
        echo "  [FAIL] #{$b['id']} {$b['email']} — email not sent (check mail settings enabled/SMTP)\n";
    }
}

audit_log('REMINDERS_SENT', 'reminder', null, "Sent $count reminder(s) for $tomorrow", null);
echo "Done. $count reminder(s) sent.\n";
?>
