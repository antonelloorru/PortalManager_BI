<?php
/**
 * prj_history.php — Scenari & confronti progetti (v1.10.02)
 *
 * Calc run salvati di tutti i Progetti PRJ (cm_prj_calc_run + cm_prj_calc_result, immutabili):
 * filtri per periodo del calcolo, progetto, stato, società, commessa SP, scenario e zona;
 * confronto affiancato di 2+ run (delta assoluto e % rispetto al primo), andamento per anno di contratto,
 * trend dei calcoli nel tempo, export XLSX. Permessi: prj_history.php view / export.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/PrjRepo.php');
require_once(__DIR__ . '/app/PrjUi.php');
require_once(__DIR__ . '/app/PmCharts.php');

if (!can('view', 'prj_history.php')) { redirect('dashboard'); }
$can_export = can('export', 'prj_history.php');
$u_id = (int)$_SESSION['user_id'];
$repo = new PrjRepo($pdo);

$dOk = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET[$k] ?? '') ? $_GET[$k] : '';
$arr = function ($v): array { if (is_string($v)) $v = $v === '' ? [] : explode(',', $v); return array_values(array_unique(array_filter(array_map('strval', (array)$v), fn($x) => $x !== ''))); };
$ints = fn(array $a) => array_values(array_unique(array_map('intval', array_filter($a, fn($x) => ctype_digit((string)$x)))));
$f = [
    'from'    => $dOk('from'), 'to' => $dOk('to'),
    'prj'     => $ints($arr($_GET['prj'] ?? [])),
    'stati'   => array_values(array_intersect($arr($_GET['stato'] ?? []), PrjUi::STATI)),
    'company' => (int)($_GET['company'] ?? 0),
    'link'    => in_array($_GET['link'] ?? '', ['0', '1'], true) ? $_GET['link'] : '',
    'sp'      => mb_substr(trim((string)($_GET['sp'] ?? '')), 0, 40),
    'scen'    => mb_substr(trim((string)($_GET['scen'] ?? '')), 0, 120),
    'zone'    => $arr($_GET['zona'] ?? []),
    'last'    => !empty($_GET['last']) ? 1 : 0,
];
$cmpIds = $ints($arr($_GET['cmp'] ?? []));

$w = ['1=1']; $a = [];
if ($f['from'] !== '') { $w[] = 'r.created_at >= ?'; $a[] = $f['from'] . ' 00:00:00'; }
if ($f['to'] !== '')   { $w[] = 'r.created_at <= ?'; $a[] = $f['to'] . ' 23:59:59'; }
if ($f['prj'])   { $w[] = 'r.prj_id IN (' . implode(',', array_fill(0, count($f['prj']), '?')) . ')'; array_push($a, ...$f['prj']); }
if ($f['stati']) { $w[] = 'p.stato IN (' . implode(',', array_fill(0, count($f['stati']), '?')) . ')'; array_push($a, ...$f['stati']); }
if ($f['company']) { $w[] = 'p.exec_company_id = ?'; $a[] = $f['company']; }
if ($f['link'] !== '') $w[] = $f['link'] === '1' ? 'r.sp_project_id IS NOT NULL' : 'r.sp_project_id IS NULL';
if ($f['sp'] !== '')   { $w[] = 'sp.project_code LIKE ?'; $a[] = '%' . $f['sp'] . '%'; }
if ($f['scen'] !== '') { $w[] = 'r.scenario_nome LIKE ?'; $a[] = '%' . $f['scen'] . '%'; }
if ($f['zone'])  { $w[] = "EXISTS (SELECT 1 FROM cm_prj_calc_result z WHERE z.run_id = r.id AND z.ambito = 'zona' AND z.ambito_ref IN (" . implode(',', array_fill(0, count($f['zone']), '?')) . '))'; array_push($a, ...$f['zone']); }
if ($f['last'])  $w[] = 'r.id = (SELECT MAX(r2.id) FROM cm_prj_calc_run r2 WHERE r2.prj_id = r.prj_id AND r2.scenario_id <=> r.scenario_id)';
$M = ['fte_totali', 'costo_personale', 'costo_aziendale_personale', 'strutturali', 'overhead', 'costo_aziendale_totale', 'canone_medio', 'canone_netto', 'pct_canone',
      'margine', 'margine_pct', 'ribasso_max_pareggio', 'valore_punto_ribasso', 'fte_finanziabili', 'costo_medio_fte', 'valore_unitario_ticket'];
$sel = implode(', ', array_map(fn($m) => "MAX(CASE WHEN x.metrica = '$m' THEN x.valore END) AS `$m`", $M));
$st = $pdo->prepare("SELECT r.id, r.prj_id, r.created_at, r.as_of, r.scenario_id, r.scenario_nome, r.app_version, r.params_hash, p.prj_code, p.nome, p.stato,
                            sp.project_code AS sp_code, co.name AS societa,
                            (SELECT z.ambito_ref FROM cm_prj_calc_result z WHERE z.run_id = r.id AND z.ambito = 'zona' LIMIT 1) AS zona, $sel
                       FROM cm_prj_calc_run r JOIN cm_prj p ON p.id = r.prj_id
                       LEFT JOIN cm_projects sp ON sp.id = r.sp_project_id LEFT JOIN companies co ON co.id = p.exec_company_id
                       LEFT JOIN cm_prj_calc_result x ON x.run_id = r.id AND x.ambito = 'totale'
                      WHERE " . implode(' AND ', $w) . " GROUP BY r.id ORDER BY r.created_at DESC, r.id DESC LIMIT 1000");
$st->execute($a);
$runs = $st->fetchAll(PDO::FETCH_ASSOC);
$byId = []; foreach ($runs as $r) $byId[(int)$r['id']] = $r;
$cmpIds = array_values(array_filter($cmpIds, fn($i) => isset($byId[$i])));
$cmp = []; $years = [];
foreach ($cmpIds as $rid) {
    $cmp[$rid] = $repo->runResults($rid);
    foreach (array_keys($cmp[$rid]['anno'] ?? []) as $y) $years[$y] = true;
}
ksort($years);

$qp = array_filter(['from' => $f['from'], 'to' => $f['to'], 'prj' => implode(',', $f['prj']), 'stato' => implode(',', $f['stati']), 'company' => $f['company'] ?: '',
                    'link' => $f['link'], 'sp' => $f['sp'], 'scen' => $f['scen'], 'zona' => implode(',', $f['zone']), 'last' => $f['last'] ?: '', 'cmp' => implode(',', $cmpIds)],
                   fn($v) => $v !== '' && $v !== null);
$active = (int)($f['from'] !== '') + (int)($f['to'] !== '') + (int)(bool)$f['prj'] + (int)(bool)$f['stati'] + (int)(bool)$f['company'] + (int)($f['link'] !== '')
        + (int)($f['sp'] !== '') + (int)($f['scen'] !== '') + (int)(bool)$f['zone'] + $f['last'];
$LBL = ['fte_totali' => 'FTE', 'costo_personale' => 'Costo personale (RAL)', 'costo_aziendale_personale' => 'Costo aziendale personale', 'strutturali' => 'Strutturali',
        'overhead' => 'Overhead', 'costo_aziendale_totale' => 'Costo aziendale totale', 'canone_medio' => 'Canone medio', 'canone_netto' => 'Canone netto',
        'pct_canone' => '% canone', 'margine' => 'Margine', 'margine_pct' => 'Margine %', 'ribasso_max_pareggio' => 'Ribasso max a pareggio',
        'valore_punto_ribasso' => 'Valore punto di ribasso', 'fte_finanziabili' => 'FTE finanziabili', 'costo_medio_fte' => 'Costo medio per FTE', 'valore_unitario_ticket' => 'Valore unitario ticket'];
$fmt = fn(string $m, $v) => $v === null ? '—' : (in_array($m, ['pct_canone', 'margine_pct', 'ribasso_max_pareggio'], true) ? PrjUi::pct((float)$v, 1)
       : (in_array($m, ['fte_totali', 'fte_finanziabili'], true) ? PrjUi::n((float)$v, 1) : (in_array($m, ['costo_medio_fte', 'valore_unitario_ticket'], true) ? PrjUi::eur((float)$v, 0) : PrjUi::k((float)$v))));

// ── Export XLSX ──
if (($_GET['export'] ?? '') === 'xlsx') {
    if (!$can_export) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Export non consentito.</div>"; redirect('prj_history'); }
    while (ob_get_level() > 0) { @ob_end_clean(); }
    @ini_set('zlib.output_compression', '0');
    $data = [array_merge(['Run', 'Data calcolo', 'As-of', 'Codice PRJ', 'Progetto', 'Stato', 'Società', 'Commessa SP', 'Scenario', 'Zona', 'Versione'], array_values($LBL))];
    foreach ($runs as $r) {
        $row = [(int)$r['id'], $r['created_at'], $r['as_of'], $r['prj_code'], $r['nome'], $r['stato'], (string)$r['societa'], (string)$r['sp_code'], (string)$r['scenario_nome'], (string)$r['zona'], $r['app_version']];
        foreach ($M as $m) $row[] = $r[$m] !== null ? round((float)$r[$m], 4) : '';
        $data[] = $row;
    }
    require_once(__DIR__ . '/XlsxWriter.php');
    $xw = new XlsxWriter(); $xw->addSheet('Calc run', $data);
    if (count($cmp) >= 2) {
        $base = (int)$cmpIds[0];
        $c = [array_merge(['Metrica'], array_map(fn($i) => '#' . $i . ' ' . $byId[$i]['prj_code'] . ' ' . $byId[$i]['scenario_nome'], $cmpIds), array_map(fn($i) => 'Δ #' . $i, array_slice($cmpIds, 1)))];
        foreach ($M as $m) {
            $row = [$LBL[$m]]; foreach ($cmpIds as $i) $row[] = $cmp[$i]['totale'][''][$m] ?? '';
            foreach (array_slice($cmpIds, 1) as $i) $row[] = isset($cmp[$i]['totale'][''][$m], $cmp[$base]['totale'][''][$m]) ? $cmp[$i]['totale'][''][$m] - $cmp[$base]['totale'][''][$m] : '';
            $c[] = $row;
        }
        $xw->addSheet('Confronto', $c);
    }
    $flt = [['Parametro', 'Valore']]; foreach ($qp as $k => $v) $flt[] = [$k, (string)$v]; $flt[] = ['Generato il', date('d/m/Y H:i')];
    $xw->addSheet('Filtri', $flt);
    write_log('Commesse', 'info', 'Export Scenari & confronti progetti: ' . count($runs) . ' run', $u_id);
    $xw->download('prj_calc_run_' . date('Ymd_Hi') . '.xlsx');
    exit;
}

$prjs = $pdo->query("SELECT id, prj_code, nome FROM cm_prj ORDER BY prj_code DESC")->fetchAll(PDO::FETCH_ASSOC);
$companies = $pdo->query("SELECT id, name FROM companies WHERE id IN (SELECT exec_company_id FROM cm_prj) ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$zones = $pdo->query("SELECT DISTINCT ambito_ref FROM cm_prj_calc_result WHERE ambito = 'zona' ORDER BY ambito_ref")->fetchAll(PDO::FETCH_COLUMN);

// trend: con un solo progetto filtrato, calcoli in ordine di tempo
$trend = null;
if (count($f['prj']) === 1 && count($runs) >= 2) {
    $tr = array_reverse(array_slice($runs, 0, 24));
    $trend = PmCharts::groupedBars(array_map(fn($r) => '#' . $r['id'] . ' ' . date('d/m', strtotime($r['created_at'])), $tr), [
        ['label' => 'Costo aziendale totale', 'color' => '#2563eb', 'values' => array_map(fn($r) => (float)$r['costo_aziendale_totale'], $tr)],
        ['label' => 'Canone netto', 'color' => '#16a34a', 'values' => array_map(fn($r) => (float)$r['canone_netto'], $tr)],
    ], ['unit' => 'k€', 'divisor' => 1000, 'decimals' => 1, 'height' => 200]);
}

$msg = '';
if (!empty($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
$GLOBALS['PM_NO_AUTOFILTER'] = true;
require_once('header.php');
?>
<div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
  <div><h1><i class="fa-solid fa-scale-balanced"></i> Scenari &amp; confronti progetti</h1>
    <p style="color:var(--muted);font-size:13px">Calcoli salvati dei Progetti PRJ: ogni calcolo conserva i parametri usati (as-of), la commessa SP collegata al momento e la versione del software.</p></div>
  <div style="display:flex;gap:8px">
    <?php if (can('view', 'manage_projects_prj.php')): ?><a class="btn btn-sm" href="<?=url_safe('manage_projects', ['view' => 'prj'])?>"><i class="fa-solid fa-compass-drafting"></i> Progetti PRJ</a><?php endif; ?>
    <?php if ($can_export): ?><a class="btn btn-success btn-sm" href="<?=url_safe('prj_history', $qp + ['export' => 'xlsx'])?>"><i class="fa-solid fa-file-excel"></i> XLSX</a><?php endif; ?>
  </div>
</div>
<?= $msg ?>

<details class="pm-panel" <?=$active ? 'open' : ''?>>
  <summary><i class="fa-solid fa-chevron-right pm-chev"></i> Filtri <?php if ($active): ?><span class="pm-badge"><?=$active?></span><?php endif; ?>
    <span class="pm-hint"><?=count($runs)?> calcoli</span></summary>
  <div class="pm-panel-body">
    <form method="get"><?= route_slug_field() ?>
      <?php foreach ($cmpIds as $i): ?><input type="hidden" name="cmp[]" value="<?=$i?>"><?php endforeach; ?>
      <div class="pm-grid">
        <div class="form-group"><label>Calcolo: dal</label><input type="date" name="from" value="<?=h($f['from'])?>"></div>
        <div class="form-group"><label>Calcolo: al</label><input type="date" name="to" value="<?=h($f['to'])?>"></div>
        <div class="form-group"><label>Progetto <span class="pm-multi">(multipla)</span></label><select name="prj[]" multiple size="3" class="pm-ms" data-placeholder="Tutti">
          <?php foreach ($prjs as $p): ?><option value="<?=(int)$p['id']?>" <?=in_array((int)$p['id'], $f['prj'], true) ? 'selected' : ''?>><?=h($p['prj_code'] . ' — ' . $p['nome'])?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Stato progetto <span class="pm-multi">(multipla)</span></label><select name="stato[]" multiple size="3" class="pm-ms" data-placeholder="Tutti">
          <?php foreach (PrjUi::STATI as $t): ?><option <?=in_array($t, $f['stati'], true) ? 'selected' : ''?>><?=h($t)?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Società esecutrice</label><select name="company"><option value="">— tutte —</option><?php foreach ($companies as $cid => $cn): ?><option value="<?=(int)$cid?>" <?=$f['company'] === (int)$cid ? 'selected' : ''?>><?=h($cn)?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Collegato a commessa SP (al calcolo)</label><select name="link"><option value="">— indifferente —</option><option value="1" <?=$f['link'] === '1' ? 'selected' : ''?>>Sì</option><option value="0" <?=$f['link'] === '0' ? 'selected' : ''?>>No</option></select></div>
        <div class="form-group"><label>Codice commessa SP</label><input type="text" name="sp" value="<?=h($f['sp'])?>"></div>
        <div class="form-group"><label>Scenario (nome)</label><input type="text" name="scen" value="<?=h($f['scen'])?>" placeholder="es. Sostenibile"></div>
        <div class="form-group"><label>Zona <span class="pm-multi">(multipla)</span></label><select name="zona[]" multiple size="3" class="pm-ms" data-placeholder="Tutte">
          <?php foreach ($zones as $z): ?><option <?=in_array($z, $f['zone'], true) ? 'selected' : ''?>><?=h($z)?></option><?php endforeach; ?></select></div>
        <div class="form-group" style="display:flex;align-items:flex-end"><label style="display:flex;gap:6px;align-items:center;font-size:12px;font-weight:500;padding-bottom:8px">
          <input type="checkbox" name="last" value="1" <?=$f['last'] ? 'checked' : ''?>> Solo l'ultimo calcolo per scenario</label></div>
      </div>
      <div style="display:flex;gap:8px;margin-top:10px"><button class="btn btn-primary btn-sm"><i class="fa-solid fa-magnifying-glass"></i> Applica</button>
        <?php if ($active): ?><a class="btn btn-sm" href="<?=url_safe('prj_history')?>">Azzera</a><?php endif; ?></div>
    </form>
  </div>
</details>

<?php if ($trend): ?>
<div class="card" style="margin-bottom:14px"><div class="card-header"><span class="card-title"><i class="fa-solid fa-chart-column"></i> Andamento dei calcoli — <?=h($runs[0]['prj_code'])?></span></div><?=$trend?></div>
<?php endif; ?>

<?php if (count($cmp) >= 2): $base = (int)$cmpIds[0]; ?>
<div class="card" style="margin-bottom:14px;overflow-x:auto">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-code-compare"></i> Confronto affiancato</span><span class="pm-hint">delta rispetto al primo calcolo selezionato (#<?=$base?>)</span></div>
  <table class="data-table" style="width:100%;font-size:12px;white-space:nowrap">
    <thead><tr><th>Indicatore</th>
      <?php foreach ($cmpIds as $i): $r = $byId[$i]; ?><th style="text-align:right">#<?=$i?> <?=h($r['prj_code'])?><br><span style="font-weight:400"><?=h((string)$r['scenario_nome'])?> · <?=h(date('d/m/Y', strtotime($r['created_at'])))?></span></th><?php endforeach; ?>
      <?php foreach (array_slice($cmpIds, 1) as $i): ?><th style="text-align:right">Δ #<?=$i?></th><th style="text-align:right">Δ%</th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($M as $m): $b = $cmp[$base]['totale'][''][$m] ?? null; ?>
      <tr style="<?=in_array($m, ['costo_aziendale_totale', 'pct_canone'], true) ? 'font-weight:700' : ''?>"><td><?=h($LBL[$m])?></td>
        <?php foreach ($cmpIds as $i): ?><td style="text-align:right"><?=$fmt($m, $cmp[$i]['totale'][''][$m] ?? null)?></td><?php endforeach; ?>
        <?php foreach (array_slice($cmpIds, 1) as $i): $v = $cmp[$i]['totale'][''][$m] ?? null; $d = ($v !== null && $b !== null) ? $v - $b : null; ?>
          <?php $up = in_array($m, ['margine', 'margine_pct', 'ribasso_max_pareggio', 'fte_finanziabili', 'canone_medio', 'canone_netto', 'valore_punto_ribasso', 'valore_unitario_ticket'], true); /* più alto = migliore */ ?>
          <td style="text-align:right;color:<?=$d === null || abs($d) < 1e-9 ? 'inherit' : (($d > 0) !== $up ? '#dc2626' : '#16a34a')?>"><?=$d === null ? '—' : $fmt($m, $d)?></td>
          <td style="text-align:right"><?=($d !== null && $b) ? PrjUi::pct($d / abs($b), 1) : '—'?></td>
        <?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($years): ?>
    <h4 style="font-size:12px;margin:14px 0 6px">Costo per anno di contratto</h4>
    <?= PmCharts::groupedBars(array_map('strval', array_keys($years)), array_map(fn($i, $j) => [
          'label' => '#' . $i . ' ' . $byId[$i]['prj_code'] . ' ' . $byId[$i]['scenario_nome'],
          'color' => ['#2563eb', '#16a34a', '#d97706', '#7c3aed', '#dc2626', '#0891b2'][$j % 6],
          'values' => array_map(fn($y) => (float)($cmp[$i]['anno'][(string)$y]['costo'] ?? 0), array_keys($years))], $cmpIds, array_keys($cmpIds)),
        ['unit' => 'k€', 'divisor' => 1000, 'decimals' => 1, 'height' => 200]) ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<form method="get"><?= route_slug_field() ?>
