-- ============================================================================
-- PortalManager — 20260918_113000_fix_menu_permissions.sql
-- Allineamento Idempotente Schema RBAC, Tabella menu_preferences e Dizionario Permessi
-- Dialetto: MySQL 5.7+ / 8.0+ / MariaDB 10.x+
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Tabella preferenze menu (idempotente con PK inline)
CREATE TABLE IF NOT EXISTS `menu_preferences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `scope_type` enum('role','user') NOT NULL,
  `scope_id` int(11) NOT NULL,
  `menu_config` longtext NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scope` (`scope_type`, `scope_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabella catalogo/dizionario dei permessi di sistema (idempotente con PK inline)
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

-- 3. Censimento formale nel catalogo permessi (idempotente via ON DUPLICATE KEY UPDATE)
INSERT INTO `permissions` (`name`, `label`, `description`, `module`) VALUES
  ('menu_customizer.php', 'Personalizza Menu', 'Accesso al modulo di personalizzazione ordine e visibilità menu', 'Amministrazione'),
  ('menu.customize', 'Personalizza Menu (Alias)', 'Alias standard RBAC per la personalizzazione del menu', 'Amministrazione'),
  ('can_customize_menu', 'Personalizza Menu (Capability)', 'Capability check per la modifica delle preferenze di navigazione', 'Amministrazione')
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `module`      = VALUES(`module`);

-- 4. Allineamento ruoli predefiniti in role_permissions (idempotente con tuple base)
INSERT IGNORE INTO `role_permissions` (`role_id`, `page_name`) VALUES
  (1, 'menu_customizer.php'),
  (2, 'menu_customizer.php');

-- Popola tutti gli altri ruoli esistenti in roles con accesso predefinito
INSERT IGNORE INTO `role_permissions` (`role_id`, `page_name`)
SELECT r.`id`, 'menu_customizer.php'
FROM `roles` r
WHERE r.`id` NOT IN (1, 2)
  AND NOT EXISTS (
    SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = r.`id` AND rp.`page_name` = 'menu_customizer.php'
  );

-- Se la colonna 'can_view' è presente in role_permissions, allinea i flag di permesso
SET @has_can_view := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'role_permissions' AND COLUMN_NAME = 'can_view'
);

SET @sql_grant_admin := IF(@has_can_view > 0,
  'UPDATE `role_permissions` SET `can_view`=1, `can_create`=1, `can_edit`=1, `can_delete`=1, `can_export`=1 WHERE `role_id`=1 AND `page_name`=''menu_customizer.php''',
  'DO 0');
PREPARE stmt_admin FROM @sql_grant_admin;
EXECUTE stmt_admin;
DEALLOCATE PREPARE stmt_admin;

SET @sql_grant_hr := IF(@has_can_view > 0,
  'UPDATE `role_permissions` SET `can_view`=1, `can_create`=1, `can_edit`=1, `can_delete`=0, `can_export`=0 WHERE `role_id`=2 AND `page_name`=''menu_customizer.php''',
  'DO 0');
PREPARE stmt_hr FROM @sql_grant_hr;
EXECUTE stmt_hr;
DEALLOCATE PREPARE stmt_hr;

-- 5. Allineamento versione schema applicativo
INSERT INTO `app_settings` (`setting_key`, `setting_value`, `description`) VALUES
  ('app_version',    '1.9.56', 'Versione applicazione'),
  ('schema_version', '1.9.56', 'Versione schema database'),
  ('release_label',  '1.9.56', 'Etichetta release mostrata in footer')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

SET FOREIGN_KEY_CHECKS = 1;
