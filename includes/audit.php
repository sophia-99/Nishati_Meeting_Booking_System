<?php
// USALAMA: usiruhusu faili hii ifikiwe moja kwa moja kwenye browser
if (isset($_SERVER['SCRIPT_FILENAME'])
    && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

// Audit log helper â€” inarekodi matendo ya watumiaji na mfumo.
// Hutumia $_SESSION['user_id'] kama ipo, vinginevyo NULL (system/cron).
// Inawezwa kupokea user_id wazi kwa matukio ya cron/system.
function audit_log($action, $entity_type = null, $entity_id = null, $details = '', $user_id = null) {
    global $conn;
    if (!$conn) return;

    if ($user_id === null && isset($_SESSION['user_id'])) {
        $user_id = intval($_SESSION['user_id']);
    } elseif ($user_id !== null) {
        $user_id = intval($user_id);
    }

    // IP: tumia client_ip() (ime-normalize ::1/::ffff:* â†’ 127.0.0.1) ili
    // audit log isome muundo mmoja; fallback kwa cron/bila security.php.
    $ip = function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? null);
    if ($ip !== null && !filter_var($ip, FILTER_VALIDATE_IP)) {
        $ip = null;
    }
    $action = substr(strval($action), 0, 50);
    $entity_type = $entity_type !== null ? substr(strval($entity_type), 0, 50) : null;
    $entity_id = $entity_id !== null ? intval($entity_id) : null;
    $details = strval($details);

    $stmt = $conn->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, ip) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ississ", $user_id, $action, $entity_type, $entity_id, $details, $ip);
    $stmt->execute();
}
?>
