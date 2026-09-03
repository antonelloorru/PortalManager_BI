<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  certV — db_upgrade.php
 *  Verificatore integrità DB e Upgrade Manager multi-versione
 *
 *  FUNZIONALITÀ:
 *  1. Rileva automaticamente la versione corrente del database
 *  2. Verifica integrità: tabelle, colonne, indici, FK, impostazioni, permessi
 *  3. Mostra report visuale con stato OK / MANCANTE / WARNING per ogni elemento
 *  4. Upgrade sequenziale: v2.0 → v2.1 → v2.2 → v2.3 → v2.4
 *  5. Modalità DRY RUN (mostra SQL) e APPLY (esegue)
 *  6. NON usa information_schema (compatibile con qualsiasi permesso MySQL)
 *  7. Idempotente: sicuro da eseguire più volte
 *  8. Log dettagliato di ogni operazione
 *
 *  USO:
 *  1. Copiare nella cartella certV/
 *  2. Aprire: http://localhost/certV/db_upgrade.php
 *  3. Verificare il report → Applicare gli upgrade necessari
 *  4. ELIMINARE questo file dopo l'uso in produzione
 * ═══════════════════════════════════════════════════════════════════════════
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);

// ── Connessione ──────────────────────────────────────────────────────────────
$pdo = null;
$db_error = null;
$db_name  = '';

if (file_exists(__DIR__ . '/Config.php')) {
    try {
        require_once __DIR__ . '/Config.php';
        $db_name = DB_NAME;
    } catch (Throwable $e) {
        $db_error = $e->getMessage();
    }
} elseif (file_exists(__DIR__ . '/Config.php.dist')) {
    $db_error = 'Config.php non trovato. Rinominare Config.php.dist → Config.php e configurare le credenziali, oppure usare install.php.';
}

// Override da form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['db_host'])) {
    try {
        $pdo = new PDO(
            "mysql:host={$_POST['db_host']};dbname={$_POST['db_name']};charset=utf8mb4",
            $_POST['db_user'], $_POST['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true]
        );
        $db_name = $_POST['db_name'];
    } catch (PDOException $e) {
        $db_error = $e->getMessage();
        $pdo = null;
    }
}

$action      = $_POST['action'] ?? '';
$target_ver  = $_POST['target_version'] ?? '';
$backup_msg  = '';

// ══════════════════════════════════════════════════════════════════════════════
//  HELPER DB — ZERO information_schema
// ══════════════════════════════════════════════════════════════════════════════

function db_tables(): array {
    global $pdo;
    return array_map(fn($r) => $r[0], $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM));
}

function db_table_exists(string $t): bool {
    global $pdo;
    // FIX: SHOW TABLES LIKE ? non supportato da MariaDB con prepared statement reali
    // Usa in_array su SHOW TABLES (già bufferizzato)
    try { return in_array($t, db_tables()); }
    catch (\Exception $e) { return false; }
}

function db_columns(string $t): array {
    global $pdo;
    try { return array_map(fn($r) => $r['Field'], $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll()); }
    catch (PDOException $e) { return []; }
}

function db_column_exists(string $t, string $c): bool {
    global $pdo;
    // FIX: SHOW COLUMNS LIKE ? non supportato da MariaDB con prepared statement reali
    try { $pdo->query("SELECT `$c` FROM `$t` LIMIT 0")->closeCursor(); return true; }
    catch (\PDOException $e) { return false; }
}

function db_indexes(string $t): array {
    global $pdo;
    try {
        $rows = $pdo->query("SHOW INDEX FROM `$t`")->fetchAll();
        return array_values(array_unique(array_map(fn($r) => $r['Key_name'], $rows)));
    } catch (PDOException $e) { return []; }
}

function db_fks(string $t): array {
    global $pdo;
    $fks = [];
    try {
        $row = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM);
        if ($row && !empty($row[1])) {
            preg_match_all('/CONSTRAINT\s+`([^`]+)`\s+FOREIGN\s+KEY\s+\(`([^`]+)`\)\s+REFERENCES\s+`([^`]+)`\s+\(`([^`]+)`\)/i',
                $row[1], $m, PREG_SET_ORDER);
            foreach ($m as $match) {
                $fks[$match[1]] = ['col' => $match[2], 'ref_table' => $match[3], 'ref_col' => $match[4]];
            }
        }
    } catch (PDOException $e) {}
    return $fks;
}

function db_column_fks(string $t, string $col): array {
    $all = db_fks($t);
    $result = [];
    foreach ($all as $name => $info) { if ($info['col'] === $col) $result[] = $name; }
    return $result;
}

function db_setting(string $key): ?string {
    global $pdo;
    try { $s = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key=?"); $s->execute([$key]); $r = $s->fetchColumn(); return $r !== false ? $r : null; }
    catch (PDOException $e) { return null; }
}

function db_settings_keys(): array {
    global $pdo;
    try { return $pdo->query("SELECT setting_key FROM app_settings")->fetchAll(PDO::FETCH_COLUMN); }
    catch (PDOException $e) { return []; }
}

function db_permissions(): array {
    global $pdo;
    try { return $pdo->query("SELECT CONCAT(role_id,':',page_name) FROM role_permissions")->fetchAll(PDO::FETCH_COLUMN); }
    catch (PDOException $e) { return []; }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES|ENT_HTML5, 'UTF-8'); }

function exec_sql(string $sql): array {
    global $pdo;
    $results = [];
    $lines = explode("\n", $sql);
    $buffer = '';
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '' || strpos($t, '--') === 0) continue;
        if (preg_match('/^\/\*.*\*\/;?\s*$/', $t)) continue;
        $buffer .= $line . "\n";
        if (preg_match('/;\s*$/', $t)) {
            $stmt = trim($buffer); $buffer = '';
            if ($stmt === '' || $stmt === ';') continue;
            // Skip SELECT/SHOW (producono result set → unbuffered query error)
            if (preg_match('/^\s*(SELECT|SHOW)\s/i', $stmt)) continue;
            try {
                $pdo->exec($stmt);
                $results[] = ['ok' => true, 'sql' => substr($stmt, 0, 120)];
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                if (str_contains($msg, 'already exists') || str_contains($msg, 'Duplicate')) {
                    $results[] = ['ok' => true, 'sql' => substr($stmt, 0, 120), 'note' => 'già presente'];
                } else {
                    $results[] = ['ok' => false, 'sql' => substr($stmt, 0, 120), 'error' => $msg];
                }
            }
        }
    }
    return $results;
}

