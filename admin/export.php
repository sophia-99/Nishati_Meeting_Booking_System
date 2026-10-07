<?php
// Stream all report data as a multi-sheet Excel-compatible .xls file.
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/auth.php';
check_login();
check_admin('reports.export');
require_permission('reports.export');

$type = trim($_GET['type'] ?? 'all');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$room_filter = intval($_GET['room'] ?? 0);
$status_filter = trim($_GET['status'] ?? '');

// Build bookings WHERE clause
$where = "1=1";
$types = "";
$params = [];
if ($from !== '') { $where .= " AND b.booking_date >= ?"; $types .= "s"; $params[] = $from; }
if ($to !== '')   { $where .= " AND b.booking_date <= ?"; $types .= "s"; $params[] = $to; }
if ($room_filter > 0) { $where .= " AND b.room_id = ?"; $types .= "i"; $params[] = $room_filter; }
if ($status_filter !== '' && in_array($status_filter, ['confirmed','postponed','cancelled'])) {
    $where .= " AND b.status = ?"; $types .= "s"; $params[] = $status_filter;
}

function esc_xml($s) {
    return htmlspecialchars(strval($s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xml_cell($value, $is_header = false, $is_number = false) {
    $style = $is_header ? ' ss:StyleID="header"' : '';
    if ($is_number && is_numeric($value)) {
        return '<Cell' . $style . '><Data ss:Type="Number">' . esc_xml($value) . '</Data></Cell>';
    }
    return '<Cell' . $style . '><Data ss:Type="String">' . esc_xml($value) . '</Data></Cell>';
}

function xml_row($cells) {
    $out = '<Row>';
    foreach ($cells as $c) {
        if (isset($c['header'])) $out .= xml_cell($c['header'], true);
        elseif (isset($c['num'])) $out .= xml_cell($c['num'], false, true);
        else $out .= xml_cell($c['str'] ?? '');
    }
    return $out . '</Row>';
}

function xml_sheet_open($name) {
    return '<Worksheet ss:Name="' . esc_xml($name) . '"><Table>';
}
function xml_sheet_close() {
    return '</Table></Worksheet>';
}

// --- Gather data ---
$now = date('Y-m-d H:i:s');
$month_start = date('Y-m-01');
$data = [];

// Summary
$sum = [];
$r = $conn->query("SELECT COUNT(*) c FROM bookings")->fetch_assoc(); $sum['Total Bookings'] = (int)$r['c'];
$r = $conn->query("SELECT COUNT(*) c FROM bookings WHERE status='confirmed'")->fetch_assoc(); $sum['Confirmed'] = (int)$r['c'];
$r = $conn->query("SELECT COUNT(*) c FROM bookings WHERE status='postponed'")->fetch_assoc(); $sum['Postponed'] = (int)$r['c'];
$r = $conn->query("SELECT COUNT(*) c FROM bookings WHERE status='cancelled'")->fetch_assoc(); $sum['Cancelled'] = (int)$r['c'];
$r = $conn->query("SELECT COUNT(*) c FROM bookings WHERE booking_date >= '$month_start'")->fetch_assoc(); $sum['Bookings This Month'] = (int)$r['c'];
$r = $conn->query("SELECT COUNT(*) c FROM rooms WHERE status='available'")->fetch_assoc(); $sum['Active Rooms'] = (int)$r['c'];
$r = $conn->query("SELECT COUNT(*) c FROM users WHERE status='active'")->fetch_assoc(); $sum['Active Users'] = (int)$r['c'];
$r = $conn->query("SELECT COUNT(*) c FROM projectors WHERE status='active'")->fetch_assoc(); $sum['Active Projectors'] = (int)$r['c'];
$r = $conn->query("SELECT COUNT(*) c FROM room_issues WHERE status='open'")->fetch_assoc(); $sum['Open Room Issues'] = (int)$r['c'];
$r = $conn->query("SELECT COUNT(*) c FROM audit_log")->fetch_assoc(); $sum['Audit Entries'] = (int)$r['c'];
$sum['Report Generated'] = date('d/m/Y H:i:s');
$filter_label = ($from ?: 'start') . ' to ' . ($to ?: 'today');
if ($room_filter) $sum['Room Filter'] = 'Room #' . $room_filter;
if ($status_filter) $sum['Status Filter'] = ucfirst($status_filter);

// Bookings (filtered)
$bk = $conn->prepare("SELECT b.id, b.meeting_title, b.booking_date, b.start_time, b.end_time,
    b.Accessories, b.status, b.postponed_date, b.postponed_start_time, b.postponed_end_time, b.postponed_reason,
    b.series_id, b.series_end, b.created_at,
    r.room_name, u.full_name AS staff_name, u.email AS staff_email,
    p.name AS projector_name, p.model AS projector_model
    FROM bookings b
    JOIN rooms r ON b.room_id = r.id
    JOIN users u ON b.user_id = u.id
    LEFT JOIN projectors p ON b.projector_id = p.id
    WHERE $where
    ORDER BY b.booking_date DESC, b.start_time DESC");
if ($params) $bk->bind_param($types, ...$params);
$bk->execute();
$bookings = $bk->get_result();

// Room usage
$room_sql = "SELECT r.room_name, r.capacity, r.location, r.status,
    COUNT(b.id) AS total_bookings,
    COALESCE(SUM(CASE WHEN b.status='confirmed' THEN TIMESTAMPDIFF(MINUTE, b.start_time, b.end_time)/60 ELSE 0 END),0) AS booked_hours,
    SUM(CASE WHEN b.status='confirmed' THEN 1 ELSE 0 END) AS confirmed,
    SUM(CASE WHEN b.status='cancelled' THEN 1 ELSE 0 END) AS cancelled,
    SUM(CASE WHEN b.status='postponed' THEN 1 ELSE 0 END) AS postponed
    FROM rooms r LEFT JOIN bookings b ON b.room_id = r.id AND $where
    GROUP BY r.id, r.room_name, r.capacity, r.location, r.status ORDER BY total_bookings DESC";
$rs = $conn->prepare($room_sql);
if ($params) $rs->bind_param($types, ...$params);
$rs->execute();
$rooms = $rs->get_result();

// Users
$us = $conn->prepare("SELECT u.full_name, u.email, u.role, u.status, u.created_at,
    COUNT(b.id) AS total_bookings,
    SUM(CASE WHEN b.status='confirmed' THEN 1 ELSE 0 END) AS confirmed,
    SUM(CASE WHEN b.status='cancelled' THEN 1 ELSE 0 END) AS cancelled,
    SUM(CASE WHEN b.status='postponed' THEN 1 ELSE 0 END) AS postponed
    FROM users u LEFT JOIN bookings b ON b.user_id = u.id AND $where
    GROUP BY u.id, u.full_name, u.email, u.role, u.status, u.created_at ORDER BY total_bookings DESC");
if ($params) $us->bind_param($types, ...$params);
$us->execute();
$users = $us->get_result();

// Projectors
$ps = $conn->prepare("SELECT p.name, p.model, p.location, p.status,
    COUNT(b.id) AS total_bookings,
    COALESCE(SUM(CASE WHEN b.status='confirmed' THEN TIMESTAMPDIFF(MINUTE, b.start_time, b.end_time)/60 ELSE 0 END),0) AS booked_hours
    FROM projectors p LEFT JOIN bookings b ON b.projector_id = p.id AND $where
    GROUP BY p.id, p.name, p.model, p.location, p.status ORDER BY total_bookings DESC");
if ($params) $ps->bind_param($types, ...$params);
$ps->execute();
$projectors = $ps->get_result();

// Monthly
$monthly = [];
$res = $conn->query("SELECT DATE_FORMAT(booking_date,'%Y-%m') AS ym, COUNT(*) AS c,
    SUM(CASE WHEN status='confirmed' THEN 1 ELSE 0 END) AS conf,
    SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) AS canc
    FROM bookings GROUP BY ym ORDER BY ym ASC");
while ($row = $res->fetch_assoc()) { $monthly[] = $row; }

// Audit
$audit = $conn->query("SELECT a.created_at, a.action, a.entity_type, a.entity_id, a.details, a.ip,
    u.full_name, u.email
    FROM audit_log a LEFT JOIN users u ON a.user_id = u.id
    ORDER BY a.created_at DESC, a.id DESC LIMIT 5000");

// --- Build XML Workbook ---
$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
$xml .= '<Workbook xmlns="urn:schemau-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemau-microsoft-com:office:office"
 xmlns:x="urn:schemau-microsoft-com:office:excel"
 xmlns:ss="urn:schemau-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">';
$xml .= '<Styles>
<Style ss:ID="Default" ss:Name="Normal"><Font ss:FontName="Calibri" ss:Size="11"/></Style>
<Style ss:ID="header"><Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#4B5563" ss:Pattern="Solid"/><Alignment ss:Vertical="Center"/></Style>
<Style ss:ID="title"><Font ss:FontName="Calibri" ss:Size="14" ss:Bold="1" ss:Color="#1F2937"/></Style>
</Styles>';

// Sheet: Summary
$xml .= xml_sheet_open('Summary');
$xml .= xml_row([['header' => 'Metric'], ['header' => 'Value']]);
foreach ($sum as $k => $v) {
    if (is_int($v)) $xml .= xml_row([['str' => $k], ['num' => $v]]);
    else $xml .= xml_row([['str' => $k], ['str' => $v]]);
}
$xml .= xml_sheet_close();

// Sheet: Bookings
if ($type === 'all' || $type === 'bookings') {
    $xml .= xml_sheet_open('Bookings');
    $xml .= xml_row([
        ['header'=>'ID'],['header'=>'Meeting Title'],['header'=>'Room'],['header'=>'Staff'],['header'=>'Email'],
        ['header'=>'Date'],['header'=>'Start'],['header'=>'End'],['header'=>'Type'],['header'=>'Accessories'],
        ['header'=>'Projector'],['header'=>'Status'],['header'=>'Postponed Date'],['header'=>'Postponed Time'],
        ['header'=>'Reauon'],['header'=>'Created At']
    ]);
    $now_dt = $now;
    while ($b = $bookings->fetch_assoc()) {
        $end_dt = $b['booking_date'] . ' ' . $b['end_time'];
        $disp_status = ($b['status'] === 'confirmed' && $end_dt < $now_dt) ? 'Finished' : ucfirst($b['status']);
        $acc = $b['Accessories'];
        if ($acc === 'projector' && $b['projector_name']) {
            $acc = 'Projector: ' . $b['projector_name'] . ($b['projector_model'] ? ' (' . $b['projector_model'] . ')' : '');
        } elseif ($acc !== 'none') {
            $acc = ucfirst($acc);
        } else { $acc = '—'; }
        $type_label = !empty($b['series_id']) ? 'Continuous' : 'Single';
        $post_time = '';
        if ($b['postponed_start_time'] && $b['postponed_end_time']) {
            $post_time = substr($b['postponed_start_time'],0,5) . '-' . substr($b['postponed_end_time'],0,5);
        }
        $xml .= xml_row([
            ['num'=>$b['id']],['str'=>$b['meeting_title']],['str'=>$b['room_name']],
            ['str'=>$b['staff_name']],['str'=>$b['staff_email']],
            ['str'=>$b['booking_date']],['str'=>substr($b['start_time'],0,5)],['str'=>substr($b['end_time'],0,5)],
            ['str'=>$type_label],['str'=>$acc],
            ['str'=>$b['projector_name'] ?? '—'],
            ['str'=>$disp_status],
            ['str'=>$b['postponed_date'] ?? ''],['str'=>$post_time],
            ['str'=>$b['postponed_reason'] ?? ''],['str'=>$b['created_at']]
        ]);
    }
    $xml .= xml_sheet_close();
}

// Sheet: Room Usage
if ($type === 'all' || $type === 'rooms') {
    $xml .= xml_sheet_open('Room Usage');
    $xml .= xml_row([
        ['header'=>'Room'],['header'=>'Capacity'],['header'=>'Location'],['header'=>'Status'],
        ['header'=>'Total Bookings'],['header'=>'Booked Hours'],['header'=>'Confirmed'],['header'=>'Postponed'],['header'=>'Cancelled']
    ]);
    while ($r = $rooms->fetch_assoc()) {
        $xml .= xml_row([
            ['str'=>$r['room_name']],['num'=>$r['capacity']],['str'=>$r['location']],['str'=>ucfirst($r['status'])],
            ['num'=>$r['total_bookings']],['num'=>round($r['booked_hours'],1)],
            ['num'=>$r['confirmed']],['num'=>$r['postponed']],['num'=>$r['cancelled']]
        ]);
    }
    $xml .= xml_sheet_close();
}

// Sheet: Staff Usage
if ($type === 'all' || $type === 'users') {
    $xml .= xml_sheet_open('Staff Usage');
    $xml .= xml_row([
        ['header'=>'Name'],['header'=>'Email'],['header'=>'Role'],['header'=>'Account Status'],['header'=>'Registered'],
        ['header'=>'Total Bookings'],['header'=>'Confirmed'],['header'=>'Postponed'],['header'=>'Cancelled']
    ]);
    while ($u = $users->fetch_assoc()) {
        $xml .= xml_row([
            ['str'=>$u['full_name']],['str'=>$u['email']],['str'=>ucfirst($u['role'])],['str'=>ucfirst($u['status'])],
            ['str'=>date('d/m/Y', strtotime($u['created_at']))],
            ['num'=>$u['total_bookings']],['num'=>$u['confirmed']],['num'=>$u['postponed']],['num'=>$u['cancelled']]
        ]);
    }
    $xml .= xml_sheet_close();
}

// Sheet: Projector Usage
if ($type === 'all' || $type === 'projectors') {
    $xml .= xml_sheet_open('Projector Usage');
    $xml .= xml_row([
        ['header'=>'Projector'],['header'=>'Model'],['header'=>'Location'],['header'=>'Status'],
        ['header'=>'Total Bookings'],['header'=>'Booked Hours']
    ]);
    while ($p = $projectors->fetch_assoc()) {
        $xml .= xml_row([
            ['str'=>$p['name']],['str'=>$p['model'] ?? '—'],['str'=>$p['location'] ?? '—'],['str'=>ucfirst($p['status'])],
            ['num'=>$p['total_bookings']],['num'=>round($p['booked_hours'],1)]
        ]);
    }
    $xml .= xml_sheet_close();
}

// Sheet: Monthly Trend
if ($type === 'all' || $type === 'monthly') {
    $xml .= xml_sheet_open('Monthly Trend');
    $xml .= xml_row([['header'=>'Month'],['header'=>'Total Bookings'],['header'=>'Confirmed'],['header'=>'Cancelled']]);
    foreach ($monthly as $m) {
        $xml .= xml_row([['str'=>$m['ym']],['num'=>$m['c']],['num'=>$m['conf']],['num'=>$m['canc']]]);
    }
    $xml .= xml_sheet_close();
}

// Sheet: Audit Log
if ($type === 'all' || $type === 'audit') {
    $xml .= xml_sheet_open('Audit Log');
    $xml .= xml_row([
        ['header'=>'Time'],['header'=>'User'],['header'=>'Email'],['header'=>'Action'],
        ['header'=>'Entity'],['header'=>'Details'],['header'=>'IP']
    ]);
    while ($a = $audit->fetch_assoc()) {
        $entity = $a['entity_type'] ? ($a['entity_type'] . ($a['entity_id'] !== null ? ' #' . $a['entity_id'] : '')) : '—';
        $xml .= xml_row([
            ['str'=>date('d/m/Y H:i:s', strtotime($a['created_at']))],
            ['str'=>$a['full_name'] ?? ($a['entity_id'] !== null ? 'System' : 'System')],
            ['str'=>$a['email'] ?? '—'],
            ['str'=>$a['action']],
            ['str'=>$entity],
            ['str'=>$a['details'] ?? ''],
            ['str'=>$a['ip'] ?? '—']
        ]);
    }
    $xml .= xml_sheet_close();
}

$xml .= '</Workbook>';

$filename = 'MRS_Report_' . date('Ymd_His') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');
echo $xml;
exit();
?>
