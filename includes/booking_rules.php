<?php
if (isset($_SERVER['SCRIPT_FILENAME'])
    && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    header('HTTP/1.1 403 Forbidden');
    exit();
}

/* ------------------------------------------------------------------
 * BOOKING RULES â€” kanuni MOJA ya mgongano (room + projector).
 * Booking iliyosogozwa (postponed) inauhika nafaui yake MPYA;
 * booking ya zamani huruhusiwa iachwe wazi (booking_date iliyopita).
 * ------------------------------------------------------------------ */

function mrs_eff_date_sql($b = '') {
    $p = $b ? $b . '.' : '';
    return "CASE WHEN {$p}status = 'postponed' AND {$p}postponed_date IS NOT NULL AND {$p}postponed_date <> '0000-00-00'"
         . " THEN {$p}postponed_date ELSE {$p}booking_date END";
}

function mrs_eff_start_sql($b = '') {
    $p = $b ? $b . '.' : '';
    return "CASE WHEN {$p}status = 'postponed' AND {$p}postponed_date IS NOT NULL AND {$p}postponed_date <> '0000-00-00'"
         . " THEN {$p}postponed_start_time ELSE {$p}start_time END";
}

function mrs_eff_end_sql($b = '') {
    $p = $b ? $b . '.' : '';
    return "CASE WHEN {$p}status = 'postponed' AND {$p}postponed_date IS NOT NULL AND {$p}postponed_date <> '0000-00-00'"
         . " THEN {$p}postponed_end_time ELSE {$p}end_time END";
}

function mrs_room_slot_conflicts($conn, $room_id, $date, $start, $end, $exclude_id = 0, $exclude_series = 0) {
    $sql = "SELECT COUNT(*) AS c FROM bookings
            WHERE room_id = ? AND status IN ('confirmed','postponed')
              AND " . mrs_eff_date_sql() . " = ?
              AND " . mrs_eff_start_sql() . " < ?
              AND " . mrs_eff_end_sql() . " > ?";
    $types = 'isss';
    $params = [$room_id, $date, $end, $start];
    if ($exclude_id) {
        $sql .= " AND id <> ?";
        $types .= 'i';
        $params[] = $exclude_id;
    }
    if ($exclude_series) {
        $sql .= " AND (series_id IS NULL OR series_id <> ?)";
        $types .= 'i';
        $params[] = $exclude_series;
    }
    $st = $conn->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return (int) ($row['c'] ?? 0);
}

function mrs_projector_slot_busy($conn, $projector_id, $date, $start, $end, $exclude_id = 0, $exclude_series = 0) {
    $sql = "SELECT b.meeting_title,
                   " . mrs_eff_start_sql('b') . " AS eff_start,
                   " . mrs_eff_end_sql('b') . " AS eff_end,
                   u.full_name
            FROM bookings b
            JOIN users u ON b.user_id = u.id
            WHERE b.projector_id = ? AND b.status IN ('confirmed','postponed')
              AND " . mrs_eff_date_sql('b') . " = ?
              AND " . mrs_eff_start_sql('b') . " < ?
              AND " . mrs_eff_end_sql('b') . " > ?";
    $types = 'isss';
    $params = [$projector_id, $date, $end, $start];
    if ($exclude_id) {
        $sql .= " AND b.id <> ?";
        $types .= 'i';
        $params[] = $exclude_id;
    }
    if ($exclude_series) {
        $sql .= " AND (b.series_id IS NULL OR b.series_id <> ?)";
        $types .= 'i';
        $params[] = $exclude_series;
    }
    $sql .= " LIMIT 1";
    $st = $conn->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return $row ?: null;
}

/**
 * RACE (Sprint 2 #6): fungua transaction na LOCK ya sati ya chumba
 * (na projector kama zinahusika). Ombi mbili za wakati mmoja zenye ombi
 * la chumba lenyewe hushindana hapa MOJA KWA MOJA: la pili lisubiri lock,
 * halifanikiwa kuona "chumba kiko huru" kwa wakati uleule na ku-insert.
 *
 * Mfumuo: mmsa anatumia $conflict-check + INSERT ndani ya transaction hii.
 * Caller LAZIMA ache (commit) au aache (rollback) kila njia ya kutoka.
 *
 * Rudisha false kama lock haikufanikiwa (rollback tayari imetendwa).
 */
function mrs_lock_slot($conn, $room_id, $projector_id = 0) {
    if (!$conn->begin_transaction()) {
        return false;
    }
    $st = $conn->prepare("SELECT id FROM rooms WHERE id = ? FOR UPDATE");
    $st->bind_param("i", $room_id);
    $st->execute();
    $st->store_result();
    if ($st->num_rows !== 1) {
        $conn->rollback();
        return false;
    }
    if ($projector_id) {
        $ps = $conn->prepare("SELECT id FROM projectors WHERE id = ? FOR UPDATE");
        $ps->bind_param("i", $projector_id);
        $ps->execute();
        $ps->store_result();
        if ($ps->num_rows !== 1) {
            $conn->rollback();
            return false;
        }
    }
    return true;
}

