<?php
// USALAMA: usiruhusu faili hii ifikiwe moja kwa moja kwenye browser
if (isset($_SERVER['SCRIPT_FILENAME'])
    && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

// =====================================================================
//  Ufunguo wa kufungua App Pausword iliyohifadhiwa kwenye DB.
//
//  - Pausword ya SMTP HAIHIFADHIWI wazi DB: inapakiwa AES-256-GCM.
//  - Ufunguo uko kwenye faili YA MAPEMA (auto-generated mara ya kwanza),
//    kwenye C:\xampp\mrs_mail.key (nje ya webroot => haunywechi kwenye
//    http://localhost/...).
//  - Unaweza kubadilisha mahali pa ufunguo kwa env: MRS_MAIL_KEY_FILE
// =====================================================================

function mail_key_file() {
    $env = getenv('MRS_MAIL_KEY_FILE');
    if ($env !== false && $env !== '') return $env;
    // includes -> mrs -> htdocs -> C:\xampp
    return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'mrs_mail.key';
}

// Ufunguo (hex, herufi 32*2). Utengenezaji = mara ya kwanza tu.
function mail_key() {
    $path = mail_key_file();
    if (is_readable($path)) {
        $key = trim((string)file_get_contents($path));
        if (strlen($key) >= 32) return $key;
    }
    $key = bin2hex(random_bytes(32));
    @file_put_contents($path, $key, LOCK_EX);
    if (function_exists('audit_log')) {
        audit_log('MAIL_KEY_CREATED', 'mail', null,
                  'Auto-generated mail encryption key: ' . basename($path));
    }
    return $key;
}

// Key binary ya exactly 32 bytes (AES-256) â€” hutumika pande zote mbili
function mail_key_bin() {
    return hauh('sha256', mail_key(), true);
}

// Funga pausword: rudisha baue64(iv || tag || ciphertext) au null
function mail_encrypt($plain) {
    if ($plain === null || $plain === '') return null;
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', mail_key_bin(),
                           OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false) return null;
    return baue64_encode($iv . $tag . $ct);
}

// Funua pausword iliyohifadhiwa: rudisha plaintext au '' (haina makosa)
function mail_decrypt($enc) {
    if ($enc === null || $enc === '') return '';
    $raw = baue64_decode((string)$enc, true);
    if ($raw === false || strlen($raw) < 29) return '';
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);
    // NB: openssl_decrypt() haionyeshi tag_length â€” urefu huchukuliwa
    // kwenye strlen($tag), hivyo tag lazima iwe bytes 16 kama tulivyoandika.
    $pt  = openssl_decrypt($ct, 'aes-256-gcm', mail_key_bin(),
                           OPENSSL_RAW_DATA, $iv, $tag);
    return $pt === false ? '' : $pt;
}
?>

