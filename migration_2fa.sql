-- ════════════════════════════════════════════════════════════════
--  certV 4.1 — migration_2fa.sql
--  Aggiunge le tabelle per 2FA TOTP + Email OTP + Recovery Codes.
--
--  Esecuzione: importare in phpMyAdmin oppure via CLI:
--     mysql -u <user> -p cert_management < migration_2fa.sql
--
--  Sicurezza:
--    - Il TOTP secret è memorizzato in chiaro (necessario per HMAC).
--      Garantire che il DB sia accessibile solo all'app e che i backup
--      siano cifrati. Considerare cifratura a riposo se richiesta.
--    - I recovery code sono salvati come hash bcrypt.
-- ════════════════════════════════════════════════════════════════

-- Tabella principale stato 2FA per utente
CREATE TABLE IF NOT EXISTS `user_2fa` (
  `user_id`            INT UNSIGNED NOT NULL PRIMARY KEY,
  `totp_secret`        VARCHAR(64)  DEFAULT NULL COMMENT 'Base32 secret RFC 6238',
  `totp_enabled`       TINYINT(1)   NOT NULL DEFAULT 0,
  `email_otp_enabled`  TINYINT(1)   NOT NULL DEFAULT 0,
  `verified_at`        DATETIME     DEFAULT NULL COMMENT 'Prima volta che 2FA è stata verificata',
  `last_used_at`       DATETIME     DEFAULT NULL,
  `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_user_2fa_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella codici di recupero (10 per utente, hash bcrypt, one-time)
CREATE TABLE IF NOT EXISTS `user_2fa_recovery_codes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `code_hash`  VARCHAR(255) NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `used_at`    DATETIME     DEFAULT NULL,
  KEY `idx_user_unused` (`user_id`, `used_at`),
  CONSTRAINT `fk_recovery_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log dei tentativi 2FA (audit trail)
CREATE TABLE IF NOT EXISTS `user_2fa_attempts` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `method`     ENUM('totp','email','recovery') NOT NULL,
  `success`    TINYINT(1) NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_user_created` (`user_id`, `created_at`),
  KEY `idx_success_created` (`success`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aggiunge la pagina 2fa_settings.php alla matrice permessi (visibile a tutti i ruoli)
INSERT IGNORE INTO `role_permissions` (`role_id`, `page_name`, `can_view`, `can_create`, `can_edit`, `can_delete`, `can_export`)
VALUES
  (1, '2fa_settings.php', 1, 1, 1, 1, 0),
  (2, '2fa_settings.php', 1, 1, 1, 1, 0),
  (3, '2fa_settings.php', 1, 1, 1, 1, 0),
  (4, '2fa_settings.php', 1, 1, 1, 1, 0),
  (5, '2fa_settings.php', 1, 1, 1, 1, 0),
  (6, '2fa_settings.php', 1, 1, 1, 1, 0);
