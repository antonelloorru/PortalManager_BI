-- ════════════════════════════════════════════════════════════════
--  certV 4.2 — migration_2fa_admin.sql
--
--  Aggiunge il modello "admin-controlled 2FA":
--  Solo il Super Admin può autorizzare un utente a usare TOTP/Email.
--  L'utente abilitato fa il setup pratico (scansione QR) da solo.
--
--  v3: aggiunge colonne di autorizzazione separate dallo stato di setup
--  Permette di distinguere:
--    "admin autorizzato" vs "utente ha completato setup"
--
--  Eseguire DOPO migration_2fa_v2.sql (che crea le 3 tabelle base).
--  Idempotente: si può rieseguire senza danni.
-- ════════════════════════════════════════════════════════════════

-- Aggiunge le colonne di autorizzazione (flag che solo l'admin può modificare)
-- Procedure compatibili con MySQL 5.7+ e MariaDB 10+

DELIMITER $$

DROP PROCEDURE IF EXISTS add_2fa_admin_columns $$
CREATE PROCEDURE add_2fa_admin_columns()
BEGIN
    -- totp_authorized: l'admin ha autorizzato l'utente a configurare TOTP
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_2fa' AND COLUMN_NAME = 'totp_authorized'
    ) THEN
        ALTER TABLE `user_2fa`
        ADD COLUMN `totp_authorized` TINYINT(1) NOT NULL DEFAULT 0
            COMMENT 'Admin ha autorizzato l''utente a usare TOTP'
        AFTER `totp_secret`;
    END IF;

    -- email_otp_authorized: stesso significato per email OTP
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_2fa' AND COLUMN_NAME = 'email_otp_authorized'
    ) THEN
        ALTER TABLE `user_2fa`
        ADD COLUMN `email_otp_authorized` TINYINT(1) NOT NULL DEFAULT 0
            COMMENT 'Admin ha autorizzato Email OTP per questo utente'
        AFTER `email_otp_enabled`;
    END IF;

    -- authorized_by: chi ha autorizzato (audit)
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_2fa' AND COLUMN_NAME = 'authorized_by'
    ) THEN
        ALTER TABLE `user_2fa`
        ADD COLUMN `authorized_by` INT(11) DEFAULT NULL
            COMMENT 'user_id dell''admin che ha autorizzato'
        AFTER `email_otp_authorized`;
    END IF;

    -- authorized_at: quando è stato autorizzato (audit)
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_2fa' AND COLUMN_NAME = 'authorized_at'
    ) THEN
        ALTER TABLE `user_2fa`
        ADD COLUMN `authorized_at` DATETIME DEFAULT NULL
        AFTER `authorized_by`;
    END IF;
END $$

CALL add_2fa_admin_columns() $$
DROP PROCEDURE IF EXISTS add_2fa_admin_columns $$

DELIMITER ;

-- Migra dati esistenti: chi aveva email_otp_enabled=1 viene anche segnato come autorizzato
-- (così non perde l'attivazione se aveva fatto setup con la versione precedente)
UPDATE `user_2fa`
SET `email_otp_authorized` = 1
WHERE `email_otp_enabled` = 1 AND `email_otp_authorized` = 0;

-- Stesso per TOTP: chi aveva già configurato è autorizzato di fatto
UPDATE `user_2fa`
SET `totp_authorized` = 1
WHERE `totp_enabled` = 1 AND `totp_secret` IS NOT NULL AND `totp_authorized` = 0;

-- Aggiunge la pagina admin manage_users_2fa.php ai permessi (solo Super Admin)
INSERT IGNORE INTO `role_permissions` (`role_id`, `page_name`, `can_view`, `can_create`, `can_edit`, `can_delete`, `can_export`)
VALUES
  (1, 'manage_users_2fa.php', 1, 1, 1, 1, 0);

-- Per il pannello self-service utente, lascia il permesso esistente
-- (creato da migration_2fa_v2.sql) — viene mostrato solo se l'utente
-- ha qualche autorizzazione admin attiva. La logica di visibilità è
-- nel header.php (controllo runtime sullo stato user_2fa).
