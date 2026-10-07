<?php
require __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';
require __DIR__ . '/../includes/audit.php';

// SESSION CONTROL (dakika 10): popup ya onyo hupiga logout ikiwa ?expired=1
// ili mtumiaji aone ujumbe "Your session has expired" kwenye ukurasa wa login.
$logout_reason = isset($_GET['expired']) ? 'expired' : '';

if (isset($_SESSION['user_id'])) {
    // SPRINT 3 (gating): logout lazima iwe na CSRF token (kuzuia logout-CSRF
    // kupitia link ya nje). Ikiwa tayari hakuna session, ruka moja kwa moja.
    csrf_verify('LOGOUT');
    audit_log('LOGOUT', 'user', $_SESSION['user_id'], $_SESSION['email'] ?? '');
}

// Ondoa session + cookie kwa usalama (siyo session_destroy pekee)
security_logout($logout_reason);
?>

