<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  PortalManager — db_upgrade.php
 *  Verificatore integrità DB e Upgrade Manager multi-versione
 *  Aggiornato a v1.6.2 — Versioni 1.6.x + SQL Runner allineate
 *
 *  FUNZIONALITÀ:
 *  1. Rileva automaticamente la versione corrente del database
 *  2. Verifica integrità: tabelle, colonne, indici, FK, impostazioni, permessi
 *  3. Mostra report visuale con stato OK / MANCANTE / WARNING per ogni elemento
 *  4. Upgrade sequenziale: v2.0 → 2.x → 4.x → 5.x → 1.0.x → 1.1.x → 1.2.x → 1.3.x → 1.4.x → 1.5.x
 *  9. AUTO-DETECT: banner all'apertura con riepilogo + bottone 'Auto-applica' fix mancanti
 *  5. Modalità DRY RUN (mostra SQL) e APPLY (esegue)
 *  6. NON usa information_schema (compatibile con qualsiasi permesso MySQL)
 *  7. Idempotente: sicuro da eseguire più volte
 *  8. Log dettagliato di ogni operazione
 *
 *  USO:
 *  1. Copiare nella cartella portalbrand/
 *  2. Aprire: http://localhost/portalbrand/db_upgrade.php
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

// v1.7.17: verifica se un valore ENUM esiste in una colonna ENUM (per cascade detection)
function db_column_enum_has(string $t, string $c, string $value): bool {
    global $pdo;
    try {
        $row = $pdo->query("SHOW COLUMNS FROM `$t` LIKE " . $pdo->quote($c))->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['Type'])) return false;
        // Type del tipo: enum('classic','modern','technical','europass')
        if (preg_match("/enum\\((.*)\\)/i", $row['Type'], $m)) {
            $values = array_map(fn($v) => trim($v, "'\""), explode(',', $m[1]));
            return in_array($value, $values, true);
        }
        return false;
    } catch (\PDOException $e) { return false; }
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

// v1.7.7 — Parser SQL robusto.
// Gestisce: stringhe singole/doppie/backtick, escape, commenti riga e blocco,
// DELIMITER personalizzato, PREPARE/EXECUTE/DEALLOCATE multi-statement.
// Esegue ogni statement non vuoto in modo sicuro.
function db_split_statements(string $sql): array {
    if (substr($sql, 0, 3) === "\xEF\xBB\xBF") $sql = substr($sql, 3);
    $statements = [];
    $current = '';
    $in_string = false; $string_char = ''; $in_comment_line = false; $in_comment_block = false;
    $delimiter = ';';
    $len = strlen($sql); $i = 0;
    while ($i < $len) {
        $ch = $sql[$i]; $next = $i + 1 < $len ? $sql[$i + 1] : '';
        if (!$in_string && !$in_comment_block && !$in_comment_line && $ch === '-' && $next === '-') {
            $in_comment_line = true; $current .= $ch; $i++; continue;
        }
        if (!$in_string && !$in_comment_line && !$in_comment_block && $ch === '/' && $next === '*') {
            $in_comment_block = true; $current .= $ch; $i++; continue;
        }
        if ($in_comment_line && $ch === "\n") $in_comment_line = false;
        if ($in_comment_block && $ch === '*' && $next === '/') {
            $in_comment_block = false; $current .= $ch . $next; $i += 2; continue;
        }
        if ($in_comment_line || $in_comment_block) { $current .= $ch; $i++; continue; }
        if (!$in_string && ($ch === "'" || $ch === '"' || $ch === '`')) {
            $in_string = true; $string_char = $ch; $current .= $ch; $i++; continue;
        }
        if ($in_string) {
            if ($ch === '\\' && $next !== '') { $current .= $ch . $next; $i += 2; continue; }
            if ($ch === $string_char) $in_string = false;
            $current .= $ch; $i++; continue;
        }
        if ($delimiter === ';' && stripos(ltrim($current), 'DELIMITER ') === 0 && $ch === "\n") {
            $line = trim(substr($current, stripos($current, 'DELIMITER ') + 10));
            if ($line !== '') { $delimiter = $line; $current = ''; $i++; continue; }
        }
        $delim_len = strlen($delimiter);
        if (substr($sql, $i, $delim_len) === $delimiter) {
            $stmt = trim($current);
            if ($stmt !== '') $statements[] = $stmt;
            $current = ''; $i += $delim_len; continue;
        }
        $current .= $ch; $i++;
    }
    $stmt = trim($current);
    if ($stmt !== '') $statements[] = $stmt;
    // Filtro vuoti / solo commenti
    return array_values(array_filter($statements, function ($s) {
        $clean = preg_replace('/--[^\n]*/', '', $s);
        $clean = preg_replace('!/\*.*?\*/!s', '', $clean);
        return trim($clean) !== '';
    }));
}

