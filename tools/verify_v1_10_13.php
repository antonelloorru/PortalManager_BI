<?php
/**
 * tools/verify_v1_10_13.php — verifica della v1.10.13: Relazione di Servizio IT in DOCX / XLSX / CSV / PDF, report generale e
 * per incaricato (ZIP), dal solo filtro principale; un solo blocco filtri.
 * Uso: php tools/verify_v1_10_13.php --db=demo_portalmanager [--host= --user= --pass= --socket=] [--out=cartella]
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
require_once "$root/app/PmContractFilter.php";
require_once "$root/app/it_service_report.php";
echo "→ Connesso a $user@$host/$db\n\n";
$ok = 0; $ko = 0;
function chk(string $lbl, bool $cond, string $extra = ''): void {
    global $ok, $ko;
    echo ($cond ? "✔" : "✘") . " " . str_pad($lbl, 72) . " " . $extra . "\n";
    $cond ? $ok++ : $ko++;
}
$file = fn(string $rel) => (string)@file_get_contents("$root/$rel");
$one = fn(string $sql) => $pdo->query($sql)->fetchColumn();
$out = $args['out'] ?? (sys_get_temp_dir() . '/pm_verify_1_10_13');
@mkdir($out, 0775, true);

chk("app_settings.app_version = 1.10.13", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.13');
chk("VERSION = 1.10.13 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.13' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.13')"));
chk("pm_migration_sql contiene 1.10.07 … 1.10.13", (int)$one("SELECT COUNT(DISTINCT version) FROM pm_migration_sql WHERE version IN ('1.10.07','1.10.08','1.10.09','1.10.10','1.10.11','1.10.12','1.10.13')") === 7);
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_13.md", is_file("$root/docs/{$d}_v1_10_13.md"));
foreach (['app/PmReportDoc.php', 'app/it_service_report.php', 'app/PmReport.php', 'app/PdfWriter.php'] as $f) chk("File $f", is_file("$root/$f"));
$pg = $file('it_service.php');
chk("it_service.php: report multi-formato, ZIP per incaricato, barra report", str_contains($pg, 'it_service_report(') && str_contains($pg, 'PmReport::bundle') && str_contains($pg, 'PmReport::toolbar'));
chk("it_service.php: export=docx resta come alias del report DOCX", str_contains($pg, "(\$_GET['export'] ?? '') === 'docx' ? 'docx'"));
chk("it_service.php: un solo blocco filtri (PM_NO_AUTOFILTER, nessun ListFilter, 1 form)", str_contains($pg, 'PM_NO_AUTOFILTER') && !preg_match('/ListFilter::render\w*\s*\(/', $pg) && substr_count($pg, '<form') === 1);
chk("it_service.php: nessuna DocxWriter diretta (un solo contenuto per tutti i formati)", !str_contains($pg, 'new DocxWriter('));

$valid = function (string $fmt, string $p): bool {
    $h = (string)@file_get_contents($p, false, null, 0, 8);
    if ($fmt === 'pdf') return str_starts_with($h, '%PDF-1.4') && str_contains((string)file_get_contents($p), '%%EOF');
    if ($fmt === 'csv') return str_starts_with($h, "\xEF\xBB\xBF");
    $z = new ZipArchive(); if ($z->open($p) !== true) return false;
    $r = $z->locateName($fmt === 'docx' ? 'word/document.xml' : 'xl/workbook.xml') !== false; $z->close(); return $r;
};
$it = new ItServiceModel($pdo);
$f = $it->normFilters([]);
$inc = ['quadro', 'andamento', 'dettaglio', 'giorni', 'costi', 'contratti', 'commesse', 'senzamodulo'];
foreach (PmReport::FORMATS as $fmt => $_) {
    $p = "$out/relazione_generale.$fmt";
    try { it_service_report($it, $f, [], $inc, $fmt)->writeToFile($fmt, $p); chk("Relazione generale — $fmt", $valid($fmt, $p), '(' . number_format((int)filesize($p), 0, ',', '.') . ' byte)'); }
    catch (Throwable $e) { chk("Relazione generale — $fmt", false, $e->getMessage()); }
}
$t = it_service_report_tecnici($it, $f);
chk("Incaricati del perimetro per i report singoli", count($t) > 0, '(' . count($t) . ')');
if ($t) {
    $k = (string)array_key_first($t);
    foreach (PmReport::FORMATS as $fmt => $_) {
        $p = "$out/relazione_" . PmReport::safe($k) . ".$fmt";
        try { it_service_report($it, ['incaricati' => [$k]] + $f, [], $inc, $fmt)->writeToFile($fmt, $p); chk("Relazione di $k — $fmt", $valid($fmt, $p)); }
        catch (Throwable $e) { chk("Relazione di $k — $fmt", false, $e->getMessage()); }
    }
    $tot = (float)($it->totali($f)['ore'] ?? 0); $sum = 0.0;
    foreach (array_keys($t) as $k2) $sum += (float)($it->totali(['incaricati' => [(string)$k2]] + $f)['ore'] ?? 0);
    chk("Σ ore delle relazioni per incaricato = relazione generale", abs($tot - $sum) < 0.05, "($sum = $tot)");
    // XLSX: il dettaglio per commessa è un solo foglio, non uno per contratto
    $z = new ZipArchive(); $z->open("$out/relazione_generale.xlsx"); $wb = (string)$z->getFromName('xl/workbook.xml'); $z->close();
    chk("XLSX: «Dettaglio per Commessa» in un solo foglio", substr_count($wb, '<sheet ') < 40, '(' . substr_count($wb, '<sheet ') . ' fogli)');
}
echo "\nFile generati in $out\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
