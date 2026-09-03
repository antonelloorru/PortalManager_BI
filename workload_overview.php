<?php
/**
 * workload_overview.php — Carico risorse e sovrapposizioni (v1.7.71)
 *
 * Tre viste complementari sullo stesso periodo:
 *   1. Heatmap persona × mese: ore impegnate e saturazione rispetto alla capacità,
 *      con dettaglio delle commesse su cui ciascuno ha lavorato (clic sulla cella).
 *   2. Conflitti per persona: mesi con più commesse in parallelo o oltre capacità.
 *   3. Sovrapposizioni tra commesse: coppie che condividono le stesse risorse negli
 *      stessi mesi (contesa di risorse).
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/Workload.php');

if (!can('view', 'workload_overview.php')) { redirect('manage_projects'); }
$u_id = (int)$_SESSION['user_id'];
$wl = new Workload($pdo);

// periodo di default: ultimi 12 mesi con dati (o ultimo anno)
$bounds = $pdo->query("SELECT DATE_FORMAT(MIN(report_date),'%Y-%m') dal, DATE_FORMAT(MAX(report_date),'%Y-%m') al
                         FROM cm_intervention_reports WHERE report_date IS NOT NULL")->fetch(PDO::FETCH_ASSOC);
$def_to   = $bounds['al'] ?: date('Y-m');
$def_from = date('Y-m', strtotime($def_to . '-01 -11 month'));
if ($bounds['dal'] && $def_from < $bounds['dal']) $def_from = $bounds['dal'];

$from = preg_match('/^\d{4}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : $def_from;
$to   = preg_match('/^\d{4}-\d{2}$/', $_GET['to'] ?? '')   ? $_GET['to']   : $def_to;
if ($from > $to) [$from, $to] = [$to, $from];
$f = ['project_id' => (int)($_GET['proj'] ?? 0), 'employee_id' => (int)($_GET['emp'] ?? 0)];
// v1.7.79: filtro per linea di servizio.
$service_line = trim((string)($_GET['sl'] ?? ''));
$f['service_line'] = $service_line;
// v1.8.6: società di appartenenza, cliente, stato operativo, tipologia.
$f['company_id']        = (int)($_GET['company'] ?? 0);
$f['client_id']         = (int)($_GET['client'] ?? 0);
$f['operational_status']= trim((string)($_GET['ostatus'] ?? ''));
$f['project_type']      = trim((string)($_GET['ptype'] ?? ''));
// v1.7.73: selezione multipla risorse + ordinamento.
$emp_ids = array_values(array_filter(array_map('intval', (array)($_GET['emps'] ?? []))));
$f['employee_ids'] = $emp_ids;
$sort = in_array($_GET['sort'] ?? '', ['hours_desc','hours_asc','name'], true) ? $_GET['sort'] : 'hours_desc';
$f['sort'] = $sort;
$only_overload = ($_GET['ov'] ?? '') === '1';

$months  = Workload::monthRange($from, $to);
// limite di sicurezza: non più di 24 mesi in heatmap
if (count($months) > 24) { $months = array_slice($months, -24); $from = $months[0]; }

$matrix   = $wl->matrix($from, $to, $f);
$overlaps = $wl->personOverlaps($matrix, 2, $only_overload);
$projOv   = $wl->projectOverlaps($from, $to, 100, $f);
// v1.7.73: serie per il grafico (limitate alle risorse in matrice, quindi ai filtri attivi).
$chart    = $wl->chartSeries($matrix, $months);
$capSeries = $wl->capacitySeries($months);

// v1.8.2: mese di dettaglio per la vista giornaliera (default: ultimo mese del periodo).
$dm = preg_match('/^\d{4}-\d{2}$/', $_GET['dm'] ?? '') ? $_GET['dm'] : (end($months) ?: $to);
if ($months && !in_array($dm, $months, true)) $dm = end($months);
$days        = $dm ? Workload::daysOfMonth($dm) : [];
$dailyMatrix = $dm ? $wl->dailyMatrix($dm, $f) : [];
$dailyChart  = $wl->dailyChartSeries($dailyMatrix, $days);
$dailyCap    = $dm ? $wl->dailyCapacity($dm) : [];

$projects  = $pdo->query("SELECT id, CONCAT(project_code,' — ',name) l FROM cm_projects ORDER BY project_code")->fetchAll(PDO::FETCH_KEY_PAIR);
$employees = $pdo->query("SELECT id, CONCAT(last_name,' ',first_name) l FROM employees ORDER BY last_name, first_name")->fetchAll(PDO::FETCH_KEY_PAIR);
$service_lines = $pdo->query("SELECT DISTINCT service_line FROM cm_projects WHERE service_line IS NOT NULL AND service_line <> '' ORDER BY service_line")->fetchAll(PDO::FETCH_COLUMN);
// v1.8.6: sorgenti dei nuovi filtri
$companies    = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$clients_wl   = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$op_statuses  = $pdo->query("SELECT DISTINCT operational_status FROM cm_projects WHERE operational_status IS NOT NULL AND operational_status <> '' ORDER BY operational_status")->fetchAll(PDO::FETCH_COLUMN);
$proj_types   = ['Gara Consip/MePA/Carrier','Progetto Standard','Trattativa Diretta'];

// v1.8.40: indicatori standard di record allineati a manage_projects.php.
// - total_projects   = totale commesse in anagrafica (cm_projects).
// - scoped_projects  = commesse effettivamente coinvolte dal filtro corrente
//   (compaiono almeno una volta nella heatmap oppure sono selezionate).
$total_projects = (int)$pdo->query("SELECT COUNT(*) FROM cm_projects")->fetchColumn();
$scoped_projects = 0;
if ($f['project_id']) {
    $scoped_projects = 1;
} else {
    $seen = [];
    foreach ($matrix as $eid => $row) {
        foreach (($row['months'] ?? []) as $ym => $cell) {
            foreach (($cell['projects'] ?? []) as $pid => $_) $seen[(int)$pid] = 1;
        }
    }
    $scoped_projects = count($seen);
}

// dettaglio cella (persona+mese)
$d_emp = (int)($_GET['d_emp'] ?? 0);
$d_ym  = preg_match('/^\d{4}-\d{2}$/', $_GET['d_ym'] ?? '') ? $_GET['d_ym'] : '';
$d_projects = [];
if ($d_emp && $d_ym && isset($matrix[$d_emp]['months'][$d_ym])) {
    $d_projects = $matrix[$d_emp]['months'][$d_ym]['projects'];
    uasort($d_projects, fn($a, $b) => $b['ore'] <=> $a['ore']);
}

// export XLSX della heatmap
if (($_GET['export'] ?? '') === 'xlsx') {
    require_once(__DIR__ . '/XlsxWriter.php');
    $head = ['Risorsa']; foreach ($months as $ym) $head[] = $ym; $head[] = 'Totale';
    $rows = [$head];
    foreach ($matrix as $eid => $row) {
        $r = [$row['nome']];
        foreach ($months as $ym) { $c = $row['months'][$ym]['ore'] ?? 0; $r[] = $c ? round($c, 2) : ''; }
        $r[] = round($row['tot'], 2);
        $rows[] = $r;
    }
    $cap = [['Mese','Giorni feriali','Capacità (h)']];
    foreach ($months as $ym) $cap[] = [$ym, Workload::workingDaysOfMonth($ym), $wl->monthlyCapacity($ym)];
    $ov = [['Risorsa','Mese','Ore','Capacità','Saturazione %','N. commesse','Sovraccarico','Commesse']];
    foreach ($overlaps as $o) {
        $ov[] = [$o['nome'], $o['ym'], $o['ore'], $o['capacity'], $o['sat'], $o['n_proj'], $o['overload']?'SI':'', implode(' | ', array_map(fn($p)=>$p['code'].' ('.$p['ore'].'h)', $o['projects']))];
    }
    $w = new XlsxWriter();
    $w->addSheet('Carico risorse', $rows);
    $w->addSheet('Capacità mensile', $cap);
    $w->addSheet('Conflitti risorse', $ov);
    // v1.7.74: foglio sovrapposizioni tra commesse con fascia temporale e ore per commessa
    $so = [['Commessa A','Commessa B','Dal mese','Al mese','Mesi','Risorse condivise','Ore A','Ore B','Ore totali']];
    foreach ($wl->projectOverlaps($from, $to, 500, $f) as $po) {
        $so[] = [$po['a']['code'].' — '.$po['a']['name'], $po['b']['code'].' — '.$po['b']['name'],
                 $po['first_month'], $po['last_month'], $po['n_months'], implode(', ', array_values($po['people'])),
                 $po['hours_a'], $po['hours_b'], $po['shared_hours']];
    }
    $w->addSheet('Sovrapposizioni commesse', $so);
    // v1.8.2: dettaglio giornaliero del mese selezionato (risorsa × giorno)
    if ($dm && $days) {
        $dh = ['Risorsa']; foreach ($days as $d) $dh[] = (int)substr($d, 8, 2); $dh[] = 'Totale';
        $dr = [$dh];
        foreach ($dailyMatrix as $eid => $row) {
            $rr = [$row['nome']];
            foreach ($days as $d) { $c = $row['days'][$d]['ore'] ?? 0; $rr[] = $c ? round($c, 2) : ''; }
            $rr[] = round($row['tot'], 2);
            $dr[] = $rr;
        }
        $w->addSheet('Giornaliero ' . $dm, $dr);
    }
    write_log('Workload','info',"Export carico $from..$to",$u_id);
    $w->download("carico_risorse_{$from}_{$to}.xlsx");
    exit;
}

require_once('header.php');
$qs = function (array $over = []) use ($from,$to,$f,$only_overload,$emp_ids,$sort,$service_line,$dm) {
    $p = array_filter(['from'=>$from,'to'=>$to,'proj'=>$f['project_id'],'emp'=>$f['employee_id'],'sl'=>$service_line,
                       'company'=>$f['company_id'],'client'=>$f['client_id'],'ostatus'=>$f['operational_status'],'ptype'=>$f['project_type'],
                       'ov'=>$only_overload?'1':'','sort'=>$sort!=='hours_desc'?$sort:'','dm'=>$dm], fn($v)=>$v!=='' && $v!==0);
    $q = array_merge($p, $over);
    // preserva la selezione multipla di risorse
    if ($emp_ids && !array_key_exists('emps', $over)) $q['emps'] = $emp_ids;
    return url_safe('workload_overview', $q);
};
// scala colore saturazione
$satColor = function (float $sat): string {
    if ($sat <= 0)   return '';
    if ($sat < 60)   return 'background:#dbeafe;color:#1e40af';
    if ($sat < 90)   return 'background:#dcfce7;color:#166534';
    if ($sat <= 110) return 'background:#fef9c3;color:#854d0e';
    return 'background:#fee2e2;color:#991b1b;font-weight:800';
};
?>
<div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start">
  <div>
    <h1><i class="fa-solid fa-people-arrows"></i> Carico risorse e sovrapposizioni</h1>
    <p style="color:var(--muted);font-size:13px">Impegno delle persone per commessa nel tempo, con evidenza delle contemporaneità e dei sovraccarichi. Capacità: giorni feriali × <?=$wl->dailyHours()?> h.</p>
  </div>
  <div style="display:flex;gap:10px;align-items:center">
    <span style="color:var(--muted);font-size:12px"><strong><?=$scoped_projects?></strong> / <strong><?=$total_projects?></strong> commesse</span>
    <a class="btn btn-sm" href="<?=$qs(['export'=>'xlsx'])?>"><i class="fa-solid fa-file-export"></i> Esporta XLSX</a>
  </div>
</div>

<div class="card" style="margin-bottom:14px">
  <form method="get" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
      <?= route_slug_field() ?>
    <div class="form-group" style="margin:0"><label>Da (mese)</label><input type="month" name="from" value="<?=h($from)?>"></div>
    <div class="form-group" style="margin:0"><label>A (mese)</label><input type="month" name="to" value="<?=h($to)?>"></div>
    <div class="form-group" style="margin:0"><label>Commessa</label>
      <select name="proj"><option value="">tutte</option>
        <?php foreach($projects as $id=>$l):?><option value="<?=$id?>" <?=$f['project_id']==$id?'selected':''?>><?=h($l)?></option><?php endforeach;?></select></div>
    <div class="form-group" style="margin:0"><label>Linea di servizio</label>
      <select name="sl"><option value="">tutte</option>
        <?php foreach($service_lines as $sl):?><option value="<?=h($sl)?>" <?=$service_line===$sl?'selected':''?>><?=h($sl)?></option><?php endforeach;?></select></div>
    <div class="form-group" style="margin:0"><label>Società (dipendente)</label>
      <select name="company"><option value="">tutte</option>
        <?php foreach($companies as $id=>$n):?><option value="<?=(int)$id?>" <?=$f['company_id']===(int)$id?'selected':''?>><?=h($n)?></option><?php endforeach;?></select></div>
    <div class="form-group" style="margin:0"><label>Cliente</label>
      <select name="client"><option value="">tutti</option>
        <?php foreach($clients_wl as $id=>$n):?><option value="<?=(int)$id?>" <?=$f['client_id']===(int)$id?'selected':''?>><?=h($n)?></option><?php endforeach;?></select></div>
    <div class="form-group" style="margin:0"><label>Stato operativo</label>
      <select name="ostatus"><option value="">tutti</option>
        <?php foreach($op_statuses as $s):?><option value="<?=h($s)?>" <?=$f['operational_status']===$s?'selected':''?>><?=h($s)?></option><?php endforeach;?></select></div>
    <div class="form-group" style="margin:0"><label>Tipologia</label>
      <select name="ptype"><option value="">tutte</option>
        <?php foreach($proj_types as $t):?><option value="<?=h($t)?>" <?=$f['project_type']===$t?'selected':''?>><?=h($t)?></option><?php endforeach;?></select></div>
    <div class="form-group" style="margin:0"><label>Ordina risorse per</label>
      <select name="sort">
        <option value="hours_desc" <?=$sort==='hours_desc'?'selected':''?>>ore (decrescente)</option>
        <option value="hours_asc" <?=$sort==='hours_asc'?'selected':''?>>ore (crescente)</option>
        <option value="name" <?=$sort==='name'?'selected':''?>>nome (A→Z)</option>
      </select></div>
    <label style="display:flex;gap:6px;align-items:center;padding-bottom:6px"><input type="checkbox" name="ov" value="1" <?=$only_overload?'checked':''?>> solo sovraccarichi</label>
    <button class="btn">Applica</button>
  </form>
  <div style="margin-top:12px">
    <label style="font-size:12px;color:var(--muted);font-weight:600">Filtra per una o più risorse <span style="font-weight:400">(tieni premuto Ctrl/Cmd per selezione multipla; vuoto = tutte)</span></label>
    <form method="get" style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;margin-top:6px">
      <?= route_slug_field() ?>
      <input type="hidden" name="from" value="<?=h($from)?>"><input type="hidden" name="to" value="<?=h($to)?>">
      <input type="hidden" name="proj" value="<?=$f['project_id']?>"><input type="hidden" name="sort" value="<?=h($sort)?>"><input type="hidden" name="sl" value="<?=h($service_line)?>">
      <input type="hidden" name="company" value="<?=$f['company_id']?>"><input type="hidden" name="client" value="<?=$f['client_id']?>"><input type="hidden" name="ostatus" value="<?=h($f['operational_status'])?>"><input type="hidden" name="ptype" value="<?=h($f['project_type'])?>">
      <?php if($only_overload):?><input type="hidden" name="ov" value="1"><?php endif;?>
      <select name="emps[]" multiple size="6" style="min-width:280px;padding:6px">
        <?php foreach($employees as $id=>$l):?><option value="<?=$id?>" <?=in_array((int)$id,$emp_ids,true)?'selected':''?>><?=h($l)?></option><?php endforeach;?>
      </select>
      <div style="display:flex;flex-direction:column;gap:6px">
        <button class="btn btn-primary"><i class="fa-solid fa-filter"></i> Applica selezione</button>
        <?php if($emp_ids):?><a class="btn btn-sm" href="<?=$qs(['emps'=>null])?>">Azzera selezione</a><?php endif;?>
        <span style="font-size:11px;color:var(--muted)"><?=$emp_ids?count($emp_ids).' risorse selezionate':'tutte le risorse'?></span>
      </div>
    </form>
  </div>
</div>

<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-circle-info"></i> Come leggere i colori</span></div>
  <table class="data-table" style="width:100%;font-size:12px">
    <thead><tr><th>Colore</th><th>Saturazione</th><th>Significato</th></tr></thead>
    <tbody>
      <tr><td><span style="display:inline-block;width:60px;height:16px;border-radius:3px;background:#dbeafe"></span></td>
          <td><strong>&lt; 60%</strong></td><td>Sotto-utilizzo: la risorsa ha lavorato meno della metà-due terzi della capacità del mese. Margine ampio per nuove assegnazioni.</td></tr>
      <tr><td><span style="display:inline-block;width:60px;height:16px;border-radius:3px;background:#dcfce7"></span></td>
          <td><strong>60 – 90%</strong></td><td>Utilizzo ottimale: impegno pieno ma sostenibile, entro la capacità disponibile.</td></tr>
      <tr><td><span style="display:inline-block;width:60px;height:16px;border-radius:3px;background:#fef9c3"></span></td>
          <td><strong>90 – 110%</strong></td><td>Al limite: la risorsa è vicina o appena oltre la capacità teorica. Da monitorare, possibile straordinario.</td></tr>
      <tr><td><span style="display:inline-block;width:60px;height:16px;border-radius:3px;background:#fee2e2"></span></td>
          <td><strong>&gt; 110%</strong></td><td><strong>Sovraccarico</strong>: ore ben oltre la capacità del mese. Indice di conflitto di pianificazione, spesso dovuto a più commesse in parallelo.</td></tr>
      <tr><td style="text-align:center"><sup style="font-size:14px">⚠</sup></td>
          <td>—</td><td>La risorsa ha lavorato su <strong>2 o più commesse</strong> nello stesso mese (contemporaneità), a prescindere dalla saturazione.</td></tr>
    </tbody>
  </table>
  <p style="font-size:11px;color:var(--muted);margin-top:6px">La capacità mensile è calcolata come giorni feriali (lun-ven) × <?=$wl->dailyHours()?> ore. Le ore provengono dai rapporti di intervento con tecnico e commessa risolti.</p>
</div>

<!-- ══ 1. HEATMAP persona × mese ══ -->
<div class="card" style="margin-bottom:14px;overflow-x:auto">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-table-cells"></i> Impegno per risorsa e mese</span></div>
  <table class="data-table" style="width:100%;font-size:11px;white-space:nowrap">
    <thead><tr>
      <th style="position:sticky;left:0;background:#fff;text-align:left;min-width:160px">Risorsa</th>
      <?php foreach($months as $ym): ?><th style="text-align:center"><?=substr($ym,2)?><br><small style="font-weight:400;color:var(--muted)"><?=(int)Workload::workingDaysOfMonth($ym)?>gg</small></th><?php endforeach; ?>
      <th style="text-align:center">Tot</th>
    </tr></thead>
    <tbody>
    <?php if(!$matrix): ?><tr><td colspan="<?=count($months)+2?>" style="text-align:center;color:var(--muted);padding:18px">Nessun impegno nel periodo.</td></tr>
    <?php else: foreach($matrix as $eid=>$row): ?>
      <tr>
        <td style="position:sticky;left:0;background:#fff;font-weight:600"><?=h($row['nome'])?></td>
        <?php foreach($months as $ym):
          $cell = $row['months'][$ym] ?? null;
          $ore = $cell['ore'] ?? 0; $cap = $wl->monthlyCapacity($ym);
          $sat = $cap>0 && $ore>0 ? round($ore/$cap*100) : 0;
          $np = $cell['n_proj'] ?? 0;
        ?>
          <td style="text-align:center;<?=$satColor((float)$sat)?>">
            <?php if($ore>0): ?>
              <a href="<?=$qs(['d_emp'=>$eid,'d_ym'=>$ym])?>" style="color:inherit;text-decoration:none" title="<?=$ore?>h · <?=$sat?>% · <?=$np?> commesse">
                <?=round($ore)?><?php if($np>=2):?><sup title="<?=$np?> commesse in parallelo">⚠</sup><?php endif;?></a>
            <?php else: ?><span style="color:#e2e8f0">·</span><?php endif; ?>
          </td>
        <?php endforeach; ?>
        <td style="text-align:center;font-weight:700"><?=round($row['tot'])?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <p style="color:var(--muted);font-size:11px;margin-top:8px">
    Colore = saturazione: <span style="background:#dbeafe;padding:1px 5px;border-radius:3px">&lt;60%</span>
    <span style="background:#dcfce7;padding:1px 5px;border-radius:3px">60-90%</span>
    <span style="background:#fef9c3;padding:1px 5px;border-radius:3px">90-110%</span>
    <span style="background:#fee2e2;padding:1px 5px;border-radius:3px">&gt;110% (sovraccarico)</span>
    · <sup>⚠</sup> = più commesse nello stesso mese · clic sulla cella per il dettaglio.
  </p>
</div>

<?php if($d_emp && $d_ym && $d_projects): ?>
<div class="card" style="margin-bottom:14px;border:2px solid var(--p)">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-user-clock"></i>
    <?=h($employees[$d_emp] ?? '')?> — <?=h($d_ym)?> · commesse in parallelo</span></div>
  <table class="data-table" style="width:100%">
    <thead><tr><th>Commessa</th><th>Ore</th><th>Quota</th></tr></thead>
    <tbody>
    <?php $tot=array_sum(array_map(fn($p)=>$p['ore'],$d_projects)); foreach($d_projects as $pid=>$p): ?>
      <tr><td><a href="<?=url_safe('project_dashboard',['id'=>$pid])?>"><?=h($p['code'])?></a> — <?=h(mb_strimwidth($p['name'],0,50,'…'))?></td>
          <td style="text-align:right"><?=h($p['ore'])?></td>
          <td style="text-align:right"><?=$tot>0?round($p['ore']/$tot*100):0?>%</td></tr>
    <?php endforeach; ?>
      <tr style="background:#f8fafc;font-weight:700"><td>Totale mese</td><td style="text-align:right"><?=round($tot,2)?></td>
          <td style="text-align:right"><?php $cap=$wl->monthlyCapacity($d_ym); echo $cap>0?round($tot/$cap*100):0; ?>% cap.</td></tr>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php
// ══ GRAFICO SVG: carico mensile per risorsa (server-side, nessuna dipendenza JS) ══
$chartSeries = $chart['series'];
// se molte risorse, mostra le prime 12 per leggibilità (l'utente restringe con il multi-select)
$tooMany = count($chartSeries) > 12;
$plot = $tooMany ? array_slice($chartSeries, 0, 12) : $chartSeries;
$capMax = $capSeries ? max($capSeries) : 0;
$yMax = max($chart['peak'], $capMax, 1);
$yMax = ceil($yMax / 20) * 20;
$W = 900; $H = 340; $padL = 48; $padR = 16; $padT = 16; $padB = 46;
$plotW = $W - $padL - $padR; $plotH = $H - $padT - $padB;
$nM = max(1, count($months));
$xAt = fn($i) => $padL + ($nM === 1 ? $plotW / 2 : $i / ($nM - 1) * $plotW);
$yAt = fn($v) => $padT + $plotH - ($v / $yMax) * $plotH;
$palette = ['#2563eb','#16a34a','#dc2626','#d97706','#7c3aed','#0891b2','#db2777','#65a30d','#ea580c','#0d9488','#9333ea','#4f46e5'];

// ── v1.8.81 — serie delle assenze, sugli stessi mesi del grafico ────────────
//
// Palette deliberatamente separata da quella delle risorse: le assenze non sono
// un'altra risorsa, sono una grandezza di natura diversa che condivide solo
// l'asse dei tempi. Colori vicini avrebbero suggerito che si confrontino.
//
// Le VISITE sono trasversali: le loro ore sono gia' dentro ferie, permessi o
// recuperi, perche' chi registra una visita medica sceglie il tipo in modo non
// uniforme. La serie e' quindi un evidenziatore su un sottoinsieme, non una
// categoria aggiuntiva, ed e' tratteggiata per distinguerla.
$absSeries = [];
$absCfg = [
    'ferie'    => ['label' => 'Ferie',        'color' => '#8b5cf6', 'dash' => false],
    'permessi' => ['label' => 'Permessi',     'color' => '#0ea5e9', 'dash' => false],
    'recuperi' => ['label' => 'Recupero ore', 'color' => '#f59e0b', 'dash' => false],
    'visite'   => ['label' => 'Visite',       'color' => '#ec4899', 'dash' => true],
];
try {
    $stA = $pdo->prepare(
        "SELECT DATE_FORMAT(`giorno`, '%Y-%m') AS ym,
                ROUND(SUM(`ferie`),2) AS ferie, ROUND(SUM(`permessi`),2) AS permessi,
                ROUND(SUM(`recuperi`),2) AS recuperi, ROUND(SUM(`visite`),2) AS visite
           FROM `v_cm_assenze_serie_giorno`
          WHERE DATE_FORMAT(`giorno`, '%Y-%m') BETWEEN ? AND ?
          GROUP BY ym");
    $stA->execute([$months[0] ?? '', end($months) ?: '']);
    while (($r = $stA->fetch(PDO::FETCH_ASSOC)) !== false) {
        foreach (array_keys($absCfg) as $k) $absSeries[$k][$r['ym']] = (float)$r[$k];
    }
    $stA->closeCursor();
} catch (Throwable $e) { $absSeries = []; }

// l'asse Y deve contenere anche le assenze, altrimenti le serie escono dal
// grafico o schiacciano quelle del carico
foreach ($absSeries as $serie) foreach ($serie as $v) if ($v > $yMax) $yMax = $v;
$yAt = fn($v) => $padT + $plotH - ($yMax > 0 ? ($v / $yMax) * $plotH : 0);
?>
<div class="card" style="margin-bottom:14px;overflow-x:auto">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
    <span class="card-title"><i class="fa-solid fa-chart-line"></i> Andamento del carico per risorsa</span>
    <span style="font-size:11px;color:var(--muted)"><?=$emp_ids?count($emp_ids).' risorse selezionate':($tooMany?'prime 12 risorse — usa il filtro per selezionarne un gruppo':count($plot).' risorse')?></span>
  </div>
<?php if(!$plot || $nM < 1): ?>
  <p style="text-align:center;color:var(--muted);padding:18px">Nessun dato da rappresentare.</p>
<?php else: ?>
  <svg viewBox="0 0 <?=$W?> <?=$H?>" style="width:100%;min-width:640px;height:auto;font-family:inherit">
    <!-- griglia orizzontale + asse Y -->
    <?php for($g=0;$g<=4;$g++): $val=$yMax*$g/4; $yy=$yAt($val); ?>
      <line x1="<?=$padL?>" y1="<?=round($yy,1)?>" x2="<?=$W-$padR?>" y2="<?=round($yy,1)?>" stroke="#e2e8f0" stroke-width="1"/>
      <text x="<?=$padL-6?>" y="<?=round($yy+3,1)?>" text-anchor="end" font-size="10" fill="#94a3b8"><?=round($val)?></text>
    <?php endfor; ?>
    <!-- etichette mesi -->
    <?php foreach($months as $i=>$ym): $xx=$xAt($i); ?>
      <text x="<?=round($xx,1)?>" y="<?=$H-$padB+16?>" text-anchor="middle" font-size="9" fill="#64748b" transform="rotate(-30 <?=round($xx,1)?> <?=$H-$padB+16?>)"><?=substr($ym,2)?></text>
    <?php endforeach; ?>
    <!-- linea capacità (tratteggiata) -->
    <?php
      $capPts = [];
      foreach($months as $i=>$ym){ $capPts[] = round($xAt($i),1).','.round($yAt($capSeries[$ym] ?? 0),1); }
    ?>
    <polyline fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-dasharray="5 4" points="<?=implode(' ',$capPts)?>"/>
    <text x="<?=$W-$padR?>" y="<?=round($yAt($capSeries[end($months)] ?? 0)-5,1)?>" text-anchor="end" font-size="9" fill="#64748b">capacità</text>
    <!-- una polilinea per risorsa -->
    <?php foreach($plot as $k=>$s): $col=$palette[$k % count($palette)]; $pts=[];
      foreach($months as $i=>$ym){ $pts[] = round($xAt($i),1).','.round($yAt($s['points'][$ym] ?? 0),1); } ?>
      <polyline fill="none" stroke="<?=$col?>" stroke-width="2" points="<?=implode(' ',$pts)?>"/>
      <?php foreach($months as $i=>$ym): $v=$s['points'][$ym] ?? 0; if($v<=0) continue; ?>
        <circle cx="<?=round($xAt($i),1)?>" cy="<?=round($yAt($v),1)?>" r="2.5" fill="<?=$col?>"><title><?=h($s['nome'])?> · <?=$ym?>: <?=$v?> h</title></circle>
      <?php endforeach; ?>
    <?php endforeach; ?>

    <?php // v1.8.81 — serie delle assenze, in gruppi commutabili dalla legenda ?>
    <?php foreach ($absCfg as $key => $cfg):
      if (empty($absSeries[$key])) continue;
      $tot = array_sum($absSeries[$key]); if ($tot <= 0) continue;
      $pts = [];
      foreach ($months as $i => $ym) $pts[] = round($xAt($i),1) . ',' . round($yAt($absSeries[$key][$ym] ?? 0),1); ?>
      <g class="abs-serie" data-serie="<?=h($key)?>">
        <polyline fill="none" stroke="<?=$cfg['color']?>" stroke-width="2.5"
                  <?= $cfg['dash'] ? 'stroke-dasharray="6 3"' : '' ?>
                  points="<?=implode(' ', $pts)?>"/>
        <?php foreach ($months as $i => $ym): $v = $absSeries[$key][$ym] ?? 0; if ($v <= 0) continue; ?>
          <circle cx="<?=round($xAt($i),1)?>" cy="<?=round($yAt($v),1)?>" r="3" fill="<?=$cfg['color']?>">
            <title><?=h($cfg['label'])?> · <?=$ym?>: <?=number_format($v,1,',','.')?> h</title></circle>
        <?php endforeach; ?>
      </g>
    <?php endforeach; ?>
  </svg>

  <?php // legenda commutabile delle assenze ?>
  <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;padding-top:10px;border-top:1px solid #e2e8f0">
    <span style="font-size:11px;font-weight:700;color:var(--muted);align-self:center">ASSENZE:</span>
    <?php foreach ($absCfg as $key => $cfg):
      if (empty($absSeries[$key])) continue;
      $tot = array_sum($absSeries[$key]); if ($tot <= 0) continue; ?>
      <button type="button" class="abs-toggle" data-serie="<?=h($key)?>"
              style="display:inline-flex;align-items:center;gap:6px;font-size:11px;cursor:pointer;
                     border:1px solid <?=$cfg['color']?>;background:#fff;color:#334155;
                     border-radius:14px;padding:3px 10px">
        <span style="width:14px;height:3px;background:<?=$cfg['color']?>;display:inline-block;
                     <?= $cfg['dash'] ? 'border-top:3px dotted ' . $cfg['color'] . ';background:none;height:0' : '' ?>"></span>
        <?=h($cfg['label'])?>
        <strong><?=number_format($tot, 0, ',', '.')?> h</strong>
      </button>
    <?php endforeach; ?>
    <span style="font-size:10px;color:var(--muted);align-self:center;margin-left:auto">
      Cliccare per mostrare o nascondere. <strong>Visite</strong> è trasversale:
      le sue ore sono già comprese in ferie, permessi o recuperi.
    </span>
  </div>
  <script>
  (function () {
      // la commutazione agisce sul gruppo SVG, non ridisegna il grafico: le
      // serie restano nel documento e cambia solo la visibilita', cosi' lo stato
      // e' reversibile senza una nuova richiesta al server
      document.querySelectorAll('.abs-toggle').forEach(function (b) {
          b.addEventListener('click', function () {
              var k = b.getAttribute('data-serie');
              var g = document.querySelector('.abs-serie[data-serie="' + k + '"]');
              if (!g) return;
              var nascosto = g.style.display === 'none';
              g.style.display = nascosto ? '' : 'none';
              b.style.opacity = nascosto ? '1' : '0.35';
              b.style.background = nascosto ? '#fff' : '#f1f5f9';
          });
      });
  })();
  </script>

  <!-- legenda risorse -->
  <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:10px">
    <?php foreach($plot as $k=>$s): $col=$palette[$k % count($palette)]; ?>
      <span style="font-size:11px;display:inline-flex;align-items:center;gap:5px">
        <span style="width:14px;height:3px;background:<?=$col?>;display:inline-block;border-radius:2px"></span><?=h($s['nome'])?> <span style="color:var(--muted)">(<?=round($s['tot'])?>h)</span></span>
    <?php endforeach; ?>
    <span style="font-size:11px;display:inline-flex;align-items:center;gap:5px;color:#64748b">
      <span style="width:14px;height:0;border-top:2px dashed #94a3b8;display:inline-block"></span>capacità mensile</span>
  </div>
  <?php if($tooMany && !$emp_ids): ?><p style="font-size:11px;color:#d97706;margin-top:8px"><i class="fa-solid fa-circle-info"></i> Sono rappresentate le prime 12 risorse per monte ore. Seleziona un gruppo di risorse nel filtro sopra per un grafico mirato.</p><?php endif; ?>
<?php endif; ?>
</div>

<?php
// ══ GRAFICO SVG GIORNALIERO: andamento del carico per risorsa nel mese di dettaglio (v1.8.2) ══
$dSeries  = $dailyChart['series'];
$dTooMany = count($dSeries) > 12;
$dPlot    = $dTooMany ? array_slice($dSeries, 0, 12) : $dSeries;
$dCapH    = $wl->dailyHours();
$dPeak    = max($dailyChart['peak'], $dCapH, 1);
$dYMax    = max(4, (int)(ceil($dPeak / 4) * 4));
$nD       = max(1, count($days));
$dW = 900; $dH = 320; $dPadL = 40; $dPadR = 16; $dPadT = 14; $dPadB = 40;
$dPlotW = $dW - $dPadL - $dPadR; $dPlotH = $dH - $dPadT - $dPadB;
$dxAt = fn($i) => $dPadL + ($nD === 1 ? $dPlotW / 2 : $i / ($nD - 1) * $dPlotW);
$dyAt = fn($v) => $dPadT + $dPlotH - ($v / $dYMax) * $dPlotH;
$mesi_lbl = (function($ym){ if(!$ym) return ''; $M=['','gennaio','febbraio','marzo','aprile','maggio','giugno','luglio','agosto','settembre','ottobre','novembre','dicembre']; [$y,$mn]=array_map('intval',explode('-',$ym)); return $M[$mn].' '.$y; })($dm);
?>
<div class="card" style="margin-bottom:14px;overflow-x:auto">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <span class="card-title"><i class="fa-solid fa-calendar-day"></i> Andamento giornaliero del carico per risorsa <small style="color:var(--muted)">— <?=h($mesi_lbl)?></small></span>
    <form method="get" style="display:flex;gap:6px;align-items:flex-end;margin:0">
      <?= route_slug_field() ?>
      <input type="hidden" name="from" value="<?=h($from)?>"><input type="hidden" name="to" value="<?=h($to)?>">
      <input type="hidden" name="proj" value="<?=$f['project_id']?>"><input type="hidden" name="sl" value="<?=h($service_line)?>">
      <input type="hidden" name="company" value="<?=$f['company_id']?>"><input type="hidden" name="client" value="<?=$f['client_id']?>"><input type="hidden" name="ostatus" value="<?=h($f['operational_status'])?>"><input type="hidden" name="ptype" value="<?=h($f['project_type'])?>">
      <input type="hidden" name="sort" value="<?=h($sort)?>"><?php if($only_overload):?><input type="hidden" name="ov" value="1"><?php endif;?>
      <?php foreach($emp_ids as $eid):?><input type="hidden" name="emps[]" value="<?=$eid?>"><?php endforeach;?>
      <div class="form-group" style="margin:0"><label style="font-size:11px">Mese di dettaglio</label>
        <select name="dm" onchange="this.form.submit()" style="font-weight:700">
          <?php foreach($months as $ym): ?><option value="<?=$ym?>" <?=$ym===$dm?'selected':''?>><?=$ym?></option><?php endforeach; ?>
        </select></div>
      <noscript><button class="btn btn-sm">Vai</button></noscript>
    </form>
  </div>
<?php if(!$dPlot || $nD < 1): ?>
  <p style="text-align:center;color:var(--muted);padding:18px">Nessun impegno giornaliero nel mese selezionato.</p>
<?php else: ?>
  <svg viewBox="0 0 <?=$dW?> <?=$dH?>" style="width:100%;min-width:680px;height:auto;font-family:inherit">
    <!-- bande weekend -->
    <?php foreach($days as $i=>$d): if(Workload::isWorkingDay($d)) continue;
      $cx=$dxAt($i); $half=($nD>1?($dPlotW/($nD-1))/2:6); $x0=max($dPadL,$cx-$half); $x1=min($dW-$dPadR,$cx+$half); ?>
      <rect x="<?=round($x0,1)?>" y="<?=$dPadT?>" width="<?=round($x1-$x0,1)?>" height="<?=$dPlotH?>" fill="#f1f5f9"/>
    <?php endforeach; ?>
    <!-- griglia orizzontale + asse Y -->
    <?php for($g=0;$g<=4;$g++): $val=$dYMax*$g/4; $yy=$dyAt($val); ?>
      <line x1="<?=$dPadL?>" y1="<?=round($yy,1)?>" x2="<?=$dW-$dPadR?>" y2="<?=round($yy,1)?>" stroke="#e2e8f0" stroke-width="1"/>
      <text x="<?=$dPadL-6?>" y="<?=round($yy+3,1)?>" text-anchor="end" font-size="10" fill="#94a3b8"><?=round($val)?></text>
    <?php endfor; ?>
    <!-- etichette giorni (numero del mese) -->
    <?php foreach($days as $i=>$d): $xx=$dxAt($i); $dow=(int)date('N',strtotime($d)); ?>
      <text x="<?=round($xx,1)?>" y="<?=$dH-$dPadB+15?>" text-anchor="middle" font-size="8" fill="<?=$dow<=5?'#64748b':'#cbd5e1'?>"><?=(int)substr($d,8,2)?></text>
    <?php endforeach; ?>
    <!-- linea capacità giornaliera (ore/giorno feriale) -->
    <line x1="<?=$dPadL?>" y1="<?=round($dyAt($dCapH),1)?>" x2="<?=$dW-$dPadR?>" y2="<?=round($dyAt($dCapH),1)?>" stroke="#94a3b8" stroke-width="1.5" stroke-dasharray="5 4"/>
    <text x="<?=$dW-$dPadR?>" y="<?=round($dyAt($dCapH)-4,1)?>" text-anchor="end" font-size="9" fill="#64748b">capacità <?=round($dCapH)?>h/gg</text>
    <!-- una polilinea per risorsa -->
    <?php foreach($dPlot as $k=>$s): $col=$palette[$k % count($palette)]; $pts=[];
      foreach($days as $i=>$d){ $pts[] = round($dxAt($i),1).','.round($dyAt($s['points'][$d] ?? 0),1); } ?>
      <polyline fill="none" stroke="<?=$col?>" stroke-width="1.8" points="<?=implode(' ',$pts)?>"/>
      <?php foreach($days as $i=>$d): $v=$s['points'][$d] ?? 0; if($v<=0) continue;
        $np=$dailyMatrix[$s['eid']]['days'][$d]['n_proj'] ?? 0; ?>
        <circle cx="<?=round($dxAt($i),1)?>" cy="<?=round($dyAt($v),1)?>" r="2.4" fill="<?=$col?>"><title><?=h($s['nome'])?> · <?=$d?>: <?=$v?> h<?=$np>=2?" · $np commesse":''?></title></circle>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </svg>
  <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:10px">
    <?php foreach($dPlot as $k=>$s): $col=$palette[$k % count($palette)]; ?>
      <span style="font-size:11px;display:inline-flex;align-items:center;gap:5px">
        <span style="width:14px;height:3px;background:<?=$col?>;display:inline-block;border-radius:2px"></span><?=h($s['nome'])?> <span style="color:var(--muted)">(<?=round($s['tot'])?>h)</span></span>
    <?php endforeach; ?>
    <span style="font-size:11px;display:inline-flex;align-items:center;gap:5px;color:#64748b">
      <span style="width:14px;height:0;border-top:2px dashed #94a3b8;display:inline-block"></span>capacità <?=round($dCapH)?>h/giorno</span>
    <span style="font-size:11px;display:inline-flex;align-items:center;gap:5px;color:#64748b">
      <span style="width:14px;height:12px;background:#f1f5f9;display:inline-block;border-radius:2px"></span>weekend</span>
  </div>
  <?php if($dTooMany && !$emp_ids): ?><p style="font-size:11px;color:#d97706;margin-top:8px"><i class="fa-solid fa-circle-info"></i> Grafico giornaliero limitato alle prime 12 risorse per monte ore del mese. Seleziona un gruppo di risorse per un dettaglio mirato.</p><?php endif; ?>
  <p style="font-size:11px;color:var(--muted);margin-top:6px">Ore consuntivate per giorno dai rapporti di intervento del mese <strong><?=h($mesi_lbl)?></strong>. La linea tratteggiata è la capacità giornaliera (<?=round($dCapH)?> h/giorno feriale); le bande grigie sono i weekend. Cambia il mese di dettaglio dal selettore in alto a destra.</p>
<?php endif; ?>
</div>

<!-- ══ 2. Conflitti per persona ══ -->
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-triangle-exclamation"></i> Conflitti per risorsa <small style="color:var(--muted)">(più commesse nello stesso mese e/o oltre capacità)</small></span></div>
  <table class="data-table" style="width:100%;font-size:12px">
    <thead><tr><th>Risorsa</th><th>Mese</th><th>Ore</th><th>Capacità</th><th>Sat.</th><th>Commesse in parallelo</th></tr></thead>
    <tbody>
    <?php if(!$overlaps): ?><tr><td colspan="6" style="text-align:center;color:#16a34a;padding:14px"><i class="fa-solid fa-check"></i> Nessun conflitto nel periodo.</td></tr>
    <?php else: foreach(array_slice($overlaps,0,150) as $o): ?>
      <tr <?=$o['overload']?'style="background:#fef2f2"':''?>>
        <td style="font-weight:600"><?=h($o['nome'])?></td>
        <td><?=h($o['ym'])?></td>
        <td style="text-align:right"><?=$o['ore']?></td>
        <td style="text-align:right;color:var(--muted)"><?=$o['capacity']?></td>
        <td style="text-align:right;font-weight:700;color:<?=$o['overload']?'#dc2626':($o['sat']>=90?'#d97706':'#16a34a')?>"><?=$o['sat']?>%<?=$o['overload']?' ⚠':''?></td>
        <td><?php foreach($o['projects'] as $pid=>$p): ?><a href="<?=url_safe('project_dashboard',['id'=>$pid])?>" style="text-decoration:none"><code><?=h($p['code'])?></code></a> <small style="color:var(--muted)"><?=round($p['ore'])?>h</small>&nbsp; <?php endforeach; ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <?php if(count($overlaps)>150): ?><p style="color:var(--muted);font-size:11px;margin-top:6px">Mostrati i primi 150 conflitti (ordinati per gravità). Usa i filtri per restringere.</p><?php endif; ?>
</div>

<!-- ══ 3. Sovrapposizioni tra commesse ══ -->
<div class="card">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-code-compare"></i> Sovrapposizioni tra commesse <small style="color:var(--muted)">(condividono risorse negli stessi mesi, nel periodo filtrato)</small></span></div>
  <table class="data-table" style="width:100%;font-size:12px">
    <thead><tr>
      <th>Commessa A</th><th>Commessa B</th><th>Fascia di sovrapposizione</th><th>Mesi</th>
      <th>Risorse condivise</th><th>Ore A</th><th>Ore B</th><th>Ore totali</th>
    </tr></thead>
    <tbody>
    <?php if(!$projOv): ?><tr><td colspan="8" style="text-align:center;color:#16a34a;padding:14px"><i class="fa-solid fa-check"></i> Nessuna sovrapposizione tra commesse nel periodo.</td></tr>
    <?php else: foreach($projOv as $po):
      $mesi_it = fn($ym) => (function($ym){ $M=['','gen','feb','mar','apr','mag','giu','lug','ago','set','ott','nov','dic']; [$y,$mn]=array_map('intval',explode('-',$ym)); return $M[$mn].' '.$y; })($ym);
      $fascia = $po['first_month']===$po['last_month'] ? $mesi_it($po['first_month']) : $mesi_it($po['first_month']).' → '.$mesi_it($po['last_month']);
    ?>
      <tr>
        <td><a href="<?=url_safe('project_dashboard',['id'=>$po['a']['pid']])?>"><code><?=h($po['a']['code'])?></code></a> <small style="color:var(--muted)"><?=h(mb_strimwidth($po['a']['name'],0,26,'…'))?></small></td>
        <td><a href="<?=url_safe('project_dashboard',['id'=>$po['b']['pid']])?>"><code><?=h($po['b']['code'])?></code></a> <small style="color:var(--muted)"><?=h(mb_strimwidth($po['b']['name'],0,26,'…'))?></small></td>
        <td style="white-space:nowrap"><strong><?=h($fascia)?></strong></td>
        <td style="text-align:center"><?=$po['n_months']?></td>
        <td><strong><?=$po['n_people']?></strong> <small style="color:var(--muted)"><?=h(implode(', ', array_slice(array_values($po['people']),0,4)))?><?=$po['n_people']>4?' +'.($po['n_people']-4):''?></small></td>
        <td style="text-align:right"><?=$po['hours_a']?></td>
        <td style="text-align:right"><?=$po['hours_b']?></td>
        <td style="text-align:right;font-weight:700"><?=$po['shared_hours']?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <p style="color:var(--muted);font-size:11px;margin-top:6px">La fascia indica il primo e l'ultimo mese in cui le due commesse hanno impegnato le stesse persone, entro il periodo selezionato. "Ore A"/"Ore B" sono le ore consuntivate su ciascuna commessa nei mesi di sovrapposizione dalle risorse condivise.</p>
</div>
<?php require_once('footer.php'); ?>
