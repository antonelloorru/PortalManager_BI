-- migration_v1_9_85.sql — Import candidati LinkedIn: ID offerta in forma canonica (solo cifre).
-- Corregge i codici salvati con notazione scientifica o suffisso E9 (es. 4.412730757E9, 4427193793E9),
-- con URL dell'annuncio o con .0 finale, su job_positions e candidates, poi ricollega alle posizioni
-- i candidati LinkedIn rimasti senza candidatura per il mancato match.
-- Idempotente: al secondo passaggio nessuna riga soddisfa i criteri.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `job_positions` ADD COLUMN IF NOT EXISTS `linkedin_code` VARCHAR(100) DEFAULT NULL;
ALTER TABLE `candidates` ADD COLUMN IF NOT EXISTS `li_job_id` VARCHAR(60) DEFAULT NULL;
CREATE INDEX IF NOT EXISTS `idx_jp_linkedin_code` ON `job_positions` (`linkedin_code`);
CREATE INDEX IF NOT EXISTS `idx_cand_li_job_id` ON `candidates` (`li_job_id`);

-- 1) job_positions.linkedin_code
UPDATE `job_positions` SET `linkedin_code` = TRIM(`linkedin_code`)
 WHERE `linkedin_code` IS NOT NULL AND `linkedin_code` <> TRIM(`linkedin_code`);
UPDATE `job_positions` SET `linkedin_code` = REGEXP_REPLACE(`linkedin_code`, '^.*/jobs/view/([^/?#]*-)?([0-9]{5,}).*$', '\\2')
 WHERE `linkedin_code` REGEXP '/jobs/view/([^/?#]*-)?[0-9]{5,}';
UPDATE `job_positions` SET `linkedin_code` = SUBSTRING_INDEX(UPPER(`linkedin_code`), 'E', 1)
 WHERE `linkedin_code` REGEXP '^[0-9]+[eE][+]?[0-9]+$'
   AND CHAR_LENGTH(SUBSTRING_INDEX(UPPER(`linkedin_code`), 'E', 1)) = CAST(REPLACE(SUBSTRING_INDEX(UPPER(`linkedin_code`), 'E', -1), '+', '') AS UNSIGNED) + 1
   AND CAST(REPLACE(SUBSTRING_INDEX(UPPER(`linkedin_code`), 'E', -1), '+', '') AS UNSIGNED) >= 6;
UPDATE `job_positions` SET `linkedin_code` = CAST(CAST(`linkedin_code` AS DOUBLE) AS DECIMAL(30,0))
 WHERE `linkedin_code` REGEXP '^[0-9]+[.][0-9]+[eE][+]?[0-9]+$'
   AND CAST(`linkedin_code` AS DOUBLE) = FLOOR(CAST(`linkedin_code` AS DOUBLE));
UPDATE `job_positions` SET `linkedin_code` = REGEXP_REPLACE(`linkedin_code`, '[.]0+$', '')
 WHERE `linkedin_code` REGEXP '^[0-9]+[.]0+$';

-- 2) candidates.li_job_id
UPDATE `candidates` SET `li_job_id` = TRIM(`li_job_id`)
 WHERE `li_job_id` IS NOT NULL AND `li_job_id` <> TRIM(`li_job_id`);
UPDATE `candidates` SET `li_job_id` = SUBSTRING_INDEX(UPPER(`li_job_id`), 'E', 1)
 WHERE `li_job_id` REGEXP '^[0-9]+[eE][+]?[0-9]+$'
   AND CHAR_LENGTH(SUBSTRING_INDEX(UPPER(`li_job_id`), 'E', 1)) = CAST(REPLACE(SUBSTRING_INDEX(UPPER(`li_job_id`), 'E', -1), '+', '') AS UNSIGNED) + 1
   AND CAST(REPLACE(SUBSTRING_INDEX(UPPER(`li_job_id`), 'E', -1), '+', '') AS UNSIGNED) >= 6;
UPDATE `candidates` SET `li_job_id` = CAST(CAST(`li_job_id` AS DOUBLE) AS DECIMAL(30,0))
 WHERE `li_job_id` REGEXP '^[0-9]+[.][0-9]+[eE][+]?[0-9]+$'
   AND CAST(`li_job_id` AS DOUBLE) = FLOOR(CAST(`li_job_id` AS DOUBLE));
UPDATE `candidates` SET `li_job_id` = REGEXP_REPLACE(`li_job_id`, '[.]0+$', '')
 WHERE `li_job_id` REGEXP '^[0-9]+[.]0+$';

-- 3) candidates: ID progetto di assunzione e ID contratto (stessa alterazione dal file LinkedIn)
ALTER TABLE `candidates` ADD COLUMN IF NOT EXISTS `hiring_project_id` VARCHAR(80) DEFAULT NULL;
ALTER TABLE `candidates` ADD COLUMN IF NOT EXISTS `li_contract_id` VARCHAR(80) DEFAULT NULL;
UPDATE `candidates` SET `hiring_project_id` = CAST(CAST(`hiring_project_id` AS DOUBLE) AS DECIMAL(30,0))
 WHERE `hiring_project_id` REGEXP '^[0-9]+[.][0-9]+[eE][+]?[0-9]+$'
   AND CAST(`hiring_project_id` AS DOUBLE) = FLOOR(CAST(`hiring_project_id` AS DOUBLE));
UPDATE `candidates` SET `li_contract_id` = CAST(CAST(`li_contract_id` AS DOUBLE) AS DECIMAL(30,0))
 WHERE `li_contract_id` REGEXP '^[0-9]+[.][0-9]+[eE][+]?[0-9]+$'
   AND CAST(`li_contract_id` AS DOUBLE) = FLOOR(CAST(`li_contract_id` AS DOUBLE));

-- 4) ricollegamento: candidati LinkedIn con ID offerta valido e nessuna candidatura
--    posizione scelta: stesso codice, prima le aperte, poi la piu' recente
SET @pm_v1985_t := NOW() - INTERVAL 1 SECOND;
INSERT IGNORE INTO `candidate_applications` (`candidate_id`, `position_id`, `stage`)
SELECT c.`id`,
       (SELECT p.`id` FROM `job_positions` p
         WHERE p.`linkedin_code` = c.`li_job_id`
         ORDER BY (p.`status` = 'open') DESC, p.`id` DESC LIMIT 1),
       'cv_received'
  FROM `candidates` c
 WHERE c.`source` = 'LinkedIn'
   AND c.`li_job_id` REGEXP '^[0-9]{5,}$'
   AND EXISTS (SELECT 1 FROM `job_positions` p2 WHERE p2.`linkedin_code` = c.`li_job_id`)
   AND NOT EXISTS (SELECT 1 FROM `candidate_applications` a WHERE a.`candidate_id` = c.`id`);

UPDATE `candidates` c SET c.`status` = 'in_pipeline'
 WHERE c.`source` = 'LinkedIn' AND c.`status` = 'new'
   AND c.`li_job_id` REGEXP '^[0-9]{5,}$'
   AND EXISTS (SELECT 1 FROM `candidate_applications` a JOIN `job_positions` p ON p.`id` = a.`position_id`
                WHERE a.`candidate_id` = c.`id` AND p.`linkedin_code` = c.`li_job_id`
                  AND a.`created_at` >= @pm_v1985_t);

UPDATE `app_settings` SET `setting_value`='1.9.85'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.9.85','migration_v1_9_85.sql');
