-- migration_v1_10_10.sql — Service SOC: categoria del ticket da tt_ticket.id_tt_category e tt_category, filtro Categoria a scelta multipla.
-- Imposta una rilettura completa una tantum del DB SOC per portare la categoria anche sugli eventi gia importati. Idempotente.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
 ('soc.full_resync','0','Service SOC: 1 = alla prossima esecuzione la pipeline rilegge tutto il DB SOC (poi torna a 0)');

-- rilettura completa e ricostruzione alla prossima esecuzione (solo alla prima applicazione)
UPDATE `app_settings` SET `setting_value` = '1' WHERE `setting_key` = 'soc.full_resync'
   AND NOT EXISTS (SELECT 1 FROM `pm_migration_sql` m WHERE m.`version` = '1.10.10');
UPDATE `app_settings` SET `setting_value` = '1970-01-01 00:00:00' WHERE `setting_key` = 'soc.last_run_at'
   AND NOT EXISTS (SELECT 1 FROM `pm_migration_sql` m WHERE m.`version` = '1.10.10');

CREATE INDEX IF NOT EXISTS `idx_soc_tickets_category` ON `cm_soc_tickets` (`category`);

UPDATE `app_settings` SET `setting_value`='1.10.10'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.10.10','migration_v1_10_10.sql');