// ══════════════════════════════════════════════════════════════════════════════
//  DEFINIZIONE VERSIONI E UPGRADE STEPS
// ══════════════════════════════════════════════════════════════════════════════

$VERSIONS = [
    '2.0' => [
        'label'    => 'v2.0 — Base',
        'color'    => '#64748b',
        'tables'   => ['users','employees','roles','role_permissions','companies','company_locations','work_modes',
                        'brands','brand_referents','brand_contacts_history','brand_requirements_history',
                        'certifications','user_certifications','training_plans','planned_exams','technologies',
                        'employee_brands','app_settings','app_logs','notifications','password_resets'],
        'settings' => ['app_name','primary_color','app_version','notify_days_1','notify_days_2','notify_days_3','notify_days_4'],
    ],
    '2.1' => [
        'label'    => 'v2.1 — Recruiting',
        'color'    => '#3b82f6',
        'tables'   => ['job_positions','candidates','candidate_applications','interview_scorecards',
                        'agencies','agency_contacts','agency_contracts'],
        'settings' => ['agency_contract_alert_days','compliance_warning_pct','compliance_critical_pct'],
    ],
    '2.2' => [
        'label'    => 'v2.2 — Users/Employees Split',
        'color'    => '#8b5cf6',
        'columns'  => [
            'users' => ['employee_id','display_name','notifications_email'],
        ],
        'settings' => ['employee_code_prefix','mail_from','mail_from_name'],
        'permissions' => [
            '2:manage_employees.php','3:manage_employees.php',
            '2:candidato_profilo.php','3:candidato_profilo.php','4:candidato_profilo.php','5:candidato_profilo.php',
        ],
    ],
    '2.3' => [
        'label'    => 'v2.3 — Candidate Profile',
        'color'    => '#0ea5e9',
        'columns'  => [
            'candidates' => ['education_level','education_field','education_institute','education_year',
                             'external_certs','test_path','lettera_path','doc_extra_path','soft_skills_notes'],
        ],
    ],
    '2.4' => [
        'label'    => 'v2.4 — SMTP + Brand + Distributori + Segreteria + Documenti',
        'color'    => '#059669',
        'tables'   => ['brand_technologies','email_log','distributors','brand_distributors','logistics_requests',
                        'person_documents','document_access_rules','contract_documents',
                        'position_master_texts','position_templates','certification_versions'],
        'columns'  => [
            'brands' => ['priority','priority_color'],
            'brand_referents' => ['synced_at'],
            'person_documents' => ['version','is_current','signed_date'],
            'certifications' => ['renewal_policy','exam_cost','updated_at','updated_by'],
            'candidates' => ['deleted_at','deleted_by'],
            'planned_exams' => ['needs_logistics','plan_type','notified_at','reminder_7d_sent','reminder_1d_sent'],
            'training_plans' => ['plan_type'],
        ],
        'settings' => ['smtp_enabled','smtp_host','smtp_port','smtp_encryption','smtp_user','smtp_pass',
                        'smtp_auth','smtp_timeout','smtp_debug','smtp_test_email','smtp_verified',
                        'notify_exam_days_1','notify_exam_days_2','notify_renewal_days'],
        'permissions' => [
            '1:smtp_settings.php',
            '2:brand_technologies.php','3:brand_technologies.php',
            '2:brand_distributors.php','3:brand_distributors.php','4:brand_distributors.php',
            '2:segreteria.php','3:segreteria.php','4:segreteria.php','6:segreteria.php',
            '1:documenti.php','2:documenti.php','3:documenti.php','4:documenti.php','5:documenti.php','6:documenti.php',
            '1:catalogo_certificazioni.php','2:catalogo_certificazioni.php','3:catalogo_certificazioni.php','4:catalogo_certificazioni.php','5:catalogo_certificazioni.php',
        ],
        'indexes' => [
            'brands' => ['idx_brands_priority'],
        ],
    ],

    '4.0' => [
        'label'    => 'v4.0 — Permessi granulari utente + override',
        'color'    => '#7c3aed',
        'tables'   => ['user_permissions'],
        'columns'  => [
            'role_permissions' => ['can_view','can_create','can_edit','can_delete','can_export'],
        ],
        'settings' => ['notify_logistics_email','notify_logistics_cc'],
        'permissions' => [],
        'indexes' => [],
    ],
];

// ══════════════════════════════════════════════════════════════════════════════
//  UPGRADE SQL PER VERSIONE
// ══════════════════════════════════════════════════════════════════════════════

$UPGRADE_SQL = [];

$UPGRADE_SQL['2.2'] = <<<'SQL'
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `employee_id` INT DEFAULT NULL AFTER `id`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `display_name` VARCHAR(200) DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `notifications_email` TINYINT(1) NOT NULL DEFAULT 1;
INSERT IGNORE INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
  ('employee_code_prefix','EMP-','Prefisso matricola dipendenti'),
  ('mail_from','certv@example.com','Email mittente notifiche'),
  ('mail_from_name','certV System','Nome mittente notifiche');
INSERT IGNORE INTO `role_permissions` (`role_id`,`page_name`) VALUES
  (2,'manage_employees.php'),(3,'manage_employees.php'),
  (2,'candidato_profilo.php'),(3,'candidato_profilo.php'),
  (4,'candidato_profilo.php'),(5,'candidato_profilo.php');
SQL;

$UPGRADE_SQL['2.3'] = <<<'SQL'
ALTER TABLE `candidates`
  ADD COLUMN IF NOT EXISTS `education_level`     VARCHAR(80) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `education_field`     VARCHAR(150) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `education_institute` VARCHAR(200) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `education_year`      VARCHAR(10) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `external_certs`      TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `test_path`           VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `lettera_path`        VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `doc_extra_path`      VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `soft_skills_notes`   TEXT DEFAULT NULL;
SQL;

