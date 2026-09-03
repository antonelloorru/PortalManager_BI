-- ============================================================
--  certV — Migration: tracciamento pubblicazioni posizioni
--  Eseguire in phpMyAdmin → cert_management → SQL
-- ============================================================

CREATE TABLE IF NOT EXISTS `position_publications` (
  `id`          int(11) NOT NULL AUTO_INCREMENT,
  `position_id` int(11) NOT NULL COMMENT 'FK → job_positions.id',
  `channel`     enum('linkedin','indeed','infojobs','glassdoor','monster','jobrapido','custom') NOT NULL DEFAULT 'linkedin',
  `channel_url` varchar(500) DEFAULT NULL COMMENT 'URL del post/annuncio pubblicato',
  `status`      enum('draft','published','expired','removed') NOT NULL DEFAULT 'draft',
  `published_at` datetime DEFAULT NULL,
  `expires_at`  date DEFAULT NULL,
  `published_by` int(11) DEFAULT NULL COMMENT 'FK → users.id',
  `api_post_id` varchar(255) DEFAULT NULL COMMENT 'ID restituito dall API (LinkedIn jobPostingId ecc.)',
  `notes`       text DEFAULT NULL,
  `created_at`  timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `position_id` (`position_id`),
  KEY `channel` (`channel`),
  KEY `status` (`status`),
  CONSTRAINT `pp_fk1` FOREIGN KEY (`position_id`) REFERENCES `job_positions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pp_fk2` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Traccia le pubblicazioni delle posizioni sui portali esterni';

-- Impostazioni LinkedIn / API
INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `description`) VALUES
('linkedin_client_id',     '',    'LinkedIn App Client ID (da developer.linkedin.com)'),
('linkedin_client_secret', '',    'LinkedIn App Client Secret'),
('linkedin_company_id',    '',    'LinkedIn Company ID (numerico, da URL pagina azienda)'),
('linkedin_access_token',  '',    'OAuth2 Access Token (generato via publish_posizione.php)'),
('company_website',        '',    'URL sito web azienda (usato nei post)'),
('company_apply_url',      '',    'URL candidatura esterna (es. https://careers.azienda.it)');

-- Permessi per la nuova pagina
INSERT IGNORE INTO `role_permissions` (`role_id`, `page_name`) VALUES
(1, 'publish_posizione.php'),
(2, 'publish_posizione.php'),
(5, 'publish_posizione.php');

-- Verifica (v2.4: senza information_schema)
SHOW TABLES LIKE 'position_publications';
