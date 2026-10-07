<?php
// ---------------------------------------------------------------------------
// session_ping mtandao — SESSION CONTROL (dakika 10)
// Mtumiaji akipita kuendelea na kazi yake, JS hupiga ombi hili ili kusasisha
// last_activity (muda wa shughuli). Bila hii, session ingeisha ingawa mtu
// yuko hai kwenye ukurasa. Inatumwa na assets/js/session_timeout.js.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Hakuna session => JS inajua imeisha (haitakiwi kurekebisha chochote)
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'reason' => 'unauthenticated']);
    exit;
}

// Sasaisha shughuli + ukaguzi wa hali ya akaunti (inactive => logout).
// Ikiwa muda umepita, session_guard hutoa Location: login.php?expired=1.
check_login();

echo json_encode([
    'ok'         => true,
    'idle_limit' => SESSION_IDLE_LIMIT,
    'warn_at'    => SESSION_IDLE_WARNING,
    'remaining'  => SESSION_IDLE_LIMIT,
    'time'       => time(),
]);
