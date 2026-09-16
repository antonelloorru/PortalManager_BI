-- ════════════════════════════════════════════════════════════════
--  certV — migration_v2.4_smtp_brands.sql
--  1) Configurazione SMTP in app_settings (indipendente dal SO)
--  2) Classificazione brand (priorità 1-5, colore, tecnologie)
--  Sicuro da eseguire più volte (IF NOT EXISTS / INSERT IGNORE)
-- ════════════════════════════════════════════════════════════════

-- ══ 1. IMPOSTAZIONI SMTP ═════════════════════════════════════

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `description`) VALUES
  ('smtp_enabled',     '0',             'Abilita invio email via SMTP (1=si, 0=no/fallback mail())'),
  ('smtp_host',        '',              'Server SMTP (es. smtp.gmail.com, smtp.office365.com)'),
  ('smtp_port',        '587',           'Porta SMTP (25=plain, 465=SSL, 587=STARTTLS)'),
  ('smtp_encryption',  'tls',           'Crittografia: tls (STARTTLS), ssl (implicita), none'),
  ('smtp_user',        '',              'Username SMTP (solitamente l''email completa)'),
  ('smtp_pass',        '',              'Password SMTP o App Password'),
  ('smtp_auth',        '1',             'Richiede autenticazione (1=si, 0=no)'),
  ('smtp_timeout',     '15',            'Timeout connessione in secondi'),
  ('smtp_debug',       '0',             'Log debug SMTP nel log di sistema (1=si)'),
  ('smtp_test_email',  '',              'Indirizzo per il test di invio'),
  ('smtp_verified',    '0',             'Ultimo test SMTP riuscito (timestamp o 0)');

-- ══ 2. CLASSIFICAZIONE BRAND ═════════════════════════════════

-- Priorità 1-5 e colore codificato
ALTER TABLE `brands`
  ADD COLUMN IF NOT EXISTS `priority`       TINYINT(1)  NOT NULL DEFAULT 3
      COMMENT 'Priorità/importanza 1 (max) - 5 (min)',
  ADD COLUMN IF NOT EXISTS `priority_color`  VARCHAR(7)  NOT NULL DEFAULT '#3b82f6'
      COMMENT 'Colore HEX per codifica visiva priorità';

-- Indice per ordinamento rapido (v2.4: senza information_schema)
-- IF NOT EXISTS supportato da MariaDB 10.1+
ALTER TABLE `brands` ADD INDEX IF NOT EXISTS `idx_brands_priority` (`priority`);

-- ══ 3. TABELLA TECNOLOGIE/SERVIZI/PRODOTTI PER BRAND ═════════

CREATE TABLE IF NOT EXISTS `brand_technologies` (
  `id`          INT NOT NULL AUTO_INCREMENT,
  `brand_id`    INT NOT NULL               COMMENT 'FK → brands.id',
  `category`    ENUM('Tecnologia','Servizio','Prodotto') NOT NULL DEFAULT 'Tecnologia'
                COMMENT 'Tipo: Tecnologia, Servizio o Prodotto',
  `name`        VARCHAR(150) NOT NULL      COMMENT 'Nome tecnologia/servizio/prodotto',
  `description` TEXT DEFAULT NULL           COMMENT 'Descrizione dettagliata',
  `version`     VARCHAR(50) DEFAULT NULL    COMMENT 'Versione corrente',
  `status`      ENUM('active','deprecated','eol') NOT NULL DEFAULT 'active'
                COMMENT 'Stato: attiva, deprecata, end-of-life',
  `doc_url`     VARCHAR(500) DEFAULT NULL   COMMENT 'Link documentazione ufficiale',
  `relevance`   TINYINT(1) NOT NULL DEFAULT 3
                COMMENT 'Rilevanza per l''azienda 1 (alta) - 5 (bassa)',
  `notes`       TEXT DEFAULT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_bt_brand` (`brand_id`),
  KEY `idx_bt_category` (`category`),
  CONSTRAINT `fk_bt_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tecnologie, servizi e prodotti associati a ciascun brand';

-- ══ 4. MAPPA COLORI DEFAULT PER PRIORITÀ ═════════════════════
--    (usata dal frontend come riferimento)

-- Aggiorna i brand esistenti con priorità default 3 (se non impostata)
UPDATE `brands` SET `priority` = 3 WHERE `priority` = 0 OR `priority` IS NULL;
UPDATE `brands` SET `priority_color` = CASE `priority`
  WHEN 1 THEN '#dc2626'
  WHEN 2 THEN '#f59e0b'
  WHEN 3 THEN '#3b82f6'
  WHEN 4 THEN '#8b5cf6'
  WHEN 5 THEN '#64748b'
  ELSE '#3b82f6'
END WHERE `priority_color` = '#3b82f6' OR `priority_color` = '' OR `priority_color` IS NULL;

-- ══ 5. PERMESSI NUOVA PAGINA ═════════════════════════════════

INSERT IGNORE INTO `role_permissions` (`role_id`, `page_name`) VALUES
  (1, 'smtp_settings.php'),
  (2, 'brand_technologies.php'),
  (3, 'brand_technologies.php');

-- ══ 6. LOG NOTIFICHE EMAIL (tracciabilità invii) ═════════════

CREATE TABLE IF NOT EXISTS `email_log` (
  `id`          INT NOT NULL AUTO_INCREMENT,
  `recipient`   VARCHAR(255) NOT NULL,
  `subject`     VARCHAR(500) NOT NULL,
  `status`      ENUM('sent','failed','queued') NOT NULL DEFAULT 'sent',
  `error_msg`   TEXT DEFAULT NULL,
  `smtp_response` TEXT DEFAULT NULL,
  `module`      VARCHAR(50) DEFAULT 'system',
  `related_id`  INT DEFAULT NULL            COMMENT 'ID record collegato (opzionale)',
  `sent_by`     INT DEFAULT NULL            COMMENT 'FK → users.id (NULL = cron/system)',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_email_log_status` (`status`),
  KEY `idx_email_log_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Log di tutti gli invii email SMTP';

-- ══ VERIFICA ═════════════════════════════════════════════════

SELECT 'migration_v2.4_smtp_brands.sql completata' AS result;

-- Verifica (v2.4: senza information_schema)
SHOW COLUMNS FROM `brands` WHERE `Field` IN ('priority','priority_color');
