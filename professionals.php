<?php
/**
 * professionals.php — Anagrafica Professionisti (v1.7.81)
 *
 * Gestione degli operatori importati non presenti tra i dipendenti: ricerca,
 * suggerimento di corrispondenza con un dipendente (email/nome), merge
 * (collegamento a employees) o marcatura come confermato/ignorato.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/ProfessionalStore.php');

if (!can('view', 'professionals.php')) { redirect('manage_projects'); }
$u_id  = (int)$_SESSION['user_id'];
$store = new ProfessionalStore($pdo);
$can_edit = can('edit', 'professionals.php') || can('create', 'professionals.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    if (!$can_edit) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect_self(); }
    $act = $_POST['action'] ?? '';
    $pid = (int)($_POST['prof_id'] ?? 0);

    if ($act === 'link') {
        $eid = (int)($_POST['employee_id'] ?? 0);
        $res = $store->linkToEmployee($pid, $eid, $u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-" . ($res['ok'] ? 'success' : 'danger') . "'>" . h($res['msg']) . "</div>";
        if ($res['ok']) write_log('Professionals', 'success', "Professionista #$pid collegato a dipendente #$eid", $u_id);
    } elseif ($act === 'unlink') {
        $store->unlink($pid);
        write_log('Professionals', 'success', "Professionista #$pid scollegato", $u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>Collegamento rimosso.</div>";
    } elseif ($act === 'status') {
        $store->setStatus($pid, $_POST['status'] ?? '');
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>Stato aggiornato.</div>";
    } elseif ($act === 'detect') {
        $n = $store->detectEmployees();
        write_log('Professionals', 'success', "Rilevamento dipendenti: $n corrispondenze", $u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>Rilevamento completato: <strong>$n</strong> professionisti corrispondono a un dipendente.</div>";
    } elseif ($act === 'promote') {
        $endDate = trim($_POST['end_date'] ?? '');
        $endDate = ($endDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) ? $endDate : null;
        $res = $store->promoteToEmployee($pid, $endDate, $u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-" . ($res['ok'] ? 'success' : 'danger') . "'>" . h($res['msg']) . "</div>";
        if ($res['ok']) write_log('Professionals', 'success', "Professionista #$pid importato in dipendenti come #" . ($res['employee_id'] ?? '?'), $u_id);
    }
    redirect_self(['q' => $_POST['q'] ?? '', 'status' => $_POST['fstatus'] ?? '', 'company' => $_POST['fcompany'] ?? '', 'type' => $_POST['ftype'] ?? '', 'pg' => $_POST['pg'] ?? 1]);
}

$f = ['q' => trim($_GET['q'] ?? ''), 'status' => trim($_GET['status'] ?? ''), 'company' => trim($_GET['company'] ?? ''),
      'type' => trim($_GET['type'] ?? ''), 'only_active' => ($_GET['act'] ?? '') === '1'];
$per = 40; $pg = max(1, (int)($_GET['pg'] ?? 1));
$L = $store->listItems($f, $per, ($pg - 1) * $per);
$pages = max(1, (int)ceil($L['total'] / $per));
$counts = $store->counts();
$companies = $store->companies();

// suggerimenti di merge per le righe non ancora collegate
$suggest = [];
foreach ($L['rows'] as $r) {
    if (!$r['employee_id'] && in_array($r['status'], ['nuovo', 'confermato'], true)) {
        $suggest[$r['id']] = $store->suggestEmployee($r);
    }
}

require_once('header.php');
$qs = function (array $o = []) use ($f, $pg) {
    return url_safe('professionals', array_filter(array_merge(['q' => $f['q'], 'status' => $f['status'], 'company' => $f['company'], 'type' => $f['type'], 'act' => $f['only_active'] ? '1' : '', 'pg' => $pg], $o), fn($v) => $v !== '' && $v !== 0));
};
$badge = ['nuovo' => '#0369a1', 'confermato' => '#16a34a', 'unito' => '#7c3aed', 'ignorato' => '#94a3b8'];
?>
<div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start">
  <div>
    <h1><i class="fa-solid fa-user-tie"></i> Anagrafica Professionisti</h1>
    <p style="color:var(--muted);font-size:13px">Operatori importati dal gestionale non presenti tra i dipendenti. Puoi collegarli (merge) a un dipendente esistente o gestirne lo stato.</p>
  </div>
  <a class="btn btn-sm" href="<?=url_safe('import_professionals')?>"><i class="fa-solid fa-file-import"></i> Importa operatori</a>
</div>

<div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;align-items:stretch">
  <?php foreach ([['tot','Totale','#0f172a'],['esterni','Esterni','#0891b2'],['dipendenti','Dipendenti','#7c3aed'],['nuovo','Nuovi',$badge['nuovo']],['unito','Uniti',$badge['unito']],['ignorato','Ignorati',$badge['ignorato']]] as [$k,$lbl,$c]): ?>
    <div class="card" style="flex:1;min-width:110px;text-align:center;padding:12px">
      <div style="font-size:22px;font-weight:800;color:<?=$c?>"><?=$counts[$k]?? 0?></div>
      <div style="font-size:11px;color:var(--muted);font-weight:700"><?=$lbl?></div>
    </div>
  <?php endforeach; ?>
  <?php if ($can_edit): ?>
  <form method="post" style="display:flex;align-items:center">
    <?= csrf_field() ?><input type="hidden" name="action" value="detect">
    <input type="hidden" name="q" value="<?=h($f['q'])?>"><input type="hidden" name="fstatus" value="<?=h($f['status'])?>"><input type="hidden" name="fcompany" value="<?=h($f['company'])?>"><input type="hidden" name="ftype" value="<?=h($f['type'])?>">
    <button class="btn btn-sm" title="Riesamina le corrispondenze con l'anagrafica dipendenti"><i class="fa-solid fa-magnifying-glass-arrow-right"></i> Rileva dipendenti</button>
  </form>
  <?php endif; ?>
</div>

<div class="card" style="margin-bottom:14px">
  <form method="get" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
    <?= route_slug_field() ?>
    <div class="form-group" style="margin:0"><label>Cerca</label><input type="text" name="q" value="<?=h($f['q'])?>" placeholder="nome, email, sigla…" style="width:220px"></div>
    <div class="form-group" style="margin:0"><label>Tipo</label>
      <select name="type"><option value="">tutti</option>
        <option value="esterni" <?=$f['type']==='esterni'?'selected':''?>>solo esterni</option>
        <option value="dipendenti" <?=$f['type']==='dipendenti'?'selected':''?>>solo dipendenti</option>
      </select></div>
    <div class="form-group" style="margin:0"><label>Stato</label>
      <select name="status"><option value="">tutti</option>
        <?php foreach (['nuovo','confermato','unito','ignorato'] as $s):?><option value="<?=$s?>" <?=$f['status']===$s?'selected':''?>><?=$s?></option><?php endforeach;?></select></div>
    <div class="form-group" style="margin:0"><label>Azienda</label>
      <select name="company"><option value="">tutte</option>
        <?php foreach ($companies as $c):?><option value="<?=h($c)?>" <?=$f['company']===$c?'selected':''?>><?=h($c)?></option><?php endforeach;?></select></div>
    <label style="display:flex;gap:6px;align-items:center;padding-bottom:6px"><input type="checkbox" name="act" value="1" <?=$f['only_active']?'checked':''?>> solo attivi</label>
    <button class="btn">Filtra</button>
    <span style="color:var(--muted);font-size:12px;align-self:center"><strong><?=$L['total']?></strong> professionisti</span>
  </form>
</div>

<div class="card">
  <table class="data-table" style="width:100%;font-size:12px">
    <thead><tr><th>Nome</th><th>Tipo</th><th>Email</th><th>Azienda</th><th>Costo/h</th><th>Stato</th><th>Dipendente collegato / suggerimento</th><th></th></tr></thead>
    <tbody>
    <?php if (!$L['rows']): ?><tr><td colspan="8" style="text-align:center;color:var(--muted);padding:16px">Nessun professionista. <a href="<?=url_safe('import_professionals')?>">Importa gli operatori</a>.</td></tr>
    <?php else: foreach ($L['rows'] as $r): $sg = $suggest[$r['id']] ?? null; ?>
      <tr <?=$r['deleted_src']?'style="opacity:.55"':''?>>
        <td style="font-weight:600"><?=h(trim(($r['last_name']??'').' '.($r['first_name']??'')))?><?php if($r['abbr']):?> <small style="color:var(--muted)">(<?=h($r['abbr'])?>)</small><?php endif;?><?=$r['active']?'':' <span style="font-size:10px;color:#dc2626">inattivo</span>'?></td>
        <td><?php if (ProfessionalStore::isEmployee($r)): ?>
              <span style="background:#ede9fe;color:#6d28d9;border-radius:8px;padding:1px 8px;font-size:11px;font-weight:700"><i class="fa-solid fa-id-badge"></i> Dipendente</span>
            <?php else: ?>
              <span style="background:#cffafe;color:#0e7490;border-radius:8px;padding:1px 8px;font-size:11px;font-weight:700"><i class="fa-solid fa-user-tie"></i> Esterno</span>
            <?php endif; ?></td>
        <td style="color:var(--muted)"><?=h($r['email']??'—')?></td>
        <td><?=h($r['company_abbr']??'—')?></td>
        <td style="text-align:right"><?=$r['hourly_cost']!==null?number_format((float)$r['hourly_cost'],2,',','.'):'—'?></td>
        <td><span style="color:<?=$badge[$r['status']]??'#64748b'?>;font-weight:700"><?=h($r['status'])?></span></td>
        <td>
          <?php if ($r['employee_id']): ?>
            <i class="fa-solid fa-link" style="color:#7c3aed"></i> <?=h(trim($r['emp_name']))?> <small style="color:var(--muted)">#<?=$r['employee_id']?></small>
            <?php if ($can_edit): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Rimuovere il collegamento?')">
                <?= csrf_field() ?><input type="hidden" name="action" value="unlink"><input type="hidden" name="prof_id" value="<?=$r['id']?>">
                <input type="hidden" name="q" value="<?=h($f['q'])?>"><input type="hidden" name="fstatus" value="<?=h($f['status'])?>"><input type="hidden" name="fcompany" value="<?=h($f['company'])?>"><input type="hidden" name="pg" value="<?=$pg?>">
                <button class="btn-link" style="border:0;background:none;color:#dc2626;cursor:pointer;font-size:11px" title="Scollega"><i class="fa-solid fa-link-slash"></i></button></form>
            <?php endif; ?>
          <?php elseif ($sg && $can_edit): ?>
            <form method="post" style="display:flex;gap:6px;align-items:center">
              <?= csrf_field() ?><input type="hidden" name="action" value="link"><input type="hidden" name="prof_id" value="<?=$r['id']?>"><input type="hidden" name="employee_id" value="<?=$sg['id']?>">
              <input type="hidden" name="q" value="<?=h($f['q'])?>"><input type="hidden" name="fstatus" value="<?=h($f['status'])?>"><input type="hidden" name="fcompany" value="<?=h($f['company'])?>"><input type="hidden" name="pg" value="<?=$pg?>">
              <span style="font-size:11px;color:var(--muted)">forse: <strong><?=h(trim($sg['first_name'].' '.$sg['last_name']))?></strong>
                <span style="background:#ede9fe;color:#6d28d9;border-radius:8px;padding:0 6px;font-size:10px"><?=$sg['match']==='email'?'email':($sg['match']==='name_swapped'?'nome inv.':'nome')?></span></span>
              <button class="btn btn-sm" style="background:#7c3aed;color:#fff;border:0"><i class="fa-solid fa-code-merge"></i> Unisci</button>
            </form>
          <?php else: ?>
            <span style="color:#cbd5e1">nessun suggerimento</span>
          <?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <?php if ($can_edit && !$r['employee_id']): ?>
            <form method="post" style="display:inline-flex;gap:4px;align-items:center">
              <?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="prof_id" value="<?=$r['id']?>">
              <input type="hidden" name="q" value="<?=h($f['q'])?>"><input type="hidden" name="fstatus" value="<?=h($f['status'])?>"><input type="hidden" name="fcompany" value="<?=h($f['company'])?>"><input type="hidden" name="pg" value="<?=$pg?>">
              <select name="status" onchange="this.form.submit()" style="font-size:11px;padding:2px">
                <?php foreach (['nuovo','confermato','ignorato'] as $s):?><option value="<?=$s?>" <?=$r['status']===$s?'selected':''?>><?=$s?></option><?php endforeach;?>
              </select>
            </form>
            <form method="post" style="display:inline-flex;gap:4px;align-items:center;margin-top:4px" onsubmit="return confirm('Creare un nuovo dipendente da questo professionista?')">
              <?= csrf_field() ?><input type="hidden" name="action" value="promote"><input type="hidden" name="prof_id" value="<?=$r['id']?>">
              <input type="hidden" name="q" value="<?=h($f['q'])?>"><input type="hidden" name="fstatus" value="<?=h($f['status'])?>"><input type="hidden" name="fcompany" value="<?=h($f['company'])?>"><input type="hidden" name="ftype" value="<?=h($f['type'])?>"><input type="hidden" name="pg" value="<?=$pg?>">
              <input type="date" name="end_date" title="Data di cessazione (se non più attivo)" <?=((int)$r['active']===1)?'':'value="'.date('Y-m-d').'"'?> style="font-size:11px;padding:1px 3px">
              <button class="btn btn-sm" style="background:#0891b2;color:#fff;border:0" title="Crea un dipendente da questo professionista"><i class="fa-solid fa-user-plus"></i> In Dipendenti</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <?php if ($pages > 1): ?>
  <div style="display:flex;gap:6px;justify-content:center;margin-top:12px;align-items:center">
    <?php if($pg>1):?><a class="btn btn-sm" href="<?=$qs(['pg'=>$pg-1])?>">‹</a><?php endif;?>
    <span style="color:var(--muted);font-size:12px">pagina <?=$pg?> di <?=$pages?></span>
    <?php if($pg<$pages):?><a class="btn btn-sm" href="<?=$qs(['pg'=>$pg+1])?>">›</a><?php endif;?>
  </div>
  <?php endif; ?>
</div>
<?php require_once('footer.php'); ?>
