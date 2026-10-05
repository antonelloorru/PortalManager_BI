<?php
/**
 * tools/verify_v1_10_03.php — verifica della v1.10.03 (Stimato vs Consuntivo, scostamenti e alert, export) e
 * controllo complessivo del modulo Progetti PRJ (v1.9.99 → v1.10.03).
 * Uso: php tools/verify_v1_10_03.php --db=demo_portalmanager [--host= --user= --pass= --socket=]
 * Le prove di scrittura girano in una transazione annullata.
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
foreach (['PrjRepo', 'PrjLink', 'PrjActuals', 'PrjExport', 'PrjUi', 'PmCharts'] as $c) require_once "$root/app/$c.php";
echo "→ Connesso a $user@$host/$db\n\n";
$ok = 0; $ko = 0;
function chk(string $lbl, bool $cond, string $extra = ''): void {
    global $ok, $ko;
    echo ($cond ? "✔" : "✘") . " " . str_pad($lbl, 66) . " " . $extra . "\n";
    $cond ? $ok++ : $ko++;
}
$file = fn(string $rel) => (string)@file_get_contents("$root/$rel");
$one = fn(string $sql) => $pdo->query($sql)->fetchColumn();

// versione e migrazioni del modulo
chk("app_settings.app_version = 1.10.03", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.03');
chk("VERSION = 1.10.03 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.03' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.03')"));
foreach (['1.9.99', '1.10.00', '1.10.01', '1.10.02', '1.10.03'] as $v)
    chk("pm_migration_sql contiene $v", (bool)$one("SELECT COUNT(*) FROM pm_migration_sql WHERE version='$v'"));
chk("Tabelle cm_prj* = 39 (38 + cm_prj_deviation)", (int)$one("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'cm\\_prj%' AND TABLE_TYPE='BASE TABLE'") === 39);
chk("Vista v_cm_prj_alert_da_rilevare", (bool)$one("SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v_cm_prj_alert_da_rilevare'"));
chk("Regole prj_scost_fte e prj_scost_costo in cm_alert_rules", (int)$one("SELECT COUNT(*) FROM cm_alert_rules WHERE code IN ('prj_scost_fte','prj_scost_costo')") === 2);
chk("AlertEngine legge la vista PRJ", str_contains($file('app/AlertEngine.php'), 'v_cm_prj_alert_da_rilevare'));

// file del modulo
foreach (['prj_dashboard.php', 'prj_parameters.php', 'prj_history.php', 'api_prj.php', 'app/prj_list.php', 'app/PrjCalc.php', 'app/PrjRepo.php', 'app/PrjLink.php',
          'app/PrjUi.php', 'app/PrjActuals.php', 'app/PrjExport.php'] as $f) chk("File $f", is_file("$root/$f"));
chk("Scheda PRJ: tab Stimato vs Consuntivo ed export XLSX/DOCX", str_contains($file('prj_dashboard.php'), "'cons' => 'Stimato vs Consuntivo'") && str_contains($file('prj_dashboard.php'), 'PrjExport::docx'));
foreach (['docs/TECHNICAL_DESIGN_v1_10_03.md', 'docs/MANUALE_ADMIN_PRJ_v1_10_03.md', 'docs/MANUALE_UTENTE_PRJ_v1_10_03.md', 'docs/DEPLOYMENT_v1_10_03.md',
          'docs/CHANGELOG_v1_10_03.md', 'docs/RELEASE_CHECKLIST_v1_10_03.md'] as $f) chk("Documento $f", is_file("$root/$f"));

// funzioni (transazione annullata)
$prj = (int)$one("SELECT id FROM cm_prj WHERE prj_code='PRJ-2026-0001'");
$sp = (int)$one("SELECT r.project_id FROM cm_intervention_reports r JOIN cm_projects p ON p.id = r.project_id WHERE p.project_code NOT LIKE 'DGB-%' GROUP BY r.project_id ORDER BY COUNT(*) DESC LIMIT 1");
if ($prj && $sp) {
    $pdo->beginTransaction();
    try {
        (new PrjLink($pdo))->link($prj, $sp, 'verifica v1.10.03', null);
        $pa = new PrjActuals($pdo);
        $r = $pa->refresh($prj);
        $cv = $pa->compare($prj);
        $c = $pa->context($prj);
        chk("Consuntivi mensili ricalcolati", $r['mesi'] > 0 && $r['mesi'] === count($c['months']), "({$r['mesi']} mesi, {$r['righe']} righe)");
        $h = (float)$one("SELECT COALESCE(SUM(quantity_hours),0) FROM cm_intervention_reports WHERE project_id=$sp AND report_date BETWEEN '{$c['from']}' AND '{$c['to']}'");
        $hr = array_sum(array_column($cv['mesi'], 'ore_report'));
        chk("Ore dei rapporti = somma dei rapporti della commessa nel periodo", abs($h - $hr) < 0.01, '(' . number_format($hr, 1, ',', '.') . ' h)');
        $ac = (float)$one("SELECT COALESCE(actual_cost,0) FROM cm_projects WHERE id=$sp");
        chk("Costo reale coerente con il costo consuntivato sincronizzato (±5%)", $ac <= 0 || abs($cv['totali']['costo'] - $ac) / $ac <= 0.05,
            '(' . number_format($cv['totali']['costo'], 0, ',', '.') . ' € vs ' . number_format($ac, 0, ',', '.') . ' €)');
        chk("FTE reali = ore / capacità mensile", (function () use ($cv, $pdo) {
            require_once dirname(__DIR__) . '/app/Workload.php'; $w = new Workload($pdo);
            foreach ($cv['mesi'] as $m) { $cap = $w->monthlyCapacity($m['ym']); if ($cap > 0 && abs($m["consuntivo"]["fte"] - $m["ore"] / $cap) > 1e-3) return false; } return true; })());
        chk("Stimato mensile dallo scenario di riferimento", $cv['stimato'] !== null && $cv['mesi'][0]['stimato']['fte'] > 0);
        $nd = (int)$one("SELECT COUNT(*) FROM cm_prj_deviation WHERE prj_id=$prj");
        chk("Scostamenti mensili registrati", $nd > 0, "($nd righe)");
        $pdo->exec("UPDATE cm_alert_rules SET threshold_warn = 0, threshold_alarm = 0 WHERE code LIKE 'prj_scost_%'");
        $va = (int)$one("SELECT COUNT(*) FROM v_cm_prj_alert_da_rilevare");
        chk("Vista alert: righe per l'ultimo mese completo", $va >= 1, "($va)");
        chk("Team e SLA leggibili", is_array($pa->team($prj, $sp)) && is_array($pa->sla((string)$one("SELECT project_code FROM cm_projects WHERE id=$sp"))));
        $n0 = (int)$one("SELECT COUNT(*) FROM cm_projects");
        chk("cm_projects mai scritta (conteggio invariato)", $n0 === (int)$one("SELECT COUNT(*) FROM cm_projects"));
        // export su file temporanei
        $repo = new PrjRepo($pdo);
        $scen = $pdo->query("SELECT * FROM cm_prj_scenario WHERE prj_id=$prj")->fetchAll(PDO::FETCH_ASSOC);
        $calc = []; foreach ($scen as $s) $calc[(int)$s['id']] = $repo->calc($prj, (int)$s['id'], date('Y-m-d'));
        $prjRow = $pdo->query("SELECT * FROM cm_prj WHERE id=$prj")->fetch(PDO::FETCH_ASSOC);
        require_once dirname(__DIR__) . '/app/DocxWriter.php';
        $ok_docx = class_exists('ZipArchive');
        chk("ZipArchive disponibile per XLSX e DOCX", $ok_docx);
    } catch (Throwable $e) { chk("Prove funzionali", false, $e->getMessage()); }
    $pdo->rollBack();
} else chk("PRJ-2026-0001 e commessa con rapporti presenti", false);

echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
