-- ============================================================
--  certV — Migration candidates: nuove colonne per profilo completo
--  Eseguire in phpMyAdmin → cert_management → SQL
--  Sicuro da eseguire più volte (IF NOT EXISTS)
-- ============================================================

-- Dati di istruzione
ALTER TABLE `candidates`
  ADD COLUMN IF NOT EXISTS `education_level`     VARCHAR(80)  DEFAULT NULL COMMENT 'Titolo di studio',
  ADD COLUMN IF NOT EXISTS `education_field`     VARCHAR(150) DEFAULT NULL COMMENT 'Indirizzo / facoltà',
  ADD COLUMN IF NOT EXISTS `education_institute` VARCHAR(200) DEFAULT NULL COMMENT 'Istituto / Università',
  ADD COLUMN IF NOT EXISTS `education_year`      VARCHAR(10)  DEFAULT NULL COMMENT 'Anno conseguimento titolo';

-- Certificazioni esterne dichiarate (JSON array)
ALTER TABLE `candidates`
  ADD COLUMN IF NOT EXISTS `external_certs`      TEXT         DEFAULT NULL COMMENT 'JSON: certificazioni dichiarate dal candidato';

-- Documenti
ALTER TABLE `candidates`
  ADD COLUMN IF NOT EXISTS `test_path`           VARCHAR(255) DEFAULT NULL COMMENT 'Test psicologico',
  ADD COLUMN IF NOT EXISTS `lettera_path`        VARCHAR(255) DEFAULT NULL COMMENT 'Lettera di presentazione',
  ADD COLUMN IF NOT EXISTS `doc_extra_path`      VARCHAR(255) DEFAULT NULL COMMENT 'Documento aggiuntivo';

-- Note soft skills e valutazione carattere
ALTER TABLE `candidates`
  ADD COLUMN IF NOT EXISTS `soft_skills_notes`   TEXT         DEFAULT NULL COMMENT 'Note su soft skills / carattere';

-- Permesso di accesso alla nuova pagina per ruoli 3,4,5
INSERT IGNORE INTO `role_permissions` (role_id, page_name) VALUES
  (2, 'candidato_profilo.php'),
  (3, 'candidato_profilo.php'),
  (4, 'candidato_profilo.php'),
  (5, 'candidato_profilo.php');

-- Verifica (v2.4: senza information_schema)
SHOW COLUMNS FROM `candidates` WHERE `Field` IN (
  'education_level','education_field','education_institute',
  'education_year','external_certs','test_path',
  'lettera_path','doc_extra_path','soft_skills_notes'
);
