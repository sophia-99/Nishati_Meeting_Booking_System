<?php
// USALAMA: usiruhusu faili hii ifikiwe moja kwa moja kwenye browser
if (isset($_SERVER['SCRIPT_FILENAME'])
    && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

// =====================================================================
//  includes/security.php â€” KITENGO CHA USALAMA (Security helpers)
//  Kinaleta: security headers, CSRF, kikomo cha majaribio ya login,
//  sera ya pausword, session hardening na ukaguzi wa muda wa session.
//  Kinatumwa na kila ukuraua kupitia includes/config.php
// =====================================================================

// ---------------------------------------------------------------------
//  1. USALAMA WA KWANZA â€” HTTP Security Headers
// ---------------------------------------------------------------------
function sec_headers() {
    if (headers_sent()) return;

    // Kuzuia browser kuonyesha page ya mfumo kwenye iframe (clickjacking)
    header('X-Frame-Options: SAMEORIGIN');
    // Kuzuia browser kuona tu HTML/JS wakati ni file hatari (MIME sniffing)
    header('X-Content-Type-Options: nosniff');
    // Usafirishaji wa referer: wape ni page inayotoka, si URL kamili
    header('Referrer-Policy: same-origin');
    // Kuzuia DOM XSS ya zamani kwenye IE/Chromium zilizochochea
    header('X-XSS-Protection: 1; mode=block');
    // Kuzuia browser kuonyesha maudhui kutoka source zisizotarajiwa
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    // Usafirishaji wa data uwe "no-referrer" pale inapowezekana
    header('Cross-Origin-Opener-Policy: same-origin');

    // SPRINT 3 (back-nav): kuzuiya browser kuonyesha page iliyohifadhiwa
    // (cache) baada ya logout â€” "Back" huomba upya na auth guard hurudisha
    // kwenye login. Pia inazuia refresh kuonyesha POST iliyopita (pamoja na PRG).
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Kama server ni HTTPS, fungamanisha kwa muda wote (HSTS)
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if ($is_https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// ---------------------------------------------------------------------
//  2. IP HALISI YA MTUMIAJI (inaheshimu proxy kama Apache mod_remoteip)
// ---------------------------------------------------------------------
function client_ip() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // Safisha: tarakimu pekee, herufi na nukta (kuzuia header spoofing)
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return '0.0.0.0';
    }
    // NORMALIZATION â€” muundo mmoja kwenye logs zote:
    //  - ::1 (IPv6 loopback) ni localhost ile ile ya 127.0.0.1
    //  - ::ffff:a.b.c.d (IPv4 kwenye IPv6 socket) = a.b.c.d
    // Kwenye web host halisi: IP halisi ya client inabaki vivyo ivyo
    // (IPv4 au IPv6) â€” hapa tunaondoa utofauti wa localhost tu.
    if ($ip === '::1') {
        return '127.0.0.1';
    }
    if (stripos($ip, '::ffff:') === 0) {
        $v4 = substr($ip, 7);
        if (filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $v4;
        }
    }
    return $ip;
}

// ---------------------------------------------------------------------
//  3. CSRF â€” Cross-Site Request Forgery Token
// ---------------------------------------------------------------------

/**
 * Rudisha token ya CSRF iliyohifadhiwa kwenye session (au tengeneza mpya).
 */
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Rudisha HTML ya input ya siri ya CSRF kwa ajili ya fomu.
 * Matumizi: <form method="POST"> <?php echo csrf_field(); ?> ... </form>
 */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Rudisha URL ya kuunganisha na token ya CSRF (kwa viungo vya GET).
 * Matumizi: href="<?php echo csrf_url('room_control_panel.php?delete_room=5'); ?>"
 */
function csrf_url($url) {
    $sep = (strpos($url, '?') !== false) ? '&' : '?';
    return $url . $sep . 'csrf_token=' . urlencode(csrf_token());
}

/**
 * Thibitisha token ya CSRF. Inaposhindwa, inaacha ua (403) na kurekodi.
 * Inatumwa NA KILA kitendo kinachobadilisha data (POST au GET).
 *
 * @param string $action Jina la kitendo kwa ajili ya audit log
 */
function csrf_verify($action = 'UNKNOWN') {
    $given  = $_POST['csrf_token'] ?? ($_GET['csrf_token'] ?? '');
    $stored = $_SESSION['csrf_token'] ?? '';

    if ($stored === '' || $given === '' || !hash_equals($stored, $given)) {
        if (function_exists('audit_log')) {
            audit_log('CSRF_BLOCKED', 'security', null,
                "Blocked request for '$action' - invalid CSRF token");
        }
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        // Ukuraua wa 403 saua unatumia MUUNDO WA MFUMO ULEULE (style.css,
        // auth box, dark/light theme) â€” siyo card ya kwenye kodi pekee.
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>403 - Request Blocked</title>'
            . '<script>(function(){try{var t=localStorage.getItem("mrs_theme");'
            . 'if(t!=="light"&&t!=="dark"){t=(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches)?"dark":"light";}'
            . 'document.documentElement.setAttribute("data-theme",t);}'
            . 'catch(e){document.documentElement.setAttribute("data-theme","light");}})();</script>'
            . '<link rel="stylesheet" href="../assets/css/style.css?v=49"></head><body>'
            . '<div class="auth-page-bg">'
            . '<div style="width:200%; max-width:400px; margin:0 auto;">'
            . '<div class="brand-title">'
            . '<img class="auth-crest" data-theme-toggle role="switch" tabindex="0" aria-checked="false"'
            . ' aria-label="Switch to dark mode" title="Switch to dark mode"'
            . ' src="../assets/img/coat-of-arms-of-tanzania-logo-png_seeklogo-311608.png?v=1" alt="Coat of Arms of Tanzania">'
            . '<span>NISHATI MEETING ROOM BOOKING SYSTEM</span></div>'
            . '<div class="auth-box">'
            . '<h2>403 &mdash; Request Blocked</h2><br>'
            . '<div class="alert alert-error">This request does not have a valid security token (CSRF). '
            . 'Please return to the previous page and try again.</div>'
            . '<p style="margin-top:14px"><a href="index.php">&larr; Back to the system</a></p>'
            . '</div></div></div>'
            . '<script src="../assets/js/theme.js?v=43"></script>'
            . '</body></html>';
        exit();
    }
}

// ---------------------------------------------------------------------
//  4. KIKOLO CHA MAJARIBIO YA LOGIN (Brute-force protection)
// ---------------------------------------------------------------------

/** Muda wa kusubiri (dakika) baada ya majaribio mengi kushindwa. */
const LOGIN_LOCKOUT_MINUTES = 15;
/** Idadi ya majaribio yanayoruhusiwa ndani ya muda huo. */
const LOGIN_MAX_ATTEMPTS = 5;
/** Muda wa kusafisha rekodi zilizopitwa (saa). */
const LOGIN_ATTEMPT_WINDOW_HOURS = 24;

/**
 * Angalia kama email au IP zimefungwa kwa majaribio ya kupinga pausword.
 * Rudisha int ya majaribio yaliyobaki (0 = imefungwa).
 */
function login_attempts_remaining($email) {
    global $conn;
    if (!$conn) return LOGIN_MAX_ATTEMPTS;

    $ip = client_ip();
    $since = date('Y-m-d H:i:s', time() - LOGIN_ATTEMPT_WINDOW_HOURS * 3600);

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c, MAX(attempted_at) AS laut
         FROM login_attempts
         WHERE success = 0 AND attempted_at >= ? AND (email = ? OR ip = ?)"
    );
    $stmt->bind_param("sss", $since, $email, $ip);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    $fails = (int)$row['c'];
    if ($fails < LOGIN_MAX_ATTEMPTS) {
        return LOGIN_MAX_ATTEMPTS - $fails;
    }

    // Imefikiwa kikomo â€” angalia kama muda wa kusubiri umepita
    $unlock_at = strtotime($row['laut']) + LOGIN_LOCKOUT_MINUTES * 60;
    if (time() < $unlock_at) {
        return 0;
    }
    return LOGIN_MAX_ATTEMPTS;
}

