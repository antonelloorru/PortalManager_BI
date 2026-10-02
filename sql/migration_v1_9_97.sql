-- migration_v1_9_97.sql — Attivita e Rendicontazione DGB: pannello filtri multi-selezione con ricerca e nuovi parametri.
-- Nessuna modifica di schema: indici di supporto ai nuovi filtri e versione.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS `idx_dgbfa_activitytype` ON `dgb_forms_activity` (`id_activitytype`);
CREATE INDEX IF NOT EXISTS `idx_dgbfa_customer` ON `dgb_forms_activity` (`id_customer_comp`);
CREATE INDEX IF NOT EXISTS `idx_dgbfao_activity_operator` ON `dgb_forms_activity_operator` (`id_activity`, `id_operator`);

UPDATE `app_settings` SET `setting_value`='1.9.97'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.9.97','migration_v1_9_97.sql');
