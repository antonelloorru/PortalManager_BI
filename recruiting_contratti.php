<?php
/**
 * certV 2.4 — recruiting_contratti.php
 * Contratti agenzie con upload documento firmato e storicizzazione versioni
 * Solo HR Director (2) e Super Admin (1)
 */
require_once('access_control.php');
require_once('functions.php');

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
if (!can("edit")) { header("Location: unauthorized.php"); exit(); }

$upload_dir = __DIR__ . '/uploads/contratti/';
if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

// Auto-migrate
try { $pdo->query("SELECT id FROM contract_documents LIMIT 0")->closeCursor(); }
catch (\Exception $e) {
    $mf = __DIR__ . '/migration_contract_docs.sql';
    if (file_exists($mf)) {
        foreach (explode(";", file_get_contents($mf)) as $s) {
            $s = trim($s); if (!$s || strpos($s,'--')===0 || preg_match('/^\s*(SELECT|SHOW)/i',$s)) continue;
            try { $pdo->exec($s); } catch (\Exception $ex) {}
        }
    }
}

$f_agency = (int)($_GET['agency_id'] ?? 0);

// ── CRUD (prima di header.php per redirect PRG) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    $id = (int)($_POST['contract_id'] ?? 0);

    if ($action === 'terminate' && $id > 0) {
        $pdo->prepare("UPDATE agency_contracts SET status='terminated', end_date=CURDATE() WHERE id=?")->execute([$id]);
        write_log('Contracts','success',"Contratto #$id terminato",$u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Contratto terminato.</div>";
        redirect('recruiting_contratti');
    }

    if ($action === 'save') {
        $agency_id = (int)$_POST['agency_id'];
        if ($agency_id) {
            $fee_pct = strlen(trim($_POST['fee_percent'] ?? '')) > 0 ? (float)$_POST['fee_percent'] : null;
            $fee_h = strlen(trim($_POST['fee_hourly'] ?? '')) > 0 ? (float)$_POST['fee_hourly'] : null;
            $data = [$agency_id, $_POST['contract_ref']?:null, $_POST['type'], $fee_pct, $fee_h,
                     $_POST['start_date'], $_POST['end_date']?:null, isset($_POST['auto_renewal'])?1:0,
                     max(0,(int)($_POST['notice_days']??30)), $_POST['status'], $_POST['notes']?:null];
            try {
                if ($id > 0) {
                    $pdo->prepare("UPDATE agency_contracts SET agency_id=?,contract_ref=?,type=?,fee_percent=?,fee_hourly=?,start_date=?,end_date=?,auto_renewal=?,notice_days=?,status=?,notes=? WHERE id=?")->execute([...$data,$id]);
                    $contract_id = $id;
                } else {
                    $pdo->prepare("INSERT INTO agency_contracts (agency_id,contract_ref,type,fee_percent,fee_hourly,start_date,end_date,auto_renewal,notice_days,status,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")->execute([...$data,$u_id]);
                    $contract_id = (int)$pdo->lastInsertId();
                }
                // Upload documento firmato
                if (isset($_FILES['contract_file']) && $_FILES['contract_file']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['contract_file'];
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    if (in_array($ext,['pdf','doc','docx','jpg','jpeg','png','zip']) && $file['size']<=15*1024*1024) {
                        $pdo->prepare("UPDATE contract_documents SET status='superseded', archived_at=NOW(), archived_by=? WHERE contract_id=? AND status='current'")->execute([$u_id,$contract_id]);
                        $v=$pdo->prepare("SELECT COALESCE(MAX(version),0)+1 FROM contract_documents WHERE contract_id=?");
                        $v->execute([$contract_id]); $nv=(int)$v->fetchColumn(); $v->closeCursor();
                        $fname="contr_{$contract_id}_v{$nv}_".time().".$ext";
                        if (move_uploaded_file($file['tmp_name'], $upload_dir.$fname)) {
                            $pdo->prepare("INSERT INTO contract_documents (contract_id,version,status,file_name,original_name,file_size,mime_type,title,signed_date,notes,uploaded_by) VALUES (?,?,'current',?,?,?,?,?,?,?,?)")
                                ->execute([$contract_id,$nv,$fname,$file['name'],$file['size'],$file['type'],
                                    trim($_POST['doc_title']??'')?:"Contratto v$nv",
                                    !empty($_POST['signed_date'])?$_POST['signed_date']:null,
                                    trim($_POST['doc_notes']??'')?:null,$u_id]);
                            $pdo->prepare("UPDATE agency_contracts SET document_path=? WHERE id=?")->execute([$fname,$contract_id]);
                        }
                    }
                }
                $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Contratto ".($id>0?'aggiornato':'creato').".</div>";
            } catch (Exception $e) {
                $_SESSION['flash_msg'] = "<div class='alert alert-danger'>".h($e->getMessage())."</div>";
            }
            redirect('recruiting_contratti');
        }
    }

    if ($action === 'upload_version' && isset($_FILES['version_file']) && $_FILES['version_file']['error'] === UPLOAD_ERR_OK) {
        $cid = (int)$_POST['contract_id'];
        $file = $_FILES['version_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($ext,['pdf','doc','docx','jpg','jpeg','png','zip']) && $file['size']<=15*1024*1024) {
            $pdo->prepare("UPDATE contract_documents SET status='superseded', archived_at=NOW(), archived_by=? WHERE contract_id=? AND status='current'")->execute([$u_id,$cid]);
            $v=$pdo->prepare("SELECT COALESCE(MAX(version),0)+1 FROM contract_documents WHERE contract_id=?");
            $v->execute([$cid]); $nv=(int)$v->fetchColumn(); $v->closeCursor();
            $fname="contr_{$cid}_v{$nv}_".time().".$ext";
            if (move_uploaded_file($file['tmp_name'], $upload_dir.$fname)) {
                $pdo->prepare("INSERT INTO contract_documents (contract_id,version,status,file_name,original_name,file_size,mime_type,title,signed_date,notes,uploaded_by) VALUES (?,?,'current',?,?,?,?,?,?,?,?)")
                    ->execute([$cid,$nv,$fname,$file['name'],$file['size'],$file['type'],
                        trim($_POST['doc_title']??'')?:"Aggiornamento v$nv",
                        !empty($_POST['signed_date'])?$_POST['signed_date']:null,
                        trim($_POST['doc_notes']??'')?:null,$u_id]);
                $pdo->prepare("UPDATE agency_contracts SET document_path=? WHERE id=?")->execute([$fname,$cid]);
            }
        }
        $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Nuova versione caricata — precedente archiviata.</div>";
        redirect('recruiting_contratti');
    }
}

