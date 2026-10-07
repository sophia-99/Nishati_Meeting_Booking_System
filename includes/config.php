<?php
// Mipangilio ya kuunganisha na database
// USALAMA: usiruhusu faili hii ifikiwe moja kwa moja kwenye browser
if (isset($_SERVER['SCRIPT_FILENAME'])
    && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

date_default_timezone_set('Africa/Dar_es_Salaam');

// --- USALAMA: headers za HTTP + session imara (huhitaji session_start ya kawaida) ---
require_once __DIR__ . '/security.php';
sec_headers();
secure_session_start();

$host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "room_booking_system";

$conn = new mysqli($host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Imeshindwa kuunganisha na database: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// USALAMA (#8): msingi wa URL wa mfumo. Kwa ajili ya deployment halisi,
// weka env MRS_BASE_URL (mf. http://192.168.1.10/mrs) — viungo kama
// password reset havitegemei Host header ya ombi. Kazi = '' => localhost.
define('MRS_BASE_URL', getenv('MRS_BASE_URL') ?: '');

// ROLES & PERMISSIONS - kila page ipate has_permission()/require_permission()
require_once __DIR__ . '/permissions.php';

function flash_set($type, $text) {
    $_SESSION['flash_' . $type] = $text;
}

function flash_pull() {
    $out = ['success' => '', 'error' => ''];
    if (!empty($_SESSION['flash_success'])) {
        $out['success'] = $_SESSION['flash_success'];
        unset($_SESSION['flash_success']);
    }
    if (!empty($_SESSION['flash_error'])) {
        $out['error'] = $_SESSION['flash_error'];
        unset($_SESSION['flash_error']);
    }
    return $out;
}
?>
