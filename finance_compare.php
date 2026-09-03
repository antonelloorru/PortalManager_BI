<?php
/**
 * finance_compare.php — Confronto tra annualità economiche (v1.8.0)
 *
 * Confronta una metrica economica (TotaleFTE+CA, FullCost, CostoNoAuto,
 * ValoreFTE, TotCostoTab, RAL) tra due esercizi (anno A vs anno B), per
 * dipendente e in aggregato, con delta assoluto e percentuale. Esportazione XLSX.
 * Riservato ad Amministratore, HR e Responsabile Finanziario.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/XlsxWriter.php');
require_once(__DIR__ . '/app/CostModel.php');

$can_hr = can('view', 'finance_compare.php')
    && (can('view', 'employee_compensation.php') || can('view', 'manage_employees_compensation.php') || can('view', 'finance_overview.php'));
if (!can('view', 'finance_compare.php') || !$can_hr) { redirect('dashboard'); }
$u_id       = (int)$_SESSION['user_id'];
$can_export = can('export', 'finance_compare.php');

$cm    = new CostModel($pdo);
$years = $cm->years();
$ykeys = array_keys($years);

// anno A / anno B (default: corrente vs precedente catalogato)
$cur    = $cm->currentYear();
$prev   = null;
foreach ($ykeys as $y) if ($y < $cur) { $prev = $y; break; }
$yearA  = $cm->resolveYear($_GET['ya'] ?? $cur);
$yearB  = isset($_GET['yb']) ? $cm->resolveYear($_GET['yb']) : ($prev ?? $cur);

// metrica
$METRICS = [
    'totale_fte_ca' => ['TotaleFTE+CA', 'calc'],
    'tot_costo_tab' => ['TotCostoTab',  'calc'],
    'full_cost'     => ['FullCost',     'calc'],
    'costo_no_auto' => ['CostoNoAuto',  'calc'],
    'valore_fte'    => ['ValoreFTE',    'calc'],
    'ral'           => ['RAL',          'ral'],
];
$metric = isset($_GET['metric']) && isset($METRICS[$_GET['metric']]) ? $_GET['metric'] : 'totale_fte_ca';
$metricLabel = $METRICS[$metric][0];

// filtri
$f = [
    'company' => (int)($_GET['company'] ?? 0),
    'stato'   => (string)($_GET['stato'] ?? 'tutti'),
    'only'    => (string)($_GET['only'] ?? 'entrambi'), // entrambi | soloA | soloB | variati
];

/** Calcola la metrica per un dipendente in un anno, dai src_* prefissati. */
function metric_value(CostModel $cm, array $row, string $prefix, int $year, string $metric, array $METRICS): ?float
{
    $emp = []; $any = false;
    foreach (CostModel::INPUT_COLUMNS as $c) {
        $k = $prefix . $c;
        if (array_key_exists($k, $row)) { $emp[$c] = $row[$k]; if ($row[$k] !== null) $any = true; }
    }
    if (!$any) return null; // nessun dato per quell'anno
    $cost = $cm->compute($emp, $year);
    [$lbl, $kind] = $METRICS[$metric];
    if ($kind === 'ral') return (float)($cost['ral'] ?? 0);
    return (float)($cost['calc'][$metric] ?? 0);
}

/** Righe di confronto. */
function compare_rows(PDO $pdo, CostModel $cm, int $yearA, int $yearB, array $f, string $metric, array $METRICS): array
{
    $inCols = CostModel::INPUT_COLUMNS;
    $selA = implode(',', array_map(fn($c) => "eea.`$c` AS `a_$c`", $inCols));
    $selB = implode(',', array_map(fn($c) => "eeb.`$c` AS `b_$c`", $inCols));
    $w = ['(eea.id IS NOT NULL OR eeb.id IS NOT NULL)']; $a = [];
    if ($f['company'] > 0)          { $w[] = 'e.company_id = ?'; $a[] = $f['company']; }
    if ($f['stato'] === 'attivi')   { $w[] = "(e.end_date IS NULL OR e.end_date >= CURDATE())"; }
    if ($f['stato'] === 'cessati')  { $w[] = "(e.end_date IS NOT NULL AND e.end_date < CURDATE())"; }
    $where = implode(' AND ', $w);

    $sql = "SELECT e.id AS emp_id, e.last_name, e.first_name, e.employee_code,
                   eea.id AS a_id, eeb.id AS b_id, $selA, $selB
              FROM employees e
              LEFT JOIN hr_employee_economics eea ON eea.employee_id = e.id AND eea.year = ?
              LEFT JOIN hr_employee_economics eeb ON eeb.employee_id = e.id AND eeb.year = ?
             WHERE $where
             ORDER BY e.last_name, e.first_name";
    $st = $pdo->prepare($sql); $st->execute(array_merge([$yearA, $yearB], $a));
    $raw = $st->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($raw as $r) {
        $va = ($r['a_id'] !== null) ? metric_value($cm, $r, 'a_', $yearA, $metric, $METRICS) : null;
        $vb = ($r['b_id'] !== null) ? metric_value($cm, $r, 'b_', $yearB, $metric, $METRICS) : null;
        if ($f['only'] === 'soloA' && $vb !== null) continue;
        if ($f['only'] === 'soloB' && $va !== null) continue;
        if ($f['only'] === 'variati' && $va !== null && $vb !== null && abs($va - $vb) < 0.005) continue;
        $delta = ($va !== null && $vb !== null) ? ($va - $vb) : null;
        $pct   = ($delta !== null && $vb != 0) ? ($delta / $vb * 100) : null;
        $out[] = [
            'emp_id' => (int)$r['emp_id'],
            'name'   => trim(($r['last_name'] ?? '') . ' ' . ($r['first_name'] ?? '')),
            'code'   => $r['employee_code'],
            'a' => $va, 'b' => $vb, 'delta' => $delta, 'pct' => $pct,
        ];
    }
    return $out;
}