// ── Output HTML ──────────────────────────────────────────────────────────────
require_once('header.php');
$msg='';
if (!empty($_SESSION['flash_msg'])) { $msg=$_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }

$agencies=$pdo->query("SELECT id,name FROM agencies WHERE status='active' ORDER BY name")->fetchAll();
$wh=$f_agency?"WHERE ac.agency_id=$f_agency":"";
$contracts=$pdo->query(
    "SELECT ac.*, a.name agency_name, DATEDIFF(ac.end_date,CURDATE()) dd_left,
     (SELECT COUNT(*) FROM contract_documents cd WHERE cd.contract_id=ac.id) doc_count,
     (SELECT cd2.file_name FROM contract_documents cd2 WHERE cd2.contract_id=ac.id AND cd2.status='current' LIMIT 1) current_doc
     FROM agency_contracts ac JOIN agencies a ON ac.agency_id=a.id $wh ORDER BY ac.status, ac.end_date"
)->fetchAll();

$edit=null;
if(isset($_GET['edit'])){
    $es=$pdo->prepare("SELECT * FROM agency_contracts WHERE id=?");
    $es->execute([(int)$_GET['edit']]); $edit=$es->fetch(); $es->closeCursor();
}

$tot_active=(int)$pdo->query("SELECT COUNT(*) FROM agency_contracts WHERE status='active'")->fetchColumn();
$exp60=(int)$pdo->query("SELECT COUNT(*) FROM agency_contracts WHERE status='active' AND end_date<=DATE_ADD(CURDATE(),INTERVAL 60 DAY)")->fetchColumn();
$tot_docs=0; try{$tot_docs=(int)$pdo->query("SELECT COUNT(*) FROM contract_documents")->fetchColumn();}catch(\Exception $e){}
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
  <div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:3px"><i class="fa-solid fa-file-signature" style="color:var(--p);margin-right:10px"></i>Contratti agenzie</h1>
    <p style="color:var(--muted);font-size:13px">Upload contratti firmati con versionamento e archivio storico</p>
  </div>
  <a href="<?= qs_self_safe(['edit'=>'0']) ?>" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Nuovo contratto</a>
