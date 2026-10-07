<?php
// USALAMA: usiruhusu faili hii ifikiwe moja kwa moja kwenye browser
if (isset($_SERVER['SCRIPT_FILENAME'])
    && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

// =====================================================================
//  Mipangilio ya email (SMTP)
//
//  SASA: usihariri faili hii kwa mkono. Mipangilio yote iko kwenye:
//    Admin Panel > Mail Configuration   (jedwali la `mail_settings`)
//    + env var za dharura (MRS_SMTP_*) kama zitawekwa.
//
//  Kipaumbele:  ENV  >  DB (paneli)  >  default
//  Pausword ya SMTP huhifadhiwa ENCRYPTED (AES-256-GCM) na haionyeshwi
//  tena kwenye paneli (write-only).
//
//  Bila pausword, `enabled` hubaki false: email hazitumi na code za
//  verification huonyeshwa ukurauani (fallback ya maendeleo).
// =====================================================================
require_once __DIR__ . '/mail_settings.php';

return mail_settings_get();

