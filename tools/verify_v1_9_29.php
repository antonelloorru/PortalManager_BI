<?php
declare(strict_types=1);
$args = [];
foreach (array_slice($argv, 1) as $a) if (preg_match('/^--([^=]+)=(.*)$/', $a, $m)) $args[$m[1]] = $m[2];
$host = $args['host'] ?? getenv('PM_HC_HOST') ?: '127.0.0.1';
$db   = $args['db']   ?? getenv('PM_HC_DB')   ?: 'portalmanager';
$user = $args['user'] ?? getenv('PM_HC_USER') ?: 'root';
$pass = $args['pass'] ?? getenv('PM_HC_PASS') ?: '';

try { $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
catch (Throwable $e) { echo "✘ Connessione: " . $e->getMessage() . "\n"; exit(1); }

echo "→ Connesso a $user@$host/$db\n\n";
$ok = 0; $ko = 0;
function chk(string $lbl, bool $cond, string $extra = '') {
    global $ok, $ko;
    echo ($cond ? "✔" : "✘") . " " . str_pad($lbl, 55) . " " . $extra . "\n";
    $cond ? $ok++ : $ko++;
}

$row = $pdo->query("SELECT applied_at FROM pm_migration_sql WHERE version='1.9.29' LIMIT 1")->fetch();
chk("pm_migration_sql contiene 1.9.29", (bool)$row, $row ? "(applied_at={$row['applied_at']})" : '');

$row = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app_version'")->fetch();
chk("app_settings.app_version = 1.9.29", ($row['setting_value'] ?? '') === '1.9.29', "(={$row['setting_value']})");

foreach (['v_rsi_per_persona','v_rsi_per_contratto','v_rsi_dettaglio_commessa'] as $v) {
    try { $c = (int)$pdo->query("SELECT COUNT(*) FROM $v")->fetchColumn(); chk("Vista $v presente", true, "($c righe)"); }
    catch (Throwable $e) { chk("Vista $v presente", false, $e->getMessage()); }
}

// Colonne critiche
try {
    $cols = $pdo->query("SHOW COLUMNS FROM v_rsi_dettaglio_commessa")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['ticket','fascia','regime','ore','costo_contratto','tot_costo_tab','riga_formattata'] as $c)
        chk("Colonna $c in v_rsi_dettaglio_commessa", in_array($c, $cols, true));
} catch (Throwable $e) {
    chk("Ispezione vista", false, $e->getMessage());
}

// File PHP
$file = dirname(__DIR__) . '/report_servizi_it.php';
if (!is_file($file)) $file = realpath(__DIR__ . '/..') . '/report_servizi_it.php';
$has = is_file($file);
chk("report_servizi_it.php presente", $has, $has ? $file : '');
if ($has) {
    $src = file_get_contents($file) ?: '';
    chk("File contiene v1.9.29", strpos($src, 'v1.9.29') !== false);
    chk("Sezione 'Dettaglio per Commessa'", strpos($src, 'Dettaglio per Commessa') !== false);
    chk("Export CSV attivo", strpos($src, "'export' => 'csv'") !== false || strpos($src, "?export=csv") !== false);
}

echo "\n" . ($ko === 0 ? "[OK] Tutti i {$ok} check superati." : "[KO] {$ko} falliti, {$ok} superati.") . "\n";
exit($ko === 0 ? 0 : 1);
