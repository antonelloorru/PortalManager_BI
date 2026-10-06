<?php
/**
 * tools/verify_v1_10_10.php — verifica della v1.10.10: Service SOC con categoria da tt_ticket.id_tt_category → tt_category,
 * filtro Categoria a scelta multipla nel blocco filtri principale, viste per categoria, un solo blocco filtri.
 * Uso: php tools/verify_v1_10_10.php --db=demo_portalmanager [--host= --user= --pass= --socket=] [--source=1]
 * --source=1 verifica anche sullo schema del DB SOC la query predefinita con la categoria (sola lettura).
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
foreach (['SourceDb', 'SocIngest', 'PmContractFilter', 'SocModel', 'PmCharts'] as $c) require_once "$root/app/$c.php";
echo "→ Connesso a $user@$host/$db\n\n";
$ok = 0; $ko = 0;
function chk(string $lbl, bool $cond, string $extra = ''): void {
    global $ok, $ko;
    echo ($cond ? "✔" : "✘") . " " . str_pad($lbl, 72) . " " . $extra . "\n";
    $cond ? $ok++ : $ko++;
}
$file = fn(string $rel) => (string)@file_get_contents("$root/$rel");
$one = fn(string $sql) => $pdo->query($sql)->fetchColumn();

chk("app_settings.app_version = 1.10.10", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.10');
chk("VERSION = 1.10.10 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.10' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.10')"));
chk("pm_migration_sql contiene 1.10.07 … 1.10.10", (int)$one("SELECT COUNT(DISTINCT version) FROM pm_migration_sql WHERE version IN ('1.10.07','1.10.08','1.10.09','1.10.10')") === 4);
chk("Impostazione soc.full_resync", $one("SELECT COUNT(*) FROM app_settings WHERE setting_key='soc.full_resync'") == 1);
chk("Indice cm_soc_tickets.category", (bool)$one("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cm_soc_tickets' AND INDEX_NAME='idx_soc_tickets_category'"));
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_10.md", is_file("$root/docs/{$d}_v1_10_10.md"));

$ing = $file('app/SocIngest.php'); $page = $file('service_soc.php');
chk("Query predefinita: tt_ticket.id_tt_category → tt_category", str_contains($ing, 'function defaultSql') && str_contains($ing, 'tk.id_tt_category') && str_contains($ing, 'tt_category c ON c.id'));
chk("Pipeline: rilettura completa una tantum (soc.full_resync)", str_contains($file('app/SocSync.php'), "soc.full_resync"));
chk("Filtro principale: Categoria a scelta multipla", str_contains($page, 'name="categoria[]" multiple'));
chk("Un solo blocco filtri (PM_NO_AUTOFILTER, nessun ListFilter)", str_contains($page, "\$GLOBALS['PM_NO_AUTOFILTER'] = true") && !preg_match('/ListFilter::render\w*\s*\(/', $page));
chk("Viste per categoria: grafici e matrice componente × categoria", str_contains($page, 'trendCategorie') && str_contains($page, 'teamCategorie') && str_contains($page, "'stacked' => true"));
chk("Barre impilate in PmCharts::groupedBars", str_contains(PmCharts::groupedBars(['a'], [['label' => 'x', 'color' => '#000', 'values' => [2]], ['label' => 'y', 'color' => '#111', 'values' => [3]]], ['stacked' => true]), 'totale 5'));

$m = new SocModel($pdo);
chk("listParam: array e stringa con virgole", SocModel::listParam(['A', ' B ', '', 'A']) === ['A', 'B'] && SocModel::listParam('A,B') === ['A', 'B']);
$tot = (int)$one("SELECT COUNT(*) FROM cm_soc_tickets");
if ($tot) {
    $f = $m->normFilters(['from' => '2000-01-01', 'to' => date('Y-m-d')]);
    $all = $m->countTickets($f);
    $cats = $pdo->query("SELECT COALESCE(NULLIF(category, ''), '" . SocModel::NO_CATEGORY . "') c, COUNT(*) n FROM cm_soc_tickets GROUP BY c ORDER BY n DESC")->fetchAll(PDO::FETCH_KEY_PAIR);
    $sum = 0; foreach (array_keys($cats) as $c) $sum += $m->countTickets($m->normFilters(['from' => '2000-01-01', 'to' => date('Y-m-d'), 'categoria' => [$c]]));
    chk("Filtro per categoria: la somma delle categorie = totale", $sum === $all, "($sum = $all)");
    $two = array_slice(array_keys($cats), 0, 2);
    $n2 = $m->countTickets($m->normFilters(['from' => '2000-01-01', 'to' => date('Y-m-d'), 'categoria' => $two]));
    chk("Scelta multipla = unione", count($two) < 2 || $n2 === array_sum(array_map(fn($c) => $m->countTickets($m->normFilters(['from' => '2000-01-01', 'to' => date('Y-m-d'), 'categoria' => [$c]])), $two)), '(' . implode(' + ', $two) . " = $n2)");
    $tc = $m->teamCategorie($f);
    chk("Matrice componente × categoria coerente col totale", array_sum($tc['tot']) === $all);
    $withCat = (int)$one("SELECT COUNT(*) FROM cm_soc_tickets WHERE COALESCE(category, '') <> ''");
    chk("Ticket con categoria", $withCat > 0, "($withCat su $tot)");
} else chk("Archivio ticket SOC presente", false, 'eseguire la pipeline');

if (($args['source'] ?? '') === '1') {
    $row = $pdo->query("SELECT * FROM cm_soc_source_db WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) echo "  DB SOC non configurato su questo database: verifica della sorgente saltata\n";
    else try {
        $src = SourceDb::connect(SourceDb::configFromRow(SocIngest::resolveSource($pdo, $row)));
        [$sql, $note] = SocIngest::defaultSql($src);
        chk("DB SOC: categoria disponibile", str_contains($sql, '`categoria`'), "($note)");
        $st = $src->query($sql . ' LIMIT 1', SocIngest::extractQuery($row, 3650, $src)[1]); $st->fetch(); $st->closeCursor();
        chk("DB SOC: query predefinita eseguibile", true);
    } catch (Throwable $e) { chk("DB SOC: query predefinita", false, SocIngest::connError($pdo, $e, $row)); }
}
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
