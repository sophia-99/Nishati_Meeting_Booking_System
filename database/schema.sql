-- Mfumo wa Kuweka Order za Meeting Rooms (Room Booking System)
-- Tengeneza database kisha endesha faili hii
--
-- SASISHO (25/09/2026): faili hii sasa inajenga jedwali ZOTE 13 za mfumo.
--   Kabla ilikuwa 10 tu — `mail_settings` ilikuwa haijajengwa hapa (ilikuwa
--   kwenye update_mail.sql pekee), hivyo install safi ilikuwa na kasoro.
--   Kwa HAMISHA ya mfumo kwenye server/kompyuta nyingine (structure + data
--   yote), tumia `full.sql` badala ya faili hii.

CREATE DATABASE IF NOT EXISTS room_booking_system;
USE room_booking_system;

-- Jedwali la watumiaji
-- status: 'pending' = hajathibitisha email yake bado (hover kunamaliza usajili),
--         'active'   = anafanya kazi,
--         'inactive' = amezuiwa na admin
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'staff') DEFAULT 'staff',
    status ENUM('pending', 'active', 'inactive') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Jedwali la email verification (code ya tarakimu 6 kwa kila mtu pending)
-- USALAMA: code_hash ni HASH ya code — siyo code yenyewe.
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

-- Jedwali la vyumba vya mikutano
CREATE TABLE rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_name VARCHAR(100) NOT NULL,
    capacity INT NOT NULL,
    location VARCHAR(150),
    status ENUM('available', 'maintenance') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Jedwali la order za vyumba (bookings)
CREATE TABLE bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    user_id INT NOT NULL,
    meeting_title VARCHAR(150) NOT NULL,
    booking_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    Accessories ENUM('none', 'tv', 'projector') DEFAULT 'none',
    status ENUM('confirmed', 'postponed', 'cancelled') DEFAULT 'confirmed',
    postponed_date DATE DEFAULT NULL,
    postponed_start_time TIME DEFAULT NULL,
    postponed_end_time TIME DEFAULT NULL,
    postponed_reason VARCHAR(255) DEFAULT NULL,
    series_id INT DEFAULT NULL,
    series_end DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Jedwali la kuhifadhi token za kubadilisha password
CREATE TABLE password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Jedwali la kuripoti matatizo ya chumba kwa booking husika
CREATE TABLE room_issues (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    room_id INT NOT NULL,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    status ENUM('open', 'resolved') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Jedwali la projectors za wizara (inventory ya jumla)
CREATE TABLE projectors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    model VARCHAR(100) DEFAULT NULL,
    location VARCHAR(150) DEFAULT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Kuunganisha booking na projector iliyochaguliwa
ALTER TABLE bookings
    ADD COLUMN projector_id INT DEFAULT NULL AFTER Accessories,
    ADD FOREIGN KEY (projector_id) REFERENCES projectors(id) ON DELETE SET NULL;

-- Sample admin (password: admin123)
-- status wazi = 'active' (kikawaida kinachukuliwa ni 'pending')
INSERT INTO users (full_name, email, password, role, status)
VALUES ('Office Admin', 'admin@ofisi.co.tz', '$2b$10$UEWvIL0/eZL99H7aJ0YhL.hz85K9y7sFKO.ORRK78ZFPGBjTyQDC.', 'admin', 'active');

-- Sample rooms
INSERT INTO rooms (room_name, capacity, location) VALUES
('Meeting Room A', 10, '1st Floor'),
('Meeting Room B', 6, '1st Floor'),
('Training Hall', 30, '2nd Floor');

-- Sample projectors
INSERT INTO projectors (name, model, location) VALUES
('Projector 01', 'Epson EB-X06', 'Main Store'),
('Projector 02', 'Ben TH585', 'Main Store'),
('Projector 03', 'Canon LV-X300', 'Annex Store');

-- Jedwali la audit log (matendo ya watumiaji)
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

-- Jedwali la reminder log (kuzuia marudio — kwa kila sifa ya kumbusho)
CREATE TABLE IF NOT EXISTS reminder_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    for_date DATE NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_reminder_book_date (booking_id, for_date),
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
);

-- Jedwali la majaribio ya login (brute-force protection)
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

-- =====================================================================
--  SASISHO 25/09/2026 — JEDWALI LA 11: MIPANGILIO YA SMTP (mail_settings)
--  Inasimamiwa na paneli ya admin (admin_mail_config.php).
--  USALAMA: smtp_password_enc = password IMEFICHWA (AES-256-GCM, ufunguo
--  uko faili nje ya webroot) — kamwe si text wazi kwenye DB.
--  config_locked = 1 → Read-Only (auto-lock baada ya kila save ya admin).
--  Kipaumbele: env var > DB > default (ona includes/mail_settings.php).
--  REKEBISHO: jedwali hili linalazima liwe na safu MOJA (id=1) pekee —
--  ndiyo inayosomwa na mfumo; bila hiyo SMTP haitafanya kazi.
-- =====================================================================
CREATE TABLE IF NOT EXISTS mail_settings (
    id               TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    smtp_host        VARCHAR(120)  NOT NULL DEFAULT 'smtp.gmail.com',
    smtp_port        SMALLINT UNSIGNED NOT NULL DEFAULT 587,
    smtp_encryption  VARCHAR(10)   NOT NULL DEFAULT 'tls',   -- tls | ssl | none
    smtp_user        VARCHAR(190)  NOT NULL DEFAULT '',
    smtp_password_enc TEXT         NULL,                      -- NULL = haijawekwa
    from_email       VARCHAR(190)  NOT NULL DEFAULT '',
    from_name        VARCHAR(150)  NOT NULL DEFAULT 'Mfumo wa Meeting Rooms',
    admin_email      VARCHAR(190)  NOT NULL DEFAULT '',
    enabled          TINYINT(1)    NOT NULL DEFAULT 1,
    config_locked    TINYINT(1)    NOT NULL DEFAULT 1,
    updated_at       DATETIME      NULL,
    updated_by       INT           NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Safu moja tu (id=1) hufanya kazi — ongeza kama bado haipo
INSERT INTO mail_settings (id) VALUES (1)
    ON DUPLICATE KEY UPDATE id = id;

-- =====================================================================
--  SASISHO 25/09/2026 — JEDWALI 12 & 13: ROLES & PERMISSIONS
--  (admin_roles.php + includes/permissions.php)
--  permissions      = activity zote za mfumo (module/submodule/activity)
--  role_permissions = kila role (admin/staff) ina ruhusa gani (0/1)
--  Kumbuka: activity mpya huongezwa yenyewe kutoka registry ya
--  includes/permissions.php na page ya admin_roles.php (INSERT IGNORE).
--  Angalia pia update_roles.sql (uhamisho rasmi).
-- =====================================================================
CREATE TABLE IF NOT EXISTS permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module VARCHAR(60) NOT NULL,
    submodule VARCHAR(60) NOT NULL DEFAULT '',
    activity_code VARCHAR(80) NOT NULL UNIQUE,
    activity_label VARCHAR(150) NOT NULL,
    default_admin TINYINT(1) NOT NULL DEFAULT 1,
    default_staff TINYINT(1) NOT NULL DEFAULT 0,
    lock_admin TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS role_permissions (
    role VARCHAR(30) NOT NULL,
    permission_id INT NOT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (role, permission_id),
    CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id)
        REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
