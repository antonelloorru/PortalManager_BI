CREATE TABLE IF NOT EXISTS `departments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `value_type` ENUM('Servizio a Valore','Non a Valore') NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_department_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `department_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_id` INT UNSIGNED NULL,
  `action` ENUM('CREATE','UPDATE','DELETE') NOT NULL,
  `old_name` VARCHAR(150) NULL,
  `new_name` VARCHAR(150) NULL,
  `old_value_type` ENUM('Servizio a Valore','Non a Valore') NULL,
  `new_value_type` ENUM('Servizio a Valore','Non a Valore') NULL,
  `old_is_active` TINYINT(1) NULL,
  `new_is_active` TINYINT(1) NULL,
  `changed_by` INT UNSIGNED NULL,
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dh_department` (`department_id`),
  KEY `idx_dh_changed_at` (`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `department_id` INT UNSIGNED NULL AFTER `department`;
ALTER TABLE `employees` ADD CONSTRAINT `fk_employees_department` FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON UPDATE CASCADE ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS `idx_employees_department_id` ON `employees`(`department_id`);
INSERT IGNORE INTO `departments` (`name`,`value_type`) SELECT DISTINCT TRIM(`department`), 'Non a Valore' FROM `employees` WHERE `department` IS NOT NULL AND TRIM(`department`) <> '';
UPDATE `employees` e JOIN `departments` d ON d.`name` = TRIM(e.`department`) SET e.`department_id` = d.`id` WHERE e.`department_id` IS NULL;
INSERT IGNORE INTO `role_permissions` (`role_id`,`page_name`) VALUES (1,'manage_departments.php');
INSERT INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES ('app_version','1.7.58','Versione build'),('schema_version','1.7.58','Versione schema database'),('release_label','1.7.58','Etichetta release mostrata in footer') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
