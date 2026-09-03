<?php
/**
 * certV 2.4 — brand_technologies.php
 * Gestione Tecnologie, Servizi e Prodotti per Brand
 * Ruoli: Super Admin (1), HR Director (2), Brand Manager (3)
 */
require_once('access_control.php');
require_once('header.php');

$u_id    = (int)$_SESSION['user_id'];
$u_role  = (int)($_SESSION['role_id'] ?? 99);
$can_edit = can('edit');
$msg     = '';

$brand_id = (int)($_GET['brand_id'] ?? 0);

// ── CRUD ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['tech_id'] ?? 0);

    try {
        if ($action === 'save') {
            $bid  = (int)$_POST['brand_id'];
            $data = [
                $bid,
                $_POST['category'] ?? 'Tecnologia',
                trim($_POST['name'] ?? ''),
                trim($_POST['description'] ?? '') ?: null,
                trim($_POST['version'] ?? '') ?: null,
                $_POST['status'] ?? 'active',
                trim($_POST['doc_url'] ?? '') ?: null,
                max(1, min(5, (int)($_POST['relevance'] ?? 3))),
                trim($_POST['notes'] ?? '') ?: null,
            ];
            if (!$data[2]) throw new Exception("Il nome è obbligatorio.");

            if ($id > 0) {
                $pdo->prepare(
                    "UPDATE brand_technologies SET brand_id=?,category=?,name=?,description=?,
                     version=?,status=?,doc_url=?,relevance=?,notes=? WHERE id=?"
                )->execute([...$data, $id]);
                $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Aggiornato.</div>";
            } else {
                $pdo->prepare(
                    "INSERT INTO brand_technologies (brand_id,category,name,description,version,status,doc_url,relevance,notes)
                     VALUES (?,?,?,?,?,?,?,?,?)"
                )->execute($data);
                $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Aggiunto.</div>";
            }
            write_log('Brand','success',"Tecnologia brand: " . ($id > 0 ? "mod #$id" : "nuova"), $u_id);
        }

        if ($action === 'delete' && $id > 0 && can('delete')) {
            $pdo->prepare("DELETE FROM brand_technologies WHERE id=?")->execute([$id]);
            $msg = "<div class='alert alert-success'>Eliminato.</div>";
            write_log('Brand','info',"Tecnologia #$id eliminata", $u_id);
        }
    } catch (Exception $e) {
        $msg = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
    }
}

// ── Dati ──────────────────────────────────────────────────────────────────────
$all_brands = $pdo->query("SELECT id, name, priority, priority_color FROM brands ORDER BY priority, name")->fetchAll();

$where = "WHERE 1=1"; $params = [];
$f_brand    = (int)($_GET['f_brand'] ?? $brand_id);
$f_category = $_GET['f_cat'] ?? '';
$f_status   = $_GET['f_st'] ?? '';
if ($f_brand)    { $where .= " AND bt.brand_id = ?"; $params[] = $f_brand; }
if ($f_category) { $where .= " AND bt.category = ?"; $params[] = $f_category; }
if ($f_status)   { $where .= " AND bt.status = ?";   $params[] = $f_status; }

$stm = $pdo->prepare(
    "SELECT bt.*, b.name brand_name, b.priority brand_priority, b.priority_color brand_color
     FROM brand_technologies bt
     JOIN brands b ON bt.brand_id = b.id
     $where
     ORDER BY b.priority ASC, b.name ASC, bt.category, bt.relevance ASC, bt.name ASC"
);
$stm->execute($params);
$items = $stm->fetchAll();

// KPI
$kpi_tech    = 0; $kpi_serv = 0; $kpi_prod = 0; $kpi_deprecated = 0;
foreach ($items as $it) {
    match($it['category']) { 'Tecnologia' => $kpi_tech++, 'Servizio' => $kpi_serv++, 'Prodotto' => $kpi_prod++, default => null };
    if ($it['status'] !== 'active') $kpi_deprecated++;
}

$cat_icons  = ['Tecnologia' => 'fa-microchip', 'Servizio' => 'fa-cloud', 'Prodotto' => 'fa-box-open'];
$cat_colors = ['Tecnologia' => '#6366f1', 'Servizio' => '#0ea5e9', 'Prodotto' => '#f59e0b'];
$st_badges  = [
    'active'     => ['Attivo',     'badge-success'],
    'deprecated' => ['Deprecato',  'badge-warning'],
    'eol'        => ['End of Life','badge-danger'],
];
$rel_labels = [1=>'Critica', 2=>'Alta', 3=>'Media', 4=>'Bassa', 5=>'Marginale'];
$rel_colors = [1=>'#dc2626', 2=>'#f59e0b', 3=>'#3b82f6', 4=>'#8b5cf6', 5=>'#94a3b8'];
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
  <div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:3px">
      <i class="fa-solid fa-microchip" style="color:var(--p);margin-right:10px"></i>Tecnologie, Servizi & Prodotti
    </h1>
    <p style="color:var(--muted);font-size:13px">Classificazione del catalogo tecnologico per brand</p>
  </div>
  <?php if($can_edit): ?>
  <button onclick="openTechModal()" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Aggiungi</button>
  <?php endif; ?>
