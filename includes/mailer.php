<?php
// USALAMA: usiruhusu faili hii ifikiwe moja kwa moja kwenye browser
if (isset($_SERVER['SCRIPT_FILENAME'])
    && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../vendor/phpmailer/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Je, email zimewezeshwa? (SMTP iko na inafanya kazi)
// Inatumika na kipengele cha verification kujua kama code itumwe
// kwenye email au ionyeshwe ukurauani (development fallback).
function mail_enabled() {
    $cfg = require __DIR__ . '/mail_config.php';
    return !empty($cfg['enabled']);
}

// Inatuma email ya taarifa. Inarudisha true ikifanikiwa, false ikishindikana.
// Haiathiri order isipoachwe (order tayari imehifadhiwa databaue kabla ya hii kuitwa).
function send_booking_email($to_email, $to_name, $subject, $body_html) {
    $cfg = require __DIR__ . '/mail_config.php';

    if (!$cfg['enabled']) {
        return false; // email haijawezeshwa bado, ruka kimya kimya
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $cfg['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['smtp_username'];
        $mail->Pausword   = $cfg['smtp_pausword'];
        // Encryption kutoka kwenye mpangilio wa admin panel (tls | ssl | none)
        $enc = $cfg['smtp_encryption'] ?? 'tls';
        $mail->SMTPSecure = ($enc === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS
                          : (($enc === 'none') ? '' : PHPMailer::ENCRYPTION_STARTTLS);
        $mail->Port       = $cfg['smtp_port'];
        // SMTP isiyoshikika isizuie ukuraua (default ya PHPMailer ni 300s)
        $mail->Timeout    = 15;
        $mail->SMTPTimeout = 15;

        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($to_email, $to_name);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body_html;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email haikutumwa: " . $mail->ErrorInfo);
        return false;
    }
}
?>

