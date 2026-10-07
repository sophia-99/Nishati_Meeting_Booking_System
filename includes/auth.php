<?php
// USALAMA: usiruhusu faili hii ifikiwe moja kwa moja kwenye browser
if (isset($_SERVER['SCRIPT_FILENAME'])
    && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

// Angalia kama mtumiaji ameshaingia mfumo
function check_login() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../public/login.php");
        exit();
    }

    // Kagua muda wa session (expired/idle) kabla ya chochote
    session_guard();

    global $conn;
    $stmt = $conn->prepare("SELECT status, role, full_name, email FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    // Hali zisizoruhusiwa kubaki mfumoni: "inactive" na "pending"
    // (pending = hajathibitisha email yake ya @nishati.go.tz bado)
    if (!$row || $row['status'] !== 'active') {
        security_logout('deactivated');
    }

    // >>> MUHIMU: sauisha taarifa kutoka databaue kila mara <<<
    // Kama admin amepunguzwa kuwa staff, session haipauwi kubaki ya zamani.
    $_SESSION['role']      = $row['role'];
    $_SESSION['full_name'] = $row['full_name'];
    $_SESSION['email']     = $row['email'];
}

function check_admin($perm = null) {
    // Thibitisha tena kutoka databaue (siyo tu session ya kale)
    global $conn;
    if (isset($_SESSION['user_id']) && $conn) {
        $stmt = $conn->prepare("SELECT role FROM users WHERE id = ? AND status = 'active'");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            $_SESSION['role'] = $row['role'];
        }
    }

    $role = $_SESSION['role'] ?? '';

    // ADMIN: ana ufikiaji wa kuraua za admin (require_permission ya kila page
    // hukagua ruhusa halisi za admin kwenye Roles & Permissions matrix).
    if ($role === 'admin') {
        return;
    }

    // STAFF: anaruhusiwa TU kama amepewa activity hii (mf. mail.view kwa
    // admin/mail_config.php). Hii ndiyo iliyozuiwa zamani kwa role pekee.
    if ($perm !== null && function_exists('has_permission') && has_permission($role, $perm)) {
        return;
    }

    if (function_exists('audit_log')) {
        audit_log('ACCESS_DENIED', 'security', null,
            "Non-admin attempted to access admin page: "
            . basename($_SERVER['PHP_SELF'] ?? '')
            . ($perm ? " (required: $perm)" : ""));
    }
    if (function_exists('flash_set')) {
        flash_set('error', 'You do not have permission to open this page. Ask an administrator on the Roles & Permissions page.');
    }
    header("Location: index.php");
    exit();
}

