-- ════════════════════════════════════════════════════════════════════════════
--  certV 2.4 — migration_plan_types.sql
--  Tipologia percorso su planned_exams e training_plans
-- ════════════════════════════════════════════════════════════════════════════

-- Nuova colonna plan_type su planned_exams
ALTER TABLE `planned_exams`
  ADD COLUMN `plan_type` ENUM(
    'formazione',
    'esame_certificazione',
    'rinnovo',
    'workshop_tecnico',
    'workshop_commerciale',
    'convegno'
  ) NOT NULL DEFAULT 'esame_certificazione' COMMENT 'Tipologia percorso',
  MODIFY COLUMN `certification_id` INT DEFAULT NULL COMMENT 'FK opzionale per workshop/convegni';

-- Nuova colonna plan_type su training_plans
ALTER TABLE `training_plans`
  ADD COLUMN `plan_type` ENUM(
    'formazione',
    'esame_certificazione',
    'rinnovo',
    'workshop_tecnico',
    'workshop_commerciale',
    'convegno'
  ) NOT NULL DEFAULT 'formazione' COMMENT 'Tipologia percorso';

-- Migrazione dati esistenti: is_renewal → plan_type 'rinnovo'
UPDATE `training_plans` SET plan_type='rinnovo' WHERE is_renewal=1;
UPDATE `planned_exams` SET plan_type='esame_certificazione' WHERE plan_type='esame_certificazione';