$rows = compare_rows($pdo, $cm, $yearA, $yearB, $f, $metric, $METRICS);

// totali
$totA = $totB = 0.0; $nA = $nB = 0;
foreach ($rows as $r) { if ($r['a'] !== null) { $totA += $r['a']; $nA++; } if ($r['b'] !== null) { $totB += $r['b']; $nB++; } }
$totDelta = $totA - $totB;
$totPct   = $totB != 0 ? ($totDelta / $totB * 100) : null;

/* ── Export ──────────────────────────────────────────────────────────────── */
if (($_GET['export'] ?? '') === 'xlsx') {
    if (!$can_export) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti per l'esportazione.</div>"; redirect('finance_compare'); }
    $out = [['Dipendente', 'Matricola', "$metricLabel $yearA", "$metricLabel $yearB", 'Delta', 'Delta %']];
    foreach ($rows as $r) {
        $out[] = [$r['name'], (string)($r['code'] ?? ''),
            $r['a'] === null ? '' : (string)round($r['a'], 2),
            $r['b'] === null ? '' : (string)round($r['b'], 2),
            $r['delta'] === null ? '' : (string)round($r['delta'], 2),
            $r['pct'] === null ? '' : (string)round($r['pct'], 2)];
    }
    $out[] = ['TOTALE', '', (string)round($totA, 2), (string)round($totB, 2), (string)round($totDelta, 2), $totPct === null ? '' : (string)round($totPct, 2)];
    write_log('Finance', 'success', "Export confronto annualità $yearA vs $yearB ($metricLabel): " . count($rows) . " dipendenti", $u_id);
    $w = new XlsxWriter(); $w->addSheet("Confronto {$yearA}-{$yearB}", $out); $w->download("confronto_{$metric}_{$yearA}_{$yearB}_" . date('Ymd_Hi') . ".xlsx"); exit;
}

$companies = [];
try { $companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable $e) {}

