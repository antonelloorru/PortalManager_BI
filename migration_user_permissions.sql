-- certV 4.0 — migration_user_permissions.sql

CREATE TABLE IF NOT EXISTS `user_permissions` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `user_id`    INT NOT NULL,
  `page_name`  VARCHAR(100) NOT NULL,
  `can_view`   TINYINT(1) DEFAULT NULL COMMENT 'NULL=eredita da ruolo, 0=negato, 1=concesso',
  `can_create` TINYINT(1) DEFAULT NULL,
  `can_edit`   TINYINT(1) DEFAULT NULL,
  `can_delete` TINYINT(1) DEFAULT NULL,
  `can_export` TINYINT(1) DEFAULT NULL,
  `updated_by` INT DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_up_user_page` (`user_id`,`page_name`),
  CONSTRAINT `fk_up_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `role_permissions`
  ADD COLUMN IF NOT EXISTS `can_view`   TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `can_create` TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `can_edit`   TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `can_delete` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `can_export` TINYINT(1) NOT NULL DEFAULT 1;