</div>

<?=$msg?>

<!-- KPI -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px">
  <div class="stat-card" style="border-color:#6366f1"><div class="sl">Tecnologie</div><div class="sv" style="color:#6366f1"><?=$kpi_tech?></div></div>
  <div class="stat-card" style="border-color:#0ea5e9"><div class="sl">Servizi</div><div class="sv" style="color:#0ea5e9"><?=$kpi_serv?></div></div>
  <div class="stat-card" style="border-color:#f59e0b"><div class="sl">Prodotti</div><div class="sv" style="color:#f59e0b"><?=$kpi_prod?></div></div>
  <div class="stat-card" style="border-color:var(--danger)"><div class="sl">Deprecati / EOL</div><div class="sv" style="color:var(--danger)"><?=$kpi_deprecated?></div></div>
</div>

<!-- Filtri -->
<form method="GET" class="filter-bar">
<?php if (!empty($_GET["r"])): ?><input type="hidden" name="r" value="<?= htmlspecialchars($_GET["r"], ENT_QUOTES, "UTF-8") ?>"><?php endif; ?>
  <div class="fg"><label>Brand</label>
    <select name="f_brand" style="min-width:160px">
      <option value="">Tutti</option>
      <?php foreach($all_brands as $b): ?>
      <option value="<?=$b['id']?>" <?=$f_brand==$b['id']?'selected':''?>><?=h($b['name'])?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="fg"><label>Categoria</label>
    <select name="f_cat">
      <option value="">Tutte</option>
      <option value="Tecnologia" <?=$f_category==='Tecnologia'?'selected':''?>>Tecnologia</option>
      <option value="Servizio" <?=$f_category==='Servizio'?'selected':''?>>Servizio</option>
      <option value="Prodotto" <?=$f_category==='Prodotto'?'selected':''?>>Prodotto</option>
    </select>
  </div>
  <div class="fg"><label>Stato</label>
    <select name="f_st">
      <option value="">Tutti</option>
      <option value="active" <?=$f_status==='active'?'selected':''?>>Attivi</option>
      <option value="deprecated" <?=$f_status==='deprecated'?'selected':''?>>Deprecati</option>
      <option value="eol" <?=$f_status==='eol'?'selected':''?>>End of Life</option>
    </select>
  </div>
  <button type="submit" class="btn btn-primary" style="margin-top:20px">Filtra</button>
  <a href="brand_technologies.php" class="btn btn-sm" style="margin-top:20px">Reset</a>

  <div class="fg" style="margin-left:auto">
    <label style="visibility:hidden">.</label>
    <button type="button" onclick="window.print()" class="btn btn-sm" title="Stampa pagina"
            style="background:#fef3c7;color:#92400e;border-color:#fde68a">
      <i class="fa-solid fa-print"></i> Stampa
    </button>
  </div>
</form>

<!-- Tabella risultati -->
<div class="card" style="margin-top:4px">
<?php require_once __DIR__ . '/app/ListFilter.php'; ListFilter::render('brand_technologies', '#tTech', ['export_filename' => 'brand_technologies', 'title' => 'Tecnologie & Servizi']); ?>
<table id="tTech" class="display" style="width:100%">
<thead>
  <tr>
    <th>Brand</th>
    <th>Categoria</th>
    <th>Nome</th>
    <th>Versione</th>
    <th>Rilevanza</th>
    <th>Stato</th>
    <th style="width:80px">Azioni</th>
  </tr>
</thead>
<tbody>
<?php foreach ($items as $it):
  $ci = $cat_icons[$it['category']] ?? 'fa-cube';
  $cc = $cat_colors[$it['category']] ?? '#94a3b8';
  [$st_lbl, $st_cls] = $st_badges[$it['status']] ?? ['?','badge-neutral'];
  $rl = $rel_labels[$it['relevance']] ?? 'n/d';
  $rc = $rel_colors[$it['relevance']] ?? '#94a3b8';
