-- migration_v1_9_89.sql — Relazione di Servizio IT: ore per classe coerenti, dettaglio ore non valorizzate.
-- Nessuna modifica di schema: indice di supporto alla ricerca delle tariffe e versione.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS `idx_ccr_project_nature` ON `cm_contract_rates` (`project_code`, `rate_nature`);

UPDATE `app_settings` SET `setting_value`='1.9.89'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.9.89','migration_v1_9_89.sql');
