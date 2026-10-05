-- migration_v1_10_01.sql — Progetti PRJ, fase 4 di 6: elenco PRJ, scheda progetto, collegamento alla commessa SP, parametri.
-- Nessuna modifica di schema (tabelle cm_prj* dalla v1.9.99). Indice per lo storico dei collegamenti, permessi e versione.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS `idx_plh_azione` ON `cm_prj_link_history` (`azione`,`prj_id`);
CREATE INDEX IF NOT EXISTS `idx_cmp_commercial_ref` ON `cm_projects` (`commercial_ref`);

-- permessi: i ruoli con accesso alla scheda PRJ vedono anche i Parametri dimensionamento (sola lettura)
INSERT IGNORE INTO `role_permissions` (`role_id`,`page_name`,`can_view`,`can_create`,`can_edit`,`can_delete`,`can_export`)
SELECT rp.`role_id`, 'prj_parameters.php', 1, 0, 0, 0, 0
  FROM `role_permissions` rp
 WHERE rp.`page_name` = 'prj_dashboard.php' AND rp.`can_view` = 1;

UPDATE `app_settings` SET `setting_value`='1.10.01'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.10.01','migration_v1_10_01.sql');
