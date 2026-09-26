-- migration_v1_9_77.sql — Relazione di Servizio IT: filtro globale Codice Contratto / PM Project.
-- Intervento solo-PHP, nessun delta di schema: gli indici usati dal filtro
-- (cm_projects.uq_project_code, idx_cmp_dgb_contract, dgb_forms_activity.idx_dfa_contract) esistono gia'.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS `idx_cmp_dgb_contract` ON `cm_projects` (`dgb_contract_id`);
CREATE INDEX IF NOT EXISTS `idx_dfa_contract` ON `dgb_forms_activity` (`id_contract`);

UPDATE `app_settings` SET `setting_value`='1.9.77'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.9.77','migration_v1_9_77.sql');
