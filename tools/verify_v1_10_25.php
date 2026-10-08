<?php
/**
 * tools/verify_v1_10_25.php — verifica della v1.10.25: Gestione Commesse › Relazione Tecnici (tech_report.php, app/TechReport.php,
 * letture e filtri nuovi di ItServiceModel), permessi tech_report.php / tech_report_economics.php. Esegue le letture sul database indicato.
 * Uso: php tools/verify_v1_10_25.php --db=demo_portalmanager [--host= --user= --pass= --socket=]
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

chk("app_settings.app_version = 1.10.25", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.25');
chk("VERSION = 1.10.25 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.25' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.25')"));
chk("pm_migration_sql contiene 1.10.25", (int)$one("SELECT COUNT(*) FROM pm_migration_sql WHERE version = '1.10.25'") === 1);
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_25.md", is_file("$root/docs/{$d}_v1_10_25.md"));
require_once "$root/app/TechReport.php";
$pg = $file('tech_report.php'); $tr = $file('app/TechReport.php'); $im = $file('app/ItServiceModel.php');
chk("Pagina: permesso view, export con can export, valori con tech_report_economics.php", str_contains($pg, "can('view', 'tech_report.php')") && str_contains($pg, "can('export', 'tech_report.php')") && str_contains($pg, "can('view', 'tech_report_economics.php')"));
chk("Filtri unificati della Relazione IT (normFilters) + tipologia e provenienza", str_contains($pg, '$m->normFilters($_GET)') && str_contains($pg, 'name="tipologie[]"') && str_contains($pg, 'name="prov[]"') && str_contains($im, "'tipologie' => array_slice(") && str_contains($im, "'prov'      => array_values("));
chk("Colonne principali (Tecnico, Codice linea, N. attività, N. ticket, GG lavorabili, GG uomo, ore, fascia di costo)", str_contains($tr, "H_MAIN = ['Tecnico', 'Codice linea', 'N. attività', 'N. ticket', 'GG lavorabili', 'GG uomo lavorati', 'N. ore lavorate', 'Fascia di costo']"));
chk("Metriche di dettaglio", str_contains($tr, "H_DET  = ['Tecnico', 'Codice linea', 'Giornate-uomo', 'Ore cons.', 'Ordinarie', 'Fuori orario', 'Reperib.', 'Extra dich.', 'Presso cl.', 'Remoto', 'Smart']"));
chk("Moduli valorizzati / non valorizzati", str_contains($tr, "'val' => 'Valorizzati', 'nv' => 'Non valorizzati'") && str_contains($im, 'public function valorizzazione('));
chk("Rapporti: tipologia, commessa, provenienza ticket, drill-down ed export commessa", str_contains($im, 'public function rapportiTipologia(') && str_contains($im, 'public function rapportiCommessa(') && str_contains($pg, "'ajax'] ?? '') === 'moduli'") && str_contains($pg, "'rep' => 'xlsx', 'commessa' => \$code"));
chk("Stampa ed export 4 formati via PmReport, log", str_contains($pg, '$rep->toHtml(true)') && str_contains($pg, 'PmReport::FORMATS[$repFmt]') && str_contains($pg, "write_log('TechReport'"));
chk("Router, menu, catalogo permessi", str_contains($file('app/Router.php'), "'tech_report'") && str_contains($file('app/MenuManager.php'), "['page' => 'tech_report',") && str_contains($file('app/PermissionCatalog.php'), "'tech_report_economics.php'"));
chk("DB: permessi a catalogo e Super Admin", (int)$one("SELECT COUNT(*) FROM permissions WHERE name IN ('tech_report.php','tech_report_economics.php')") === 2
    && (int)$one("SELECT COUNT(*) FROM role_permissions WHERE role_id = 1 AND can_view = 1 AND page_name IN ('tech_report.php','tech_report_economics.php')") === 2);
chk("Giorni lavorabili: set 2026 = 22, apr 2026 = 21 (Pasquetta 6/4, 25/4 sabato), dic 2026 = 21", ItServiceModel::giorniLavorabili('2026-09-01', '2026-09-30') === 22
    && ItServiceModel::giorniLavorabili('2026-04-01', '2026-04-30') === 21 && ItServiceModel::giorniLavorabili('2026-12-01', '2026-12-31') === 21);
$errs = [];
try {
    $m = new ItServiceModel($pdo); $f = $m->normFilters(['contratti_set' => 1]);
    $tl = $m->tecniciLinea($f); $v = $m->valorizzazione($f); $vt = $m->valorizzazione($f, true);
    $tp = $m->rapportiTipologia($f); $cm = $m->rapportiCommessa($f); $mm = $m->rapportiModuli($f, null, 5); $m->totali($f);
    $coh = true; foreach ($tp as $x) if ((int)$x['da_ticket'] + (int)$x['da_testo'] + (int)$x['da_commessa'] !== (int)$x['moduli']) $coh = false;
    $f2 = $m->normFilters(['contratti_set' => 1, 'tipologie' => 'a_scalare', 'prov' => 'ticket']); $tp2 = $m->rapportiTipologia($f2);
    $R = new TechReport($m, false);
    $csv = $R->build('tecnici', $f, $R->data('tecnici', $f), '', 'csv')->toCsv() . $R->build('rapporti', $f, $R->data('rapporti', $f, true, null, 50), '', 'csv')->toCsv();
} catch (Throwable $e) { $errs[] = $e->getMessage(); $coh = false; $csv = ''; $tp2 = [0, 0]; $v = []; }
chk("Letture della Relazione Tecnici eseguibili sul database", !$errs, $errs ? implode(' | ', $errs) : count($tl['righe']) . ' righe tecnico×linea, ' . count($cm) . ' commesse');
chk("Provenienza coerente: ticket + riferimento libero + da commessa = moduli", $coh);
chk("Filtri tipologia + provenienza applicati", count($tp2) <= 1);
chk("Report CSV di entrambe le schede; senza permesso valori nessuna colonna €", str_contains($csv, 'Riepilogo per tecnico e codice linea') && str_contains($csv, 'Per tipologia di contratto') && !str_contains($csv, 'Produzione teorica €'));
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