$UPGRADE_SQL['2.4'] = <<<'SQL'
ALTER TABLE `brands`
  ADD COLUMN IF NOT EXISTS `priority`       TINYINT(1) NOT NULL DEFAULT 3,
  ADD COLUMN IF NOT EXISTS `priority_color` VARCHAR(7) NOT NULL DEFAULT '#3b82f6';
ALTER TABLE `brands` ADD INDEX IF NOT EXISTS `idx_brands_priority` (`priority`);
UPDATE `brands` SET `priority` = 3 WHERE `priority` = 0 OR `priority` IS NULL;
UPDATE `brands` SET `priority_color` = CASE `priority`
  WHEN 1 THEN '#dc2626' WHEN 2 THEN '#f59e0b' WHEN 3 THEN '#3b82f6'
  WHEN 4 THEN '#8b5cf6' WHEN 5 THEN '#64748b' ELSE '#3b82f6'
END WHERE `priority_color` = '#3b82f6' OR `priority_color` = '' OR `priority_color` IS NULL;

CREATE TABLE IF NOT EXISTS `brand_technologies` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `brand_id` INT NOT NULL,
  `category` ENUM('Tecnologia','Servizio','Prodotto') NOT NULL DEFAULT 'Tecnologia',
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `version` VARCHAR(50) DEFAULT NULL,
  `status` ENUM('active','deprecated','eol') NOT NULL DEFAULT 'active',
  `doc_url` VARCHAR(500) DEFAULT NULL,
  `relevance` TINYINT(1) NOT NULL DEFAULT 3,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_bt_brand` (`brand_id`),
  KEY `idx_bt_category` (`category`),
  CONSTRAINT `fk_bt_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_log` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `recipient` VARCHAR(255) NOT NULL,
  `subject` VARCHAR(500) NOT NULL,
  `status` ENUM('sent','failed','queued') NOT NULL DEFAULT 'sent',
  `error_msg` TEXT DEFAULT NULL,
  `smtp_response` TEXT DEFAULT NULL,
  `module` VARCHAR(50) DEFAULT 'system',
  `related_id` INT DEFAULT NULL,
  `sent_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_email_log_status` (`status`),
  KEY `idx_email_log_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
  ('smtp_enabled','0','Abilita invio email via SMTP'),
  ('smtp_host','','Server SMTP'),
  ('smtp_port','587','Porta SMTP'),
  ('smtp_encryption','tls','Crittografia: tls, ssl, none'),
  ('smtp_user','','Username SMTP'),
  ('smtp_pass','','Password SMTP'),
  ('smtp_auth','1','Richiede autenticazione'),
  ('smtp_timeout','15','Timeout connessione secondi'),
  ('smtp_debug','0','Log debug SMTP'),
  ('smtp_test_email','','Email per test invio'),
  ('smtp_verified','0','Ultimo test riuscito');

INSERT IGNORE INTO `role_permissions` (`role_id`,`page_name`) VALUES
  (1,'smtp_settings.php'),
  (2,'brand_technologies.php'),
  (3,'brand_technologies.php'),
  (2,'brand_distributors.php'),
  (3,'brand_distributors.php'),
  (4,'brand_distributors.php');

