-- migration_v1_9_79.sql — Collegamento rapportini alle attivita DGB.
-- I rapportini sincronizzati dal gestionale arrivavano senza dgb_activity_id: le viste
-- li classificavano tutti in sede e con fascia non rilevata, e i filtri Smart working
-- e Reperibilita della Relazione IT restituivano zero righe.
-- Chiave: dgb_source_id = id allocazione (dgb_forms_activity_operator.id), verificata sul
-- codice modulo. In subordine il codice modulo, solo se univoco. Idempotente.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS `idx_ir_dgb_activity` ON `cm_intervention_reports` (`dgb_activity_id`);
CREATE INDEX IF NOT EXISTS `idx_ir_dgb_source` ON `cm_intervention_reports` (`dgb_source_id`);
CREATE INDEX IF NOT EXISTS `idx_dfa_code` ON `dgb_forms_activity` (`code`);

UPDATE `cm_intervention_reports` r
  JOIN `dgb_forms_activity_operator` ao ON ao.`id` = r.`dgb_source_id`
  JOIN `dgb_forms_activity` a ON a.`id` = ao.`id_activity`
   SET r.`dgb_activity_id` = a.`id`, r.`dgb_activity_code` = a.`code`
 WHERE r.`dgb_activity_id` IS NULL AND r.`dgb_source_id` IS NOT NULL
   AND a.`code` = r.`report_code`;

UPDATE `cm_intervention_reports` r
  JOIN (SELECT MIN(`id`) AS `id`, `code` FROM `dgb_forms_activity`
         WHERE `code` IS NOT NULL AND `code` <> '' AND COALESCE(`deleted`, 0) = 0
         GROUP BY `code` HAVING COUNT(*) = 1) a ON a.`code` = r.`report_code`
   SET r.`dgb_activity_id` = a.`id`, r.`dgb_activity_code` = a.`code`
 WHERE r.`dgb_activity_id` IS NULL;

-- copie delle viste (v1.9.73): marcate come scadute, si ricostruiscono alla prossima
-- richiesta, nel frattempo le pagine leggono le viste
UPDATE `pm_snapshot` SET `refreshed_at` = '2000-01-01 00:00:00' WHERE `refreshed_at` IS NOT NULL;

UPDATE `app_settings` SET `setting_value`='1.9.79'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.9.79','migration_v1_9_79.sql');
