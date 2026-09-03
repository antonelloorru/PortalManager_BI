<?php
/**
 * certV 2.0 — recruiting_agenzie.php   Anagrafica agenzie di selezione
 */
require_once('access_control.php');
require_once('header.php');

$u_id      = (int)$_SESSION['user_id'];
$u_role    = (int)($_SESSION['role_id'] ?? 99);
$can_edit  = can('edit');
$can_del   = can('delete');
$can_see_fees = can('view','recruiting_contratti.php');
$msg       = '';

// ── CRUD ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_agency') {
        $id = (int)($_POST['agency_id'] ?? 0);
        $data = [
            trim($_POST['name']),
            $_POST['type'],
            $_POST['website'] ?: null,
            $_POST['email']   ?: null,
            $_POST['phone']   ?: null,
            $_POST['address'] ?: null,
            $_POST['vat_number'] ?: null,
            $_POST['status'],
            $_POST['rating'] ? (int)$_POST['rating'] : null,
            $_POST['notes'] ?: null,
        ];
        if ($id > 0) {
            $pdo->prepare(
                "UPDATE agencies SET name=?,type=?,website=?,email=?,phone=?,address=?,vat_number=?,status=?,rating=?,notes=? WHERE id=?"
            )->execute([...$data, $id]);
            header("Location: recruiting_agenzie.php?id=$agency_id&updated=1" . (!empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '')); exit();
        } else {
            $pdo->prepare(
                "INSERT INTO agencies (name,type,website,email,phone,address,vat_number,status,rating,notes) VALUES (?,?,?,?,?,?,?,?,?,?)"
            )->execute($data);
            $msg = "<div class='alert alert-success'>Agenzia aggiunta.</div>";
        }
        write_log('Agencies','success',"Agenzia salvata id=$id",$u_id);
    }

    if ($action === 'save_contact') {
        $cid = (int)($_POST['contact_id'] ?? 0);
        $d   = [
            (int)$_POST['agency_id'],
            trim($_POST['first_name']), trim($_POST['last_name']),
            $_POST['role']  ?: null, $_POST['email'] ?: null,
            $_POST['phone'] ?: null, isset($_POST['is_primary']) ? 1 : 0,
            $_POST['notes'] ?: null,
        ];
        if ($cid > 0) {
            $pdo->prepare(
                "UPDATE agency_contacts SET agency_id=?,first_name=?,last_name=?,role=?,email=?,phone=?,is_primary=?,notes=? WHERE id=?"
            )->execute([...$d, $cid]);
        } else {
            $pdo->prepare(
                "INSERT INTO agency_contacts (agency_id,first_name,last_name,role,email,phone,is_primary,notes) VALUES (?,?,?,?,?,?,?,?)"
            )->execute($d);
        }
        $msg = "<div class='alert alert-success'>Contatto salvato.</div>";
    }

    if ($action === 'delete_agency' && $can_del) {
        $pdo->prepare("DELETE FROM agencies WHERE id=?")->execute([(int)$_POST['agency_id']]);
        $msg = "<div class='alert alert-success'>Agenzia eliminata.</div>";
        header("Location: recruiting_agenzie.php?msg=deleted" . (!empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '')); exit();
    }
}

// ── DATI ──────────────────────────────────────────────────────────────────────
$sel_id = (int)($_GET['id'] ?? 0);

$agencies = $pdo->query(
    "SELECT a.*,
            (SELECT COUNT(*) FROM agency_contacts ac WHERE ac.agency_id=a.id) contact_count,
            (SELECT COUNT(*) FROM agency_contracts ac WHERE ac.agency_id=a.id AND ac.status='active') contract_active,
            (SELECT COUNT(*) FROM candidates c WHERE c.agency_id=a.id) cand_total,
            (SELECT COUNT(*) FROM candidates c WHERE c.agency_id=a.id AND c.status='hired') cand_hired
     FROM agencies a ORDER BY a.name"
)->fetchAll();

