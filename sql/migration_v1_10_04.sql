-- migration_v1_10_04.sql — Attivita e Rendicontazione DGB: classi orarie allineate alla Relazione di Servizio IT.
-- Nessuna nuova tabella. Allinea la fine intervento dei moduli sincronizzati da DGB (cm_intervention_reports.end_at)
-- alla fine attivita DGB dove mancante, cosi le due pagine classificano le stesse righe sugli stessi orari. Idempotente.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `cm_intervention_reports` ir
  JOIN `dgb_forms_activity` a ON a.`id` = ir.`dgb_activity_id`
   SET ir.`end_at` = a.`date_dead_line`
 WHERE ir.`source_system` = 'dgb' AND ir.`end_at` IS NULL AND a.`date_dead_line` IS NOT NULL;

UPDATE `app_settings` SET `setting_value`='1.10.04'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.10.04','migration_v1_10_04.sql');
