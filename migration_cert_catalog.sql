-- ════════════════════════════════════════════════════════════════════════════
--  certV 2.4 — migration_cert_catalog.sql
--  Storicizzazione catalogo certificazioni + ricerca smart
-- ════════════════════════════════════════════════════════════════════════════

-- ── 1. Storicizzazione: tabella versioni certificazioni ─────────────────────
CREATE TABLE IF NOT EXISTS `certification_versions` (
  `id`               INT NOT NULL AUTO_INCREMENT,
  `certification_id` INT NOT NULL COMMENT 'FK → certifications.id',
  `version`          INT NOT NULL DEFAULT 1,
  `field_changed`    VARCHAR(50) NOT NULL COMMENT 'Nome campo modificato',
  `old_value`        TEXT DEFAULT NULL,
  `new_value`        TEXT DEFAULT NULL,
  `changed_by`       INT DEFAULT NULL COMMENT 'FK → users.id',
  `changed_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_cv_cert` (`certification_id`),
  KEY `idx_cv_date` (`changed_at`),
  CONSTRAINT `fk_cv_cert` FOREIGN KEY (`certification_id`) REFERENCES `certifications`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Storico modifiche catalogo certificazioni';

-- ── 2. Nuovi campi su certifications per gestione scadenze ──────────────────
-- validity_months esiste già, aggiungiamo dettagli
ALTER TABLE `certifications`
  ADD COLUMN `renewal_policy` VARCHAR(200) DEFAULT NULL COMMENT 'Policy rinnovo (es. riesame ogni 2 anni)',
  ADD COLUMN `exam_cost`      DECIMAL(8,2) DEFAULT NULL COMMENT 'Costo esame in EUR',
  ADD COLUMN `updated_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  ADD COLUMN `updated_by`     INT DEFAULT NULL COMMENT 'FK → users.id';

-- ── 3. Indice fulltext per ricerca smart ────────────────────────────────────
ALTER TABLE `certifications` ADD FULLTEXT INDEX `ft_cert_search` (`name`, `code`, `description`);

-- Permessi pagina catalogo certificazioni
INSERT IGNORE INTO role_permissions (role_id, page_name) VALUES
  (1,'catalogo_certificazioni.php'),(2,'catalogo_certificazioni.php'),(3,'catalogo_certificazioni.php'),
  (4,'catalogo_certificazioni.php'),(5,'catalogo_certificazioni.php');