$agency = null; $contacts = []; $contracts = [];
if ($sel_id > 0) {
    $s = $pdo->prepare("SELECT * FROM agencies WHERE id=?");
    $s->execute([$sel_id]);
    $agency = $s->fetch();

    $cs = $pdo->prepare("SELECT * FROM agency_contacts WHERE agency_id=? ORDER BY is_primary DESC, last_name");
    $cs->execute([$sel_id]);
    $contacts = $cs->fetchAll();

    $ct = $pdo->prepare("SELECT * FROM agency_contracts WHERE agency_id=? ORDER BY start_date DESC");
    $ct->execute([$sel_id]);
    $contracts = $ct->fetchAll();
}
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
  <div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:3px">
      <i class="fa-solid fa-building" style="color:var(--p);margin-right:10px"></i>Anagrafica agenzie
    </h1>
    <p style="color:var(--muted);font-size:13px"><?=count($agencies)?> agenzie registrate</p>
  </div>
  <?php if($can_edit): ?>
  <button onclick="openAgModal()" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Nuova agenzia</button>
  <?php endif; ?>
</div>

<?php
if (!isset($msg) || !$msg) {
    if (isset($_GET['saved']))   $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Salvato con successo.</div>";
    if (isset($_GET['updated'])) $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Aggiornato con successo.</div>";
    if (isset($_GET['msg']) && $_GET['msg']==='deleted') $msg = "<div class='alert alert-success'>Agenzia eliminata.</div>";
}
?>
<?=$msg?>

