<?php
/**
 * certV 2.0 — visualizza_storico.php
 */
require_once('access_control.php');
require_once('header.php');

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
$can_edit = can('edit');
$u_emp_id = (int)($_SESSION["employee_id"] ?? 0);
$restrict = ($u_role === 6) ? $u_emp_id : 0;
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit && isset($_POST['save_cert'])) {
    $id = (int)$_POST['cert_id'];
    $expiry = $_POST['expiry_date'] ?: null;
    $notes  = trim($_POST['notes'] ?? '');
    $old = $pdo->prepare("SELECT * FROM user_certifications WHERE id=?");
    $old->execute([$id]);
    $old_data = $old->fetch();
    if ($old_data) {
        $pdo->prepare("INSERT INTO brand_contacts_history (brand_id,archived_data,archived_by) VALUES (NULL,?,?)")
            ->execute([json_encode(['type'=>'uc_edit','old'=>$old_data]), $u_id]);
    }
    $new_status = cert_status_from_date($expiry);
    $pdo->prepare("UPDATE user_certifications SET expiry_date=?,status=?,notes=? WHERE id=?")
        ->execute([$expiry, $new_status, $notes, $id]);
    write_log('Certifications','success',"Aggiornata cert #$id",$u_id);
    $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Certificazione aggiornata.</div>";
}

$f_br  = $_GET['f_br']  ?? [];
$f_us  = $_GET['f_us']  ?? [];
$f_st  = $_GET['f_st']  ?? [];
$f_txt = trim($_GET['q'] ?? '');

$where = ["1=1"]; $params = [];
if ($restrict)         { $where[]="uc.employee_id=?"; $params[]=$restrict; }
if (!empty($f_br))     { $where[]="cert.brand_id IN(".implode(',',array_fill(0,count($f_br),'?')).")"; $params=array_merge($params,$f_br); }
if (!empty($f_us)&&!$restrict) { $where[]="uc.employee_id IN(".implode(',',array_fill(0,count($f_us),'?')).")"; $params=array_merge($params,$f_us); }
if (!empty($f_st))     { $where[]="uc.status IN(".implode(',',array_fill(0,count($f_st),'?')).")"; $params=array_merge($params,$f_st); }
if ($f_txt)            { $where[]="(cert.name LIKE ? OR e.last_name LIKE ? OR e.first_name LIKE ?)"; array_push($params,"%$f_txt%","%$f_txt%","%$f_txt%"); }

$stmt = $pdo->prepare(
    "SELECT uc.*, cert.name cert_name, cert.code cert_code, cert.category,
            b.name brand_name, e.first_name, e.last_name, t.name tech_name
     FROM user_certifications uc
     JOIN certifications cert ON uc.certification_id=cert.id
     JOIN brands b            ON cert.brand_id=b.id
     JOIN employees e         ON uc.employee_id=e.id
     JOIN technologies t      ON cert.technology_id=t.id
     WHERE ".implode(' AND ',$where)."
     ORDER BY uc.expiry_date ASC, uc.issue_date DESC"
);
$stmt->execute($params);
$results = $stmt->fetchAll();

$cnt_active   = count(array_filter($results,fn($r)=>$r['status']==='active'));
$cnt_expiring = count(array_filter($results,fn($r)=>$r['status']==='expiring'));
$cnt_expired  = count(array_filter($results,fn($r)=>$r['status']==='expired'));

$all_brands = $pdo->query("SELECT id,name FROM brands ORDER BY name")->fetchAll();
$all_users  = $pdo->query("SELECT id,first_name,last_name FROM employees WHERE status='active' ORDER BY last_name")->fetchAll();

$edit_cert = null;
if (isset($_GET['edit']) && $can_edit) {
    $es = $pdo->prepare("SELECT uc.*,cert.name cert_name FROM user_certifications uc JOIN certifications cert ON uc.certification_id=cert.id WHERE uc.id=?");
    $es->execute([(int)$_GET['edit']]);
    $edit_cert = $es->fetch();
}
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
  <div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:3px">
      <i class="fa-solid fa-clock-rotate-left" style="color:var(--p);margin-right:10px"></i>Storico competenze
    </h1>
    <p style="color:var(--muted);font-size:13px"><?=count($results)?> certificazioni trovate</p>
  </div>
  <div style="display:flex;gap:8px" class="no-print">
    <button onclick="window.print()" class="btn btn-sm"><i class="fa-solid fa-print"></i> Stampa</button>
    <?php if(check_ui_permission('upload_certificato.php')): ?>
    <a href="upload_certificato.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> Aggiungi</a>
    <?php endif; ?>
  </div>