function exec_sql(string $sql): array {
    global $pdo;
    $results = [];
    $statements = db_split_statements($sql);

    foreach ($statements as $stmt) {
        try {
            // Statement che restituiscono result set vanno gestiti con query() + closeCursor()
            // per non lasciare cursori aperti. Tutti gli altri vanno con exec().
            $first_word = strtoupper(strtok(ltrim($stmt), " \t\n\r"));
            if (in_array($first_word, ['SELECT', 'SHOW', 'EXECUTE'], true)) {
                $stmt_obj = $pdo->query($stmt);
                if ($stmt_obj) $stmt_obj->closeCursor();
            } else {
                $pdo->exec($stmt);
            }
            $results[] = ['ok' => true, 'sql' => substr($stmt, 0, 140)];
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            // Gestisco gli errori "benigni" come successi con nota
            if (str_contains($msg, 'already exists')
                || str_contains($msg, 'Duplicate column name')
                || str_contains($msg, 'Duplicate key name')
                || str_contains($msg, 'Duplicate entry')) {
                $results[] = ['ok' => true, 'sql' => substr($stmt, 0, 140), 'note' => 'già presente'];
            } else {
                $results[] = ['ok' => false, 'sql' => substr($stmt, 0, 140), 'error' => $msg];
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

    '4.1' => [
        'label'    => 'v4.1 — 2FA Stack (TOTP + Email OTP + Recovery codes)',
        'color'    => '#dc2626',
        'tables'   => ['user_2fa','user_2fa_recovery_codes','user_2fa_attempts'],
        'settings' => ['mfa_enforced'],
    ],

    '5.0' => [
        'label'    => 'v5.0 — Position expected + multi-figura',
        'color'    => '#f59e0b',
        'tables'   => ['positions_expected'],
    ],

    '5.4' => [
        'label'    => 'v5.4 — Clients N:M + branding + storicizzazione',
        'color'    => '#ec4899',
        'tables'   => ['clients','position_clients','branding_settings'],
    ],

    '5.5' => [
        'label'    => 'v5.5 — LDB workflow async + import staging',
        'color'    => '#8b5cf6',
        'tables'   => ['import_jobs','import_staging_rows','import_partial_completions'],
    ],

    '5.7' => [
        'label'    => 'v5.7 — Tecnologie cross-brand + EntityChangeLog',
        'color'    => '#0ea5e9',
        'tables'   => ['tech_categories','tech_brands','tech_certifications',
                       'tech_user_certifications','tech_employee_skills',
                       'employee_skills','entity_change_log'],
        'columns'  => [
            'technologies' => ['category_id','slug','icon','color','is_active'],
        ],
    ],

    '5.8' => [
        'label'    => 'v5.8 — Extensible ENUM + EnumExtender',
        'color'    => '#6366f1',
        'tables'   => ['enum_proposals'],
    ],

    '5.9' => [
        'label'    => 'v5.9 — System backup + filesystem access API',
        'color'    => '#10b981',
        'permissions' => ['1:system_backup.php'],
    ],

    '1.0.0' => [
        'label'    => 'v1.0.0 — Rebranding PortalManager + costanti centralizzate',
        'color'    => '#0a66c2',
        'settings' => ['app_name','app_version','schema_version','legacy_codename'],
    ],

    '1.0.1' => [
        'label'    => 'v1.0.1 — API cert codes autofill',
        'color'    => '#0284c7',
        'permissions' => ['1:api_cert_codes.php','2:api_cert_codes.php',
                          '3:api_cert_codes.php','4:api_cert_codes.php',
                          '5:api_cert_codes.php','6:api_cert_codes.php','7:api_cert_codes.php'],
        'indexes' => [
            'user_certifications' => ['idx_uc_cert_code'],
        ],
    ],

    '1.1.0' => [
        'label'    => 'v1.1.0 — Integrazione Credly',
        'color'    => '#7c3aed',
        'tables'   => ['employee_credly_link'],
        'columns'  => [
            'certifications' => ['credly_template_id'],
        ],
        'settings' => ['credly_enabled','credly_match_fuzzy'],
        'permissions' => ['1:credly_sync.php','2:credly_sync.php'],
        'indexes' => [
            'certifications' => ['idx_cert_credly_template'],
        ],
    ],

    '1.1.3' => [
        'label'    => 'v1.1.3 — Credly auto-create catalogo',
        'color'    => '#a855f7',
        'settings' => ['credly_auto_create_catalog'],
    ],

    '1.2.0' => [
        'label'    => 'v1.2.0 — Integrazione LinkedIn',
        'color'    => '#0a66c2',
        'tables'   => ['employee_linkedin_link'],
        'settings' => ['linkedin_enabled','linkedin_auto_create_catalog','linkedin_update_cv'],
        'permissions' => ['1:linkedin_sync.php','2:linkedin_sync.php'],
    ],

    '1.3.0' => [
        'label'    => 'v1.3.0 — Anagrafica estesa: email aziendale, telefoni, URL social',
        'color'    => '#16a34a',
        'columns'  => [
            'employees' => ['business_email','phone_personal','credly_url','linkedin_url'],
        ],
    ],

    '1.4.0' => [
        'label'    => 'v1.4.0 — Scheda dipendente full-page (employee_profile) + istruzione',
        'color'    => '#0891b2',
        'columns'  => [
            'employees' => ['education_level','education_field','education_institute','education_year'],
        ],
        'permissions' => ['1:employee_profile.php','2:employee_profile.php','4:employee_profile.php','6:employee_profile.php'],
    ],

    '1.4.3' => [
        'label'    => 'v1.4.3 — RBAC fix: api_cert_codes, credly_sync, linkedin_sync (ruoli HR/PM/TL/REC)',
        'color'    => '#2563eb',
        'permissions' => [
            '2:api_cert_codes.php','3:api_cert_codes.php','4:api_cert_codes.php','5:api_cert_codes.php',
            '2:credly_sync.php','2:linkedin_sync.php',
        ],
    ],

    '1.5.0' => [
        'label'    => 'v1.5.0 — Dispositivi aziendali (telefono, SIM, notebook, veicolo, carburante, credito)',
        'color'    => '#9333ea',
        'tables'   => [
            'emp_devices_phone','emp_devices_sim','emp_devices_notebook',
            'emp_devices_vehicle','emp_vehicle_service',
            'emp_devices_fuel_card','emp_fuel_log',
            'emp_devices_credit_card','emp_credit_card_statement',
        ],
        'permissions' => ['1:device_manager.php','2:device_manager.php'],
    ],

    '1.5.1' => [
        'label'    => 'v1.5.1 — Endpoint export/print/import dispositivi',
        'color'    => '#a21caf',
        'permissions' => [
            '1:device_export.php','2:device_export.php','4:device_export.php',
            '1:device_print.php','2:device_print.php','4:device_print.php',
            '1:device_import.php','2:device_import.php',
        ],
    ],

    '1.5.4' => [
        'label'    => 'v1.5.4 — Fix critico salvataggio dispositivi (annidamento if)',
        'color'    => '#16a34a',
        // Nessun cambio schema: è solo un fix di sintassi PHP nel file employee_profile.php.
        // Marker schema-only per riconoscere il livello applicativo raggiunto.
        'settings' => [],
    ],

    '1.6.0' => [
        'label'    => 'v1.6.0 — Generatore CV Europass DOCX (emp_languages, foto profilo, preferenze CV)',
        'color'    => '#1e40af',
        'tables'   => ['emp_languages', 'emp_cv_preferences'],
        'columns'  => [
            'employees' => ['photo_path'],
        ],
        'permissions' => ['1:employee_cv.php','2:employee_cv.php','4:employee_cv.php','5:employee_cv.php','6:employee_cv.php'],
    ],

    '1.6.1' => [
        'label'    => 'v1.6.1 — Vista globale Gestione Dispositivi (device_manager.php)',
        'color'    => '#7c3aed',
        'permissions' => ['1:device_manager.php','2:device_manager.php','4:device_manager.php'],
    ],

    '1.6.2' => [
        'label'    => 'v1.6.2 — SQL Runner UI per applicare migration via portale',
        'color'    => '#dc2626',
        'tables'   => ['sql_migrations_log'],
        'permissions' => ['1:sql_runner.php'],
    ],

    '1.6.4' => [
        'label'    => 'v1.6.4 — File Manager interno (Super Admin)',
        'color'    => '#f59e0b',
        'permissions' => ['1:file_manager.php'],
    ],

    '1.6.5' => [
        'label'    => 'v1.6.5 — Fix recruiting candidati (CSRF mal posizionato)',
        'color'    => '#16a34a',
        // Solo PHP fix, nessun cambio schema
        'settings' => [],
    ],

    '1.6.6' => [
        'label'    => 'v1.6.6 — Gap Analysis con grafici e conteggi',
        'color'    => '#7c3aed',
        // Solo PHP, nessun cambio schema
        'settings' => [],
    ],

    '1.6.7' => [
        'label'    => 'v1.6.7 — Cascade Azienda→Sede robusta',
        'color'    => '#0ea5e9',
        // Solo PHP, nessun cambio schema
        'settings' => [],
    ],

    '1.6.8' => [
        'label'    => 'v1.6.8 — Credly import offline + proxy aziendale',
        'color'    => '#ff6900',
        'permissions' => ['1:credly_manual_import.php', '2:credly_manual_import.php'],
        // Settings opzionali per proxy (verificati via setting key)
    ],

    '1.6.9' => [
        'label'    => 'v1.6.9 — Fix parser SQL Runner (regex bacata)',
        'color'    => '#16a34a',
        'settings' => [],
    ],

    '1.7.0' => [
        'label'    => 'v1.7.0 — Ricerca e filtri Referenti & Requisiti',
        'color'    => '#7c3aed',
        'settings' => [],
    ],

    '1.7.1' => [
        'label'    => 'v1.7.1 — Allineamento UI Referenti & Requisiti',
        'color'    => '#7c3aed',
        'settings' => [],
    ],

    '1.7.2' => [
        'label'    => 'v1.7.2 — Fix sessione download allegati anagrafica',
        'color'    => '#dc2626',
        'settings' => [],
    ],

    '1.7.3' => [
        'label'    => 'v1.7.3 — CV con scelta modello + anonimizzazione',
        'color'    => '#7c3aed',
        'columns'  => [
            'emp_cv_preferences' => ['cv_template', 'cv_anonymize'],
        ],
    ],

    '1.7.4' => [
        'label'    => 'v1.7.4 — Import CV (DOCX/PDF/OCR) con parsing automatico',
        'color'    => '#7c3aed',
        'permissions' => ['1:cv_import.php', '2:cv_import.php', '5:cv_import.php'],
    ],

    '1.7.5' => [
        'label'    => 'v1.7.5 — Collegamento documenti candidato→dipendente nella scheda',
        'color'    => '#0ea5e9',
        'settings' => [],
    ],

    '1.7.6' => [
        'label'    => 'v1.7.6 — db_upgrade allineato con tutte le release',
        'color'    => '#16a34a',
        'settings' => [],
    ],

    '1.7.7' => [
        'label'    => 'v1.7.7 — Fix parser SQL exec_sql (PREPARE/EXECUTE multi-statement)',
        'color'    => '#dc2626',
        'settings' => [],
    ],

    '1.7.8' => [
        'label'    => 'v1.7.8 — 3 template CV realmente diversi + release_label autoallineato',
        'color'    => '#7c3aed',
        'settings' => [],
    ],

    '1.7.9' => [
        'label'    => 'v1.7.9 — Menu topbar personalizzabile (drag&drop, scope user/role)',
        'color'    => '#7c3aed',
        'tables'   => ['menu_preferences'],
        'permissions' => ['1:menu_customizer.php','2:menu_customizer.php','5:menu_customizer.php'],
    ],

    '1.7.10' => [
        'label'    => 'v1.7.10 — Log SQL visibile + cascade auto-detect esteso',
        'color'    => '#16a34a',
        'settings' => [],
    ],

    '1.7.11' => [
        'label'    => 'v1.7.11 — Anagrafica estesa (lingue/titoli/esperienze) + modulo handover dispositivi',
        'color'    => '#7c3aed',
        'tables'   => ['emp_education', 'emp_experiences', 'device_handovers'],
        'permissions' => ['1:device_handover.php', '2:device_handover.php', '4:device_handover.php'],
    ],

    '1.7.12' => [
        'label'    => 'v1.7.12 — Fix nomi pagine menu + device_manager router + branding favicon',
        'color'    => '#16a34a',
        'settings' => [],
    ],

    '1.7.13' => [
        'label'    => 'v1.7.13 — redirect_self() universale + auto-bump versione + fix link router',
        'color'    => '#dc2626',
        'settings' => [],
    ],

    '1.7.14' => [
        'label'    => 'v1.7.14 — Menu Recruiting completo + audit_log → entity_change_log',
        'color'    => '#0ea5e9',
        'settings' => [],
    ],

    '1.7.15' => [
        'label'    => 'v1.7.15 — 4° template CV "Europass UE" (riproduzione fedele template online)',
        'color'    => '#003399',
        'settings' => [],
    ],

    '1.7.16' => [
        'label'    => 'v1.7.16 — Fix DEFINITIVO versioning: auto-bump spostato in Config.php',
        'color'    => '#16a34a',
        'settings' => [],
    ],

    '1.7.17' => [
        'label'    => 'v1.7.17 — Template Europass UE fedele: logo, palette, sub-header esperienze, foto cerchio',
        'color'    => '#622A6A',
        'settings' => [],
    ],

    '1.7.18' => [
        'label'    => 'v1.7.18 — db_upgrade.php aggiornato con tutte le versioni mancanti (1.7.14-1.7.18)',
        'color'    => '#0ea5e9',
        'settings' => [],
    ],

    '1.7.19' => [
        'label'    => 'v1.7.19 — Filtri + viste salvate + export universale (CSV/XLSX/PDF/DOCX) su 21 pagine',
        'color'    => '#7c3aed',
        'tables'   => ['saved_views'],
    ],

    '1.7.20' => [
        'label'    => 'v1.7.20 — Eliminazione certificazione da report_certificazioni.php',
        'color'    => '#dc2626',
        'settings' => [],
    ],

    '1.7.21' => [
        'label'    => 'v1.7.21 — Output buffer + cleanup ob_end_clean in redirect (fix headers sent)',
        'color'    => '#0ea5e9',
        'settings' => [],
    ],

    '1.7.22' => [
        'label'    => 'v1.7.22 — Output buffer DIFENSIVO anche in header.php (triplo strato)',
        'color'    => '#0ea5e9',
        'settings' => [],
    ],

    '1.7.23' => [
        'label'    => 'v1.7.23 — NUOVO modulo Progetti & Referenze Clienti (CRUD, ricerca, export)',
        'color'    => '#7c3aed',
        'tables'   => ['project_clients', 'projects', 'project_brands', 'project_technologies', 'project_certifications'],
    ],

    '1.7.24' => [
        'label'    => 'v1.7.24 — Fix schema progetti: project_technologies referenzia brand_technologies',
        'color'    => '#dc2626',
        'settings' => [],
    ],

    '1.7.25' => [
        'label'    => 'v1.7.25 — Cleanup migration 1.7.24 (RENAME INDEX invece di DROP+CREATE)',
        'color'    => '#16a34a',
        'settings' => [],
    ],

    '1.7.26' => [
        'label'    => 'v1.7.26 — Migration consolidata MariaDB 10.4 compatibile (no PREPARE, no RENAME INDEX)',
        'color'    => '#16a34a',
        'settings' => [],
    ],

    '1.7.27' => [
        'label'    => 'v1.7.27 — Modulo Progetti: campo "Azienda esecutrice" (FK companies) + filtro lista',
        'color'    => '#7c3aed',
        'tables'   => [],
    ],

    '1.7.28' => [
        'label'    => 'v1.7.28 — Import massivo progetti da CSV con auto-create anagrafica e match update',
        'color'    => '#7c3aed',
        'settings' => [],
    ],

    '1.7.29' => [
        'label'    => 'v1.7.29 — Parser PDF PHP-puro nativo agnostico al SO (no dipendenza pdftotext)',
        'color'    => '#16a34a',
        'settings' => [],
    ],

    '1.7.30' => [
        'label'    => 'v1.7.30 — Fix RBAC: $page_map allineato + MenuManager rispetta override utente',
        'color'    => '#dc2626',
        'settings' => [],
    ],

    '1.7.31' => [
        'label'    => 'v1.7.31 — Parser PDF: supporto CMap (font CID encoding) per CV da Word/LibreOffice',
        'color'    => '#16a34a',
        'settings' => [],
    ],

    '1.7.32' => [
        'label'    => 'v1.7.32 — Workflow Recruiting: trasformazione candidato→dipendente + pause/reopen posizione + auto-close',
        'color'    => '#7c3aed',
        'tables'   => [],
    ],
    '1.7.33' => [
        'label'    => 'v1.7.33 — Progetti: estensione campi commerciali (durata, agente, importi servizi/HW, periodo testuale, aree tecnologiche, note) + import CSV aggiornato',
        'color'    => '#7c3aed',
        'tables'   => [],
    ],
    '1.7.34' => [
        'label'    => 'v1.7.34 — Fix export progetti (whitelist .htaccess saved_views_api) + scheda project_view',
        'color'    => '#dc2626',
        'settings' => [],
    ],

    '1.7.35' => [
        'label'    => 'v1.7.35 — Allineamento Menu ↔ Permessi: 11 voci menu aggiunte + drill-down marcate',
        'color'    => '#7c3aed',
        'settings' => [],
    ],

    '1.7.36' => [
        'label'    => 'v1.7.36 — FIX DEFINITIVO export 403: spostato saved_views_api in ROOT (fuori da app/)',
        'color'    => '#dc2626',
        'settings' => [],
    ],
    '1.7.37' => [
        'label'    => 'v1.7.37 — Fix CSRF export progetti (token via header/campo nascosto) + uniformazione naming backup (PortalManager_*)',
        'color'    => '#dc2626',
        'settings' => [],
    ],
    '1.7.38' => [
        'label'    => 'v1.7.38 — Refactor documenti candidato (registry candidate_documents) + UPSERT candidature + cambio stage inline',
        'color'    => '#16a34a',
        'tables'   => ['candidate_documents'],
    ],
    '1.7.39' => [
        'label'    => 'v1.7.39 — Brand: nuova vista unificata brand_overview (360°: contatti, referenti, distributori, tecnologie, link)',
        'color'    => '#7c3aed',
        'settings' => [],
    ],
    '1.7.40' => [
        'label'    => 'v1.7.40 — Brand vista 360° editabile (8 sezioni CRUD) + flusso primario: nuovo brand → vista 360°',
        'color'    => '#7c3aed',
        'settings' => [],
    ],
    '1.7.41' => [
        'label'    => 'v1.7.41 — Directory Brand: ricerca libera (nome/contatti/tecnologie/distributori)',
        'color'    => '#0ea5e9',
        'settings' => [],
    ],
    '1.7.42' => [
        'label'    => 'v1.7.42 — Directory Brand: card certificazioni possedute (counter + split per categoria) + vista 360° con top 12 cert del catalogo',
        'color'    => '#dc2626',
        'settings' => [],
    ],
    '1.7.43' => [
        'label'    => 'v1.7.43 — Fix bug u.username (colonna inesistente)',
        'color'    => '#dc2626',
        'settings' => [],
    ],

    '1.7.44' => [
        'label'    => 'v1.7.44 — Nuovo importer certificazioni da CV-allegato (cert_import_cv)',
        'color'    => '#16a34a',
        'settings' => [],
    ],
    '1.7.45' => [
        'label'    => 'v1.7.45 — Voce menu Competenze: "Import certificazioni da CV" raggiungibile',
        'color'    => '#16a34a',
        'settings' => [],
    ],
    '1.7.46' => [
        'label'    => 'v1.7.46 — Anagrafica estesa (CCNL, RAL, qualifica, livello, PT, badge) + importer XLSX/CSV',
        'color'    => '#0ea5e9',
        'settings' => [],
    ],
    '1.7.47' => [
        'label'    => 'v1.7.47 — Fix bug colonna name in company_locations (importer dipendenti)',
        'color'    => '#dc2626',
        'settings' => [],
    ],
    '1.7.48' => [
        'label'    => 'v1.7.48 — Compensation HR (RAL/premio/km/fuori sede) + fix MenuManager merge voci nuove',
        'color'    => '#dc2626',
        'settings' => [],
    ],
    '1.7.49' => [
        'label'    => 'v1.7.49 — Verifica & Merge anagrafiche dipendenti (deduplica con riassegnazione FK)',
        'color'    => '#7c3aed',
        'settings' => [],
    ],
    '1.7.50' => [
        'label'    => 'v1.7.50 — Fix merge anagrafiche (rimozione FK errata + pre-validazione information_schema)',
        'color'    => '#dc2626',
        'settings' => [],
    ],
    '1.7.51' => [
        'label'    => 'v1.7.51 — Fix merge UNIQUE constraint employee_code (riordino sequenza operazioni)',
        'color'    => '#dc2626',
        'settings' => [],
    ],
    '1.7.52' => [
        'label'    => 'v1.7.52 — Fix critico merge: resolution granulare conflitti UNIQUE (preserva cert/brand unici del duplicato)',
        'color'    => '#dc2626',
        'settings' => [],
    ],
    '1.7.53' => [
        'label'    => 'v1.7.53 — Scheda dipendente (employee_profile): sezioni Inquadramento/Badge/Compensation visibili e modificabili',
        'color'    => '#0ea5e9',
        'settings' => [],
    ],
    '1.7.54' => [
        'label'    => 'v1.7.54 — Classificazione Finanziaria (Diretto/Indiretto) nella sezione riservata Compensation & Benefit',
        'color'    => '#0ea5e9',
        'settings' => [],
    ],
    '1.7.55' => [
        'label'    => 'v1.7.55 — Import certificazioni Cisco (parser XLSX nativo, match email/nome, UPSERT user_certifications)',
        'color'    => '#0ea5e9',
        'settings' => [],
    ],
    '1.7.56' => [
        'label'    => 'v1.7.56 — Import Cisco senza data (issue_date NULLABLE, toggle anteprima, UPSERT difensivo COALESCE)',
        'color'    => '#0ea5e9',
        'settings' => [],
    ],
    '1.7.57' => [
        'label'    => 'v1.7.57 — Hotfix delete Report certificazioni (header.php dopo POST handler, PRG)',
        'color'    => '#dc2626',
        'settings' => [],
    ],
    '1.7.58' => [
        'label'    => 'v1.7.58 — Refactoring Dipartimento: lookup departments (Servizio/Non a Valore) + storicizzazione + FK employees.department_id',
        'color'    => '#0ea5e9',
        'tables'   => ['departments','department_history'],
        'permissions' => ['1:manage_departments.php'],
        'settings' => [],
    ],
    '1.7.59' => [
        'label'    => 'v1.7.59 — Modulo Gestione Commesse (SUPERSEDED da 1.7.60: collisione nomi tabella con Progetti & Referenze)',
        'color'    => '#94a3b8',
        'tables'   => [],
        'permissions' => ['1:manage_projects.php','1:project_dashboard.php','1:manage_rate_bands.php','1:import_commesse.php','1:import_intervention_reports.php'],
        'settings' => [],
    ],
    '1.7.60' => [
        'label'    => 'v1.7.60 — HOTFIX 1.7.59: modulo Gestione Commesse nel namespace cm_* (fix collisione con projects/clients), riuso registro clienti, cleanup tabelle 1.7.59',
        'color'    => '#0ea5e9',
        'tables'   => ['cm_projects','client_locations','cm_company_prefix_map','cm_rate_bands','cm_rate_band_rates','cm_rate_band_history','cm_presales_effort','cm_team','cm_intervention_reports','cm_import_batches'],
        'permissions' => ['1:manage_projects.php','1:project_dashboard.php','1:manage_rate_bands.php','1:import_commesse.php','1:import_intervention_reports.php'],
        'settings' => [],
    ],
    '1.7.61' => [
        'label'    => 'v1.7.61 — HOTFIX versionamento: auto-bump mai eseguito (era in Config.php, file protetto). Innesco in bootstrap.php + system_update allinea app/schema/release_label',
        'color'    => '#f59e0b',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.62' => [
        'label'    => 'v1.7.62 — Import XLSX in streaming (XMLReader, memoria costante) + UploadGuard: diagnosi corretta degli upload oltre post_max_size',
        'color'    => '#f59e0b',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.63' => [
        'label'    => 'v1.7.63 — HOTFIX versionamento: ordine versioni auto-manutenuto (era fermo a 1.7.57 e faceva regredire app_version), guardia anti-regressione, splitter SQL di system_update non scarta più le migration commentate',
        'color'    => '#dc2626',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.64' => [
        'label'    => 'v1.7.64 — HOTFIX auto-bump a 2.1: ordine versioni classificato per era (2.x/4.x/5.x = certV, precedono 1.0.0), auto-bump limitato a PM_VERSION, Version.php consapevole delle ere',
        'color'    => '#dc2626',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.65' => [
        'label'    => 'v1.7.65 — Import rapporti: riconoscimento automatico della riga di intestazione (export con riga di titolo) + allargamento colonne sottodimensionate (ticket 80->500)',
        'color'    => '#0ea5e9',
        'tables'   => ['cm_intervention_reports'],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.66' => [
        'label'    => 'v1.7.66 — HOTFIX sintassi migration 1.7.65 (virgola pendente prima di ON DUPLICATE KEY, errore 1064) + QA: ogni file sql/ eseguito su MariaDB prima del rilascio',
        'color'    => '#dc2626',
        'tables'   => ['cm_intervention_reports'],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.67' => [
        'label'    => 'v1.7.67 — Controllo & Riconciliazione import, alias persistenti (commessa/tecnico/fascia), export-import XLSX delle anomalie, riapplicazione massiva',
        'color'    => '#0ea5e9',
        'tables'   => ['cm_alias_project','cm_alias_technician','cm_alias_band'],
        'permissions' => ['1:import_control.php'],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.68' => [
        'label'    => 'v1.7.68 — Consuntivo, dettaglio completo e modifica dei rapporti di intervento, Team popolabile dai rapporti (cm_team.source)',
        'color'    => '#0ea5e9',
        'tables'   => ['cm_team'],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.69' => [
        'label'    => 'v1.7.69 — Timesheet risorse (ore da rapporti + voci manuali) e Gantt delle commesse (fasi, pianificato vs effettivo, portfolio)',
        'color'    => '#0ea5e9',
        'tables'   => ['cm_timesheet_entries','cm_project_phases'],
        'permissions' => ['1:timesheet.php','1:project_gantt.php'],
        'settings' => ['ts_daily_hours','app_version','schema_version','release_label'],
    ],
    '1.7.70' => [
        'label'    => 'v1.7.70 — Console di sistema unificata (Aggiornamento ZIP + Migrazioni DB + SQL Runner + Log in una pagina); ex system_update/sql_runner reindirizzano',
        'color'    => '#0ea5e9',
        'tables'   => [],
        'permissions' => ['1:system_console.php'],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.71' => [
        'label'    => 'v1.7.71 — Vista Carico & Sovrapposizioni: impegno persone per commessa nel tempo, contemporaneità, sovraccarichi, contesa risorse tra commesse',
        'color'    => '#0ea5e9',
        'tables'   => [],
        'permissions' => ['1:workload_overview.php'],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.72' => [
        'label'    => 'v1.7.72 — HOTFIX console: anteprima aggiornamento (Array to string conversion su Nuovi/Modificati, ora conteggiati con count())',
        'color'    => '#dc2626',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.73' => [
        'label'    => 'v1.7.73 — Carico & Sovrapposizioni: legenda descrittiva dei colori, ordinamento per risorsa, filtro multi-risorsa, grafico SVG del carico per personale/gruppo',
        'color'    => '#0ea5e9',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.74' => [
        'label'    => 'v1.7.74 — Carico & Sovrapposizioni: sovrapposizioni tra commesse rispettano i filtri commessa/risorse e mostrano fascia temporale + ore per commessa',
        'color'    => '#0ea5e9',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.75' => [
        'label'    => 'v1.7.75 — HOTFIX console: tab Log vuota (JOIN su users.first_name/last_name inesistenti; nome preso da employees via employee_id)',
        'color'    => '#dc2626',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.76' => [
        'label'    => 'v1.7.76 — Cestino: ripristino dei record cancellati per errore (soft-delete + restore) nel modulo Gestione Commesse',
        'color'    => '#0ea5e9',
        'tables'   => ['cm_deleted_records'],
        'permissions' => ['1:recycle_bin.php'],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.77' => [
        'label'    => 'v1.7.77 — Cestino esteso a tutto il portale: recupero dei record cancellati per ogni tabella dati (dipendenti, certificazioni, dotazioni, reparti, ...), con PK auto-rilevata',
        'color'    => '#0ea5e9',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.78' => [
        'label'    => 'v1.7.78 — UI: voce di menu Cestino spostata nella sezione Sistema',
        'color'    => '#64748b',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.79' => [
        'label'    => 'v1.7.79 — Carico & Sovrapposizioni: filtro per linea di servizio',
        'color'    => '#0ea5e9',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.80' => [
        'label'    => 'v1.7.80 — Import Commesse DB: importa l\'export nativo del gestionale (CSV separatore |) su cm_projects, UPSERT su codice',
        'color'    => '#0ea5e9',
        'tables'   => [],
        'permissions' => ['1:import_commesse_db.php'],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.81' => [
        'label'    => 'v1.7.81 — Anagrafica Professionisti: import operatori dal gestionale (credenziali escluse), scheda di gestione e merge verso dipendenti',
        'color'    => '#0ea5e9',
        'tables'   => ['cm_professionals'],
        'permissions' => ['1:professionals.php','1:import_professionals.php'],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.82' => [
        'label'    => 'v1.7.82 — Professionisti: distinzione Esterni vs Dipendenti (rilevamento per email/nome), badge, filtro e pulsante Rileva dipendenti',
        'color'    => '#0ea5e9',
        'tables'   => ['cm_professionals'],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.83' => [
        'label'    => 'v1.7.83 — Riconciliazione via Professionisti (+ semina alias) e importazione professionista esterno in Dipendenti con data di cessazione',
        'color'    => '#0ea5e9',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.84' => [
        'label'    => 'v1.7.84 — Merge anagrafiche: nuovo criterio "Stesso nome (simile)" tollerante a secondi nomi / cognomi composti',
        'color'    => '#f59e0b',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.85' => [
        'label'    => 'v1.7.85 — Riconciliazione: verifica tecnici vs anagrafiche Dipendenti/Professionisti con allineamento retroattivo',
        'color'    => '#0ea5e9',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.86' => [
        'label'    => 'v1.7.86 — Hotfix: ricerca Anagrafica Professionisti (colonna ambigua nel WHERE con JOIN employees)',
        'color'    => '#dc2626',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.87' => [
        'label'    => 'v1.7.87 — Professionista esterno come tecnico del rapporto e nel team (con evidenza dipendente/esterno)',
        'color'    => '#0891b2',
        'tables'   => ['cm_intervention_reports','cm_team'],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.88' => [
        'label'    => 'v1.7.88 — Riconciliazione: mappatura dei tecnici non risolti anche su Anagrafica Professionisti (dipendenti + esterni)',
        'color'    => '#0891b2',
        'tables'   => ['cm_alias_technician'],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.89' => [
        'label'    => 'v1.7.89 — Fasce per commessa: alias di fascia per singola commessa e tariffe di fascia per commessa/professionista (fasce E ed X)',
        'color'    => '#0891b2',
        'tables'   => ['cm_alias_band','cm_project_band_rates'],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.90' => [
        'label'    => 'v1.7.90 — Estrazione anagrafica dipendenti (XLSX/CSV) e nuovo ruolo Responsabile Finanziario',
        'color'    => '#16a34a',
        'tables'   => ['roles'],
        'permissions' => ['1:export_employees.php','2:export_employees.php','11:export_employees.php'],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.91' => [
        'label'    => 'v1.7.91 — Estrazione anagrafica dipendenti: aggiunta colonna Email aziendale',
        'color'    => '#16a34a',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.92' => [
        'label'    => 'v1.7.92 — Merge anagrafiche: controllo Email aziendale mancante con valorizzazione selettiva dalla personale',
        'color'    => '#0891b2',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.93' => [
        'label'    => 'v1.7.93 — Compensation & Benefit: campi costo pieno e valore FTE (FullCost, TotAAxTA+BP, Rimborso KM, TotCostoTab, ValoreFTE...) con riferimenti aziendali',
        'color'    => '#dc2626',
        'tables'   => ['employees'],
        'permissions' => [],
        'settings' => ['hr_mult_fc','hr_valore_tabp','hr_val_km','hr_overhead_aziendale','hr_mult_fte','app_version','schema_version','release_label'],
    ],
    '1.7.94' => [
        'label'    => 'v1.7.94 — Valori di riferimento HR in tabella storicizzata (Amministrazione), scheda Compensation separata, nuova formula CostoNoAuto',
        'color'    => '#dc2626',
        'tables'   => ['hr_reference_values','hr_reference_history'],
        'permissions' => ['1:hr_reference_values.php','1:employee_compensation.php','2:hr_reference_values.php','2:employee_compensation.php'],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.95' => [
        'label'    => 'v1.7.95 — Formule di calcolo modificabili e storicizzate; ValoreFTE calcolato su CostoNoAuto',
        'color'    => '#dc2626',
        'tables'   => ['hr_formulas','hr_formula_history'],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.96' => [
        'label'    => 'v1.7.96 — Inquadramento contrattuale: nuovo tipo di rapporto Interinale',
        'color'    => '#0ea5e9',
        'tables'   => ['employees'],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.97' => [
        'label'    => 'v1.7.97 — Sezione Finance in Amministrazione: quadro dipendenti con filtri ed export; nuovo campo Azienda o Agenzia',
        'color'    => '#0891b2',
        'tables'   => ['employees'],
        'permissions' => ['1:finance_overview.php','2:finance_overview.php','11:finance_overview.php'],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.98' => [
        'label'    => 'v1.7.98 — Finance: vista personalizzabile (colonne visibili e ordine), campi economici e modalita di esportazione',
        'color'    => '#0891b2',
        'tables'   => ['finance_view_prefs'],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.7.99' => [
        'label'    => 'v1.7.99 — Hotfix export XLSX: file segnalato come danneggiato con valori decimali tipo 0,5 / 0,03',
        'color'    => '#dc2626',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.0' => [
        'label'    => 'v1.8.0 — Dati economici per anno di competenza: annualita catalogate, viste Finance/Compensation per esercizio, confronto tra annualita, import massivo per anno',
        'color'    => '#0891b2',
        'tables'   => ['hr_economic_years','hr_employee_economics','hr_reference_values','hr_reference_history'],
        'permissions' => ['1:hr_economic_years.php','1:finance_compare.php','1:import_economics_xlsx.php','2:hr_economic_years.php','2:finance_compare.php','2:import_economics_xlsx.php','11:hr_economic_years.php','11:finance_compare.php','11:import_economics_xlsx.php'],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.1' => [
        'label'    => 'v1.8.1 — Fix permessi pagine economiche: assegnazione al ruolo Finance per nome (corretto id ruolo nel metadata 1.7.90/1.7.97/1.8.0)',
        'color'    => '#16a34a',
        'tables'   => [],
        'permissions' => ['11:hr_economic_years.php','11:finance_compare.php','11:import_economics_xlsx.php'],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.2' => [
        'label'    => 'v1.8.2 — Carico risorse: vista giornaliera per mese con grafico dell andamento giornaliero per risorsa',
        'color'    => '#0891b2',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.3' => [
        'label'    => 'v1.8.3 — Gantt commesse ridisegnato (portfolio e scheda commessa): timeline scrollabile, barre separate, griglia mensile leggibile',
        'color'    => '#0891b2',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.4' => [
        'label'    => 'v1.8.4 — Commesse/Progetti: pannello filtri di selezione ed esportazione XLSX/CSV',
        'color'    => '#0891b2',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.5' => [
        'label'    => 'v1.8.5 — Commesse: sezione Report & Avanzamento (note datate, valutazioni, allegati) e workflow programmabile agganciato al Gantt',
        'color'    => '#7c3aed',
        'tables'   => ['cm_project_updates','cm_project_update_files','cm_workflow_steps'],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.6' => [
        'label'    => 'v1.8.6 — Carico & Sovrapposizioni: filtri estesi (societa, cliente, commessa, stato operativo, linea di servizio, tipologia)',
        'color'    => '#0891b2',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.7' => [
        'label'    => 'v1.8.7 — Hotfix db_upgrade: nessun warning per le migrazioni storiche assenti (lettura sicura via pm_migration_sql)',
        'color'    => '#16a34a',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.8' => [
        'label'    => 'v1.8.8 — Gestione Commesse: modulo Attivita e Rendicontazione DGB (ingestion DogoBit, gerarchia, KPI SLA/consuntivo, distribuzione carico, data quality, API JSON, import con diff)',
        'color'    => '#0891b2',
        'tables'   => ['dgb_operator','dgb_operator_allocation','dgb_forms_activity_planning','dgb_forms_activity','dgb_forms_activity_operator','dgb_import_log'],
        'permissions' => ['1:dgb_activities.php','8:dgb_activities.php','9:dgb_activities.php','10:dgb_activities.php','11:dgb_activities.php','1:dgb_api.php','8:dgb_api.php','9:dgb_api.php','10:dgb_api.php','11:dgb_api.php'],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.9' => [
        'label'    => 'v1.8.9 — DGB: orario ordinario/straordinario e carico, capacita standard/saturazione, distribuzione temporale giorno/mese con baseline, nuovi filtri (tipo report, modalita, ore/giorno) ed export XLSX/CSV/SVG',
        'color'    => '#2563eb',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.10' => [
        'label'    => 'v1.8.10 — Hotfix updater: backup DB in streaming (memory-safe) durante gli aggiornamenti, evita esaurimento memoria su tabelle grandi (import DGB)',
        'color'    => '#16a34a',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.11' => [
        'label'    => 'v1.8.11 — Riconciliazione DGB <-> Commesse: mappatura dgb_contract_id da external_link, vista rollup, tab DGB nella scheda commessa, colonna DGB in elenco commesse, filtro/etichetta commessa nell analisi DGB',
        'color'    => '#0891b2',
        'tables'   => ['cm_projects'],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.12' => [
        'label'    => 'v1.8.12 — Fix: metadata permessi DGB (role_id:page), packaging (.sql in sql/, .md in docs/), Router anonimizza le pagine Commesse/Finance/DGB',
        'color'    => '#16a34a',
        'tables'   => [],
        'permissions' => ['1:dgb_activities.php','8:dgb_activities.php','9:dgb_activities.php','10:dgb_activities.php','11:dgb_activities.php','1:dgb_api.php','8:dgb_api.php','9:dgb_api.php','10:dgb_api.php','11:dgb_api.php'],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.13' => [
        'label'    => 'v1.8.13 — DGB -> Commesse native: sync moduli intervento (cm_intervention_reports) + creazione commesse mancanti; classificazione persone per orario (ordinario/turni) e reperibilita (on_call), filtri e scheda Incaricati',
        'color'    => '#7c3aed',
        'tables'   => ['cm_intervention_reports','dgb_operator_map','dgb_operator_profile'],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.14' => [
        'label'    => 'v1.8.14 — Hotfix import dati economici: parsing decimali corretto (IT/EN/XLSX), non elimina piu la virgola/punto decimale',
        'color'    => '#16a34a',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.15' => [
        'label'    => 'v1.8.15 — Anonimizzazione URL: Router::PAGES allineato a tutte le voci di menu (Gestione Commesse e altre sezioni ora con slug opaco)',
        'color'    => '#0891b2',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.16' => [
        'label'    => 'v1.8.16 — Hotfix anonimizzazione: pagine Sistema/manutenzione ripristinate ad accesso diretto (Router::RESTRICTED), risolve accesso negato alla sezione Sistema e sotto-sezioni',
        'color'    => '#dc2626',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.17' => [
        'label'    => 'v1.8.17 — Hotfix export XLSX: download() svuota gli output buffer e termina con exit (niente BOM/HTML appesi), file non piu corrotti; vale per tutti gli export',
        'color'    => '#16a34a',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.18' => [
        'label'    => 'v1.8.18 — Hotfix filtri pagine anonimizzate: route_slug_field() preserva lo slug nei form GET (risolve 404 al filtro, es. Finance, con pretty-URL disattivate)',
        'color'    => '#0891b2',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.19' => [
        'label'    => 'v1.8.19 — Finance: export con colonna Anno di competenza; Compensation & Benefit (RISERVATO HR): creazione nuovo anno di competenza + link di ritorno a Finance',
        'color'    => '#0891b2',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.20' => [
        'label'    => 'v1.8.20 — Import dati economici: standard export Finance (Codice fiscale in import/export, Anno, tutti i campi) + controllo dati in pre-caricamento (anteprima con conferma)',
        'color'    => '#0891b2',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.21' => [
        'label'    => 'v1.8.21 — Import dati economici: colonne Cognome e Nome nel template/anteprima e match per nominativo (fallback); export Finance con Cognome e Nome',
        'color'    => '#0891b2',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.22' => [
        'label'    => 'v1.8.22 — Console: tool Pulizia file SQL obsoleti (mantiene gli ultimi 2 file per tipo, con anteprima e conferma)',
        'color'    => '#b91c1c',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.23' => [
        'label'    => 'v1.8.23 — DGB: import contratti (dgb_forms_contract); commessa con project_code=Code e nome=code_x_installation; colonna Commessa mostra il Code; sync crea/aggiorna le commesse',
        'color'    => '#0891b2',
        'tables'   => ['dgb_forms_contract'],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.24' => [
        'label'    => 'v1.8.24 — Hotfix import contratti DGB: header CSV case-insensitive e senza BOM (Code/Code_X_Installation riconosciuti); colonna Commessa mostra Code e code_x_installation distinti',
        'color'    => '#16a34a',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.25' => [
        'label'    => 'v1.8.25 — Sync DGB non distruttiva: preserva codice e nome reali delle commesse (WTS_xxxx), collega i contratti per codice, aggiorna solo i segnaposto DGB-<id>',
        'color'    => '#16a34a',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.26' => [
        'label'    => 'v1.8.26 — Hotfix import dati economici: lettura XLSX (XlsxReader::each headersOut passato per riferimento)',
        'color'    => '#16a34a',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.27' => [
        'label'    => 'v1.8.27 — Dipartimenti: sottocategorie (departments.parent_id) assegnabili al dipendente; gerarchia a due livelli con dropdown Padre > Figlio',
        'color'    => '#0891b2',
        'tables'   => ['departments'],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.28' => [
        'label'    => 'v1.8.28 — Sotto-categorie come relazione 1-a-molti pulita (tabella department_subcategories): una sotto-categoria appartiene a UNA categoria; categoria e sotto-categoria assegnabili al dipendente (employees.subcategory_id)',
        'color'    => '#0891b2',
        'tables'   => ['department_subcategories','employees'],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.29' => [
        'label'    => 'v1.8.29 — Sotto-categorie: tipologia (value_type) con default ereditato dalla categoria o a scelta',
        'color'    => '#0891b2',
        'tables'   => ['department_subcategories'],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.30' => [
        'label'    => 'v1.8.30 — Organigramma grafico (SVG) delle unita organizzative: gerarchia departments.parent_id, filtri (azienda, unita radice, sotto-categorie, dipendenti), export SVG',
        'color'    => '#0891b2',
        'tables'   => ['departments'],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.31' => [
        'label'    => 'v1.8.31 — Hotfix: tipologia (value_type) non modificabile nella riga di modifica dei dipartimenti (select ripristinato)',
        'color'    => '#16a34a',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.32' => [
        'label'    => 'v1.8.32 — Hotfix: gerarchia unita organizzative non salvata quando si cambiava solo l unita superiore (calcolo spostato prima della condizione di modifica)',
        'color'    => '#16a34a',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.33' => [
        'label'    => 'v1.8.33 — Anagrafe dipendenti: Inquadramento contrattuale dentro Inquadramento HR; filtri ed export ampliati con tutti i campi dato',
        'color'    => '#0891b2',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.34' => [
        'label'    => 'v1.8.34 — Anagrafe dipendenti: Inquadramento contrattuale e Compensation & Benefit racchiusi nel contenitore Inquadramento HR',
        'color'    => '#0891b2',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.35' => [
        'label'    => 'v1.8.35 — Scheda dipendente (employee_profile): Inquadramento contrattuale, Badge e Compensation & Benefit racchiusi nel contenitore Inquadramento HR (tab Anagrafica)',
        'color'    => '#0891b2',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.36' => [
        'label'    => 'v1.8.36 — Scheda dipendente: maschere Inquadramento contrattuale, Badge e Compensation spostate nel tab Inquadramento HR (non piu nel tab Anagrafica), con salvataggio nel relativo form',
        'color'    => '#0891b2',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.37' => [
        'label'    => 'v1.8.37 — Hotfix: export XLSX/DOCX delle liste (ListFilter, es. Anagrafica dipendenti) non apribile — svuotamento output buffer + zlib off + exit in saved_views_api.php',
        'color'    => '#16a34a',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.38' => [
        'label'    => 'v1.8.38 — Hotfix export XLSX/DOCX non apribile: ob_start a inizio saved_views_api (cattura output degli include, evita troncamento) + sanitizzazione dati XML',
        'color'    => '#16a34a',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
    ],
    '1.8.39' => [
        'label'    => 'v1.8.39 — Hotfix DEFINITIVO export XLSX/DOCX: corretto il file reale saved_views_api.php a ROOT (doppia session_start -> Notice in testa al file). Guardia session_start + ob_start + sanitizzazione',
        'color'    => '#16a34a',
        'tables'   => [],
        'permissions' => [],
        'settings' => ['app_version','schema_version','release_label'],
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

// ════════════════════════════════════════════════════════════════════════
// UPGRADE 4.1 — 2FA Stack
// ════════════════════════════════════════════════════════════════════════
$UPGRADE_SQL['4.1'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `user_2fa` (
  `user_id` INT NOT NULL PRIMARY KEY,
  `totp_secret` VARCHAR(255) DEFAULT NULL,
  `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `email_otp_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `recovery_codes_generated_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(),
  CONSTRAINT `fk_2fa_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_2fa_recovery_codes` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `code_hash` VARCHAR(255) NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_2fa_rc_user` (`user_id`),
  CONSTRAINT `fk_2fa_rc_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_2fa_attempts` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `method` ENUM('totp','email','recovery') NOT NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_2fa_att_user` (`user_id`,`attempted_at`),
  CONSTRAINT `fk_2fa_att_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
  ('app_version','4.1','Versione portale'),
  ('mfa_enforced','0','Forza 2FA per tutti gli utenti (1/0)');
SQL;

// ════════════════════════════════════════════════════════════════════════
// UPGRADE 5.0 — Position expected
// ════════════════════════════════════════════════════════════════════════
$UPGRADE_SQL['5.0'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `positions_expected` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `job_position_id` INT NOT NULL,
  `figure_label` VARCHAR(150) NOT NULL,
  `qty_expected` INT NOT NULL DEFAULT 1,
  `qty_filled` INT NOT NULL DEFAULT 0,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_pe_position` (`job_position_id`),
  CONSTRAINT `fk_pe_position` FOREIGN KEY (`job_position_id`) REFERENCES `job_positions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `app_settings` (`setting_key`,`setting_value`,`description`)
VALUES ('app_version','5.0','Versione portale')
ON DUPLICATE KEY UPDATE setting_value='5.0';
SQL;

// ════════════════════════════════════════════════════════════════════════
// UPGRADE 5.4 — Clients N:M + branding + storicizzazione
// ════════════════════════════════════════════════════════════════════════
$UPGRADE_SQL['5.4'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `clients` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `vat_number` VARCHAR(50) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_client_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `position_clients` (
  `job_position_id` INT NOT NULL,
  `client_id` INT NOT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`job_position_id`,`client_id`),
  KEY `idx_pc_client` (`client_id`),
  CONSTRAINT `fk_pc_position` FOREIGN KEY (`job_position_id`) REFERENCES `job_positions`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pc_client` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `branding_settings` (
  `setting_key` VARCHAR(80) NOT NULL PRIMARY KEY,
  `setting_value` TEXT DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `app_settings` (`setting_key`,`setting_value`,`description`)
VALUES ('app_version','5.4','Versione portale')
ON DUPLICATE KEY UPDATE setting_value='5.4';
SQL;

// ════════════════════════════════════════════════════════════════════════
// UPGRADE 5.5 — LDB workflow async + import staging
// ════════════════════════════════════════════════════════════════════════
$UPGRADE_SQL['5.5'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `import_jobs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `import_type` VARCHAR(80) NOT NULL,
  `original_name` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('uploaded','validated','partial','partial_lds','queued','processing','imported','failed','cancelled') NOT NULL DEFAULT 'uploaded',
  `total_rows` INT NOT NULL DEFAULT 0,
  `valid_rows` INT NOT NULL DEFAULT 0,
  `partial_rows` INT NOT NULL DEFAULT 0,
  `invalid_rows` INT NOT NULL DEFAULT 0,
  `imported_rows` INT NOT NULL DEFAULT 0,
  `allow_partial` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT DEFAULT NULL,
  `started_at` DATETIME DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ij_status` (`status`),
  KEY `idx_ij_type` (`import_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `import_staging_rows` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `job_id` INT NOT NULL,
  `row_number` INT NOT NULL,
  `payload` LONGTEXT NOT NULL,
  `status` ENUM('valid','partial','invalid','approved','rejected','imported','skipped') NOT NULL DEFAULT 'valid',
  `approved_as` ENUM('strict','ldb') DEFAULT NULL,
  `is_partial` TINYINT(1) NOT NULL DEFAULT 0,
  `missing_fields` TEXT DEFAULT NULL,
  `errors` TEXT DEFAULT NULL,
  `result_id` INT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_isr_job` (`job_id`,`status`),
  CONSTRAINT `fk_isr_job` FOREIGN KEY (`job_id`) REFERENCES `import_jobs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `import_partial_completions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `staging_row_id` INT NOT NULL,
  `field_name` VARCHAR(100) NOT NULL,
  `original_value` TEXT DEFAULT NULL,
  `resolved_value` TEXT DEFAULT NULL,
  `resolved_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `resolved_by` INT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ipc_row` (`staging_row_id`),
  CONSTRAINT `fk_ipc_row` FOREIGN KEY (`staging_row_id`) REFERENCES `import_staging_rows`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `app_settings` (`setting_key`,`setting_value`,`description`)
VALUES ('app_version','5.5','Versione portale')
ON DUPLICATE KEY UPDATE setting_value='5.5';
SQL;

// ════════════════════════════════════════════════════════════════════════
// UPGRADE 5.7 — Tecnologie cross-brand + EntityChangeLog
// ════════════════════════════════════════════════════════════════════════
$UPGRADE_SQL['5.7'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `tech_categories` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `display_order` INT NOT NULL DEFAULT 100,
  `icon` VARCHAR(50) DEFAULT NULL,
  `color` VARCHAR(20) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tc_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `technologies`
  ADD COLUMN IF NOT EXISTS `category_id` INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `slug` VARCHAR(80) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `icon` VARCHAR(50) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `color` VARCHAR(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS `tech_brands` (
  `technology_id` INT NOT NULL,
  `brand_id` INT NOT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`technology_id`,`brand_id`),
  KEY `idx_tb_brand` (`brand_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tech_certifications` (
  `technology_id` INT NOT NULL,
  `certification_id` INT NOT NULL,
  `relevance` ENUM('primary','secondary','related') NOT NULL DEFAULT 'related',
  PRIMARY KEY (`technology_id`,`certification_id`),
  KEY `idx_tc_cert` (`certification_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tech_user_certifications` (
  `technology_id` INT NOT NULL,
  `user_certification_id` INT NOT NULL,
  PRIMARY KEY (`technology_id`,`user_certification_id`),
  KEY `idx_tuc_uc` (`user_certification_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `employee_skills` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `employee_id` INT NOT NULL,
  `skill_name` VARCHAR(150) NOT NULL,
  `level` ENUM('Beginner','Intermediate','Advanced','Expert') DEFAULT NULL,
  `years_experience` DECIMAL(4,1) DEFAULT NULL,
  `validated` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_es_emp_skill` (`employee_id`,`skill_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tech_employee_skills` (
  `technology_id` INT NOT NULL,
  `employee_skill_id` INT NOT NULL,
  PRIMARY KEY (`technology_id`,`employee_skill_id`),
  KEY `idx_tes_skill` (`employee_skill_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `entity_change_log` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `table_name` VARCHAR(80) NOT NULL,
  `record_id` INT NOT NULL,
  `change_action` VARCHAR(20) NOT NULL,
  `changes_json` LONGTEXT DEFAULT NULL,
  `source` VARCHAR(40) DEFAULT 'ui',
  `changed_by` INT DEFAULT NULL,
  `changed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_ecl_entity` (`table_name`,`record_id`),
  KEY `idx_ecl_source` (`source`),
  KEY `idx_ecl_when` (`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `app_settings` (`setting_key`,`setting_value`,`description`)
VALUES ('app_version','5.7','Versione portale')
ON DUPLICATE KEY UPDATE setting_value='5.7';
SQL;

// ════════════════════════════════════════════════════════════════════════
// UPGRADE 5.8 — Extensible ENUM
// ════════════════════════════════════════════════════════════════════════
$UPGRADE_SQL['5.8'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `enum_proposals` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `table_name` VARCHAR(80) NOT NULL,
  `column_name` VARCHAR(80) NOT NULL,
  `proposed_value` VARCHAR(200) NOT NULL,
  `status` ENUM('pending','approved','mapped','rejected') NOT NULL DEFAULT 'pending',
  `mapped_to` VARCHAR(200) DEFAULT NULL,
  `count_occurrences` INT NOT NULL DEFAULT 1,
  `first_seen_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `resolved_at` DATETIME DEFAULT NULL,
  `resolved_by` INT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ep_full` (`table_name`,`column_name`,`proposed_value`),
  KEY `idx_ep_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `app_settings` (`setting_key`,`setting_value`,`description`)
VALUES ('app_version','5.8','Versione portale')
ON DUPLICATE KEY UPDATE setting_value='5.8';
SQL;

// ════════════════════════════════════════════════════════════════════════
// UPGRADE 5.9 — System backup
// ════════════════════════════════════════════════════════════════════════
$UPGRADE_SQL['5.9'] = <<<'SQL'
INSERT IGNORE INTO `role_permissions` (`role_id`,`page_name`,`can_view`,`can_create`,`can_edit`,`can_delete`,`can_export`)
VALUES (1,'system_backup.php',1,1,0,0,1);

INSERT INTO `app_settings` (`setting_key`,`setting_value`,`description`)
VALUES ('app_version','5.9','Versione portale'),
       ('schema_version','5.9','Versione schema')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;

// ════════════════════════════════════════════════════════════════════════
// UPGRADE 1.0.0 — Rebranding PortalManager
// ════════════════════════════════════════════════════════════════════════
$UPGRADE_SQL['1.0.0'] = <<<'SQL'
INSERT INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
  ('app_name','PortalManager','Nome applicazione visualizzato'),
  ('app_version','1.0.0','Versione applicazione'),
  ('schema_version','1.0.0','Versione schema database'),
  ('legacy_codename','certV','Codename precedente (storico)')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;

// ════════════════════════════════════════════════════════════════════════
// UPGRADE 1.0.1 — API cert codes autofill
// ════════════════════════════════════════════════════════════════════════
$UPGRADE_SQL['1.0.1'] = <<<'SQL'
-- Indice composto per ottimizzare autofill (idempotente via stored procedure)
SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name   = 'user_certifications'
     AND index_name   = 'idx_uc_cert_code'
);
SET @sql = IF(@idx_exists > 0,
              'SELECT "idx_uc_cert_code esiste" AS info',
              'CREATE INDEX idx_uc_cert_code ON user_certifications (certification_id, certificate_code)');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `role_permissions` (`role_id`,`page_name`,`can_view`,`can_create`,`can_edit`,`can_delete`,`can_export`) VALUES
  (1,'api_cert_codes.php',1,0,0,0,0),
  (2,'api_cert_codes.php',1,0,0,0,0),
  (3,'api_cert_codes.php',1,0,0,0,0),
  (4,'api_cert_codes.php',1,0,0,0,0),
  (5,'api_cert_codes.php',1,0,0,0,0),
  (6,'api_cert_codes.php',1,0,0,0,0),
  (7,'api_cert_codes.php',1,0,0,0,0);

INSERT INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
  ('app_version','1.0.1','Versione applicazione'),
  ('schema_version','1.0.1','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;

// ════════════════════════════════════════════════════════════════════════
// UPGRADE 1.1.0 — Credly integration
// ════════════════════════════════════════════════════════════════════════
$UPGRADE_SQL['1.1.0'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `employee_credly_link` (
  `employee_id` INT NOT NULL PRIMARY KEY,
  `credly_username` VARCHAR(150) NOT NULL,
  `last_sync_at` DATETIME DEFAULT NULL,
  `last_sync_imported` INT NOT NULL DEFAULT 0,
  `last_sync_updated` INT NOT NULL DEFAULT 0,
  `last_sync_unmatched` INT NOT NULL DEFAULT 0,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(),
  KEY `idx_credly_username` (`credly_username`),
  CONSTRAINT `fk_ecl_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Colonna credly_template_id idempotente
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name   = 'certifications'
     AND column_name  = 'credly_template_id'
);
SET @sql = IF(@col_exists > 0,
              'SELECT "credly_template_id esiste" AS info',
              'ALTER TABLE certifications ADD COLUMN credly_template_id VARCHAR(64) DEFAULT NULL COMMENT "UUID badge_template Credly"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name   = 'certifications'
     AND index_name   = 'idx_cert_credly_template'
);
SET @sql = IF(@idx_exists > 0,
              'SELECT "idx_cert_credly_template esiste" AS info',
              'CREATE INDEX idx_cert_credly_template ON certifications (credly_template_id)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `role_permissions` (`role_id`,`page_name`,`can_view`,`can_create`,`can_edit`,`can_delete`,`can_export`) VALUES
  (1,'credly_sync.php',1,1,1,1,1),
  (2,'credly_sync.php',1,1,1,0,1);

INSERT INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
  ('credly_enabled','1','Abilita integrazione Credly (1/0)'),
  ('credly_match_fuzzy','1','Match fuzzy per nome cert quando manca credly_template_id'),
  ('app_version','1.1.0','Versione applicazione'),
  ('schema_version','1.1.0','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;

// ════════════════════════════════════════════════════════════════════════
// UPGRADE 1.1.3 — Credly auto-create catalogo
// ════════════════════════════════════════════════════════════════════════
$UPGRADE_SQL['1.1.3'] = <<<'SQL'
INSERT INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
  ('credly_auto_create_catalog','1','Se 1, crea automaticamente brand+cert a catalogo per badge sconosciuti'),
  ('app_version','1.1.3','Versione applicazione'),
  ('schema_version','1.1.3','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;

// ════════════════════════════════════════════════════════════════════════
// UPGRADE 1.2.0 — LinkedIn integration
// ════════════════════════════════════════════════════════════════════════
$UPGRADE_SQL['1.2.0'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `employee_linkedin_link` (
  `employee_id` INT NOT NULL PRIMARY KEY,
  `linkedin_vanity` VARCHAR(150) NOT NULL,
  `last_sync_at` DATETIME DEFAULT NULL,
  `last_sync_imported` INT NOT NULL DEFAULT 0,
  `last_sync_updated` INT NOT NULL DEFAULT 0,
  `last_sync_unmatched` INT NOT NULL DEFAULT 0,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(),
  KEY `idx_linkedin_vanity` (`linkedin_vanity`),
  CONSTRAINT `fk_ell_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `role_permissions` (`role_id`,`page_name`,`can_view`,`can_create`,`can_edit`,`can_delete`,`can_export`) VALUES
  (1,'linkedin_sync.php',1,1,1,1,1),
  (2,'linkedin_sync.php',1,1,1,0,1);

INSERT INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
  ('linkedin_enabled','1','Abilita integrazione LinkedIn (1/0)'),
  ('linkedin_auto_create_catalog','1','Crea automaticamente brand+cert per badge sconosciuti'),
  ('linkedin_update_cv','1','Aggiorna bio/skills/CV durante import'),
  ('app_version','1.2.0','Versione applicazione'),
  ('schema_version','1.2.0','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;

// ════════════════════════════════════════════════════════════════════════
// UPGRADE 1.3.0 — Anagrafica estesa: email aziendale, telefoni, URL social
// ════════════════════════════════════════════════════════════════════════
$UPGRADE_SQL['1.3.0'] = <<<'SQL'
-- business_email
SET @col = (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema=DATABASE() AND table_name='employees' AND column_name='business_email');
SET @sql = IF(@col=0,
  'ALTER TABLE employees ADD COLUMN business_email VARCHAR(150) DEFAULT NULL COMMENT "Email aziendale" AFTER personal_email',
  'SELECT "business_email esiste" AS info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- phone_personal
SET @col = (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema=DATABASE() AND table_name='employees' AND column_name='phone_personal');
SET @sql = IF(@col=0,
  'ALTER TABLE employees ADD COLUMN phone_personal VARCHAR(30) DEFAULT NULL COMMENT "Telefono personale" AFTER phone',
  'SELECT "phone_personal esiste" AS info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- credly_url
SET @col = (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema=DATABASE() AND table_name='employees' AND column_name='credly_url');
SET @sql = IF(@col=0,
  'ALTER TABLE employees ADD COLUMN credly_url VARCHAR(255) DEFAULT NULL COMMENT "URL profilo pubblico Credly" AFTER cv_path',
  'SELECT "credly_url esiste" AS info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- linkedin_url
SET @col = (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema=DATABASE() AND table_name='employees' AND column_name='linkedin_url');
SET @sql = IF(@col=0,
  'ALTER TABLE employees ADD COLUMN linkedin_url VARCHAR(255) DEFAULT NULL COMMENT "URL profilo pubblico LinkedIn" AFTER credly_url',
  'SELECT "linkedin_url esiste" AS info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
  ('schema_version','1.3.0','Versione schema database'),
  ('app_version',   '1.3.0','Versione applicazione')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;


// ──────────────────────────────────────────────────────────────────────
// UPGRADE 1.4.0 — Scheda dipendente full-page + colonne istruzione
// ──────────────────────────────────────────────────────────────────────
$UPGRADE_SQL['1.4.0'] = <<<'SQL'
-- 4 colonne education allineate al candidato
SET @col = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='employees' AND column_name='education_level');
SET @sql = IF(@col=0,
  'ALTER TABLE employees ADD COLUMN education_level VARCHAR(100) DEFAULT NULL COMMENT "Titolo di studio" AFTER fiscal_code',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='employees' AND column_name='education_field');
SET @sql = IF(@col=0,
  'ALTER TABLE employees ADD COLUMN education_field VARCHAR(150) DEFAULT NULL COMMENT "Indirizzo/Facolt\u00e0" AFTER education_level',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='employees' AND column_name='education_institute');
SET @sql = IF(@col=0,
  'ALTER TABLE employees ADD COLUMN education_institute VARCHAR(200) DEFAULT NULL COMMENT "Istituto/Universit\u00e0" AFTER education_field',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='employees' AND column_name='education_year');
SET @sql = IF(@col=0,
  'ALTER TABLE employees ADD COLUMN education_year VARCHAR(10) DEFAULT NULL AFTER education_institute',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Permessi employee_profile.php (clone da manage_employees)
INSERT IGNORE INTO role_permissions (role_id, page_name, can_view, can_create, can_edit, can_delete, can_export)
SELECT role_id, 'employee_profile.php', can_view, can_create, can_edit, can_delete, can_export
  FROM role_permissions WHERE page_name = 'manage_employees.php';
INSERT IGNORE INTO role_permissions (role_id, page_name, can_view, can_create, can_edit, can_delete, can_export) VALUES
  (1, 'employee_profile.php', 1, 1, 1, 1, 1),
  (2, 'employee_profile.php', 1, 1, 1, 0, 1),
  (4, 'employee_profile.php', 1, 0, 0, 0, 0),
  (6, 'employee_profile.php', 1, 0, 1, 0, 0);

INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.4.0','Versione applicazione'),
  ('schema_version','1.4.0','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;


// ──────────────────────────────────────────────────────────────────────
// UPGRADE 1.4.3 — Fix RBAC permessi mancanti su API e sync
// ──────────────────────────────────────────────────────────────────────
$UPGRADE_SQL['1.4.3'] = <<<'SQL'
INSERT IGNORE INTO role_permissions (role_id, page_name, can_view, can_create, can_edit, can_delete, can_export) VALUES
  (1,'api_cert_codes.php',1,1,1,1,1),
  (2,'api_cert_codes.php',1,1,1,1,1),
  (3,'api_cert_codes.php',1,1,1,0,1),
  (4,'api_cert_codes.php',1,1,1,0,1),
  (5,'api_cert_codes.php',1,1,1,0,1),
  (6,'api_cert_codes.php',1,1,1,0,0),
  (7,'api_cert_codes.php',1,1,1,1,1),
  (1,'credly_sync.php',1,1,1,1,1),
  (2,'credly_sync.php',1,1,1,0,1),
  (1,'linkedin_sync.php',1,1,1,1,1),
  (2,'linkedin_sync.php',1,1,1,0,1);

INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.4.3','Versione applicazione'),
  ('schema_version','1.4.3','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;


// ──────────────────────────────────────────────────────────────────────
// UPGRADE 1.5.0 — Dispositivi aziendali (9 tabelle)
// ──────────────────────────────────────────────────────────────────────
$UPGRADE_SQL['1.5.0'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `emp_devices_phone` (
  `id` INT NOT NULL AUTO_INCREMENT, `employee_id` INT NOT NULL,
  `brand` VARCHAR(80) DEFAULT NULL, `model` VARCHAR(120) DEFAULT NULL,
  `imei_1` VARCHAR(40) DEFAULT NULL, `imei_2` VARCHAR(40) DEFAULT NULL,
  `serial_number` VARCHAR(80) DEFAULT NULL,
  `assigned_at` DATE DEFAULT NULL, `returned_at` DATE DEFAULT NULL,
  `status` ENUM('assegnato','restituito','smarrito','rotto') NOT NULL DEFAULT 'assegnato',
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, `created_by` INT DEFAULT NULL,
  PRIMARY KEY (`id`), KEY `idx_edp_emp` (`employee_id`),
  CONSTRAINT `fk_edp_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `emp_devices_sim` (
  `id` INT NOT NULL AUTO_INCREMENT, `employee_id` INT NOT NULL,
  `sim_type` ENUM('voce','dati') NOT NULL DEFAULT 'voce',
  `phone_number` VARCHAR(40) DEFAULT NULL, `serial_number` VARCHAR(80) DEFAULT NULL,
  `pin_code` VARCHAR(40) DEFAULT NULL, `puk_code` VARCHAR(40) DEFAULT NULL,
  `operator` VARCHAR(80) DEFAULT NULL,
  `assigned_at` DATE DEFAULT NULL, `returned_at` DATE DEFAULT NULL,
  `status` ENUM('attiva','disattiva','smarrita','sostituita') NOT NULL DEFAULT 'attiva',
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, `created_by` INT DEFAULT NULL,
  PRIMARY KEY (`id`), KEY `idx_eds_emp` (`employee_id`, `sim_type`),
  CONSTRAINT `fk_eds_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `emp_devices_notebook` (
  `id` INT NOT NULL AUTO_INCREMENT, `employee_id` INT NOT NULL,
  `brand` VARCHAR(80) DEFAULT NULL, `model` VARCHAR(120) DEFAULT NULL,
  `serial_number` VARCHAR(80) DEFAULT NULL, `specs` TEXT DEFAULT NULL,
  `os` VARCHAR(80) DEFAULT NULL,
  `assigned_at` DATE DEFAULT NULL, `returned_at` DATE DEFAULT NULL,
  `status` ENUM('assegnato','restituito','smarrito','rotto','in_riparazione') NOT NULL DEFAULT 'assegnato',
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, `created_by` INT DEFAULT NULL,
  PRIMARY KEY (`id`), KEY `idx_edn_emp` (`employee_id`),
  CONSTRAINT `fk_edn_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `emp_devices_vehicle` (
  `id` INT NOT NULL AUTO_INCREMENT, `employee_id` INT NOT NULL,
  `brand` VARCHAR(80) DEFAULT NULL, `model` VARCHAR(120) DEFAULT NULL,
  `plate` VARCHAR(20) DEFAULT NULL, `fuel_type` VARCHAR(40) DEFAULT NULL,
  `acquisition_type` ENUM('noleggio','leasing','finanziamento','acquisto_diretto','altro') NOT NULL DEFAULT 'noleggio',
  `contract_ref` VARCHAR(120) DEFAULT NULL,
  `contract_start` DATE DEFAULT NULL, `contract_end` DATE DEFAULT NULL,
  `monthly_cost` DECIMAL(10,2) DEFAULT NULL, `conditions` TEXT DEFAULT NULL,
  `initial_km` INT DEFAULT NULL, `current_km` INT DEFAULT NULL,
  `assigned_at` DATE DEFAULT NULL, `returned_at` DATE DEFAULT NULL,
  `status` ENUM('assegnato','restituito','incidente','rotto') NOT NULL DEFAULT 'assegnato',
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, `created_by` INT DEFAULT NULL,
  PRIMARY KEY (`id`), KEY `idx_edv_emp` (`employee_id`),
  CONSTRAINT `fk_edv_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `emp_vehicle_service` (
  `id` INT NOT NULL AUTO_INCREMENT, `vehicle_id` INT NOT NULL,
  `service_date` DATE NOT NULL, `km` INT DEFAULT NULL,
  `cost` DECIMAL(10,2) DEFAULT NULL, `description` TEXT DEFAULT NULL,
  `document_path` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, `created_by` INT DEFAULT NULL,
  PRIMARY KEY (`id`), KEY `idx_evs_vehicle` (`vehicle_id`, `service_date`),
  CONSTRAINT `fk_evs_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `emp_devices_vehicle`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `emp_devices_fuel_card` (
  `id` INT NOT NULL AUTO_INCREMENT, `employee_id` INT NOT NULL,
  `circuit` VARCHAR(80) DEFAULT NULL, `card_number` VARCHAR(40) DEFAULT NULL,
  `pin_code` VARCHAR(40) DEFAULT NULL, `vehicle_id` INT DEFAULT NULL,
  `assigned_at` DATE DEFAULT NULL, `returned_at` DATE DEFAULT NULL,
  `status` ENUM('attiva','disattiva','smarrita','bloccata') NOT NULL DEFAULT 'attiva',
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, `created_by` INT DEFAULT NULL,
  PRIMARY KEY (`id`), KEY `idx_edfc_emp` (`employee_id`),
  CONSTRAINT `fk_edfc_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_edfc_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `emp_devices_vehicle`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `emp_fuel_log` (
  `id` INT NOT NULL AUTO_INCREMENT, `fuel_card_id` INT NOT NULL,
  `refuel_date` DATE NOT NULL, `km` INT DEFAULT NULL,
  `liters` DECIMAL(8,2) DEFAULT NULL, `amount` DECIMAL(10,2) DEFAULT NULL,
  `location` VARCHAR(120) DEFAULT NULL, `document_path` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, `created_by` INT DEFAULT NULL,
  PRIMARY KEY (`id`), KEY `idx_efl_card` (`fuel_card_id`, `refuel_date`),
  CONSTRAINT `fk_efl_card` FOREIGN KEY (`fuel_card_id`) REFERENCES `emp_devices_fuel_card`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `emp_devices_credit_card` (
  `id` INT NOT NULL AUTO_INCREMENT, `employee_id` INT NOT NULL,
  `circuit` VARCHAR(40) DEFAULT NULL, `card_number_last4` VARCHAR(8) DEFAULT NULL,
  `pin_code` VARCHAR(40) DEFAULT NULL, `bank` VARCHAR(120) DEFAULT NULL,
  `credit_limit` DECIMAL(10,2) DEFAULT NULL,
  `assigned_at` DATE DEFAULT NULL, `returned_at` DATE DEFAULT NULL,
  `status` ENUM('attiva','disattiva','smarrita','bloccata') NOT NULL DEFAULT 'attiva',
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, `created_by` INT DEFAULT NULL,
  PRIMARY KEY (`id`), KEY `idx_edcc_emp` (`employee_id`),
  CONSTRAINT `fk_edcc_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `emp_credit_card_statement` (
  `id` INT NOT NULL AUTO_INCREMENT, `credit_card_id` INT NOT NULL,
  `period_year` SMALLINT NOT NULL, `period_month` TINYINT NOT NULL,
  `total_amount` DECIMAL(10,2) DEFAULT NULL,
  `document_path` VARCHAR(255) DEFAULT NULL, `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, `created_by` INT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_eccs_period` (`credit_card_id`, `period_year`, `period_month`),
  KEY `idx_eccs_card` (`credit_card_id`),
  CONSTRAINT `fk_eccs_card` FOREIGN KEY (`credit_card_id`) REFERENCES `emp_devices_credit_card`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_permissions (role_id, page_name, can_view, can_create, can_edit, can_delete, can_export) VALUES
  (1,'device_manager.php',1,1,1,1,1),
  (2,'device_manager.php',1,1,1,0,1);

INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.5.0','Versione applicazione'),
  ('schema_version','1.5.0','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;


// ──────────────────────────────────────────────────────────────────────
// UPGRADE 1.5.1 — Permessi RBAC per endpoint export/print/import
// ──────────────────────────────────────────────────────────────────────
$UPGRADE_SQL['1.5.1'] = <<<'SQL'
INSERT IGNORE INTO role_permissions (role_id, page_name, can_view, can_create, can_edit, can_delete, can_export) VALUES
  (1,'device_export.php',1,0,0,0,1),
  (2,'device_export.php',1,0,0,0,1),
  (4,'device_export.php',1,0,0,0,1),
  (1,'device_print.php', 1,0,0,0,1),
  (2,'device_print.php', 1,0,0,0,1),
  (4,'device_print.php', 1,0,0,0,1),
  (1,'device_import.php',1,1,1,0,0),
  (2,'device_import.php',1,1,1,0,0);

INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.5.1','Versione applicazione'),
  ('schema_version','1.5.1','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;


// ──────────────────────────────────────────────────────────────────────
// UPGRADE 1.5.4 — Fix critico salvataggio dispositivi (solo bump versione,
// il fix vero è nel file employee_profile.php)
// ──────────────────────────────────────────────────────────────────────
$UPGRADE_SQL['1.5.4'] = <<<'SQL'
INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.5.4','Versione applicazione'),
  ('schema_version','1.5.4','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;


// ──────────────────────────────────────────────────────────────────────
// UPGRADE 1.6.0 — CV Europass (lingue, foto profilo, preferenze CV)
// ──────────────────────────────────────────────────────────────────────
$UPGRADE_SQL['1.6.0'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `emp_languages` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `employee_id` INT NOT NULL,
  `language_name` VARCHAR(60) NOT NULL,
  `mother_tongue` TINYINT(1) NOT NULL DEFAULT 0,
  `level_listening` ENUM('A1','A2','B1','B2','C1','C2') DEFAULT NULL,
  `level_reading` ENUM('A1','A2','B1','B2','C1','C2') DEFAULT NULL,
  `level_spoken_interaction` ENUM('A1','A2','B1','B2','C1','C2') DEFAULT NULL,
  `level_spoken_production` ENUM('A1','A2','B1','B2','C1','C2') DEFAULT NULL,
  `level_writing` ENUM('A1','A2','B1','B2','C1','C2') DEFAULT NULL,
  `certification` VARCHAR(120) DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT DEFAULT NULL,
  PRIMARY KEY (`id`), KEY `idx_el_emp` (`employee_id`),
  CONSTRAINT `fk_el_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='employees' AND column_name='photo_path');
SET @sql = IF(@col=0,
  'ALTER TABLE employees ADD COLUMN photo_path VARCHAR(255) DEFAULT NULL COMMENT "Foto profilo CV" AFTER bio',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS `emp_cv_preferences` (
  `employee_id` INT NOT NULL PRIMARY KEY,
  `include_personal` TINYINT(1) NOT NULL DEFAULT 1,
  `include_photo` TINYINT(1) NOT NULL DEFAULT 1,
  `include_experience` TINYINT(1) NOT NULL DEFAULT 1,
  `include_education` TINYINT(1) NOT NULL DEFAULT 1,
  `include_technical_skills` TINYINT(1) NOT NULL DEFAULT 1,
  `include_soft_skills` TINYINT(1) NOT NULL DEFAULT 1,
  `include_certifications` TINYINT(1) NOT NULL DEFAULT 1,
  `include_languages` TINYINT(1) NOT NULL DEFAULT 1,
  `include_devices` TINYINT(1) NOT NULL DEFAULT 0,
  `include_bio` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_ecvp_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_permissions (role_id, page_name, can_view, can_create, can_edit, can_delete, can_export) VALUES
  (1,'employee_cv.php',1,1,1,1,1),
  (2,'employee_cv.php',1,1,1,0,1),
  (4,'employee_cv.php',1,0,0,0,1),
  (5,'employee_cv.php',1,0,0,0,1),
  (6,'employee_cv.php',1,0,1,0,1);

INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.6.0','Versione applicazione'),
  ('schema_version','1.6.0','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;


// ──────────────────────────────────────────────────────────────────────
// UPGRADE 1.6.1 — Gestione Dispositivi (vista globale)
// ──────────────────────────────────────────────────────────────────────
$UPGRADE_SQL['1.6.1'] = <<<'SQL'
INSERT IGNORE INTO role_permissions (role_id, page_name, can_view, can_create, can_edit, can_delete, can_export) VALUES
  (1,'device_manager.php',1,1,1,1,1),
  (2,'device_manager.php',1,1,1,0,1),
  (4,'device_manager.php',1,0,0,0,1);

INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.6.1','Versione applicazione'),
  ('schema_version','1.6.1','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;


// ──────────────────────────────────────────────────────────────────────
// UPGRADE 1.6.2 — SQL Runner UI
// ──────────────────────────────────────────────────────────────────────
$UPGRADE_SQL['1.6.2'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `sql_migrations_log` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `script_name` VARCHAR(255) NOT NULL,
  `script_hash` CHAR(64) NOT NULL,
  `script_size` INT NOT NULL,
  `statements_total` INT NOT NULL DEFAULT 0,
  `statements_ok` INT NOT NULL DEFAULT 0,
  `status` ENUM('success','partial','failed','rolled_back') NOT NULL,
  `error_message` TEXT DEFAULT NULL,
  `execution_log` MEDIUMTEXT DEFAULT NULL,
  `executed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `executed_by` INT DEFAULT NULL,
  `duration_ms` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_sml_name` (`script_name`),
  KEY `idx_sml_hash` (`script_hash`),
  KEY `idx_sml_date` (`executed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_permissions (role_id, page_name, can_view, can_create, can_edit, can_delete, can_export) VALUES
  (1,'sql_runner.php',1,1,1,1,1);

INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.6.2','Versione applicazione'),
  ('schema_version','1.6.2','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;




// ──────────────────────────────────────────────────────────────────────
// UPGRADE 1.6.4 — File Manager interno (Super Admin)
// ──────────────────────────────────────────────────────────────────────
$UPGRADE_SQL['1.6.4'] = <<<'SQL'
INSERT IGNORE INTO role_permissions (role_id, page_name, can_view, can_create, can_edit, can_delete, can_export) VALUES
  (1,'file_manager.php',1,1,1,1,1);

INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.6.4','Versione applicazione'),
  ('schema_version','1.6.4','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;


// ──────────────────────────────────────────────────────────────────────
// UPGRADE 1.6.5/1.6.6/1.6.7 — Solo PHP fix, bump versione
// ──────────────────────────────────────────────────────────────────────
$UPGRADE_SQL['1.6.5'] = <<<'SQL'
INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.6.5','Versione applicazione'),
  ('schema_version','1.6.5','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;

$UPGRADE_SQL['1.6.6'] = <<<'SQL'
INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.6.6','Versione applicazione'),
  ('schema_version','1.6.6','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;

$UPGRADE_SQL['1.6.7'] = <<<'SQL'
INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.6.7','Versione applicazione'),
  ('schema_version','1.6.7','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;


// ──────────────────────────────────────────────────────────────────────
// UPGRADE 1.6.8 — Credly offline import (settings proxy + permessi)
// ──────────────────────────────────────────────────────────────────────
$UPGRADE_SQL['1.6.8'] = <<<'SQL'
INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('credly_proxy_url',     '', 'URL proxy HTTP/SOCKS5 per chiamate a Credly. Vuoto = diretto.'),
  ('credly_proxy_userpwd', '', 'Credenziali user:password per il proxy Credly (se richieste).')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, page_name, can_view, can_create, can_edit, can_delete, can_export) VALUES
  (1,'credly_manual_import.php',1,1,1,1,1),
  (2,'credly_manual_import.php',1,1,1,0,1);

INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.6.8','Versione applicazione'),
  ('schema_version','1.6.8','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;


// ──────────────────────────────────────────────────────────────────────
// UPGRADE 1.6.9/1.7.0/1.7.1/1.7.2 — Solo PHP fix
// ──────────────────────────────────────────────────────────────────────
$UPGRADE_SQL['1.6.9'] = <<<'SQL'
INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.6.9','Versione applicazione'),
  ('schema_version','1.6.9','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;

$UPGRADE_SQL['1.7.0'] = <<<'SQL'
INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.7.0','Versione applicazione'),
  ('schema_version','1.7.0','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;

$UPGRADE_SQL['1.7.1'] = <<<'SQL'
INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.7.1','Versione applicazione'),
  ('schema_version','1.7.1','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;

$UPGRADE_SQL['1.7.2'] = <<<'SQL'
INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.7.2','Versione applicazione'),
  ('schema_version','1.7.2','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;


// ──────────────────────────────────────────────────────────────────────
// UPGRADE 1.7.3 — Colonne cv_template + cv_anonymize in emp_cv_preferences
// ──────────────────────────────────────────────────────────────────────
$UPGRADE_SQL['1.7.3'] = <<<'SQL'
SET @col = (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema=DATABASE() AND table_name='emp_cv_preferences' AND column_name='cv_template');
SET @sql = IF(@col=0,
  'ALTER TABLE emp_cv_preferences ADD COLUMN cv_template ENUM("classic","modern","technical") NOT NULL DEFAULT "classic" COMMENT "Modello CV di default"',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema=DATABASE() AND table_name='emp_cv_preferences' AND column_name='cv_anonymize');
SET @sql = IF(@col=0,
  'ALTER TABLE emp_cv_preferences ADD COLUMN cv_anonymize TINYINT(1) NOT NULL DEFAULT 0 COMMENT "Anonimizza dati sensibili"',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.7.3','Versione applicazione'),
  ('schema_version','1.7.3','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;


// ──────────────────────────────────────────────────────────────────────
// UPGRADE 1.7.4 — CV import (permessi)
// ──────────────────────────────────────────────────────────────────────
$UPGRADE_SQL['1.7.4'] = <<<'SQL'
INSERT IGNORE INTO role_permissions (role_id, page_name, can_view, can_create, can_edit, can_delete, can_export) VALUES
  (1,'cv_import.php',1,1,1,1,1),
  (2,'cv_import.php',1,1,1,0,1),
  (5,'cv_import.php',1,1,0,0,0);

INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.7.4','Versione applicazione'),
  ('schema_version','1.7.4','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;


// ──────────────────────────────────────────────────────────────────────
// UPGRADE 1.7.5 — Solo PHP fix (collegamento documenti candidato→dipendente)
// ──────────────────────────────────────────────────────────────────────
$UPGRADE_SQL['1.7.5'] = <<<'SQL'
INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.7.5','Versione applicazione'),
  ('schema_version','1.7.5','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;


// ─── 1.7.6 → 1.7.8: solo bump versione (no schema change) ───
$UPGRADE_SQL['1.7.6'] = <<<'SQL'
INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.7.6','Versione applicazione'),
  ('schema_version','1.7.6','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;

$UPGRADE_SQL['1.7.7'] = <<<'SQL'
INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.7.7','Versione applicazione'),
  ('schema_version','1.7.7','Versione schema database')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;

$UPGRADE_SQL['1.7.8'] = <<<'SQL'
INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.7.8','Versione applicazione'),
  ('schema_version','1.7.8','Versione schema database'),
  ('release_label','1.7.8','Etichetta release mostrata in footer')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;


// ─── 1.7.9: tabella menu_preferences + permessi menu_customizer ───
$UPGRADE_SQL['1.7.10'] = <<<'SQL'
INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.7.10','Versione applicazione'),
  ('schema_version','1.7.10','Versione schema database'),
  ('release_label','1.7.10','Etichetta release mostrata in footer')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;


// ─── v1.7.11: tabelle emp_education, emp_experiences, device_handovers ───
$UPGRADE_SQL['1.7.11'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS emp_education (
  id INT NOT NULL AUTO_INCREMENT,
  employee_id INT NOT NULL,
  level VARCHAR(120) DEFAULT NULL,
  field VARCHAR(180) DEFAULT NULL,
  institute VARCHAR(200) DEFAULT NULL,
  year_from SMALLINT DEFAULT NULL,
  year_to SMALLINT DEFAULT NULL,
  grade VARCHAR(60) DEFAULT NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 0,
  notes TEXT DEFAULT NULL,
  sort_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  created_by INT DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_ee_emp (employee_id),
  CONSTRAINT fk_ee_emp FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emp_experiences (
  id INT NOT NULL AUTO_INCREMENT,
  employee_id INT NOT NULL,
  period_from DATE DEFAULT NULL,
  period_to DATE DEFAULT NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 0,
  job_title VARCHAR(180) DEFAULT NULL,
  company VARCHAR(200) DEFAULT NULL,
  location VARCHAR(180) DEFAULT NULL,
  contract_type VARCHAR(80) DEFAULT NULL,
  description TEXT DEFAULT NULL,
  sort_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  created_by INT DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_ex_emp (employee_id),
  CONSTRAINT fk_ex_emp FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_handovers (
  id INT NOT NULL AUTO_INCREMENT,
  employee_id INT NOT NULL,
  handover_type ENUM('delivery','return','combined') NOT NULL DEFAULT 'combined',
  delivery_date DATE DEFAULT NULL,
  return_date DATE DEFAULT NULL,
  device_list LONGTEXT NOT NULL,
  delivery_notes TEXT DEFAULT NULL,
  return_notes TEXT DEFAULT NULL,
  status ENUM('draft','delivered','returned','closed') NOT NULL DEFAULT 'draft',
  file_path VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  created_by INT NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_dh_emp (employee_id),
  KEY idx_dh_status (status),
  CONSTRAINT fk_dh_emp FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_permissions (role_id, page_name, can_view, can_create, can_edit, can_delete, can_export) VALUES
  (1,'device_handover.php',1,1,1,1,1),
  (2,'device_handover.php',1,1,1,0,1),
  (4,'device_handover.php',1,1,1,0,1);

INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.7.11','Versione applicazione'),
  ('schema_version','1.7.11','Versione schema database'),
  ('release_label','1.7.11','Etichetta release mostrata in footer')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;

// ─── v1.7.12: solo bump versione ───
$UPGRADE_SQL['1.7.12'] = <<<'SQL'
INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.7.12','Versione applicazione'),
  ('schema_version','1.7.12','Versione schema database'),
  ('release_label','1.7.12','Etichetta release mostrata in footer')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;

// ─── v1.7.13: solo bump versione (auto-bump da Version.php) ───
$UPGRADE_SQL['1.7.13'] = <<<'SQL'
INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.7.13','Versione applicazione'),
  ('schema_version','1.7.13','Versione schema database'),
  ('release_label','1.7.13','Etichetta release mostrata in footer')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;

// ─── v1.7.14: bump versione + menu Recruiting completato ───
$UPGRADE_SQL['1.7.14'] = <<<'SQL'
INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.7.14','Versione applicazione'),
  ('schema_version','1.7.14','Versione schema database'),
  ('release_label','1.7.14','Etichetta release mostrata in footer')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;

// ─── v1.7.15: estensione ENUM cv_template + bump ───
$UPGRADE_SQL['1.7.15'] = <<<'SQL'
SET @col = (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema=DATABASE() AND table_name='emp_cv_preferences' AND column_name='cv_template');
SET @sql = IF(@col=1,
  'ALTER TABLE emp_cv_preferences MODIFY COLUMN cv_template ENUM("classic","modern","technical","europass") NOT NULL DEFAULT "classic" COMMENT "Modello CV"',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.7.15','Versione applicazione'),
  ('schema_version','1.7.15','Versione schema database'),
  ('release_label','1.7.15','Etichetta release mostrata in footer')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;

// ─── v1.7.16: fix versioning (auto-bump in Config.php) ───
$UPGRADE_SQL['1.7.16'] = <<<'SQL'
INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.7.16','Versione applicazione'),
  ('schema_version','1.7.16','Versione schema database'),
  ('release_label','1.7.16','Etichetta release mostrata in footer')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;

// ─── v1.7.17: template Europass fedele (solo bump, no schema change) ───
$UPGRADE_SQL['1.7.17'] = <<<'SQL'
INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.7.17','Versione applicazione'),
  ('schema_version','1.7.17','Versione schema database'),
  ('release_label','1.7.17','Etichetta release mostrata in footer')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;

// ─── v1.7.18: db_upgrade.php aggiornato (solo bump) ───
$UPGRADE_SQL['1.7.18'] = <<<'SQL'
INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.7.18','Versione applicazione'),
  ('schema_version','1.7.18','Versione schema database'),
  ('release_label','1.7.18','Etichetta release mostrata in footer')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;

// ─── v1.7.20-22: solo bump versione ───
$UPGRADE_SQL['1.7.20'] = "INSERT INTO app_settings (setting_key,setting_value,description) VALUES ('app_version','1.7.20',''),('schema_version','1.7.20',''),('release_label','1.7.20','') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);";
$UPGRADE_SQL['1.7.21'] = "INSERT INTO app_settings (setting_key,setting_value,description) VALUES ('app_version','1.7.21',''),('schema_version','1.7.21',''),('release_label','1.7.21','') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);";
$UPGRADE_SQL['1.7.22'] = "INSERT INTO app_settings (setting_key,setting_value,description) VALUES ('app_version','1.7.22',''),('schema_version','1.7.22',''),('release_label','1.7.22','') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);";

// ─── v1.7.23: modulo Progetti & Referenze (schema completo) ───
// v1.8.7: lettura sicura delle migrazioni — i file storici gia' applicati possono
// non essere piu' presenti su disco; in tal caso si registra stringa vuota senza warning.
if (!function_exists('pm_migration_sql')) {
    function pm_migration_sql(string $file): string {
        $path = __DIR__ . '/sql/' . $file;
        return is_file($path) ? (file_get_contents($path) ?: '') : '';
    }
}

$UPGRADE_SQL['1.7.23'] = pm_migration_sql('migration_v1_7_23.sql');
$UPGRADE_SQL['1.7.24'] = pm_migration_sql('migration_v1_7_24.sql');
$UPGRADE_SQL['1.7.25'] = pm_migration_sql('migration_v1_7_25.sql');
$UPGRADE_SQL['1.7.26'] = pm_migration_sql('migration_v1_7_26.sql');
$UPGRADE_SQL['1.7.27'] = pm_migration_sql('migration_v1_7_27.sql');
$UPGRADE_SQL['1.7.28'] = pm_migration_sql('migration_v1_7_28.sql');
$UPGRADE_SQL['1.7.29'] = pm_migration_sql('migration_v1_7_29.sql');
$UPGRADE_SQL['1.7.30'] = pm_migration_sql('migration_v1_7_30.sql');
$UPGRADE_SQL['1.7.31'] = pm_migration_sql('migration_v1_7_31.sql');
$UPGRADE_SQL['1.7.32'] = pm_migration_sql('migration_v1_7_32.sql');
$UPGRADE_SQL['1.7.33'] = pm_migration_sql('migration_v1_7_33.sql');
$UPGRADE_SQL['1.7.36'] = pm_migration_sql('migration_v1_7_36.sql');
$UPGRADE_SQL['1.7.37'] = pm_migration_sql('migration_v1_7_37.sql');
$UPGRADE_SQL['1.7.38'] = pm_migration_sql('migration_v1_7_38.sql');
$UPGRADE_SQL['1.7.39'] = pm_migration_sql('migration_v1_7_39.sql');
$UPGRADE_SQL['1.7.40'] = pm_migration_sql('migration_v1_7_40.sql');
$UPGRADE_SQL['1.7.41'] = pm_migration_sql('migration_v1_7_41.sql');
$UPGRADE_SQL['1.7.42'] = pm_migration_sql('migration_v1_7_42.sql');
$UPGRADE_SQL['1.7.43'] = pm_migration_sql('migration_v1_7_43.sql');
$UPGRADE_SQL['1.7.44'] = pm_migration_sql('migration_v1_7_44.sql');
$UPGRADE_SQL['1.7.45'] = pm_migration_sql('migration_v1_7_45.sql');
$UPGRADE_SQL['1.7.46'] = pm_migration_sql('migration_v1_7_46.sql');
$UPGRADE_SQL['1.7.47'] = pm_migration_sql('migration_v1_7_47.sql');
$UPGRADE_SQL['1.7.48'] = pm_migration_sql('migration_v1_7_48.sql');
$UPGRADE_SQL['1.7.49'] = pm_migration_sql('migration_v1_7_49.sql');
$UPGRADE_SQL['1.7.50'] = pm_migration_sql('migration_v1_7_50.sql');
$UPGRADE_SQL['1.7.51'] = pm_migration_sql('migration_v1_7_51.sql');
$UPGRADE_SQL['1.7.52'] = pm_migration_sql('migration_v1_7_52.sql');
$UPGRADE_SQL['1.7.53'] = pm_migration_sql('migration_v1_7_53.sql');
$UPGRADE_SQL['1.7.54'] = pm_migration_sql('migration_v1_7_54.sql');
$UPGRADE_SQL['1.7.55'] = pm_migration_sql('migration_v1_7_55.sql');
$UPGRADE_SQL['1.7.56'] = pm_migration_sql('migration_v1_7_56.sql');
$UPGRADE_SQL['1.7.57'] = pm_migration_sql('migration_v1_7_57.sql');
$UPGRADE_SQL['1.7.58'] = pm_migration_sql('migration_v1_7_58.sql');
$UPGRADE_SQL['1.7.59'] = pm_migration_sql('migration_v1_7_59.sql');
$UPGRADE_SQL['1.7.60'] = pm_migration_sql('migration_v1_7_60.sql');
$UPGRADE_SQL['1.7.61'] = pm_migration_sql('migration_v1_7_61.sql');
$UPGRADE_SQL['1.7.62'] = pm_migration_sql('migration_v1_7_62.sql');
$UPGRADE_SQL['1.7.63'] = pm_migration_sql('migration_v1_7_63.sql');
$UPGRADE_SQL['1.7.64'] = pm_migration_sql('migration_v1_7_64.sql');
$UPGRADE_SQL['1.7.65'] = pm_migration_sql('migration_v1_7_65.sql');
$UPGRADE_SQL['1.7.66'] = pm_migration_sql('migration_v1_7_66.sql');
$UPGRADE_SQL['1.7.67'] = pm_migration_sql('migration_v1_7_67.sql');
$UPGRADE_SQL['1.7.68'] = pm_migration_sql('migration_v1_7_68.sql');
$UPGRADE_SQL['1.7.69'] = pm_migration_sql('migration_v1_7_69.sql');
$UPGRADE_SQL['1.7.70'] = pm_migration_sql('migration_v1_7_70.sql');
$UPGRADE_SQL['1.7.71'] = pm_migration_sql('migration_v1_7_71.sql');
$UPGRADE_SQL['1.7.72'] = pm_migration_sql('migration_v1_7_72.sql');
$UPGRADE_SQL['1.7.73'] = pm_migration_sql('migration_v1_7_73.sql');
$UPGRADE_SQL['1.7.74'] = pm_migration_sql('migration_v1_7_74.sql');
$UPGRADE_SQL['1.7.75'] = pm_migration_sql('migration_v1_7_75.sql');
$UPGRADE_SQL['1.7.76'] = pm_migration_sql('migration_v1_7_76.sql');
$UPGRADE_SQL['1.7.77'] = pm_migration_sql('migration_v1_7_77.sql');
$UPGRADE_SQL['1.7.78'] = pm_migration_sql('migration_v1_7_78.sql');
$UPGRADE_SQL['1.7.79'] = pm_migration_sql('migration_v1_7_79.sql');
$UPGRADE_SQL['1.7.80'] = pm_migration_sql('migration_v1_7_80.sql');
$UPGRADE_SQL['1.7.81'] = pm_migration_sql('migration_v1_7_81.sql');
$UPGRADE_SQL['1.7.82'] = pm_migration_sql('migration_v1_7_82.sql');
$UPGRADE_SQL['1.7.83'] = pm_migration_sql('migration_v1_7_83.sql');
$UPGRADE_SQL['1.7.84'] = pm_migration_sql('migration_v1_7_84.sql');
$UPGRADE_SQL['1.7.85'] = pm_migration_sql('migration_v1_7_85.sql');
$UPGRADE_SQL['1.7.86'] = pm_migration_sql('migration_v1_7_86.sql');
$UPGRADE_SQL['1.7.87'] = pm_migration_sql('migration_v1_7_87.sql');
$UPGRADE_SQL['1.7.88'] = pm_migration_sql('migration_v1_7_88.sql');
$UPGRADE_SQL['1.7.89'] = pm_migration_sql('migration_v1_7_89.sql');
$UPGRADE_SQL['1.7.90'] = pm_migration_sql('migration_v1_7_90.sql');
$UPGRADE_SQL['1.7.91'] = pm_migration_sql('migration_v1_7_91.sql');
$UPGRADE_SQL['1.7.92'] = pm_migration_sql('migration_v1_7_92.sql');
$UPGRADE_SQL['1.7.93'] = pm_migration_sql('migration_v1_7_93.sql');
$UPGRADE_SQL['1.7.94'] = pm_migration_sql('migration_v1_7_94.sql');
$UPGRADE_SQL['1.7.95'] = pm_migration_sql('migration_v1_7_95.sql');
$UPGRADE_SQL['1.7.96'] = pm_migration_sql('migration_v1_7_96.sql');
$UPGRADE_SQL['1.7.97'] = pm_migration_sql('migration_v1_7_97.sql');
$UPGRADE_SQL['1.7.98'] = pm_migration_sql('migration_v1_7_98.sql');
$UPGRADE_SQL['1.7.99'] = pm_migration_sql('migration_v1_7_99.sql');
$UPGRADE_SQL['1.8.0'] = pm_migration_sql('migration_v1_8_0.sql');
$UPGRADE_SQL['1.8.1'] = pm_migration_sql('migration_v1_8_1.sql');
$UPGRADE_SQL['1.8.2'] = pm_migration_sql('migration_v1_8_2.sql');
$UPGRADE_SQL['1.8.3'] = pm_migration_sql('migration_v1_8_3.sql');
$UPGRADE_SQL['1.8.4'] = pm_migration_sql('migration_v1_8_4.sql');
$UPGRADE_SQL['1.8.5'] = pm_migration_sql('migration_v1_8_5.sql');
$UPGRADE_SQL['1.8.6'] = pm_migration_sql('migration_v1_8_6.sql');
$UPGRADE_SQL['1.8.7'] = pm_migration_sql('migration_v1_8_7.sql');
$UPGRADE_SQL['1.8.8'] = pm_migration_sql('migration_v1_8_8.sql');
$UPGRADE_SQL['1.8.9'] = pm_migration_sql('migration_v1_8_9.sql');
$UPGRADE_SQL['1.8.10'] = pm_migration_sql('migration_v1_8_10.sql');
$UPGRADE_SQL['1.8.11'] = pm_migration_sql('migration_v1_8_11.sql');
$UPGRADE_SQL['1.8.12'] = pm_migration_sql('migration_v1_8_12.sql');
$UPGRADE_SQL['1.8.13'] = pm_migration_sql('migration_v1_8_13.sql');
$UPGRADE_SQL['1.8.14'] = pm_migration_sql('migration_v1_8_14.sql');
$UPGRADE_SQL['1.8.15'] = pm_migration_sql('migration_v1_8_15.sql');
$UPGRADE_SQL['1.8.16'] = pm_migration_sql('migration_v1_8_16.sql');
$UPGRADE_SQL['1.8.17'] = pm_migration_sql('migration_v1_8_17.sql');
$UPGRADE_SQL['1.8.18'] = pm_migration_sql('migration_v1_8_18.sql');
$UPGRADE_SQL['1.8.19'] = pm_migration_sql('migration_v1_8_19.sql');
$UPGRADE_SQL['1.8.20'] = pm_migration_sql('migration_v1_8_20.sql');
$UPGRADE_SQL['1.8.21'] = pm_migration_sql('migration_v1_8_21.sql');
$UPGRADE_SQL['1.8.22'] = pm_migration_sql('migration_v1_8_22.sql');
$UPGRADE_SQL['1.8.23'] = pm_migration_sql('migration_v1_8_23.sql');
$UPGRADE_SQL['1.8.24'] = pm_migration_sql('migration_v1_8_24.sql');
$UPGRADE_SQL['1.8.25'] = pm_migration_sql('migration_v1_8_25.sql');
$UPGRADE_SQL['1.8.26'] = pm_migration_sql('migration_v1_8_26.sql');
$UPGRADE_SQL['1.8.27'] = pm_migration_sql('migration_v1_8_27.sql');
$UPGRADE_SQL['1.8.28'] = pm_migration_sql('migration_v1_8_28.sql');
$UPGRADE_SQL['1.8.29'] = pm_migration_sql('migration_v1_8_29.sql');
$UPGRADE_SQL['1.8.30'] = pm_migration_sql('migration_v1_8_30.sql');
$UPGRADE_SQL['1.8.31'] = pm_migration_sql('migration_v1_8_31.sql');
$UPGRADE_SQL['1.8.32'] = pm_migration_sql('migration_v1_8_32.sql');
$UPGRADE_SQL['1.8.33'] = pm_migration_sql('migration_v1_8_33.sql');
$UPGRADE_SQL['1.8.34'] = pm_migration_sql('migration_v1_8_34.sql');
$UPGRADE_SQL['1.8.35'] = pm_migration_sql('migration_v1_8_35.sql');
$UPGRADE_SQL['1.8.36'] = pm_migration_sql('migration_v1_8_36.sql');
$UPGRADE_SQL['1.8.37'] = pm_migration_sql('migration_v1_8_37.sql');
$UPGRADE_SQL['1.8.38'] = pm_migration_sql('migration_v1_8_38.sql');
$UPGRADE_SQL['1.8.39'] = pm_migration_sql('migration_v1_8_39.sql');

// ══════════════════════════════════════════════════════════════════════════════
// v1.7.63 — Ordine cronologico delle versioni, AUTO-MANUTENUTO.
//
// Difetto corretto: l'ordine era una lista hardcoded che si fermava a '1.7.57'.
// Le release successive risultavano sconosciute (indice PHP_INT_MAX, quindi tutte
// "equivalenti"), non entravano mai in $to_apply e — soprattutto — il target di
// default restava '1.7.57', che veniva scritto in app_settings.app_version:
// eseguendo l'upgrade a 1.7.61 il DB REGREDIVA a 1.7.57.
//
// La parte storica resta esplicita perché dopo la v5.9 il portale è stato
// rinominato in PortalManager e il versioning è ripartito da 1.0.0: quindi
// 1.0.0 è SUCCESSIVO a 5.9 e version_compare() non può saperlo. Tutte le
// versioni nuove appartengono alla serie 1.7.x post-rinomina e vengono
// accodate automaticamente in ordine naturale: nessuna manutenzione manuale.
// ══════════════════════════════════════════════════════════════════════════════
function pm_version_order(): array {
    static $order = null;
    if ($order !== null) return $order;
    global $UPGRADE_SQL, $VERSIONS;

    // Era PRE-rinomina (certV): serie 2.x / 4.x / 5.x. Precede tutta la serie 1.x.
    $legacy_pre = ['2.2','2.3','2.4','4.0','4.1','5.0','5.4','5.5','5.7','5.8','5.9'];

    // Era POST-rinomina (PortalManager): il versioning è ripartito da 1.0.0,
    // quindi 1.0.0 è SUCCESSIVO a 5.9 — version_compare() non può saperlo.
    $legacy_mod = [
        '1.0.0','1.0.1','1.1.0','1.1.3','1.2.0','1.3.0',
        '1.4.0','1.4.3','1.5.0','1.5.1','1.5.4',
        '1.6.0','1.6.1','1.6.2','1.6.4','1.6.5','1.6.6','1.6.7','1.6.8','1.6.9',
        '1.7.0','1.7.1','1.7.2','1.7.3','1.7.4','1.7.5','1.7.6','1.7.7','1.7.8','1.7.9',
        '1.7.10','1.7.11','1.7.12','1.7.13','1.7.14','1.7.15','1.7.16','1.7.17','1.7.18','1.7.19',
        '1.7.20','1.7.21','1.7.22','1.7.23','1.7.24','1.7.25','1.7.26','1.7.27','1.7.28','1.7.29',
        '1.7.30','1.7.31','1.7.32','1.7.33','1.7.34','1.7.35','1.7.36','1.7.37','1.7.38','1.7.39',
        '1.7.40','1.7.41','1.7.42','1.7.43','1.7.44','1.7.45','1.7.46','1.7.47','1.7.48','1.7.49',
        '1.7.50','1.7.51','1.7.52','1.7.53','1.7.54','1.7.55','1.7.56','1.7.57',
    ];

    $known = [];
    if (isset($UPGRADE_SQL) && is_array($UPGRADE_SQL)) $known = array_merge($known, array_keys($UPGRADE_SQL));
    if (isset($VERSIONS)    && is_array($VERSIONS))    $known = array_merge($known, array_keys($VERSIONS));
    if (defined('PM_VERSION')) $known[] = PM_VERSION;
    $known = array_values(array_unique(array_filter($known, 'is_string')));

    // v1.7.64: classificazione per ERA. Il difetto precedente accodava OGNI versione
    // sconosciuta ordinandola con version_compare: le chiavi storiche '2.0'/'2.1'
    // (era certV) finivano cosi' DOPO 1.7.x, diventando "ultima versione" e
    // provocando un auto-bump a 2.1.
    $pre = $legacy_pre;
    $mod = $legacy_mod;
    foreach ($known as $v) {
        if (in_array($v, $legacy_pre, true) || in_array($v, $legacy_mod, true)) continue;
        // Solo la serie 1.x appartiene a PortalManager (post-rinomina).
        if (preg_match('/^1\./', $v)) $mod[] = $v; else $pre[] = $v;
    }
    // Dentro ciascuna era il semver e' monotono: version_compare e' affidabile.
    $pre = array_values(array_unique($pre)); usort($pre, function ($a, $b) { return version_compare($a, $b); });
    $mod = array_values(array_unique($mod)); usort($mod, function ($a, $b) { return version_compare($a, $b); });

    $order = array_merge($pre, $mod);
    return $order;
}

/** Confronto cronologico basato su pm_version_order(). */
function pm_version_cmp(string $a, string $b): int {
    static $idx = null;
    if ($idx === null) $idx = array_flip(pm_version_order());
    $ia = $idx[$a] ?? PHP_INT_MAX;
    $ib = $idx[$b] ?? PHP_INT_MAX;
    return $ia <=> $ib;
}

/** Ultima versione conosciuta (target di default degli upgrade). */
function pm_latest_version(): string {
    $o = pm_version_order();
    return (string)end($o);
}


// ─── v1.7.19: tabella saved_views (per filtri/viste/export) ───
$UPGRADE_SQL['1.7.19'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS saved_views (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  page_name VARCHAR(80) NOT NULL,
  name VARCHAR(120) NOT NULL,
  filters_json LONGTEXT NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_shared TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sv_user_page (user_id, page_name),
  KEY idx_sv_page_shared (page_name, is_shared),
  UNIQUE KEY uniq_sv_user_page_name (user_id, page_name, name),
  CONSTRAINT fk_sv_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.7.19','Versione applicazione'),
  ('schema_version','1.7.19','Versione schema database'),
  ('release_label','1.7.19','Etichetta release mostrata in footer')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;

$UPGRADE_SQL['1.7.9'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS menu_preferences (
  id            INT NOT NULL AUTO_INCREMENT,
  scope_type    ENUM('role','user') NOT NULL,
  scope_id      INT NOT NULL,
  menu_config   LONGTEXT NOT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_scope (scope_type, scope_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_permissions (role_id, page_name, can_view, can_create, can_edit, can_delete, can_export) VALUES
  (1,'menu_customizer.php',1,1,1,1,1),
  (2,'menu_customizer.php',1,1,1,0,0),
  (3,'menu_customizer.php',1,1,1,0,0),
  (4,'menu_customizer.php',1,1,1,0,0),
  (5,'menu_customizer.php',1,1,1,0,0),
  (6,'menu_customizer.php',1,1,1,0,0);

INSERT INTO app_settings (setting_key, setting_value, description) VALUES
  ('app_version','1.7.9','Versione applicazione'),
  ('schema_version','1.7.9','Versione schema database'),
  ('release_label','1.7.9','Etichetta release mostrata in footer')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
SQL;



// Helper: verifica se esiste un permesso role/page
if (!function_exists('has_role_permission')) {
    function has_role_permission(string $page, int $role): bool {
        global $pdo;
        try {
            $q = $pdo->prepare("SELECT 1 FROM role_permissions WHERE role_id=? AND page_name=? LIMIT 1");
            $q->execute([$role, $page]);
            return (bool)$q->fetchColumn();
        } catch (Throwable $e) { return false; }
    }
}

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
        if (!db_table_exists('users'))                              $current_version = 'N/A';
        elseif (!db_table_exists('job_positions'))                   $current_version = '2.0';
        elseif (!db_column_exists('users','employee_id'))            $current_version = '2.1';
        elseif (!db_column_exists('candidates','education_level'))   $current_version = '2.2';
        elseif (!db_table_exists('brand_technologies'))              $current_version = '2.3';
        elseif (!db_column_exists('role_permissions','can_view'))    $current_version = '2.4';
        elseif (!db_table_exists('user_2fa'))                        $current_version = '4.0';
        elseif (!db_table_exists('positions_expected'))              $current_version = '4.1';
        elseif (!db_table_exists('clients'))                         $current_version = '5.0';
        elseif (!db_table_exists('import_jobs'))                     $current_version = '5.4';
        elseif (!db_table_exists('tech_categories'))                 $current_version = '5.5';
        elseif (!db_table_exists('enum_proposals'))                  $current_version = '5.7';
        elseif (!db_table_exists('employee_credly_link'))            $current_version = '5.9';
        elseif (!db_column_exists('certifications','credly_template_id')) $current_version = '5.9';
        elseif (!db_setting('credly_auto_create_catalog'))           $current_version = '1.1.0';
        elseif (!db_table_exists('employee_linkedin_link'))          $current_version = '1.1.3';
        elseif (!db_column_exists('employees','business_email'))     $current_version = '1.2.0';
        elseif (!db_column_exists('employees','education_level'))    $current_version = '1.3.0';
        elseif (!db_table_exists('emp_devices_phone'))                $current_version = '1.4.3';
        elseif (!db_table_exists('emp_devices_credit_card'))          $current_version = '1.5.0';
        elseif (!db_table_exists('emp_languages'))                    $current_version = '1.5.4';
        elseif (!db_column_exists('employees','photo_path'))          $current_version = '1.5.4';
        elseif (!db_table_exists('emp_cv_preferences'))               $current_version = '1.6.0';
        elseif (!db_table_exists('sql_migrations_log'))               $current_version = '1.6.1';
        elseif (!has_role_permission('file_manager.php', 1))          $current_version = '1.6.2';
        elseif (!has_role_permission('credly_manual_import.php', 1))  $current_version = '1.6.4';
        elseif (!db_column_exists('emp_cv_preferences', 'cv_template')) $current_version = '1.6.8';
        elseif (!has_role_permission('cv_import.php', 1))             $current_version = '1.7.3';
        elseif (!db_table_exists('menu_preferences'))                 $current_version = '1.7.5';
        elseif (!db_table_exists('device_handovers'))                 $current_version = '1.7.10';
        elseif (!db_column_enum_has('emp_cv_preferences','cv_template','europass'))
                                                                       $current_version = '1.7.14';
        elseif (!db_table_exists('saved_views'))                       $current_version = '1.7.18';
        elseif (!db_table_exists('projects'))                          $current_version = '1.7.22';
        elseif (!db_column_exists('project_technologies','brand_technology_id')) $current_version = '1.7.23';
        else $current_version = '1.7.24';
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
        $dump = "-- PortalManager Database Backup\n-- Data: " . date('Y-m-d H:i:s') . "\n-- Database: $db_name\n-- Tabelle: " . count($tables) . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";

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
    if ($action === 'upgrade' || $action === 'auto_apply') {
        $upgrade_log = [];

        // FIX: applica TUTTI gli step che hanno elementi mancanti, non solo quelli > versione corrente
        // Il DB potrebbe essere marcato v2.4 ma avere permessi v2.2 mancanti
        //
        // NOTA versioning: dopo la v5.9 il portale è stato rinominato in PortalManager
        // e il versioning è ripartito da 1.0.0. Quindi 1.0.0 è SUCCESSIVO a 5.9, non precedente.
        // version_compare() non gestisce questo: usiamo confronto basato su indice esplicito.
        // v1.7.63: ordine e confronto centralizzati e auto-manutenuti (vedi pm_version_order()).
        $version_order = pm_version_order();
        $cmp = function($a, $b) { return pm_version_cmp((string)$a, (string)$b); };

        $to_apply = [];
        foreach ($version_order as $v) {
            // Applica se: è nella lista upgrades_needed OPPURE è <= target e > current
            if (in_array($v, $upgrades_needed) || 
                ($cmp($v, $current_version) > 0 && $cmp($v, $target_ver ?: pm_latest_version()) <= 0)) {
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

            // v1.7.63: guardia anti-regressione. Non scrivere mai una versione
            // precedente a quella gia' presente a DB (causa dei "downgrade" a 1.7.57).
            if ($current_version && $cmp($new_ver, $current_version) < 0) $new_ver = $current_version;
            // Non superare mai la versione del codice effettivamente installato.
            if (defined('PM_VERSION') && $cmp($new_ver, PM_VERSION) > 0) $new_ver = PM_VERSION;

            // v1.7.63: allinea TUTTE le chiavi di versione (prima solo app_version,
            // lasciando schema_version e release_label indietro).
            $st_ver = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value, description) VALUES (?,?,?)
                           ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            $st_ver->execute(['app_version',    $new_ver, 'Versione applicazione']);
            $st_ver->execute(['schema_version', $new_ver, 'Versione schema database']);
            $st_ver->execute(['release_label',  $new_ver, 'Etichetta release mostrata in footer']);

            // Log
            $pdo->prepare("INSERT INTO app_logs (category, level, message, ip_address) VALUES (?,?,?,?)")
                ->execute(['Upgrade', 'success', "Database aggiornato a v$new_ver", $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);
        } catch (PDOException $e) {
            // Non fatale
        }

        // v1.7.10: salvo log in session per mostrarlo dopo redirect
        $_SESSION['_db_upgrade_log'] = $upgrade_log;
        $_SESSION['_db_upgrade_applied'] = $to_apply;

        // Ricarica analisi
        header("Location: db_upgrade.php?upgraded=$new_ver");
        exit();
    }
}

// Recupero log da session se presente (mostra al ricaricamento dopo redirect)
$upgrade_log = [];
$applied_versions = [];
if (!empty($_SESSION['_db_upgrade_log'])) {
    $upgrade_log = $_SESSION['_db_upgrade_log'];
    $applied_versions = $_SESSION['_db_upgrade_applied'] ?? [];
    unset($_SESSION['_db_upgrade_log'], $_SESSION['_db_upgrade_applied']);
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
<title>PortalManager — DB Upgrade Manager</title>
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
    <h1>PortalManager — DB Upgrade Manager</h1>
    <p>Verifica integrità database e aggiornamento multi-versione</p>
</div>

<?php if ($just_upgraded): ?>
<div class="alert alert-success">
    <i class="fa-solid fa-circle-check" style="margin-top:2px"></i>
    <div><strong>Upgrade completato!</strong> Database aggiornato alla versione <strong><?=h($just_upgraded)?></strong>. Verificare il log esecuzione sotto per confermare che tutti gli statement siano OK.</div>
</div>
<?php endif; ?>

<?php /* v1.7.10: log esecuzione statement (visibile dopo redirect grazie a session) */ ?>
<?php if (!empty($upgrade_log)): ?>
<div class="card" style="margin-bottom:20px">
  <div style="padding:12px 16px;background:#f8fafc;border-bottom:1px solid var(--border);font-weight:700;display:flex;justify-content:space-between;align-items:center">
    <span><i class="fa-solid fa-terminal" style="color:#16a34a"></i> Log esecuzione SQL — <?= count($upgrade_log) ?> versioni applicate</span>
    <span style="font-size:11px;color:var(--muted)">Espandi le sezioni per vedere ogni statement</span>
  </div>
  <div style="padding:14px">
    <?php foreach ($upgrade_log as $ver => $statements): ?>
      <?php
        $tot = count($statements);
        $ok  = count(array_filter($statements, fn($s) => $s['ok']));
        $err = $tot - $ok;
      ?>
      <details <?= $err > 0 ? 'open' : '' ?> style="margin-bottom:8px;border:1px solid <?= $err>0 ? '#fecaca' : '#86efac' ?>;border-radius:8px;background:<?= $err>0 ? '#fef2f2' : '#f0fdf4' ?>">
        <summary style="cursor:pointer;padding:10px 14px;font-weight:700;font-size:13px;list-style:none;display:flex;justify-content:space-between;align-items:center">
          <span>
            <i class="fa-solid fa-<?= $err>0 ? 'triangle-exclamation' : 'circle-check' ?>" style="color:<?= $err>0 ? '#dc2626' : '#16a34a' ?>"></i>
            <strong>v<?= h($ver) ?></strong> — <?= $ok ?>/<?= $tot ?> statement OK<?= $err>0 ? ' · ' . $err . ' errori' : '' ?>
          </span>
          <span style="font-size:10px;color:var(--muted);font-weight:600">click per espandere</span>
        </summary>
        <div style="padding:0 14px 14px">
          <table style="width:100%;font-size:11px;font-family:Consolas,monospace;border-collapse:collapse">
            <thead>
              <tr style="background:#1e293b;color:#fff">
                <th style="padding:6px;text-align:left;width:60px">Esito</th>
                <th style="padding:6px;text-align:left">Statement</th>
                <th style="padding:6px;text-align:left;width:200px">Note</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($statements as $st): ?>
              <tr style="background:<?= $st['ok'] ? '#fff' : '#fee2e2' ?>;border-bottom:1px solid #e2e8f0">
                <td style="padding:5px 6px">
                  <?php if ($st['ok']): ?>
                    <span style="background:#dcfce7;color:#166534;padding:2px 6px;border-radius:4px;font-weight:700;font-size:10px">OK</span>
                  <?php else: ?>
                    <span style="background:#fee2e2;color:#991b1b;padding:2px 6px;border-radius:4px;font-weight:700;font-size:10px">FAIL</span>
                  <?php endif; ?>
                </td>
                <td style="padding:5px 6px;color:#475569"><code><?= h($st['sql']) ?></code></td>
                <td style="padding:5px 6px;color:<?= $st['ok'] ? '#16a34a' : '#dc2626' ?>;font-size:10px">
                  <?= h($st['note'] ?? $st['error'] ?? ($st['ok'] ? 'eseguito' : '')) ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </details>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php
// ═══════════════════════════════════════════════════════════════════════════
//  AUTO-DETECT v1.5.4: banner riassuntivo allineamento DB all'apertura pagina
// ═══════════════════════════════════════════════════════════════════════════
if ($pdo && !empty($report) && !$just_upgraded):
    $_total_missing = 0;
    $_missing_by_ver = [];
    foreach ($report as $ver => $info) {
        $_v_missing = array_filter($info['items'], fn($i) => !$i['ok']);
        if ($_v_missing) {
            $_missing_by_ver[$ver] = count($_v_missing);
            $_total_missing += count($_v_missing);
        }
    }
    // Lista versioni in ordine cronologico (hardcoded: serve anche fuori dal blocco upgrade)
    // v1.7.63: era una seconda lista hardcoded ferma a 1.7.57 (guidava $_target_latest
    // e un auto-bump che riportava la versione indietro). Ora fonte unica.
    $_ordered = pm_version_order();
    $_target_latest = end($_ordered);
    $_is_at_latest  = (isset($report[$_target_latest]) && $report[$_target_latest]['ok']);
?>

<?php
  // v1.5.4 auto-bump: se schema è OK ma versione setting è inferiore al target, aggiorna
  // v1.7.64: l'auto-bump non deve mai scrivere una versione superiore al codice
  // installato ne' regredire rispetto al DB.
  if (defined('PM_VERSION') && pm_version_cmp($_target_latest, PM_VERSION) > 0) $_target_latest = PM_VERSION;
  if ($current_version && pm_version_cmp($_target_latest, $current_version) < 0) $_target_latest = $current_version;
  if ($_total_missing === 0 && $_is_at_latest && $current_version !== $_target_latest) {
      try {
          $pdo->prepare(
              "INSERT INTO app_settings (setting_key, setting_value, description) VALUES
                 ('app_version', ?, 'Versione applicazione'),
                 ('schema_version', ?, 'Versione schema database')
               ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
          )->execute([$_target_latest, $_target_latest]);
          $pdo->prepare("INSERT INTO app_logs (category, level, message, ip_address) VALUES (?,?,?,?)")
              ->execute(['Upgrade','info', "Auto-bump versione: $current_version -> $_target_latest (schema gia' allineato)", $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);
          $_bumped_from = $current_version;
          $current_version = $_target_latest;
      } catch (Throwable $e) {
          $_bump_error = $e->getMessage();
      }
  }
?>

<?php if ($_total_missing === 0 && $_is_at_latest): ?>
  <!-- Tutto OK: DB allineato all'ultima versione -->
  <div class="alert alert-success" style="border-left:4px solid var(--success);background:#ecfdf5">
    <i class="fa-solid fa-shield-halved" style="margin-top:2px;color:var(--success);font-size:18px"></i>
    <div>
      <strong>Database allineato e aggiornato.</strong>
      Versione corrente: <code style="background:#fff;padding:2px 8px;border-radius:4px;color:var(--success);font-weight:700"><?=h($current_version)?></code> ·
      Tutti gli elementi attesi sono presenti (tabelle, colonne, indici, permessi, settings).
      Non è richiesto alcun intervento.
      <?php if (!empty($_bumped_from)): ?>
        <div style="margin-top:6px;font-size:11px;color:#065f46;background:#d1fae5;padding:6px 10px;border-radius:6px">
          <i class="fa-solid fa-wand-magic-sparkles"></i>
          <strong>Auto-bump:</strong> versione aggiornata automaticamente da <code><?=h($_bumped_from)?></code> a <code><?=h($_target_latest)?></code>
          (lo schema era già allineato, ma il setting <code>app_version</code> nel database era ancora vecchio).
        </div>
      <?php endif; ?>
    </div>
  </div>

<?php else: ?>
  <!-- Disallineamento rilevato: suggerimento upgrade con auto-apply -->
  <div class="alert alert-warning" style="border-left:4px solid var(--warning);background:#fffbeb">
    <i class="fa-solid fa-triangle-exclamation" style="margin-top:2px;color:var(--warning);font-size:20px"></i>
    <div style="flex:1">
      <strong style="font-size:14px">Disallineamento rilevato — aggiornamento consigliato</strong>
      <div style="margin-top:6px;font-size:12px;color:#78350f">
        Versione corrente: <code style="background:#fff;padding:2px 8px;border-radius:4px;font-weight:700"><?=h($current_version)?></code>
        → versione target: <code style="background:#fff;padding:2px 8px;border-radius:4px;color:var(--success);font-weight:700"><?=h($_target_latest)?></code> ·
        <strong><?=$_total_missing?></strong> elementi mancanti in <strong><?=count($_missing_by_ver)?></strong> upgrade step:
      </div>
      <ul style="margin:8px 0 8px 22px;font-size:12px;color:#78350f;line-height:1.7">
        <?php foreach ($_missing_by_ver as $_v => $_cnt): ?>
        <li><strong><?=h($_v)?></strong> — <?=h($VERSIONS[$_v]['label'] ?? '')?> · <span style="color:#dc2626;font-weight:700"><?=$_cnt?> mancanti</span></li>
        <?php endforeach; ?>
      </ul>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
        <form method="POST" style="display:inline" onsubmit="return confirm('Applicare automaticamente <?=$_total_missing?> elementi mancanti?\n\nLa pagina verrà ricaricata al termine.\n\nIN CASO DI DUBBIO eseguire prima un BACKUP.');">
          <input type="hidden" name="action" value="auto_apply">
          <button type="submit" class="btn btn-primary" style="background:var(--warning);border:0;padding:9px 18px">
            <i class="fa-solid fa-wand-magic-sparkles"></i> Auto-applica tutti i fix mancanti
          </button>
        </form>
        <form method="POST" style="display:inline">
          <input type="hidden" name="action" value="backup_db">
          <button type="submit" class="btn btn-outline" style="padding:9px 14px">
            <i class="fa-solid fa-download"></i> Backup DB prima
          </button>
        </form>
        <a href="#report" class="btn btn-outline" style="padding:9px 14px;text-decoration:none">
          <i class="fa-solid fa-list-check"></i> Vedi dettaglio
        </a>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php endif; // end auto-detect ?>

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
                // v1.7.37: cerco sia il nuovo naming uniforme che il legacy certv_*
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

<?php if ($pct === 100 && $current_version === '1.3.0'): ?>
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
                <input type="hidden" name="target_version" value="1.3.0">
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
    PortalManager — DB Upgrade Manager · v1.3.0 · <?=date('Y-m-d H:i:s')?> · PHP <?=PHP_VERSION?> · <?=PHP_OS?>
    <br>Eliminare questo file dopo l'uso in produzione
</div>
</div>
</body>
</html>

