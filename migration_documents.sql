-- ════════════════════════════════════════════════════════════════════════════
--  certV 2.4 — migration_documents.sql
--  Sistema documentale unificato candidati + dipendenti
--  Con controllo accesso basato su ruoli per tipo documento
-- ════════════════════════════════════════════════════════════════════════════

-- ══ 1. TABELLA DOCUMENTI UNIFICATA ══════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `person_documents` (
  `id`              INT NOT NULL AUTO_INCREMENT,

  -- Collegamento polimorfico: candidato OPPURE dipendente
  `candidate_id`    INT DEFAULT NULL           COMMENT 'FK → candidates.id (se allegato a candidato)',
  `employee_id`     INT DEFAULT NULL           COMMENT 'FK → employees.id (se allegato a dipendente)',

  -- Tipo documento
  `doc_type`        ENUM(
    'cv',
    'lettera_presentazione',
    'note_selezione',
    'test_tecnico',
    'test_psicologico',
    'valutazione',
    'contratto',
    'certificato_formazione',
    'documento_identita',
    'altro'
  ) NOT NULL DEFAULT 'altro'                   COMMENT 'Tipologia documento',

  -- File
  `file_name`       VARCHAR(255) NOT NULL      COMMENT 'Nome file su disco (univoco)',
  `original_name`   VARCHAR(255) NOT NULL      COMMENT 'Nome originale del file caricato',
  `file_size`       INT DEFAULT NULL           COMMENT 'Dimensione in byte',
  `mime_type`       VARCHAR(100) DEFAULT NULL   COMMENT 'MIME type (application/pdf, etc.)',

  -- Metadati
  `title`           VARCHAR(200) DEFAULT NULL  COMMENT 'Titolo/descrizione breve',
  `compilation_date` DATE DEFAULT NULL         COMMENT 'Data compilazione/redazione documento',
  `notes`           TEXT DEFAULT NULL          COMMENT 'Note interne sul documento',

  -- Versionamento (per documenti come contratti che hanno storico)
  `version`         INT NOT NULL DEFAULT 1     COMMENT 'Numero versione (1, 2, 3...)',
  `is_current`     TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=versione corrente, 0=archiviata',
  `signed_date`    DATE DEFAULT NULL           COMMENT 'Data firma documento (per contratti)',

  -- Accesso
  `visibility`      ENUM('all','restricted') NOT NULL DEFAULT 'restricted'
                    COMMENT 'all = visibile a tutti, restricted = solo ruoli autorizzati',
  `min_role_view`   TINYINT NOT NULL DEFAULT 2
                    COMMENT 'Ruolo minimo per visualizzare (1=Admin, 2=HR, 3=BM, 4=TL, 5=Recruiter, 6=Dipendente)',
  `min_role_download` TINYINT NOT NULL DEFAULT 2
                    COMMENT 'Ruolo minimo per scaricare',

  -- Audit
  `uploaded_by`     INT DEFAULT NULL           COMMENT 'FK → users.id — chi ha caricato',
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() COMMENT 'Data caricamento',
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),

  PRIMARY KEY (`id`),
  KEY `idx_pd_candidate` (`candidate_id`),
  KEY `idx_pd_employee` (`employee_id`),
  KEY `idx_pd_type` (`doc_type`),
  KEY `idx_pd_date` (`created_at`),
  CONSTRAINT `fk_pd_cand` FOREIGN KEY (`candidate_id`) REFERENCES `candidates`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pd_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Documenti unificati candidati/dipendenti con controllo accesso';

-- ══ 2. REGOLE ACCESSO PER TIPO DOCUMENTO ════════════════════════════════════

CREATE TABLE IF NOT EXISTS `document_access_rules` (
  `id`              INT NOT NULL AUTO_INCREMENT,
  `doc_type`        VARCHAR(50) NOT NULL       COMMENT 'Tipo documento (da person_documents.doc_type)',
  `role_id`         INT NOT NULL               COMMENT 'FK → roles.id',
  `can_view`        TINYINT(1) NOT NULL DEFAULT 0,
  `can_download`    TINYINT(1) NOT NULL DEFAULT 0,
  `can_upload`      TINYINT(1) NOT NULL DEFAULT 0,
  `can_delete`      TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dar_type_role` (`doc_type`, `role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Matrice accesso documenti per ruolo';

-- ══ 3. REGOLE DEFAULT ═══════════════════════════════════════════════════════
-- Super Admin (1): accesso totale a tutto
-- HR Director (2): accesso totale a tutto
-- Brand Manager (3): CV, test tecnico, certificati formazione
-- Team Leader (4): CV, test tecnico
-- Recruiter (5): CV, lettera, note selezione, test
-- Dipendente (6): solo i propri CV e certificati formazione

INSERT IGNORE INTO `document_access_rules` (`doc_type`,`role_id`,`can_view`,`can_download`,`can_upload`,`can_delete`) VALUES
-- Super Admin (1) — tutto
('cv',1,1,1,1,1),('lettera_presentazione',1,1,1,1,1),('note_selezione',1,1,1,1,1),
('test_tecnico',1,1,1,1,1),('test_psicologico',1,1,1,1,1),('valutazione',1,1,1,1,1),
('contratto',1,1,1,1,1),('certificato_formazione',1,1,1,1,1),('documento_identita',1,1,1,1,1),('altro',1,1,1,1,1),
-- HR Director (2) — tutto
('cv',2,1,1,1,1),('lettera_presentazione',2,1,1,1,1),('note_selezione',2,1,1,1,1),
('test_tecnico',2,1,1,1,1),('test_psicologico',2,1,1,1,1),('valutazione',2,1,1,1,1),
('contratto',2,1,1,1,1),('certificato_formazione',2,1,1,1,1),('documento_identita',2,1,1,1,1),('altro',2,1,1,1,1),
-- Brand Manager (3) — CV, test tecnico, cert formazione, valutazione
('cv',3,1,1,0,0),('test_tecnico',3,1,1,0,0),('certificato_formazione',3,1,1,0,0),('valutazione',3,1,0,0,0),
-- Team Leader (4) — CV, test tecnico, cert formazione
('cv',4,1,1,0,0),('test_tecnico',4,1,1,0,0),('certificato_formazione',4,1,1,0,0),
-- Recruiter (5) — CV, lettera, note selezione, test
('cv',5,1,1,1,0),('lettera_presentazione',5,1,1,1,0),('note_selezione',5,1,1,1,0),
('test_tecnico',5,1,1,1,0),('test_psicologico',5,1,1,1,0),
-- Dipendente (6) — solo propri CV e cert formazione (il check "proprio" è nel PHP)
('cv',6,1,1,0,0),('certificato_formazione',6,1,1,0,0);

-- ══ 4. PERMESSI PAGINA ═════════════════════════════════════════════════════

INSERT IGNORE INTO `role_permissions` (`role_id`,`page_name`) VALUES
  (1,'documenti.php'),(2,'documenti.php'),(3,'documenti.php'),
  (4,'documenti.php'),(5,'documenti.php'),(6,'documenti.php');

-- ══ VERIFICA ════════════════════════════════════════════════════════════════
SHOW TABLES LIKE 'person_documents';
SHOW TABLES LIKE 'document_access_rules';
