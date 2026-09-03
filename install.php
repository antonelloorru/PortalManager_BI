<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 *  certV 4.0 — INSTALLER WIZARD
 *  Installazione guidata in 6 step con verifica requisiti, creazione DB,
 *  import schema, configurazione e primo account admin.
 * ═══════════════════════════════════════════════════════════════════════
 *
 *  ISTRUZIONI:
 *  1. Estrarre certV-4.0-completo.zip nella document root del web server
 *  2. Aprire http://localhost/certV/install.php
 *  3. Seguire i 6 step del wizard
 *  4. Al termine, il file install.php verrà rinominato in install.php.done
 *
 *  SICUREZZA: Questo file si auto-disabilita dopo l'installazione.
 *             NON lasciarlo attivo in produzione.
 * ═══════════════════════════════════════════════════════════════════════
 */

// ── Blocca se già installato E funzionante ───────────────────────────────────
// FIX #6/#7: verifica che Config.php esista E che la connessione funzioni
$already = false;
$already_but_broken = false;
if (file_exists(__DIR__ . '/Config.php') && !isset($_GET['force'])) {
    try {
        require_once __DIR__ . '/Config.php';
        $pdo->query("SELECT 1");
        $already = true;
    } catch (\Throwable $e) {
        $already_but_broken = true;
    }
}

session_start();

/**
 * Import SQL sicuro — risolve "Cannot execute queries while unbuffered queries active"
 *
 * CAUSA DEL BUG: PDO::exec() dopo SELECT/SHOW lascia result set aperti.
 *   Il dump phpMyAdmin contiene direttive condizionali MySQL, SELECT di verifica, SHOW TABLES.
 *   Questi producono result set che PDO non consuma automaticamente.
 *
 * SOLUZIONE:
 *   1. MYSQL_ATTR_USE_BUFFERED_QUERY nella connessione
 *   2. Skip di SELECT/SHOW (sono solo verifiche, non servono per l'import)
 *   3. Le direttive condizionali MySQL vengono eseguite singolarmente
 */
function _safe_import(PDO $pdo, string $sql): array {
    $result = ['ok' => 0, 'errors' => [], 'skipped' => 0, 'total' => 0];

    // Rimuove CREATE DATABASE e USE hardcoded
    $sql = preg_replace('/^\s*CREATE\s+DATABASE\s+.+?;\s*$/mi', '', $sql);
    $sql = preg_replace('/^\s*USE\s+`[^`]+`\s*;\s*$/mi', '', $sql);

    $lines = explode("\n", $sql);
    $buffer = '';
    $inBlock = false;  // dentro /* ... */

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Righe vuote e commenti singoli
        if ($trimmed === '' || strpos($trimmed, '--') === 0) continue;

        // Commenti multi-riga /* ... */
        if (!$inBlock && strpos($trimmed, '/*') !== false && strpos($trimmed, '*/') === false) {
            // Direttiva condizionale MySQL /*!40101 ... che continua su più righe
            if (preg_match('/^\/\*!\d+/', $trimmed)) {
                $buffer .= $line . "\n";
                $inBlock = true;
                continue;
            }
            $inBlock = true;
            continue;
        }
        if ($inBlock) {
            if (strpos($trimmed, '*/') !== false) {
                $inBlock = false;
                // Se era una direttiva MySQL, chiudi il buffer
                if ($buffer !== '') {
                    $buffer .= $line . "\n";
                    // Esegui la direttiva completa
                    $s = trim($buffer);
                    $buffer = '';
                    if ($s) {
                        try { $pdo->exec($s); $result['ok']++; }
                        catch (\PDOException $e) { /* direttive MySQL non critiche */ }
                    }
                }
            } else {
                $buffer .= $line . "\n";
            }
            continue;
        }

        // Direttiva MySQL su riga singola: /*!40101 SET ... */;
        if (preg_match('/^\/\*!\d+.*\*\/\s*;?\s*$/', $trimmed)) {
            try { $pdo->exec($trimmed); } catch (\PDOException $e) {}
            continue;
        }

        // Commento su riga singola /* ... */
        if (preg_match('/^\/\*[^!].*\*\/\s*$/', $trimmed)) continue;

        // Accumula nel buffer
        $buffer .= $line . "\n";

        // Statement completo: termina con ;
        if (preg_match('/;\s*$/', $trimmed)) {
            $stmt = trim($buffer);
            $buffer = '';

            if ($stmt === '' || $stmt === ';') continue;

            $result['total']++;

            // SKIP SELECT e SHOW: producono result set che causano l'errore "unbuffered"
            // Nel dump sono solo query di verifica, non servono per creare lo schema
            $upper = strtoupper(ltrim($stmt));
            if (preg_match('/^(SELECT|SHOW)\s/i', $upper)) {
                $result['skipped']++;
                continue;
            }

            // Esegui DDL/DML
            try {
                $pdo->exec($stmt);
                $result['ok']++;
            } catch (\PDOException $e) {
                $msg = $e->getMessage();
                // Errori accettabili (idempotenza)
                if (str_contains($msg, 'already exists') ||
                    str_contains($msg, 'Duplicate') ||
                    str_contains($msg, 'DROP FOREIGN KEY IF EXISTS')) {
                    $result['ok']++;
                } else {
                    $result['errors'][] = substr($msg, 0, 200);
                }
            }
        }
    }

    return $result;
}

// ── Step management ───────────────────────────────────────────────────
$step = (int)($_GET['step'] ?? ($_SESSION['install_step'] ?? 1));
if ($step < 1 || $step > 7) $step = 1;

$errors   = [];
$warnings = [];
$success  = [];

