<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_rules.php';
check_login();

header('Content-Type: application/json');

$date = $_GET['date'] ?? '';
$start = $_GET['start'] ?? '';
$end = $_GET['end'] ?? '';

if (!$date || !$start || !$end) {
    echo json_encode(['ok' => false, 'error' => 'Missing date/start/end']);
    exit();
}

$projectors = $conn->query("SELECT id, name, model, location FROM projectors WHERE status = 'active' ORDER BY name");

$list = [];
while ($p = $projectors->fetch_assoc()) {
    $busy = mrs_projector_slot_busy($conn, $p['id'], $date, $start, $end);

    $item = [
        'id' => (int)$p['id'],
        'name' => $p['name'],
        'model' => $p['model'],
        'location' => $p['location'],
        'available' => !$busy,
    ];

    if ($busy) {
        $item['busy'] = [
            'meeting' => $busy['meeting_title'],
            'start' => substr($busy['eff_start'], 0, 5),
            'end' => substr($busy['eff_end'], 0, 5),
            'user' => $busy['full_name'],
        ];
    }

    $list[] = $item;
}

echo json_encode(['ok' => true, 'projectors' => $list]);
