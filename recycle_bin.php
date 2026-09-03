<?php
/**
 * recycle_bin.php — Cestino: ripristino record cancellati per errore (v1.7.76)
 *
 * Elenca i record eliminati (archiviati in cm_deleted_records al momento della
 * cancellazione), permette di ripristinarli o eliminarli definitivamente e di
 * svuotare le voci più vecchie di N giorni.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/RecycleBin.php');

if (!can('view', 'recycle_bin.php')) { redirect('manage_projects'); }
$can_restore = can('edit', 'recycle_bin.php') || can('create', 'recycle_bin.php');
$can_purge   = can('delete', 'recycle_bin.php');
$u_id = (int)$_SESSION['user_id'];
$bin  = new RecycleBin($pdo);

// ─────────── POST (PRG) ───────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $act = $_POST['action'] ?? '';
    if ($act === 'restore') {
        if (!$can_restore) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect_self(); }
        $r = $bin->restore((int)($_POST['bin_id'] ?? 0), $u_id);
        write_log('RecycleBin', $r['ok'] ? 'success' : 'warning', $r['msg'], $u_id);
        $cls = $r['ok'] ? 'success' : 'danger';
        $_SESSION['flash_msg'] = "<div class='alert alert-$cls'>" . h($r['msg']) . "</div>";
        redirect_self();
    }
    if ($act === 'purge') {
        if (!$can_purge) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect_self(); }
        $bin->purge((int)($_POST['bin_id'] ?? 0));
        write_log('RecycleBin','success','Elemento eliminato definitivamente dal cestino',$u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>Elemento eliminato definitivamente.</div>";
        redirect_self();
    }
    if ($act === 'purge_old') {
        if (!$can_purge) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect_self(); }
        $days = max(1, (int)($_POST['days'] ?? 90));
        $n = $bin->purgeOlderThan($days);
        write_log('RecycleBin','success',"Svuotamento cestino: $n voci oltre $days giorni",$u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>$n voci eliminate (oltre $days giorni).</div>";
        redirect_self();
    }
    redirect_self();
}

$f = ['table' => trim($_GET['t'] ?? ''), 'restored' => $_GET['r'] ?? '', 'q' => trim($_GET['q'] ?? '')];
$page = max(1, (int)($_GET['p'] ?? 1));
$per  = 50;
$res  = $bin->listItems($f, $per, ($page - 1) * $per);
$pages = max(1, (int)ceil($res['total'] / $per));
$tables_present = $bin->tables();

$msg = '';
if (!empty($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
require_once('header.php');

$qs = fn(array $o=[]) => url_safe('recycle_bin', array_filter(array_merge(['t'=>$f['table'],'r'=>$f['restored'],'q'=>$f['q'],'p'=>$page], $o), fn($v)=>$v!=='' && $v!==null));
?>
<div class="page-header">
  <h1><i class="fa-solid fa-trash-arrow-up"></i> Cestino — ripristino record</h1>
  <p style="color:var(--muted);font-size:13px">I record eliminati dal modulo Gestione Commesse vengono conservati qui e possono essere ripristinati. Il ripristino re-inserisce il record con i dati originali.</p>
</div>
<?= $msg ?>

<div class="card" style="margin-bottom:12px">
  <form method="get" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
    <div class="form-group" style="margin:0"><label>Tipo record</label>
      <select name="t"><option value="">tutti</option>
        <?php foreach($tables_present as $t):?><option value="<?=h($t)?>" <?=$f['table']===$t?'selected':''?>><?=h(RecycleBin::labelFor($t))?></option><?php endforeach;?></select></div>
    <div class="form-group" style="margin:0"><label>Stato</label>
      <select name="r"><option value="">tutti</option>
        <option value="0" <?=$f['restored']==='0'?'selected':''?>>nel cestino</option>
        <option value="1" <?=$f['restored']==='1'?'selected':''?>>ripristinati</option></select></div>
    <div class="form-group" style="margin:0"><label>Cerca</label><input type="text" name="q" value="<?=h($f['q'])?>" placeholder="descrizione" style="width:220px"></div>
    <button class="btn">Filtra</button>
    <span style="color:var(--muted);font-size:12px;align-self:center"><strong><?=$res['total']?></strong> voci</span>
  </form>
</div>

<div class="card">
  <table class="data-table" style="width:100%;font-size:12px">
    <thead><tr><th>Eliminato il</th><th>Tipo</th><th>Descrizione</th><th>Origine</th><th>Da</th><th>Stato</th><th style="width:170px"></th></tr></thead>
    <tbody>
    <?php if(!$res['rows']): ?><tr><td colspan="7" style="text-align:center;color:var(--muted);padding:16px">Nessun record nel cestino.</td></tr>
    <?php else: foreach($res['rows'] as $r): ?>
      <tr <?=$r['restored']?'style="opacity:.6"':''?>>
        <td style="white-space:nowrap"><?=date('d/m/Y H:i', strtotime($r['deleted_at']))?></td>
        <td><?=h(RecycleBin::labelFor($r['table_name']))?></td>
        <td><?=h($r['label'])?> <small style="color:var(--muted)">(<?=h($r['pk_column'])?>=<?=h($r['record_pk'])?>)</small></td>
        <td style="color:var(--muted)"><?=h($r['context'])?></td>
        <td><?=h(trim($r['deleted_by_name'] ?? '') ?: ($r['deleted_by']?'#'.$r['deleted_by']:'—'))?></td>
        <td><?php if($r['restored']): ?><span style="color:#16a34a">ripristinato<?=$r['restored_at']?' '.date('d/m/Y',strtotime($r['restored_at'])):''?></span><?php else: ?><span style="color:#d97706">nel cestino</span><?php endif; ?></td>
        <td>
          <?php if(!$r['restored'] && $can_restore): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Ripristinare questo record?')">
              <?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="bin_id" value="<?=(int)$r['id']?>">
              <button class="btn btn-sm btn-success"><i class="fa-solid fa-rotate-left"></i> Ripristina</button></form>
          <?php endif; ?>
          <?php if($can_purge): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Eliminare DEFINITIVAMENTE questa voce dal cestino?')">
              <?= csrf_field() ?><input type="hidden" name="action" value="purge"><input type="hidden" name="bin_id" value="<?=(int)$r['id']?>">
              <button class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i></button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <?php if($pages>1): ?>
  <div style="display:flex;gap:6px;justify-content:center;margin-top:12px;align-items:center">
    <?php if($page>1):?><a class="btn btn-sm" href="<?=$qs(['p'=>$page-1])?>">‹</a><?php endif;?>
    <span style="color:var(--muted);font-size:12px">pagina <?=$page?> di <?=$pages?></span>
    <?php if($page<$pages):?><a class="btn btn-sm" href="<?=$qs(['p'=>$page+1])?>">›</a><?php endif;?>
  </div>
  <?php endif; ?>
</div>

<?php if($can_purge): ?>
<div class="card" style="margin-top:12px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-broom"></i> Manutenzione cestino</span></div>
  <form method="post" style="display:flex;gap:10px;align-items:flex-end" onsubmit="return confirm('Eliminare definitivamente le voci più vecchie?')">
    <?= csrf_field() ?><input type="hidden" name="action" value="purge_old">
    <div class="form-group" style="margin:0"><label>Elimina voci oltre (giorni)</label><input type="number" name="days" value="90" min="1" style="width:100px"></div>
    <button class="btn btn-danger"><i class="fa-solid fa-broom"></i> Svuota vecchie voci</button>
  </form>
</div>
<?php endif; ?>
<?php require_once('footer.php'); ?>