</div>

<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px">
  <div class="stat-card" style="border-color:var(--p)"><div class="sl">Contratti attivi</div><div class="sv" style="color:var(--p)"><?=$tot_active?></div></div>
  <div class="stat-card" style="border-color:var(--warning)"><div class="sl">In scadenza (60gg)</div><div class="sv" style="color:var(--warning)"><?=$exp60?></div></div>
  <div class="stat-card" style="border-color:#8b5cf6"><div class="sl">Agenzie</div><div class="sv" style="color:#8b5cf6"><?=count($agencies)?></div></div>
  <div class="stat-card" style="border-color:#059669"><div class="sl">Documenti archiviati</div><div class="sv" style="color:#059669"><?=$tot_docs?></div></div>
</div>
<?=$msg?>

<form method="GET" class="filter-bar">
<?php if (!empty($_GET["r"])): ?><input type="hidden" name="r" value="<?= htmlspecialchars($_GET["r"], ENT_QUOTES, "UTF-8") ?>"><?php endif; ?>
  <div class="fg"><label>Agenzia</label><select name="agency_id"><option value="0">Tutte</option>
  <?php foreach($agencies as $ag):?><option value="<?=$ag['id']?>" <?=$f_agency==$ag['id']?'selected':''?>><?=h($ag['name'])?></option><?php endforeach;?>
  </select></div><button type="submit" class="btn btn-primary">Filtra</button>
  <?php if($f_agency):?><a href="recruiting_contratti.php" class="btn">Reset</a><?php endif;?>

  <div class="fg" style="margin-left:auto">
    <label style="visibility:hidden">.</label>
    <button type="button" onclick="window.print()" class="btn btn-sm" title="Stampa pagina"
            style="background:#fef3c7;color:#92400e;border-color:#fde68a">
      <i class="fa-solid fa-print"></i> Stampa
    </button>
  </div>
</form>

