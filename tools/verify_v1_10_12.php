<?php
/**
 * tools/verify_v1_10_12.php — verifica della v1.10.12: report DOCX / XLSX / CSV / PDF (generale e per tecnico) per
 * Service Desk, Service SOC e Attività & Rendicontazione DGB, dal solo filtro principale; nessun blocco filtri secondario.
 * Uso: php tools/verify_v1_10_12.php --db=demo_portalmanager [--host= --user= --pass= --socket=] [--out=cartella]
 * Genera i report in una cartella temporanea (o --out) e ne controlla la validità (PDF, ZIP OOXML, CSV con BOM).
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
foreach (['PmContractFilter', 'PmReport', 'SocReport', 'SdReport', 'DgbReport'] as $c) require_once "$root/app/$c.php";
echo "→ Connesso a $user@$host/$db\n\n";
$ok = 0; $ko = 0;
function chk(string $lbl, bool $cond, string $extra = ''): void {
    global $ok, $ko;
    echo ($cond ? "✔" : "✘") . " " . str_pad($lbl, 72) . " " . $extra . "\n";
    $cond ? $ok++ : $ko++;
}
$file = fn(string $rel) => (string)@file_get_contents("$root/$rel");
$one = fn(string $sql) => $pdo->query($sql)->fetchColumn();
$out = $args['out'] ?? (sys_get_temp_dir() . '/pm_verify_1_10_12');
@mkdir($out, 0775, true);

chk("app_settings.app_version = 1.10.12", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.12');
chk("VERSION = 1.10.12 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.12' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.12')"));
chk("pm_migration_sql contiene 1.10.07 … 1.10.12", (int)$one("SELECT COUNT(DISTINCT version) FROM pm_migration_sql WHERE version IN ('1.10.07','1.10.08','1.10.09','1.10.10','1.10.11','1.10.12')") === 6);
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_12.md", is_file("$root/docs/{$d}_v1_10_12.md"));
foreach (['app/PdfWriter.php', 'app/PmReport.php', 'app/SocReport.php', 'app/SdReport.php', 'app/DgbReport.php'] as $f) chk("File $f", is_file("$root/$f"));
chk("ZipArchive disponibile (DOCX, XLSX, ZIP per tecnico)", class_exists('ZipArchive'));

// pagine: report dal filtro principale, un solo blocco filtri
foreach (['service_soc.php' => ['SocReport::build', 'PmReport::toolbar'], 'service_desk.php' => ['SdReport::build', 'PmReport::toolbar'], 'dgb_activities.php' => ['DgbReport::build', 'PmReport::toolbar']] as $pg => $need) {
    $src = $file($pg);
    chk("$pg: report + barra export + report per tecnico", str_contains($src, $need[0]) && str_contains($src, $need[1]) && str_contains($src, 'PmReport::bundle'));
    chk("$pg: un solo blocco filtri (PM_NO_AUTOFILTER, nessun ListFilter)", str_contains($src, "PM_NO_AUTOFILTER") && !preg_match('/ListFilter::render\w*\s*\(/', $src));
}
$dgb = $file('dgb_activities.php');
chk("DGB anomalie: nessun filtro proprio (atec/atipo/asev/adal/aal)", !preg_match('/name="a(tec|tipo|sev|dal|al)"/', $dgb) && !preg_match("/\\\$_GET\['a(tec|tipo|sev|dal|al)'\]/", $dgb) && str_contains($dgb, 'anomalieWhere'));

// validità dei file
$valid = function (string $fmt, string $p): bool {
    $h = (string)@file_get_contents($p, false, null, 0, 8);
    if ($fmt === 'pdf') return str_starts_with($h, '%PDF-1.4') && str_contains((string)file_get_contents($p), '%%EOF');
    if ($fmt === 'csv') return str_starts_with($h, "\xEF\xBB\xBF");
    $z = new ZipArchive(); if ($z->open($p) !== true) return false;
    $okz = $z->locateName($fmt === 'docx' ? 'word/document.xml' : 'xl/workbook.xml') !== false; $z->close(); return $okz;
};
$gen = function (string $lbl, callable $build) use ($out, $valid): void {
    foreach (PmReport::FORMATS as $fmt => $_) {
        $p = "$out/" . PmReport::safe($lbl) . ".$fmt";
        try { $build($fmt)->writeToFile($fmt, $p); chk("$lbl — $fmt", $valid($fmt, $p), '(' . number_format((int)filesize($p), 0, ',', '.') . ' byte)'); }
        catch (Throwable $e) { chk("$lbl — $fmt", false, $e->getMessage()); }
    }
};

$soc = new SocModel($pdo);
if ($soc->ready()) {
    $f = $soc->normFilters([]);
    $gen('SOC generale', fn($fmt) => SocReport::build($pdo, $soc, $f, $fmt));
    $t = SocReport::tecnici($soc, $f);
    if ($t) $gen('SOC tecnico ' . array_key_first($t), fn($fmt) => SocReport::build($pdo, $soc, ['tec' => (string)array_key_first($t)] + $f, $fmt));
} else echo "  Service SOC senza ticket: report saltati\n";

$sd = new SdModel($pdo);
$f = $sd->normFilters([]);
$gen('SD generale', fn($fmt) => SdReport::build($sd, $f, $fmt));
$t = SdReport::tecnici($sd, $f);
if ($t) $gen('SD tecnico ' . array_key_first($t), fn($fmt) => SdReport::build($sd, ['tec' => (string)array_key_first($t)] + $f, $fmt));

$dm = new DgbModel($pdo);
$last = $one("SELECT MAX(DATE(a.date_start)) FROM dgb_forms_activity_operator ao JOIN dgb_forms_activity a ON a.id = ao.id_activity WHERE a.date_start <= NOW()");
$f = DgbModel::normFilters($last ? ['from' => date('Y-m-01', strtotime($last)), 'to' => $last] : []);
$gen('DGB generale', fn($fmt) => DgbReport::build($pdo, $dm, $f, $fmt));
$t = DgbReport::tecnici($dm, $f);
if ($t) { $k = (int)array_key_first($t); $gen('DGB incaricato ' . $t[$k], fn($fmt) => DgbReport::build($pdo, $dm, ['operators' => [$k], 'operator' => $k] + $f, $fmt)); }
// coerenza: la somma delle ore dei singoli incaricati = ore del report generale
if ($t) {
    $tot = (float)($dm->aggregaTotale($f)['ore'] ?? 0); $sum = 0.0;
    foreach (array_keys($t) as $k) $sum += (float)($dm->aggregaTotale(['operators' => [(int)$k], 'operator' => (int)$k] + $f)['ore'] ?? 0);
    chk("DGB: Σ report per incaricato = report generale (ore)", abs($tot - $sum) < 0.05, "($sum = $tot)");
}
echo "\nFile generati in $out\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
