<?php
/**
 * tools/verify_v1_10_32.php — verifica della v1.10.32: Relazione Tecnici › Controllo Reperibilità. Esegue le letture sul database indicato.
 * Uso: php tools/verify_v1_10_32.php --db=demo_portalmanager [--host= --user= --pass= --socket=]
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

chk("app_settings.app_version = 1.10.32", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.32');
chk("VERSION = 1.10.32 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.32' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.32')"));
chk("pm_migration_sql contiene 1.10.32", (int)$one("SELECT COUNT(*) FROM pm_migration_sql WHERE version = '1.10.32'") === 1);
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_32.md", is_file("$root/docs/{$d}_v1_10_32.md"));
$im = $file('app/ItServiceModel.php'); $tr = $file('app/TechReport.php'); $pg = $file('tech_report.php');
require_once "$root/app/TechReport.php";
chk("Scheda Controllo Reperibilità registrata", (TechReport::TABS['reperibilita'] ?? '') === 'Controllo Reperibilità');
chk("Colonne (8) nell'ordine richiesto", TechReport::H_REP === ['Tecnico / Incaricato', 'Data/Ora Reperibilità', 'Rif. Modulo Intervento (reperibilità)', 'Data/Ora Giorno Succ.', 'Rif. Modulo Intervento (giorno succ.)', 'Cliente', 'Codice Commessa', 'Tipo']);
chk("Fasce 18:01–08:59 e 09:00–18:00", ItServiceModel::REP_NOTTE === ['18:01:00', '09:00:00'] && ItServiceModel::REP_GIORNO === ['09:00:00', '18:00:59']);
chk("Giorno lavorativo successivo (weekend e festivi)", ItServiceModel::prossimoLavorativo('2026-04-03') === '2026-04-07' && ItServiceModel::prossimoLavorativo('2026-12-24') === '2026-12-28' && ItServiceModel::prossimoLavorativo('2026-09-07') === '2026-09-08');
chk("giorniLavorabili invariato (2026 = 254)", ItServiceModel::giorniLavorabili('2026-01-01', '2026-12-31') === 254);
chk("Filtri di colonna: vista, form, link", str_contains($pg, 'class="tr-cf"') && str_contains($pg, "TechReport::colFiltri(\$_GET['cf'] ?? [])") && str_contains($pg, "u.searchParams.set('cf['"));
chk("Filtri di colonna normalizzati", TechReport::colFiltri(['0' => ' andrea ', 'x' => 'k', '9' => 'oob', '7' => '']) === [0 => 'andrea']);
$errs = [];
try {
    $m = new ItServiceModel($pdo); $f = $m->normFilters(['contratti_set' => 1, 'from' => '2026-01-01', 'to' => '2026-09-30']);
    $d = $m->controlloReperibilita($f);
    $okR = true;
    foreach ($d['righe'] as $x) {
        $tr0 = substr($x['rep_inizio'], 11); $ts = substr($x['succ_inizio'], 11);
        $okR = $okR && ($tr0 >= '18:01:00' || $tr0 < '09:00:00') && $ts >= '09:00:00' && $ts <= '18:00:59'
                    && substr($x['succ_inizio'], 0, 10) === ItServiceModel::prossimoLavorativo($x['turno']) && $x['succ_inizio'] >= ($x['rep_fine'] ?: $x['rep_inizio']);
    }
    $TR = new TechReport($m); $f['cf'] = [0 => mb_substr((string)($d['righe'][0]['tecnico'] ?? 'zzz'), 0, 4)];
    $dd = $TR->data('reperibilita', $f); $csv = $TR->build('reperibilita', $f, $dd, '', 'csv')->toCsv();
    $okC = count($dd['righe']) <= count($d['righe']) && $dd['casi'] === count($d['righe']) && str_contains($csv, 'Rif. Modulo Intervento (giorno succ.)');
    $okL = $okR && $okC;
} catch (Throwable $e) { $errs[] = $e->getMessage(); $okL = false; }
chk("Letture eseguibili (correlazione coerente, export)", $okL && !$errs, $errs ? implode(' | ', $errs) : ($d['notturni'] ?? 0) . ' interventi in reperibilità · ' . count($d['righe'] ?? []) . ' casi');
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
