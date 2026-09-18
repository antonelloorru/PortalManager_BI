<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.28 — Verify installation.
 * Uso: PM_HC_DB=<nome_db> PM_HC_USER=<u> PM_HC_PASS=<p> php tools\verify_v1_9_28.php
 *      (o passare --db --user --pass come CLI args)
 */
$args = [];
foreach (array_slice($argv, 1) as $a) if (preg_match('/^--([^=]+)=(.*)$/', $a, $m)) $args[$m[1]] = $m[2];
$host = $args['host'] ?? getenv('PM_HC_HOST') ?: '127.0.0.1';
$db   = $args['db']   ?? getenv('PM_HC_DB')   ?: 'portalmanager';
$user = $args['user'] ?? getenv('PM_HC_USER') ?: 'root';
$pass = $args['pass'] ?? getenv('PM_HC_PASS') ?: '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) { echo "✘ Connessione: " . $e->getMessage() . "\n"; exit(1); }

echo "→ Connesso a $user@$host/$db\n\n";
$ok = 0; $ko = 0;

function chk(string $lbl, bool $cond, string $extra = '') {
    global $ok, $ko;
    echo ($cond ? "✔" : "✘") . " " . str_pad($lbl, 55) . " " . $extra . "\n";
    $cond ? $ok++ : $ko++;
}

// 1) Migration
$row = $pdo->query("SELECT applied_at FROM pm_migration_sql WHERE version='1.9.28' LIMIT 1")->fetch();
chk("pm_migration_sql contiene 1.9.28", (bool)$row, $row ? "(applied_at={$row['applied_at']})" : '');

// 2) app_version
$row = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app_version'")->fetch();
chk("app_settings.app_version = 1.9.28", ($row['setting_value'] ?? '') === '1.9.28', "(={$row['setting_value']})");

// 3) Viste
foreach (['v_rsi_per_persona','v_rsi_per_contratto'] as $v) {
    try { $c = (int)$pdo->query("SELECT COUNT(*) FROM $v")->fetchColumn(); chk("Vista $v presente", true, "($c righe)"); }
    catch (Throwable $e) { chk("Vista $v presente", false, $e->getMessage()); }
}

// 4) RBAC
$row = $pdo->query("SELECT COUNT(*) FROM role_permissions WHERE page_name='report_servizi_it.php'")->fetch(PDO::FETCH_NUM);
chk("role_permissions ha report_servizi_it.php", (int)$row[0] > 0, "({$row[0]} ruoli)");

// 5) File PHP presente e patchato
$file = dirname(__DIR__) . '/report_servizi_it.php';
if (!is_file($file)) $file = realpath(__DIR__ . '/..') . '/report_servizi_it.php';
$has = is_file($file);
chk("File report_servizi_it.php presente", $has, $has ? $file : '(non trovato — copia il file in webroot)');
if ($has) {
    $src = file_get_contents($file) ?: '';
    chk("File contiene v1.9.28", strpos($src, 'v1.9.28') !== false);
    chk("Sezione 'Giorni lavorati per persona'", strpos($src, 'Giorni lavorati per persona') !== false);
    chk("Sezione 'Riepilogo per Codice Contratto'", strpos($src, 'Riepilogo per Codice Contratto') !== false);
    chk("Uso di COUNT(DISTINCT report_date)", strpos($src, 'COUNT(DISTINCT a.report_date)') !== false);
}

echo "\n" . ($ko === 0 ? "[OK] Tutti i {$ok} check superati." : "[KO] {$ko} check falliti, {$ok} superati.") . "\n";
exit($ko === 0 ? 0 : 1);