<?php if(isset($_GET['edit'])):?>
<div class="card" style="margin-bottom:22px;border-color:var(--p);background:#f0f9ff">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-pen" style="color:var(--p)"></i> <?=$edit?'Modifica':'Nuovo'?> contratto</span><a href="recruiting_contratti.php" class="btn btn-sm">Annulla</a></div>
  <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
    <input type="hidden" name="action" value="save"><input type="hidden" name="contract_id" value="<?=$edit['id']??0?>">
    <div class="grid-2">
      <div class="form-group span-2"><label>Agenzia *</label><select name="agency_id" required><option value="">Seleziona...</option>
        <?php foreach($agencies as $ag):?><option value="<?=$ag['id']?>" <?=($edit['agency_id']??0)==$ag['id']?'selected':''?>><?=h($ag['name'])?></option><?php endforeach;?></select></div>
      <div class="form-group"><label>Rif. contratto</label><input type="text" name="contract_ref" value="<?=h($edit['contract_ref']??'')?>"></div>
      <div class="form-group"><label>Tipo</label><select name="type"><?php foreach(['Quadro','Puntuale','Somministrazione'] as $t):?><option value="<?=$t?>" <?=($edit['type']??'')===$t?'selected':''?>><?=$t?></option><?php endforeach;?></select></div>
      <div class="form-group"><label>Fee successo (%)</label><input type="number" name="fee_percent" step="0.1" min="0" max="100" value="<?=h($edit['fee_percent']??'')?>"></div>
      <div class="form-group"><label>Margine orario (€)</label><input type="number" name="fee_hourly" step="0.01" min="0" value="<?=h($edit['fee_hourly']??'')?>"></div>
      <div class="form-group"><label>Data inizio *</label><input type="date" name="start_date" value="<?=h($edit['start_date']??'')?>" required></div>
      <div class="form-group"><label>Data fine</label><input type="date" name="end_date" value="<?=h($edit['end_date']??'')?>"></div>
      <div class="form-group"><label>Preavviso (gg)</label><input type="number" name="notice_days" min="0" value="<?=h($edit['notice_days']??30)?>"></div>
      <div class="form-group"><label>Stato</label><select name="status">
        <option value="active" <?=($edit['status']??'')==='active'?'selected':''?>>Attivo</option>
        <option value="expired" <?=($edit['status']??'')==='expired'?'selected':''?>>Scaduto</option>
        <option value="terminated" <?=($edit['status']??'')==='terminated'?'selected':''?>>Terminato</option></select></div>
      <div style="display:flex;align-items:center;gap:10px;padding-top:20px"><input type="checkbox" name="auto_renewal" id="ar_chk" <?=!empty($edit['auto_renewal'])?'checked':''?> style="width:18px;height:18px"><label for="ar_chk" style="cursor:pointer">Rinnovo automatico</label></div>
      <div class="form-group span-2"><label>Note</label><textarea name="notes" rows="2"><?=h($edit['notes']??'')?></textarea></div>
    </div>
    <!-- Upload contratto firmato -->
    <div style="background:#ecfdf5;padding:16px;border-radius:10px;border:1px solid #a7f3d0;margin:14px 0">
      <div style="font-size:10px;font-weight:700;color:#059669;text-transform:uppercase;margin-bottom:10px"><i class="fa-solid fa-file-signature"></i> Documento contratto firmato</div>
      <?php if($edit && $edit['document_path']):?>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;padding:8px 12px;background:#fff;border-radius:8px;border:1px solid #d1fae5">
        <i class="fa-solid fa-file-pdf" style="color:#059669;font-size:18px"></i>
        <div style="flex:1"><div style="font-size:12px;font-weight:600"><?=h($edit['document_path'])?></div><div style="font-size:10px;color:var(--muted)">Versione corrente</div></div>
        <a href="uploads/contratti/<?=h($edit['document_path'])?>" target="_blank" class="btn btn-sm" style="background:#059669;color:#fff;border:none"><i class="fa-solid fa-download"></i></a>
      </div>
      <?php endif;?>
      <div class="grid-2">
        <div class="form-group" style="margin:0"><label>Allega contratto firmato</label><input type="file" name="contract_file" accept=".pdf,.doc,.docx,.jpg,.png,.zip">
          <div style="font-size:10px;color:var(--muted);margin-top:3px"><?=$edit&&$edit['document_path']?'La versione precedente verrà archiviata automaticamente':'PDF, DOC, DOCX, JPG, PNG, ZIP — max 15MB'?></div></div>
        <div class="form-group" style="margin:0"><label>Data firma</label><input type="date" name="signed_date"></div>
      </div>
      <div class="grid-2" style="margin-top:8px">
        <div class="form-group" style="margin:0"><label>Titolo versione</label><input type="text" name="doc_title" placeholder="Es. Rinnovo 2025"></div>
        <div class="form-group" style="margin:0"><label>Note documento</label><input type="text" name="doc_notes"></div>
      </div>
    </div>
    <div style="display:flex;gap:10px;margin-top:6px">
      <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:12px"><i class="fa-solid fa-floppy-disk"></i> <?=$edit?'Aggiorna':'Crea'?></button>
      <a href="recruiting_contratti.php" class="btn" style="flex:1;justify-content:center;padding:12px">Annulla</a>
    </div>
  </form>
</div>
<?php endif;?>

