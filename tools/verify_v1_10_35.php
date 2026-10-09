<?php
/**
 * tools/verify_v1_10_35.php — verifica della v1.10.35: Relazione Tecnici › ServiceDesk. Esegue le letture sul database indicato.
 * Uso: php tools/verify_v1_10_35.php --db=demo_portalmanager [--host= --user= --pass= --socket=]
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

chk("app_settings.app_version = 1.10.35", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.35');
chk("VERSION = 1.10.35 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.35' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.35')"));
chk("pm_migration_sql contiene 1.10.35", (int)$one("SELECT COUNT(*) FROM pm_migration_sql WHERE version = '1.10.35'") === 1);
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_35.md", is_file("$root/docs/{$d}_v1_10_35.md"));
$im = $file('app/ItServiceModel.php'); $pg = $file('tech_report.php');
require_once "$root/app/TechReport.php";
chk("Scheda ServiceDesk registrata", (TechReport::TABS['servicedesk'] ?? '') === 'ServiceDesk');
chk("Costanti WTS-SD / Service Desk", ItServiceModel::SD_LINEA === 'WTS-SD' && ItServiceModel::SD_UO === 'Service Desk');
chk("Formule SQL esposte (7 metriche)", count(ItServiceModel::sdDefinizioni()) === 7);
chk("Esclusione per UO nel pannello e nei link", str_contains($pg, 'name="uo_escl[]"') && str_contains($pg, "\$p['uo_escl']"));
chk("Valori economici protetti lato server", str_contains($file('app/TechReport.php'), "\$c['valore'] = \$c['valore_periodo'] = null"));
$errs = []; $info = '';
try {
    $m = new ItServiceModel($pdo); $sd = $m->sdUnitId();
    $f = $m->normFilters(['contratti_set' => 1, 'from' => '2026-01-01', 'to' => '2026-09-30']);
    $d = $m->serviceDesk($f, $sd ? [$sd] : []); $k = $d['kpi'];
    $d0 = $m->serviceDesk($f, []);
    $nC = (int)$one("SELECT COUNT(*) FROM cm_projects WHERE service_line = 'WTS-SD' AND start_date <= '2026-09-30' AND end_date >= '2026-01-01'");
    $tk = (int)$one("SELECT COUNT(DISTINCT TRIM(ir.ticket)) FROM v_cm_it_servizio s JOIN cm_intervention_reports ir ON ir.id = s.report_id WHERE s.giorno BETWEEN '2026-01-01' AND '2026-09-30' AND s.linea_servizio = 'WTS-SD' AND ir.ticket REGEXP '" . ItServiceModel::TICKET_RE . "'");
    $sumR = array_sum(array_map(fn($c) => (int)$c['moduli'] > 0 ? (int)$c['risorse'] : 0, $d['contratti']));
    $okM = $k['contratti'] >= $nC && $k['ticket'] === $tk && $k['ticket_altri'] <= $k['ticket'] && $k['ticket_solo_escl'] === $k['ticket'] - $k['ticket_altri']
        && ($k['contratti_moduli'] === 0 || abs($k['media_risorse'] - round($sumR / $k['contratti_moduli'], 2)) < 0.01)
        && $d0['kpi']['ticket_altri'] === $d0['kpi']['ticket'] && abs((float)$k['valore'] - array_sum(array_map(fn($c) => (float)$c['valore'], $d['contratti']))) < 0.01;
    $TR = new TechReport($m, false); $f['uo_escl'] = $sd ? [$sd] : []; $dd = $TR->data('servicedesk', $f);
    $csv = $TR->build('servicedesk', $f, $dd, '', 'csv')->toCsv();
    $okE = $dd['kpi']['valore'] === null && !str_contains($csv, 'Valore totale €');
    $okL = $okM && $okE && $sd !== null;
    $info = "{$k['contratti']} contratti · {$k['ticket']} ticket · {$k['ticket_altri']} altri team ({$k['pct_altri']}%) · media risorse {$k['media_risorse']}";
} catch (Throwable $e) { $errs[] = $e->getMessage(); $okL = false; }
chk("Letture eseguibili (COUNT/AVG coerenti, esclusione, permessi)", $okL && !$errs, $errs ? implode(' | ', $errs) : $info);
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
