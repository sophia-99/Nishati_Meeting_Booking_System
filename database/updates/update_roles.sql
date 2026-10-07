-- =====================================================================
-- ROLES & PERMISSIONS (update_roles.sql)
-- Hufanya kazi: MRS > database (room_booking_system)
-- Kumbuka: page ya admin_roles.php hutengeneza tables hizi nenyewe
-- (CREATE TABLE IF NOT EXISTS + INSERT IGNORE) — faili hii ni ya
-- uhamisho rasmi / dokumentation kwa mkaguzi wa mfumo.
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

--(activity mpya zinaingizwa kutoka includes/permissions.php registry
--  na page ya admin_roles.php — INSERT IGNORE, hakuna rudufu.)
