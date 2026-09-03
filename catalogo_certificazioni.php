<?php
/**
 * certV 2.4 — catalogo_certificazioni.php
 * CRUD anagrafica certificazioni con storicizzazione modifiche e gestione TTL
 */
require_once('access_control.php');
require_once('functions.php');

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
$can_edit = can('edit'); // Admin, HR, Brand Manager

// ── Auto-migration robusta ──────────────────────────────────────────────────
foreach (['renewal_policy'=>"ALTER TABLE certifications ADD COLUMN renewal_policy VARCHAR(200) DEFAULT NULL",
          'exam_cost'=>"ALTER TABLE certifications ADD COLUMN exam_cost DECIMAL(8,2) DEFAULT NULL",
          'updated_at'=>"ALTER TABLE certifications ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP()",
          'updated_by'=>"ALTER TABLE certifications ADD COLUMN updated_by INT DEFAULT NULL"] as $col=>$sql) {
    try { $pdo->query("SELECT `$col` FROM certifications LIMIT 0")->closeCursor(); }
    catch (\Exception $e) { try { $pdo->exec($sql); } catch (\Exception $ex) {} }
}
try { $pdo->query("SELECT id FROM certification_versions LIMIT 0")->closeCursor(); }
catch (\Exception $e) {
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS certification_versions (
        id INT NOT NULL AUTO_INCREMENT, certification_id INT NOT NULL, version INT NOT NULL DEFAULT 1,
        field_changed VARCHAR(50) NOT NULL, old_value TEXT DEFAULT NULL, new_value TEXT DEFAULT NULL,
        changed_by INT DEFAULT NULL, changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
        PRIMARY KEY (id), KEY idx_cv_cert (certification_id),
        CONSTRAINT fk_cv_cert FOREIGN KEY (certification_id) REFERENCES certifications(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); }
    catch (\Exception $ex) {}
}