</div>

<!-- Badge contatori rapidi -->
<div style="display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap" class="no-print">
  <a href="<?= qs_self_safe(['f_st[]'=>'active']) ?>" style="background:#d1fae5;border:1px solid #10b981;color:#065f46;padding:7px 16px;border-radius:20px;font-size:12px;font-weight:700;text-decoration:none">
    <i class="fa-solid fa-circle-check"></i> Attive: <?=$cnt_active?>
  </a>
  <a href="<?= qs_self_safe(['f_st[]'=>'expiring']) ?>" style="background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:7px 16px;border-radius:20px;font-size:12px;font-weight:700;text-decoration:none">
    <i class="fa-solid fa-clock"></i> In scadenza: <?=$cnt_expiring?>
  </a>
  <a href="<?= qs_self_safe(['f_st[]'=>'expired']) ?>" style="background:#fee2e2;border:1px solid #ef4444;color:#991b1b;padding:7px 16px;border-radius:20px;font-size:12px;font-weight:700;text-decoration:none">
    <i class="fa-solid fa-circle-xmark"></i> Scadute: <?=$cnt_expired?>
  </a>
  <a href="visualizza_storico.php" style="padding:7px 16px;border-radius:20px;font-size:12px;font-weight:700;background:#f1f5f9;color:#475569;text-decoration:none;border:1px solid var(--border)">
    Tutte (<?=count($results)?>)
  </a>
</div>

<?=$msg?>

<?php if(!$restrict): ?>
<form method="GET" class="filter-bar" style="align-items:flex-start;margin-bottom:20px">
<?php if (!empty($_GET["r"])): ?><input type="hidden" name="r" value="<?= htmlspecialchars($_GET["r"], ENT_QUOTES, "UTF-8") ?>"><?php endif; ?>
  <div class="fg">
    <label>Cerca</label>
    <input type="text" name="q" value="<?=h($f_txt)?>" placeholder="Nome, certificazione..." style="min-width:180px">
  </div>
  <div class="fg">
    <label>Brand</label>
    <div style="background:#fff;border:1px solid var(--border);border-radius:8px;height:90px;overflow-y:auto;padding:8px;min-width:160px">
      <?php foreach($all_brands as $b): ?>
      <label style="display:flex;gap:7px;font-size:12px;margin-bottom:3px;cursor:pointer;align-items:center">
        <input type="checkbox" name="f_br[]" value="<?=$b['id']?>" <?=in_array($b['id'],$f_br)?'checked':''?>><?=h($b['name'])?>
      </label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="fg">
    <label>Collaboratori</label>
    <div style="background:#fff;border:1px solid var(--border);border-radius:8px;height:90px;overflow-y:auto;padding:8px;min-width:160px">
      <?php foreach($all_users as $u): ?>
      <label style="display:flex;gap:7px;font-size:12px;margin-bottom:3px;cursor:pointer;align-items:center">
        <input type="checkbox" name="f_us[]" value="<?=$u['id']?>" <?=in_array($u['id'],$f_us)?'checked':''?>><?=h($u['last_name'].' '.$u['first_name'])?>
      </label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="fg">
    <label>Stato</label>
    <div style="background:#fff;border:1px solid var(--border);border-radius:8px;padding:10px;min-width:120px">
      <label style="display:flex;gap:7px;font-size:12px;margin-bottom:5px;cursor:pointer"><input type="checkbox" name="f_st[]" value="active"   <?=in_array('active',$f_st)?'checked':''?>> Attiva</label>
      <label style="display:flex;gap:7px;font-size:12px;margin-bottom:5px;cursor:pointer"><input type="checkbox" name="f_st[]" value="expiring" <?=in_array('expiring',$f_st)?'checked':''?>> In scadenza</label>
      <label style="display:flex;gap:7px;font-size:12px;cursor:pointer"><input type="checkbox" name="f_st[]" value="expired"  <?=in_array('expired',$f_st)?'checked':''?>> Scaduta</label>
    </div>
  </div>
  <div style="display:flex;flex-direction:column;gap:6px;padding-top:22px">
    <button type="submit" class="btn btn-primary">Filtra</button>
    <a href="visualizza_storico.php" class="btn btn-sm" style="text-align:center">Reset</a>
  </div>

  <div class="fg" style="margin-left:auto">
    <label style="visibility:hidden">.</label>
    <button type="button" onclick="window.print()" class="btn btn-sm" title="Stampa pagina"
            style="background:#fef3c7;color:#92400e;border-color:#fde68a">
      <i class="fa-solid fa-print"></i> Stampa
    </button>
  </div>
