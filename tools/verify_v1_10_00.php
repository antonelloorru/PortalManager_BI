<?php
/**
 * tools/verify_v1_10_00.php — test di accettazione del motore PrjCalc (§9 della specifica) sui dati seed v1.9.99.
 * Uso: php tools/verify_v1_10_00.php --db=demo_portalmanager [--host= --user= --pass= --socket=] [--save]
 *   --save  registra anche un calc run per ogni scenario (default: solo calcolo, nessuna scrittura)
 * Tolleranza importi: ±1 k€. Esce con codice 1 se un test fallisce.
 */
declare(strict_types=1);
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)=(.*)$/', $a, $m)) $args[$m[1]] = $m[2];
    elseif (preg_match('/^--([a-z]+)$/', $a, $m)) $args[$m[1]] = '1';
}
$host = $args['host'] ?? getenv('PM_HC_HOST') ?: '127.0.0.1';
$db   = $args['db']   ?? getenv('PM_HC_DB')   ?: 'portalmanager';
$user = $args['user'] ?? getenv('PM_HC_USER') ?: 'root';
$pass = $args['pass'] ?? getenv('PM_HC_PASS') ?: '';
$sock = $args['socket'] ?? '';
try {
    $dsn = $sock !== '' ? "mysql:unix_socket=$sock;dbname=$db;charset=utf8mb4" : "mysql:host=$host;dbname=$db;charset=utf8mb4";
    // stesse opzioni di Config.php: prepared statement nativi
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
} catch (Throwable $e) { echo "✘ Connessione: " . $e->getMessage() . "\n"; exit(1); }

$root = realpath(__DIR__ . '/..');
require_once $root . '/app/PrjRepo.php';
echo "→ Connesso a $user@$host/$db\n\n";
$ok = 0; $ko = 0;
function chk(string $lbl, bool $cond, string $extra = ''): void {
    global $ok, $ko;
    echo ($cond ? "✔" : "✘") . " " . str_pad($lbl, 64) . " " . $extra . "\n";
    $cond ? $ok++ : $ko++;
}
$k  = fn(float $v) => number_format($v / 1000, 1, ',', '.') . ' k€';
$pc = fn(?float $v) => $v === null ? '—' : number_format($v * 100, 0, ',', '.') . '%';
$near = fn(float $v, float $att, float $tol = 1000.0) => abs($v - $att) <= $tol;

