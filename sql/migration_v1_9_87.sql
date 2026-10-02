-- migration_v1_9_87.sql — Relazione di Servizio IT: filtro Stato commessa e perimetro unico dei filtri.
-- Nessuna modifica di schema: indici di supporto (gia' presenti nelle installazioni recenti) e versione.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS `idx_proj_opstatus` ON `cm_projects` (`operational_status`);
CREATE INDEX IF NOT EXISTS `idx_cmp_dgb_contract` ON `cm_projects` (`dgb_contract_id`);
CREATE INDEX IF NOT EXISTS `idx_ir_dgb_activity` ON `cm_intervention_reports` (`dgb_activity_id`);

UPDATE `app_settings` SET `setting_value`='1.9.87'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.9.87','migration_v1_9_87.sql');
