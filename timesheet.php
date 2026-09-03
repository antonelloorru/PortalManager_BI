<?php
/**
 * timesheet.php — Timesheet risorse (v1.7.69)
 *
 * Griglia dipendente × giorno del mese. Somma le ore consuntivate dai rapporti di
 * intervento (sola lettura: la fonte di verità resta l'import) e le voci manuali
 * per le attività che non passano dai rapporti (ferie, formazione, trasferte...).
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/Timesheet.php');
require_once(__DIR__ . '/app/RecycleBin.php');

if (!can('view', 'timesheet.php')) { redirect('dashboard'); }
$can_edit = can('edit', 'timesheet.php') || can('create', 'timesheet.php');
$u_id = (int)$_SESSION['user_id'];
$ts   = new Timesheet($pdo);

$year  = (int)($_GET['y'] ?? date('Y'));
$month = (int)($_GET['m'] ?? date('n'));
if ($month < 1 || $month > 12) $month = (int)date('n');
if ($year < 2000 || $year > 2100) $year = (int)date('Y');
$f = ['project_id' => (int)($_GET['proj'] ?? 0), 'employee_id' => (int)($_GET['emp'] ?? 0)];
$all_emp = ($_GET['all'] ?? '') === '1';

// ─────────────── POST (PRG, prima di header.php) ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    if (!$can_edit) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect_self(); }
    $act = $_POST['action'] ?? '';
    $back = ['y' => (int)($_POST['y'] ?? $year), 'm' => (int)($_POST['m'] ?? $month)];
    if (!empty($_POST['proj'])) $back['proj'] = (int)$_POST['proj'];
    if (!empty($_POST['emp']))  $back['emp']  = (int)$_POST['emp'];

    if ($act === 'add_entry') {
        $eid  = (int)($_POST['employee_id'] ?? 0);
        $date = trim($_POST['work_date'] ?? '');
        $h    = (float)str_replace(',', '.', (string)($_POST['hours'] ?? '0'));
        if ($eid && $date && $h > 0) {
            $ts->addEntry($eid, $date, $h, (string)($_POST['activity_type'] ?? 'Altro'),
                          (int)($_POST['project_id'] ?? 0) ?: null, trim($_POST['notes'] ?? ''), $u_id);
            write_log('Timesheet','success',"Voce timesheet: dip #$eid, $date, {$h}h",$u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Voce aggiunta.</div>";
        } else {
            $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Dipendente, data e ore (&gt; 0) sono obbligatori.</div>";
        }
        redirect('timesheet', $back);
    }

    if ($act === 'del_entry') {
        (new RecycleBin($pdo))->softDelete('cm_timesheet_entries', 'id=?', [(int)($_POST['entry_id'] ?? 0)], null, $u_id, 'timesheet.php');
        write_log('Timesheet','success','Voce timesheet eliminata',$u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>Voce eliminata.</div>";
        redirect('timesheet', $back);
    }
    redirect('timesheet', $back);
}

// ─────────────── Export XLSX ───────────────
if (($_GET['export'] ?? '') === 'xlsx') {
    require_once(__DIR__ . '/XlsxWriter.php');
    $emps = $ts->employees($year, $month, $f, $all_emp);
    $rep  = $ts->reportHours($year, $month, $f);
    $man  = $ts->manualHours($year, $month, $f);
    $days = (int)date('t', mktime(0, 0, 0, $month, 1, $year));

    $head = ['Dipendente'];
    for ($d = 1; $d <= $days; $d++) $head[] = $d . ' ' . ['','lun','mar','mer','gio','ven','sab','dom'][(int)date('N', mktime(0,0,0,$month,$d,$year))];
    $head[] = 'Totale'; $head[] = 'Da rapporti'; $head[] = 'Manuali'; $head[] = 'Ore attese'; $head[] = 'Saturazione %';
    $rows = [$head];
    $attese = $ts->workingDays($year, $month) * $ts->dailyHours();
    foreach ($emps as $e) {
        $eid = (int)$e['id']; $r = [$e['nome']]; $tr = 0; $tm = 0;
        for ($d = 1; $d <= $days; $d++) {
            $a = $rep[$eid][$d]['ore'] ?? 0; $b = $man[$eid][$d]['ore'] ?? 0;
            $tr += $a; $tm += $b;
            $r[] = ($a + $b) > 0 ? round($a + $b, 2) : '';
        }
        $r[] = round($tr + $tm, 2); $r[] = round($tr, 2); $r[] = round($tm, 2); $r[] = $attese;
        $r[] = $attese > 0 ? round(($tr + $tm) / $attese * 100, 1) : 0;
        $rows[] = $r;
    }
    $w = new XlsxWriter();
    $w->addSheet('Timesheet ' . sprintf('%04d-%02d', $year, $month), $rows);
    write_log('Timesheet','info',"Export timesheet $year-$month",$u_id);
    $w->download(sprintf('timesheet_%04d_%02d.xlsx', $year, $month));
    exit;
}

// ─────────────── Vista ───────────────
$emps = $ts->employees($year, $month, $f, $all_emp);
$rep  = $ts->reportHours($year, $month, $f);
$man  = $ts->manualHours($year, $month, $f);
$days = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
$attese = $ts->workingDays($year, $month) * $ts->dailyHours();

$projects  = $pdo->query("SELECT id, CONCAT(project_code,' — ',name) l FROM cm_projects ORDER BY project_code")->fetchAll(PDO::FETCH_KEY_PAIR);
$employees = $pdo->query("SELECT id, CONCAT(last_name,' ',first_name) l FROM employees ORDER BY last_name, first_name")->fetchAll(PDO::FETCH_KEY_PAIR);

$detail_emp  = (int)($_GET['d_emp'] ?? 0);
$detail_date = trim($_GET['d_date'] ?? '');
$d_entries = $d_reports = [];
if ($detail_emp && $detail_date) {
    $d_entries = $ts->entriesOfDay($detail_emp, $detail_date);
    $d_reports = $ts->reportsOfDay($detail_emp, $detail_date);
}

$msg = '';
if (!empty($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
require_once('header.php');

$mesi = [1=>'Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
$qs = function(array $over = []) use ($year,$month,$f,$all_emp) {
    $p = array_filter(['y'=>$year,'m'=>$month,'proj'=>$f['project_id'],'emp'=>$f['employee_id'],'all'=>$all_emp?'1':''], fn($v)=>$v!=='' && $v!==0);
    return url_safe('timesheet', array_merge($p, $over));
};
$prev = $month === 1 ? ['y'=>$year-1,'m'=>12] : ['y'=>$year,'m'=>$month-1];
$next = $month === 12 ? ['y'=>$year+1,'m'=>1] : ['y'=>$year,'m'=>$month+1];
?>
<div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start">
  <div>
    <h1><i class="fa-solid fa-table-list"></i> Timesheet — <?=$mesi[$month]?> <?=$year?></h1>
    <p style="color:var(--muted);font-size:13px">Ore da rapporti di intervento (sola lettura) e voci manuali. Ore attese: <strong><?=$attese?></strong> (<?=$ts->workingDays($year,$month)?> gg feriali × <?=$ts->dailyHours()?> h).</p>
  </div>
  <div style="display:flex;gap:6px">
    <a class="btn btn-sm" href="<?=$qs($prev)?>">‹ <?=$mesi[$prev['m']]?></a>
    <a class="btn btn-sm" href="<?=$qs($next)?>"><?=$mesi[$next['m']]?> ›</a>
    <a class="btn btn-sm" href="<?=$qs(['export'=>'xlsx'])?>"><i class="fa-solid fa-file-export"></i> Esporta XLSX</a>
  </div>
</div>
<?= $msg ?>

<div class="card" style="margin-bottom:12px">
  <form method="get" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
    <?= route_slug_field() ?>
    <div class="form-group" style="margin:0"><label>Mese</label>
      <select name="m"><?php foreach($mesi as $k=>$v):?><option value="<?=$k?>" <?=$k==$month?'selected':''?>><?=$v?></option><?php endforeach;?></select></div>
    <div class="form-group" style="margin:0"><label>Anno</label>
      <input type="number" name="y" value="<?=$year?>" style="width:90px"></div>
    <div class="form-group" style="margin:0"><label>Commessa</label>
      <select name="proj"><option value="">tutte</option>
        <?php foreach($projects as $id=>$l):?><option value="<?=$id?>" <?=$f['project_id']==$id?'selected':''?>><?=h($l)?></option><?php endforeach;?></select></div>
    <div class="form-group" style="margin:0"><label>Dipendente</label>
      <select name="emp"><option value="">tutti</option>
        <?php foreach($employees as $id=>$l):?><option value="<?=$id?>" <?=$f['employee_id']==$id?'selected':''?>><?=h($l)?></option><?php endforeach;?></select></div>
    <label style="display:flex;gap:6px;align-items:center;padding-bottom:6px">
      <input type="checkbox" name="all" value="1" <?=$all_emp?'checked':''?>> mostra tutti i dipendenti</label>
    <button class="btn">Applica</button>
  </form>
</div>

<div class="card" style="overflow-x:auto">
  <table class="data-table" style="width:100%;font-size:11px;white-space:nowrap">
    <thead>
      <tr>
        <th style="position:sticky;left:0;background:#fff;text-align:left;min-width:170px">Dipendente</th>
        <?php for($d=1;$d<=$days;$d++): $w=(int)date('N',mktime(0,0,0,$month,$d,$year)); ?>
          <th style="text-align:center;<?=$w>5?'background:#f1f5f9;color:#94a3b8':''?>">
            <?=$d?><br><small style="font-weight:400"><?=['','L','M','M','G','V','S','D'][$w]?></small></th>
        <?php endfor; ?>
        <th style="text-align:center">Tot</th><th style="text-align:center">Rapp.</th><th style="text-align:center">Man.</th><th style="text-align:center">Sat.</th>
      </tr>
    </thead>
    <tbody>
    <?php if(!$emps): ?><tr><td colspan="<?=$days+5?>" style="text-align:center;color:var(--muted);padding:18px">Nessuna attività nel periodo.</td></tr>
    <?php else: $gt=0; foreach($emps as $e): $eid=(int)$e['id']; $tr=0;$tm=0; ?>
      <tr>
        <td style="position:sticky;left:0;background:#fff;font-weight:600"><?=h($e['nome'])?></td>
        <?php for($d=1;$d<=$days;$d++):
          $w=(int)date('N',mktime(0,0,0,$month,$d,$year));
          $a=$rep[$eid][$d]['ore']??0; $b=$man[$eid][$d]['ore']??0; $tot=$a+$b; $tr+=$a; $tm+=$b;
          $date=sprintf('%04d-%02d-%02d',$year,$month,$d);
          $bg = $w>5 ? '#f1f5f9' : ($tot>0 ? ($tot > $ts->dailyHours() ? '#fef3c7' : '#dcfce7') : '');
        ?>
          <td style="text-align:center;<?=$bg?"background:$bg":''?>">
            <?php if($tot>0): ?>
              <a href="<?=$qs(['d_emp'=>$eid,'d_date'=>$date])?>" style="color:inherit;text-decoration:none" title="<?=$a?'Rapporti: '.$a.'h':''?><?=$b?' Manuali: '.$b.'h':''?>">
                <strong><?=rtrim(rtrim(number_format($tot,2,',',''),'0'),',')?></strong><?php if($b>0):?><sup style="color:#0369a1">m</sup><?php endif;?></a>
            <?php else: ?><span style="color:#cbd5e1">·</span><?php endif; ?>
          </td>
        <?php endfor; $gt += $tr+$tm; $sat = $attese>0 ? round(($tr+$tm)/$attese*100) : 0; ?>
        <td style="text-align:center;font-weight:700"><?=round($tr+$tm,2)?></td>
        <td style="text-align:center"><?=round($tr,2)?></td>
        <td style="text-align:center;color:#0369a1"><?=round($tm,2)?></td>
        <td style="text-align:center;color:<?=$sat>=90?'#16a34a':($sat>=60?'#d97706':'#dc2626')?>;font-weight:700"><?=$sat?>%</td>
      </tr>
    <?php endforeach; ?>
      <tr style="background:#f8fafc;font-weight:700">
        <td style="position:sticky;left:0;background:#f8fafc">Totale (<?=count($emps)?> risorse)</td>
        <td colspan="<?=$days?>"></td>
        <td style="text-align:center"><?=round($gt,2)?></td><td colspan="3"></td>
      </tr>
    <?php endif; ?>
    </tbody>
  </table>
  <p style="color:var(--muted);font-size:11px;margin-top:8px">
    Verde = ore registrate · Giallo = oltre <?=$ts->dailyHours()?> h · <sup style="color:#0369a1">m</sup> = presenti voci manuali · Clic sulla cella per il dettaglio.
  </p>
</div>

<?php if($detail_emp && $detail_date): ?>
<div class="card" style="margin-top:12px;border:2px solid var(--p)">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-calendar-day"></i>
    <?=h($employees[$detail_emp] ?? '')?> — <?=h($detail_date)?></span></div>
  <table class="data-table" style="width:100%">
    <thead><tr><th>Origine</th><th>Commessa</th><th>Attività / Rapporto</th><th>Ore</th><th></th></tr></thead>
    <tbody>
    <?php foreach($d_reports as $r): ?>
      <tr><td><span style="color:var(--muted)">Rapporto</span></td>
          <td><?=h($r['project_code'] ?? '—')?></td>
          <td><code><?=h($r['report_code'])?></code><?=((int)$r['on_call'])?' <small style="color:#d97706">reperibilità</small>':''?></td>
          <td style="text-align:right"><?=h($r['quantity_hours'])?></td><td></td></tr>
    <?php endforeach; ?>
    <?php foreach($d_entries as $r): ?>
      <tr><td><span style="color:#0369a1">Manuale</span></td>
          <td><?=h($r['project_code'] ?? '—')?></td>
          <td><?=h($r['activity_type'])?><?=$r['notes']?' — <small style="color:var(--muted)">'.h($r['notes']).'</small>':''?></td>
          <td style="text-align:right"><?=h($r['hours'])?></td>
          <td><?php if($can_edit):?><form method="post" onsubmit="return confirm('Eliminare la voce?')" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="del_entry"><input type="hidden" name="entry_id" value="<?=(int)$r['id']?>">
            <input type="hidden" name="y" value="<?=$year?>"><input type="hidden" name="m" value="<?=$month?>">
            <button class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i></button></form><?php endif;?></td></tr>
    <?php endforeach; ?>
    <?php if(!$d_reports && !$d_entries): ?><tr><td colspan="5" style="text-align:center;color:var(--muted);padding:12px">Nessuna registrazione.</td></tr><?php endif;?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if($can_edit): ?>
<div class="card" style="margin-top:12px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-plus"></i> Nuova voce manuale</span></div>
  <form method="post" style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_entry">
    <input type="hidden" name="y" value="<?=$year?>"><input type="hidden" name="m" value="<?=$month?>">
    <div class="form-group"><label>Dipendente *</label>
      <select name="employee_id" required><option value="">—</option>
        <?php foreach($employees as $id=>$l):?><option value="<?=$id?>" <?=$detail_emp==$id?'selected':''?>><?=h($l)?></option><?php endforeach;?></select></div>
    <div class="form-group"><label>Data *</label>
      <input type="date" name="work_date" value="<?=h($detail_date ?: sprintf('%04d-%02d-01',$year,$month))?>" required></div>
    <div class="form-group"><label>Ore *</label><input type="number" step="0.25" min="0.25" name="hours" required></div>
    <div class="form-group"><label>Attività</label>
      <select name="activity_type"><?php foreach(Timesheet::ACTIVITIES as $a):?><option><?=$a?></option><?php endforeach;?></select></div>
    <div class="form-group"><label>Commessa</label>
      <select name="project_id"><option value="">— nessuna —</option>
        <?php foreach($projects as $id=>$l):?><option value="<?=$id?>" <?=$f['project_id']==$id?'selected':''?>><?=h($l)?></option><?php endforeach;?></select></div>
    <div class="form-group"><label>Note</label><input type="text" name="notes"></div>
    <div style="grid-column:1/-1"><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Aggiungi voce</button></div>
  </form>
</div>
<?php endif; ?>
<?php require_once('footer.php'); ?>
