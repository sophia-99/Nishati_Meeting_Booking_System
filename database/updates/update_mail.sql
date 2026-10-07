-- ============================================================
--  MAIL CONFIGURATION (admin panel)
--  Hifadhi ya mipangilio ya SMTP inayosimamiwa na admin paneli.
--  Password haifichuliwi: huhifadhiwa ENCRYPTED (AES-256-GCM,
--  ufunguo uko faili nje ya webroot) kwenye smtp_password_enc.
--  Kipaumbele: env var > DB > default (ona includes/mail_settings.php)
-- ============================================================
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
    updated_at       DATETIME      NULL,
    updated_by       INT           NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO mail_settings (id) VALUES (1)
    ON DUPLICATE KEY UPDATE id = id;

-- =====================================================================
--  READ-ONLY MODE (muundo wa kusoma tu / edit) kwa paneli ya admin.
--  1 = Read-Only (host/port/encryption/... HAZIBADILIKI)
--  0 = Edit mode (admin alifungua kwa hiari)
--  Kipaumbele: kila save ya kikamilifu hurudisha mode = 1 (auto-lock).
-- =====================================================================
ALTER TABLE mail_settings ADD COLUMN config_locked TINYINT(1) NOT NULL DEFAULT 1 AFTER enabled;