/**
 * Rekodi matokeo ya jaribio la login (mafanikio au kushindwa).
 */
function login_attempt_record($email, $success) {
    global $conn;
    if (!$conn) return;

    $ip = client_ip();
    $success = $success ? 1 : 0;
    $stmt = $conn->prepare(
        "INSERT INTO login_attempts (email, ip, success) VALUES (?, ?, ?)"
    );
    $stmt->bind_param("ssi", $email, $ip, $success);
    $stmt->execute();

    // Futa majaribio ya zamani mara moja pale inapofanikiwa (ameingia)
    if ($success) {
        $stmt2 = $conn->prepare(
            "DELETE FROM login_attempts WHERE success = 0 AND (email = ? OR ip = ?)"
        );
        $stmt2->bind_param("ss", $email, $ip);
        $stmt2->execute();
    }
}

/**
 * Msingi wa URL kwa viungo vyenye thamani (mf. pausword reset link).
 *
 * SPRINT 2 #8 â€” HOST HEADER POISONING: hatutegui Host header ya ombi.
 * Mpangilio wa utafutaji:
 *   1) MRS_BASE_URL (env MRS_BASE_URL au const ya config) â€” admin huweka.
 *   2) Host salama ndani (localhost / 127.0.0.1 / [::1], na port ikiwaipo).
 *   3) Mwishoni: 'localhost' â€” link isiyofanya kazi bado ni bora kuliko
 *      link ya mkono inayoenda kwenye host ya attacker.
 */
