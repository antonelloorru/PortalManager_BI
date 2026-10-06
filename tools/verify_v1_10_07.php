<?php
/**
 * tools/verify_v1_10_07.php — verifica della v1.10.07: pipeline unica del Service SOC, sottosezione SOC di
 * Sincronizzazione gestionale, mappatura dei tecnici sull'Unità Organizzativa SOC.
 * Uso: php tools/verify_v1_10_07.php --db=demo_portalmanager [--host= --user= --pass= --socket=]
 * Le prove di scrittura sulle unità girano in una transazione annullata.
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
require_once "$root/app/SocSync.php";
echo "→ Connesso a $user@$host/$db\n\n";
$ok = 0; $ko = 0;
function chk(string $lbl, bool $cond, string $extra = ''): void {
    global $ok, $ko;
    echo ($cond ? "✔" : "✘") . " " . str_pad($lbl, 70) . " " . $extra . "\n";
    $cond ? $ok++ : $ko++;
}
$file = fn(string $rel) => (string)@file_get_contents("$root/$rel");
$one = fn(string $sql) => $pdo->query($sql)->fetchColumn();

chk("app_settings.app_version = 1.10.07", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.07');
chk("VERSION = 1.10.07 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.07' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.07')"));
chk("pm_migration_sql contiene 1.10.06 e 1.10.07", (int)$one("SELECT COUNT(DISTINCT version) FROM pm_migration_sql WHERE version IN ('1.10.06','1.10.07')") === 2);
chk("Tabella cm_soc_sync_runs", (bool)$one("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cm_soc_sync_runs'"));
chk("Impostazioni soc.interval_min / inbox_dir / uo_code / uo_auto", (int)$one("SELECT COUNT(*) FROM app_settings WHERE setting_key IN ('soc.interval_min','soc.inbox_dir','soc.uo_code','soc.uo_auto')") === 4);
chk("Unità Organizzativa SOC presente", (bool)$one("SELECT COUNT(*) FROM cm_tech_units WHERE code = (SELECT setting_value FROM app_settings WHERE setting_key='soc.uo_code')"));
foreach (['app/SocSync.php', 'app/soc_sync_panel.php', 'app/soc_sync_actions.php', 'sql/migration_v1_10_07.sql'] as $f) chk("File $f", is_file("$root/$f"));
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_07.md", is_file("$root/docs/{$d}_v1_10_07.md"));

// interfaccia: un solo punto di sincronizzazione
$sync = $file('sync_commesse.php'); $page = $file('service_soc.php');
chk("Sincronizzazione gestionale: sottosezione SOC", str_contains($sync, "app/soc_sync_panel.php") && str_contains($sync, "app/soc_sync_actions.php"));
chk("Service SOC senza import/DB (rimandati a Sincronizzazione gestionale)", !str_contains($page, "'ingestion'") && !str_contains($page, 'importFile') && !str_contains($page, 'importDb') && str_contains($page, "'tab' => 'soc'"));
chk("Import da file e DB SOC solo nella pipeline", str_contains($file('app/soc_sync_actions.php'), 'SocSync::enqueue') && str_contains($file('app/soc_sync_actions.php'), "SocSync::run(\$pdo, 'caricamento'"));

// automazione: tutti gli inneschi convergono su SocSync::run
chk("Scheduler del portale: task «soc» (tick, firma, in-process)", str_contains($file('app/CronlessScheduler.php'), "'soc'") && str_contains($file('app/CronlessScheduler.php'), 'SocSync::isDue'));
chk("Worker: task «soc»", str_contains($file('cronless_worker.php'), "SocSync::run"));
chk("Sincronizzazione giornaliera: pipeline SOC in coda", str_contains($file('app/SyncRunner.php'), "SocSync::run(\$pdo, 'giornaliera'"));
chk("Riga di comando cron_soc_sync.php → SocSync::run", str_contains($file('cron_soc_sync.php'), 'SocSync::run'));
require_once "$root/app/CronlessScheduler.php";
$ts = time(); chk("Firma del worker valida per il task «soc»", CronlessScheduler::verify((string)$ts, '0', CronlessScheduler::sign($ts, false, 'soc'), 'soc'));
chk("Cartella di arrivo creata e protetta", is_dir(SocSync::inbox($pdo) . '/archivio') && is_file(SocSync::inbox($pdo) . '/.htaccess'));
$why = ''; SocSync::isDue($pdo, $why);
chk("Decisione «è il momento?» disponibile", is_bool(SocSync::isDue($pdo)), $why !== '' ? "($why)" : '');

// Unità Organizzativa SOC
$uid = SocSync::unitId($pdo);
$techs = SocSync::serviceTechnicians($pdo);
if ($techs) {
    $pdo->beginTransaction();
    try {
        $t = $techs[0];
        $pdo->prepare("UPDATE cm_tech_profiles SET unit_id = NULL WHERE employee_id = ?")->execute([$t['employee_id']]);
        $r = SocSync::assignUnit($pdo);
        $now = (int)$one("SELECT unit_id FROM cm_tech_profiles WHERE employee_id = " . (int)$t['employee_id']);
        chk("Tecnico senza unità → assegnato a SOC", $now === $uid && $r['assigned'] >= 1, '(' . $t['dipendente'] . ')');
        $other = (int)$one("SELECT id FROM cm_tech_units WHERE id <> $uid ORDER BY id LIMIT 1");
        $pdo->prepare("UPDATE cm_tech_profiles SET unit_id = ? WHERE employee_id = ?")->execute([$other, $t['employee_id']]);
        $r = SocSync::assignUnit($pdo);
        chk("Tecnico in altra unità → non spostato, segnalato", (int)$one("SELECT unit_id FROM cm_tech_profiles WHERE employee_id = " . (int)$t['employee_id']) === $other && count($r['conflicts']) >= 1);
        SocSync::assignUnit($pdo, [(int)$t['employee_id']]);
        chk("Spostamento forzato su SOC", (int)$one("SELECT unit_id FROM cm_tech_profiles WHERE employee_id = " . (int)$t['employee_id']) === $uid);
    } catch (Throwable $e) { chk("Prove Unità Organizzativa", false, $e->getMessage()); }
    $pdo->rollBack();
    $all = SocSync::serviceTechnicians($pdo);
    chk("Tecnici del servizio nell'unità SOC (dopo la sincronizzazione)", count(array_filter($all, fn($x) => (int)$x['unit_id'] === $uid)) >= 1, '(' . count(array_filter($all, fn($x) => (int)$x['unit_id'] === $uid)) . ' su ' . count($all) . ')');
} else chk("Tecnici del servizio abbinati a dipendenti", false, 'nessun ticket SOC o abbinamento');
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
