-- migration_v1_10_09.sql — Service SOC: incaricato dedotto dai messaggi quando la sorgente non lo riporta e operatori abbinati anche come autori.
-- Nuova colonna cm_soc_tickets.assignee_source (sorgente, dedotto). La ricostruzione dei ticket avviene alla prossima esecuzione della pipeline. Idempotente.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `cm_soc_tickets`
  ADD COLUMN IF NOT EXISTS `assignee_source` varchar(10) DEFAULT NULL
  COMMENT 'sorgente = incaricato dall export o dalla query, dedotto = primo operatore che risponde o annota il ticket' AFTER `assignee_name`;

-- la pipeline ricostruisce i ticket alla prossima esecuzione
UPDATE `app_settings` SET `setting_value` = '1970-01-01 00:00:00' WHERE `setting_key` = 'soc.last_run_at'
   AND NOT EXISTS (SELECT 1 FROM `pm_migration_sql` m WHERE m.`version` = '1.10.09');

UPDATE `app_settings` SET `setting_value`='1.10.09'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.10.09','migration_v1_10_09.sql');