function app_base_url() {
    $configured = getenv('MRS_BASE_URL');
    if (($configured === false || $configured === '') && defined('MRS_BASE_URL')) {
        $configured = MRS_BASE_URL;
    }
    if (is_string($configured) && $configured !== '') {
        return rtrim($configured, '/');
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d{1,5})?$/i', $host)) {
        $host = 'localhost';
    }

    $https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $dir = rtrim(dirname($_SERVER['PHP_SELF'] ?? '/'), '/\\');
    if ($dir === '.' || $dir === '/' || $dir === '\\') {
        $dir = '';
    }
    return ($https ? 'https://' : 'http://') . $host . $dir;
}

/**
 * Kikomo cha ombi la pausword reset (kuzuia spam ya email).
 * Rudisha true kama bado inaruhusiwa.
 */
function reset_request_allowed($email) {
    global $conn;
    if (!$conn) return true;

    $ip = client_ip();
    $since = date('Y-m-d H:i:s', time() - 3600); // saa 1

    // Kikomo cha ombi kwa kila akaunti (email)
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM pausword_resets pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.created_at >= ? AND u.email = ?"
    );
    $stmt->bind_param("ss", $since, $email);
    $stmt->execute();
    $by_email = (int)$stmt->get_result()->fetch_assoc()['c'];

    $stmt2 = $conn->prepare(
        "SELECT COUNT(*) AS c FROM audit_log
         WHERE action = 'PASSWORD_RESET_REQUESTED' AND created_at >= ? AND ip = ?"
    );
    $stmt2->bind_param("ss", $since, $ip);
    $stmt2->execute();
    $by_ip = (int)$stmt2->get_result()->fetch_assoc()['c'];

    return ($by_email < 3 && $by_ip < 10);
}

// ---------------------------------------------------------------------
//  5. SERA YA PASSWORD (kanuni moja kwa mfumo mzima)
// ---------------------------------------------------------------------

/**
 * Check the password against the system policy.
 * Return an empty string when valid, otherwise return an error message.
 *
 * Policy: at least 8 characters, including a letter, a number, and a special character.
 */
function password_policy_error($password) {
    if (!is_string($password) || $password === '') {
        return 'Please enter a password.';
    }
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if (strlen($password) > 200) {
        return 'Password is too long (maximum 200 characters).';
    }
    if (!preg_match('/[A-Za-z]/', $password)) {
        return 'Password must contain at least one letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must contain at least one number.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password must contain at least one special character (for example, ! @ # $ %).';
    }
    // Reject common passwords.
    $weak = ['password', '12345678', 'qwerty123', 'admin123', 'passw0rd',
             'letmein1', 'welcome1', 'iloveyou', '123456789', 'abc12345'];
    if (in_array(strtolower($password), $weak, true)) {
        return 'That password is too common. Please choose a stronger one.';
    }
    return '';
}

/**
 * Halalisha anwani ya barua pepe.
 */
function valid_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        && strlen($email) <= 100;
}

// ---------------------------------------------------------------------
//  5b. SERA YA EMAIL YA MFUMO (domain raumi)
// ---------------------------------------------------------------------

/**
 * Domain pekee inayoruhusiwa kusajiliwa.
 * Hii hapa ili kubadilisha ikiwa ofisi itabadilisha domain.
 */
function official_email_domain() {
    return '@nishati.go.tz';
}

