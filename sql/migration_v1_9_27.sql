-- =====================================================================
-- PortalManager v1.9.27 — HOTFIX data-loss employee_profile.php
-- Nessun DDL. Solo log migration + bump versione.
-- =====================================================================
CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version`    VARCHAR(20)  NOT NULL,
  `filename`   VARCHAR(190) NOT NULL,
  `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `pm_migration_sql` (`version`,`filename`,`applied_at`)
VALUES ('1.9.27','migration_v1_9_27.sql',NOW())
ON DUPLICATE KEY UPDATE `applied_at`=NOW();

INSERT INTO `app_settings` (`setting_key`,`setting_value`,`description`)
VALUES ('app_version','1.9.27','Hotfix employee_profile.php: pre-fetch $emp per prevenire data-loss')
ON DUPLICATE KEY UPDATE `setting_value`='1.9.27', `description`=VALUES(`description`);
