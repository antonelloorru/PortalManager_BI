-- migration_v1_9_83.sql — Auto-sync RBAC: catalogo pagine, regole di seeding, registro sincronizzazioni.
-- Dopo questa migration le nuove pagine NON richiedono piu' INSERT manuali su role_permissions:
-- la sincronizzazione (app/RbacSync.php) le registra e applica le regole decise in Sistema.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `label` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `module` varchar(100) NOT NULL DEFAULT 'General',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_perm_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `permissions`
  ADD COLUMN IF NOT EXISTS `is_page` tinyint(1) NOT NULL DEFAULT 0 AFTER `module`,
  ADD COLUMN IF NOT EXISTS `in_menu` tinyint(1) NOT NULL DEFAULT 0 AFTER `is_page`,
  ADD COLUMN IF NOT EXISTS `in_router` tinyint(1) NOT NULL DEFAULT 0 AFTER `in_menu`,
  ADD COLUMN IF NOT EXISTS `in_curated` tinyint(1) NOT NULL DEFAULT 0 AFTER `in_router`,
  ADD COLUMN IF NOT EXISTS `icon` varchar(64) NULL AFTER `in_curated`,
  ADD COLUMN IF NOT EXISTS `sort_order` int(11) NOT NULL DEFAULT 0 AFTER `icon`,
  ADD COLUMN IF NOT EXISTS `hard_gate_max_role` int(11) NULL AFTER `sort_order`,
  ADD COLUMN IF NOT EXISTS `is_active` tinyint(1) NOT NULL DEFAULT 1 AFTER `hard_gate_max_role`,
  ADD COLUMN IF NOT EXISTS `first_seen_at` datetime NULL AFTER `is_active`,
  ADD COLUMN IF NOT EXISTS `last_seen_at` datetime NULL AFTER `first_seen_at`;

CREATE INDEX IF NOT EXISTS `idx_perm_page` ON `permissions` (`is_page`, `is_active`);

CREATE TABLE IF NOT EXISTS `rbac_seed_rules` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id` int(11) NOT NULL,
  `scope` enum('all','section','page') NOT NULL DEFAULT 'section',
  `target` varchar(150) NULL COMMENT 'sezione del catalogo o pagina.php; NULL con scope all',
  `can_view` tinyint(1) NOT NULL DEFAULT 0,
  `can_create` tinyint(1) NOT NULL DEFAULT 0,
  `can_edit` tinyint(1) NOT NULL DEFAULT 0,
  `can_delete` tinyint(1) NOT NULL DEFAULT 0,
  `can_export` tinyint(1) NOT NULL DEFAULT 0,
  `note` varchar(255) NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rsr_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rbac_sync_log` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_at` datetime NOT NULL DEFAULT current_timestamp(),
  `trigger_type` varchar(30) NOT NULL,
  `mode` varchar(20) NOT NULL,
  `status` varchar(20) NOT NULL,
  `changes` int(11) NOT NULL DEFAULT 0,
  `warnings` int(11) NOT NULL DEFAULT 0,
  `report` longtext NULL,
  `user_id` int(11) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rsl_run` (`run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`) VALUES
  ('rbac_autosync_enabled', '1'),
  ('rbac_baseline_at', ''),
  ('rbac_last_sync_at', '');

-- nuova pagina Sistema → Sincronizzazione permessi (solo Super Admin, che non richiede righe)
INSERT IGNORE INTO `role_permissions` (`role_id`, `page_name`, `can_view`, `can_create`, `can_edit`, `can_delete`, `can_export`)
VALUES (1, 'rbac_sync.php', 1, 1, 1, 1, 1);

UPDATE `app_settings` SET `setting_value`='1.9.83'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.9.83','migration_v1_9_83.sql');
