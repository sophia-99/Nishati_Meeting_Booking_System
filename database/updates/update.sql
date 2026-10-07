-- Endesha faili hii kwenye database yako (room_booking_system)
-- Salama kuendesha mara nyingi

USE room_booking_system;

-- Projector Inventory
CREATE TABLE IF NOT EXISTS projectors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    model VARCHAR(100) DEFAULT NULL,
    location VARCHAR(150) DEFAULT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Hakikisha bookings.projector_id ipo
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'projector_id');
SET @sql := IF(@col = 0, 'ALTER TABLE bookings ADD COLUMN projector_id INT DEFAULT NULL AFTER Accessories', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Continuous meeting series
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'series_id');
SET @sql := IF(@col = 0, 'ALTER TABLE bookings ADD COLUMN series_id INT DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'series_end');
SET @sql := IF(@col = 0, 'ALTER TABLE bookings ADD COLUMN series_end DATE DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Sample projectors (ongeza kama bado hayapo)
INSERT INTO projectors (name, model, location)
SELECT 'Projector 01', 'Epson EB-X06', 'Main Store' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM projectors WHERE name = 'Projector 01');

INSERT INTO projectors (name, model, location)
SELECT 'Projector 02', 'Ben TH585', 'Main Store' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM projectors WHERE name = 'Projector 02');

INSERT INTO projectors (name, model, location)
SELECT 'Projector 03', 'Canon LV-X300', 'Annex Store' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM projectors WHERE name = 'Projector 03');

-- Audit Log
CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50) DEFAULT NULL,
    entity_id INT DEFAULT NULL,
    details TEXT DEFAULT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_action (action),
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_created (created_at)
);

-- Reminder Log (kuzuia marudio ya email za kumbusho — kwa kila sifa ya kumbusho)
CREATE TABLE IF NOT EXISTS reminder_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    for_date DATE NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_reminder_book_date (booking_id, for_date),
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
);

-- Upgrade (Sprint 4): sasa kumbusho hulingana na tarehe HALISI ya booking
-- (postponed inaweza kuhamia siku nyingine) — kila (booking, sifa) marudio moja.
ALTER TABLE reminder_log
    DROP INDEX uniq_reminder_booking,
    ADD COLUMN for_date DATE NULL AFTER booking_id,
    ADD UNIQUE KEY uniq_reminder_book_date (booking_id, for_date);

-- Login Attempts (brute-force protection: kikomo cha majaribio ya login)
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempt_email (email),
    INDEX idx_attempt_ip (ip),
    INDEX idx_attempt_time (attempted_at)
);

-- Futa majaribio ya zamani ya login (usafi wa data)
DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 7 DAY);

-- =====================================================================
--  KIPENGELE KIPYA: THIBITISHO YA BARUA PEPE (Email verification)
--  Usajili lazima uwe na email ya @nishati.go.tz na mtumiaji aweke
--  code ya tarakimu 6 iliyotumwa kwenye email hiyo.
-- =====================================================================

-- 1) Hali mpya: "pending" = amesajiliwa lakini hajathibitisha email bado
--    Default ni "pending" — INSERT isiyoainisha hali huanza pending.
ALTER TABLE users
    MODIFY status ENUM('pending', 'active', 'inactive') DEFAULT 'pending';

-- 2) Jedwali la email verification
--    USALAMA: code_hash ni HASH ya code (siyo code yenyewe).
--             Kama DB itaibwa, attacker hawezi kusoma code.
--             `attempts` huzuia kupiga chini code kwa majaribio mengi.
--             `sent_count` + `last_sent_at` huzuia spam ya resend.
CREATE TABLE IF NOT EXISTS email_verifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    sent_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
    last_sent_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ev_user (user_id),
    KEY idx_ev_expires (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Usafi: futa code zilizokwisha muda (zile za siku 1 zilizopita)
DELETE FROM email_verifications WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY);
