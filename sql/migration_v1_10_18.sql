-- migration_v1_10_18.sql — Sito web WordPress (pm-ats): stato di pubblicazione per posizione e anteprima dell'annuncio.
-- job_positions.web_status: publish = visibile sul sito, draft = bozza sul sito (non visibile), off = non pubblicare o ritirata.
-- Predefinito publish: comportamento invariato per le posizioni esistenti. Idempotente (MariaDB 10.4: ADD COLUMN IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `job_positions`
  ADD COLUMN IF NOT EXISTS `web_status` ENUM('publish','draft','off') NOT NULL DEFAULT 'publish' COMMENT 'Sito web: publish, draft (bozza), off (non pubblicare)',
  ADD COLUMN IF NOT EXISTS `web_status_at` DATETIME NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `web_status_by` INT(11) NULL DEFAULT NULL;

UPDATE `app_settings` SET `setting_value`='1.10.18'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.10.18','migration_v1_10_18.sql');
