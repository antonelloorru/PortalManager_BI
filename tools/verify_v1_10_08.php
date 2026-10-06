<?php
/**
 * tools/verify_v1_10_08.php — verifica della v1.10.08: il DB SOC usa la stessa logica di connessione della
 * «Connessione al gestionale» (SourceDb::configFromRow + SourceDb::connect) e può ereditarne server e credenziali.
 * Uso: php tools/verify_v1_10_08.php --db=demo_portalmanager [--host= --user= --pass= --socket=] [--connect=1]
 * --connect=1 prova anche la connessione reale al DB SOC con i parametri effettivi.
 */
declare(strict_types=1);
$args = [];
foreach (array_slice($argv, 1) as $a) if (preg_match('/^--([^=]+)=(.*)$/', $a, $m)) $args[$m[1]] = $m[2];
$host = $args['host'] ?? getenv('PM_HC_HOST') ?: '127.0.0.1';
$db   = $args['db']   ?? getenv('PM_HC_DB')   ?: 'portalmanager';
$user = $args['user'] ?? getenv('PM_HC_USER') ?: 'root';
$pass = $args['pass'] ?? getenv('PM_HC_PASS') ?: '';
$sock = $args['socket'] ?? '';
try {
    $dsn = $sock !== '' ? "mysql:unix_socket=$sock;dbname=$db;charset=utf8mb4" : "mysql:host=$host;dbname=$db;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
} catch (Throwable $e) { echo "✘ Connessione: " . $e->getMessage() . "\n"; exit(1); }
$root = realpath(__DIR__ . '/..');
if (!defined('APP_BASE')) define('APP_BASE', $root);
require_once "$root/app/Env.php"; if (method_exists('Env', 'load')) { try { Env::load(); } catch (Throwable $e) {} }
require_once "$root/app/SourceDb.php";
require_once "$root/app/SocIngest.php";
echo "→ Connesso a $user@$host/$db\n\n";
$ok = 0; $ko = 0;
function chk(string $lbl, bool $cond, string $extra = ''): void {
    global $ok, $ko;
    echo ($cond ? "✔" : "✘") . " " . str_pad($lbl, 72) . " " . $extra . "\n";
    $cond ? $ok++ : $ko++;
}
$file = fn(string $rel) => (string)@file_get_contents("$root/$rel");
$one = fn(string $sql) => $pdo->query($sql)->fetchColumn();

chk("app_settings.app_version = 1.10.08", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.08');
chk("VERSION = 1.10.08 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.08' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.08')"));
chk("pm_migration_sql contiene 1.10.07 e 1.10.08", (int)$one("SELECT COUNT(DISTINCT version) FROM pm_migration_sql WHERE version IN ('1.10.07','1.10.08')") === 2);
chk("Colonna cm_soc_source_db.use_gestionale", (bool)$one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cm_soc_source_db' AND COLUMN_NAME='use_gestionale'"));
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_08.md", is_file("$root/docs/{$d}_v1_10_08.md"));

// stessa logica di connessione: gestionale e SOC passano da SourceDb::configFromRow + SourceDb::connect
$ing = $file('app/SocIngest.php'); $act = $file('app/soc_sync_actions.php'); $run = $file('app/SyncRunner.php');
chk("Gestionale (pianificata): SourceDb::configFromRow + connect", str_contains($run, 'SourceDb::configFromRow($src)') && str_contains($run, 'SourceDb::connect($srcCfg)'));
chk("DB SOC (pipeline): resolveSource → configFromRow → connect", substr_count($ing, 'SourceDb::connect(SourceDb::configFromRow(') >= 2 && str_contains($ing, 'self::resolveSource($this->pdo, $cfgRow)'));
chk("DB SOC (test/anteprima): resolveSource → configFromRow → connect", str_contains($act, 'SocIngest::resolveSource($pdo, $row)') && str_contains($act, 'SourceDb::connect(SourceDb::configFromRow($row))'));
chk("Messaggi d'errore guidati (1045/1698/1044/1049)", str_contains($ing, 'function connError') && str_contains($act, 'SocIngest::connError'));
chk("Interfaccia: opzione «Usa server e credenziali della Connessione al gestionale»", str_contains($file('app/soc_sync_panel.php'), 'name="use_gestionale"'));

// ereditarietà
$g = $pdo->query("SELECT * FROM cm_source_db WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
$s = $pdo->query("SELECT * FROM cm_soc_source_db ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
if ($g) {
    $probe = ['use_gestionale' => 1, 'driver' => 'x', 'host' => 'x', 'port' => 1, 'username' => 'x', 'password_enc' => '', 'dbname' => 'db_soc', 'timeout' => 10];
    $r = SocIngest::resolveSource($pdo, $probe);
    chk("Eredità: driver/host/porta/utente/password dal gestionale, database proprio",
        $r['driver'] === $g['driver'] && $r['host'] === $g['host'] && (int)$r['port'] === (int)$g['port'] && $r['username'] === $g['username']
        && $r['password_enc'] === $g['password_enc'] && $r['dbname'] === 'db_soc' && $r['cred_origin'] === 'gestionale', "({$g['username']}@{$g['host']}:{$g['port']})");
    $c1 = SourceDb::configFromRow($g); $c2 = SourceDb::configFromRow($r);
    chk("Stessa password decifrata e stessi parametri, cambia solo il database",
        $c1['password'] === $c2['password'] && $c1['host'] === $c2['host'] && $c1['username'] === $c2['username'] && $c2['dbname'] === 'db_soc');
} else chk("Connessione al gestionale configurata", false, 'cm_source_db attiva assente');
$own = SocIngest::resolveSource($pdo, ['use_gestionale' => 0, 'host' => 'h']);
chk("Senza eredità restano i parametri propri", $own['host'] === 'h' && $own['cred_origin'] === 'propria');
$e = new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'u'@'h' (using password: YES)");
chk("Errore 1045 con indicazione operativa", str_contains(SocIngest::connError($pdo, $e, ['use_gestionale' => 1]), 'Connessione al gestionale'));
if ($s) echo "\n  DB SOC configurato: database {$s['dbname']}, " . (!empty($s['use_gestionale']) ? 'credenziali del gestionale' : "credenziali proprie ({$s['username']}@{$s['host']})") . "\n";
if (($args['connect'] ?? '') === '1' && $s) {
    try { $src = SourceDb::connect(SourceDb::configFromRow(SocIngest::resolveSource($pdo, $s))); chk("Connessione reale al DB SOC", true, $src->serverVersion()); }
    catch (Throwable $x) { chk("Connessione reale al DB SOC", false, SocIngest::connError($pdo, $x, $s)); }
}
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