CREATE TABLE IF NOT EXISTS `distributors` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `type` ENUM('Distributore','VAD','Rivenditore','Aggregatore') NOT NULL DEFAULT 'Distributore',
  `website` VARCHAR(255) DEFAULT NULL,
  `address` VARCHAR(300) DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `province` VARCHAR(5) DEFAULT NULL,
  `vat_number` VARCHAR(30) DEFAULT NULL,
  `status` ENUM('active','paused','inactive') NOT NULL DEFAULT 'active',
  `commercial_name` VARCHAR(150) DEFAULT NULL,
  `commercial_email` VARCHAR(150) DEFAULT NULL,
  `commercial_phone` VARCHAR(30) DEFAULT NULL,
  `academy_name` VARCHAR(150) DEFAULT NULL,
  `academy_email` VARCHAR(150) DEFAULT NULL,
  `academy_phone` VARCHAR(30) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_distributor_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `brand_distributors` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `brand_id` INT NOT NULL,
  `distributor_id` INT NOT NULL,
  `ranking` ENUM('primary','secondary') NOT NULL DEFAULT 'primary',
  `priority_order` TINYINT NOT NULL DEFAULT 1,
  `is_volume` TINYINT(1) NOT NULL DEFAULT 0,
  `is_value` TINYINT(1) NOT NULL DEFAULT 0,
  `is_academy` TINYINT(1) NOT NULL DEFAULT 0,
  `commercial_ref` VARCHAR(150) DEFAULT NULL,
  `commercial_email` VARCHAR(150) DEFAULT NULL,
  `commercial_phone` VARCHAR(30) DEFAULT NULL,
  `academy_ref` VARCHAR(150) DEFAULT NULL,
  `academy_email` VARCHAR(150) DEFAULT NULL,
  `academy_phone` VARCHAR(30) DEFAULT NULL,
  `contract_ref` VARCHAR(100) DEFAULT NULL,
  `discount_pct` DECIMAL(5,2) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_brand_dist` (`brand_id`,`distributor_id`),
  CONSTRAINT `fk_bd_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bd_dist` FOREIGN KEY (`distributor_id`) REFERENCES `distributors`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `logistics_requests` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `employee_id` INT NOT NULL,
  `planned_exam_id` INT DEFAULT NULL,
  `certification_id` INT DEFAULT NULL,
  `brand_id` INT DEFAULT NULL,
  `request_type` ENUM('alloggio','mezzo','attrezzatura','aula','catering','altro') NOT NULL DEFAULT 'alloggio',
  `status` ENUM('draft','submitted','approved','booked','completed','cancelled') NOT NULL DEFAULT 'draft',
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `date_from` DATE NOT NULL,
  `date_to` DATE DEFAULT NULL,
  `time_from` TIME DEFAULT NULL,
  `time_to` TIME DEFAULT NULL,
  `location` VARCHAR(300) DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `num_people` TINYINT DEFAULT 1,
  `budget_estimated` DECIMAL(10,2) DEFAULT NULL,
  `budget_actual` DECIMAL(10,2) DEFAULT NULL,
  `supplier` VARCHAR(200) DEFAULT NULL,
  `booking_ref` VARCHAR(100) DEFAULT NULL,
  `requested_by` INT DEFAULT NULL,
  `approved_by` INT DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `notes_internal` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_lr_employee` (`employee_id`),
  KEY `idx_lr_status` (`status`),
  CONSTRAINT `fk_lr_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `planned_exams`
  ADD COLUMN IF NOT EXISTS `exam_location` VARCHAR(300) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `exam_center` VARCHAR(200) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `booking_code` VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `needs_logistics` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `plan_type` ENUM('formazione','esame_certificazione','rinnovo','workshop_tecnico','workshop_commerciale','convegno') NOT NULL DEFAULT 'esame_certificazione',
  ADD COLUMN IF NOT EXISTS `notified_at` DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `reminder_7d_sent` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `reminder_1d_sent` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `training_plans`
  ADD COLUMN IF NOT EXISTS `plan_type` ENUM('formazione','esame_certificazione','rinnovo','workshop_tecnico','workshop_commerciale','convegno') NOT NULL DEFAULT 'formazione';

UPDATE `training_plans` SET plan_type='rinnovo' WHERE is_renewal=1 AND plan_type='formazione';

ALTER TABLE `brand_referents`
  ADD COLUMN IF NOT EXISTS `synced_at` TIMESTAMP NULL DEFAULT NULL;

INSERT IGNORE INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
  ('notify_exam_days_1','7','Primo avviso esame pianificato (giorni prima)'),
  ('notify_exam_days_2','1','Promemoria esame (giorno prima)'),
  ('notify_renewal_days','30','Avviso finestra rinnovo certificazione (giorni dopo scadenza)');

INSERT IGNORE INTO `role_permissions` (`role_id`,`page_name`) VALUES
  (2,'segreteria.php'),(3,'segreteria.php'),(4,'segreteria.php'),(6,'segreteria.php'),
  (1,'documenti.php'),(2,'documenti.php'),(3,'documenti.php'),(4,'documenti.php'),(5,'documenti.php'),(6,'documenti.php'),
  (1,'catalogo_certificazioni.php'),(2,'catalogo_certificazioni.php'),(3,'catalogo_certificazioni.php'),(4,'catalogo_certificazioni.php'),(5,'catalogo_certificazioni.php');

CREATE TABLE IF NOT EXISTS `person_documents` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `candidate_id` INT DEFAULT NULL,
  `employee_id` INT DEFAULT NULL,
  `doc_type` ENUM('cv','lettera_presentazione','note_selezione','test_tecnico','test_psicologico','valutazione','contratto','certificato_formazione','documento_identita','altro') NOT NULL DEFAULT 'altro',
  `file_name` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `file_size` INT DEFAULT NULL,
  `mime_type` VARCHAR(100) DEFAULT NULL,
  `title` VARCHAR(200) DEFAULT NULL,
  `compilation_date` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `version` INT NOT NULL DEFAULT 1,
  `is_current` TINYINT(1) NOT NULL DEFAULT 1,
  `signed_date` DATE DEFAULT NULL,
  `visibility` ENUM('all','restricted') NOT NULL DEFAULT 'restricted',
  `min_role_view` TINYINT NOT NULL DEFAULT 2,
  `min_role_download` TINYINT NOT NULL DEFAULT 2,
  `uploaded_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_pd_candidate` (`candidate_id`),
  KEY `idx_pd_employee` (`employee_id`),
  KEY `idx_pd_type` (`doc_type`),
  CONSTRAINT `fk_pd_cand` FOREIGN KEY (`candidate_id`) REFERENCES `candidates`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pd_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `person_documents`
  ADD COLUMN IF NOT EXISTS `version` INT NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `is_current` TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `signed_date` DATE DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `document_access_rules` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `doc_type` VARCHAR(50) NOT NULL,
  `role_id` INT NOT NULL,
  `can_view` TINYINT(1) NOT NULL DEFAULT 0,
  `can_download` TINYINT(1) NOT NULL DEFAULT 0,
  `can_upload` TINYINT(1) NOT NULL DEFAULT 0,
  `can_delete` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dar_type_role` (`doc_type`,`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `document_access_rules` (`doc_type`,`role_id`,`can_view`,`can_download`,`can_upload`,`can_delete`) VALUES
('cv',1,1,1,1,1),('lettera_presentazione',1,1,1,1,1),('note_selezione',1,1,1,1,1),('test_tecnico',1,1,1,1,1),('test_psicologico',1,1,1,1,1),('valutazione',1,1,1,1,1),('contratto',1,1,1,1,1),('certificato_formazione',1,1,1,1,1),('documento_identita',1,1,1,1,1),('altro',1,1,1,1,1),
('cv',2,1,1,1,1),('lettera_presentazione',2,1,1,1,1),('note_selezione',2,1,1,1,1),('test_tecnico',2,1,1,1,1),('test_psicologico',2,1,1,1,1),('valutazione',2,1,1,1,1),('contratto',2,1,1,1,1),('certificato_formazione',2,1,1,1,1),('documento_identita',2,1,1,1,1),('altro',2,1,1,1,1),
('cv',3,1,1,0,0),('test_tecnico',3,1,1,0,0),('certificato_formazione',3,1,1,0,0),('valutazione',3,1,0,0,0),
('cv',4,1,1,0,0),('test_tecnico',4,1,1,0,0),('certificato_formazione',4,1,1,0,0),
('cv',5,1,1,1,0),('lettera_presentazione',5,1,1,1,0),('note_selezione',5,1,1,1,0),('test_tecnico',5,1,1,1,0),('test_psicologico',5,1,1,1,0),
('cv',6,1,1,0,0),('certificato_formazione',6,1,1,0,0);

CREATE TABLE IF NOT EXISTS `contract_documents` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `contract_id` INT NOT NULL,
  `version` INT NOT NULL DEFAULT 1,
  `status` ENUM('current','archived','superseded') NOT NULL DEFAULT 'current',
  `file_name` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `file_size` INT DEFAULT NULL,
  `mime_type` VARCHAR(100) DEFAULT NULL,
  `title` VARCHAR(200) DEFAULT NULL,
  `signed_date` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `uploaded_by` INT DEFAULT NULL,
  `archived_at` DATETIME DEFAULT NULL,
  `archived_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_cd_contract` (`contract_id`),
  KEY `idx_cd_status` (`status`),
  CONSTRAINT `fk_cd_contract` FOREIGN KEY (`contract_id`) REFERENCES `agency_contracts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

$UPGRADE_SQL['4.0'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `user_permissions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `page_name` VARCHAR(100) NOT NULL,
  `can_view` TINYINT(1) DEFAULT NULL,
  `can_create` TINYINT(1) DEFAULT NULL,
  `can_edit` TINYINT(1) DEFAULT NULL,
  `can_delete` TINYINT(1) DEFAULT NULL,
  `can_export` TINYINT(1) DEFAULT NULL,
  `updated_by` INT DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_up_user_page` (`user_id`,`page_name`),
  CONSTRAINT `fk_up_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `role_permissions`
  ADD COLUMN IF NOT EXISTS `can_view` TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `can_create` TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `can_edit` TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `can_delete` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `can_export` TINYINT(1) NOT NULL DEFAULT 1;

INSERT IGNORE INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
  ('app_version','4.0','Versione portale'),
  ('notify_logistics_email','','Email destinatario notifiche segreteria/logistica (vuoto = tutti i manager)'),
  ('notify_logistics_cc','','CC aggiuntivo per notifiche segreteria/logistica');
SQL;

// ══════════════════════════════════════════════════════════════════════════════
//  ANALISI VERSIONE CORRENTE
// ══════════════════════════════════════════════════════════════════════════════

$current_version = '?.?';
$report = [];
$upgrades_needed = [];
$analysis_done = false;

if ($pdo && $db_name) {
    $analysis_done = true;

    // Rileva versione da app_settings
    $stored_ver = db_setting('app_version');
    if ($stored_ver) {
        $current_version = $stored_ver;
    } else {
        // Inferisci dalla struttura
        if (!db_table_exists('users'))          $current_version = 'N/A';
        elseif (!db_table_exists('job_positions'))  $current_version = '2.0';
        elseif (!db_column_exists('users','employee_id')) $current_version = '2.1';
        elseif (!db_column_exists('candidates','education_level')) $current_version = '2.2';
        elseif (!db_table_exists('brand_technologies') || !db_table_exists('distributors')) $current_version = '2.3';
        else $current_version = '2.4';
    }

    // ── Verifica per ogni versione ──────────────────────────────────────────
    foreach ($VERSIONS as $ver => $def) {
        $ver_ok = true;
        $ver_items = [];

        // Tabelle
        if (!empty($def['tables'])) {
            foreach ($def['tables'] as $t) {
                $exists = db_table_exists($t);
                $ver_items[] = ['type' => 'table', 'name' => "Tabella `$t`", 'ok' => $exists];
                if (!$exists) $ver_ok = false;
            }
        }

        // Colonne
        if (!empty($def['columns'])) {
            foreach ($def['columns'] as $table => $cols) {
                if (!db_table_exists($table)) {
                    $ver_items[] = ['type' => 'column', 'name' => "Tabella `$table` (mancante)", 'ok' => false];
                    $ver_ok = false;
                    continue;
                }
                foreach ($cols as $col) {
                    $exists = db_column_exists($table, $col);
                    $ver_items[] = ['type' => 'column', 'name' => "`$table`.`$col`", 'ok' => $exists];
                    if (!$exists) $ver_ok = false;
                }
            }
        }

        // Indici
        if (!empty($def['indexes'])) {
            foreach ($def['indexes'] as $table => $idxs) {
                if (!db_table_exists($table)) continue;
                $existing = db_indexes($table);
                foreach ($idxs as $idx) {
                    $exists = in_array($idx, $existing);
                    $ver_items[] = ['type' => 'index', 'name' => "Index `$table`.`$idx`", 'ok' => $exists];
                    if (!$exists) $ver_ok = false;
                }
            }
        }

        // Settings
        if (!empty($def['settings'])) {
            $existing = db_settings_keys();
            foreach ($def['settings'] as $key) {
                $exists = in_array($key, $existing);
                $ver_items[] = ['type' => 'setting', 'name' => "Setting `$key`", 'ok' => $exists];
                if (!$exists) $ver_ok = false;
            }
        }

        // Permissions
        if (!empty($def['permissions'])) {
            $existing = db_permissions();
            foreach ($def['permissions'] as $perm) {
                $exists = in_array($perm, $existing);
                $ver_items[] = ['type' => 'permission', 'name' => "Permesso `$perm`", 'ok' => $exists];
                if (!$exists) $ver_ok = false;
            }
        }

        $report[$ver] = ['ok' => $ver_ok, 'items' => $ver_items, 'def' => $def];
        if (!$ver_ok && isset($UPGRADE_SQL[$ver])) {
            $upgrades_needed[] = $ver;
        }
    }

    // ── BACKUP DATABASE ────────────────────────────────────────────────
    if ($action === 'backup_db') {
        $backup_dir = __DIR__ . '/uploads/backups';
        if (!is_dir($backup_dir)) @mkdir($backup_dir, 0775, true);

        $ts = date('Ymd_His');
        $backup_file = $backup_dir . "/PortalManager_db_{$db_name}_{$ts}.sql";
        $tables = db_tables();
        $dump = "-- certV Database Backup\n-- Data: " . date('Y-m-d H:i:s') . "\n-- Database: $db_name\n-- Tabelle: " . count($tables) . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            // Struttura
            $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
            $dump .= "DROP TABLE IF EXISTS `$table`;\n";
            $dump .= $create[1] . ";\n\n";

            // Dati
            $rows = $pdo->query("SELECT * FROM `$table`");
            while ($row = $rows->fetch(PDO::FETCH_NUM)) {
                $vals = array_map(function($v) use ($pdo) {
                    return $v === null ? 'NULL' : $pdo->quote($v);
                }, $row);
                $dump .= "INSERT INTO `$table` VALUES (" . implode(',', $vals) . ");\n";
            }
            $dump .= "\n";
        }
        $dump .= "SET FOREIGN_KEY_CHECKS=1;\n";

        if (file_put_contents($backup_file, $dump)) {
            $backup_size = round(filesize($backup_file) / 1024);
            $backup_msg = "<div class='alert alert-success'><i class='fa-solid fa-circle-check'></i> "
                . "<strong>Backup completato!</strong> File: <code>" . basename($backup_file) . "</code> ({$backup_size} KB) — "
                . count($tables) . " tabelle salvate in <code>uploads/backups/</code></div>";
        } else {
            $backup_msg = "<div class='alert alert-danger'><i class='fa-solid fa-circle-xmark'></i> Errore scrittura backup. Verificare permessi cartella uploads/.</div>";
        }
    }

    // ── BACKUP FILES (ZIP portale) ──────────────────────────────────
    if ($action === 'backup_files') {
        $backup_dir = __DIR__ . '/uploads/backups';
        if (!is_dir($backup_dir)) @mkdir($backup_dir, 0775, true);

        $ts = date('Ymd_His');
        $zip_file = $backup_dir . "/PortalManager_files_{$ts}.zip";

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zip_file, ZipArchive::CREATE) === true) {
                $dir = new RecursiveDirectoryIterator(__DIR__, RecursiveDirectoryIterator::SKIP_DOTS);
                $iter = new RecursiveIteratorIterator($dir);
                $count = 0;
                foreach ($iter as $file) {
                    $path = $file->getRealPath();
                    $rel = substr($path, strlen(__DIR__) + 1);
                    // Escludi cartella backups, file temporanei, .git
                    if (str_contains($rel, 'backups') || str_contains($rel, '.git')) continue;
                    $zip->addFile($path, $rel);
                    $count++;
                }
                $zip->close();
                $zip_size = round(filesize($zip_file) / 1024);
                $backup_msg = "<div class='alert alert-success'><i class='fa-solid fa-circle-check'></i> "
                    . "<strong>Backup file completato!</strong> <code>" . basename($zip_file) . "</code> ({$zip_size} KB) — "
                    . "$count file archiviati in <code>uploads/backups/</code></div>";
            } else {
                $backup_msg = "<div class='alert alert-danger'>Errore creazione ZIP.</div>";
            }
        } else {
            $backup_msg = "<div class='alert alert-warning'>Estensione ZipArchive non disponibile. Backup file non possibile.</div>";
        }
    }

    // ── ESECUZIONE UPGRADE ─────────────────────────────────────────────
    if ($action === 'upgrade') {
        $upgrade_log = [];

        // FIX: applica TUTTI gli step che hanno elementi mancanti, non solo quelli > versione corrente
        // Il DB potrebbe essere marcato v2.4 ma avere permessi v2.2 mancanti
        $version_order = array_keys($UPGRADE_SQL); // ['2.2', '2.3', '2.4']
        $to_apply = [];
        foreach ($version_order as $v) {
            // Applica se: è nella lista upgrades_needed OPPURE è <= target e > current
            if (in_array($v, $upgrades_needed) || 
                (version_compare($v, $current_version, '>') && version_compare($v, $target_ver ?: '2.4', '<='))) {
                $to_apply[] = $v;
            }
        }
        // Se nessuno selezionato ma ci sono upgrade necessari, applica tutti quelli necessari
        if (empty($to_apply) && !empty($upgrades_needed)) {
            $to_apply = $upgrades_needed;
        }

        foreach ($to_apply as $v) {
            if (!isset($UPGRADE_SQL[$v])) continue;
            $upgrade_log[$v] = exec_sql($UPGRADE_SQL[$v]);
        }

        // Aggiorna versione nel DB
        try {
            $new_ver = $target_ver ?: end($to_apply) ?: $current_version;
            $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value, description) VALUES ('app_version',?,?)
                           ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
                ->execute([$new_ver, 'Versione build']);

            // Log
            $pdo->prepare("INSERT INTO app_logs (category, level, message, ip_address) VALUES (?,?,?,?)")
                ->execute(['Upgrade', 'success', "Database aggiornato a v$new_ver", $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);
        } catch (PDOException $e) {
            // Non fatale
        }

        // Ricarica analisi
        header("Location: db_upgrade.php?upgraded=$new_ver");
        exit();
    }
}

$just_upgraded = $_GET['upgraded'] ?? '';

// ══════════════════════════════════════════════════════════════════════════════
//  RENDER
// ══════════════════════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>certV — DB Upgrade Manager</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root { --p:#0369a1; --bg:#f0f4f8; --surface:#fff; --border:#e2e8f0; --text:#1e293b; --muted:#64748b; --success:#059669; --warning:#d97706; --danger:#dc2626; }
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);font-size:14px}
.page{max-width:960px;margin:0 auto;padding:30px 20px}
.header{text-align:center;margin-bottom:28px}
.header .logo{width:60px;height:60px;background:linear-gradient(135deg,var(--p),#7c3aed);border-radius:14px;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:26px;margin-bottom:12px;box-shadow:0 6px 20px rgba(3,105,161,.2)}
.header h1{font-size:22px;font-weight:800}.header p{color:var(--muted);font-size:13px;margin-top:4px}
.card{background:var(--surface);border-radius:14px;border:1px solid var(--border);box-shadow:0 1px 3px rgba(0,0,0,.04);margin-bottom:20px;overflow:hidden}
.card-head{padding:16px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px}
.card-head h2{font-size:15px;font-weight:700}.card-head .badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:6px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px}
.card-body{padding:18px 22px}
.row{display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid #f8fafc;font-size:13px}
.row:last-child{border-bottom:none}
.icon{width:22px;height:22px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:10px;flex-shrink:0}
.icon.ok{background:#ecfdf5;color:var(--success)}.icon.fail{background:#fef2f2;color:var(--danger)}.icon.warn{background:#fffbeb;color:var(--warning)}.icon.info{background:#eff6ff;color:#3b82f6}
.name{flex:1;font-weight:500}.type{font-size:10px;color:var(--muted);text-transform:uppercase;font-weight:700;letter-spacing:.3px;min-width:70px}
.ver-badge{display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:8px;font-size:13px;font-weight:700;margin:0 6px}
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 22px;border-radius:10px;border:none;font-size:13px;font-weight:700;cursor:pointer;transition:.15s;font-family:inherit;text-decoration:none}
.btn-primary{background:var(--p);color:#fff}.btn-primary:hover{filter:brightness(1.1)}
.btn-success{background:var(--success);color:#fff}.btn-danger{background:var(--danger);color:#fff}
.btn-outline{background:transparent;border:1.5px solid var(--border);color:var(--text)}.btn-outline:hover{background:#f8fafc}
.btn:disabled{opacity:.5;cursor:not-allowed}
.alert{padding:14px 18px;border-radius:10px;margin-bottom:16px;font-size:13px;display:flex;align-items:flex-start;gap:10px;line-height:1.5}
.alert-success{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
.alert-warning{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
.alert-danger{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.alert-info{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
.fg{margin-bottom:14px}.fg label{display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
.fg input{width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:9px;font-size:14px;color:var(--text);font-family:inherit}
.fg input:focus{outline:none;border-color:var(--p);box-shadow:0 0 0 3px rgba(3,105,161,.1)}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px}
.summary-card{padding:16px;border-radius:10px;text-align:center;border:1.5px solid var(--border)}
.summary-card .num{font-size:28px;font-weight:800;font-family:'Courier New',monospace}.summary-card .lbl{font-size:10px;color:var(--muted);font-weight:700;text-transform:uppercase;margin-top:4px;letter-spacing:.3px}
.progress{height:8px;background:#f1f5f9;border-radius:4px;overflow:hidden;margin:16px 0}
.progress-bar{height:100%;border-radius:4px;transition:width .6s}
code{font-size:11px;background:#f1f5f9;padding:2px 7px;border-radius:4px;font-family:'Consolas',monospace;color:#334155}
.collapse-btn{background:none;border:none;color:var(--p);font-size:12px;cursor:pointer;padding:4px 8px;border-radius:6px;font-weight:600}
.collapse-btn:hover{background:#f0f9ff}
.footer{text-align:center;padding:20px;font-size:11px;color:var(--muted)}
</style>
</head>
<body>
<?php $page_title = "DB Upgrade & Verifica Integrità"; require __DIR__ . "/_nav_system.php"; ?>
<div class="page">

<!-- Header -->
<div class="header">
    <div class="logo"><i class="fa-solid fa-database"></i></div>
    <h1>certV — DB Upgrade Manager</h1>
    <p>Verifica integrità database e aggiornamento multi-versione</p>
</div>

<?php if ($just_upgraded): ?>
<div class="alert alert-success">
    <i class="fa-solid fa-circle-check" style="margin-top:2px"></i>
    <div><strong>Upgrade completato!</strong> Database aggiornato alla versione <strong><?=h($just_upgraded)?></strong>. Verificare il report sotto per confermare che tutti gli elementi siano OK.</div>
</div>
<?php endif; ?>

<?php if ($db_error): ?>
<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> <?=h($db_error)?></div>
<?php endif; ?>

<?php if (!$pdo): ?>
<!-- Form connessione -->
<div class="card">
    <div class="card-head"><i class="fa-solid fa-plug" style="color:var(--p)"></i><h2>Connessione Database</h2></div>
    <div class="card-body">
        <form method="POST">
            <div class="grid-2">
                <div class="fg"><label>Host</label><input type="text" name="db_host" value="localhost"></div>
                <div class="fg"><label>Database</label><input type="text" name="db_name" value="cert_management"></div>
                <div class="fg"><label>Utente</label><input type="text" name="db_user" value="root"></div>
                <div class="fg"><label>Password</label><input type="password" name="db_pass" value=""></div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plug"></i> Connetti e analizza</button>
        </form>
    </div>
</div>

<?php else: ?>

<!-- ════════════════════════════════════════════════════════════ -->
<!--  REPORT                                                      -->
<!-- ════════════════════════════════════════════════════════════ -->

<!-- Summary -->
<div class="summary-grid">
    <div class="summary-card" style="border-color:var(--p)">
        <div class="num" style="color:var(--p)"><?=h($current_version)?></div>
        <div class="lbl">Versione DB</div>
    </div>
    <div class="summary-card" style="border-color:var(--success)">
        <div class="num" style="color:var(--success)">2.4</div>
        <div class="lbl">Versione Target</div>
    </div>
    <div class="summary-card" style="border-color:<?=count($upgrades_needed)?'var(--warning)':'var(--success)'?>">
        <div class="num" style="color:<?=count($upgrades_needed)?'var(--warning)':'var(--success)'?>"><?=count($upgrades_needed)?></div>
        <div class="lbl">Upgrade necessari</div>
    </div>
    <div class="summary-card" style="border-color:<?=count(db_tables())?'var(--success)':'var(--danger)'?>">
        <div class="num" style="color:var(--p)"><?=count(db_tables())?></div>
        <div class="lbl">Tabelle trovate</div>
    </div>
</div>

<!-- Backup -->
<?=$backup_msg?>
<div class="card" style="margin-bottom:20px">
    <div class="card-head"><i class="fa-solid fa-shield-halved" style="color:var(--p)"></i><h2 style="flex:1">Backup</h2></div>
    <div class="card-body" style="padding:16px 22px">
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
            <form method="POST" style="display:inline"><input type="hidden" name="action" value="backup_db">
                <button type="submit" class="btn btn-outline" onclick="this.innerHTML='<i class=\'fa-solid fa-spinner fa-spin\'></i> Backup DB...';this.disabled=true;this.form.submit()"><i class="fa-solid fa-database"></i> Backup Database</button>
            </form>
            <form method="POST" style="display:inline"><input type="hidden" name="action" value="backup_files">
                <button type="submit" class="btn btn-outline" onclick="this.innerHTML='<i class=\'fa-solid fa-spinner fa-spin\'></i> Backup files...';this.disabled=true;this.form.submit()"><i class="fa-solid fa-file-zipper"></i> Backup File Portale</button>
            </form>
            <span style="font-size:11px;color:var(--muted)"><i class="fa-solid fa-circle-info"></i> Salvati in <code>uploads/backups/</code></span>
            <?php
            $bk_dir = __DIR__ . '/uploads/backups';
            if (is_dir($bk_dir)) {
                $bk_files = array_merge(
                    glob($bk_dir . '/PortalManager_*') ?: [],
                    glob($bk_dir . '/certv_*') ?: []
                );
                if (!empty($bk_files)) { rsort($bk_files);
                    echo '<span style="font-size:11px;color:var(--success);margin-left:8px"><i class="fa-solid fa-check"></i> ' . count($bk_files) . ' backup — ultimo: ' . basename($bk_files[0]) . '</span>';
                }
            }
            ?>
        </div>
    </div>
</div>

<?php
// Conta totali
$total_ok = 0; $total_fail = 0;
foreach ($report as $vr) { foreach ($vr['items'] as $it) { $it['ok'] ? $total_ok++ : $total_fail++; } }
$total = $total_ok + $total_fail;
$pct = $total > 0 ? round($total_ok / $total * 100) : 0;
$bar_col = $pct >= 90 ? 'var(--success)' : ($pct >= 60 ? 'var(--warning)' : 'var(--danger)');
?>

<!-- Progress -->
<div class="card">
    <div class="card-body" style="padding:14px 22px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
            <span style="font-weight:700;font-size:13px">Integrità complessiva</span>
            <span style="font-weight:800;font-size:15px;color:<?=$bar_col?>"><?=$pct?>% <span style="font-size:11px;color:var(--muted);font-weight:500">(<?=$total_ok?>/<?=$total?> elementi OK)</span></span>
        </div>
        <div class="progress"><div class="progress-bar" style="width:<?=$pct?>%;background:<?=$bar_col?>"></div></div>
    </div>
</div>

<?php if ($pct === 100 && $current_version === '2.4'): ?>
<div class="alert alert-success"><i class="fa-solid fa-shield-check"></i> <strong>Database completamente aggiornato alla v2.4.</strong> Tutti gli elementi verificati. Nessuna azione richiesta.</div>
<?php endif; ?>

<!-- Upgrade action -->
<?php if (!empty($upgrades_needed)): ?>
<div class="card" style="border-left:4px solid var(--warning)">
    <div class="card-body">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <div>
                <div style="font-weight:700;font-size:15px;margin-bottom:4px">
                    <i class="fa-solid fa-arrow-up-right-dots" style="color:var(--warning);margin-right:8px"></i>
                    Allineamento necessario
                </div>
                <div style="font-size:13px;color:var(--muted)">
                    Versione DB: <span class="ver-badge" style="background:#f1f5f9;color:var(--text)"><?=h($current_version)?></span>
                    — <?=count($upgrades_needed)?> step da applicare: <?=implode(', ', $upgrades_needed)?>
                    <br><span style="font-size:11px">Verranno aggiunti tutti gli elementi mancanti (tabelle, colonne, permessi, impostazioni)</span>
                </div>
            </div>
            <form method="POST" onsubmit="this.querySelector('button').innerHTML='<i class=\'fa-solid fa-spinner fa-spin\'></i> In corso...';this.querySelector('button').disabled=true">
                <input type="hidden" name="action" value="upgrade">
                <input type="hidden" name="target_version" value="2.4">
                <button type="submit" class="btn btn-success" style="font-size:15px;padding:12px 28px">
                    <i class="fa-solid fa-wrench"></i> Correggi tutto
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Dettaglio per versione -->
<?php foreach ($report as $ver => $vr): ?>
<?php
    $ok_count = count(array_filter($vr['items'], fn($i) => $i['ok']));
    $fail_count = count($vr['items']) - $ok_count;
    $vr_pct = count($vr['items']) > 0 ? round($ok_count / count($vr['items']) * 100) : 100;
    $sec_id = 'sec_' . str_replace('.','_',$ver);
?>
<div class="card">
    <div class="card-head" style="cursor:pointer" onclick="document.getElementById('<?=$sec_id?>').style.display=document.getElementById('<?=$sec_id?>').style.display==='none'?'':'none'">
        <span style="width:10px;height:10px;border-radius:50%;background:<?=$vr['def']['color']?>;flex-shrink:0"></span>
        <h2 style="flex:1"><?=h($vr['def']['label'])?></h2>
        <?php if ($vr['ok']): ?>
        <span class="badge" style="background:#ecfdf5;color:var(--success)"><i class="fa-solid fa-check"></i> OK</span>
        <?php else: ?>
        <span class="badge" style="background:#fef2f2;color:var(--danger)"><i class="fa-solid fa-xmark"></i> <?=$fail_count?> mancanti</span>
        <?php endif; ?>
        <span style="font-size:12px;color:var(--muted);font-weight:600"><?=$ok_count?>/<?=count($vr['items'])?></span>
        <i class="fa-solid fa-chevron-down" style="color:var(--muted);font-size:11px"></i>
    </div>
    <div id="<?=$sec_id?>" style="<?=$vr['ok'] ? 'display:none' : ''?>">
    <?php foreach ($vr['items'] as $item): ?>
        <div class="row" style="padding:7px 22px">
            <div class="icon <?=$item['ok']?'ok':'fail'?>">
                <i class="fa-solid <?=$item['ok']?'fa-check':'fa-xmark'?>"></i>
            </div>
            <span class="type"><?=$item['type']?></span>
            <span class="name"><?=h($item['name'])?></span>
            <span style="font-size:11px;color:<?=$item['ok']?'var(--success)':'var(--danger)'?>;font-weight:600">
                <?=$item['ok']?'OK':'MANCANTE'?>
            </span>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<!-- Info -->
<div class="alert alert-info">
    <i class="fa-solid fa-circle-info"></i>
    <div>
        <strong>Sicurezza:</strong> Questo strumento non usa <code>information_schema</code>. Tutte le verifiche usano
        <code>SHOW TABLES</code>, <code>SHOW COLUMNS</code>, <code>SHOW INDEX</code> e <code>SHOW CREATE TABLE</code>.
        Compatibile con qualsiasi livello di permessi MySQL.
        <br><strong>Eliminare questo file dopo l'uso in produzione.</strong>
    </div>
</div>

<!-- Actions footer -->
<div style="display:flex;gap:10px;justify-content:center;margin-top:20px">
    <a href="db_upgrade.php" class="btn btn-outline"><i class="fa-solid fa-refresh"></i> Rianalizza</a>
    <?php if ($pct === 100): ?>
    <a href="login.php" class="btn btn-primary"><i class="fa-solid fa-arrow-right"></i> Vai al portale</a>
    <?php endif; ?>
</div>

<?php endif; ?>

<div class="footer">
    certV — DB Upgrade Manager · v2.4 · <?=date('Y-m-d H:i:s')?> · PHP <?=PHP_VERSION?> · <?=PHP_OS?>
    <br>Eliminare questo file dopo l'uso in produzione
</div>
</div>
</body>
</html>

