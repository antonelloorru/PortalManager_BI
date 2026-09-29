-- migration_v1_9_82.sql — Audit RBAC menu (ruolo Responsabile Commerciale).
-- Il ruolo 9 aveva permessi di visualizzazione su pagine assenti dalla matrice Permessi:
-- le voci comparivano nel menu ma in Amministrazione risultavano non assegnate e non revocabili.
-- Questi permessi vengono azzerati (non cancellati: restano visibili e riassegnabili dalla
-- matrice) dopo averne salvato una copia in role_permissions_backup.
-- Ripristino: vedi docs/DEPLOYMENT_v1_9_82.md.
-- Rieseguibile: dopo la prima esecuzione (registrata in pm_migration_sql) non azzera piu' nulla,
-- quindi non revoca i permessi riassegnati in seguito dall'amministratore.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_permissions_backup` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL,
  `role_id` int(11) NOT NULL,
  `page_name` varchar(100) NOT NULL,
  `can_view` tinyint(1) NOT NULL, `can_create` tinyint(1) NOT NULL, `can_edit` tinyint(1) NOT NULL,
  `can_delete` tinyint(1) NOT NULL, `can_export` tinyint(1) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `backed_up_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rpb_v_r_p` (`version`,`role_id`,`page_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `role_permissions_backup`
       (`version`,`role_id`,`page_name`,`can_view`,`can_create`,`can_edit`,`can_delete`,`can_export`,`reason`)
SELECT '1.9.82', rp.`role_id`, rp.`page_name`, rp.`can_view`, rp.`can_create`, rp.`can_edit`, rp.`can_delete`, rp.`can_export`,
       'permesso non assegnabile dalla matrice (voce di menu non autorizzata)'
  FROM `role_permissions` rp
  JOIN `roles` r ON r.`id` = rp.`role_id`
 WHERE r.`name` = 'Responsabile Commerciale'
   AND rp.`page_name` IN ('pratix_orders.php','service_desk.php','it_service.php','dir_report.php','organigramma.php',
                          'relazione_servizio_it.php','report_servizi_it.php','manage_applications.php','manage_job_positions.php')
   AND (rp.`can_view` + rp.`can_create` + rp.`can_edit` + rp.`can_delete` + rp.`can_export`) > 0;

UPDATE `role_permissions` rp
  JOIN `roles` r ON r.`id` = rp.`role_id`
   SET rp.`can_view` = 0, rp.`can_create` = 0, rp.`can_edit` = 0, rp.`can_delete` = 0, rp.`can_export` = 0
 WHERE r.`name` = 'Responsabile Commerciale'
   AND rp.`page_name` IN ('pratix_orders.php','service_desk.php','it_service.php','dir_report.php','organigramma.php',
                          'relazione_servizio_it.php','report_servizi_it.php','manage_applications.php','manage_job_positions.php')
   AND NOT EXISTS (SELECT 1 FROM `pm_migration_sql` m WHERE m.`version` = '1.9.82' AND m.`filename` = 'migration_v1_9_82.sql')
   AND EXISTS (SELECT 1 FROM `role_permissions_backup` b
                WHERE b.`version` = '1.9.82' AND b.`role_id` = rp.`role_id` AND b.`page_name` = rp.`page_name`);

-- Nega per impostazione predefinita: un INSERT che omette i flag non concede piu' nulla
-- (prima can_view, can_create, can_edit, can_export avevano DEFAULT 1)
ALTER TABLE `role_permissions`
  ALTER COLUMN `can_view` SET DEFAULT 0,
  ALTER COLUMN `can_create` SET DEFAULT 0,
  ALTER COLUMN `can_edit` SET DEFAULT 0,
  ALTER COLUMN `can_delete` SET DEFAULT 0,
  ALTER COLUMN `can_export` SET DEFAULT 0;

UPDATE `app_settings` SET `setting_value`='1.9.82'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.9.82','migration_v1_9_82.sql');