</form>
<?php endif; ?>

<?php if($edit_cert && $can_edit): ?>
<div class="card" style="margin-bottom:18px;border-color:var(--p);background:#f0f9ff">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-pen" style="color:var(--p)"></i> Modifica — <?=h($edit_cert['cert_name'])?></span>
    <a href="visualizza_storico.php" class="btn btn-sm">Annulla</a>
  </div>
  <form method="POST" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap">
            <?= csrf_field() ?>
    <input type="hidden" name="save_cert" value="1">
    <input type="hidden" name="cert_id"   value="<?=$edit_cert['id']?>">
    <div class="form-group" style="margin:0;flex:1;min-width:150px">
      <label>Data scadenza</label>
      <input type="date" name="expiry_date" value="<?=h($edit_cert['expiry_date']??'')?>">
    </div>
    <div class="form-group" style="margin:0;flex:2;min-width:220px">
      <label>Note</label>
      <input type="text" name="notes" value="<?=h($edit_cert['notes']??'')?>" placeholder="Note opzionali...">
    </div>
    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Salva</button>
  </form>
</div>
<?php endif; ?>

<div class="card" style="overflow-x:auto">
  <?php require_once __DIR__ . '/app/ListFilter.php'; ListFilter::render('visualizza_storico', '#tStorico', ['export_filename' => 'visualizza_storico', 'title' => 'Storico']); ?>
<table class="data-table" id="tStorico">
    <thead>
      <tr>
        <th>Collaboratore</th><th>Certificazione</th><th>Brand</th><th>Tecnologia</th>
        <th>Categoria</th><th>Conseguita</th><th>Scadenza</th>
        <th style="text-align:center">PDF</th><th style="text-align:center">Stato</th>
        <?php if($can_edit): ?><th style="text-align:center" class="no-print">Azioni</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
    <?php if(empty($results)): ?>
    <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--muted)">Nessun risultato.</td></tr>
    <?php endif; ?>
    <?php foreach($results as $r):
      $dl = $r['expiry_date'] ? days_diff($r['expiry_date']) : null;
      $cat_badge = match($r['category']??'') { 'tecnica'=>'badge-info','commerciale'=>'badge-success',default=>'badge-neutral' };
    ?>
    <tr>
      <td><strong><?=h($r['last_name'].' '.$r['first_name'])?></strong></td>
      <td><?=h($r['cert_name'])?><?php if($r['cert_code']): ?><br><code style="font-size:10px;color:var(--muted)"><?=h($r['cert_code'])?></code><?php endif; ?><?php if($r['notes']): ?><br><em style="font-size:10px;color:var(--muted)"><?=h($r['notes'])?></em><?php endif; ?></td>
      <td><span class="badge badge-neutral" style="font-size:9px"><?=h($r['brand_name'])?></span></td>
      <td style="font-size:11px;color:var(--muted)"><?=h($r['tech_name'])?></td>
      <td><span class="badge <?=$cat_badge?>" style="font-size:9px"><?=h(ucfirst($r['category']??''))?></span></td>
      <td style="font-size:12px"><?=format_date($r['issue_date'])?></td>
      <td style="font-size:12px">
        <?=$r['expiry_date']?format_date($r['expiry_date']):'<span style="color:var(--muted)">Perpetua</span>'?>
        <?php if($dl!==null&&$dl>=0&&$dl<=90): ?><br><span style="font-size:10px;color:<?=$dl<=30?'var(--danger)':'var(--warning)'?>;font-weight:700"><?=$dl?> gg</span><?php endif; ?>
      </td>
      <td style="text-align:center">
        <?php if($r['document_path']): ?>
        <a href="download.php?file=<?=urlencode($r['document_path'])?>" target="_blank" style="color:#e11d48;font-size:17px"><i class="fa-solid fa-file-pdf"></i></a>
        <?php else: ?><i class="fa-solid fa-file-circle-xmark" style="color:#cbd5e1"></i><?php endif; ?>
      </td>
      <td style="text-align:center"><?=status_badge($r['status'])?></td>
      <?php if($can_edit): ?>
      <td style="text-align:center" class="no-print">
        <a href="<?= qs_self_safe(['edit'=>''.($r['id']).'']) ?>" class="btn btn-blue btn-sm"><i class="fa-solid fa-pen"></i></a>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<script>$('#tStorico').DataTable({language:{search:"Cerca:"},pageLength:25,order:[[6,'asc']]});</script>
<?php require_once('footer.php'); ?>
