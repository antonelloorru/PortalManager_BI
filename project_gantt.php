<?php
/**
 * project_gantt.php — Gantt di portfolio delle commesse (v1.7.69)
 *
 * Una barra per commessa: PIANIFICATO (start_date → end_date) confrontato con
 * l'EFFETTIVO ricavato dai rapporti di intervento (primo → ultimo rapporto).
 * Il Gantt di dettaglio, con le fasi, è nella scheda commessa (tab Gantt).
 *
 * Rendering HTML/CSS puro: nessuna dipendenza esterna, stampabile.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/Gantt.php');

if (!can('view', 'project_gantt.php')) { redirect('manage_projects'); }
$g = new Gantt($pdo);

$f = [
    'status'     => trim($_GET['status'] ?? ''),
    'company_id' => (int)($_GET['company'] ?? 0),
    'q'          => trim($_GET['q'] ?? ''),
];
$rows = $g->portfolio($f, 200);

$dates = [];
foreach ($rows as $r) { $dates[] = $r['start_date']; $dates[] = $r['end_date']; $dates[] = $r['act_dal']; $dates[] = $r['act_al']; }
[$min, $max] = Gantt::scale($dates);
$ticks = Gantt::ticks($min, $max);

$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$statuses  = $pdo->query("SELECT DISTINCT operational_status FROM cm_projects WHERE operational_status IS NOT NULL AND operational_status<>'' ORDER BY operational_status")->fetchAll(PDO::FETCH_COLUMN);
// v1.8.40: indicatore standard "N commesse (filtrate) / totale" coerente con manage_projects.php
$total_projects = (int)$pdo->query("SELECT COUNT(*) FROM cm_projects")->fetchColumn();

require_once('header.php');
$today = Gantt::bar(date('Y-m-d'), date('Y-m-d'), $min, $max);
?>
<div class="page-header">
  <h1><i class="fa-solid fa-chart-gantt"></i> Gantt commesse</h1>
  <p style="color:var(--muted);font-size:13px">Pianificato (barra chiara) confrontato con l'effettivo dai rapporti di intervento (barra piena). Clic sul codice per la scheda commessa.</p>
</div>

<div class="card" style="margin-bottom:12px">
  <form method="get" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
    <?= route_slug_field() ?>
    <div class="form-group" style="margin:0"><label>Cerca</label>
      <input type="text" name="q" value="<?=h($f['q'])?>" placeholder="codice o nome commessa" style="width:220px"></div>
    <div class="form-group" style="margin:0"><label>Stato operativo</label>
      <select name="status"><option value="">tutti</option>
        <?php foreach($statuses as $s):?><option value="<?=h($s)?>" <?=$f['status']===$s?'selected':''?>><?=h($s)?></option><?php endforeach;?></select></div>
    <div class="form-group" style="margin:0"><label>Azienda esecutrice</label>
      <select name="company"><option value="">tutte</option>
        <?php foreach($companies as $id=>$n):?><option value="<?=$id?>" <?=$f['company_id']==$id?'selected':''?>><?=h($n)?></option><?php endforeach;?></select></div>
    <button class="btn">Filtra</button>
    <span style="color:var(--muted);font-size:12px;align-self:center"><strong><?=count($rows)?></strong> / <strong><?=$total_projects?></strong> commesse<?=count($rows)>=200?' (prime 200)':''?></span>
  </form>
</div>

<?php
$trackMin = Gantt::timelineMinWidth($min, $max);
$ticks    = Gantt::ticks($min, $max, Gantt::tickBudget($trackMin));
$grid     = Gantt::monthGridlines($min, $max);
echo Gantt::css();
?>
<div class="card">
<?php if(!$rows): ?>
  <p style="text-align:center;color:var(--muted);padding:18px">Nessuna commessa per i filtri impostati.</p>
<?php else: ?>
  <div class="pm-gantt" style="--label:260px">
    <div class="g-legend">
      <span class="k"><span class="sw" style="background:#c7d2fe"></span> pianificato</span>
      <span class="k"><span class="sw" style="background:#2563eb"></span> effettivo (rapporti)</span>
      <span class="k"><span class="sw" style="background:#dc2626"></span> effettivo oltre la fine pianificata</span>
      <span class="k"><span class="sw" style="width:2px;background:#dc2626"></span> oggi</span>
    </div>
    <div class="g-scroll">
      <div class="g-inner" style="min-width:calc(260px + <?=$trackMin?>px)">
        <div class="g-gridlayer">
          <?php foreach($grid as $gx): ?><div class="g-grid" style="left:<?=$gx?>%"></div><?php endforeach; ?>
          <?php if($today): ?><div class="g-today" style="left:<?=$today['left']?>%"></div><?php endif; ?>
        </div>
        <!-- testata timeline -->
        <div class="g-row g-head">
          <div class="g-label" style="font-weight:700">Commessa</div>
          <div class="g-track">
            <?php foreach($ticks as $t): ?><div class="g-tick" style="left:<?=$t['left']?>%"><?=h($t['label'])?></div><?php endforeach; ?>
          </div>
        </div>
        <!-- righe commessa -->
        <?php foreach($rows as $r):
          $plan = Gantt::bar($r['start_date'] ?? null, $r['end_date'] ?? null, $min, $max);
          $act  = Gantt::bar($r['act_dal'] ?? null, $r['act_al'] ?? null, $min, $max);
          $late = ($r['end_date'] && $r['act_al'] && strtotime($r['act_al']) > strtotime($r['end_date']));
        ?>
        <div class="g-row">
          <div class="g-label">
            <a class="code" href="<?=url_safe('project_dashboard',['id'=>(int)$r['id']])?>"><?=h($r['project_code'])?></a>
            <div class="sub"><?=h(mb_strimwidth((string)$r['name'],0,44,'…'))?><?=$r['client_name']?' · '.h(mb_strimwidth($r['client_name'],0,24,'…')):''?></div>
            <div class="sub"><?=$r['act_ore']!==null?'<strong>'.h($r['act_ore']).' h</strong> · '.(int)$r['act_n'].' rapp.':'—'?></div>
          </div>
          <div class="g-track" style="min-height:44px">
            <?php if($plan): ?><div class="g-bar g-plan" title="Pianificato: <?=h($r['start_date'] ?? '—')?> → <?=h($r['end_date'] ?? '—')?>" style="left:<?=$plan['left']?>%;width:<?=$plan['width']?>%;top:8px;height:13px"></div><?php endif; ?>
            <?php if($act): ?><div class="g-bar g-act<?=$late?' late':''?>" title="Effettivo: <?=h($r['act_dal'])?> → <?=h($r['act_al'])?> (<?=h($r['act_ore'])?> h)" style="left:<?=$act['left']?>%;width:<?=$act['width']?>%;top:24px;height:13px"></div><?php endif; ?>
            <?php if(!$plan && !$act): ?><span class="g-empty">nessuna data pianificata né rapporti</span><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <p style="color:var(--muted);font-size:11px;margin-top:10px">Ogni riga confronta la barra <strong>pianificata</strong> (sopra) con l'<strong>effettiva</strong> dai rapporti (sotto); quest'ultima diventa rossa se l'ultimo rapporto supera la fine pianificata. La timeline scorre orizzontalmente per restare leggibile su periodi lunghi.</p>
  </div>
<?php endif; ?>
</div>
<?php require_once('footer.php'); ?>
