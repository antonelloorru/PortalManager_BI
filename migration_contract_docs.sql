-- ════════════════════════════════════════════════════════════════════════════
--  certV 2.4 — migration_contract_docs.sql
--  Storico documenti contrattuali con versionamento
-- ════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `contract_documents` (
  `id`              INT NOT NULL AUTO_INCREMENT,
  `contract_id`     INT NOT NULL               COMMENT 'FK → agency_contracts.id',
  `version`         INT NOT NULL DEFAULT 1     COMMENT 'Numero versione (1, 2, 3...)',
  `status`          ENUM('current','archived','superseded') NOT NULL DEFAULT 'current'
                    COMMENT 'current = versione attiva, archived = storicizzata',

  -- File
  `file_name`       VARCHAR(255) NOT NULL      COMMENT 'Nome file su disco',
  `original_name`   VARCHAR(255) NOT NULL      COMMENT 'Nome file originale',
  `file_size`       INT DEFAULT NULL           COMMENT 'Dimensione in byte',
  `mime_type`       VARCHAR(100) DEFAULT NULL,

  -- Metadati
  `title`           VARCHAR(200) DEFAULT NULL  COMMENT 'Descrizione versione (es. Rinnovo 2025)',
  `signed_date`     DATE DEFAULT NULL          COMMENT 'Data firma del contratto',
  `notes`           TEXT DEFAULT NULL          COMMENT 'Note sulla versione',

  -- Audit
  `uploaded_by`     INT DEFAULT NULL           COMMENT 'FK → users.id',
  `archived_at`     DATETIME DEFAULT NULL      COMMENT 'Data archiviazione (quando sostituito)',
  `archived_by`     INT DEFAULT NULL           COMMENT 'FK → users.id — chi ha archiviato',
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),

  PRIMARY KEY (`id`),
  KEY `idx_cd_contract` (`contract_id`),
  KEY `idx_cd_status` (`status`),
  KEY `idx_cd_version` (`contract_id`, `version`),
  CONSTRAINT `fk_cd_contract` FOREIGN KEY (`contract_id`) REFERENCES `agency_contracts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Storico versioni documenti contrattuali firmati';