<?php foreach ($qp as $k => $v) if ($k !== 'cmp') echo '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">'; ?>
<div class="card" style="overflow-x:auto">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-flask"></i> Calcoli salvati</span>
    <span><button class="btn btn-sm"><i class="fa-solid fa-code-compare"></i> Confronta i selezionati</button></span></div>
  <table class="data-table" style="width:100%;font-size:12px;white-space:nowrap">
    <thead><tr><th></th><th>Run</th><th>Data</th><th>As-of</th><th>Progetto</th><th>Stato</th><th>Commessa SP</th><th>Scenario</th><th>Zona</th>
      <th style="text-align:right">FTE</th><th style="text-align:right">Costo totale</th><th style="text-align:right">Canone netto</th><th style="text-align:right">% canone</th><th style="text-align:right">Margine</th><th style="text-align:right">Ribasso max</th><th>Versione</th></tr></thead>
    <tbody>
    <?php if (!$runs): ?><tr><td colspan="16" style="text-align:center;color:var(--muted);padding:18px">Nessun calcolo salvato<?=$active ? ' per i filtri impostati' : ': usare «Calcola e salva» nella tab Scenari della scheda progetto'?>.</td></tr><?php endif; ?>
    <?php foreach ($runs as $r): ?>
      <tr><td><input type="checkbox" name="cmp[]" value="<?=(int)$r['id']?>" <?=in_array((int)$r['id'], $cmpIds, true) ? 'checked' : ''?>></td>
        <td>#<?=(int)$r['id']?></td><td><?=h(date('d/m/Y H:i', strtotime($r['created_at'])))?></td><td><?=h($r['as_of'])?></td>
        <td><a href="<?=url_safe('prj_dashboard', ['id' => (int)$r['prj_id'], 'tab' => 'stor'])?>" style="font-weight:700"><?=h($r['prj_code'])?></a> <span style="color:var(--muted)"><?=h(mb_strimwidth($r['nome'], 0, 30, '…'))?></span></td>
        <td><?=h($r['stato'])?></td><td><?=h((string)($r['sp_code'] ?? '—'))?></td><td><?=h((string)$r['scenario_nome'])?></td><td><?=h((string)$r['zona'])?></td>
        <td style="text-align:right"><?=$fmt('fte_totali', $r['fte_totali'])?></td><td style="text-align:right"><?=$fmt('costo_aziendale_totale', $r['costo_aziendale_totale'])?></td>
        <td style="text-align:right"><?=$fmt('canone_netto', $r['canone_netto'])?></td>
        <td style="text-align:right;font-weight:700;color:<?=$r['pct_canone'] !== null ? ((float)$r['pct_canone'] > 1 ? '#dc2626' : '#16a34a') : 'inherit'?>"><?=$fmt('pct_canone', $r['pct_canone'])?></td>
        <td style="text-align:right"><?=$fmt('margine', $r['margine'])?></td><td style="text-align:right"><?=$fmt('ribasso_max_pareggio', $r['ribasso_max_pareggio'])?></td>
        <td style="color:var(--muted)"><?=h($r['app_version'])?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
</form>
<?php require_once('footer.php'); ?>