<!-- Tabella -->
<div class="card" style="overflow-x:auto">
<?php require_once __DIR__ . '/app/ListFilter.php'; ListFilter::render('recruiting_contratti', '#tContr', ['export_filename' => 'recruiting_contratti', 'title' => 'Contratti agenzie']); ?>
<table class="data-table" id="tContr">
<thead><tr><th>Agenzia</th><th>Rif.</th><th>Tipo</th><th>Fee %</th><th>€/h</th><th>Validità</th><th>Gg</th><th>Rinnovo</th><th>Documento</th><th>Stato</th><th class="no-print">Azioni</th></tr></thead>
<tbody>
<?php if(empty($contracts)):?><tr><td colspan="11" style="text-align:center;padding:40px;color:var(--muted)">Nessun contratto.</td></tr><?php endif;?>
<?php foreach($contracts as $c):
  $dl=$c['dd_left']!==null?(int)$c['dd_left']:null;
  $dlc=$c['end_date']?($dl<=30?'var(--danger)':($dl<=60?'var(--warning)':'var(--success)')):'var(--muted)';
  $rbg=($dl!==null&&$dl<=60&&$c['status']==='active')?'background:#fffbeb;':'';
?>
<tr style="<?=$rbg?>">
  <td><strong><?=h($c['agency_name'])?></strong></td>
  <td><code style="font-size:11px"><?=h($c['contract_ref']??'—')?></code></td>
  <td><span class="badge badge-info" style="font-size:9px"><?=h($c['type'])?></span></td>
  <td style="text-align:center;font-weight:700"><?=$c['fee_percent']!==null?number_format($c['fee_percent'],1).'%':'—'?></td>
  <td style="text-align:center"><?=$c['fee_hourly']!==null?'€'.number_format($c['fee_hourly'],2):'—'?></td>
  <td style="font-size:12px"><?=format_date($c['start_date'])?> → <?=$c['end_date']?format_date($c['end_date']):'Open'?></td>
  <td style="text-align:center;font-weight:800;color:<?=$dlc?>"><?=$c['end_date']?($dl??'—'):'∞'?></td>
  <td style="text-align:center"><?=$c['auto_renewal']?'<span class="badge badge-success" style="font-size:9px">Sì</span>':'<span class="badge badge-neutral" style="font-size:9px">No</span>'?></td>
  <td style="text-align:center;white-space:nowrap">
    <?php if($c['current_doc']):?><a href="uploads/contratti/<?=h($c['current_doc'])?>" target="_blank" class="btn btn-sm" style="background:#ecfdf5;color:#059669;border-color:#a7f3d0" title="Scarica corrente"><i class="fa-solid fa-file-pdf"></i></a><?php endif;?>
    <?php if((int)($c['doc_count']??0)>0):?>
    <button onclick="openHist(<?=$c['id']?>,'<?=h(addslashes($c['agency_name']))?>')" class="btn btn-sm" style="background:#f3e8ff;color:#7c3aed;border-color:#d8b4fe" title="Storico (<?=$c['doc_count']?>)"><i class="fa-solid fa-clock-rotate-left"></i> <?=$c['doc_count']?></button>
    <?php endif;?>
  </td>
  <td style="text-align:center"><span class="badge <?=$c['status']==='active'?'badge-success':($c['status']==='expired'?'badge-danger':'badge-neutral')?>" style="font-size:9px"><?=ucfirst(h($c['status']))?></span></td>
  <td style="text-align:center;white-space:nowrap" class="no-print">
    <a href="<?= qs_self_safe(['edit'=>''.($c['id']).'']) ?>" class="btn btn-blue btn-sm"><i class="fa-solid fa-pen"></i></a>
    <?php if($c['status']==='active'):?>
    <form method="POST" style="display:inline" onsubmit="return confirm('Terminare?')">
        <?= csrf_field() ?><input type="hidden" name="action" value="terminate"><input type="hidden" name="contract_id" value="<?=$c['id']?>"><button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-ban"></i></button></form>
    <?php endif;?>
  </td>
</tr>
<?php endforeach;?>
</tbody></table></div>

