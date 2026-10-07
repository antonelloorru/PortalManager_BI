<?php
/**
 * tools/verify_v1_10_15.php — verifica della v1.10.15: Report Direzionale per tipologia di commessa
 * (ACM, WTS-CSS, WTS-CC, WTS-MEG, NV_, Moduli di intervento) in HTML / DOCX / XLSX / CSV / PDF dal filtro principale.
 * Uso: php tools/verify_v1_10_15.php --db=demo_portalmanager [--host= --user= --pass= --socket=] [--out=cartella] [--from=AAAA-MM-GG --to=AAAA-MM-GG]
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
$GLOBALS['PM_NO_SNAPSHOT'] = true;
require_once "$root/app/DirModel.php";
require_once "$root/app/DirTipologie.php";
echo "→ Connesso a $user@$host/$db\n\n";
$ok = 0; $ko = 0;
function chk(string $lbl, bool $cond, string $extra = ''): void {
    global $ok, $ko;
    echo ($cond ? "✔" : "✘") . " " . str_pad($lbl, 76) . " " . $extra . "\n";
    $cond ? $ok++ : $ko++;
}
$file = fn(string $rel) => (string)@file_get_contents("$root/$rel");
$one = fn(string $sql) => $pdo->query($sql)->fetchColumn();
$out = $args['out'] ?? (sys_get_temp_dir() . '/pm_verify_1_10_15');
@mkdir($out, 0775, true);

chk("app_settings.app_version = 1.10.15", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.15');
chk("VERSION = 1.10.15 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.15' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.15')"));
chk("pm_migration_sql contiene 1.10.07 … 1.10.15", (int)$one("SELECT COUNT(DISTINCT version) FROM pm_migration_sql WHERE version IN ('1.10.07','1.10.08','1.10.09','1.10.10','1.10.11','1.10.12','1.10.13','1.10.14','1.10.15')") === 9);
chk("Impostazione dir.acm_tolleranza_pct", is_numeric($one("SELECT setting_value FROM app_settings WHERE setting_key='dir.acm_tolleranza_pct'")));
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_15.md", is_file("$root/docs/{$d}_v1_10_15.md"));
$pg = $file('dir_report.php');
chk("dir_report.php: schede per tipologia, report multi-formato, stampa, permesso export", str_contains($pg, 'DirTipologie::TABS') && str_contains($pg, '->send($repFmt') && str_contains($pg, 'toHtml(true)') && str_contains($pg, "can('export', 'dir_report.php')"));
chk("dir_report.php: un solo blocco filtri (PM_NO_AUTOFILTER, 1 form)", str_contains($pg, 'PM_NO_AUTOFILTER') && substr_count($pg, '<form') === 1);

$dm = new DirModel($pdo);
$t = new DirTipologie($pdo, $dm);
$f = $dm->normFilters(['solo' => 'tutte', 'from' => $args['from'] ?? '', 'to' => $args['to'] ?? '']);
$x = DirTipologie::normExtra([], $pdo);
$valid = function (string $fmt, string $p): bool {
    $h = (string)@file_get_contents($p, false, null, 0, 8);
    if ($fmt === 'pdf') return str_starts_with($h, '%PDF-1.4') && str_contains((string)file_get_contents($p), '%%EOF');
    if ($fmt === 'csv') return str_starts_with($h, "\xEF\xBB\xBF");
    if ($fmt === 'html') return str_contains((string)file_get_contents($p), '</html>');
    $z = new ZipArchive(); if ($z->open($p) !== true) return false;
    $r = $z->locateName($fmt === 'docx' ? 'word/document.xml' : 'xl/workbook.xml') !== false; $z->close(); return $r;
};
foreach (DirTipologie::TABS as $k => $l) {
    try {
        $r = $t->build($k, $f, $x, 'verifica');
        $okf = [];
        foreach (array_keys(PmReport::FORMATS) as $fmt) { $p = "$out/$k.$fmt"; $r->writeToFile($fmt, $p); if ($valid($fmt, $p)) $okf[] = $fmt; }
        file_put_contents("$out/$k.html", $r->toHtml(true)); if ($valid('html', "$out/$k.html")) $okf[] = 'html';
        chk("$l: HTML, DOCX, XLSX, CSV, PDF", count($okf) === 5, '(' . implode(',', $okf) . ')');
    } catch (Throwable $e) { chk("$l: report", false, $e->getMessage()); }
}

$acm = $t->acm($f, $x);
$bad = 0;
foreach ($acm as $c) {
    if ($c['esito'] === 'nd') continue;
    $exp = abs($c['consumo_pct'] - 100) <= $x['toll'] ? 'inline' : ($c['consumo_pct'] < 100 ? 'over' : 'under');
    if ($exp !== $c['esito'] || abs($c['performance_pct'] - (100 - $c['consumo_pct'])) > 0.11) $bad++;
}
chk("ACM: esito e Performance % coerenti con consumo e tolleranza", $bad === 0, '(' . count($acm) . ' commesse, ±' . $x['toll'] . '%)');
$sub = 0; foreach (['inline', 'over', 'under', 'nd'] as $e) $sub += count($t->acm($f, ['esito' => [$e], 'toll' => $x['toll']]));
chk("ACM: filtri per esito ripartiscono tutte le commesse", $sub === count($acm), "($sub = " . count($acm) . ')');
$css = $t->consumo($f, 'css');
$p = DirTipologie::periodo($f);
$st = $pdo->prepare("SELECT ROUND(SUM(g.ore),2) FROM v_cm_it_giorni_base g JOIN cm_projects p ON p.project_code = g.commessa WHERE p.service_line = 'WTS-CSS' AND g.giorno BETWEEN ? AND ?");
$st->execute([$p['da'], $p['a']]);
$dir = (float)$st->fetchColumn();
chk("WTS-CSS: Σ ore = moduli della tipologia nel periodo", abs(array_sum(array_column($css, 'ore')) - $dir) < 0.05, '(' . round(array_sum(array_column($css, 'ore')), 2) . " = $dir)");
$nv = $t->nv($f);
chk("NV_: Σ incidenze = ore NV / ore totali", abs(array_sum(array_column($nv['righe'], 'incidenza_pct')) - ($nv['ore_totali'] > 0 ? $nv['ore_nv'] / $nv['ore_totali'] * 100 : 0)) < 0.1);
$mo = $t->moduli($f);
chk("Moduli: Σ per tecnico = Σ per tecnico e fascia", abs(array_sum(array_column($mo['tecnici'], 'ore')) - array_sum(array_column($mo['righe'], 'ore'))) < 0.05 && array_sum(array_column($mo['tecnici'], 'moduli')) === array_sum(array_column($mo['righe'], 'moduli')));
$meg = $t->meg($f);
chk("WTS-MEG: margine = valore − costo totale", !array_filter($meg, fn($c) => abs($c['margine'] - ((float)$c['valore'] - $c['costo_totale'])) > 0.05), '(' . count($meg) . ' commesse)');

echo "\nFile generati in $out\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
