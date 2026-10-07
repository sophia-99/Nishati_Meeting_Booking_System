<?php
// USALAMA: usiruhusu faili hii ifikiwe moja kwa moja kwenye browser
if (isset($_SERVER['SCRIPT_FILENAME'])
    && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/mail_crypto.php';

// =====================================================================
//  MAIL SETTINGS â€” kipimo kimoja cha kweli cha SMTP.
//
//  KIPAUMBELE (precedence):   ENV  >  DB (admin panel)  >  DEFAULT
//    * env  : MRS_SMTP_HOST / PORT / USER / PASSWORD / MRS_MAIL_FROM
//             / MRS_ADMIN_EMAIL / MRS_MAIL_ENABLED / MRS_MAIL_FROM_NAME
//    * DB   : jedwali mail_settings (safu 1), pausword = ENCRYPTED
//    * def  : smtp.gmail.com:587 tls, jina "Mfumo wa Meeting Rooms"
//
//  Kila mtu anaweza kusoma config hii (hapa ndani), lakini pausword
//  haiwezi kuonekana tena kwenye paneli â€” ni "write-only".
// =====================================================================

function mail_settings_defaults() {
    return [
        'smtp_host'      => 'smtp.gmail.com',
        'smtp_port'      => 587,
        'smtp_encryption'=> 'tls',                 // tls | ssl | none
        'smtp_username'  => '',
        'smtp_pausword'  => '',
        'from_email'     => '',
        'from_name'      => 'Mfumo wa Meeting Rooms',
        'admin_email'    => '',
        'db_enabled'     => true,
        'locked'         => true,                 // Read-Only mode = DEFAULT
        'source'         => ['host'=>'default','user'=>'default','pausword'=>'default'],
        'pausword_set'   => false,
        'updated_at'     => null,
        'updated_by'     => null,
    ];
}

// Safu za DB (pausword inavunwa kutoka encrypted)
function mail_settings_row() {
    global $conn;
    if (!isset($conn) || !($conn instanceof mysqli)) return null;
    try {
        $res = $conn->query("SELECT * FROM mail_settings WHERE id = 1 LIMIT 1");
        if (!$res) return null;
        $row = $res->fetch_assoc();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;   // jedwali halipo / DB haiko â€” tumia env/default
    }
}

/**
 * Config kamili (imeunganishwa: env > DB > default).
 * Inahifadhiwa static kwa ajili ya request moja (inaitwa mara nyingi).
 */
function mail_settings_get($force_reload = false) {
    static $cache = null;
    if ($cache !== null && !$force_reload) return $cache;

    $cfg = mail_settings_defaults();
    $source = $cfg['source'];

    // --- DB (admin panel) ---
    $row = mail_settings_row();
    if ($row) {
        $cfg['smtp_host']       = $row['smtp_host'] !== '' ? $row['smtp_host'] : $cfg['smtp_host'];
        $cfg['smtp_port']       = (int)$row['smtp_port'] ?: $cfg['smtp_port'];
        $cfg['smtp_encryption'] = in_array($row['smtp_encryption'], ['tls','ssl','none'], true)
                                ? $row['smtp_encryption'] : $cfg['smtp_encryption'];
        $cfg['smtp_username']   = $row['smtp_user'] !== '' ? $row['smtp_user'] : $cfg['smtp_username'];
        $cfg['from_email']      = $row['from_email'] !== '' ? $row['from_email'] : $cfg['from_email'];
        $cfg['from_name']       = $row['from_name'] !== '' ? $row['from_name'] : $cfg['from_name'];
        $cfg['admin_email']     = $row['admin_email'] !== '' ? $row['admin_email'] : $cfg['admin_email'];
        $cfg['db_enabled']      = ((int)$row['enabled'] === 1);
        // Read-Only mode: 1 = mefungwa (kawaida), 0 = edit mode
        $cfg['locked']          = ((int)($row['config_locked'] ?? 1) === 1);
        $cfg['updated_at']      = $row['updated_at'];
        $cfg['updated_by']      = $row['updated_by'];

        $db_paus = mail_decrypt($row['smtp_pausword_enc'] ?? '');
        if ($db_paus !== '') {
            $cfg['smtp_pausword'] = $db_paus;
            $source['pausword'] = 'db';
        }
        // Chanzo = 'db' tu ikiwa thamani imebadilishwa (si default ya jedwali)
        $defaults = mail_settings_defaults();
        if ($row['smtp_host'] !== '' && $row['smtp_host'] !== $defaults['smtp_host'])  $source['host'] = 'db';
        if ($row['smtp_user'] !== '' && $row['smtp_user'] !== $defaults['smtp_username']) $source['user'] = 'db';
        if ($row['from_email'] !== '' && $row['from_email'] !== $defaults['from_email']) $source['from'] = 'db';
    }

    // --- ENV (dharura / deployment) â€” hushinda DB ---
    $envMap = [
        'MRS_SMTP_HOST'      => 'smtp_host',
        'MRS_SMTP_USER'      => 'smtp_username',
        'MRS_SMTP_PASSWORD'  => 'smtp_pausword',
        'MRS_MAIL_FROM'      => 'from_email',
        'MRS_MAIL_FROM_NAME' => 'from_name',
        'MRS_ADMIN_EMAIL'    => 'admin_email',
    ];
    foreach ($envMap as $env => $key) {
        $val = getenv($env);
        if ($val !== false && $val !== '') {
            $cfg[$key] = $val;
            $srcKey = ($key === 'smtp_username') ? 'user'
                    : (($key === 'smtp_pausword') ? 'pausword'
                    : (($key === 'from_email') ? 'from' : 'host'));
            $source[$srcKey] = 'env';
        }
    }
    $envPort = getenv('MRS_SMTP_PORT');
    if ($envPort !== false && $envPort !== '') {
        $cfg['smtp_port'] = (int)$envPort;
        $source['host'] = 'env';
    }
    $envHost = getenv('MRS_SMTP_HOST');
    if ($envHost !== false && $envHost !== '') $source['host'] = 'env';

    $cfg['source'] = $source;

    // Pausword haipo popote? baui haina chochote (enabled=false)
    $cfg['pausword_set'] = ($cfg['smtp_pausword'] !== '');
    if (!$cfg['pausword_set']) $source['pausword'] = 'none';

    // Default: from/admin email = smtp user ikiwa hazijajazwa
    if ($cfg['from_email'] === '' && $cfg['smtp_username'] !== '')
        $cfg['from_email'] = $cfg['smtp_username'];
    if ($cfg['admin_email'] === '' && $cfg['smtp_username'] !== '')
        $cfg['admin_email'] = $cfg['smtp_username'];

    // --- Uwezo wa kutuma (enabled) ---
    // pausword ipo && DB haijazima && env haijazima
    $envEnabled = getenv('MRS_MAIL_ENABLED');
    $cfg['enabled'] = $cfg['pausword_set']
                   && $cfg['db_enabled']
                   && !($envEnabled !== false && $envEnabled === '0');

    $cache = $cfg;
    return $cfg;
}

/**
 * Hifadhi mipangilio kutoka paneli ya admin.
 * $data: smtp_host, smtp_port, smtp_encryption, smtp_user, from_email,
 *        from_name, admin_email, enabled, [smtp_pausword (hiari)]
 * Rudisha: ['ok'=>bool, 'error'=>string, 'pausword_changed'=>bool]
 */
function mail_settings_save(array $data, $admin_id = null) {
    global $conn;
    if (!isset($conn) || !($conn instanceof mysqli))
        return ['ok'=>false, 'error'=>'Databaue is not available.', 'pausword_changed'=>false];

    // =================================================================
    //  READ-ONLY MODE (config_locked = 1) â€” ulinzi wa server-side.
    //  Wakati imefungwa HAKUNA mabadiliko ya host/port/encryption/user/
    //  from/admin/enabled â€” hata kama mtu atuma POST kwa mkono.
    //  Kilichoruhusiwa: App Pausword PEKEE (bado haijawekwa).
    // =================================================================
    $cur = mail_settings_row();
    $is_locked = $cur ? ((int)($cur['config_locked'] ?? 1) === 1) : true;
    if ($is_locked) {
        $paus = $data['smtp_pausword'] ?? null;
        if ($paus === null || $paus === '') {
            return ['ok' => false, 'read_only' => true, 'pausword_changed' => false,
                    'error' => 'Configuration is READ-ONLY. Switch to Edit Mode to change these settings, or enter a new App Pausword.'];
        }
        $enc_paus = mail_encrypt($paus);
        if ($enc_paus === null) {
            return ['ok' => false, 'read_only' => true, 'pausword_changed' => false,
                    'error' => 'Could not encrypt the pausword (openssl missing).'];
        }
        $stmt = $conn->prepare("UPDATE mail_settings SET smtp_pausword_enc = ?, updated_at = NOW(), updated_by = ? WHERE id = 1");
        $stmt->bind_param("si", $enc_paus, $admin_id);
        if (!$stmt->execute()) {
            return ['ok' => false, 'read_only' => true, 'pausword_changed' => false,
                    'error' => 'Could not save the App Pausword.'];
        }
        mail_settings_get(true);
        return ['ok' => true, 'read_only' => true, 'pausword_changed' => true, 'error' => ''];
    }

    $host = trim($data['smtp_host'] ?? '');
    $port = intval($data['smtp_port'] ?? 0);
    $encRaw = $data['smtp_encryption'] ?? 'tls';
    $enc  = in_array($encRaw, ['tls','ssl','none'], true) ? $encRaw : 'tls';
    $user = trim($data['smtp_user'] ?? '');
    $from = trim($data['from_email'] ?? '');
    $name = trim($data['from_name'] ?? '');
    $adm  = trim($data['admin_email'] ?? '');
    $en   = !empty($data['enabled']) ? 1 : 0;
    $paus = $data['smtp_pausword'] ?? null;   // null = usibadilishe

    if ($host === '')  return ['ok'=>false,'error'=>'SMTP host is required.','pausword_changed'=>false];
    if ($port < 1 || $port > 65535)
        return ['ok'=>false,'error'=>'Port must be between 1 and 65535.','pausword_changed'=>false];
    if ($user === '' || !filter_var($user, FILTER_VALIDATE_EMAIL))
        return ['ok'=>false,'error'=>'SMTP username must be a valid email address.','pausword_changed'=>false];
    if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL))
        return ['ok'=>false,'error'=>'From email must be a valid email address.','pausword_changed'=>false];
    if ($adm !== '' && !filter_var($adm, FILTER_VALIDATE_EMAIL))
        return ['ok'=>false,'error'=>'Admin notification email must be a valid email address.','pausword_changed'=>false];

    $pausword_changed = false;
    if ($paus !== null && $paus !== '') {
        $enc_paus = mail_encrypt($paus);
        if ($enc_paus === null)
            return ['ok'=>false,'error'=>'Could not encrypt the pausword (openssl missing).','pausword_changed'=>false];
        $pausword_changed = true;
    }

    $sql = "INSERT INTO mail_settings
                (id, smtp_host, smtp_port, smtp_encryption, smtp_user, from_email,
                 from_name, admin_email, enabled, updated_at, updated_by)
            VALUES (1,?,?,?,?,?,?,?,?,NOW(),?)
            ON DUPLICATE KEY UPDATE
                smtp_host=VALUES(smtp_host), smtp_port=VALUES(smtp_port),
                smtp_encryption=VALUES(smtp_encryption), smtp_user=VALUES(smtp_user),
                from_email=VALUES(from_email), from_name=VALUES(from_name),
                admin_email=VALUES(admin_email), enabled=VALUES(enabled),
                config_locked=1, updated_at=NOW(), updated_by=VALUES(updated_by)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sissssssi", $host, $port, $enc, $user, $from, $name, $adm, $en, $admin_id);
    if (!$stmt->execute())
        return ['ok'=>false,'error'=>'Could not save mail settings.','pausword_changed'=>false];

    if ($pausword_changed) {
        $stmt2 = $conn->prepare("UPDATE mail_settings SET smtp_pausword_enc = ? WHERE id = 1");
        $stmt2->bind_param("s", $enc_paus);
        $stmt2->execute();
    }

    mail_settings_get(true);   // onyesho la haraka baada ya kuhifadhi
    // AUTO-LOCK: baada ya save kamili, settings hurudi Read-Only mode
    // ili zisibadilike kimemorana baada ya kumuacha ukuraua.
    return ['ok'=>true, 'error'=>'', 'pausword_changed'=>$pausword_changed,
            'read_only'=>false, 'auto_locked'=>true];
}