chk("pm_migration_sql contiene 1.10.00", (bool)$pdo->query("SELECT COUNT(*) FROM pm_migration_sql WHERE version='1.10.00'")->fetchColumn());
chk("app_settings.app_version = 1.10.00", $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app_version'")->fetchColumn() === '1.10.00');

$prj = (int)$pdo->query("SELECT id FROM cm_prj WHERE prj_code='PRJ-2026-0001'")->fetchColumn();
if (!$prj) { echo "✘ PRJ-2026-0001 assente: eseguire prima la migration v1.9.99\n"; exit(1); }
$sc = $pdo->query("SELECT nome, id FROM cm_prj_scenario WHERE prj_id=$prj")->fetchAll(PDO::FETCH_KEY_PAIR);
$repo = new PrjRepo($pdo);
$asOf = '2026-10-05';
$R = [];
foreach ($sc as $nome => $id) $R[$nome] = $repo->calc($prj, (int)$id, $asOf);

// ── §9 ─────────────────────────────────────────────────────────────
$t = $R['Sostenibile Roma']['ticket']['totali'];
chk("Ticket totali 2025 = 15.971", (int)round($t['ticket']) === 15971, '(' . number_format($t['ticket'], 0, ',', '.') . ')');
chk("FTE da ticket (senza uplift) = 34,7 ±0,1", abs($t['fte_ticket'] - 34.7) <= 0.1, '(' . number_format($t['fte_ticket'], 2, ',', '.') . ')');
chk("FTE con uplift 35% = 46,9 ±0,1", abs($t['fte_uplift'] - 34.737 * 1.35) <= 0.1, '(' . number_format($t['fte_uplift'], 2, ',', '.') . ')');

$fo = 0.0; $ft = 0.0;
foreach ($R['Completa Roma']['profili'] as $r) { $ft += $r['fte']; if ($r['tipo'] === 'obbligatorio') $fo += $r['fte']; }
chk("FTE profili obbligatori / totali = 26,5 / 55,1", abs($fo - 26.5) < 1e-6 && abs($ft - 55.1) < 1e-6, "($fo / $ft)");
chk("FTE scenario sostenibile = 34,0", abs($R['Sostenibile Roma']['totali']['fte_totali'] - 34.0) < 1e-6);

$sz = [];
foreach (['Milano' => 'Completa Milano', 'Roma' => 'Sostenibile Roma', 'Firenze' => 'Sostenibile Firenze', 'Napoli' => 'Sostenibile Napoli'] as $z => $n) $sz[$z] = $R[$n]['strutturale_zona'];
chk("Dotazione per FTE = 1.184 €", abs($sz['Roma']['dotazione'] - 1184) < 1, '(' . number_format($sz['Roma']['dotazione'], 2, ',', '.') . ')');
foreach (['Milano' => 3532, 'Roma' => 2985, 'Firenze' => 2646, 'Napoli' => 2402] as $z => $att)
    chk("Strutturale per FTE ufficio $z = " . number_format($att, 0, ',', '.') . " €", abs($sz[$z]['ufficio'] - $att) < 1, '(' . number_format($sz[$z]['ufficio'], 1, ',', '.') . ')');
chk("Canone medio = 2.351,9 k€", abs($R['Sostenibile Roma']['totali']['canone_medio'] / 1000 - 2351.9) < 0.05, '(' . $k($R['Sostenibile Roma']['totali']['canone_medio']) . ')');

$T = $R['Sostenibile Roma']['totali'];
chk("Sostenibile Roma: RAL = 1.517 k€", $near($T['costo_personale'], 1517000), '(' . $k($T['costo_personale']) . ')');
chk("Sostenibile Roma: costo az. personale = 2.207 k€", $near($T['costo_aziendale_personale'], 2207000), '(' . $k($T['costo_aziendale_personale']) . ')');
chk("Sostenibile Roma: totale = 2.372 k€, 101%", $near($T['costo_aziendale_totale'], 2372000) && round($T['pct_canone'] * 100) == 101,
    '(' . $k($T['costo_aziendale_totale']) . ', ' . $pc($T['pct_canone']) . ')');
foreach ([['Sostenibile Firenze', 2233000, 95], ['Sostenibile Napoli', 2012000, 86], ['Sostenibile Firenze + supporto Romania', 2083000, 89],
          ['Completa Firenze', 3389000, 144], ['Completa Milano', 4014000, 171]] as [$n, $att, $pct]) {
    $T = $R[$n]['totali'];
    chk("$n: totale = " . $k($att) . ", $pct%", $near($T['costo_aziendale_totale'], $att) && round($T['pct_canone'] * 100) == $pct,
        '(' . $k($T['costo_aziendale_totale']) . ', ' . $pc($T['pct_canone']) . ')');
}

// ── coerenza interna ───────────────────────────────────────────────
$T = $R['Sostenibile Roma']['totali'];
chk("Margine = canone netto − costo totale", abs($T['margine'] - ($T['canone_netto'] - $T['costo_aziendale_totale'])) < 0.01);
chk("Valore punto di ribasso = canone medio / 100", abs($T['valore_punto_ribasso'] - $T['canone_medio'] / 100) < 0.01, '(' . $k($T['valore_punto_ribasso']) . ')');
chk("Ribasso max a pareggio = 1 − costo / canone", abs($T['ribasso_max_pareggio'] - (1 - $T['costo_aziendale_totale'] / $T['canone_medio'])) < 1e-9, '(' . $pc($T['ribasso_max_pareggio']) . ')');
chk("FTE finanziabili (margine 15%) calcolati", $T['fte_finanziabili'] > 0, '(' . number_format($T['fte_finanziabili'], 1, ',', '.') . ')');
chk("Valore unitario ticket = canone medio / ticket", abs($T['valore_unitario_ticket'] - $T['canone_medio'] / 15971) < 0.01, '(' . number_format($T['valore_unitario_ticket'], 2, ',', '.') . ' €)');
$h = 0.0; foreach ($R['Sostenibile Roma']['profili'] as $r) if ($r['h24']) $h += $r['fte'];
chk("Indennità H24 solo sugli FTE con flag H24", abs($R['Sostenibile Roma']['totali']['indennita'] - $h * 4000) < 0.01, '(' . number_format($h, 2, ',', '.') . ' FTE)');
$rm = $R['Sostenibile Firenze + supporto Romania'];
chk("Nearshore: solo supporto, strutturale = dotazione", array_reduce($rm['profili'], fn($c, $r) => $c && (!$r['nearshore'] || ($r['tipo'] === 'supporto' && abs($r['strutturale_fte'] - $sz['Roma']['dotazione']) < 0.01)), true));
chk("Per anno: 4 anni di contratto (2027-2030)", count($R['Sostenibile Roma']['anni']) === 4);

// ── conguaglio, penali, punteggio ─────────────────────────────────
$cg = PrjCalc::conguaglio(15971, 20000, 2351898.43, 0.20);
chk("Conguaglio +20%: ticket oltre banda = 834,8", abs($cg['ticket_fuori_banda'] - (20000 - 15971 * 1.2)) < 0.01, '(' . number_format($cg['conguaglio'], 0, ',', '.') . ' €)');
$cg2 = PrjCalc::conguaglio(15971, 15000, 2351898.43, 0.20);
chk("Conguaglio entro banda = 0", $cg2['conguaglio'] == 0.0);
$kpis = $pdo->query("SELECT codice, penale_importo, penale_importi_priorita, penale_unita, blocco, penale_formula FROM cm_prj_kpi WHERE prj_id=$prj AND is_current=1")->fetchAll(PDO::FETCH_ASSOC);
$pen = PrjCalc::penalties($kpis, ['KPI_01' => 3, 'KPI_05' => ['A' => 12, 'M' => 5, 'B' => 4], 'KPI_11' => 30, 'KPI_19' => ['critiche' => 1, 'non_critiche' => 2], 'KPI_24' => 0], 2351898.43 / 12);
// 3×2000 + (2×1000 + 1×500 + 0×300) + 30×20 + (1×1000 + 2×500) + 0 = 6000 + 2500 + 600 + 2000 = 11100
chk("Penali simulate (mese) = 11.100 €", abs($pen['totale'] - 11100) < 0.01, '(' . number_format($pen['totale'], 0, ',', '.') . ' €, ' . number_format($pen['pct_canone'] * 100, 2, ',', '.') . '% canone mese)');
$crit = $pdo->query("SELECT codice, gruppo, tipo, punti_max, formula, flag_incongruenza FROM cm_prj_criterion WHERE prj_id=$prj AND is_current=1")->fetchAll(PDO::FETCH_ASSOC);
$ts = PrjCalc::technicalScore($crit, ['C' => [63], 'D' => [144], 'E' => array_fill(0, 11, [1, 1]), 'E_n' => 11, 'F' => [1, 1, 1, 1], 'G' => 100, 'H' => 1,
                                     'coeff' => ['A.1' => 1, 'A.2' => 1, 'A.3' => 1, 'A.4' => 1, 'A.5' => 1, 'B.1' => 1, 'B.2' => 1]]);
chk("Punteggio tecnico massimo = 70", abs($ts['totale'] - 70) < 1e-9, '(' . $ts['totale'] . ')');
$pe = PrjCalc::economicScore(1.0, 1.0, 1.0, 1.0, 1.0);
chk("Punteggio economico con ribasso 100% = 30", abs($pe['totale'] - 30) < 1e-9);
$pe0 = PrjCalc::economicScore(0.0, 0.0, 1.0, 1.0, 1.0);
chk("Punteggio economico con ribasso 0% = 0", abs($pe0['totale']) < 1e-9);

// ── as-of e versioni ───────────────────────────────────────────────
$h1 = PrjCalc::hash($repo->input($prj, (int)$sc['Sostenibile Roma'], $asOf));
$h2 = PrjCalc::hash($repo->input($prj, (int)$sc['Sostenibile Roma'], $asOf));
chk("Hash parametri deterministico", $h1 === $h2, '(' . substr($h1, 0, 12) . '…)');
$pdo->beginTransaction();
try {
    $pid = (int)$pdo->query("SELECT id FROM cm_prj_param WHERE prj_key=0 AND chiave='oneri_pct' AND is_current=1")->fetchColumn();
    $repo->writeVersion('cm_prj_param', $pid, ['valore' => 0.50], '2027-01-01', null, 'test verify');
    $a26 = $repo->calc($prj, (int)$sc['Sostenibile Roma'], '2026-10-05')['totali']['oneri'];
    $a27 = $repo->calc($prj, (int)$sc['Sostenibile Roma'], '2027-06-30')['totali']['oneri'];
    $nv  = (int)$pdo->query("SELECT COUNT(*) FROM cm_prj_param WHERE prj_key=0 AND chiave='oneri_pct'")->fetchColumn();
    chk("Versioning: nuova versione + lettura as-of (oneri 40% → 50%)", $nv >= 2 && abs($a27 / $a26 - 1.25) < 1e-9,
        '(' . $k($a26) . ' → ' . $k($a27) . ')');
} catch (Throwable $e) { chk("Versioning", false, $e->getMessage()); }
$pdo->rollBack();
$pdo->beginTransaction();
try {
    $c1 = $repo->nextCode(2031); $c2 = $repo->nextCode(2031);
    chk("Codice PRJ atomico e progressivo", $c1 === 'PRJ-2031-0001' && $c2 === 'PRJ-2031-0002', "($c1, $c2)");
} catch (Throwable $e) { chk("Codice PRJ", false, $e->getMessage()); }
$pdo->rollBack();
$pdo->beginTransaction();
try {
    $run = $repo->saveRun($prj, (int)$sc['Sostenibile Roma'], $asOf, null);
    $res = $repo->runResults($run['run_id']);
    chk("Calc run salvato con risultati", abs(($res['totale']['']['costo_aziendale_totale'] ?? 0) - $run['result']['totali']['costo_aziendale_totale']) < 0.01,
        '(' . count($res['totale']['']) . ' metriche totali, ' . count($res['profilo'] ?? []) . ' profili)');
    $run2 = $repo->saveRun($prj, (int)$sc['Sostenibile Firenze'], $asOf, null);
    $cmp = $repo->compareRuns($run['run_id'], $run2['run_id']);
    $d = array_values(array_filter($cmp, fn($r) => $r['ambito'] === 'totale' && $r['metrica'] === 'costo_aziendale_totale'))[0] ?? null;
    chk("Confronto run: delta costo Roma → Firenze", $d && $d['delta'] < 0, $d ? '(' . $k($d['delta']) . ', ' . number_format($d['delta_pct'] * 100, 1, ',', '.') . '%)' : '');
    try { $pdo->exec("DELETE FROM cm_prj WHERE id=$prj"); chk("Run immutabili: PRJ con run non eliminabile", false); }
    catch (Throwable $e) { chk("Run immutabili: PRJ con run non eliminabile (RESTRICT)", true); }
} catch (Throwable $e) { chk("Calc run", false, $e->getMessage()); }
isset($args['save']) ? $pdo->commit() : $pdo->rollBack();

// ── file ───────────────────────────────────────────────────────────
chk("VERSION = 1.10.00", trim((string)@file_get_contents("$root/VERSION")) === '1.10.00');
chk("app/Version.php PM_VERSION = 1.10.00", (bool)preg_match("/define\\('PM_VERSION', '1\\.10\\.00'\\)/", (string)@file_get_contents("$root/app/Version.php")));

echo "\n";
printf("%-40s %8s %10s %12s %12s %7s\n", 'Scenario', 'FTE', 'RAL', 'Costo pers.', 'Totale', '%can.');
foreach ($R as $n => $x) { $T = $x['totali'];
    printf("%-40s %8s %10s %12s %12s %7s\n", $n, number_format($T['fte_totali'], 1, ',', '.'), $k($T['costo_personale']), $k($T['costo_aziendale_personale']),
           $k($T['costo_aziendale_totale']), $pc($T['pct_canone'])); }
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
