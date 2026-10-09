<?php
/**
 * tools/verify_v1_10_34.php — verifica della v1.10.34: Controllo Reperibilità: modalità Reperibilità, ordine colonne, colori di intestazione. Esegue le letture sul database indicato.
 * Uso: php tools/verify_v1_10_34.php --db=demo_portalmanager [--host= --user= --pass= --socket=]
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

chk("app_settings.app_version = 1.10.34", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.34');
chk("VERSION = 1.10.34 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.34' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.34')"));
chk("pm_migration_sql contiene 1.10.34", (int)$one("SELECT COUNT(*) FROM pm_migration_sql WHERE version = '1.10.34'") === 1);
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_34.md", is_file("$root/docs/{$d}_v1_10_34.md"));
$im = $file('app/ItServiceModel.php'); $pg = $file('tech_report.php'); $pr = $file('app/PmReport.php');
require_once "$root/app/TechReport.php";
chk("Ordine delle 11 colonne", TechReport::H_REP === ['Tecnico / Incaricato', 'Data/Ora Reperibilità', 'Rif. Modulo Intervento (reperibilità)', 'Cliente', 'Codice Commessa', 'Tipo', 'Data/Ora Giorno Succ.', 'Rif. Modulo Intervento (giorno succ.)', 'Cliente', 'Codice Commessa', 'Tipo']);
chk("Colori: neutro / rosso mattone / verde", TechReport::hRepColori() === ['475569', 'A0442C', 'A0442C', 'A0442C', 'A0442C', 'A0442C', '15803D', '15803D', '15803D', '15803D', '15803D']);
chk("CSV: intestazioni univoche", count(array_unique(TechReport::hRepEstese())) === 11);
chk("Reperibilità = modalità del filtro", ItServiceModel::REP_MODALITA === 'reperibilita' && str_contains($im, "s.`modalita` = '\" . self::REP_MODALITA . \"'") && !str_contains($im, 'repFlagSql'));
chk("Giorno successivo non in reperibilità", str_contains($im, 'AND COALESCE(ir.`on_call`, 0) = 0'));
chk("hcolors in PmReport (HTML, XLSX, DOCX, PDF)", substr_count($pr, "hcolors") >= 4 && str_contains($file('app/XlsxWriter.php'), 'array $headerColors = []') && str_contains($file('app/DocxWriter.php'), "\$o['hcolors']") && str_contains($file('app/PdfWriter.php'), "\$o['hcolors']"));
chk("Vista: intestazioni per gruppo", str_contains($pg, 'class="tr-<?= TechReport::H_REP_GRUPPO[$i] ?>-h"') && str_contains($pg, 'TechReport::C_REP') && str_contains($pg, 'TechReport::C_GS'));
$errs = [];
try {
    $eq = (int)$one("SELECT COUNT(*) FROM v_cm_it_servizio s JOIN cm_intervention_reports ir ON ir.id = s.report_id WHERE (s.modalita = 'reperibilita') <> (COALESCE(ir.on_call,0) = 1)");
    $m = new ItServiceModel($pdo); $f = $m->normFilters(['contratti_set' => 1, 'from' => '2026-01-01', 'to' => '2026-09-30']);
    $d = $m->controlloReperibilita($f);
    $mods = $d['righe'] ? $pdo->query("SELECT report_code, COALESCE(on_call,0) FROM cm_intervention_reports WHERE report_code IN ('" . implode("','", array_map(fn($x) => addslashes($x), array_merge(array_column($d['righe'], 'rep_modulo'), array_column($d['righe'], 'succ_modulo')))) . "')")->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    $okR = true;
    foreach ($d['righe'] as $x) {
        $okR = $okR && (int)($mods[$x['rep_modulo']] ?? 0) === 1 && (int)($mods[$x['succ_modulo']] ?? 1) === 0
                    && substr($x['succ_inizio'], 0, 10) === ItServiceModel::prossimoLavorativo($x['turno']) && $x['succ_inizio'] >= ($x['rep_fine'] ?: $x['rep_inizio']);
    }
    require_once "$root/app/XlsxWriter.php";
    $TR = new TechReport($m); $f['cf'] = []; $dd = $TR->data('reperibilita', $f);
    $tmp = tempnam(sys_get_temp_dir(), 'v34'); $TR->build('reperibilita', $f, $dd, '', 'xlsx')->toXlsx()->writeToFile($tmp);
    $z = new ZipArchive(); $z->open($tmp); $sty = (string)$z->getFromName('xl/styles.xml'); $z->close(); @unlink($tmp);
    $okX = str_contains($sty, 'FFA0442C') && str_contains($sty, 'FF15803D') && str_contains($sty, 'FF475569');
    $okL = $eq === 0 && $okR && $okX;
} catch (Throwable $e) { $errs[] = $e->getMessage(); $okL = false; }
chk("Letture eseguibili (modalità ≡ on_call, correlazione, XLSX colorato)", $okL && !$errs, $errs ? implode(' | ', $errs) : ($d['notturni'] ?? 0) . ' interventi in reperibilità · ' . count($d['righe'] ?? []) . ' casi');
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
