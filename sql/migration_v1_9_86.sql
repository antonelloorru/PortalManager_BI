-- migration_v1_9_86.sql — Posizioni aperte: pannello filtri multi-selezione con ricerca.
-- Nessuna modifica di schema: indici di supporto ai filtri e allineamento versione.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS `idx_jp_status_prio` ON `job_positions` (`status`, `priority`);
CREATE INDEX IF NOT EXISTS `idx_jp_opened` ON `job_positions` (`opened_at`);
CREATE INDEX IF NOT EXISTS `idx_jp_target` ON `job_positions` (`target_date`);
CREATE INDEX IF NOT EXISTS `idx_ca_pos_stage` ON `candidate_applications` (`position_id`, `stage`);

UPDATE `app_settings` SET `setting_value`='1.9.86'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.9.86','migration_v1_9_86.sql');
