-- migration_v1_10_11.sql — Service SOC: consuntivo attivita dei componenti dell Unita Organizzativa SOC per tipologia di contratto e per operatore.
-- Nessuna modifica di schema: letture su v_cm_it_servizio con il modello della Relazione di Servizio IT. Allineamento versione. Idempotente.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS `idx_ir_ticket` ON `cm_intervention_reports` (`ticket`);

UPDATE `app_settings` SET `setting_value`='1.10.11'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.10.11','migration_v1_10_11.sql');
