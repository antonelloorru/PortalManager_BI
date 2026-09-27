-- migration_v1_9_80.sql — Report Certificazioni: pannello filtri esteso.
-- Intervento solo-PHP. Indici a supporto dei nuovi filtri (idempotenti).

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS `idx_uc_expiry` ON `user_certifications` (`expiry_date`);
CREATE INDEX IF NOT EXISTS `idx_uc_issue` ON `user_certifications` (`issue_date`);
CREATE INDEX IF NOT EXISTS `idx_uc_employee` ON `user_certifications` (`employee_id`);
CREATE INDEX IF NOT EXISTS `idx_uc_cert` ON `user_certifications` (`certification_id`);

UPDATE `app_settings` SET `setting_value`='1.9.80'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.9.80','migration_v1_9_80.sql');
