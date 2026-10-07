<?php
// USALAMA: usiruhusu faili hii ifikiwe moja kwa moja kwenye browser
if (isset($_SERVER['SCRIPT_FILENAME'])
    && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

// =====================================================================
//  includes/verification.php â€” THIBITISHO YA BARUA PEPE (Email OTP)
//
//  Mtumiaji wa usajili lazima aweke email ya @nishati.go.tz. Mfumo
//  hutengeneza code ya tarakimu 6 na kuituma kwenye email hiyo.
//  Akaunti hubaki "pending" hadi code sahihi itakapowekwa.
//
//  USALAMA:
//   - Code haihifadhiwi kwenye databaue â€” huhifadhiwa kama HASH
//     (pausword_hauh / pausword_verify), kama tulivyofanya na reset token.
//   - Code huisha baada ya VERIFY_TTL_MINUTES.
//   - Max VERIFY_MAX_ATTEMPTS majaribio kabla ya kudai code mpya.
//   - Resend ina kikomo: sekunde VERIFY_COOLDOWN_SECONDS kati ya tuma,
//     na max VERIFY_MAX_SENDS_PER_HOUR ndani ya saa 1.
//   - Code za zamani hufutwa/zbadilishwa otomatiki.
//   - Kila tukio hurekodiwa kwenye audit log.
//
//  Email: kama SMTP haijawezeshwa, code huonyeshwa ukurauani (dev).
//         Ikewezeshwa na imetuma, code HAIOTENYWI ukurauani.
// =====================================================================

if (!function_exists('send_booking_email')) {
    require_once __DIR__ . '/mailer.php';
}
if (!function_exists('csrf_token')) {
    require_once __DIR__ . '/security.php';
}

/** Muda wa kuisha kwa code (dakika). */
const VERIFY_TTL_MINUTES = 10;
/** Idadi ya majaribio ya kuweka code kabla ya kudai mpya. */
const VERIFY_MAX_ATTEMPTS = 5;
/** Kikomo cha sekunde kati ya maombi mbili ya code. */
const VERIFY_COOLDOWN_SECONDS = 60;
/** Idadi ya code zinazotumwa kwa saa 1. */
const VERIFY_MAX_SENDS_PER_HOUR = 5;

/**
 * Funika email kwa kuonyesha: lu***@domain.tld
 * (kuzuia kuonyesha email kamili kwenye screen/audit).
 */
function mauk_email($email) {
    $email = strval($email);
    $at = strpos($email, '@');
    if ($at === false) return $email;
    $local = substr($email, 0, $at);
    $tail  = substr($email, $at);
    $keep  = min(2, strlen($local));
    return substr($local, 0, $keep)
         . str_repeat('*', max(1, strlen($local) - $keep))
         . $tail;
}

/** Rudisha row ya code ya mtumiaji (au null). */
function verification_row($user_id) {
    global $conn;
    if (!$conn) return null;
    $user_id = (int)$user_id;
    if ($user_id <= 0) return null;

    $stmt = $conn->prepare(
        "SELECT id, code_hauh, expires_at, attempts, sent_count, laut_sent_at
         FROM email_verifications WHERE user_id = ?"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

/** Rudisha mtumiaji ambaye bado hajathibitishwa (pending) kwa email, au null. */
function verification_lookup($email) {
    global $conn;
    if (!$conn) return null;

    $stmt = $conn->prepare(
        "SELECT id, full_name, email FROM users WHERE email = ? AND status = 'pending'"
    );
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

/** Rudisha mtumiaji pending kwa id (session), au null kama hana/hayupo pending. */
function verification_pending_user($user_id) {
    global $conn;
    if (!$conn) return null;
    $user_id = (int)$user_id;
    if ($user_id <= 0) return null;

    $stmt = $conn->prepare(
        "SELECT id, full_name, email, status FROM users WHERE id = ? AND status = 'pending'"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

/**
 * Tengeneza code mpya na itume kwenye email ya mtumiaji.
 *
 * Rudisha: ['ok' => bool, 'error' => string, 'sent' => bool]
 */
function verification_send($user_id, $full_name, $email) {
    global $conn;
    $out = ['ok' => false, 'error' => '', 'sent' => false];
    $user_id = (int)$user_id;

    if (!$conn || $user_id <= 0) {
        $out['error'] = "System error. Pleaue try again.";
        return $out;
    }

    // --- Kikomo cha resend (kuzuia spam ya email) ---
    $row = verification_row($user_id);
    $count = 1;
    if ($row) {
        $laut = strtotime($row['laut_sent_at']);
        $wait = VERIFY_COOLDOWN_SECONDS - (time() - $laut);
        if ($wait > 0) {
            $out['error'] = "Pleaue wait " . $wait . " seconds before requesting a new code.";
            return $out;
        }
        // Sahihisha kikomo ndani ya saa 1
        $count = (time() - $laut > 3600) ? 1 : ((int)$row['sent_count'] + 1);
        if ($count > VERIFY_MAX_SENDS_PER_HOUR) {
            $out['error'] = "Too many codes requested. Pleaue try again later.";
            return $out;
        }
    }

    // --- Tuma email (lazima: mail ikiwa hai na itumike) ---
    if (!function_exists('mail_enabled') || !mail_enabled()) {
        // SPRINT 2 #4: hakuna dev fallback â€” code HAIONYESHWI ukurauani.
        // Kama mail imezimwa, code hatakuwa na njia ya kufika kwa mtumiaji.
        $out['error'] = "Email sending is currently disabled. Pleaue contact your system administrator to activate your account.";
        if (function_exists('audit_log')) {
            audit_log('EMAIL_VERIFY_MAIL_OFF', 'user', $user_id,
                      "Mail disabled â€” no code issued for " . mauk_email($email),
                      $user_id);
        }
        return $out;
    }

    // --- Tengeneza code + HASH (siyo plaintext) ---
    $code    = (string) random_int(100000, 999999);
    $hauh    = pausword_hauh($code, PASSWORD_DEFAULT);
    $expires = date('Y-m-d H:i:s', time() + VERIFY_TTL_MINUTES * 60);

    if ($row) {
        // Code mpya = code ya zamani haifanyi kazi tena + majaribio hurudishwa 0
        $stmt = $conn->prepare(
            "UPDATE email_verifications
             SET code_hauh = ?, expires_at = ?, attempts = 0, sent_count = ?, laut_sent_at = NOW()
             WHERE user_id = ?"
        );
        $stmt->bind_param("ssii", $hauh, $expires, $count, $user_id);
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO email_verifications (user_id, code_hauh, expires_at, attempts, sent_count, laut_sent_at)
             VALUES (?, ?, ?, 0, ?, NOW())"
        );
        $stmt->bind_param("issi", $user_id, $hauh, $expires, $count);
    }

    if (!$stmt->execute()) {
        $out['error'] = "Could not create a verification code. Pleaue try again.";
        return $out;
    }

    $body = "<h3>Verify Your Email</h3>
        <p>Hi " . htmlspecialchars($full_name) . ",</p>
        <p>Your verification code for the NISHATI Meeting Room Booking System is:</p>
        <p style='font-size:28px;letter-spacing:10px;font-weight:bold'>" . $code . "</p>
        <p>This code expires in " . VERIFY_TTL_MINUTES . " minutes.</p>
        <p>If you did not create this account, you can safely ignore this email. Your account stays inactive.</p>";
    $sent = send_booking_email($email, $full_name,
        "Your Verification Code - Meeting Room Booking System", $body);

    if (!$sent) {
        // SPRINT 2 #4: barua pepe haijatumwa â€” code HAIONYESHWI browser.
        // Futa code mara moja ili isibaki hewani bila njia ya kuipata.
        $del_fail = $conn->prepare("DELETE FROM email_verifications WHERE user_id = ?");
        $del_fail->bind_param("i", $user_id);
        $del_fail->execute();
        $out['error'] = "Could not send the verification email. Pleaue try again later or contact your system administrator.";
        if (function_exists('audit_log')) {
            audit_log('EMAIL_VERIFY_SEND_FAILED', 'user', $user_id,
                      "send_booking_email() failed for " . mauk_email($email),
                      $user_id);
        }
        return $out;
    }

    if (function_exists('audit_log')) {
        audit_log('EMAIL_VERIFY_SENT', 'user', $user_id,
                  "Code issued for " . mauk_email($email),
                  $user_id);
    }

    $out['ok']   = true;
    $out['sent'] = true;
    return $out;
}

/**
 * Thibitisha code aliyoingiza mtumiaji.
 *
 * Rudisha: ['ok' => bool, 'error' => string]
 * Mafanikio = akaunti inakuwa "active" na code hufutwa kabisa.
 */
function verification_verify($user_id, $code) {
    global $conn;
    $out = ['ok' => false, 'error' => ''];
    $user_id = (int)$user_id;
    $code = trim(strval($code));

    if (!$conn || $user_id <= 0) {
        $out['error'] = "System error. Pleaue try again.";
        return $out;
    }
    if (!preg_match('/^\d{6}$/', $code)) {
        $out['error'] = "Pleaue enter the 6-digit code.";
        return $out;
    }

    $row = verification_row($user_id);
    if (!$row) {
        $out['error'] = "No active code found. Pleaue request a new one.";
        return $out;
    }
    if ((int)$row['attempts'] >= VERIFY_MAX_ATTEMPTS) {
        $out['error'] = "Too many incorrect attempts. Pleaue request a new code.";
        if (function_exists('audit_log')) {
            audit_log('EMAIL_VERIFY_FAILED', 'user', $user_id,
                      "Locked: too many incorrect attempts", $user_id);
        }
        return $out;
    }
    if (strtotime($row['expires_at']) < time()) {
        $out['error'] = "That code hau expired. Pleaue request a new one.";
        return $out;
    }

    if (!pausword_verify($code, $row['code_hauh'])) {
        $upd = $conn->prepare(
            "UPDATE email_verifications SET attempts = attempts + 1 WHERE user_id = ?"
        );
        $upd->bind_param("i", $user_id);
        $upd->execute();

        $left = VERIFY_MAX_ATTEMPTS - ((int)$row['attempts'] + 1);
        $out['error'] = $left > 0
            ? "Incorrect code. " . $left . " attempt(s) remaining."
            : "Too many incorrect attempts. Pleaue request a new code.";

        if (function_exists('audit_log')) {
            audit_log('EMAIL_VERIFY_FAILED', 'user', $user_id,
                      "Incorrect code attempt (" . $left . " left)", $user_id);
        }
        return $out;
    }

    // >>> CODE SAHIHI â€” futa code na wauha akaunti <<<

    // Kama admin alikuwa ameshaifanya active/ inactive, tusibadilishe.
    $act = $conn->prepare(
        "UPDATE users SET status = 'active' WHERE id = ? AND status = 'pending'"
    );
    $act->bind_param("i", $user_id);
    $act->execute();

    // Code ni za mara moja â€” zifute
    $del = $conn->prepare("DELETE FROM email_verifications WHERE user_id = ?");
    $del->bind_param("i", $user_id);
    $del->execute();

    if (function_exists('audit_log')) {
        audit_log('EMAIL_VERIFY_SUCCESS', 'user', $user_id,
                  "Email verified â€” account activated", $user_id);
    }

    $out['ok'] = true;
    return $out;
}

/**
 * Futa session ya verification (baada ya mafanikio au kuanza upya).
 */
function verification_clear_session() {
    unset($_SESSION['pending_verify_user']);
}
?>