// ── CRUD (prima di header.php) ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $action = $_POST['action'] ?? '';
    $cert_id = (int)($_POST['cert_id'] ?? 0);

    if ($action === 'save') {
        $data = [
            'brand_id' => !empty($_POST['brand_id']) ? (int)$_POST['brand_id'] : null,
            'technology_id' => !empty($_POST['technology_id']) ? (int)$_POST['technology_id'] : null,
            'name' => trim($_POST['name'] ?? ''),
            'code' => !empty($_POST['code']) ? trim($_POST['code']) : null,
            'category' => $_POST['category'] ?? 'tecnica',
            'level' => !empty($_POST['level']) ? $_POST['level'] : null,
            'validity_months' => !empty($_POST['validity_months']) ? (int)$_POST['validity_months'] : null,
            'renewal_policy' => !empty($_POST['renewal_policy']) ? trim($_POST['renewal_policy']) : null,
            'exam_cost' => !empty($_POST['exam_cost']) ? (float)str_replace(',','.',$_POST['exam_cost']) : null,
            'description' => !empty($_POST['description']) ? trim($_POST['description']) : null,
            'notes'       => !empty($_POST['notes']) ? trim($_POST['notes']) : null,
            'exam_url' => !empty($_POST['exam_url']) ? trim($_POST['exam_url']) : null,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];

        if (!$data['name'] || !$data['brand_id']) {
            $_SESSION['flash_msg'] = "<div class='alert alert-warning'>Nome e Brand sono obbligatori.</div>";
            redirect('catalogo_certificazioni');
        }

        try {
            if ($cert_id > 0) {
                // ── STORICIZZAZIONE: confronta prima di aggiornare ──────────
                $old = $pdo->prepare("SELECT * FROM certifications WHERE id=?");
                $old->execute([$cert_id]); $old_data = $old->fetch(PDO::FETCH_ASSOC); $old->closeCursor();

                if ($old_data) {
                    // Calcola prossima versione
                    $vq = $pdo->prepare("SELECT COALESCE(MAX(version),0)+1 FROM certification_versions WHERE certification_id=?");
                    $vq->execute([$cert_id]); $next_ver = (int)$vq->fetchColumn(); $vq->closeCursor();

                    $tracked = ['name','code','category','level','validity_months','description','notes','exam_url','is_active','renewal_policy','exam_cost','brand_id','technology_id'];
                    foreach ($tracked as $f) {
                        $ov = (string)($old_data[$f] ?? '');
                        $nv = (string)($data[$f] ?? '');
                        if ($ov !== $nv) {
                            $pdo->prepare("INSERT INTO certification_versions (certification_id,version,field_changed,old_value,new_value,changed_by) VALUES (?,?,?,?,?,?)")
                                ->execute([$cert_id, $next_ver, $f, $ov ?: null, $nv ?: null, $u_id]);
                        }
                    }
                }

                $pdo->prepare("UPDATE certifications SET brand_id=?,technology_id=?,name=?,code=?,category=?,level=?,validity_months=?,renewal_policy=?,exam_cost=?,description=?,notes=?,exam_url=?,is_active=?,updated_by=? WHERE id=?")
                    ->execute([$data['brand_id'],$data['technology_id'],$data['name'],$data['code'],$data['category'],$data['level'],$data['validity_months'],$data['renewal_policy'],$data['exam_cost'],$data['description'],$data['notes'],$data['exam_url'],$data['is_active'],$u_id,$cert_id]);
                write_log('Certifications','success',"Certificazione #$cert_id aggiornata",$u_id);
                $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Certificazione aggiornata. Modifiche storicizzate.</div>";
            } else {
                $pdo->prepare("INSERT INTO certifications (brand_id,technology_id,name,code,category,level,validity_months,renewal_policy,exam_cost,description,notes,exam_url,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$data['brand_id'],$data['technology_id'],$data['name'],$data['code'],$data['category'],$data['level'],$data['validity_months'],$data['renewal_policy'],$data['exam_cost'],$data['description'],$data['notes'],$data['exam_url'],$data['is_active']]);
                $new_id = (int)$pdo->lastInsertId();
                write_log('Certifications','success',"Certificazione #$new_id creata",$u_id);
                $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Certificazione creata (ID: $new_id).</div>";
            }
        } catch (Exception $e) {
            $_SESSION['flash_msg'] = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
        }
        header("Location: catalogo_certificazioni.php" . (!empty($_GET['f_br']) ? '?f_br='.(int)$_GET['f_br'] : '')); exit();
    }

    if ($action === 'toggle_active' && $cert_id > 0) {
        $pdo->prepare("UPDATE certifications SET is_active = NOT is_active, updated_by=? WHERE id=?")->execute([$u_id, $cert_id]);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>Stato aggiornato.</div>";
        header("Location: catalogo_certificazioni.php" . (!empty($_GET['f_br']) ? '?f_br='.(int)$_GET['f_br'] : '')); exit();
    }
}

