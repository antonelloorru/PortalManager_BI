-- migration_v1_10_08.sql — Service SOC: connessione al DB SOC con server e credenziali della Connessione al gestionale.
-- Con use_gestionale = 1 driver, host, porta, utente e password vengono da cm_source_db attiva e cambia solo il database. Idempotente.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `cm_soc_source_db`
  ADD COLUMN IF NOT EXISTS `use_gestionale` tinyint(1) NOT NULL DEFAULT 0
  COMMENT '1 = server e credenziali della Connessione al gestionale (cm_source_db), cambia solo il database' AFTER `password_enc`;

-- Connessione esistente con lo stesso utente del gestionale: passa alle credenziali del gestionale (solo alla prima applicazione)
UPDATE `cm_soc_source_db` s
  JOIN (SELECT `username` FROM `cm_source_db` WHERE `is_active` = 1 ORDER BY `id` DESC LIMIT 1) g
    ON g.`username` = s.`username`
   SET s.`use_gestionale` = 1
 WHERE s.`use_gestionale` = 0
   AND NOT EXISTS (SELECT 1 FROM `pm_migration_sql` m WHERE m.`version` = '1.10.08');

UPDATE `app_settings` SET `setting_value`='1.10.08'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.10.08','migration_v1_10_08.sql');