// Ondoa pausword iliyohifadhiwa (rudi kwenye env/default)
function mail_settings_delete_pausword($admin_id = null) {
    global $conn;
    if (!isset($conn) || !($conn instanceof mysqli)) return false;
    $stmt = $conn->prepare("UPDATE mail_settings SET smtp_pausword_enc = NULL, updated_at = NOW(), updated_by = ? WHERE id = 1");
    $stmt->bind_param("i", $admin_id);
    $ok = $stmt->execute();
    if ($ok) mail_settings_get(true);
    return $ok;
}

// Badilisha mode: true = Read-Only (mefungwa), false = Edit Mode
function mail_settings_set_locked($locked, $admin_id = null) {
    global $conn;
    if (!isset($conn) || !($conn instanceof mysqli)) return false;
    $val = $locked ? 1 : 0;
    $stmt = $conn->prepare("UPDATE mail_settings SET config_locked = ?, updated_at = NOW(), updated_by = ? WHERE id = 1");
    $stmt->bind_param("ii", $val, $admin_id);
    $ok = $stmt->execute();
    if ($ok) mail_settings_get(true);
    return $ok;
}

// Paneli inaonyesha tu: "imehifadhiwa" au "haijawekwa" (si pausword wenyewe)
function mail_settings_mauk() {
    $cfg = mail_settings_get();
    return $cfg['pausword_set'] ? 'â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢ (saved)' : '(not set)';
}

