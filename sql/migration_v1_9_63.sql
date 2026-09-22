-- migration_v1_9_63.sql — Import certificazioni Omnissa: permessi voce di menu + bump.
-- Nessuna nuova tabella (usa certifications / user_certifications / brands esistenti).

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `role_permissions`
  (`role_id`,`page_name`,`can_view`,`can_create`,`can_edit`,`can_delete`,`can_export`)
VALUES
 (1,'cert_import_omnissa.php',1,1,1,1,1),
 (2,'cert_import_omnissa.php',1,1,1,0,1)
ON DUPLICATE KEY UPDATE
  `can_view`=VALUES(`can_view`), `can_create`=VALUES(`can_create`),
  `can_edit`=VALUES(`can_edit`), `can_export`=VALUES(`can_export`);

UPDATE `app_settings` SET `setting_value`='1.9.63'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`)
 VALUES ('1.9.63','migration_v1_9_63.sql');
