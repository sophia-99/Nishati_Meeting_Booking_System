<?php
// USALAMA: usiruhusu faili hii ifikiwe moja kwa moja kwenye browser
if (isset($_SERVER['SCRIPT_FILENAME'])
    && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

$nav_self = $_SERVER['PHP_SELF'] ?? '';
$nav_is_admin_folder = strpos($nav_self, '/admin/') !== false;
$nav_prefix = $nav_is_admin_folder ? '../public/' : '';
$nav_admin_prefix = $nav_is_admin_folder ? '' : '../admin/';
$nav_asset_prefix = '../';
$nav_include_prefix = '../includes/';
$nav_page = '/' . basename($nav_self);
$nav_role = $_SESSION['role'] ?? '';
$nav_is_admin_area = $nav_is_admin_folder;
$nav_is_admin = ($nav_role === 'admin');
// Kila admin page inaonyesha JINA LAKE lenyewe kwenye navbar (si "Admin Panel")
$nav_brand_pages = [
    '/index.php'               => 'Admin Panel',
    '/room_control_panel.php'  => 'Room Control Panel',
    '/users.php'               => 'User Management',
    '/edit_user.php'           => 'Edit User',
    '/reset_user_password.php' => 'Reset Password',
    '/projectors.php'          => 'Projector Inventory',
    '/reports.php'             => 'Reports & Stats',
    '/audit_log.php'           => 'Audit Log',
    '/mail_config.php'         => 'Mail Configuration',
    '/roles.php'               => 'Roles & Permissions',
];
$nav_brand = !$nav_is_admin_area
    ? 'NISHATI MEETING ROOM BOOKING SYSTEM'
    : ($nav_brand_pages[$nav_page] ?? 'Admin Panel');

// ROLES & PERMISSIONS - pill ya "Admin" (link moja kwenda admin/index.php hub)
// inaonekana kwa (admin) AU mtu aliye na permission yoyote ya admin-area
// (mf. staff aliye na reports.view anaona pill; bila chochote - hakuna pill).
// Ndani ya hub kila box huonekana kwa permission ya page husika.
$nav_can = function ($perm) {
    return function_exists('has_permission')
        && has_permission($_SESSION['role'] ?? '', $perm);
};
$nav_admin_item_perms = [
    'room_control_panel.php' => 'admin.dashboard',
    'users.php'              => 'users.view',
    'projectors.php'         => 'projectors.view',
    'reports.php'            => 'reports.view',
    'mail_config.php'        => 'mail.view',
    'audit_log.php'          => 'audit.view',
    'roles.php'              => 'roles.view',
];
$nav_show_admin_group = $nav_is_admin;
if (!$nav_show_admin_group) {
    foreach ($nav_admin_item_perms as $p) {
        if ($nav_can($p)) { $nav_show_admin_group = true; break; }
    }
}

// CSRF token kwa fomu zinazotengenezwa na JavaScript (modal za admin/staff)
$nav_csrf = function_exists('csrf_token') ? csrf_token() : '';
?>
<script>window.MRS_CSRF = "<?php echo htmlspecialchars($nav_csrf, ENT_QUOTES, 'UTF-8'); ?>";</script>
<nav class="navbar">
    <div class="navbar-brand">
        <img src="<?php echo $nav_asset_prefix; ?>assets/img/coat-of-arms-of-tanzania-logo-png_seeklogo-311608.png?v=1" alt="Coat of Arms of Tanzania">
        <span><?php echo htmlspecialchars($nav_brand); ?></span>
    </div>
    <button class="menu-toggle" id="menuToggle" type="button" aria-label="Toggle menu" onclick="toggleMenu()">
        <span></span><span></span><span></span>
    </button>
    <div id="navLinks" class="nav-links">
        <a class="nav-pill<?php echo !$nav_is_admin_folder && $nav_page === '/index.php' ? ' active' : ''; ?>" href="<?php echo $nav_prefix; ?>index.php">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1v-9.5z"/>
            </svg>
            <span>Home</span>
        </a>
        <a class="nav-pill<?php echo $nav_page === '/my_bookings.php' ? ' active' : ''; ?>" href="<?php echo $nav_prefix; ?>my_bookings.php">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="5" width="18" height="16" rx="2"/>
                <path d="M3 10h18M8 3v4M16 3v4"/>
            </svg>
            <span>My Bookings</span>
        </a>
        <?php if ($nav_can('schedule.view')): ?>
        <a class="nav-pill<?php echo $nav_page === '/schedule.php' ? ' active' : ''; ?>" href="<?php echo $nav_prefix; ?>schedule.php">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="5" width="18" height="16" rx="2"/>
                <path d="M3 10h18M8 3v4M16 3v4"/>
                <path d="M7 14h4M13 14h4M7 17h4"/>
            </svg>
            <span>Schedule</span>
        </a>
        <?php endif; ?>
        <a class="nav-pill<?php echo $nav_page === '/profile.php' ? ' active' : ''; ?>" href="<?php echo $nav_prefix; ?>profile.php">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="8" r="4"/>
                <path d="M4.5 20c1.2-3.6 4-5.5 7.5-5.5s6.3 1.9 7.5 5.5"/>
            </svg>
            <span>My Profile</span>
        </a>
        <?php if ($nav_show_admin_group): ?>
        <a class="nav-pill<?php echo $nav_is_admin_area ? ' active' : ''; ?>" href="<?php echo $nav_admin_prefix; ?>index.php">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/>
                <path d="M9.5 12l1.8 1.8L15 10"/>
            </svg>
            <span>Admin</span>
        </a>
        <?php endif; ?>
        <a class="nav-logout" href="<?php echo $nav_prefix . csrf_url('logout.php'); ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M10 17l5-5-5-5"/>
                <path d="M15 12H3"/>
                <path d="M14 4h5a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-5"/>
            </svg>
            <span>Log Out</span>
        </a>
    </div>
    <!-- Switch ya DARK/LIGHT (duara, ikoni SVG) - upande wa kulia wa Log Out.
         Hali (aria-checked/title) inauimamiwa na assets/js/theme.js -->
    <button type="button" class="theme-toggle" data-theme-toggle role="switch"
            aria-checked="false" aria-label="Switch to dark mode" title="Switch to dark mode">
        <svg class="ico-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
        </svg>
        <svg class="ico-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="4.2"/>
            <path d="M12 2.6v2.2M12 19.2v2.2M4.6 4.6l1.55 1.55M17.85 17.85 19.4 19.4M2.6 12h2.2M19.2 12h2.2M4.6 19.4l1.55-1.55M17.85 6.15 19.4 4.6"/>
        </svg>
    </button>
</nav>

<?php
// ---------------------------------------------------------------------------
// SESSION CONTROL (dakika 10): mipango ya JS ya popup ya onyo kabla logout
// ---------------------------------------------------------------------------
$sess_idle    = (int) (defined('SESSION_IDLE_LIMIT') ? SESSION_IDLE_LIMIT : 600);
$sess_warn    = (int) (defined('SESSION_IDLE_WARNING') ? SESSION_IDLE_WARNING : 540);
$sess_logout  = $nav_prefix . (function_exists('csrf_url') ? csrf_url('logout.php?expired=1') : 'logout.php?expired=1');
?>
<script>
window.MRS_SESSION = {
    idleLimit: <?php echo $sess_idle; ?>,
    warnAt: <?php echo $sess_warn; ?>,
    pingUrl: "<?php echo $nav_include_prefix; ?>session_ping.php",
    /* json_encode (si htmlspecialchars): ndani ya <script> HTML entities
       HAZITUNZWI - htmlspecialchars ingetoa "amp;csrf_token" na login
       ingekatalia CSRF (403). JSON_HEX_* huweka  ambayo JS hufupa. */
    logoutUrl: <?php echo json_encode($sess_logout, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
};
</script>
<script src="<?php echo $nav_asset_prefix; ?>assets/js/session_timeout.js?v=1"></script>
