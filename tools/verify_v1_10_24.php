<?php
/**
 * tools/verify_v1_10_24.php — verifica della v1.10.24: Gestione Commesse › Ricerca (cm_search.php, app/CmSearch.php), 13 ambiti
 * con le correlazioni del modulo, filtri per colonna, export CSV/XLSX/DOCX/PDF, permessi cm_search.php e cm_search_economics.php.
 * Esegue anche ogni ambito sul database indicato (conteggio, righe, filtri) per intercettare colonne o tabelle mancanti.
 * Uso: php tools/verify_v1_10_24.php --db=demo_portalmanager [--host= --user= --pass= --socket=]
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

chk("app_settings.app_version = 1.10.24", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.24');
chk("VERSION = 1.10.24 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.24' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.24')"));
chk("pm_migration_sql contiene 1.10.24", (int)$one("SELECT COUNT(*) FROM pm_migration_sql WHERE version = '1.10.24'") === 1);
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_24.md", is_file("$root/docs/{$d}_v1_10_24.md"));
$cms = $file('app/CmSearch.php');
$pg  = $file('cm_search.php');
chk("Pagina cm_search.php: permesso view, export solo con can export", str_contains($pg, "can('view', 'cm_search.php')") && str_contains($pg, "can('export', 'cm_search.php')") && str_contains($pg, '!$can_export || !$d'));
chk("Colonne economiche solo con cm_search_economics.php", str_contains($pg, "can('view', 'cm_search_economics.php')") && str_contains($cms, "str_contains(\$c['f'], 'e')"));
chk("Ambiti filtrati per pagina sorgente (gate)", str_contains($cms, "foreach (\$d['gate'] as \$pg) if (\$can('view', \$pg))") && str_contains($pg, "\$S->allowed('can')"));
chk("Whitelist colonne/filtri/ordinamento, valori come parametri", str_contains($cms, "isset(\$vis[\$k])") && str_contains($cms, "isset(\$vis[\$g['s']])") && str_contains($cms, "addcslashes(\$v, '%_\\\\')"));
chk("Export nei 4 formati via PmReport, log dell'export", str_contains($pg, 'PmReport::FORMATS[$fmt]') && str_contains($pg, "write_log('CmSearch'") && str_contains($cms, "new PmReport('Ricerca — '"));
chk("Limiti export 50.000 (XLSX/CSV) e 3.000 (DOCX/PDF)", str_contains($cms, 'MAX_FILE = 50000') && str_contains($cms, 'MAX_DOC  = 3000'));
chk("Router: slug opaco per cm_search", str_contains($file('app/Router.php'), "'manage_projects', 'cm_search',"));
chk("Menu Gestione Commesse: voce Ricerca", str_contains($file('app/MenuManager.php'), "['page' => 'cm_search',"));
chk("Catalogo permessi: cm_search.php e cm_search_economics.php", str_contains($file('app/PermissionCatalog.php'), "'cm_search.php'") && str_contains($file('app/PermissionCatalog.php'), "'cm_search_economics.php'"));
chk("DB: permessi a catalogo", (int)$one("SELECT COUNT(*) FROM permissions WHERE name IN ('cm_search.php','cm_search_economics.php')") === 2);
chk("DB: Super Admin con Ricerca ed economici", (int)$one("SELECT COUNT(*) FROM role_permissions WHERE role_id = 1 AND can_view = 1 AND page_name IN ('cm_search.php','cm_search_economics.php')") === 2);
require_once "$root/app/CmSearch.php";
$S = new CmSearch($pdo, true); $errs = []; $n = 0;
foreach (CmSearch::registry() as $k => $x) {
    $d = $S->def($k); $all = array_keys(CmSearch::visibleCols($d));
    try {
        $p = CmSearch::params(['q' => 'a', 'da' => '2020-01-01', 'a' => date('Y-m-d')], $d); $a = []; $b = [];
        $w = $S->where($d, $p, $a, $b); $S->summary($d, $w, $a, $all); $S->rows($d, $w, $a, $all, $p['s'], $p['d'], 3);
        $f = []; foreach ($d['cols'] as $c => $cd) if (!str_contains($cd['f'], 'h')) $f[$c] = match ($cd['t']) { 'date', 'datetime' => '>=2020', 'bool' => 'no', 'int', 'num', 'hours', 'eur' => '0..999999', default => '!zz' };
        $p = CmSearch::params(['f' => $f], $d); $w = $S->where($d, $p, $a, $b); $S->summary($d, $w, $a, $all);
        $n++;
    } catch (Throwable $e) { $errs[] = "$k: " . $e->getMessage(); }
}
chk("13 ambiti eseguibili sul database (testo, periodo, filtri su ogni colonna)", $n === 13 && !$errs, $errs ? implode(' | ', $errs) : "$n ambiti");
$e = new CmSearch($pdo, false); $eco = 0;
foreach (CmSearch::registry() as $k => $x) foreach ($e->def($k)['cols'] as $c) if (str_contains($c['f'], 'e')) $eco++;
chk("Senza permesso economico nessuna colonna economica nel registro", $eco === 0);
$c = ['x' => 'v', 't' => 'date', 'f' => ''];
chk("Sintassi filtri: date, numeri, testo, sì/no", CmSearch::cond($c, '2026-03')[1] === ['2026-03-01', '2026-03-31'] && CmSearch::cond(['x' => 'v', 't' => 'eur', 'f' => ''], '>=1.234,5')[1] === [1234.5]
    && CmSearch::cond(['x' => 'v', 't' => 'text', 'f' => ''], 'a|b')[1] === ['%a%', '%b%'] && CmSearch::cond(['x' => 'v', 't' => 'bool', 'f' => ''], 'sì')[0] === 'COALESCE(v, 0) <> 0' && CmSearch::cond($c, 'pippo') === null);
$rep = $S->report('commesse', $S->def('commesse'), CmSearch::params([], $S->def('commesse')), 'csv');
chk("Report di export costruito (CSV con filtri e totale)", str_contains($rep['report']->toCsv(), 'Ricerca — Commesse') && str_contains($rep['report']->toCsv(), 'Totale'));
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
