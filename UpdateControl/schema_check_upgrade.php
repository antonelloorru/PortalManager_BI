<?php
/**
 * ═══════════════════════════════════════════════════════════════
 *  certV — schema_check_upgrade.php
 *  Controllo e aggiornamento automatico schema DB → v2.2
 * ═══════════════════════════════════════════════════════════════
 *
 *  COSA FA:
 *  1. Si connette al database usando Config.php (se presente)
 *     oppure tramite form manuale
 *  2. Controlla ogni tabella, colonna, indice e FK richiesti da v2.2
 *  3. Mostra un report visuale con ✅ / ❌ per ogni elemento
 *  4. In modalità DRY RUN mostra le query senza eseguirle
 *  5. In modalità APPLY esegue le sole modifiche mancanti
 *  6. Idempotente: sicuro da eseguire più volte
 *
 *  USO:
 *  1. Copia nella cartella certV/
 *  2. Aprilo: http://localhost/certV/schema_check_upgrade.php
 *  3. Clicca "Controlla schema" → verifica
 *  4. Clicca "Applica aggiornamenti" → esegui
 *  5. CANCELLA questo file dopo l'uso
 * ═══════════════════════════════════════════════════════════════
 */

// ── Connessione DB ─────────────────────────────────────────────
$pdo      = null;
$db_error = null;
$db_name  = '';

if (file_exists(__DIR__ . '/Config.php')) {
    try {
        require_once __DIR__ . '/Config.php';
        $db_name = DB_NAME;
    } catch (Throwable $e) {
        $db_error = $e->getMessage();
    }
}