// ── STEP PROCESSING (POST) ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$already && !$already_but_broken) {
    $action = $_POST['action'] ?? '';

    // STEP 2 → 3: Save DB config
    if ($action === 'save_db') {
        $_SESSION['db_host'] = trim($_POST['db_host'] ?? 'localhost');
        $_SESSION['db_port'] = trim($_POST['db_port'] ?? '3306');
        $_SESSION['db_name'] = trim($_POST['db_name'] ?? 'cert_management');
        $_SESSION['db_user'] = trim($_POST['db_user'] ?? 'root');
        $_SESSION['db_pass'] = $_POST['db_pass'] ?? '';

        // Test connection
        try {
            $dsn = "mysql:host={$_SESSION['db_host']};port={$_SESSION['db_port']};charset=utf8mb4";
            $testPdo = new PDO($dsn, $_SESSION['db_user'], $_SESSION['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]);

            // Check if DB exists
            $dbs = $testPdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
            $_SESSION['db_exists'] = in_array($_SESSION['db_name'], $dbs);

            $_SESSION['db_ok'] = true;
            $_SESSION['install_step'] = 3;
            header("Location: install.php?step=3");
            exit();
        } catch (PDOException $e) {
            $errors[] = "Connessione fallita: " . $e->getMessage();
            $step = 2;
        }
    }

    // STEP 3 → 4: Create DB and import schema
    if ($action === 'import_schema') {
        try {
            // FIX: MYSQL_ATTR_USE_BUFFERED_QUERY previene "unbuffered queries" error
            $dsn = "mysql:host={$_SESSION['db_host']};port={$_SESSION['db_port']};charset=utf8mb4";
            $pdo = new PDO($dsn, $_SESSION['db_user'], $_SESSION['db_pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]);

            $dbName = $_SESSION['db_name'];

            if (!$_SESSION['db_exists']) {
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }

            // Chiudi e riapri connessione CON il database selezionato
            // (evita problemi di state tra USE e le query successive)
            $pdo = null;
            $dsn2 = "mysql:host={$_SESSION['db_host']};port={$_SESSION['db_port']};dbname=$dbName;charset=utf8mb4";
            $pdo = new PDO($dsn2, $_SESSION['db_user'], $_SESSION['db_pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]);

            // Import main schema
            $sqlFile = __DIR__ . '/cert_management.sql';
            if (!file_exists($sqlFile)) {
                throw new Exception("File cert_management.sql non trovato nella cartella di installazione.");
            }

            $sql = file_get_contents($sqlFile);
            $result = _safe_import($pdo, $sql);
            $imported = $result['ok'];
            $import_errors = $result['errors'];

            $_SESSION['schema_imported'] = true;
            $_SESSION['import_stats'] = $result;

            // Import migrations
            $migrations = [
                'migration_candidates_v2.3.sql',
                'migration_position_publications.sql',
                'migration_v4.0_smtp_brands.sql',
                'migration_distributors.sql',
                'migration_integrations.sql',
                'migration_documents.sql',
                'migration_contract_docs.sql',
                'migration_position_templates.sql',
                'migration_cert_catalog.sql',
                'migration_plan_types.sql',
                'migration_user_permissions.sql',
            ];
            $_SESSION['migrations_done'] = [];

            foreach ($migrations as $mig) {
                $migFile = __DIR__ . '/' . $mig;
                if (file_exists($migFile)) {
                    $migSql = file_get_contents($migFile);
                    $mr = _safe_import($pdo, $migSql);
                    $_SESSION['migrations_done'][] = $mig . " ({$mr['ok']}/{$mr['total']})";
                }
            }

            // Verify tables (connessione pulita grazie a BUFFERED_QUERY)
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $_SESSION['table_count'] = count($tables);

            $_SESSION['install_step'] = 4;
            header("Location: install.php?step=4");
            exit();

        } catch (PDOException $e) {
            $errors[] = "Errore import schema: " . $e->getMessage();
            $step = 3;
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
            $step = 3;
        }
    }

    // STEP 4 → 5: App settings
    if ($action === 'save_settings') {
        $_SESSION['app_name']      = trim($_POST['app_name'] ?? 'certV');
        $_SESSION['primary_color'] = trim($_POST['primary_color'] ?? '#0ea5e9');
        $_SESSION['admin_email']   = trim($_POST['admin_email'] ?? 'admin@certv.local');
        $_SESSION['admin_pass']    = $_POST['admin_pass'] ?? 'Admin@certV2!';
        $_SESSION['admin_pass2']   = $_POST['admin_pass2'] ?? '';

        if ($_SESSION['admin_pass'] !== $_SESSION['admin_pass2']) {
            $errors[] = "Le password non corrispondono.";
            $step = 4;
        } elseif (strlen($_SESSION['admin_pass']) < 8) {
            $errors[] = "La password deve essere almeno 8 caratteri.";
            $step = 4;
        } else {
            $_SESSION['install_step'] = 5;
            header("Location: install.php?step=5");
            exit();
        }
    }

    // STEP 5 → 6: Execute final installation
    if ($action === 'finalize') {
        try {
            // 1. Generate Config.php — FIX #1: porta SEMPRE nel DSN
            $configContent = "<?php\n";
            $configContent .= "/**\n";
            $configContent .= " * certV 4.0 — Config.php (generato dall'installer)\n";
            $configContent .= " * Generato il: " . date('Y-m-d H:i:s') . "\n";
            $configContent .= " */\n\n";
            $configContent .= "define('DB_HOST',    " . var_export($_SESSION['db_host'], true) . ");\n";
            $configContent .= "define('DB_PORT',    " . var_export($_SESSION['db_port'] ?? '3306', true) . ");\n";
            $configContent .= "define('DB_NAME',    " . var_export($_SESSION['db_name'], true) . ");\n";
            $configContent .= "define('DB_USER',    " . var_export($_SESSION['db_user'], true) . ");\n";
            $configContent .= "define('DB_PASS',    " . var_export($_SESSION['db_pass'], true) . ");\n";
            $configContent .= "define('DB_CHARSET', 'utf8mb4');\n\n";
            $configContent .= "define('APP_ROOT',   __DIR__);\n";
            $configContent .= "define('UPLOAD_DIR', APP_ROOT . '/uploads/');\n\n";
            $configContent .= "try {\n";
            $configContent .= "    \$pdo = new PDO(\n";
            $configContent .= "        \"mysql:host=\" . DB_HOST . \";port=\" . DB_PORT . \";dbname=\" . DB_NAME . \";charset=\" . DB_CHARSET,\n";
            $configContent .= "        DB_USER,\n";
            $configContent .= "        DB_PASS,\n";
            $configContent .= "        [\n";
            $configContent .= "            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,\n";
            $configContent .= "            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,\n";
            $configContent .= "            PDO::ATTR_EMULATE_PREPARES   => false,\n";
            $configContent .= "            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,\n";
            $configContent .= "        ]\n";
            $configContent .= "    );\n";
            $configContent .= "} catch (\\PDOException \$e) {\n";
            $configContent .= "    http_response_code(503);\n";
            $configContent .= "    die('<div style=\"font-family:sans-serif;padding:40px;background:#fee2e2;color:#991b1b;border-radius:8px;margin:40px auto;max-width:500px\">"
                            . "<h2>&#9888; Errore connessione DB</h2>"
                            . "<p style=\"margin-top:10px\">Verifica Config.php o <a href=\"install.php?force=1\" style=\"color:#991b1b;font-weight:700\">riesegui installer</a></p>"
                            . "</div>');\n";
            $configContent .= "}\n";

            if (!file_put_contents(__DIR__ . '/Config.php', $configContent)) {
                throw new Exception("Impossibile scrivere Config.php. Verificare i permessi della cartella.");
            }

            // 2. Connect to DB and apply settings — FIX #1: porta nel DSN
            $dsn = "mysql:host={$_SESSION['db_host']};port=" . ($_SESSION['db_port'] ?? '3306') . ";dbname={$_SESSION['db_name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $_SESSION['db_user'], $_SESSION['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]);

            // 3. Update app settings
            $stm = $pdo->prepare("UPDATE app_settings SET setting_value = ? WHERE setting_key = ?");
            $stm->execute([$_SESSION['app_name'], 'app_name']);
            $stm->execute([$_SESSION['primary_color'], 'primary_color']);

            // 4. Update admin account
            $hash = password_hash($_SESSION['admin_pass'], PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare("UPDATE users SET email = ?, password_hash = ? WHERE id = 1")
                ->execute([$_SESSION['admin_email'], $hash]);

            // 5. Create uploads directories
            $dirs = [
                __DIR__ . '/uploads',
                __DIR__ . '/uploads/candidati',
                __DIR__ . '/uploads/certificati',
            ];
            foreach ($dirs as $dir) {
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
            }

            // 6. Write installation log
            $pdo->prepare(
                "INSERT INTO app_logs (category, level, message, user_id, ip_address) VALUES (?,?,?,?,?)"
            )->execute(['System', 'success', 'Installazione completata tramite wizard', 1, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);

            $_SESSION['install_complete'] = true;
            $_SESSION['install_step'] = 6;
            header("Location: install.php?step=6");
            exit();

        } catch (Exception $e) {
            $errors[] = "Errore durante la finalizzazione: " . $e->getMessage();
            $step = 5;
        }
    }

    // STEP 6: Lock installer
    if ($action === 'lock_installer') {
        // Rename install.php to prevent re-execution
        @rename(__DIR__ . '/install.php', __DIR__ . '/install.php.done');
        session_destroy();
        header("Location: login.php");
        exit();
    }
}

// ── REQUIREMENTS CHECK (Step 1) ───────────────────────────────────────
function check_requirements(): array {
    $checks = [];

    // PHP version
    $phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
    $checks[] = ['PHP ' . PHP_VERSION, $phpOk, $phpOk ? 'OK' : 'Richiesto PHP 8.1+', 'critical'];

    // Extensions
    $exts = ['pdo', 'pdo_mysql', 'mbstring', 'json', 'session', 'fileinfo'];
    foreach ($exts as $ext) {
        $loaded = extension_loaded($ext);
        $checks[] = ["Estensione: $ext", $loaded, $loaded ? 'Caricata' : 'Mancante — abilitare in php.ini', 'critical'];
    }

    // Writable directories
    $writeDirs = [
        __DIR__ => 'Directory installazione',
        __DIR__ . '/uploads' => 'Cartella uploads',
    ];
    foreach ($writeDirs as $dir => $label) {
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $wr = is_writable($dir);
        $checks[] = ["Scrittura: $label", $wr, $wr ? 'Scrivibile' : "Non scrivibile — chmod 775 $dir", 'critical'];
    }

    // php.ini settings
    $uploadMax = ini_get('upload_max_filesize');
    $postMax   = ini_get('post_max_size');
    $checks[] = ["upload_max_filesize: $uploadMax", true, "Consigliato >= 10M per upload certificati", 'info'];
    $checks[] = ["post_max_size: $postMax", true, "Consigliato >= 12M", 'info'];

    // Memory
    $memLimit = ini_get('memory_limit');
    $memOk = (int)$memLimit >= 128 || $memLimit === '-1';
    $checks[] = ["memory_limit: $memLimit", $memOk, $memOk ? 'OK' : 'Consigliato >= 128M', 'warning'];

    // Session
    $sessPath = ini_get('session.save_path') ?: sys_get_temp_dir();
    $sessOk = is_writable($sessPath);
    $checks[] = ["Session path scrivibile", $sessOk, $sessOk ? $sessPath : "Non scrivibile: $sessPath", 'critical'];

    return $checks;
}

function all_critical_ok(array $checks): bool {
    foreach ($checks as $c) {
        if ($c[3] === 'critical' && !$c[1]) return false;
    }
    return true;
}

$checks = check_requirements();
$can_proceed = all_critical_ok($checks);

// ── Detect SQL files ──────────────────────────────────────────────────
$sql_files = [];
foreach (['cert_management.sql', 'migration_candidates_v2.3.sql', 'migration_position_publications.sql'] as $f) {
    $sql_files[$f] = file_exists(__DIR__ . '/' . $f);
}

// ── RENDER ────────────────────────────────────────────────────────────
$primary = '#0369a1';
$steps_labels = [
    1 => ['Requisiti', 'fa-clipboard-check'],
    2 => ['Database', 'fa-database'],
    3 => ['Schema', 'fa-table'],
    4 => ['Configurazione', 'fa-sliders-h'],
    5 => ['Revisione', 'fa-check-double'],
    6 => ['Completato', 'fa-flag-checkered'],
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Installazione — certV 4.0</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
    --p: <?=$primary?>;
    --pd: #075985;
    --bg: #f0f4f8;
    --surface: #ffffff;
    --border: #e2e8f0;
    --text: #1e293b;
    --muted: #64748b;
    --success: #059669;
    --warning: #d97706;
    --danger: #dc2626;
    --info: #2563eb;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    background: var(--bg);
    color: var(--text);
    font-size: 14px;
    min-height: 100vh;
}
.installer {
    max-width: 800px;
    margin: 0 auto;
    padding: 30px 20px;
}

/* Header */
.inst-header {
    text-align: center;
    margin-bottom: 32px;
}
.inst-logo {
    width: 64px; height: 64px;
    background: linear-gradient(135deg, var(--p), #7c3aed);
    border-radius: 16px;
    display: inline-flex; align-items: center; justify-content: center;
    color: #fff; font-size: 28px;
    margin-bottom: 14px;
    box-shadow: 0 8px 24px rgba(3,105,161,.25);
}
.inst-header h1 { font-size: 24px; font-weight: 800; color: var(--text); }
.inst-header p { color: var(--muted); font-size: 13px; margin-top: 4px; }

/* Step bar */
.step-bar {
    display: flex;
    justify-content: center;
    gap: 0;
    margin-bottom: 32px;
    position: relative;
}
.step-item {
    display: flex; flex-direction: column; align-items: center;
    position: relative;
    flex: 1;
    max-width: 130px;
}
.step-circle {
    width: 40px; height: 40px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
    border: 2.5px solid var(--border);
    background: var(--surface);
    color: var(--muted);
    transition: all .3s;
    position: relative;
    z-index: 2;
}
.step-item.done .step-circle {
    background: var(--success);
    border-color: var(--success);
    color: #fff;
}
.step-item.active .step-circle {
    background: var(--p);
    border-color: var(--p);
    color: #fff;
    box-shadow: 0 0 0 4px rgba(3,105,161,.15);
}
.step-label {
    font-size: 10px;
    font-weight: 600;
    color: var(--muted);
    margin-top: 6px;
    text-transform: uppercase;
    letter-spacing: .5px;
}
.step-item.active .step-label,
.step-item.done .step-label { color: var(--text); }
.step-item:not(:last-child)::after {
    content: '';
    position: absolute;
    top: 20px;
    left: calc(50% + 24px);
    width: calc(100% - 48px);
    height: 2px;
    background: var(--border);
    z-index: 1;
}
.step-item.done:not(:last-child)::after {
    background: var(--success);
}

/* Card */
.card {
    background: var(--surface);
    border-radius: 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,.06), 0 4px 16px rgba(0,0,0,.04);
    border: 1px solid var(--border);
    overflow: hidden;
}
.card-head {
    padding: 20px 24px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 12px;
}
.card-head i {
    width: 36px; height: 36px;
    border-radius: 10px;
    background: rgba(3,105,161,.08);
    display: flex; align-items: center; justify-content: center;
    color: var(--p);
    font-size: 16px;
}
.card-head h2 { font-size: 17px; font-weight: 700; }
.card-head p { font-size: 12px; color: var(--muted); margin-top: 2px; }
.card-body { padding: 24px; }

/* Checks table */
.check-row {
    display: flex;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid #f1f5f9;
    gap: 12px;
}
.check-row:last-child { border-bottom: none; }
.check-icon {
    width: 28px; height: 28px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px;
    flex-shrink: 0;
}
.check-icon.ok { background: #ecfdf5; color: var(--success); }
.check-icon.fail { background: #fef2f2; color: var(--danger); }
.check-icon.warn { background: #fffbeb; color: var(--warning); }
.check-icon.info { background: #eff6ff; color: var(--info); }
.check-name { font-weight: 600; font-size: 13px; flex: 1; }
.check-status { font-size: 12px; color: var(--muted); text-align: right; max-width: 300px; }

/* Form */
.fg { margin-bottom: 18px; }
.fg label {
    display: block; font-size: 11px; font-weight: 700;
    color: var(--muted); text-transform: uppercase;
    letter-spacing: .5px; margin-bottom: 6px;
}
.fg input, .fg select {
    width: 100%; padding: 11px 14px;
    border: 1.5px solid var(--border);
    border-radius: 10px; font-size: 14px;
    color: var(--text); transition: .2s;
    font-family: inherit; background: var(--surface);
}
.fg input:focus, .fg select:focus {
    outline: none;
    border-color: var(--p);
    box-shadow: 0 0 0 3px rgba(3,105,161,.12);
}
.fg .hint { font-size: 11px; color: var(--muted); margin-top: 4px; }
.fg-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.fg-row-3 { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 16px; }

/* Buttons */
.btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 24px;
    border-radius: 10px;
    border: none;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: all .15s;
    font-family: inherit;
    text-decoration: none;
}
.btn-primary { background: var(--p); color: #fff; }
.btn-primary:hover { background: var(--pd); }
.btn-success { background: var(--success); color: #fff; }
.btn-success:hover { filter: brightness(1.1); }
.btn-outline { background: transparent; border: 1.5px solid var(--border); color: var(--text); }
.btn-outline:hover { background: #f8fafc; }
.btn-danger { background: var(--danger); color: #fff; }
.btn-danger:hover { filter: brightness(1.1); }
.btn:disabled { opacity: .5; cursor: not-allowed; }

.btn-bar {
    display: flex; justify-content: space-between; align-items: center;
    padding: 20px 24px;
    border-top: 1px solid var(--border);
    background: #f8fafc;
}

/* Alerts */
.alert {
    padding: 14px 18px;
    border-radius: 10px;
    margin-bottom: 16px;
    font-size: 13px;
    display: flex; align-items: flex-start; gap: 10px;
    line-height: 1.5;
}
.alert i { margin-top: 2px; }
.alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
.alert-warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
.alert-info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }

/* Review items */
.review-section { margin-bottom: 20px; }
.review-section h3 {
    font-size: 12px; font-weight: 700;
    color: var(--muted); text-transform: uppercase;
    letter-spacing: .8px; margin-bottom: 10px;
    padding-bottom: 6px;
    border-bottom: 1px solid var(--border);
}
.review-row {
    display: flex; justify-content: space-between;
    padding: 8px 0; font-size: 13px;
}
.review-row .label { color: var(--muted); }
.review-row .value { font-weight: 600; font-family: 'Consolas', monospace; }

/* Complete */
.complete-box {
    text-align: center;
    padding: 40px 20px;
}
.complete-icon {
    width: 80px; height: 80px;
    border-radius: 50%;
    background: #ecfdf5;
    display: inline-flex;
    align-items: center; justify-content: center;
    font-size: 36px; color: var(--success);
    margin-bottom: 20px;
    animation: pop .5s cubic-bezier(.175,.885,.32,1.275);
}
@keyframes pop { 0% { transform: scale(0); } 100% { transform: scale(1); } }
.complete-box h2 { font-size: 22px; margin-bottom: 8px; }
.complete-box p { color: var(--muted); font-size: 14px; line-height: 1.6; max-width: 500px; margin: 0 auto; }
.cred-box {
    display: inline-block;
    background: #f8fafc;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 18px 28px;
    margin: 20px 0;
    text-align: left;
}
.cred-box .row { display: flex; justify-content: space-between; gap: 40px; padding: 5px 0; font-size: 13px; }
.cred-box .lbl { color: var(--muted); }
.cred-box .val { font-weight: 700; font-family: 'Consolas', monospace; }

/* Already installed */
.already-box {
    text-align: center;
    padding: 60px 20px;
}
.already-box .icon {
    width: 70px; height: 70px;
    border-radius: 50%;
    background: #fffbeb;
    display: inline-flex;
    align-items: center; justify-content: center;
    font-size: 30px; color: var(--warning);
    margin-bottom: 16px;
}

/* Color preview */
.color-preview {
    display: inline-block;
    width: 18px; height: 18px;
    border-radius: 5px;
    vertical-align: middle;
    margin-left: 6px;
    border: 1px solid var(--border);
}
</style>
</head>
<body>

<div class="installer">

    <!-- Header -->
    <div class="inst-header">
        <div class="inst-logo"><i class="fa-solid fa-graduation-cap"></i></div>
        <h1>certV 4.0 — Installazione</h1>
        <p>Portale Integrato Governance, Competenze & Recruiting</p>
    </div>

    <?php if ($already): ?>
    <!-- Already installed -->
    <div class="card">
        <div class="card-body">
            <div class="already-box">
                <div class="icon"><i class="fa-solid fa-lock"></i></div>
                <h2>Installazione già completata</h2>
                <p style="margin-bottom:20px">
                    Il file <code>Config.php</code> è già presente. Il sistema è configurato.
                </p>
                <div style="display:flex;gap:12px;justify-content:center">
                    <a href="login.php" class="btn btn-primary"><i class="fa-solid fa-arrow-right"></i> Vai al login</a>
                    <a href="install.php?force=1" class="btn btn-outline"><i class="fa-solid fa-redo"></i> Reinstalla</a>
                </div>
                <p style="margin-top:16px;font-size:11px;color:var(--muted)">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    La reinstallazione sovrascriverà Config.php. Effettuare un backup prima.
                </p>
            </div>
        </div>
    </div>

    <?php elseif ($already_but_broken): ?>
    <!-- FIX #6: Config.php esiste ma connessione DB fallisce -->
    <div class="alert alert-error">
        <i class="fa-solid fa-circle-xmark"></i>
        <div><strong>Config.php presente ma la connessione al database fallisce.</strong><br>
        Le credenziali potrebbero essere errate, oppure MySQL non è avviato.</div>
    </div>
    <div class="card"><div class="card-body" style="text-align:center;padding:40px 20px">
        <div style="display:flex;gap:12px;justify-content:center">
            <a href="install.php?force=1" class="btn btn-primary"><i class="fa-solid fa-redo"></i> Riesegui installazione</a>
            <a href="login.php" class="btn btn-outline"><i class="fa-solid fa-arrow-right"></i> Riprova login</a>
        </div>
        <p style="color:var(--muted);font-size:12px;margin-top:16px">L'installer sovrascriverà Config.php con nuove credenziali.</p>
    </div></div>

    <?php else: ?>
    <div class="step-bar">
        <?php foreach ($steps_labels as $s => $info): ?>
        <div class="step-item <?= $s < $step ? 'done' : ($s === $step ? 'active' : '') ?>">
            <div class="step-circle">
                <?php if ($s < $step): ?>
                    <i class="fa-solid fa-check"></i>
                <?php else: ?>
                    <i class="fa-solid <?=$info[1]?>"></i>
                <?php endif; ?>
            </div>
            <div class="step-label"><?=$info[0]?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Errors -->
    <?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><i class="fa-solid fa-circle-xmark"></i> <?=htmlspecialchars($err)?></div>
    <?php endforeach; ?>

    <!-- ═══════ STEP 1: REQUISITI ═══════ -->
    <?php if ($step === 1): ?>
    <div class="card">
        <div class="card-head">
            <i><i class="fa-solid fa-clipboard-check"></i></i>
            <div>
                <h2>Verifica Requisiti</h2>
                <p>Controllo dell'ambiente server prima dell'installazione</p>
            </div>
        </div>
        <div class="card-body">
            <?php foreach ($checks as $c): ?>
            <div class="check-row">
                <div class="check-icon <?= $c[1] ? 'ok' : ($c[3]==='critical'?'fail':($c[3]==='warning'?'warn':'info')) ?>">
                    <i class="fa-solid <?= $c[1] ? 'fa-check' : ($c[3]==='critical'?'fa-xmark':'fa-info') ?>"></i>
                </div>
                <div class="check-name"><?=htmlspecialchars($c[0])?></div>
                <div class="check-status"><?=htmlspecialchars($c[2])?></div>
            </div>
            <?php endforeach; ?>

            <!-- SQL files check -->
            <div style="margin-top:20px;padding-top:16px;border-top:2px solid var(--border)">
                <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:10px">File SQL</div>
                <?php foreach ($sql_files as $f => $exists): ?>
                <div class="check-row">
                    <div class="check-icon <?= $exists ? 'ok' : 'fail' ?>">
                        <i class="fa-solid <?= $exists ? 'fa-check' : 'fa-xmark' ?>"></i>
                    </div>
                    <div class="check-name" style="font-family:Consolas,monospace;font-size:12px"><?=$f?></div>
                    <div class="check-status"><?= $exists ? 'Trovato' : 'MANCANTE — necessario per installazione' ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="btn-bar">
            <span style="font-size:12px;color:var(--muted)">
                <?= $can_proceed ? '<i class="fa-solid fa-circle-check" style="color:var(--success)"></i> Tutti i requisiti critici soddisfatti' : '<i class="fa-solid fa-triangle-exclamation" style="color:var(--danger)"></i> Risolvere gli errori prima di procedere' ?>
            </span>
            <a href="install.php?step=2" class="btn btn-primary" <?= !$can_proceed ? 'style="pointer-events:none;opacity:.5"' : '' ?>>
                Continua <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══════ STEP 2: DATABASE CONNECTION ═══════ -->
    <?php if ($step === 2): ?>
    <div class="card">
        <div class="card-head">
            <i><i class="fa-solid fa-database"></i></i>
            <div>
                <h2>Connessione Database</h2>
                <p>Inserire le credenziali del server MySQL/MariaDB</p>
            </div>
        </div>
        <form method="POST">
        <input type="hidden" name="action" value="save_db">
        <div class="card-body">
            <div class="fg-row-3">
                <div class="fg">
                    <label>Host</label>
                    <input type="text" name="db_host" value="<?=htmlspecialchars($_SESSION['db_host'] ?? 'localhost')?>" required>
                    <div class="hint">Solitamente "localhost" o "127.0.0.1"</div>
                </div>
                <div class="fg">
                    <label>Porta</label>
                    <input type="text" name="db_port" value="<?=htmlspecialchars($_SESSION['db_port'] ?? '3306')?>">
                    <div class="hint">Default: 3306</div>
                </div>
                <div class="fg">
                    <label>Nome Database</label>
                    <input type="text" name="db_name" value="<?=htmlspecialchars($_SESSION['db_name'] ?? 'cert_management')?>" required>
                    <div class="hint">Verrà creato se non esiste</div>
                </div>
            </div>
            <div class="fg-row">
                <div class="fg">
                    <label>Utente MySQL</label>
                    <input type="text" name="db_user" value="<?=htmlspecialchars($_SESSION['db_user'] ?? 'root')?>" required>
                </div>
                <div class="fg">
                    <label>Password MySQL</label>
                    <input type="password" name="db_pass" value="<?=htmlspecialchars($_SESSION['db_pass'] ?? '')?>">
                    <div class="hint">Vuota su XAMPP default, "root" su MAMP</div>
                </div>
            </div>

            <div class="alert alert-info">
                <i class="fa-solid fa-info-circle"></i>
                <div>L'utente MySQL deve avere i privilegi <strong>CREATE DATABASE</strong>, <strong>CREATE TABLE</strong>, <strong>INSERT</strong>, <strong>UPDATE</strong>, <strong>DELETE</strong>, <strong>ALTER</strong> e <strong>INDEX</strong>.</div>
            </div>
        </div>
        <div class="btn-bar">
            <a href="install.php?step=1" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> Indietro</a>
            <button type="submit" class="btn btn-primary">Testa connessione <i class="fa-solid fa-arrow-right"></i></button>
        </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- ═══════ STEP 3: SCHEMA IMPORT ═══════ -->
    <?php if ($step === 3): ?>
    <div class="card">
        <div class="card-head">
            <i><i class="fa-solid fa-table"></i></i>
            <div>
                <h2>Import Schema Database</h2>
                <p>Creazione delle tabelle e dati iniziali</p>
            </div>
        </div>
        <div class="card-body">
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                <div>Connessione a <strong><?=htmlspecialchars($_SESSION['db_host'])?></strong> riuscita. Utente: <strong><?=htmlspecialchars($_SESSION['db_user'])?></strong></div>
            </div>

            <div style="background:#f8fafc;border-radius:12px;padding:18px;margin:16px 0;border:1px solid var(--border)">
                <div style="font-weight:700;margin-bottom:12px">Operazioni che verranno eseguite:</div>
                <div class="check-row">
                    <div class="check-icon <?= $_SESSION['db_exists'] ? 'warn' : 'ok' ?>">
                        <i class="fa-solid <?= $_SESSION['db_exists'] ? 'fa-database' : 'fa-plus' ?>"></i>
                    </div>
                    <div class="check-name">Database: <?=htmlspecialchars($_SESSION['db_name'])?></div>
                    <div class="check-status"><?= $_SESSION['db_exists'] ? 'Esiste già — le tabelle saranno sovrascritte' : 'Verrà creato automaticamente' ?></div>
                </div>
                <div class="check-row">
                    <div class="check-icon info"><i class="fa-solid fa-file-import"></i></div>
                    <div class="check-name">cert_management.sql</div>
                    <div class="check-status">~29 tabelle con dati demo, indici, FK</div>
                </div>
                <div class="check-row">
                    <div class="check-icon info"><i class="fa-solid fa-file-import"></i></div>
                    <div class="check-name">migration_candidates_v2.3.sql</div>
                    <div class="check-status">Profilo completo candidati</div>
                </div>
                <div class="check-row">
                    <div class="check-icon info"><i class="fa-solid fa-file-import"></i></div>
                    <div class="check-name">migration_position_publications.sql</div>
                    <div class="check-status">Pubblicazione posizioni su portali</div>
                </div>
            </div>

            <?php if ($_SESSION['db_exists']): ?>
            <div class="alert alert-warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>Il database <strong><?=htmlspecialchars($_SESSION['db_name'])?></strong> esiste già. L'import sovrascriverà le tabelle esistenti. Effettuare un backup se contiene dati importanti.</div>
            </div>
            <?php endif; ?>
        </div>
        <form method="POST">
        <input type="hidden" name="action" value="import_schema">
        <div class="btn-bar">
            <a href="install.php?step=2" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> Indietro</a>
            <button type="submit" class="btn btn-primary" onclick="this.innerHTML='<i class=\'fa-solid fa-spinner fa-spin\'></i> Importazione in corso...';this.disabled=true;this.form.submit()">
                Importa schema <i class="fa-solid fa-arrow-right"></i>
            </button>
        </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- ═══════ STEP 4: CONFIGURATION ═══════ -->
    <?php if ($step === 4): ?>
    <div class="card">
        <div class="card-head">
            <i><i class="fa-solid fa-sliders-h"></i></i>
            <div>
                <h2>Configurazione Applicazione</h2>
                <p>Personalizza il portale e configura l'account amministratore</p>
            </div>
        </div>
        <form method="POST">
        <input type="hidden" name="action" value="save_settings">
        <div class="card-body">
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                <div>Schema importato con successo. <strong><?=$_SESSION['table_count'] ?? '?'?> tabelle</strong> create. Migration: <?=implode(', ', $_SESSION['migrations_done'] ?? [])?></div>
            </div>

            <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;margin:20px 0 12px;letter-spacing:.5px">
                <i class="fa-solid fa-paintbrush" style="color:var(--p)"></i> Aspetto
            </div>
            <div class="fg-row">
                <div class="fg">
                    <label>Nome applicazione</label>
                    <input type="text" name="app_name" value="<?=htmlspecialchars($_SESSION['app_name'] ?? 'Portale Integrato Governance, Competenze & Recruiting')?>" required>
                    <div class="hint">Visualizzato nella sidebar e nel login</div>
                </div>
                <div class="fg">
                    <label>Colore primario <span class="color-preview" id="colorPreview" style="background:<?=htmlspecialchars($_SESSION['primary_color'] ?? '#0ea5e9')?>"></span></label>
                    <input type="color" name="primary_color" value="<?=htmlspecialchars($_SESSION['primary_color'] ?? '#0ea5e9')?>" style="height:44px;padding:4px" onchange="document.getElementById('colorPreview').style.background=this.value">
                </div>
            </div>

            <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;margin:24px 0 12px;letter-spacing:.5px">
                <i class="fa-solid fa-user-shield" style="color:var(--p)"></i> Account Amministratore
            </div>
            <div class="fg">
                <label>Email amministratore</label>
                <input type="email" name="admin_email" value="<?=htmlspecialchars($_SESSION['admin_email'] ?? 'admin@certv.local')?>" required>
                <div class="hint">Usata per il login. Può essere cambiata successivamente.</div>
            </div>
            <div class="fg-row">
                <div class="fg">
                    <label>Password</label>
                    <input type="password" name="admin_pass" value="" required minlength="8" placeholder="Minimo 8 caratteri">
                </div>
                <div class="fg">
                    <label>Conferma password</label>
                    <input type="password" name="admin_pass2" value="" required minlength="8" placeholder="Ripeti la password">
                </div>
            </div>
            <div class="alert alert-info" style="margin-top:4px">
                <i class="fa-solid fa-shield-halved"></i>
                <div>La password sarà hashata con bcrypt (cost 12). Usa una password forte con maiuscole, minuscole, numeri e simboli.</div>
            </div>
        </div>
        <div class="btn-bar">
            <a href="install.php?step=3" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> Indietro</a>
            <button type="submit" class="btn btn-primary">Continua <i class="fa-solid fa-arrow-right"></i></button>
        </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- ═══════ STEP 5: REVIEW ═══════ -->
    <?php if ($step === 5): ?>
    <div class="card">
        <div class="card-head">
            <i><i class="fa-solid fa-check-double"></i></i>
            <div>
                <h2>Revisione e Conferma</h2>
                <p>Verificare tutti i parametri prima dell'installazione finale</p>
            </div>
        </div>
        <div class="card-body">
            <div class="review-section">
                <h3><i class="fa-solid fa-database"></i> Database</h3>
                <div class="review-row"><span class="label">Host</span><span class="value"><?=htmlspecialchars($_SESSION['db_host'] ?? '')?><?= ($_SESSION['db_port'] ?? '3306') !== '3306' ? ':' . $_SESSION['db_port'] : '' ?></span></div>
                <div class="review-row"><span class="label">Database</span><span class="value"><?=htmlspecialchars($_SESSION['db_name'] ?? '')?></span></div>
                <div class="review-row"><span class="label">Utente</span><span class="value"><?=htmlspecialchars($_SESSION['db_user'] ?? '')?></span></div>
                <div class="review-row"><span class="label">Tabelle</span><span class="value"><?=$_SESSION['table_count'] ?? '?'?> tabelle importate</span></div>
            </div>
            <div class="review-section">
                <h3><i class="fa-solid fa-paintbrush"></i> Applicazione</h3>
                <div class="review-row"><span class="label">Nome</span><span class="value"><?=htmlspecialchars($_SESSION['app_name'] ?? 'certV')?></span></div>
                <div class="review-row"><span class="label">Colore primario</span><span class="value"><?=htmlspecialchars($_SESSION['primary_color'] ?? '#0ea5e9')?> <span class="color-preview" style="background:<?=htmlspecialchars($_SESSION['primary_color'] ?? '#0ea5e9')?>"></span></span></div>
            </div>
            <div class="review-section">
                <h3><i class="fa-solid fa-user-shield"></i> Amministratore</h3>
                <div class="review-row"><span class="label">Email</span><span class="value"><?=htmlspecialchars($_SESSION['admin_email'] ?? '')?></span></div>
                <div class="review-row"><span class="label">Password</span><span class="value">••••••••</span></div>
            </div>
            <div class="review-section">
                <h3><i class="fa-solid fa-folder-open"></i> File che verranno creati</h3>
                <div class="review-row"><span class="label">Config.php</span><span class="value" style="color:var(--success)">Generato automaticamente</span></div>
                <div class="review-row"><span class="label">uploads/</span><span class="value" style="color:var(--success)">Directory creata</span></div>
                <div class="review-row"><span class="label">uploads/candidati/</span><span class="value" style="color:var(--success)">Directory creata</span></div>
                <div class="review-row"><span class="label">uploads/certificati/</span><span class="value" style="color:var(--success)">Directory creata</span></div>
            </div>

            <div class="alert alert-warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>Cliccando <strong>"Installa"</strong> il file Config.php sarà generato, le impostazioni applicate e l'account admin aggiornato. L'operazione non è reversibile senza un backup.</div>
            </div>
        </div>
        <form method="POST">
        <input type="hidden" name="action" value="finalize">
        <div class="btn-bar">
            <a href="install.php?step=4" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> Indietro</a>
            <button type="submit" class="btn btn-success" onclick="this.innerHTML='<i class=\'fa-solid fa-spinner fa-spin\'></i> Installazione in corso...';this.disabled=true;this.form.submit()">
                <i class="fa-solid fa-rocket"></i> Installa certV 4.0
            </button>
        </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- ═══════ STEP 6: COMPLETE ═══════ -->
    <?php if ($step === 6): ?>
    <div class="card">
        <div class="card-body">
            <div class="complete-box">
                <div class="complete-icon"><i class="fa-solid fa-check"></i></div>
                <h2>Installazione completata!</h2>
                <p>certV 4.0 è stato installato con successo ed è pronto all'uso.</p>

                <div class="cred-box">
                    <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:10px;letter-spacing:.5px">Credenziali di accesso</div>
                    <div class="row"><span class="lbl">URL:</span><span class="val">login.php</span></div>
                    <div class="row"><span class="lbl">Email:</span><span class="val"><?=htmlspecialchars($_SESSION['admin_email'] ?? 'admin@certv.local')?></span></div>
                    <div class="row"><span class="lbl">Password:</span><span class="val">••••••••</span></div>
                </div>

                <div class="alert alert-warning" style="text-align:left;max-width:500px;margin:16px auto">
                    <i class="fa-solid fa-shield-halved"></i>
                    <div>
                        <strong>Prossimi passi importanti:</strong><br>
                        1. Il file <code>install.php</code> verrà rinominato per sicurezza<br>
                        2. Eliminare <code>schema_check_upgrade.php</code> e <code>reset_admin.php</code> in produzione<br>
                        3. Configurare il cron per le notifiche automatiche<br>
                        4. Abilitare HTTPS se il server è raggiungibile dall'esterno
                    </div>
                </div>

                <form method="POST" style="margin-top:20px">
                    <input type="hidden" name="action" value="lock_installer">
                    <button type="submit" class="btn btn-success" style="font-size:16px;padding:14px 32px">
                        <i class="fa-solid fa-arrow-right"></i> Vai al login
                    </button>
                </form>
                <p style="font-size:11px;color:var(--muted);margin-top:10px">
                    install.php verrà rinominato in install.php.done
                </p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; /* fine !$already */ ?>

    <div style="text-align:center;padding:20px;font-size:11px;color:var(--muted)">
        certV 4.0 — Portale Integrato Governance, Competenze & Recruiting
    </div>
</div>

</body>
</html>
