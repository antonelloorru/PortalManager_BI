-- ============================================================================
-- PortalManager 1.9.56 — migration_menu_permissions.sql
-- Allineamento Schema RBAC, Tabella menu_preferences e Dizionario Permessi
-- ============================================================================

-- 1. Tabella preferenze menu (se non ancora presente)
CREATE TABLE IF NOT EXISTS `menu_preferences` (
  `id`          INT NOT NULL AUTO_INCREMENT,
  `scope_type`  ENUM('role','user') NOT NULL,
  `scope_id`    INT NOT NULL,
  `menu_config` LONGTEXT NOT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scope` (`scope_type`, `scope_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabella catalogo/dizionario dei permessi di sistema
CREATE TABLE IF NOT EXISTS `permissions` (
  `id`          INT NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100) NOT NULL,
  `label`       VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `module`      VARCHAR(100) NOT NULL DEFAULT 'General',
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_perm_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Censimento formale della voce "Personalizza Menu" nel catalogo permessi
INSERT INTO `permissions` (`name`, `label`, `description`, `module`) VALUES
  ('menu_customizer.php', 'Personalizza Menu', 'Accesso al modulo di personalizzazione ordine e visibilità menu', 'Amministrazione'),
  ('menu.customize', 'Personalizza Menu (Alias)', 'Alias standard RBAC per la personalizzazione del menu', 'Amministrazione'),
  ('can_customize_menu', 'Personalizza Menu (Capability)', 'Capability check per la modifica delle preferenze di navigazione', 'Amministrazione')
ON DUPLICATE KEY UPDATE
  `label` = VALUES(`label`),
  `description` = VALUES(`description`),
  `module` = VALUES(`module`);

-- 4. Integrità Referenziale su role_permissions
-- Inserimento sicuro per i ruoli di sistema esistenti
INSERT IGNORE INTO `role_permissions` (`role_id`, `page_name`, `can_view`, `can_create`, `can_edit`, `can_delete`, `can_export`) VALUES
  (1, 'menu_customizer.php', 1, 1, 1, 1, 1),
  (2, 'menu_customizer.php', 1, 1, 1, 0, 0);

-- Garantire che ogni ruolo definito in `roles` abbia una tupla in `role_permissions` per menu_customizer.php
INSERT IGNORE INTO `role_permissions` (`role_id`, `page_name`, `can_view`, `can_create`, `can_edit`, `can_delete`, `can_export`)
SELECT r.id, 'menu_customizer.php', 0, 0, 0, 0, 0
FROM `roles` r
WHERE r.id NOT IN (1, 2)
  AND NOT EXISTS (
    SELECT 1 FROM `role_permissions` rp WHERE rp.role_id = r.id AND rp.page_name = 'menu_customizer.php'
  );