<div style="display:grid;grid-template-columns:280px 1fr;gap:24px">

  <!-- Sidebar -->
  <div class="card" style="padding:0;height:fit-content;position:sticky;top:80px">
    <div style="padding:11px 16px;background:#f8fafc;border-bottom:1px solid var(--border);font-size:10px;font-weight:700;text-transform:uppercase;color:var(--muted)">
      Elenco agenzie
    </div>
    <?php foreach($agencies as $ag):
      $is_sel = ($sel_id == $ag['id']);
      $st_dot = match($ag['status']) { 'active'=>'var(--success)','paused'=>'var(--warning)',default=>'var(--danger)' };
      $cr = $ag['cand_total'] > 0 ? round($ag['cand_hired']/$ag['cand_total']*100) : 0;
    ?>
    <a href="<?= qs_self_safe(['id'=>''.($ag['id']).'']) ?>"
       style="display:flex;align-items:center;gap:10px;padding:12px 16px;text-decoration:none;color:#1e293b;border-bottom:1px solid #f8fafc;<?=$is_sel?'background:#e0f2fe;border-left:3px solid var(--p);':''?>">
      <div style="width:9px;height:9px;border-radius:50%;background:<?=$st_dot?>;flex-shrink:0"></div>
      <div style="flex:1;min-width:0">
        <div style="font-weight:<?=$is_sel?700:600?>;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h($ag['name'])?></div>
        <div style="font-size:10px;color:var(--muted);margin-top:1px"><?=h($ag['type'])?> · <?=$ag['cand_hired']?>/<?=$ag['cand_total']?> assunti (<?=$cr?>%)</div>
      </div>
      <?php if($ag['rating']): ?>
      <div style="font-size:11px;color:#f59e0b;flex-shrink:0"><?=str_repeat('★',$ag['rating'])?></div>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
    <?php if(empty($agencies)): ?>
    <div style="padding:24px;text-align:center;color:var(--muted);font-size:13px">Nessuna agenzia. Aggiungine una.</div>
    <?php endif; ?>
  </div>

  <!-- Dettaglio -->
  <div>
    <?php if(!$agency): ?>
    <div style="text-align:center;padding:80px;background:#fff;border-radius:12px;border:1px dashed var(--border);color:var(--muted)">
      <i class="fa-solid fa-building" style="font-size:48px;margin-bottom:16px;display:block;opacity:.3"></i>
      <h3 style="margin-bottom:8px;color:#1e293b">Seleziona un'agenzia</h3>
      <p style="font-size:13px">Scegli dalla lista per vedere dettagli, referenti e contratti.</p>
    </div>
    <?php else: ?>

    <!-- Header agenzia -->
    <div class="card" style="margin-bottom:20px">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px">
        <div>
          <h2 style="margin:0 0 8px;font-size:19px"><?=h($agency['name'])?></h2>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <span class="badge badge-info"><?=h($agency['type'])?></span>
            <span class="badge <?=$agency['status']==='active'?'badge-success':($agency['status']==='paused'?'badge-warning':'badge-danger')?>"><?=ucfirst(h($agency['status']))?></span>
            <?php if($agency['rating']): ?>
            <span style="color:#f59e0b;font-size:13px"><?=str_repeat('★',$agency['rating'])?><?=str_repeat('☆',5-$agency['rating'])?></span>
            <?php endif; ?>
          </div>
          <?php if($agency['website']): ?>
          <a href="<?=h($agency['website'])?>" target="_blank" style="font-size:12px;color:var(--p);display:block;margin-top:8px">
            <i class="fa-solid fa-globe" style="margin-right:5px"></i><?=h($agency['website'])?>
          </a>
          <?php endif; ?>
        </div>
        <div style="display:flex;gap:8px">
          <?php if($can_edit): ?>
          <button onclick='openAgModal(<?=json_encode($agency,JSON_HEX_APOS|JSON_HEX_QUOT)?>)' class="btn btn-blue btn-sm">
            <i class="fa-solid fa-pen"></i> Modifica
          </button>
          <?php endif; ?>
          <?php if($can_del): ?>
          <form method="POST" onsubmit="return confirm('Eliminare questa agenzia e tutti i suoi dati?')">
        <?= csrf_field() ?>
            <input type="hidden" name="action"    value="delete_agency">
            <input type="hidden" name="agency_id" value="<?=$sel_id?>">
            <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i></button>
          </form>
          <?php endif; ?>
        </div>
      </div>

      <!-- KPI agenzia -->
      <?php
      $cv_tot  = (int)$pdo->prepare("SELECT COUNT(*) FROM candidates WHERE agency_id=?")->execute([$sel_id]) ? (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE agency_id=$sel_id")->fetchColumn() : 0;
      $hired_n = (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE agency_id=$sel_id AND status='hired'")->fetchColumn();
      $pip_n   = (int)$pdo->query("SELECT COUNT(*) FROM candidates c JOIN candidate_applications ca ON c.id=ca.candidate_id WHERE c.agency_id=$sel_id AND ca.stage NOT IN('hired','rejected')")->fetchColumn();
      $cr_pct  = $cv_tot > 0 ? round($hired_n/$cv_tot*100) : 0;
      ?>
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px">
        <?php foreach([['CV totali',$cv_tot,'var(--p)'],['In pipeline',$pip_n,'#8b5cf6'],['Assunti',$hired_n,'var(--success)'],['Conversion',$cr_pct.'%',$cr_pct>=20?'var(--success)':($cr_pct>=10?'var(--warning)':'var(--danger)')]] as [$lb,$val,$col]): ?>
        <div style="background:#f8fafc;padding:12px;border-radius:8px;text-align:center">
          <div style="font-size:20px;font-weight:800;color:<?=$col?>"><?=$val?></div>
          <div style="font-size:10px;color:var(--muted);font-weight:700;text-transform:uppercase;margin-top:2px"><?=$lb?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if($agency['notes']): ?>
      <div style="margin-top:14px;background:#f8fafc;border-radius:8px;padding:12px;font-size:13px;color:#475569;border-left:3px solid var(--border)">
        <?=h($agency['notes'])?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Referenti -->
    <div class="card" style="margin-bottom:20px">
      <div class="card-header">
        <span class="card-title"><i class="fa-solid fa-address-book" style="color:var(--p)"></i> Referenti (<?=count($contacts)?>)</span>
        <?php if($can_edit): ?>
        <button onclick="openCtModal(<?=$sel_id?>)" class="btn btn-blue btn-sm"><i class="fa-solid fa-plus"></i> Aggiungi</button>
        <?php endif; ?>
      </div>
      <?php if(empty($contacts)): ?>
      <div style="text-align:center;color:var(--muted);padding:20px;font-size:13px">Nessun referente registrato.</div>
      <?php else: ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px">
        <?php foreach($contacts as $ct): ?>
        <div style="background:#f8fafc;border-radius:10px;padding:14px;border:1px solid var(--border);position:relative">
          <?php if($ct['is_primary']): ?><span class="badge badge-info" style="position:absolute;top:10px;right:10px;font-size:8px">Principale</span><?php endif; ?>
          <div style="font-weight:700;font-size:13px;margin-bottom:2px"><?=h($ct['first_name'].' '.$ct['last_name'])?></div>
          <?php if($ct['role']): ?><div style="font-size:11px;color:var(--muted);margin-bottom:8px"><?=h($ct['role'])?></div><?php endif; ?>
          <?php if($ct['email']): ?><div style="font-size:12px"><i class="fa-solid fa-envelope" style="width:14px;color:var(--muted)"></i> <a href="mailto:<?=h($ct['email'])?>" style="color:var(--p)"><?=h($ct['email'])?></a></div><?php endif; ?>
          <?php if($ct['phone']): ?><div style="font-size:12px;margin-top:2px"><i class="fa-solid fa-phone" style="width:14px;color:var(--muted)"></i> <?=h($ct['phone'])?></div><?php endif; ?>
          <?php if($can_edit): ?>
          <button onclick='openCtModal(<?=$sel_id?>,<?=json_encode($ct,JSON_HEX_APOS|JSON_HEX_QUOT)?>)' class="btn btn-sm" style="margin-top:10px;width:100%;justify-content:center;font-size:10px"><i class="fa-solid fa-pen"></i> Modifica</button>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Contratti preview -->
    <?php if(!empty($contracts)): ?>
    <div class="card">
      <div class="card-header">
        <span class="card-title"><i class="fa-solid fa-file-signature" style="color:var(--p)"></i> Contratti (<?=count($contracts)?>)</span>
        <?php if($can_see_fees): ?>
        <a href="recruiting_contratti.php?f_ag=<?=$sel_id?>" class="btn btn-sm">Gestisci →</a>
        <?php endif; ?>
      </div>
      <?php require_once __DIR__ . '/app/ListFilter.php'; ListFilter::render('recruiting_agenzie', '#lf-table-recruiting_agenzie', ['export_filename' => 'recruiting_agenzie', 'title' => 'Agenzie recruiting']); ?>
<table id="lf-table-recruiting_agenzie" class="data-table">
        <thead>
          <tr>
            <th>Rif.</th><th>Tipo</th>
            <?php if($can_see_fees): ?><th>Fee</th><?php endif; ?>
            <th>Validità</th><th style="text-align:center">Stato</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($contracts as $ct):
          $dl = $ct['end_date'] ? days_diff($ct['end_date']) : null;
          $is_exp = ($dl !== null && $dl <= 60 && $ct['status']==='active');
        ?>
        <tr <?=$is_exp?'style="background:#fffbeb"':''?>>
          <td><code style="font-size:11px"><?=h($ct['contract_ref']??'—')?></code></td>
          <td><span class="badge badge-info" style="font-size:9px"><?=h($ct['type'])?></span></td>
          <?php if($can_see_fees): ?>
          <td style="font-size:12px">
            <?=$ct['fee_percent']?number_format($ct['fee_percent'],1).'%':'—'?>
            <?=$ct['fee_hourly']?' / €'.number_format($ct['fee_hourly'],2).'/h':''?>
          </td>
          <?php endif; ?>
          <td style="font-size:12px"><?=format_date($ct['start_date'])?> → <?=$ct['end_date']?format_date($ct['end_date']):'Open'?>
            <?php if($is_exp): ?><span class="badge badge-warning" style="font-size:8px;margin-left:4px"><?=$dl?>gg</span><?php endif; ?>
          </td>
          <td style="text-align:center">
            <span class="badge <?=$ct['status']==='active'?'badge-success':($ct['status']==='expired'?'badge-danger':'badge-neutral')?>" style="font-size:9px"><?=ucfirst($ct['status'])?></span>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php endif; // fine $agency ?>
  </div>
</div>

<!-- Modal agenzia -->
<div id="mAg" class="modal-overlay" style="z-index:1000">
  <div class="modal-box" style="width:600px">
    <div style="display:flex;justify-content:space-between;margin-bottom:20px">
      <h3 style="margin:0;font-size:16px" id="mAgTitle">Nuova agenzia</h3>
      <button onclick="document.getElementById('mAg').style.display='none'" style="border:none;background:none;font-size:20px;cursor:pointer;color:var(--muted)">&times;</button>
    </div>
    <form method="POST">
        <?= csrf_field() ?>
      <input type="hidden" name="action"    value="save_agency">
      <input type="hidden" name="agency_id" id="ag_id" value="0">
      <div class="grid-2">
        <div class="form-group span-2"><label>Nome *</label><input type="text" name="name" id="ag_name" required></div>
        <div class="form-group"><label>Tipo</label>
          <select name="type" id="ag_type">
            <?php foreach(['Headhunting','Somministrazione','RPO','Misto'] as $t): ?><option value="<?=$t?>"><?=$t?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Stato</label>
          <select name="status" id="ag_status">
            <option value="active">Attiva</option><option value="paused">In pausa</option><option value="blacklisted">Blacklist</option>
          </select>
        </div>
        <div class="form-group"><label>Email</label><input type="email" name="email" id="ag_email"></div>
        <div class="form-group"><label>Telefono</label><input type="text" name="phone" id="ag_phone"></div>
        <div class="form-group"><label>Sito web</label><input type="text" name="website" id="ag_website"></div>
        <div class="form-group"><label>P. IVA</label><input type="text" name="vat_number" id="ag_vat"></div>
        <div class="form-group"><label>Rating (stelle)</label>
          <select name="rating" id="ag_rating">
            <option value="">—</option>
            <?php for($i=1;$i<=5;$i++): ?><option value="<?=$i?>"><?=str_repeat('★',$i)?></option><?php endfor; ?>
          </select>
        </div>
        <div class="form-group"><label>Indirizzo</label><input type="text" name="address" id="ag_address"></div>
        <div class="form-group span-2"><label>Note</label><textarea name="notes" id="ag_notes" rows="2"></textarea></div>
      </div>
      <div style="display:flex;gap:10px;margin-top:6px">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:12px">Salva</button>
        <button type="button" onclick="document.getElementById('mAg').style.display='none'" class="btn" style="flex:1;justify-content:center;padding:12px">Annulla</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal contatto -->
<div id="mCt" class="modal-overlay" style="z-index:1001">
  <div class="modal-box" style="width:480px">
    <div style="display:flex;justify-content:space-between;margin-bottom:20px">
      <h3 style="margin:0;font-size:16px">Referente agenzia</h3>
      <button onclick="document.getElementById('mCt').style.display='none'" style="border:none;background:none;font-size:20px;cursor:pointer;color:var(--muted)">&times;</button>
    </div>
    <form method="POST">
        <?= csrf_field() ?>
      <input type="hidden" name="action"     value="save_contact">
      <input type="hidden" name="contact_id" id="ct_id"        value="0">
      <input type="hidden" name="agency_id"  id="ct_agency_id">
      <div class="grid-2">
        <div class="form-group"><label>Nome *</label><input type="text" name="first_name" id="ct_fn" required></div>
        <div class="form-group"><label>Cognome *</label><input type="text" name="last_name" id="ct_ln" required></div>
        <div class="form-group"><label>Ruolo</label><input type="text" name="role" id="ct_role" placeholder="Es. Account Manager"></div>
        <div class="form-group"><label>Email</label><input type="email" name="email" id="ct_email"></div>
        <div class="form-group"><label>Telefono</label><input type="text" name="phone" id="ct_phone"></div>
        <div style="display:flex;align-items:center;gap:8px;padding-top:20px">
          <input type="checkbox" name="is_primary" id="ct_primary" style="width:18px;height:18px">
          <label for="ct_primary" style="font-size:13px;cursor:pointer">Referente principale</label>
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:14px">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:12px">Salva</button>
        <button type="button" onclick="document.getElementById('mCt').style.display='none'" class="btn" style="flex:1;justify-content:center;padding:12px">Annulla</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAgModal(d=null){
  ['name','type','status','email','phone','website','vat_number','rating','address','notes'].forEach(f=>{
    const el=document.getElementById('ag_'+f);if(el)el.value='';
  });
  document.getElementById('ag_id').value=0;
  document.getElementById('mAgTitle').textContent='Nuova agenzia';
  document.getElementById('ag_status').value='active';
  document.getElementById('ag_type').value='Misto';
  if(d){
    document.getElementById('ag_id').value=d.id;
    document.getElementById('mAgTitle').textContent='Modifica: '+d.name;
    ['name','type','status','email','phone','website','vat_number','rating','address','notes'].forEach(f=>{
      const el=document.getElementById('ag_'+f);if(el&&d[f]!==null&&d[f]!==undefined)el.value=d[f];
    });
  }
  document.getElementById('mAg').style.display='flex';
}
function openCtModal(agId,d=null){
  document.getElementById('ct_id').value=0;
  document.getElementById('ct_agency_id').value=agId;
  ['fn','ln','role','email','phone'].forEach(f=>{const el=document.getElementById('ct_'+f);if(el)el.value='';});
  document.getElementById('ct_primary').checked=false;
  if(d){
    document.getElementById('ct_id').value=d.id;
    document.getElementById('ct_fn').value=d.first_name;
    document.getElementById('ct_ln').value=d.last_name;
    document.getElementById('ct_role').value=d.role||'';
    document.getElementById('ct_email').value=d.email||'';
    document.getElementById('ct_phone').value=d.phone||'';
    document.getElementById('ct_primary').checked=!!parseInt(d.is_primary);
  }
  document.getElementById('mCt').style.display='flex';
}
</script>
<?php require_once('footer.php'); ?>