<!-- Modal storico -->
<div id="mHist" class="modal-overlay">
<div class="modal-box" style="width:700px">
  <div style="display:flex;justify-content:space-between;margin-bottom:18px">
    <h3 id="mHT" style="margin:0;font-size:16px"><i class="fa-solid fa-clock-rotate-left" style="color:#7c3aed"></i> Storico versioni</h3>
    <button onclick="document.getElementById('mHist').style.display='none'" style="border:none;background:none;font-size:20px;cursor:pointer">&times;</button>
  </div>
  <div id="mHB" style="max-height:350px;overflow-y:auto"><div style="text-align:center;padding:40px;color:var(--muted)"><i class="fa-solid fa-spinner fa-spin"></i></div></div>
  <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border)">
    <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:10px"><i class="fa-solid fa-cloud-arrow-up" style="color:var(--p)"></i> Carica nuova versione</div>
    <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?><input type="hidden" name="action" value="upload_version"><input type="hidden" name="contract_id" id="mH_cid" value="0">
      <div class="grid-2">
        <div class="form-group" style="margin:0"><label>File *</label><input type="file" name="version_file" required accept=".pdf,.doc,.docx,.jpg,.png,.zip"></div>
        <div class="form-group" style="margin:0"><label>Data firma</label><input type="date" name="signed_date"></div>
      </div>
      <div class="grid-2" style="margin-top:8px">
        <div class="form-group" style="margin:0"><label>Titolo</label><input type="text" name="doc_title" placeholder="Es. Rinnovo 2025"></div>
        <div class="form-group" style="margin:0"><label>Note</label><input type="text" name="doc_notes"></div>
      </div>
      <button type="submit" class="btn btn-primary btn-sm" style="margin-top:10px"><i class="fa-solid fa-upload"></i> Carica e archivia precedente</button>
    </form>
  </div>
</div></div>

<script>
$('#tContr').DataTable({language:{search:"Cerca:"},pageLength:25,order:[[5,'asc']]});
function openHist(cid,name){
  document.getElementById('mHT').innerHTML='<i class="fa-solid fa-clock-rotate-left" style="color:#7c3aed"></i> Storico: '+name;
  document.getElementById('mH_cid').value=cid;
  var b=document.getElementById('mHB');
  b.innerHTML='<div style="text-align:center;padding:40px"><i class="fa-solid fa-spinner fa-spin"></i></div>';
  document.getElementById('mHist').style.display='flex';
  fetch('api_contract_docs.php?contract_id='+cid).then(r=>r.json()).then(function(docs){
    if(!docs.length){b.innerHTML='<div style="text-align:center;padding:30px;color:var(--muted)">Nessun documento.</div>';return;}
    var h='';
    docs.forEach(function(d){
      var sc=d.status==='current'?'#059669':'#94a3b8';
      var sb=d.status==='current'?'#ecfdf5':'#f8fafc';
      var sl=d.status==='current'?'CORRENTE':'ARCHIVIATO';
      h+='<div style="padding:12px;border-radius:10px;background:'+sb+';border:1px solid '+sc+'20;margin-bottom:10px;display:flex;gap:14px;align-items:center">';
      h+='<div style="width:38px;height:38px;border-radius:8px;background:'+sc+'20;color:'+sc+';display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;flex-shrink:0">v'+d.version+'</div>';
      h+='<div style="flex:1"><div style="font-weight:700;font-size:13px">'+(d.title||d.original_name)+'</div>';
      h+='<div style="font-size:11px;color:#64748b">';
      h+='<span style="margin-right:10px">Caricato: '+d.created_at+'</span>';
      if(d.signed_date) h+='<span style="margin-right:10px">Firmato: '+d.signed_date+'</span>';
      if(d.archived_at) h+='<span>Archiviato: '+d.archived_at+'</span>';
      h+='</div></div>';
      h+='<span style="padding:2px 8px;border-radius:5px;background:'+sc+'20;color:'+sc+';font-size:9px;font-weight:800">'+sl+'</span>';
      h+='<a href="uploads/contratti/'+d.file_name+'" target="_blank" class="btn btn-sm"><i class="fa-solid fa-download"></i></a>';
      h+='</div>';
    });
    b.innerHTML=h;
  }).catch(function(){b.innerHTML='<div style="color:var(--danger);text-align:center;padding:30px">Errore.</div>';});
}
</script>
<?php require_once('footer.php'); ?>