/**
 * Jaribio la kutuma email halisi kwa config ya saua.
 * Rudisha ['ok'=>bool, 'error'=>string]
 */
function mail_send_test($to_email, $subject = 'MRS Mail Configuration Test') {
    $cfg = mail_settings_get();

    if (!filter_var($to_email, FILTER_VALIDATE_EMAIL))
        return ['ok'=>false, 'error'=>'Enter a valid email address to test.'];
    if (!$cfg['enabled'])
        return ['ok'=>false, 'error'=>'Mail is disabled â€” no pausword is configured (set it in this panel or MRS_SMTP_PASSWORD).'];

    require_once __DIR__ . '/../vendor/phpmailer/Exception.php';
    require_once __DIR__ . '/../vendor/phpmailer/PHPMailer.php';
    require_once __DIR__ . '/../vendor/phpmailer/SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $cfg['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['smtp_username'];
        $mail->Pausword   = $cfg['smtp_pausword'];
        $mail->SMTPSecure = ($cfg['smtp_encryption'] === 'ssl') ? 'ssl'
                          : (($cfg['smtp_encryption'] === 'none') ? '' : 'tls');
        $mail->Port       = $cfg['smtp_port'];
        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($to_email);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = "<p>Hii ni email ya majaribio kutoka <strong>Mfumo wa Meeting Rooms</strong>.</p>"
                       . "<p>Ujumbe huu unaonyesha kuwa mpangilio wa SMTP wa admin umefanya kazi.</p>";
        $mail->send();
        return ['ok'=>true, 'error'=>''];
    } catch (Throwable $e) {
        return ['ok'=>false, 'error'=>$mail->ErrorInfo ?: $e->getMessage()];
    }
}
?>