?>
<tr>
  <td>
    <div style="display:flex;align-items:center;gap:8px">
      <span style="width:8px;height:8px;border-radius:3px;background:<?=h($it['brand_color'] ?? '#3b82f6')?>;flex-shrink:0"></span>
      <strong><?=h($it['brand_name'])?></strong>
    </div>
  </td>
  <td><span style="display:inline-flex;align-items:center;gap:5px;color:<?=$cc?>"><i class="fa-solid <?=$ci?>" style="font-size:11px"></i> <?=h($it['category'])?></span></td>
  <td>
    <div style="font-weight:600"><?=h($it['name'])?></div>
    <?php if($it['description']): ?><div style="font-size:11px;color:var(--muted);margin-top:1px"><?=h(substr($it['description'],0,60))?></div><?php endif; ?>
  </td>
  <td><code style="font-size:11px;background:#f1f5f9;padding:2px 6px;border-radius:4px"><?=h($it['version'] ?? '—')?></code></td>
  <td><span style="font-weight:700;font-size:12px;color:<?=$rc?>"><?=$it['relevance']?></span> <span style="font-size:10px;color:var(--muted)"><?=$rl?></span></td>
  <td><span class="badge <?=$st_cls?>"><?=$st_lbl?></span></td>
  <td>
    <div style="display:flex;gap:4px">
      <?php if($it['doc_url']): ?>
      <a href="<?=h($it['doc_url'])?>" target="_blank" class="btn btn-sm" title="Documentazione"><i class="fa-solid fa-book"></i></a>
      <?php endif; ?>
      <?php if($can_edit): ?>
      <button onclick='openTechModal(<?=htmlspecialchars(json_encode($it),ENT_QUOTES,"UTF-8")?>)' class="btn btn-sm" title="Modifica"><i class="fa-solid fa-pen"></i></button>
      <?php endif; ?>
      <?php if(can("delete")): ?>
      <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare?')">
            <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete"><input type="hidden" name="tech_id" value="<?=$it['id']?>">
        <button type="submit" class="btn btn-danger btn-sm" title="Elimina"><i class="fa-solid fa-trash"></i></button>
      </form>
      <?php endif; ?>
    </div>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<!-- MODAL -->
<div id="mTech" class="modal-overlay">
<div class="modal-box" style="width:620px">
  <div style="display:flex;justify-content:space-between;margin-bottom:18px">
    <h3 id="mTechTitle" style="margin:0;font-size:16px">Tecnologia / Servizio / Prodotto</h3>
    <button onclick="document.getElementById('mTech').style.display='none'" style="border:none;background:none;font-size:20px;cursor:pointer">&times;</button>
  </div>
  <form method="POST">
            <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="tech_id" id="mt_id" value="0">

    <div class="grid-2">
      <div class="form-group">
        <label>Brand *</label>
        <select name="brand_id" id="mt_brand" required>
          <option value="">— Seleziona —</option>
          <?php foreach($all_brands as $b): ?>
          <option value="<?=$b['id']?>" <?=$f_brand==$b['id']?'selected':''?>><?=h($b['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Categoria *</label>
        <select name="category" id="mt_cat" required>
          <option value="Tecnologia">🔧 Tecnologia</option>
          <option value="Servizio">☁️ Servizio</option>
          <option value="Prodotto">📦 Prodotto</option>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label>Nome *</label>
      <input type="text" name="name" id="mt_name" required placeholder="Es. Azure Kubernetes Service, FortiGate, vSphere...">
    </div>

    <div class="form-group">
      <label>Descrizione</label>
      <textarea name="description" id="mt_desc" rows="2" placeholder="Breve descrizione della tecnologia/servizio/prodotto"></textarea>
    </div>

    <div class="grid-3">
      <div class="form-group">
        <label>Versione</label>
        <input type="text" name="version" id="mt_ver" placeholder="8.0, 2024, v3.x...">
      </div>
      <div class="form-group">
        <label>Rilevanza (1-5)</label>
        <select name="relevance" id="mt_rel">
          <option value="1">1 — Critica</option>
          <option value="2">2 — Alta</option>
          <option value="3" selected>3 — Media</option>
          <option value="4">4 — Bassa</option>
          <option value="5">5 — Marginale</option>
        </select>
      </div>
      <div class="form-group">
        <label>Stato</label>
        <select name="status" id="mt_st">
          <option value="active">Attivo</option>
          <option value="deprecated">Deprecato</option>
          <option value="eol">End of Life</option>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label>Link documentazione</label>
      <input type="url" name="doc_url" id="mt_url" placeholder="https://docs.vendor.com/...">
    </div>

    <div class="form-group">
      <label>Note</label>
      <textarea name="notes" id="mt_notes" rows="2"></textarea>
    </div>

    <div style="display:flex;gap:10px">
      <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center">Salva</button>
      <button type="button" onclick="document.getElementById('mTech').style.display='none'" class="btn" style="flex:1;justify-content:center">Annulla</button>
    </div>
  </form>
</div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(document).ready(function(){ $('#tTech').DataTable({pageLength:25,order:[[0,'asc'],[4,'asc']],language:{url:'//cdn.datatables.net/plug-ins/1.13.6/i18n/it-IT.json'}}); });

function openTechModal(data=null){
  document.querySelector('#mTech form').reset();
  document.getElementById('mt_id').value = 0;
  document.getElementById('mTechTitle').textContent = data ? 'Modifica: '+data.name : 'Nuova voce catalogo';
  if(data){
    document.getElementById('mt_id').value = data.id;
    document.getElementById('mt_brand').value = data.brand_id;
    document.getElementById('mt_cat').value = data.category;
    document.getElementById('mt_name').value = data.name;
    document.getElementById('mt_desc').value = data.description||'';
    document.getElementById('mt_ver').value = data.version||'';
    document.getElementById('mt_st').value = data.status;
    document.getElementById('mt_rel').value = data.relevance;
    document.getElementById('mt_url').value = data.doc_url||'';
    document.getElementById('mt_notes').value = data.notes||'';
  }
  document.getElementById('mTech').style.display = 'flex';
}
</script>

<?php require_once('footer.php'); ?>
