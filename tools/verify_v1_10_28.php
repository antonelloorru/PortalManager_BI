<?php
/**
 * tools/verify_v1_10_28.php — verifica della v1.10.28: Relazione Tecnici, colonna «Linea di servizio» a destra di «Codice linea»
 * nelle schede Tecnici e Rapporti di intervento (vista, drill-down, stampa ed export). Esegue le letture sul database indicato.
 * Uso: php tools/verify_v1_10_28.php --db=demo_portalmanager [--host= --user= --pass= --socket=]
 */
declare(strict_types=1);
$args = [];
foreach (array_slice($argv, 1) as $a) if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? '1';
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
echo "→ Connesso a $user@$host/$db\n\n";
$ok = 0; $ko = 0;
function chk(string $lbl, bool $cond, string $extra = ''): void {
    global $ok, $ko;
    echo ($cond ? "✔" : "✘") . " " . str_pad($lbl, 80) . " " . $extra . "\n";
    $cond ? $ok++ : $ko++;
}
$file = fn(string $rel) => (string)@file_get_contents("$root/$rel");
$one = fn(string $sql) => $pdo->query($sql)->fetchColumn();
$pd = 'integrations/wordpress/pm-ats';

chk("app_settings.app_version = 1.10.28", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.28');
chk("VERSION = 1.10.28 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.28' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.28')"));
chk("pm_migration_sql contiene 1.10.28", (int)$one("SELECT COUNT(*) FROM pm_migration_sql WHERE version = '1.10.28'") === 1);
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_28.md", is_file("$root/docs/{$d}_v1_10_28.md"));
$tr = $file('app/TechReport.php'); $pg = $file('tech_report.php');
require_once "$root/app/TechReport.php";
chk("Tecnici: intestazioni con Linea di servizio dopo Codice linea", TechReport::H_MAIN[1] === 'Codice linea' && TechReport::H_MAIN[2] === 'Linea di servizio' && TechReport::H_DET[1] === 'Codice linea' && TechReport::H_DET[2] === 'Linea di servizio');
chk("Rapporti: per commessa e dettaglio moduli", str_contains($tr, "'Cliente', 'Codice linea', 'Linea di servizio', 'Tipologia'") && str_contains($tr, "'Tecnico', 'Codice linea', 'Linea di servizio', 'Tipologia'"));
chk("Vista: tabella per commessa e drill-down", str_contains($pg, '<th>Codice linea</th><th>Linea di servizio</th><th>Tipologia</th>') && str_contains($pg, '<th>Codice linea</th><th>Linea di servizio</th><th>Modalità</th>'));
$errs = [];
try {
    $m = new ItServiceModel($pdo); $f = $m->normFilters(['contratti_set' => 1]);
    $tl = $m->tecniciLinea($f); $cm = $m->rapportiCommessa($f, 5); $mm = $m->rapportiModuli($f, null, 5);
    $row = TechReport::main(TechReport::gruppi($tl)[0] ?? ['tipo' => 'riga', 'tecnico' => '', 'r' => ['codice_linea' => '', 'linea_label' => '', 'attivita' => 0, 'ticket' => 0, 'giornate_uomo' => 0, 'ore' => 0]], 22);
    $okL = count($row) === count(TechReport::H_MAIN) && (!$tl['righe'] || array_key_exists('linea_label', $tl['righe'][0])) && (!$cm || array_key_exists('linea_label', $cm[0])) && (!$mm || array_key_exists('linea_label', $mm[0]));
} catch (Throwable $e) { $errs[] = $e->getMessage(); $okL = false; }
chk("Letture con linea_label e righe allineate alle intestazioni", $okL && !$errs, implode(' | ', $errs));
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
