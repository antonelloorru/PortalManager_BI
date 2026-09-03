-- ════════════════════════════════════════════════════════════════════════════
--  certV 2.4 — migration_integrations.sql
--  1) Modulo Segreteria e Logistica (prenotazioni alloggi, mezzi, attrezzature)
--  2) Notifiche esami e rinnovi (impostazioni aggiuntive)
--  3) Allineamento anagrafiche referenti (colonne sync)
--  Sicuro da eseguire più volte (IF NOT EXISTS / INSERT IGNORE)
-- ════════════════════════════════════════════════════════════════════════════

-- ══ 1. MODULO SEGRETERIA — RICHIESTE LOGISTICHE ═════════════════════════════

CREATE TABLE IF NOT EXISTS `logistics_requests` (
  `id`                INT NOT NULL AUTO_INCREMENT,
  `employee_id`       INT NOT NULL                 COMMENT 'FK → employees.id — chi ha bisogno del servizio',
  `planned_exam_id`   INT DEFAULT NULL             COMMENT 'FK → planned_exams.id — esame collegato (opzionale)',
  `certification_id`  INT DEFAULT NULL             COMMENT 'FK → certifications.id — certificazione collegata',
  `brand_id`          INT DEFAULT NULL             COMMENT 'FK → brands.id — brand di riferimento',

  `request_type`      ENUM('alloggio','mezzo','attrezzatura','aula','catering','altro') NOT NULL DEFAULT 'alloggio'
                      COMMENT 'Tipo richiesta logistica',
  `status`            ENUM('draft','submitted','approved','booked','completed','cancelled') NOT NULL DEFAULT 'draft'
                      COMMENT 'Workflow: bozza → inviata → approvata → prenotata → completata',

  `title`             VARCHAR(200) NOT NULL        COMMENT 'Titolo breve richiesta',
  `description`       TEXT DEFAULT NULL             COMMENT 'Dettagli richiesta',

  -- Date
  `date_from`         DATE NOT NULL                COMMENT 'Data inizio servizio',
  `date_to`           DATE DEFAULT NULL            COMMENT 'Data fine (NULL = singolo giorno)',
  `time_from`         TIME DEFAULT NULL            COMMENT 'Ora inizio',
  `time_to`           TIME DEFAULT NULL            COMMENT 'Ora fine',

  -- Dettagli logistici
  `location`          VARCHAR(300) DEFAULT NULL    COMMENT 'Luogo/indirizzo destinazione',
  `city`              VARCHAR(100) DEFAULT NULL,
  `num_people`        TINYINT DEFAULT 1            COMMENT 'Numero persone coinvolte',
  `budget_estimated`  DECIMAL(10,2) DEFAULT NULL   COMMENT 'Budget stimato',
  `budget_actual`     DECIMAL(10,2) DEFAULT NULL   COMMENT 'Costo effettivo',
  `supplier`          VARCHAR(200) DEFAULT NULL    COMMENT 'Fornitore/hotel/noleggio',
  `booking_ref`       VARCHAR(100) DEFAULT NULL    COMMENT 'Codice prenotazione/conferma',

  -- Workflow
  `requested_by`      INT DEFAULT NULL             COMMENT 'FK → users.id — chi ha creato la richiesta',
  `approved_by`       INT DEFAULT NULL             COMMENT 'FK → users.id — chi ha approvato',
  `approved_at`       DATETIME DEFAULT NULL,
  `notes_internal`    TEXT DEFAULT NULL             COMMENT 'Note interne segreteria',

  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_lr_employee` (`employee_id`),
  KEY `idx_lr_exam` (`planned_exam_id`),
  KEY `idx_lr_status` (`status`),
  KEY `idx_lr_date` (`date_from`),
  KEY `idx_lr_type` (`request_type`),
  CONSTRAINT `fk_lr_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lr_exam` FOREIGN KEY (`planned_exam_id`) REFERENCES `planned_exams`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_lr_cert` FOREIGN KEY (`certification_id`) REFERENCES `certifications`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_lr_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Richieste logistiche segreteria — workflow collegato a certificazioni';

-- ══ 2. IMPOSTAZIONI NOTIFICHE ESAMI E RINNOVI ══════════════════════════════

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `description`) VALUES
  ('notify_exam_days_1',  '7',  'Primo avviso esame pianificato (giorni prima)'),
  ('notify_exam_days_2',  '1',  'Promemoria esame (giorno prima)'),
  ('notify_renewal_days', '30', 'Avviso finestra di rinnovo certificazione (giorni dopo scadenza)');

-- ══ 3. COLONNA SYNC SU BRAND_REFERENTS ══════════════════════════════════════

ALTER TABLE `brand_referents`
  ADD COLUMN IF NOT EXISTS `synced_at` TIMESTAMP NULL DEFAULT NULL
    COMMENT 'Data ultima sincronizzazione con anagrafica dipendente';

-- ══ 4. COLONNE CONTATTO SU PLANNED_EXAMS (per logistica) ════════════════════

ALTER TABLE `planned_exams`
  ADD COLUMN IF NOT EXISTS `exam_location`  VARCHAR(300) DEFAULT NULL COMMENT 'Sede esame',
  ADD COLUMN IF NOT EXISTS `exam_center`    VARCHAR(200) DEFAULT NULL COMMENT 'Centro esami / testing center',
  ADD COLUMN IF NOT EXISTS `booking_code`   VARCHAR(100) DEFAULT NULL COMMENT 'Codice prenotazione esame',
  ADD COLUMN IF NOT EXISTS `needs_logistics` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Richiede supporto logistico';

-- ══ 5. PERMESSI NUOVE PAGINE ════════════════════════════════════════════════

INSERT IGNORE INTO `role_permissions` (`role_id`, `page_name`) VALUES
  (2, 'segreteria.php'),
  (3, 'segreteria.php'),
  (4, 'segreteria.php'),
  (6, 'segreteria.php');

-- ══ VERIFICA ════════════════════════════════════════════════════════════════

SHOW TABLES LIKE 'logistics_requests';
