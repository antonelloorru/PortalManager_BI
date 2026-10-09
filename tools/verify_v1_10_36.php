<?php
/**
 * tools/verify_v1_10_36.php — verifica della v1.10.36: Service Desk › Ticket e Attività dei clienti (WTS_3119) scorporata. Esegue le letture sul database indicato.
 * Uso: php tools/verify_v1_10_36.php --db=demo_portalmanager [--host= --user= --pass= --socket=]
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

chk("app_settings.app_version = 1.10.36", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.36');
chk("VERSION = 1.10.36 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.36' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.36')"));
chk("pm_migration_sql contiene 1.10.36", (int)$one("SELECT COUNT(*) FROM pm_migration_sql WHERE version = '1.10.36'") === 1);
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_36.md", is_file("$root/docs/{$d}_v1_10_36.md"));
$sm = $file('app/SdModel.php'); $pg = $file('service_desk.php'); $rp = $file('app/SdReport.php');
require_once "$root/app/SdModel.php";
chk("Costanti WTS_3119 / etichetta", SdModel::CLIENTI_COMMESSA === 'WTS_3119' && SdModel::CLIENTI_ETICHETTA === 'Ticket e Attività dei clienti');
chk("Scorporo su 13 aggregati dei moduli", substr_count($sm, '$this->scorporo(') === 13);
chk("Costi senza scorporo", str_contains($sm, 'costi: perimetro valorizzato (non solo UO Service Desk), nessuno scorporo'));
chk("Voce in pagina, stampa, report, Dati XLSX", str_contains($pg, 'class="card sd-cli"') && substr_count($pg, '$aCli') >= 6 && str_contains($pg, "\$acx = \$sd->attivitaClienti(\$f)") && str_contains($rp, 'attivitaClienti($f)'));
chk("XlsxWriter: fogli prima di sharedStrings", strpos($file('app/XlsxWriter.php'), '$sheetXml[$idx++]') < strpos($file('app/XlsxWriter.php'), "'xl/sharedStrings.xml'"));
$errs = []; $info = '';
try {
    $sd = new SdModel($pdo); $f = $sd->normFilters(['from' => '2026-01-01', 'to' => '2026-09-30', 'contratti_set' => 1]);
    $ac = $sd->attivitaClienti($f); $tq = $sd->teamQuadro($f);
    $tot = (int)$one("SELECT COUNT(*) FROM v_cm_sd_moduli WHERE giorno BETWEEN '2026-01-01' AND '2026-09-30'");
    $cli = (int)$one("SELECT COUNT(*) FROM v_cm_sd_moduli WHERE giorno BETWEEN '2026-01-01' AND '2026-09-30' AND commessa = 'WTS_3119'");
    $dett = array_sum(array_map(fn($x) => (int)$x['moduli'], $sd->teamDettaglio($f)));
    $o21 = (int)($sd->obj21Quadro($f)['interventi'] ?? -1);
    $f2 = $sd->normFilters(['from' => '2026-01-01', 'to' => '2026-09-30', 'contratti' => 'WTS_3040']);
    $okL = $ac['attivita'] === $cli && (int)$tq['moduli'] + $ac['attivita'] === $tot && $dett === (int)$tq['moduli'] && $o21 === (int)$tq['moduli'] && $sd->attivitaClienti($f2)['attivita'] === 0;
    require_once "$root/app/XlsxWriter.php";
    $w = new XlsxWriter(); $w->addSheet('T', [['a', 'b'], ['0123', '08'], ['x', 1]]); $tmp = tempnam(sys_get_temp_dir(), 'v36'); $w->writeToFile($tmp);
    $z = new ZipArchive(); $z->open($tmp); $ss = (string)$z->getFromName('xl/sharedStrings.xml'); $z->close(); @unlink($tmp);
    $okX = str_contains($ss, '>0123<') && str_contains($ss, '>08<');
    $okL = $okL && $okX;
    $info = "WTS_3119: {$ac['ticket']} ticket · {$ac['attivita']} attività · team {$tq['moduli']} + {$ac['attivita']} = $tot";
} catch (Throwable $e) { $errs[] = $e->getMessage(); $okL = false; }
chk("Letture eseguibili (voce, scorporo, somma, filtro contratto, XLSX)", $okL && !$errs, $errs ? implode(' | ', $errs) : $info);
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
