-- migration_v1_9_78.sql — Filtro globale Codice Contratto / PM Project propagato a
-- Service Desk, Report direzionale, Attivita e Rendicontazione DGB (oltre alla Relazione IT).
-- Nessuna nuova tabella: solo indici a supporto della risoluzione del filtro
-- (ticket delle attivita DGB e dei rapportini, codice commessa dei rapportini).

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS `idx_dfa_ticket` ON `dgb_forms_activity` (`ticket`);
CREATE INDEX IF NOT EXISTS `idx_dfa_contract` ON `dgb_forms_activity` (`id_contract`);
CREATE INDEX IF NOT EXISTS `idx_ir_ticket` ON `cm_intervention_reports` (`ticket`);
CREATE INDEX IF NOT EXISTS `idx_ir_project_code` ON `cm_intervention_reports` (`project_code`);
CREATE INDEX IF NOT EXISTS `idx_cmp_dgb_contract` ON `cm_projects` (`dgb_contract_id`);

UPDATE `app_settings` SET `setting_value`='1.9.78'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.9.78','migration_v1_9_78.sql');