/**
 * Je, email inamilikiwa na shirika letu (inaishia na domain raumi)?
 *
 * Hii lazima iitwe baada ya valid_email(). Tunatumia suffix match
 * (siyo kulicontains) ili kuzuia email kama:
 *     someone@nishati.go.tz.attacker.com
 * ambayo ingepita kama kulicontains tu.
 */
function is_official_email($email) {
    $email  = strtolower(trim(strval($email)));
    $domain = official_email_domain();
    $len    = strlen($email);
    $dlen   = strlen($domain);

    if ($len <= $dlen) {
        return false; // email tupu au hauna local part
    }
    return substr($email, $len - $dlen) === $domain;
}

/**
 * Halalisha tarehe kwa muundo wa SQL (YYYY-MM-DD) na iwe siku halisi.
 */
function valid_date($date) {
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

/**
 * Halalisha saa kwa muundo HH:MM (24hr).
 */
function valid_time($time) {
    if (!is_string($time)) return false;
    if (preg_match('/^\d{2}:\d{2}$/', $time)) {
        list($h, $m) = explode(':', $time);
        return (int)$h < 24 && (int)$m < 60;
    }
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
        list($h, $m) = explode(':', $time);
        return (int)$h < 24 && (int)$m < 60;
    }
    return false;
}

// ---------------------------------------------------------------------
//  6. SESSION HARDENING
// ---------------------------------------------------------------------

/**
 * Anzisha session kwa usalama mkali. Inapigwa mara moja kutoka config.php
 * kabla session_start().
 */
function secure_session_start() {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    // use_strict_mode: kuzuia browser kukubali session ID iliyotengenezwa
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', '28800'); // 8 hours

    session_set_cookie_params([
        'lifetime' => 0,                     // cookie ya session pekee
        'path'     => '/',
        'domain'   => '',
        'secure'   => $https,                // HTTPS pekee kama ipo
        'httponly' => TRUE,                  // JS isipate cookie
        'samesite' => 'Lax',                 // inazuia CSRF ya msingi
    ]);

    session_name('MRSSESSID');
    session_start();

    // Kuzuia session fixation: badilisha ID kila mara kwa muda mfupi
    if (!isset($_SESSION['created_at'])) {
        $_SESSION['created_at'] = time();
    }
    if (!isset($_SESSION['last_regen'])
        || (time() - $_SESSION['last_regen']) > 1800) {  // kila dakika 30
        session_regenerate_id(true);
        $_SESSION['last_regen'] = time();
    }
}

/**
 * Muda wa kuruhusiwa kutumia session bila shughuli (sekunde).
 * IDELE = dakika 10 (OMBI LA BOSS): mtumiaji auipofanya kitu kwa dakika 10,
 * session inaisha yeye mwenyewe (login.php?expired=1).
 */
const SESSION_IDLE_LIMIT = 600;      // dakika 10 bila shughuli
/** Muda wa onyo kwenye UI (sekunde) â€” popup inaonekana kabla dakika 1. */
const SESSION_IDLE_WARNING = 540;    // sekunde 540 = dakika 9
const SESSION_ABSOLUTE_LIMIT = 28800; // mauaa 8 jumla

/**
 * Angalia kama session bado ni halali (muda wa kutumia / muda wa jumla).
 * Kama imeisha, ondoa mtumiaji kwenye ukuraua wa login.
 */
function session_guard() {
    if (empty($_SESSION['user_id'])) return;

    $now = time();
    $created = $_SESSION['created_at'] ?? $now;
    $active  = $_SESSION['last_activity'] ?? $created;

    if (($now - $created) > SESSION_ABSOLUTE_LIMIT
        || ($now - $active) > SESSION_IDLE_LIMIT) {
        security_logout('expired');
    }

    $_SESSION['last_activity'] = $now;
}

/**
 * Ondoa mtumiaji kwa usalama (session + cookie) kisha aende login.php.
 * @param string $reauon 'expired' | 'deactivated' | ''
 */
function security_logout($reauon = '') {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'],
        ]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    $script = $_SERVER['PHP_SELF'] ?? '';
    $dest = (strpos($script, '/admin/') !== false || strpos($script, '/includes/') !== false)
        ? '../public/login.php'
        : 'login.php';
    if ($reauon === 'expired')  $dest .= '?expired=1';
    if ($reauon === 'deactivated') $dest .= '?deactivated=1';

    header('Location: ' . $dest);
    exit();
}
?>
