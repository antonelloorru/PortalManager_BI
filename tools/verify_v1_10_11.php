<?php
/**
 * tools/verify_v1_10_11.php — verifica della v1.10.11: Service SOC › Consuntivo attività SOC (componenti dell'Unità
 * Organizzativa SOC × moduli di intervento, per Tipologia di contratto e per operatore, modello Relazione di Servizio IT).
 * Uso: php tools/verify_v1_10_11.php --db=demo_portalmanager [--host= --user= --pass= --socket=] [--from=YYYY-MM-DD --to=YYYY-MM-DD]
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
$GLOBALS['PM_NO_SNAPSHOT'] = true;                         // confronto sulle viste in tempo reale
foreach (['PmContractFilter', 'SocModel', 'ItServiceModel'] as $c) require_once "$root/app/$c.php";
echo "→ Connesso a $user@$host/$db\n\n";
$ok = 0; $ko = 0;
function chk(string $lbl, bool $cond, string $extra = ''): void {
    global $ok, $ko;
    echo ($cond ? "✔" : "✘") . " " . str_pad($lbl, 72) . " " . $extra . "\n";
    $cond ? $ok++ : $ko++;
}
$file = fn(string $rel) => (string)@file_get_contents("$root/$rel");
$one = fn(string $sql) => $pdo->query($sql)->fetchColumn();
$eq = fn($x, $y) => abs((float)$x - (float)$y) < 0.05;

chk("app_settings.app_version = 1.10.11", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.11');
chk("VERSION = 1.10.11 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.11' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.11')"));
chk("pm_migration_sql contiene 1.10.07 … 1.10.11", (int)$one("SELECT COUNT(DISTINCT version) FROM pm_migration_sql WHERE version IN ('1.10.07','1.10.08','1.10.09','1.10.10','1.10.11')") === 5);
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_11.md", is_file("$root/docs/{$d}_v1_10_11.md"));
chk("Scheda «Consuntivo attività SOC» e pannello", str_contains($file('service_soc.php'), "'consuntivo' =>") && is_file("$root/app/soc_consuntivo_panel.php"));
chk("Calcolo delegato a ItServiceModel (totali, aggrega, andamento)", str_contains($file('service_soc.php'), '$itm->totali($cf)') && str_contains($file('service_soc.php'), "\$itm->aggrega(['gb' => ['linea_servizio', 'linea_label', 'modello_contratto']]"));
chk("Export XLSX: fogli Consuntivo tipologia / operatori / operatore x tipologia", str_contains($file('service_soc.php'), "'Consuntivo tipologia'") && str_contains($file('service_soc.php'), "'Operatore x tipologia'"));

$soc = new SocModel($pdo); $it = new ItServiceModel($pdo);
$membri = $soc->membriUo();
chk("Componenti dell'Unità Organizzativa SOC", count($membri) > 0, '(' . count($membri) . ')');
$f = $soc->normFilters(['from' => $args['from'] ?? '2000-01-01', 'to' => $args['to'] ?? date('Y-m-d')]);
[$cf, $info] = $soc->consuntivoFiltri($f, $it);
$tot = $it->totali($cf);
// stesso risultato della Relazione di Servizio IT sugli stessi incaricati (per nome) — senza ipotesi sul perimetro
$nomi = array_keys($it->incaricatiDipendenti($cf));
$g = $it->normFilters(['from' => $f['from'], 'to' => $f['to'], 'incaricati' => $nomi]);
$tIt = $it->totali($g);
chk("Totali = Relazione di Servizio IT sugli stessi operatori", $nomi && $eq($tot['ore'], $tIt['ore']) && $eq($tot['ore_ordinarie'], $tIt['ore_ordinarie']) && $eq($tot['ore_fuori_orario'], $tIt['ore_fuori_orario']) && $eq($tot['ore_reperibilita'], $tIt['ore_reperibilita']) && (int)$tot['giornate_uomo'] === (int)$tIt['giornate_uomo'],
    '(' . $tot['ore'] . ' h · ' . $tot['giornate_uomo'] . ' gg-uomo)');
chk("Classi di ore: ordinarie + fuori + reperibilità + non classificate = totale", $eq((float)$tot['ore_ordinarie'] + (float)$tot['ore_fuori_orario'] + (float)$tot['ore_reperibilita'] + (float)$tot['ore_non_classificate'], $tot['ore']));
$sTip = array_sum(array_column($it->aggrega(['gb' => ['linea_servizio', 'linea_label', 'modello_contratto']] + $cf, 500), 'ore'));
$sOp  = array_sum(array_column($it->aggrega(['gb' => ['incaricato']] + $cf, 500), 'ore'));
$sPiv = array_sum(array_column($it->aggrega(['gb' => ['incaricato', 'linea_label']] + $cf, 5000), 'ore'));
chk("Per tipologia = per operatore = matrice = totale", $eq($sTip, $tot['ore']) && $eq($sOp, $tot['ore']) && $eq($sPiv, $tot['ore']), "($sTip / $sOp / $sPiv)");
$ids = array_values($it->incaricatiDipendenti($cf));
chk("Solo componenti dell'unità SOC", !array_diff($ids, array_map('intval', array_keys($membri))));
$f2 = $soc->normFilters(['from' => $f['from'], 'to' => $f['to'], 'categoria' => [$one("SELECT category FROM cm_soc_tickets WHERE category IS NOT NULL GROUP BY category ORDER BY COUNT(*) DESC LIMIT 1") ?: 'x']]);
[$cf2, $i2] = $soc->consuntivoFiltri($f2, $it);
$t2 = $it->totali($cf2);
chk("Filtri sui ticket → moduli che riportano i ticket filtrati", $i2['ticket_filtrati'] !== null && (float)$t2['ore'] <= (float)$tot['ore'], '(' . $i2['ticket_filtrati'] . ' ticket, ' . $t2['ore'] . ' h)');
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
