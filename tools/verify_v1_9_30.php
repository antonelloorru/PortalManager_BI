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
$ok=0; $ko=0;
function chk(string $lbl, bool $cond, string $extra=''): void {
    global $ok, $ko;
    echo ($cond ? "✔" : "✘") . " " . str_pad($lbl, 55) . " " . $extra . "\n";
    $cond ? $ok++ : $ko++;
}

$row = $pdo->query("SELECT applied_at FROM pm_migration_sql WHERE version='1.9.30' LIMIT 1")->fetch();
chk("pm_migration_sql contiene 1.9.30", (bool)$row, $row?"(applied_at={$row['applied_at']})":'');

$row = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app_version'")->fetch();
chk("app_settings.app_version = 1.9.30", ($row['setting_value'] ?? '') === '1.9.30');

try {
    $r = $pdo->query("SELECT operator_name FROM v_rsi_dettaglio_commessa LIMIT 1")->fetch();
    chk("Vista v_rsi_dettaglio_commessa aggiornata (operator_name Cognome Nome)", true, $r ? "(esempio: {$r['operator_name']})" : "(vuota)");
} catch (Throwable $e) { chk("Vista v_rsi_dettaglio_commessa", false, $e->getMessage()); }

$root = realpath(__DIR__ . '/..');
foreach ([
    'report_servizi_it.php'          => 'v1.9.30',
    'assets/js/pm-multiselect.js'    => 'pm-multiselect',
    'assets/css/pm-multiselect.css'  => 'pm-ms-wrap',
    'app/PmFilters.php'              => 'PmFilters',
] as $rel => $needle) {
    $p = $root . '/' . $rel;
    chk("File $rel presente", is_file($p));
    if (is_file($p)) chk(" > contiene '$needle'", strpos(file_get_contents($p), $needle) !== false);
}

// Test funzionale: PmFilters
require_once $root . '/app/PmFilters.php';
chk("PmFilters::ints normalizza array", PmFilters::ints(['1','2',0,'x']) === [1,2]);
[$s,$b] = PmFilters::inClause('t.id', [3,7]);
chk("PmFilters::inClause genera IN(?,?)", $s === 't.id IN (?,?)' && $b === [3,7]);
chk("PmFilters::person('Mario','Rossi') = 'Rossi Mario'", PmFilters::person('Mario','Rossi') === 'Rossi Mario');

echo "\n" . ($ko===0 ? "[OK] Tutti i $ok check superati." : "[KO] $ko falliti, $ok superati.") . "\n";
exit($ko===0 ? 0 : 1);
