<?php
/**
 * tools/verify_v1_10_02.php — verifica della v1.10.02 (KPI e penali, punteggio, storico, Scenari & confronti,
 * tab Progetti PRJ della commessa, colonna Progetti PRJ dell'elenco commesse).
 * Uso: php tools/verify_v1_10_02.php --db=demo_portalmanager [--host= --user= --pass= --socket=]
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
require_once $root . '/app/PrjRepo.php';
require_once $root . '/app/PrjLink.php';
require_once $root . '/app/PmCharts.php';
echo "→ Connesso a $user@$host/$db\n\n";
$ok = 0; $ko = 0;
function chk(string $lbl, bool $cond, string $extra = ''): void {
    global $ok, $ko;
    echo ($cond ? "✔" : "✘") . " " . str_pad($lbl, 64) . " " . $extra . "\n";
    $cond ? $ok++ : $ko++;
}
$file = fn(string $rel) => (string)@file_get_contents("$root/$rel");

chk("pm_migration_sql contiene 1.10.02", (bool)$pdo->query("SELECT COUNT(*) FROM pm_migration_sql WHERE version='1.10.02'")->fetchColumn());
chk("app_settings.app_version = 1.10.02", $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app_version'")->fetchColumn() === '1.10.02');
chk("VERSION = 1.10.02", trim($file('VERSION')) === '1.10.02');
chk("PM_VERSION = 1.10.02", (bool)preg_match("/define\\('PM_VERSION', '1\\.10\\.02'\\)/", $file('app/Version.php')));
chk("File prj_history.php presente", is_file("$root/prj_history.php"));
chk("MenuManager: voce Scenari & confronti progetti", str_contains($file('app/MenuManager.php'), "'page' => 'prj_history'"));
chk("Scheda PRJ: tab KPI & Penali, Punteggio, Storico", str_contains($file('prj_dashboard.php'), "'kpi' => 'KPI & Penali', 'punt' => 'Punteggio', 'stor' => 'Storico'"));
chk("Scheda commessa: tab Progetti PRJ", str_contains($file('project_dashboard.php'), 'data-tab="prj"') && str_contains($file('project_dashboard.php'), 'id="tab-prj"'));
chk("Elenco commesse: colonna, filtro ed export Progetti PRJ", str_contains($file('manage_projects.php'), "'has_prj'") && str_contains($file('manage_projects.php'), "'progetti_prj'"));
chk("PmCharts::groupedBars disponibile", method_exists('PmCharts', 'groupedBars') && str_contains(PmCharts::groupedBars(['2027'], [['label' => 'x', 'color' => '#000', 'values' => [1]]]), '<svg'));
foreach (['idx_prun_created' => 'cm_prj_calc_run', 'idx_prun_prj_scen' => 'cm_prj_calc_run', 'idx_pcin_prj' => 'cm_prj_criterion_input', 'idx_ecl_table_entity_id' => 'entity_change_log'] as $ix => $t)
    chk("Indice $ix", (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' AND INDEX_NAME='$ix'")->fetchColumn());

$prj = (int)$pdo->query("SELECT id FROM cm_prj WHERE prj_code='PRJ-2026-0001'")->fetchColumn();
if ($prj) {
    $kpis = $pdo->query("SELECT codice, penale_importo, penale_importi_priorita, penale_unita, blocco, penale_formula FROM cm_prj_kpi WHERE prj_id=$prj AND is_current=1")->fetchAll(PDO::FETCH_ASSOC);
    $pen = PrjCalc::penalties($kpis, ['KPI_01' => 3, 'KPI_05' => ['A' => 12, 'M' => 5, 'B' => 4], 'KPI_11' => 30, 'KPI_19' => ['critiche' => 1, 'non_critiche' => 2],
                                      'KPI_03' => 2, 'KPI_21' => 50000, 'KPI_24' => 1, 'KPI_18' => 9], 196000);
    // 6000 + 2500 + 600 + 2000 + 10000 + 5000 + 200000 + 0
    chk("Simulatore penali: 8 KPI (giorno, blocchi, minuto, punto %, risorsa, % sforamento, una tantum, monitoraggio)", abs($pen['totale'] - 226100) < 0.01, '(' . number_format($pen['totale'], 0, ',', '.') . ' €)');
    $crit = $pdo->query("SELECT codice, gruppo, tipo, punti_max, formula, flag_incongruenza FROM cm_prj_criterion WHERE prj_id=$prj AND is_current=1")->fetchAll(PDO::FETCH_ASSOC);
    $t = PrjCalc::technicalScore($crit, ['C' => [63], 'D' => [100, 44], 'G' => 50, 'H' => 1, 'coeff' => ['A.2' => 0.5]]);
    chk("Punteggio tecnico simulato = 32,00 (C 12 + D 16 + G 1 + H 1 + A.2 2)", abs($t['totale'] - 32) < 1e-9, '(' . $t['totale'] . ')');
    $e = PrjCalc::economicScore(0.10, 0.05, 1, 1, 1);
    chk("Punteggio economico s1 10% s2 5% (w=n=1) = 4,59", abs($e['totale'] - 4.588) < 0.01, '(' . round($e['totale'], 3) . ')');
    $pdo->beginTransaction();
    try {
        $repo = new PrjRepo($pdo);
        $sc = (int)$pdo->query("SELECT scenario_riferimento_id FROM cm_prj WHERE id=$prj")->fetchColumn();
        $r1 = $repo->saveRun($prj, $sc, date('Y-m-d'), null);
        $pid = (int)$pdo->query("SELECT id FROM cm_prj_param WHERE prj_key=0 AND chiave='oneri_pct' AND is_current=1")->fetchColumn();
        $repo->writeVersion('cm_prj_param', $pid, ['valore' => 0.45], date('Y-m-d', strtotime('+1 day')), null, 'verifica');
        $r2 = $repo->saveRun($prj, $sc, date('Y-m-d', strtotime('+1 day')), null);
        $c = array_values(array_filter($repo->compareRuns($r1['run_id'], $r2['run_id']), fn($x) => $x['ambito'] === 'totale' && $x['metrica'] === 'oneri'))[0] ?? null;
        chk("Confronto calc run: delta oneri dopo nuova versione del parametro", $c && $c['delta'] > 0, $c ? '(+' . number_format($c['delta'], 0, ',', '.') . ' €)' : '');
        $n = (int)$pdo->query("SELECT COUNT(*) FROM entity_change_log WHERE entity_table='cm_prj_param' AND entity_id=(SELECT ent_id FROM cm_prj_param WHERE id=$pid)")->fetchColumn();
        chk("Storico: modifica del parametro in EntityChangeLog", $n >= 1);
        $sp = (int)$pdo->query("SELECT id FROM cm_projects WHERE project_code NOT LIKE 'DGB-%' ORDER BY id LIMIT 1")->fetchColumn();
        $cur = $pdo->query("SELECT sp_project_id FROM cm_prj WHERE id=$prj")->fetchColumn();
        (new PrjLink($pdo))->link($prj, $sp, 'verifica', null);
        $m = $pdo->query("SELECT GROUP_CONCAT(prj_code) FROM cm_prj WHERE sp_project_id=$sp")->fetchColumn();
        chk("Commessa SP: progetti PRJ collegati per la colonna e la tab", str_contains((string)$m, 'PRJ-2026-0001'), "($m)");
    } catch (Throwable $ex) { chk("Prove funzionali", false, $ex->getMessage()); }
    $pdo->rollBack();
}
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