// Override da form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['db_host'])) {
    try {
        $pdo = new PDO(
            "mysql:host={$_POST['db_host']};dbname={$_POST['db_name']};charset=utf8mb4",
            $_POST['db_user'], $_POST['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $db_name = $_POST['db_name'];
    } catch (PDOException $e) {
        $db_error = $e->getMessage();
        $pdo = null;
    }
}

$action = $_POST['action'] ?? '';

// ── Schema target v2.2 ─────────────────────────────────────────
// Struttura: [ tabella => [ colonna => definizione_SQL, ... ] ]
// Solo colonne e elementi critici per v2.2 — non l'intero DDL
$SCHEMA = [

    'employees' => [
        '__create' => "CREATE TABLE IF NOT EXISTS `employees` (
          `id`               INT NOT NULL AUTO_INCREMENT,
          `company_id`       INT DEFAULT 1,
          `location_id`      INT DEFAULT NULL,
          `work_mode_id`     INT DEFAULT NULL,
          `first_name`       VARCHAR(100) NOT NULL,
          `last_name`        VARCHAR(100) NOT NULL,
          `fiscal_code`      VARCHAR(20)  DEFAULT NULL,
          `date_of_birth`    DATE         DEFAULT NULL,
          `phone`            VARCHAR(30)  DEFAULT NULL,
          `personal_email`   VARCHAR(150) DEFAULT NULL,
          `employee_code`    VARCHAR(30)  DEFAULT NULL,
          `job_title`        VARCHAR(100) DEFAULT NULL,
          `department`       VARCHAR(100) DEFAULT NULL,
          `contract_type`    ENUM('Indeterminato','Determinato','Somministrazione','Consulenza','Stage','Partita IVA') DEFAULT 'Indeterminato',
          `hire_date`        DATE DEFAULT NULL,
          `end_date`         DATE DEFAULT NULL,
          `status`           ENUM('active','inactive','terminated') DEFAULT 'active',
          `bio`              TEXT DEFAULT NULL,
          `technical_skills` TEXT DEFAULT NULL,
          `soft_skills`      TEXT DEFAULT NULL,
          `cv_path`          VARCHAR(255) DEFAULT NULL,
          `notes`            TEXT DEFAULT NULL,
          `created_at`       TIMESTAMP NOT NULL DEFAULT current_timestamp(),
          `updated_at`       TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'columns' => [
            'id'               => "INT NOT NULL AUTO_INCREMENT",
            'company_id'       => "INT DEFAULT 1",
            'location_id'      => "INT DEFAULT NULL",
            'work_mode_id'     => "INT DEFAULT NULL",
            'first_name'       => "VARCHAR(100) NOT NULL",
            'last_name'        => "VARCHAR(100) NOT NULL",
            'fiscal_code'      => "VARCHAR(20) DEFAULT NULL",
            'date_of_birth'    => "DATE DEFAULT NULL",
            'phone'            => "VARCHAR(30) DEFAULT NULL",
            'personal_email'   => "VARCHAR(150) DEFAULT NULL",
            'employee_code'    => "VARCHAR(30) DEFAULT NULL",
            'job_title'        => "VARCHAR(100) DEFAULT NULL",
            'department'       => "VARCHAR(100) DEFAULT NULL",
            'contract_type'    => "ENUM('Indeterminato','Determinato','Somministrazione','Consulenza','Stage','Partita IVA') DEFAULT 'Indeterminato'",
            'hire_date'        => "DATE DEFAULT NULL",
            'end_date'         => "DATE DEFAULT NULL",
            'status'           => "ENUM('active','inactive','terminated') DEFAULT 'active'",
            'bio'              => "TEXT DEFAULT NULL",
            'technical_skills' => "TEXT DEFAULT NULL",
            'soft_skills'      => "TEXT DEFAULT NULL",
            'cv_path'          => "VARCHAR(255) DEFAULT NULL",
            'notes'            => "TEXT DEFAULT NULL",
            'created_at'       => "TIMESTAMP NOT NULL DEFAULT current_timestamp()",
            'updated_at'       => "TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()",
        ],
    ],

    'users' => [
        'columns' => [
            'id'                  => "INT NOT NULL AUTO_INCREMENT",
            'employee_id'         => "INT DEFAULT NULL",
            'role_id'             => "INT NOT NULL DEFAULT 6",
            'email'               => "VARCHAR(150) NOT NULL",
            'password_hash'       => "VARCHAR(255) NOT NULL",
            'display_name'        => "VARCHAR(150) DEFAULT NULL",
            'status'              => "ENUM('active','inactive') DEFAULT 'active'",
            'notifications_email' => "TINYINT(1) NOT NULL DEFAULT 1",
            'created_at'          => "TIMESTAMP NOT NULL DEFAULT current_timestamp()",
        ],
        'drop_columns' => [
            'first_name', 'last_name', 'job_title', 'hire_date', 'end_date',
            'company_id', 'location_id', 'work_mode_id',
            'bio', 'technical_skills', 'soft_skills', 'cv_path',
        ],
    ],

    'user_certifications' => [
        'rename_columns' => [
            'user_id' => ['employee_id', "INT NOT NULL COMMENT 'FK → employees.id'"],
        ],
        'add_columns' => [
            'uploaded_by' => "INT DEFAULT NULL COMMENT 'FK → users.id'",
        ],
    ],

    'training_plans' => [
        'rename_columns' => [
            'user_id' => ['employee_id', "INT NOT NULL COMMENT 'FK → employees.id'"],
        ],
    ],

    'planned_exams' => [
        'rename_columns' => [
            'user_id' => ['employee_id', "INT NOT NULL COMMENT 'FK → employees.id'"],
        ],
    ],

    'brand_referents' => [
        'rename_columns' => [
            'user_id' => ['employee_id', "INT NOT NULL COMMENT 'FK → employees.id'"],
        ],
    ],

    'brand_contacts_history' => [
        'add_columns' => [
            'archived_by' => "INT DEFAULT NULL COMMENT 'FK → users.id'",
        ],
    ],

    'employee_brands' => [
        '__create' => "CREATE TABLE IF NOT EXISTS `employee_brands` (
          `employee_id` INT NOT NULL,
          `brand_id`    INT NOT NULL,
          PRIMARY KEY (`employee_id`, `brand_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'columns' => [
            'employee_id' => "INT NOT NULL",
            'brand_id'    => "INT NOT NULL",
        ],
    ],

    'interview_scorecards' => [
        'unique_keys' => [
            'unique_scorecard' => ['application_id', 'interviewer_id'],
        ],
    ],

    'app_settings' => [
        'required_rows' => [
            ['setting_key' => 'app_version',        'setting_value' => '2.2',         'description' => 'Versione build'],
            ['setting_key' => 'employee_code_prefix','setting_value' => 'EMP-',        'description' => 'Prefisso matricola'],
            ['setting_key' => 'compliance_warning_pct',  'setting_value' => '80', 'description' => 'Soglia gialla compliance (%)'],
            ['setting_key' => 'compliance_critical_pct', 'setting_value' => '60', 'description' => 'Soglia rossa compliance (%)'],
        ],
    ],
];

// Permessi per manage_employees.php
$PERMISSIONS = [
    [1, 'manage_employees.php'],
    [2, 'manage_employees.php'],
];

// FK da verificare/creare
$FKS = [
    ['table' => 'employees',          'constraint' => 'emp_fk_co',    'col' => 'company_id',   'ref_table' => 'companies',         'ref_col' => 'id', 'on_delete' => 'SET NULL'],
    ['table' => 'employees',          'constraint' => 'emp_fk_loc',   'col' => 'location_id',  'ref_table' => 'company_locations', 'ref_col' => 'id', 'on_delete' => 'SET NULL'],
    ['table' => 'employees',          'constraint' => 'emp_fk_wm',    'col' => 'work_mode_id', 'ref_table' => 'work_modes',        'ref_col' => 'id', 'on_delete' => 'SET NULL'],
    ['table' => 'users',              'constraint' => 'u_fk_employee','col' => 'employee_id',  'ref_table' => 'employees',         'ref_col' => 'id', 'on_delete' => 'SET NULL'],
    ['table' => 'user_certifications','constraint' => 'uc_fk_emp',    'col' => 'employee_id',  'ref_table' => 'employees',         'ref_col' => 'id', 'on_delete' => 'CASCADE'],
    ['table' => 'training_plans',     'constraint' => 'tp_fk_emp',    'col' => 'employee_id',  'ref_table' => 'employees',         'ref_col' => 'id', 'on_delete' => 'CASCADE'],
    ['table' => 'planned_exams',      'constraint' => 'pe_fk_emp',    'col' => 'employee_id',  'ref_table' => 'employees',         'ref_col' => 'id', 'on_delete' => 'CASCADE'],
    ['table' => 'brand_referents',    'constraint' => 'bref_fk_emp',  'col' => 'employee_id',  'ref_table' => 'employees',         'ref_col' => 'id', 'on_delete' => 'CASCADE'],
    ['table' => 'employee_brands',    'constraint' => 'eb_fk_emp',    'col' => 'employee_id',  'ref_table' => 'employees',         'ref_col' => 'id', 'on_delete' => 'CASCADE'],
    ['table' => 'employee_brands',    'constraint' => 'eb_fk_brand',  'col' => 'brand_id',     'ref_table' => 'brands',            'ref_col' => 'id', 'on_delete' => 'CASCADE'],
];

// ── Funzioni di utilità (v2.4: senza information_schema) ─────
// Usano SHOW TABLES / SHOW COLUMNS / SHOW INDEX / SHOW CREATE TABLE
// Compatibili con qualsiasi livello di permessi MySQL
function db_tables(PDO $pdo, string $db = ''): array {
    $rows = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM);
    return array_map(fn($r) => $r[0], $rows);
}
function db_columns(PDO $pdo, string $db, string $table): array {
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => $r['Field'], $rows);
    } catch (PDOException $e) { return []; }
}
function db_indexes(PDO $pdo, string $db, string $table): array {
    try {
        $rows = $pdo->query("SHOW INDEX FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_unique(array_map(fn($r) => $r['Key_name'], $rows)));
    } catch (PDOException $e) { return []; }
}
function db_fks(PDO $pdo, string $db = ''): array {
    $fks = [];
    $tables = db_tables($pdo);
    foreach ($tables as $table) {
        try {
            $row = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
            if (!$row || empty($row[1])) continue;
            preg_match_all(
                '/CONSTRAINT\s+`([^`]+)`\s+FOREIGN\s+KEY\s+\(`([^`]+)`\)\s+REFERENCES\s+`([^`]+)`\s+\(`([^`]+)`\)/i',
                $row[1], $matches, PREG_SET_ORDER
            );
            foreach ($matches as $m) { $fks[$m[1]] = $table; }
        } catch (PDOException $e) {}
    }
    return $fks;
}
/**
 * FK su una specifica colonna (via SHOW CREATE TABLE).
 * Sostituzione di: SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
 */
function db_column_fks(PDO $pdo, string $table, string $column): array {
    $result = [];
    try {
        $row = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
        if ($row && !empty($row[1])) {
            preg_match_all(
                '/CONSTRAINT\s+`([^`]+)`\s+FOREIGN\s+KEY\s+\(`([^`]+)`\)\s+REFERENCES/i',
                $row[1], $matches, PREG_SET_ORDER
            );
            foreach ($matches as $m) {
                if ($m[2] === $column) $result[] = $m[1];
            }
        }
    } catch (PDOException $e) {}
    return $result;
}
function db_settings(PDO $pdo): array {
    try { return $pdo->query("SELECT setting_key FROM app_settings")->fetchAll(PDO::FETCH_COLUMN); }
    catch (Exception $e) { return []; }
}
function db_permissions(PDO $pdo): array {
    try { return $pdo->query("SELECT CONCAT(role_id,':',page_name) FROM role_permissions")->fetchAll(PDO::FETCH_COLUMN); }
    catch (Exception $e) { return []; }
}
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES|ENT_HTML5, 'UTF-8'); }

// ── Analisi + generazione query ────────────────────────────────
$report   = [];  // ['label', 'status' ok|missing|info|warn, 'query']
$queries  = [];  // query da eseguire

function add($label, $status, $query='') {
    global $report, $queries;
    $report[] = ['label'=>$label,'status'=>$status,'query'=>$query];
    if ($status === 'missing' && $query) $queries[] = $query;
}

if ($pdo && $db_name) {
    $existing_tables  = db_tables($pdo, $db_name);
    $existing_fks     = db_fks($pdo, $db_name);
    $existing_settings= db_settings($pdo);
    $existing_perms   = db_permissions($pdo);

    // ── Tabelle ──────────────────────────────────────────────────
    foreach ($SCHEMA as $table => $def) {
        $table_exists = in_array($table, $existing_tables);

        // Crea tabella se mancante
        if (!$table_exists) {
            if (isset($def['__create'])) {
                add("Tabella `$table`", 'missing', $def['__create']);
            } else {
                add("Tabella `$table`", 'missing', "-- ⚠ tabella $table mancante e nessun CREATE definito");
            }
            continue;
        }

        add("Tabella `$table`", 'ok');
        $existing_cols = db_columns($pdo, $db_name, $table);

        // Rinomina colonne (user_id → employee_id)
        if (!empty($def['rename_columns'])) {
            foreach ($def['rename_columns'] as $old_col => [$new_col, $new_def]) {
                if (in_array($old_col, $existing_cols) && !in_array($new_col, $existing_cols)) {
                    add("  `$table`.`$old_col` → `$new_col`", 'missing',
                        "ALTER TABLE `$table` CHANGE COLUMN `$old_col` `$new_col` $new_def;");
                } elseif (!in_array($old_col, $existing_cols) && in_array($new_col, $existing_cols)) {
                    add("  `$table`.`$new_col` (già rinominata)", 'ok');
                } elseif (!in_array($old_col, $existing_cols) && !in_array($new_col, $existing_cols)) {
                    add("  `$table`.`$new_col`", 'missing',
                        "ALTER TABLE `$table` ADD COLUMN `$new_col` $new_def;");
                } else {
                    add("  `$table`.`$old_col` (ENTRAMBE: vecchia e nuova presenti!)", 'warn',
                        "-- ATTENZIONE: sia `$old_col` che `$new_col` esistono in `$table`. Verificare manualmente.");
                }
            }
        }

        // Colonne da aggiungere
        if (!empty($def['add_columns'])) {
            foreach ($def['add_columns'] as $col => $col_def) {
                if (!in_array($col, $existing_cols)) {
                    add("  `$table`.`$col`", 'missing',
                        "ALTER TABLE `$table` ADD COLUMN IF NOT EXISTS `$col` $col_def;");
                } else {
                    add("  `$table`.`$col`", 'ok');
                }
            }
        }

        // Colonne target (verifica esistenza)
        if (!empty($def['columns'])) {
            foreach ($def['columns'] as $col => $col_def) {
                if (!in_array($col, $existing_cols)) {
                    add("  `$table`.`$col`", 'missing',
                        "ALTER TABLE `$table` ADD COLUMN IF NOT EXISTS `$col` $col_def;");
                } else {
                    add("  `$table`.`$col`", 'ok');
                }
            }
        }

        // Colonne da rimuovere (v2.2: anagrafica rimossa da users)
        if (!empty($def['drop_columns'])) {
            foreach ($def['drop_columns'] as $col) {
                if (in_array($col, $existing_cols)) {
                    // Verifica che employees sia già popolata prima di droppare
                    $emp_count = 0;
                    if (in_array('employees', $existing_tables)) {
                        try { $emp_count = (int)$pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn(); }
                        catch (Exception $e) {}
                    }
                    if ($emp_count > 0 || $table !== 'users') {
                        // Cerca FK vincolanti sulla colonna da droppare (v2.4: senza information_schema)
                        $fk_on_col = db_column_fks($pdo, $table, $col);
                        $drop_fk_sql = '';
                        foreach ($fk_on_col as $fk_name) {
                            $drop_fk_sql .= "ALTER TABLE `$table` DROP FOREIGN KEY `$fk_name`;\n";
                        }
                        add("  `$table`.`$col` (da rimuovere)", 'missing',
                            $drop_fk_sql . "ALTER TABLE `$table` DROP COLUMN IF EXISTS `$col`;");
                    } else {
                        add("  `$table`.`$col` (attesa migrazione employees)", 'warn',
                            "-- ⚠ Non rimuovo `$table`.`$col`: la tabella employees è vuota. Eseguire prima la migrazione dati.");
                    }
                } else {
                    add("  `$table`.`$col` (già rimossa)", 'ok');
                }
            }
        }

        // Indici UNIQUE
        if (!empty($def['unique_keys'])) {
            $existing_idx = db_indexes($pdo, $db_name, $table);
            foreach ($def['unique_keys'] as $idx_name => $cols) {
                if (!in_array($idx_name, $existing_idx)) {
                    $cols_sql = implode('`, `', $cols);
                    // Prima elimina duplicati se esistono
                    $dup_check = "DELETE t1 FROM `$table` t1 INNER JOIN `$table` t2 WHERE t1.id < t2.id AND t1.`{$cols[0]}`=t2.`{$cols[0]}` AND t1.`{$cols[1]}`=t2.`{$cols[1]}`;";
                    add("  `$table` UNIQUE KEY `$idx_name`", 'missing',
                        "$dup_check\nALTER TABLE `$table` ADD UNIQUE KEY IF NOT EXISTS `$idx_name` (`$cols_sql`);");
                } else {
                    add("  `$table` UNIQUE KEY `$idx_name`", 'ok');
                }
            }
        }
    }

    // ── FK ───────────────────────────────────────────────────────
    add('', 'info', '');
    add('── Foreign Keys ──', 'info', '');
    foreach ($FKS as $fk) {
        $exists = isset($existing_fks[$fk['constraint']]);
        // Verifica anche che la tabella e colonna esistano
        $tbl_ok = in_array($fk['table'], $existing_tables);
        $ref_ok = in_array($fk['ref_table'], $existing_tables);
        if (!$exists && $tbl_ok && $ref_ok) {
            // Rimuovi FK vecchie che puntano alla colonna in modo incompatibile
            $old_fk_query = '';
            // Cerca FK esistenti sulla stessa colonna (v2.4: senza information_schema)
            $old_fks = db_column_fks($pdo, $fk['table'], $fk['col']);
            foreach ($old_fks as $ofk) {
                $old_fk_query .= "ALTER TABLE `{$fk['table']}` DROP FOREIGN KEY IF EXISTS `$ofk`;\n";
            }
            add("  FK `{$fk['constraint']}` ({$fk['table']}.{$fk['col']} → {$fk['ref_table']})", 'missing',
                $old_fk_query .
                "ALTER TABLE `{$fk['table']}` ADD CONSTRAINT `{$fk['constraint']}`\n" .
                "  FOREIGN KEY (`{$fk['col']}`) REFERENCES `{$fk['ref_table']}` (`{$fk['ref_col']}`) ON DELETE {$fk['on_delete']};");
        } elseif ($exists) {
            add("  FK `{$fk['constraint']}`", 'ok');
        } else {
            add("  FK `{$fk['constraint']}` (tabella mancante)", 'warn', '');
        }
    }

    // ── Migrazione dati users → employees ───────────────────────
    add('', 'info', '');
    add('── Migrazione dati ──', 'info', '');

    $emp_ok = in_array('employees', $existing_tables);
    $users_has_firstname = $emp_ok ? in_array('first_name', db_columns($pdo, $db_name, 'users')) : false;

    if ($emp_ok && $users_has_firstname) {
        $emp_count  = (int)$pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();
        $user_count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($emp_count < $user_count) {
            add("  Migrazione users → employees ($emp_count/$user_count migrati)", 'missing',
                "INSERT INTO `employees`
  (id,company_id,location_id,work_mode_id,first_name,last_name,job_title,
   hire_date,end_date,status,bio,technical_skills,soft_skills,cv_path,created_at)
SELECT id,company_id,location_id,work_mode_id,first_name,last_name,job_title,
       hire_date,end_date,
       CASE status WHEN 'active' THEN 'active' ELSE 'inactive' END,
       bio,technical_skills,soft_skills,cv_path,created_at
FROM `users`
ON DUPLICATE KEY UPDATE first_name=VALUES(first_name),last_name=VALUES(last_name);"
            );
            add("  Collega employee_id in users", 'missing',
                "UPDATE `users` u INNER JOIN `employees` e ON e.id=u.id SET u.employee_id=u.id WHERE u.employee_id IS NULL;"
            );
        } else {
            add("  Migrazione users → employees ($emp_count record)", 'ok');
            $no_link = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE employee_id IS NULL")->fetchColumn();
            if ($no_link > 0) {
                add("  $no_link account senza employee_id collegato", 'missing',
                    "UPDATE `users` u INNER JOIN `employees` e ON e.id=u.id SET u.employee_id=u.id WHERE u.employee_id IS NULL;");
            } else {
                add("  Tutti gli account hanno employee_id", 'ok');
            }
        }
    } elseif (!$emp_ok) {
        add("  employees non ancora creata", 'warn', '');
    } else {
        add("  Migrazione già completata (users senza colonne anagrafiche)", 'ok');
    }

    // Migra user_brands → employee_brands
    $has_user_brands = in_array('user_brands', $existing_tables);
    $has_emp_brands  = in_array('employee_brands', $existing_tables);
    if ($has_user_brands && $has_emp_brands) {
        $ub_count = (int)$pdo->query("SELECT COUNT(*) FROM user_brands")->fetchColumn();
        $eb_count = (int)$pdo->query("SELECT COUNT(*) FROM employee_brands")->fetchColumn();
        if ($eb_count < $ub_count) {
            add("  Migrazione user_brands → employee_brands ($eb_count/$ub_count)", 'missing',
                "INSERT IGNORE INTO `employee_brands` (employee_id,brand_id) SELECT user_id,brand_id FROM `user_brands`;"
            );
        } else {
            add("  user_brands → employee_brands ($eb_count record)", 'ok');
        }
    } elseif ($has_user_brands && !$has_emp_brands) {
        add("  employee_brands mancante (creare prima la tabella)", 'warn', '');
    }

    // ── Settings ─────────────────────────────────────────────────
    add('', 'info', '');
    add('── Settings e permessi ──', 'info', '');
    foreach ($SCHEMA['app_settings']['required_rows'] as $row) {
        if (!in_array($row['setting_key'], $existing_settings)) {
            add("  Setting `{$row['setting_key']}`", 'missing',
                "INSERT INTO `app_settings` (setting_key,setting_value,description)
VALUES ('{$row['setting_key']}','{$row['setting_value']}','{$row['description']}')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);");
        } else {
            add("  Setting `{$row['setting_key']}`", 'ok');
        }
    }

    // ── Permessi ─────────────────────────────────────────────────
    foreach ($PERMISSIONS as [$role_id, $page]) {
        $key = "$role_id:$page";
        if (!in_array($key, $existing_perms)) {
            add("  Permesso ruolo $role_id → $page", 'missing',
                "INSERT IGNORE INTO `role_permissions` (role_id,page_name) VALUES ($role_id,'$page');");
        } else {
            add("  Permesso ruolo $role_id → $page", 'ok');
        }
    }
}

// ── Esecuzione APPLY ───────────────────────────────────────────
$apply_results = [];
$apply_error   = null;

if ($action === 'apply' && $pdo && !empty($queries)) {
    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        foreach ($queries as $q) {
            foreach (array_filter(array_map('trim', explode(';', $q))) as $stmt) {
                if (empty($stmt) || substr($stmt, 0, 2) === '--') continue;
                try {
                    $pdo->exec($stmt . ';');
                    $apply_results[] = ['ok', $stmt];
                } catch (PDOException $e) {
                    $apply_results[] = ['error', $stmt, $e->getMessage()];
                }
            }
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        // Log
        try {
            $pdo->exec("INSERT INTO app_logs (category,level,message,ip_address)
                VALUES ('Migration','success','schema_check_upgrade.php: applicato aggiornamento v2.2','{$_SERVER['REMOTE_ADDR']}')");
        } catch (Exception $e) {}
    } catch (Exception $e) {
        $apply_error = $e->getMessage();
    }
}

// ── Conteggi ──────────────────────────────────────────────────
$n_ok      = count(array_filter($report, fn($r)=>$r['status']==='ok'));
$n_missing = count(array_filter($report, fn($r)=>$r['status']==='missing'));
$n_warn    = count(array_filter($report, fn($r)=>$r['status']==='warn'));
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>certV — Schema Check & Upgrade</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh;padding:20px}
.wrap{max-width:960px;margin:0 auto}
h1{font-size:22px;font-weight:800;color:#0ea5e9;margin-bottom:4px}
.sub{font-size:13px;color:#64748b;margin-bottom:24px}
.card{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:24px;margin-bottom:20px}
.card h2{font-size:14px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.8px;margin-bottom:16px}
.badge{display:inline-block;padding:2px 9px;border-radius:10px;font-size:10px;font-weight:800;text-transform:uppercase}
.b-ok{background:#064e3b;color:#6ee7b7}.b-miss{background:#450a0a;color:#fca5a5}.b-warn{background:#451a03;color:#fbbf24}.b-info{background:#1e293b;color:#475569}
.row{display:flex;align-items:flex-start;gap:10px;padding:7px 0;border-bottom:1px solid #1e3a5f;font-size:13px}
.row:last-child{border:none}
.row .lbl{flex:1;color:#cbd5e1}
.row .q{font-family:monospace;font-size:11px;color:#64748b;margin-top:4px;background:#0f172a;padding:6px 10px;border-radius:6px;white-space:pre-wrap;word-break:break-all}
.btn{display:inline-flex;align-items:center;gap:6px;padding:11px 20px;border-radius:9px;font-size:14px;font-weight:700;cursor:pointer;border:none;font-family:inherit;transition:.15s}
.btn-primary{background:#0ea5e9;color:#fff}.btn-primary:hover{background:#0284c7}
.btn-danger{background:#ef4444;color:#fff}.btn-danger:hover{background:#dc2626}
.btn-ghost{background:#334155;color:#e2e8f0}.btn-ghost:hover{background:#475569}
.warn-box{background:#451a03;border:1px solid #92400e;border-radius:9px;padding:14px 18px;margin-bottom:20px;font-size:13px;color:#fbbf24}
.ok-box{background:#064e3b;border:1px solid #065f46;border-radius:9px;padding:14px 18px;margin-bottom:20px;font-size:13px;color:#6ee7b7}
.err-box{background:#450a0a;border:1px solid #991b1b;border-radius:9px;padding:14px 18px;margin-bottom:20px;font-size:13px;color:#fca5a5}
.kpi{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px}
.kpi-card{background:#0f172a;padding:16px;border-radius:9px;text-align:center}
.kpi-n{font-size:28px;font-weight:800;margin-bottom:4px}
.kpi-l{font-size:10px;font-weight:700;text-transform:uppercase;color:#475569}
input,select{width:100%;padding:10px 12px;background:#0f172a;border:1px solid #334155;border-radius:8px;color:#e2e8f0;font-size:13px;font-family:inherit;margin-bottom:12px}
label{display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:4px;text-transform:uppercase}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.apply-row{padding:6px 0;font-size:12px;font-family:monospace;border-bottom:1px solid #1e293b}
.apply-row.ok{color:#6ee7b7}.apply-row.error{color:#fca5a5}
details summary{cursor:pointer;color:#64748b;font-size:11px;margin-top:4px}
</style>
</head>
<body>
<?php $page_title = "Verifica Schema DB"; require __DIR__ . "/_nav_system.php"; ?>
<div class="wrap">

<h1>certV — Schema Check &amp; Upgrade</h1>
<p class="sub">Controllo e aggiornamento automatico schema DB verso v2.2</p>

<?php if (!$pdo && !$db_error): ?>
<!-- Form connessione -->
<div class="card">
  <h2>Connessione database</h2>
  <?php if(file_exists(__DIR__.'/Config.php')): ?>
  <div class="ok-box">✅ Config.php trovato — clicca "Connetti e Controlla" per usare la configurazione esistente.</div>
  <?php else: ?>
  <div class="warn-box">⚠ Config.php non trovato nella stessa cartella. Inserisci le credenziali manualmente.</div>
  <?php endif; ?>
  <form method="POST">
    <div class="grid2">
      <div><label>Host</label><input type="text" name="db_host" value="localhost"></div>
      <div><label>Database</label><input type="text" name="db_name" value="cert_management"></div>
      <div><label>Utente</label><input type="text" name="db_user" value="root"></div>
      <div><label>Password</label><input type="password" name="db_pass" value=""></div>
    </div>
    <button type="submit" name="action" value="check" class="btn btn-primary">🔍 Connetti e Controlla</button>
  </form>
</div>

<?php elseif ($db_error): ?>
<div class="err-box">❌ Errore connessione: <?=h($db_error)?></div>
<form method="POST">
  <div class="grid2">
    <div><label>Host</label><input type="text" name="db_host" value="localhost"></div>
    <div><label>Database</label><input type="text" name="db_name" value="cert_management"></div>
    <div><label>Utente</label><input type="text" name="db_user" value="root"></div>
    <div><label>Password</label><input type="password" name="db_pass" value=""></div>
  </div>
  <button type="submit" name="action" value="check" class="btn btn-primary">Riprova</button>
</form>

<?php else: ?>

<!-- Risultati APPLY -->
<?php if ($action === 'apply' && !empty($apply_results)): ?>
<?php $n_ok_apply = count(array_filter($apply_results,fn($r)=>$r[0]==='ok')); ?>
<?php $n_err_apply= count(array_filter($apply_results,fn($r)=>$r[0]==='error')); ?>
<div class="<?=$n_err_apply>0?'err-box':'ok-box'?>" style="margin-bottom:20px">
  <?=$n_err_apply>0?"⚠ Completato con $n_err_apply errori. $n_ok_apply query eseguite.":"✅ Aggiornamento completato. $n_ok_apply query eseguite con successo."?>
</div>
<div class="card" style="margin-bottom:20px">
  <h2>Log esecuzione</h2>
  <?php foreach($apply_results as $res):
    $status  = $res[0];
    $stmt    = $res[1];
    $err_msg = $res[2] ?? '';
  ?>
  <div class="apply-row <?=$status?>">
    <?=$status==='ok'?'✓':'✗'?> <?=h(mb_substr($stmt,0,120)).(strlen($stmt)>120?'…':'')?>
    <?php if($err_msg): ?><br>&nbsp;&nbsp;→ <?=h($err_msg)?><?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php elseif($apply_error): ?>
<div class="err-box">❌ Errore durante apply: <?=h($apply_error)?></div>
<?php endif; ?>

<!-- KPI -->
<div class="kpi">
  <div class="kpi-card"><div class="kpi-n" style="color:#6ee7b7"><?=$n_ok?></div><div class="kpi-l">Elementi OK</div></div>
  <div class="kpi-card"><div class="kpi-n" style="color:#fca5a5"><?=$n_missing?></div><div class="kpi-l">Modifiche richieste</div></div>
  <div class="kpi-card"><div class="kpi-n" style="color:#fbbf24"><?=$n_warn?></div><div class="kpi-l">Avvisi</div></div>
</div>

<?php if ($n_missing === 0): ?>
<div class="ok-box">🎉 Schema già aggiornato a v2.2 — nessuna modifica necessaria.</div>
<?php else: ?>
<div class="warn-box">⚠ <?=$n_missing?> modifiche richieste per portare il database a v2.2. Usa il pulsante "Applica" in fondo alla pagina.</div>
<?php endif; ?>

<!-- Report dettagliato -->
<div class="card">
  <h2>Report schema — Database: <?=h($db_name)?></h2>
  <?php foreach($report as $item): if($item['status']==='info') continue; ?>
  <div class="row">
    <span class="badge b-<?=$item['status']?>"><?=match($item['status']){'ok'=>'✓ OK','missing'=>'✗ MANCA','warn'=>'⚠ AVVISO',default=>$item['status']}?></span>
    <div style="flex:1">
      <div class="lbl"><?=h($item['label'])?></div>
      <?php if($item['query'] && $item['status']==='missing'): ?>
      <details><summary>Mostra query SQL</summary>
        <div class="q"><?=h($item['query'])?></div>
      </details>
      <?php elseif($item['query'] && $item['status']==='warn'): ?>
      <div class="q" style="color:#fbbf24"><?=h($item['query'])?></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Query DRY RUN -->
<?php if(!empty($queries)): ?>
<div class="card">
  <h2>Query da eseguire (<?=count($queries)?>)</h2>
  <div style="margin-bottom:16px">
    <div class="q" style="max-height:320px;overflow-y:auto"><?=h(implode("\n\n", $queries))?></div>
  </div>

  <form method="POST" onsubmit="return confirm('Confermi l\'esecuzione di tutte le modifiche al database?')">
    <?php if(!file_exists(__DIR__.'/Config.php')): ?>
    <input type="hidden" name="db_host" value="<?=h($_POST['db_host']??'localhost')?>">
    <input type="hidden" name="db_name" value="<?=h($_POST['db_name']??'cert_management')?>">
    <input type="hidden" name="db_user" value="<?=h($_POST['db_user']??'root')?>">
    <input type="hidden" name="db_pass" value="<?=h($_POST['db_pass']??'')?>">
    <?php endif; ?>
    <div style="display:flex;gap:12px;margin-top:4px">
      <button type="submit" name="action" value="apply" class="btn btn-danger">⚡ Applica aggiornamenti</button>
      <button type="submit" name="action" value="check" class="btn btn-ghost">🔄 Ricontrolla</button>
    </div>
  </form>
</div>
<?php else: ?>
<form method="POST">
  <?php if(!file_exists(__DIR__.'/Config.php')): ?>
  <input type="hidden" name="db_host" value="<?=h($_POST['db_host']??'localhost')?>">
  <input type="hidden" name="db_name" value="<?=h($_POST['db_name']??'cert_management')?>">
  <input type="hidden" name="db_user" value="<?=h($_POST['db_user']??'root')?>">
  <input type="hidden" name="db_pass" value="<?=h($_POST['db_pass']??'')?>">
  <?php endif; ?>
  <button type="submit" name="action" value="check" class="btn btn-ghost">🔄 Ricontrolla</button>
</form>
<?php endif; ?>

<?php endif; // fine $pdo ?>

<div class="warn-box" style="margin-top:24px">
  🚨 <strong>Sicurezza:</strong> Cancella questo file dal server non appena hai finito l'aggiornamento.
</div>

</div><!-- wrap -->
</body>
</html>
