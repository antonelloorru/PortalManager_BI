-- migration_v1_9_81.sql — Reimpostazione password self-service (link monouso via email).
-- Riusa la tabella password_resets (presente ma inutilizzata) estendendola.
-- La colonna token contiene SOLO l'hash SHA-256 del token, mai il token in chiaro.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(150) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `password_resets`
  ADD COLUMN IF NOT EXISTS `user_id` int(11) NULL AFTER `email`,
  ADD COLUMN IF NOT EXISTS `used_at` datetime NULL AFTER `used`,
  ADD COLUMN IF NOT EXISTS `request_ip` varchar(45) NULL AFTER `used_at`,
  ADD COLUMN IF NOT EXISTS `user_agent` varchar(255) NULL AFTER `request_ip`;

CREATE INDEX IF NOT EXISTS `idx_pwr_user` ON `password_resets` (`user_id`, `used`);
CREATE INDEX IF NOT EXISTS `idx_pwr_email` ON `password_resets` (`email`);
CREATE INDEX IF NOT EXISTS `idx_pwr_created` ON `password_resets` (`created_at`);

-- eventuali righe del formato precedente (token in chiaro, senza utente): non piu' utilizzabili
UPDATE `password_resets` SET `used` = 1 WHERE `user_id` IS NULL AND `used` = 0;

ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `password_changed_at` datetime NULL AFTER `password_hash`;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`) VALUES
  ('pwd_reset_enabled', '1'),
  ('pwd_reset_ttl_min', '60'),
  ('pwd_min_length', '12'),
  ('app_public_url', '');

UPDATE `app_settings` SET `setting_value`='1.9.81'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.9.81','migration_v1_9_81.sql');
