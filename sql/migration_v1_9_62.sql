-- migration_v1_9_62.sql — Fix tab Pratix: la commessa si lega alle righe pratix tramite
-- commessa_id (= cm_projects.id), non tramite il codice. Vista 1-a-N ridefinita di
-- conseguenza: filtro per project_id.

CREATE OR REPLACE VIEW `v_cm_pratix_commessa_codici` AS
SELECT DISTINCT
       r.`commessa_id`          AS `project_id`,
       r.`commessa`             AS `commessa`,
       r.`order_code`           AS `codice_pratix`,
       e.`cliente_fatturazione` AS `cliente_fatturazione`,
       e.`cliente_effettivo`    AS `cliente_effettivo`
FROM `v_cm_pratix_righe` r
LEFT JOIN `cm_pratix_ext` e
       ON e.`order_code` = r.`order_code` COLLATE utf8mb4_unicode_ci
WHERE r.`commessa_id` IS NOT NULL AND r.`commessa_id` <> 0;

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL,
  `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `app_settings` SET `setting_value`='1.9.62'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`)
 VALUES ('1.9.62','migration_v1_9_62.sql');