// ── Output HTML ─────────────────────────────────────────────────────────────
require_once('header.php');
$msg = '';
if (!empty($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }

// Filtri
$f_br = (int)($_GET['f_br'] ?? 0);
$f_cat = $_GET['f_cat'] ?? '';
$f_act = $_GET['f_act'] ?? '';
$f_q = trim($_GET['q'] ?? '');

$where = ["1=1"]; $params = [];
if ($f_br)  { $where[] = "c.brand_id=?"; $params[] = $f_br; }
if ($f_cat) { $where[] = "c.category=?"; $params[] = $f_cat; }
if ($f_act !== '') { $where[] = "c.is_active=?"; $params[] = (int)$f_act; }
if ($f_q) {
    $where[] = "(c.name LIKE ? OR c.code LIKE ? OR c.description LIKE ?)";
    $like = '%' . $f_q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}

$cq = $pdo->prepare(
    "SELECT c.*, b.name brand_name, t.name tech_name,
            (SELECT COUNT(*) FROM user_certifications uc WHERE uc.certification_id=c.id) assigned_count,
            (SELECT COUNT(*) FROM user_certifications uc WHERE uc.certification_id=c.id AND uc.status='active') active_count,
            (SELECT COUNT(*) FROM planned_exams pe WHERE pe.certification_id=c.id AND pe.status='planned') planned_count
     FROM certifications c
     LEFT JOIN brands b ON c.brand_id=b.id
     LEFT JOIN technologies t ON c.technology_id=t.id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY b.name, c.name"
);
$cq->execute($params);
$cert_list = $cq->fetchAll();

$brands = $pdo->query("SELECT id,name FROM brands ORDER BY name")->fetchAll();
$techs = $pdo->query("SELECT id,name FROM technologies ORDER BY name")->fetchAll();

// KPI
$tot = count($cert_list);
$tot_active = count(array_filter($cert_list, fn($c) => $c['is_active']));
$tot_with_ttl = count(array_filter($cert_list, fn($c) => $c['validity_months'] > 0));
$tot_assigned = array_sum(array_column($cert_list, 'assigned_count'));
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
  <div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:3px"><i class="fa-solid fa-award" style="color:var(--p);margin-right:10px"></i>Catalogo certificazioni</h1>
    <p style="color:var(--muted);font-size:13px">Anagrafica completa con storicizzazione modifiche, TTL e ricerca smart</p>
  </div>
  <?php if($can_edit): ?>
  <button onclick="openCertModal()" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Nuova certificazione</button>
  <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px">
  <div class="stat-card" style="border-color:var(--p)"><div class="sl">Certificazioni</div><div class="sv" style="color:var(--p)"><?=$tot?></div></div>
  <div class="stat-card" style="border-color:var(--success)"><div class="sl">Attive</div><div class="sv" style="color:var(--success)"><?=$tot_active?></div></div>
  <div class="stat-card" style="border-color:#f59e0b"><div class="sl">Con scadenza</div><div class="sv" style="color:#f59e0b"><?=$tot_with_ttl?></div></div>
  <div class="stat-card" style="border-color:#8b5cf6"><div class="sl">Assegnazioni totali</div><div class="sv" style="color:#8b5cf6"><?=$tot_assigned?></div></div>
</div>

<?=$msg?>

<!-- Filtri -->
<form method="GET" class="filter-bar">
<?php if (!empty($_GET["r"])): ?><input type="hidden" name="r" value="<?= htmlspecialchars($_GET["r"], ENT_QUOTES, "UTF-8") ?>"><?php endif; ?>
  <div class="fg"><label>Brand</label><select name="f_br"><option value="0">Tutti</option>
    <?php foreach($brands as $b): ?><option value="<?=$b['id']?>" <?=$f_br==$b['id']?'selected':''?>><?=h($b['name'])?></option><?php endforeach; ?>
  </select></div>
  <div class="fg"><label>Categoria</label><select name="f_cat"><option value="">Tutte</option>
    <?php foreach(['tecnica'=>'Tecnica','commerciale'=>'Commerciale','aziendale'=>'Aziendale'] as $k=>$v): ?>
    <option value="<?=$k?>" <?=$f_cat===$k?'selected':''?>><?=$v?></option><?php endforeach; ?>
  </select></div>
  <div class="fg"><label>Stato</label><select name="f_act"><option value="">Tutti</option>
    <option value="1" <?=$f_act==='1'?'selected':''?>>Attive</option>
    <option value="0" <?=$f_act==='0'?'selected':''?>>Disattivate</option>
  </select></div>
  <div class="fg"><label>Cerca</label><input type="text" name="q" value="<?=h($f_q)?>" placeholder="Nome, codice..."></div>
  <button type="submit" class="btn btn-primary">Filtra</button>
  <a href="catalogo_certificazioni.php" class="btn">Reset</a>

  <div class="fg" style="margin-left:auto">
    <label style="visibility:hidden">.</label>
    <button type="button" onclick="window.print()" class="btn btn-sm" title="Stampa pagina"
            style="background:#fef3c7;color:#92400e;border-color:#fde68a">
      <i class="fa-solid fa-print"></i> Stampa
    </button>
  </div>
</form>

<!-- Lista -->
<div class="card" style="overflow-x:auto">
<?php require_once __DIR__ . '/app/ListFilter.php'; ListFilter::render('catalogo_certificazioni', '#tCert', ['export_filename' => 'catalogo_certificazioni', 'title' => 'Catalogo certificazioni']); ?>
<table class="data-table" id="tCert">
<thead><tr>
  <th>Brand</th><th>Certificazione</th><th>Codice</th><th>Categoria</th><th>Livello</th>
  <th style="text-align:center">TTL</th><th style="text-align:center">Costo</th>
  <th style="text-align:center">Assegnate</th><th style="text-align:center">Pianificate</th>
  <th>Stato</th><th class="no-print">Azioni</th>
</tr></thead>
<tbody>
<?php if(empty($cert_list)): ?>
<tr><td colspan="11" style="text-align:center;padding:40px;color:var(--muted)">Nessuna certificazione trovata.</td></tr>
<?php endif; ?>
<?php foreach($cert_list as $c):
  $cat_col = ['tecnica'=>'#0ea5e9','commerciale'=>'#f59e0b','aziendale'=>'#8b5cf6'][$c['category']] ?? '#94a3b8';
?>
<tr style="<?=!$c['is_active']?'opacity:.55;':''?>">
  <td><strong style="font-size:12px"><?=h($c['brand_name'] ?? '—')?></strong>
    <?php if($c['tech_name']): ?><br><span style="font-size:10px;color:var(--muted)"><?=h($c['tech_name'])?></span><?php endif; ?></td>
  <td><div style="font-weight:700;font-size:12px"><?=h($c['name'])?></div>
    <?php if($c['description']): ?><div style="font-size:10px;color:var(--muted);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h($c['description'])?></div><?php endif; ?></td>
  <td><code style="font-size:11px;background:#f1f5f9;padding:2px 6px;border-radius:4px"><?=h($c['code'] ?? '—')?></code></td>
  <td><span style="color:<?=$cat_col?>;font-size:11px;font-weight:700"><?=ucfirst(h($c['category']))?></span></td>
  <td style="font-size:11px"><?=h($c['level'] ?? '—')?></td>
  <td style="text-align:center;font-weight:700;font-size:12px"><?=$c['validity_months'] ? $c['validity_months'].'m' : '<span style="color:var(--muted)">∞</span>'?></td>
  <td style="text-align:center;font-size:11px"><?=$c['exam_cost'] ? '€'.number_format($c['exam_cost'],0,',','.') : '—'?></td>
  <td style="text-align:center"><span style="font-weight:800;color:var(--p)"><?=$c['active_count']?></span><span style="font-size:10px;color:var(--muted)"> / <?=$c['assigned_count']?></span></td>
  <td style="text-align:center;font-weight:700;color:<?=$c['planned_count']?'#f59e0b':'var(--muted)'?>"><?=$c['planned_count']?></td>
  <td><span class="badge <?=$c['is_active']?'badge-success':'badge-danger'?>" style="font-size:9px"><?=$c['is_active']?'Attiva':'Off'?></span></td>
  <td style="white-space:nowrap" class="no-print">
    <?php if($can_edit): ?>
    <button onclick='openCertModal(<?=htmlspecialchars(json_encode($c),ENT_QUOTES,"UTF-8")?>)' class="btn btn-sm" title="Modifica"><i class="fa-solid fa-pen"></i></button>
    <button onclick='openHistory(<?=$c["id"]?>,"<?=h(addslashes($c["name"]))?>")'
            class="btn btn-sm" style="background:#f3e8ff;color:#7c3aed;border-color:#d8b4fe" title="Storico modifiche">
      <i class="fa-solid fa-clock-rotate-left"></i>
    </button>
    <form method="POST" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="cert_id" value="<?=$c['id']?>">
      <button type="submit" class="btn btn-sm <?=$c['is_active']?'btn-warning':'btn-success'?>" title="<?=$c['is_active']?'Disattiva':'Riattiva'?>">
        <i class="fa-solid fa-<?=$c['is_active']?'eye-slash':'eye'?>"></i>
      </button>
    </form>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div>

<!-- ═══ MODAL: NUOVA/MODIFICA CERTIFICAZIONE ═══ -->
<?php if($can_edit): ?>
<div id="mCert" class="modal-overlay" style="z-index:1000">
  <div class="modal-box" style="width:720px;max-height:90vh;overflow-y:auto">
    <div style="display:flex;justify-content:space-between;margin-bottom:18px">
      <h3 id="mCertTitle" style="margin:0;font-size:16px"><i class="fa-solid fa-award" style="color:var(--p)"></i> Nuova certificazione</h3>
      <button onclick="document.getElementById('mCert').style.display='none'" style="border:none;background:none;font-size:20px;cursor:pointer">&times;</button>
    </div>
    <form method="POST">
            <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="cert_id" id="c_id" value="0">

      <div class="grid-2">
        <div class="form-group"><label>Brand *</label>
          <select name="brand_id" id="c_brand" required>
            <option value="">— Seleziona —</option>
            <?php foreach($brands as $b): ?><option value="<?=$b['id']?>"><?=h($b['name'])?></option><?php endforeach; ?>
          </select></div>
        <div class="form-group"><label>Tecnologia</label>
          <select name="technology_id" id="c_tech"><option value="">— Nessuna —</option>
            <?php foreach($techs as $t): ?><option value="<?=$t['id']?>"><?=h($t['name'])?></option><?php endforeach; ?>
          </select></div>
        <div class="form-group span-2"><label>Nome certificazione *</label>
          <input type="text" name="name" id="c_name" required placeholder="Es. Azure Solutions Architect Expert"></div>
        <div class="form-group"><label>Codice esame</label>
          <input type="text" name="code" id="c_code" placeholder="Es. AZ-305"></div>
        <div class="form-group"><label>Categoria</label>
          <select name="category" id="c_cat">
            <option value="tecnica">Tecnica</option><option value="commerciale">Commerciale</option><option value="aziendale">Aziendale</option>
          </select></div>
        <div class="form-group"><label>Livello</label>
          <select name="level" id="c_level">
            <option value="">— Nessuno —</option>
            <?php foreach(['Foundation','Associate','Professional','Expert','Specialty'] as $l): ?>
            <option value="<?=$l?>"><?=$l?></option><?php endforeach; ?>
          </select></div>
        <div class="form-group"><label>Validità (mesi)</label>
          <input type="number" name="validity_months" id="c_ttl" min="0" placeholder="0 = nessuna scadenza">
          <div style="font-size:10px;color:var(--muted)">TTL: mesi dalla data di conseguimento</div></div>
      </div>

      <div style="background:#eff6ff;padding:14px;border-radius:10px;border:1px solid #bfdbfe;margin:14px 0">
        <div style="font-size:10px;font-weight:700;color:#1e40af;text-transform:uppercase;margin-bottom:10px"><i class="fa-solid fa-clock"></i> Gestione scadenze e costi</div>
        <div class="grid-2">
          <div class="form-group" style="margin:0"><label>Policy rinnovo</label>
            <input type="text" name="renewal_policy" id="c_rpolicy" placeholder="Es. Riesame ogni 2 anni, ricertificazione obbligatoria"></div>
          <div class="form-group" style="margin:0"><label>Costo esame (€)</label>
            <input type="number" name="exam_cost" id="c_cost" min="0" step="0.01" placeholder="Es. 165.00"></div>
        </div>
      </div>

      <div class="form-group"><label>URL pagina esame</label>
        <input type="url" name="exam_url" id="c_url" placeholder="https://..."></div>
      <div class="form-group"><label>Descrizione</label>
        <textarea name="description" id="c_desc" rows="3" placeholder="Descrizione della certificazione, prerequisiti, ambito..."></textarea></div>
      <div class="form-group"><label>Note interne <span style="color:var(--muted);font-weight:400;font-size:11px">(non pubbliche, solo HR)</span></label>
        <textarea name="notes" id="c_notes" rows="2" placeholder="Annotazioni interne: fornitori, esperienze pregresse, vendor di riferimento..."></textarea></div>

      <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
        <input type="checkbox" name="is_active" id="c_active" checked style="width:18px;height:18px">
        <label for="c_active" style="cursor:pointer">Certificazione attiva (visibile nei dropdown e nelle pianificazioni)</label>
      </div>

      <div style="display:flex;gap:10px">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:12px"><i class="fa-solid fa-floppy-disk"></i> Salva</button>
        <button type="button" onclick="document.getElementById('mCert').style.display='none'" class="btn" style="flex:1;justify-content:center;padding:12px">Annulla</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ═══ MODAL: STORICO MODIFICHE ═══ -->
<div id="mHist" class="modal-overlay" style="z-index:1001">
  <div class="modal-box" style="width:650px;max-height:80vh;overflow-y:auto">
    <div style="display:flex;justify-content:space-between;margin-bottom:18px">
      <h3 id="mHistTitle" style="margin:0;font-size:16px"><i class="fa-solid fa-clock-rotate-left" style="color:#7c3aed"></i> Storico</h3>
      <button onclick="document.getElementById('mHist').style.display='none'" style="border:none;background:none;font-size:20px;cursor:pointer">&times;</button>
    </div>
    <div id="mHistBody"><div style="text-align:center;padding:40px;color:var(--muted)"><i class="fa-solid fa-spinner fa-spin"></i></div></div>
  </div>
</div>

<script>
$('#tCert').DataTable({language:{search:"Cerca:"},pageLength:25,order:[[0,'asc'],[1,'asc']]});

function openCertModal(d=null) {
  var fields={id:'id',brand:'brand_id',tech:'technology_id',name:'name',code:'code',cat:'category',
              level:'level',ttl:'validity_months',rpolicy:'renewal_policy',cost:'exam_cost',
              url:'exam_url',desc:'description',notes:'notes'};
  for (var f in fields) { var el=document.getElementById('c_'+f); if(el) el.value=''; }
  document.getElementById('c_id').value=0;
  document.getElementById('c_active').checked=true;
  document.getElementById('c_cat').value='tecnica';
  document.getElementById('mCertTitle').innerHTML='<i class="fa-solid fa-award" style="color:var(--p)"></i> Nuova certificazione';
  if (d) {
    document.getElementById('c_id').value=d.id;
    document.getElementById('mCertTitle').innerHTML='<i class="fa-solid fa-pen" style="color:var(--p)"></i> Modifica: '+d.name;
    for (var f in fields) {
      var el=document.getElementById('c_'+f);
      if (el && d[fields[f]]!==null && d[fields[f]]!==undefined) el.value=d[fields[f]];
    }
    document.getElementById('c_active').checked = !!d.is_active;
  }
  document.getElementById('mCert').style.display='flex';
}

function openHistory(certId, certName) {
  document.getElementById('mHistTitle').innerHTML='<i class="fa-solid fa-clock-rotate-left" style="color:#7c3aed"></i> Storico: '+certName;
  var body=document.getElementById('mHistBody');
  body.innerHTML='<div style="text-align:center;padding:40px"><i class="fa-solid fa-spinner fa-spin"></i></div>';
  document.getElementById('mHist').style.display='flex';

  fetch('api_cert_history.php?cert_id='+certId)
    .then(function(r){return r.json()})
    .then(function(data){
      if(!data.length){body.innerHTML='<div style="text-align:center;padding:30px;color:var(--muted)">Nessuna modifica registrata.</div>';return;}
      var html='';
      var labels={name:'Nome',code:'Codice',category:'Categoria',level:'Livello',validity_months:'TTL (mesi)',
                  description:'Descrizione',notes:'Note interne',exam_url:'URL esame',is_active:'Attiva',renewal_policy:'Policy rinnovo',
                  exam_cost:'Costo',brand_id:'Brand ID',technology_id:'Tecnologia ID'};
      data.forEach(function(d){
        html+='<div style="padding:10px 14px;border-radius:8px;background:#f8fafc;border:1px solid var(--border);margin-bottom:8px">';
        html+='<div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-bottom:4px">';
        html+='<span><strong>v'+d.version+'</strong> · '+d.changed_at+'</span>';
        html+='<span>'+(d.author||'Sistema')+'</span></div>';
        html+='<div style="font-size:12px"><strong style="color:#7c3aed">'+(labels[d.field_changed]||d.field_changed)+':</strong> ';
        html+='<span style="text-decoration:line-through;color:#dc2626">'+(d.old_value||'(vuoto)')+'</span> → ';
        html+='<span style="color:#059669;font-weight:600">'+(d.new_value||'(vuoto)')+'</span></div>';
        html+='</div>';
      });
      body.innerHTML=html;
    })
    .catch(function(){body.innerHTML='<div style="color:var(--danger);text-align:center;padding:30px">Errore.</div>';});
}
</script>
<?php require_once('footer.php'); ?>
