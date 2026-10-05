-- migration_v1_10_02.sql — Progetti PRJ, fase 5 di 6: KPI e penali, punteggio, storico, Scenari e confronti progetti,
-- tab Progetti PRJ nella scheda commessa, colonna Progetti PRJ nell elenco commesse.
-- Nessuna modifica di schema. Indici di supporto alle letture dei calc run e dello storico, versione.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS `idx_prun_created` ON `cm_prj_calc_run` (`created_at`);
CREATE INDEX IF NOT EXISTS `idx_prun_prj_scen` ON `cm_prj_calc_run` (`prj_id`,`scenario_id`,`id`);
CREATE INDEX IF NOT EXISTS `idx_pcin_prj` ON `cm_prj_criterion_input` (`prj_id`,`scenario_id`);
CREATE INDEX IF NOT EXISTS `idx_ecl_table_entity_id` ON `entity_change_log` (`entity_table`,`entity_id`,`id`);

UPDATE `app_settings` SET `setting_value`='1.10.02'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.10.02','migration_v1_10_02.sql');