require_once('header.php');
if (!empty($_SESSION['flash_msg'])) { echo $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
$eur = fn($v) => $v === null ? '—' : number_format((float)$v, 2, ',', '.') . ' €';
$pcf = function ($v) {
    if ($v === null) return '—';
    $c = $v > 0.005 ? '#991b1b' : ($v < -0.005 ? '#166534' : '#64748b');
    $s = $v > 0 ? '+' : '';
    return "<span style='color:$c;font-weight:700'>$s" . number_format((float)$v, 1, ',', '.') . "%</span>";
};
$dcf = function ($v) {
    if ($v === null) return '—';
    $c = $v > 0.005 ? '#991b1b' : ($v < -0.005 ? '#166534' : '#64748b');
    $s = $v > 0 ? '+' : '';
    return "<span style='color:$c'>$s" . number_format((float)$v, 2, ',', '.') . " €</span>";
};
$qs = fn(array $o = []) => url_safe('finance_compare', array_filter(array_merge(['ya'=>$yearA,'yb'=>$yearB,'metric'=>$metric], $f, $o), fn($v) => $v !== '' && $v !== 0 && $v !== 'tutti' && $v !== 'entrambi'));
?>
<div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
  <div>
    <h1><i class="fa-solid fa-scale-balanced" style="color:#0891b2"></i> Confronto annualità</h1>
    <p style="color:var(--muted);font-size:13px">Confronto di una metrica economica tra due esercizi, per dipendente e in aggregato, con scostamento assoluto e percentuale.</p>
  </div>
  <a class="btn btn-sm" href="<?=url_safe('finance_overview',['year'=>$yearA])?>"><i class="fa-solid fa-arrow-left"></i> Torna a Finance</a>
</div>

<div class="card" style="margin-bottom:14px">
  <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
      <?= route_slug_field() ?>
    <div class="form-group" style="margin:0"><label>Metrica</label>
      <select name="metric">
        <?php foreach ($METRICS as $mk => $md): ?><option value="<?=h($mk)?>" <?=$metric===$mk?'selected':''?>><?=h($md[0])?></option><?php endforeach; ?>
      </select></div>
    <div class="form-group" style="margin:0"><label>Anno A</label>
      <select name="ya" style="font-weight:700"><?php foreach ($years as $y=>$l): ?><option value="<?=(int)$y?>" <?=$y===$yearA?'selected':''?>><?=(int)$y?></option><?php endforeach; ?></select></div>
    <div class="form-group" style="margin:0"><label>Anno B (riferimento)</label>
      <select name="yb" style="font-weight:700"><?php foreach ($years as $y=>$l): ?><option value="<?=(int)$y?>" <?=$y===$yearB?'selected':''?>><?=(int)$y?></option><?php endforeach; ?></select></div>
    <div class="form-group" style="margin:0"><label>Azienda</label>
      <select name="company"><option value="">tutte</option>
        <?php foreach($companies as $id=>$nm):?><option value="<?=(int)$id?>" <?=$f['company']===(int)$id?'selected':''?>><?=h($nm)?></option><?php endforeach;?></select></div>
    <div class="form-group" style="margin:0"><label>Stato</label>
      <select name="stato">
        <option value="tutti"   <?=$f['stato']==='tutti'?'selected':''?>>tutti</option>
        <option value="attivi"  <?=$f['stato']==='attivi'?'selected':''?>>in forza</option>
        <option value="cessati" <?=$f['stato']==='cessati'?'selected':''?>>cessati</option></select></div>
    <div class="form-group" style="margin:0"><label>Insieme</label>
      <select name="only">
        <option value="entrambi" <?=$f['only']==='entrambi'?'selected':''?>>tutti</option>
        <option value="variati"  <?=$f['only']==='variati'?'selected':''?>>solo variati</option>
        <option value="soloA"    <?=$f['only']==='soloA'?'selected':''?>>solo in <?=$yearA?></option>
        <option value="soloB"    <?=$f['only']==='soloB'?'selected':''?>>solo in <?=$yearB?></option></select></div>
    <button class="btn">Confronta</button>
    <?php if ($can_export): ?><a class="btn btn-success" href="<?=$qs(['export'=>'xlsx'])?>"><i class="fa-solid fa-file-excel"></i> XLSX</a><?php endif; ?>
  </form>
</div>

<div class="card" style="margin-bottom:14px">
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px">
    <div style="padding:12px;border-radius:8px;background:#f8fafc;border:1px solid #e2e8f0">
      <div style="font-size:10px;color:var(--muted);font-weight:700"><?=h($metricLabel)?> · <?=$yearA?> (<?=$nA?> dip.)</div>
      <div style="font-size:16px;font-weight:800"><?=$eur($totA)?></div></div>
    <div style="padding:12px;border-radius:8px;background:#f8fafc;border:1px solid #e2e8f0">
      <div style="font-size:10px;color:var(--muted);font-weight:700"><?=h($metricLabel)?> · <?=$yearB?> (<?=$nB?> dip.)</div>
      <div style="font-size:16px;font-weight:800"><?=$eur($totB)?></div></div>
    <div style="padding:12px;border-radius:8px;background:#fef9f9;border:1px solid #fca5a5">
      <div style="font-size:10px;color:var(--muted);font-weight:700">Delta assoluto</div>
      <div style="font-size:16px;font-weight:800"><?=$dcf($totDelta)?></div></div>
    <div style="padding:12px;border-radius:8px;background:#fef9f9;border:1px solid #fca5a5">
      <div style="font-size:10px;color:var(--muted);font-weight:700">Delta %</div>
      <div style="font-size:16px;font-weight:800"><?=$pcf($totPct)?></div></div>
  </div>
</div>

<div class="card">
  <div style="overflow-x:auto">
    <table class="data-table" style="width:100%;font-size:12px">
      <thead><tr>
        <th>Dipendente</th><th>Matricola</th>
        <th style="text-align:right"><?=h($metricLabel)?> <?=$yearA?></th>
        <th style="text-align:right"><?=h($metricLabel)?> <?=$yearB?></th>
        <th style="text-align:right">Delta</th><th style="text-align:right">Delta %</th>
      </tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:16px">Nessun dato per gli esercizi selezionati.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><?=h($r['name'])?></td>
          <td style="color:var(--muted)"><?=h($r['code'] ?? '—')?></td>
          <td style="text-align:right"><?=$eur($r['a'])?></td>
          <td style="text-align:right"><?=$eur($r['b'])?></td>
          <td style="text-align:right"><?=$dcf($r['delta'])?></td>
          <td style="text-align:right"><?=$pcf($r['pct'])?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <?php if ($rows): ?>
      <tfoot><tr style="font-weight:800;border-top:2px solid #cbd5e1">
        <td colspan="2">TOTALE (<?=count($rows)?>)</td>
        <td style="text-align:right"><?=$eur($totA)?></td>
        <td style="text-align:right"><?=$eur($totB)?></td>
        <td style="text-align:right"><?=$dcf($totDelta)?></td>
        <td style="text-align:right"><?=$pcf($totPct)?></td>
      </tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
  <p style="color:var(--muted);font-size:11px;margin-top:8px">Delta = <?=$yearA?> − <?=$yearB?>. Valori in verde = riduzione rispetto all'anno di riferimento, in rosso = aumento. I dipendenti privi di dati in un esercizio mostrano «—» per quella colonna.</p>
</div>
<?php require_once('footer.php'); ?>
