<?php
/**
 * certV 2.4 — brand_distributors.php
 * Gestione Distributori per Brand
 *   • Ranking gerarchico: Primario / Secondario
 *   • Modello operativo: Volume / Valore / Academy
 *   • Contatti: Commerciale + Academy per associazione
 */
require_once('access_control.php');
require_once('header.php');

$u_id     = (int)$_SESSION['user_id'];
$u_role   = (int)($_SESSION['role_id'] ?? 99);
$can_edit = can('edit');
$can_del  = can('delete');
$msg      = '';
$f_brand  = (int)($_GET['brand_id'] ?? 0);
$f_model  = $_GET['f_model'] ?? '';
$f_rank   = $_GET['f_rank'] ?? '';

// ── Auto-migrazione colonne modello operativo ────────────────────────────────
// Se is_volume/is_value/is_academy non esistono, le crea automaticamente
$HAS_MODEL = false;
try {
    $pdo->query("SELECT `is_volume` FROM `brand_distributors` LIMIT 0")->closeCursor();
    $HAS_MODEL = true;
} catch (\Exception $e) {
    // Colonne mancanti → le creo adesso
    try {
        $pdo->exec("ALTER TABLE `brand_distributors`
            ADD COLUMN `is_volume`  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Acquisto a Volume' AFTER `priority_order`,
            ADD COLUMN `is_value`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Acquisto a Valore' AFTER `is_volume`,
            ADD COLUMN `is_academy` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Academy/Formazione' AFTER `is_value`");
        $HAS_MODEL = true;
    } catch (\Exception $e2) {
        // ALTER fallito (permessi insufficienti?) → mostra avviso
        $msg = "<div class='alert alert-warning'><i class='fa-solid fa-triangle-exclamation'></i> "
             . "Colonne modello operativo mancanti. Eseguire in phpMyAdmin:<br>"
             . "<code>ALTER TABLE brand_distributors ADD COLUMN is_volume TINYINT(1) NOT NULL DEFAULT 0, "
             . "ADD COLUMN is_value TINYINT(1) NOT NULL DEFAULT 0, "
             . "ADD COLUMN is_academy TINYINT(1) NOT NULL DEFAULT 0;</code></div>";
    }
}

// ── CRUD ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $action = $_POST['action'] ?? '';
    try {
        $pdo->beginTransaction();

        // ── Salva distributore (anagrafica) ──────────────────────
        if ($action === 'save_distributor') {
            $id = (int)($_POST['dist_id'] ?? 0);
            $d = [
                trim($_POST['name'] ?? ''), $_POST['type'] ?? 'Distributore',
                trim($_POST['website'] ?? '') ?: null, trim($_POST['address'] ?? '') ?: null,
                trim($_POST['city'] ?? '') ?: null, trim($_POST['province'] ?? '') ?: null,
                trim($_POST['vat_number'] ?? '') ?: null, $_POST['status'] ?? 'active',
                trim($_POST['commercial_name'] ?? '') ?: null, trim($_POST['commercial_email'] ?? '') ?: null,
                trim($_POST['commercial_phone'] ?? '') ?: null,
                trim($_POST['academy_name'] ?? '') ?: null, trim($_POST['academy_email'] ?? '') ?: null,
                trim($_POST['academy_phone'] ?? '') ?: null, trim($_POST['notes'] ?? '') ?: null,
            ];
            if (!$d[0]) throw new Exception("Nome distributore obbligatorio.");
            if ($id > 0) {
                $pdo->prepare("UPDATE distributors SET name=?,type=?,website=?,address=?,city=?,province=?,vat_number=?,status=?,commercial_name=?,commercial_email=?,commercial_phone=?,academy_name=?,academy_email=?,academy_phone=?,notes=? WHERE id=?")->execute([...$d, $id]);
            } else {
                $pdo->prepare("INSERT INTO distributors (name,type,website,address,city,province,vat_number,status,commercial_name,commercial_email,commercial_phone,academy_name,academy_email,academy_phone,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute($d);
            }
            $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Distributore " . ($id ? 'aggiornato' : 'creato') . ".</div>";
        }

        // ── Collega distributore a brand ─────────────────────────
        if ($action === 'link_brand') {
            $bid  = (int)$_POST['brand_id'];
            $did  = (int)$_POST['distributor_id'];
            $rank = in_array($_POST['ranking'] ?? '', ['primary','secondary']) ? $_POST['ranking'] : 'primary';
            $ord  = max(1, (int)($_POST['priority_order'] ?? 1));

            // Campi base (sempre presenti)
            $cols = 'brand_id,distributor_id,ranking,priority_order,commercial_ref,commercial_email,commercial_phone,academy_ref,academy_email,academy_phone,contract_ref,discount_pct,notes';
            $vals = array_fill(0, 13, '?');
            $params = [
                $bid, $did, $rank, $ord,
                trim($_POST['commercial_ref'] ?? '') ?: null,
                trim($_POST['commercial_email_bd'] ?? '') ?: null,
                trim($_POST['commercial_phone_bd'] ?? '') ?: null,
                trim($_POST['academy_ref'] ?? '') ?: null,
                trim($_POST['academy_email_bd'] ?? '') ?: null,
                trim($_POST['academy_phone_bd'] ?? '') ?: null,
                trim($_POST['contract_ref'] ?? '') ?: null,
                !empty($_POST['discount_pct']) ? (float)$_POST['discount_pct'] : null,
                trim($_POST['link_notes'] ?? '') ?: null,
            ];

            // Campi modello operativo (solo se le colonne esistono)
            if ($HAS_MODEL) {
                $cols .= ',is_volume,is_value,is_academy';
                $vals = array_merge($vals, ['?','?','?']);
                $params[] = isset($_POST['is_volume']) ? 1 : 0;
                $params[] = isset($_POST['is_value'])  ? 1 : 0;
                $params[] = isset($_POST['is_academy']) ? 1 : 0;
            }

            $lid = (int)($_POST['link_id'] ?? 0);
            if ($lid > 0) {
                $sets = implode('=?,', explode(',', $cols)) . '=?';
                $pdo->prepare("UPDATE brand_distributors SET $sets WHERE id=?")->execute([...$params, $lid]);
            } else {
                $placeholders = implode(',', $vals);
                $pdo->prepare("INSERT INTO brand_distributors ($cols) VALUES ($placeholders)")->execute($params);
            }
            $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Associazione " . ($lid ? 'aggiornata' : 'creata') . ".</div>";
        }

        if ($action === 'unlink' && $can_del) {
            $pdo->prepare("DELETE FROM brand_distributors WHERE id=?")->execute([(int)$_POST['link_id']]);
            $msg = "<div class='alert alert-success'>Associazione rimossa.</div>";
        }
        if ($action === 'delete_distributor' && $can_del) {
            $pdo->prepare("DELETE FROM distributors WHERE id=?")->execute([(int)$_POST['dist_id']]);
            $msg = "<div class='alert alert-success'>Distributore eliminato.</div>";
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = "<div class='alert alert-danger'><i class='fa-solid fa-triangle-exclamation'></i> " . h($e->getMessage()) . "</div>";
    }
}

// ── Dati ──────────────────────────────────────────────────────────────────────
$all_brands = $pdo->query("SELECT id, name, priority, priority_color FROM brands ORDER BY priority, name")->fetchAll();
$all_distributors = $pdo->query("SELECT * FROM distributors ORDER BY name")->fetchAll();

$where = "WHERE 1=1"; $prm = [];
if ($f_brand) { $where .= " AND bd.brand_id=?"; $prm[] = $f_brand; }
if ($f_rank)  { $where .= " AND bd.ranking=?";  $prm[] = $f_rank; }
if ($HAS_MODEL) {
    if ($f_model === 'volume')  $where .= " AND bd.is_volume=1";
    if ($f_model === 'value')   $where .= " AND bd.is_value=1";
    if ($f_model === 'academy') $where .= " AND bd.is_academy=1";
}

$q = $pdo->prepare(
    "SELECT bd.*, b.name brand_name, b.priority brand_priority, b.priority_color brand_color,
            d.name dist_name, d.type dist_type, d.status dist_status,
            d.commercial_name d_cn, d.commercial_email d_ce, d.commercial_phone d_cp,
            d.academy_name d_an, d.academy_email d_ae, d.academy_phone d_ap,
            d.website dist_web, d.city dist_city
     FROM brand_distributors bd
     JOIN brands b ON bd.brand_id=b.id
     JOIN distributors d ON bd.distributor_id=d.id
     $where
     ORDER BY b.priority, b.name, bd.ranking, bd.priority_order"
);
$q->execute($prm);
$links = $q->fetchAll();

// Raggruppa per brand → ranking → lista
$by_brand = [];
foreach ($links as $l) {
    $by_brand[$l['brand_id']]['brand'] = ['name'=>$l['brand_name'], 'color'=>$l['brand_color']];
    $by_brand[$l['brand_id']][$l['ranking']][] = $l;
}

// KPI
$tot   = count($all_distributors);
$act   = count(array_filter($all_distributors, fn($d) => $d['status']==='active'));
$pri   = count(array_filter($links, fn($l) => $l['ranking']==='primary'));
$sec   = count($links) - $pri;
$k_vol = $HAS_MODEL ? count(array_filter($links, fn($l) => ($l['is_volume'] ?? 0))) : 0;
$k_val = $HAS_MODEL ? count(array_filter($links, fn($l) => ($l['is_value'] ?? 0)))  : 0;
$k_aca = $HAS_MODEL ? count(array_filter($links, fn($l) => ($l['is_academy'] ?? 0))) : 0;

$RANK = [
    'primary'   => ['lbl'=>'Primario',   'col'=>'#059669', 'bg'=>'#ecfdf5', 'ico'=>'fa-star'],
    'secondary' => ['lbl'=>'Secondario', 'col'=>'#6366f1', 'bg'=>'#eef2ff', 'ico'=>'fa-star-half-stroke'],
];
$TYPES = ['Distributore'=>'fa-warehouse','VAD'=>'fa-award','Rivenditore'=>'fa-store','Aggregatore'=>'fa-layer-group'];
?>

<!-- ═══ HEADER ═══ -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
  <div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:3px">
      <i class="fa-solid fa-truck-field" style="color:var(--p);margin-right:10px"></i>Distributori per Brand
    </h1>
    <p style="color:var(--muted);font-size:13px">Ranking Primario/Secondario · Modello operativo Volume/Valore/Academy · Contatti Commerciale &amp; Academy</p>
  </div>
  <?php if($can_edit): ?>
  <div style="display:flex;gap:8px">
    <button onclick="openDist()" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Nuovo distributore</button>
    <button onclick="openLink()" class="btn" style="background:#6366f1;color:#fff;border:none"><i class="fa-solid fa-link"></i> Associa a brand</button>
  </div>
  <?php endif; ?>
</div>
<?=$msg?>

<?php if ($f_brand): ?>
<?php
  $b_info = $pdo->prepare("SELECT name FROM brands WHERE id = ?");
  $b_info->execute([$f_brand]);
  $b_name = $b_info->fetchColumn();
  if ($b_name):
?>
<div style="background:#f0f9ff;border:1px solid #93c5fd;border-radius:8px;padding:12px 16px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
  <div>
    <i class="fa-solid fa-filter" style="color:#0ea5e9"></i>
    Filtrato sul brand: <strong style="color:#0c4a6e;font-size:14px"><?= h($b_name) ?></strong>
  </div>
  <div style="display:flex;gap:6px">
    <a href="<?= url_safe('brand_overview', ['id' => $f_brand]) ?>" class="btn btn-sm" style="background:#7c3aed;color:#fff"><i class="fa-solid fa-eye"></i> Vista 360°</a>
    <a href="<?= url_safe('brand_distributors') ?>" class="btn btn-sm">Rimuovi filtro <i class="fa-solid fa-xmark"></i></a>
  </div>
</div>
<?php endif; endif; ?>

<!-- ═══ KPI ═══ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:12px;margin-bottom:22px">
  <div class="stat-card" style="border-color:var(--p)"><div class="sl">Distributori</div><div class="sv" style="color:var(--p)"><?=$tot?></div></div>
  <div class="stat-card" style="border-color:var(--success)"><div class="sl">Attivi</div><div class="sv" style="color:var(--success)"><?=$act?></div></div>
  <div class="stat-card" style="border-color:#059669"><div class="sl">Primari</div><div class="sv" style="color:#059669"><?=$pri?></div></div>
  <div class="stat-card" style="border-color:#6366f1"><div class="sl">Secondari</div><div class="sv" style="color:#6366f1"><?=$sec?></div></div>
  <?php if($HAS_MODEL): ?>
  <div class="stat-card" style="border-color:#0ea5e9"><div class="sl">Volume</div><div class="sv" style="color:#0ea5e9"><?=$k_vol?></div></div>
  <div class="stat-card" style="border-color:#f59e0b"><div class="sl">Valore</div><div class="sv" style="color:#f59e0b"><?=$k_val?></div></div>
  <div class="stat-card" style="border-color:#8b5cf6"><div class="sl">Academy</div><div class="sv" style="color:#8b5cf6"><?=$k_aca?></div></div>
  <?php endif; ?>
</div>

<!-- ═══ FILTRI ═══ -->
<form method="GET" class="filter-bar">
<?php if (!empty($_GET["r"])): ?><input type="hidden" name="r" value="<?= htmlspecialchars($_GET["r"], ENT_QUOTES, "UTF-8") ?>"><?php endif; ?>
  <div class="fg"><label>Brand</label>
    <select name="brand_id" style="min-width:170px"><option value="">Tutti</option>
    <?php foreach($all_brands as $b): ?><option value="<?=$b['id']?>" <?=$f_brand==(int)$b['id']?'selected':''?>><?=h($b['name'])?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="fg"><label>Ranking</label>
    <select name="f_rank" style="min-width:140px">
      <option value="">Tutti</option>
      <option value="primary" <?=$f_rank==='primary'?'selected':''?>>⭐ Primario</option>
      <option value="secondary" <?=$f_rank==='secondary'?'selected':''?>>◐ Secondario</option>
    </select>
  </div>
  <?php if($HAS_MODEL): ?>
  <div class="fg"><label>Modello operativo</label>
    <select name="f_model" style="min-width:160px">
      <option value="">Tutti</option>
      <option value="volume" <?=$f_model==='volume'?'selected':''?>>📦 Acquisto a Volume</option>
      <option value="value" <?=$f_model==='value'?'selected':''?>>💎 Acquisto a Valore</option>
      <option value="academy" <?=$f_model==='academy'?'selected':''?>>🎓 Academy</option>
    </select>
  </div>
  <?php endif; ?>
  <button type="submit" class="btn btn-primary" style="margin-top:20px">Filtra</button>
  <?php if($f_brand||$f_rank||$f_model): ?><a href="brand_distributors.php" class="btn btn-sm" style="margin-top:20px">Reset</a><?php endif; ?>

  <div class="fg" style="margin-left:auto">
    <label style="visibility:hidden">.</label>
    <button type="button" onclick="window.print()" class="btn btn-sm" title="Stampa pagina"
            style="background:#fef3c7;color:#92400e;border-color:#fde68a">
      <i class="fa-solid fa-print"></i> Stampa
    </button>
  </div>
</form>

<!-- ═══ SCHEDE PER BRAND ═══ -->
<?php if(empty($by_brand)): ?>
<div style="text-align:center;padding:60px;background:#fff;border-radius:14px;border:1px dashed var(--border);color:var(--muted)">
  <i class="fa-solid fa-truck-field" style="font-size:40px;margin-bottom:16px;display:block;opacity:.3"></i>
  Nessun distributore trovato.
  <?php if($can_edit): ?><br><button onclick="openLink()" style="margin-top:12px" class="btn btn-primary btn-sm"><i class="fa-solid fa-link"></i> Associa il primo</button><?php endif; ?>
</div>
<?php endif; ?>

<?php foreach($by_brand as $bid => $bd): $br = $bd['brand']; ?>
<div class="card" style="margin-bottom:22px;border-top:3px solid <?=h($br['color'] ?? 'var(--p)')?>">
  <div class="card-header" style="background:#f8fafc">
    <span class="card-title" style="font-size:16px">
      <span style="width:10px;height:10px;border-radius:3px;background:<?=h($br['color'])?>;display:inline-block;margin-right:6px"></span>
      <?=h($br['name'])?>
    </span>
    <span style="font-size:11px;color:var(--muted)"><?=count($bd['primary'] ?? []) + count($bd['secondary'] ?? [])?> distributori</span>
    <?php if($can_edit): ?>
    <button onclick="openLink(<?=$bid?>)" class="btn btn-blue btn-sm" style="margin-left:auto"><i class="fa-solid fa-plus"></i> Aggiungi</button>
    <?php endif; ?>
  </div>

  <?php foreach(['primary','secondary'] as $rk):
    $group = $bd[$rk] ?? [];
    if(empty($group)) continue;
    $rm = $RANK[$rk];
  ?>
  <!-- Sezione ranking: <?=$rm['lbl']?> -->
  <div style="padding:8px 20px 2px">
    <div style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:6px;background:<?=$rm['bg']?>;color:<?=$rm['col']?>;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.4px">
      <i class="fa-solid <?=$rm['ico']?>" style="font-size:9px"></i> <?=$rm['lbl']?>
    </div>
  </div>

  <?php foreach($group as $d):
    // Contatti: brand-specifici sovrascrivono quelli generici
    $cn = $d['commercial_ref']   ?: $d['d_cn']; $ce = $d['commercial_email']  ?: $d['d_ce']; $cp = $d['commercial_phone']  ?: $d['d_cp'];
    $an = $d['academy_ref']      ?: $d['d_an']; $ae = $d['academy_email']     ?: $d['d_ae']; $ap = $d['academy_phone']     ?: $d['d_ap'];
    $ti = $TYPES[$d['dist_type']] ?? 'fa-building';
    $vol = (int)($d['is_volume'] ?? 0); $val = (int)($d['is_value'] ?? 0); $aca = (int)($d['is_academy'] ?? 0);
  ?>
  <div style="margin:8px 20px 12px;padding:16px;border-radius:12px;background:#fff;border:1px solid var(--border);display:flex;gap:18px;align-items:flex-start">
    <div style="flex:1;min-width:0">

      <!-- Riga titolo -->
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
        <div style="width:34px;height:34px;border-radius:9px;background:<?=$rm['bg']?>;color:<?=$rm['col']?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="fa-solid <?=$ti?>" style="font-size:14px"></i>
        </div>
        <div style="flex:1">
          <div style="font-weight:700;font-size:14px"><?=h($d['dist_name'])?></div>
          <div style="font-size:11px;color:var(--muted)">
            <?=h($d['dist_type'])?><?=$d['dist_city']?' · '.h($d['dist_city']):''?>
            <?php if($d['dist_web']): ?> · <a href="<?=h($d['dist_web'])?>" target="_blank" style="color:var(--p)">sito</a><?php endif; ?>
          </div>
        </div>
        <span style="font-size:18px;font-weight:800;color:<?=$rm['col']?>;font-family:'Courier New',monospace">#<?=$d['priority_order']?></span>
      </div>

      <!-- Badge modello operativo -->
      <?php if($vol || $val || $aca): ?>
      <div style="display:flex;gap:6px;margin-bottom:10px;flex-wrap:wrap">
        <?php if($vol): ?><span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:6px;background:#e0f2fe;color:#0369a1;font-size:10px;font-weight:700"><i class="fa-solid fa-cubes-stacked" style="font-size:9px"></i> Volume</span><?php endif; ?>
        <?php if($val): ?><span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:6px;background:#fef3c7;color:#92400e;font-size:10px;font-weight:700"><i class="fa-solid fa-gem" style="font-size:9px"></i> Valore</span><?php endif; ?>
        <?php if($aca): ?><span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:6px;background:#f3e8ff;color:#7c3aed;font-size:10px;font-weight:700"><i class="fa-solid fa-graduation-cap" style="font-size:9px"></i> Academy</span><?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Card Commerciale + Academy -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div style="padding:12px;border-radius:9px;background:#fef3c7;border:1px solid #fde68a">
          <div style="font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#92400e;margin-bottom:8px"><i class="fa-solid fa-handshake" style="margin-right:4px"></i> Commerciale</div>
          <?php if($cn): ?><div style="font-weight:700;font-size:12px;margin-bottom:4px"><?=h($cn)?></div><?php endif; ?>
          <?php if($ce): ?><div style="font-size:11px;color:#78350f;margin-bottom:2px"><i class="fa-solid fa-envelope" style="width:14px;font-size:10px;color:#d97706"></i> <a href="mailto:<?=h($ce)?>" style="color:#78350f"><?=h($ce)?></a></div><?php endif; ?>
          <?php if($cp): ?><div style="font-size:11px;color:#78350f"><i class="fa-solid fa-phone" style="width:14px;font-size:10px;color:#d97706"></i> <?=h($cp)?></div><?php endif; ?>
          <?php if(!$cn && !$ce && !$cp): ?><div style="font-size:11px;color:#92400e;font-style:italic">Non configurato</div><?php endif; ?>
        </div>
        <div style="padding:12px;border-radius:9px;background:#e0f2fe;border:1px solid #bae6fd">
          <div style="font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#0c4a6e;margin-bottom:8px"><i class="fa-solid fa-graduation-cap" style="margin-right:4px"></i> Academy</div>
          <?php if($an): ?><div style="font-weight:700;font-size:12px;margin-bottom:4px"><?=h($an)?></div><?php endif; ?>
          <?php if($ae): ?><div style="font-size:11px;color:#0c4a6e;margin-bottom:2px"><i class="fa-solid fa-envelope" style="width:14px;font-size:10px;color:#0369a1"></i> <a href="mailto:<?=h($ae)?>" style="color:#0c4a6e"><?=h($ae)?></a></div><?php endif; ?>
          <?php if($ap): ?><div style="font-size:11px;color:#0c4a6e"><i class="fa-solid fa-phone" style="width:14px;font-size:10px;color:#0369a1"></i> <?=h($ap)?></div><?php endif; ?>
          <?php if(!$an && !$ae && !$ap): ?><div style="font-size:11px;color:#0c4a6e;font-style:italic">Non configurato</div><?php endif; ?>
        </div>
      </div>

      <?php if($d['contract_ref'] || $d['discount_pct']): ?>
      <div style="margin-top:8px;font-size:11px;color:var(--muted)">
        <?php if($d['contract_ref']): ?><span><i class="fa-solid fa-file-contract" style="margin-right:3px"></i> Rif: <?=h($d['contract_ref'])?></span><?php endif; ?>
        <?php if($d['discount_pct']): ?><span style="margin-left:12px"><i class="fa-solid fa-percent" style="margin-right:3px"></i> Sconto: <?=$d['discount_pct']?>%</span><?php endif; ?>
      </div>
      <?php endif; ?>

    </div>

    <?php if($can_edit): ?>
    <div style="display:flex;flex-direction:column;gap:4px;flex-shrink:0">
      <button onclick='openLink(<?=$bid?>,<?=htmlspecialchars(json_encode($d),ENT_QUOTES,"UTF-8")?>)' class="btn btn-sm" title="Modifica"><i class="fa-solid fa-pen"></i></button>
      <?php if($can_del): ?>
      <form method="POST" style="display:inline" onsubmit="return confirm('Rimuovere?')">
            <?= csrf_field() ?>
        <input type="hidden" name="action" value="unlink"><input type="hidden" name="link_id" value="<?=$d['id']?>">
        <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-unlink"></i></button>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>

<!-- ═══ ANAGRAFICA ═══ -->
<div class="card" style="margin-top:10px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-warehouse" style="color:var(--p)"></i> Anagrafica Distributori</span></div>
  <table id="tDist" class="display" style="width:100%">
    <thead><tr><th>Nome</th><th>Tipo</th><th>Città</th><th>Commerciale</th><th>Academy</th><th>Stato</th><th>Brand</th><th>Azioni</th></tr></thead>
    <tbody>
    <?php foreach($all_distributors as $dd):
      $bls = $pdo->prepare("SELECT b.name, bd.ranking FROM brand_distributors bd JOIN brands b ON bd.brand_id=b.id WHERE bd.distributor_id=? ORDER BY bd.ranking,b.name");
      $bls->execute([$dd['id']]); $bl = $bls->fetchAll(); $bls->closeCursor();
      $st = match($dd['status']){'active'=>['Attivo','badge-success'],'paused'=>['Sospeso','badge-warning'],default=>['Inattivo','badge-neutral']};
    ?>
    <tr>
      <td><strong><?=h($dd['name'])?></strong></td>
      <td style="font-size:11px"><?=h($dd['type'])?></td>
      <td style="font-size:12px;color:var(--muted)"><?=h($dd['city'] ?? '—')?></td>
      <td style="font-size:11px"><?=h($dd['commercial_name'] ?? '—')?></td>
      <td style="font-size:11px"><?=h($dd['academy_name'] ?? '—')?></td>
      <td><span class="badge <?=$st[1]?>"><?=$st[0]?></span></td>
      <td><?php foreach($bl as $b): ?><span style="display:inline-block;padding:1px 7px;border-radius:4px;font-size:10px;font-weight:700;margin:1px;background:<?=$b['ranking']==='primary'?'#ecfdf5':'#eef2ff'?>;color:<?=$b['ranking']==='primary'?'#059669':'#6366f1'?>"><?=h($b['name'])?></span><?php endforeach; ?></td>
      <td>
        <?php if($can_edit): ?><button onclick='openDist(<?=htmlspecialchars(json_encode($dd),ENT_QUOTES,"UTF-8")?>)' class="btn btn-sm"><i class="fa-solid fa-pen"></i></button><?php endif; ?>
        <?php if($can_del): ?><form method="POST" style="display:inline" onsubmit="return confirm('Eliminare?')">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete_distributor"><input type="hidden" name="dist_id" value="<?=$dd['id']?>"><button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i></button></form><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ═══ MODAL: ANAGRAFICA DISTRIBUTORE ═══ -->
<div id="mDist" class="modal-overlay">
<div class="modal-box" style="width:660px">
  <div style="display:flex;justify-content:space-between;margin-bottom:18px">
    <h3 id="mDT" style="margin:0;font-size:16px">Distributore</h3>
    <button onclick="document.getElementById('mDist').style.display='none'" style="border:none;background:none;font-size:20px;cursor:pointer">&times;</button>
  </div>
  <form method="POST">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_distributor"><input type="hidden" name="dist_id" id="dd_id" value="0">
    <div class="grid-2">
      <div class="form-group"><label>Nome *</label><input type="text" name="name" id="dd_name" required></div>
      <div class="form-group"><label>Tipo</label><select name="type" id="dd_type"><option>Distributore</option><option>VAD</option><option>Rivenditore</option><option>Aggregatore</option></select></div>
    </div>
    <div class="grid-2">
      <div class="form-group"><label>Sito web</label><input type="url" name="website" id="dd_web"></div>
      <div class="form-group"><label>P.IVA</label><input type="text" name="vat_number" id="dd_vat"></div>
    </div>
    <div class="grid-3">
      <div class="form-group"><label>Indirizzo</label><input type="text" name="address" id="dd_addr"></div>
      <div class="form-group"><label>Città</label><input type="text" name="city" id="dd_city"></div>
      <div class="form-group"><label>Prov.</label><input type="text" name="province" id="dd_prov" maxlength="5"></div>
    </div>
    <div style="background:#fef3c7;padding:14px;border-radius:10px;border:1px solid #fde68a;margin-bottom:14px">
      <div style="font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;margin-bottom:10px"><i class="fa-solid fa-handshake"></i> Contatto Commerciale</div>
      <div class="grid-3">
        <div class="form-group" style="margin:0"><label>Nome</label><input type="text" name="commercial_name" id="dd_cn"></div>
        <div class="form-group" style="margin:0"><label>Email</label><input type="email" name="commercial_email" id="dd_ce"></div>
        <div class="form-group" style="margin:0"><label>Telefono</label><input type="text" name="commercial_phone" id="dd_cp"></div>
      </div>
    </div>
    <div style="background:#e0f2fe;padding:14px;border-radius:10px;border:1px solid #bae6fd;margin-bottom:14px">
      <div style="font-size:10px;font-weight:700;color:#0c4a6e;text-transform:uppercase;margin-bottom:10px"><i class="fa-solid fa-graduation-cap"></i> Contatto Academy</div>
      <div class="grid-3">
        <div class="form-group" style="margin:0"><label>Nome</label><input type="text" name="academy_name" id="dd_an"></div>
        <div class="form-group" style="margin:0"><label>Email</label><input type="email" name="academy_email" id="dd_ae"></div>
        <div class="form-group" style="margin:0"><label>Telefono</label><input type="text" name="academy_phone" id="dd_ap"></div>
      </div>
    </div>
    <div class="grid-2">
      <div class="form-group"><label>Stato</label><select name="status" id="dd_st"><option value="active">Attivo</option><option value="paused">Sospeso</option><option value="inactive">Inattivo</option></select></div>
      <div class="form-group"><label>Note</label><input type="text" name="notes" id="dd_notes"></div>
    </div>
    <div style="display:flex;gap:10px"><button type="submit" class="btn btn-primary" style="flex:1;justify-content:center">Salva</button><button type="button" onclick="document.getElementById('mDist').style.display='none'" class="btn" style="flex:1;justify-content:center">Annulla</button></div>
  </form>
</div></div>

<!-- ═══ MODAL: ASSOCIA BRAND ↔ DISTRIBUTORE ═══ -->
<div id="mLink" class="modal-overlay">
<div class="modal-box" style="width:660px">
  <div style="display:flex;justify-content:space-between;margin-bottom:18px">
    <h3 id="mLT" style="margin:0;font-size:16px">Associa distributore</h3>
    <button onclick="document.getElementById('mLink').style.display='none'" style="border:none;background:none;font-size:20px;cursor:pointer">&times;</button>
  </div>
  <form method="POST">
            <?= csrf_field() ?><input type="hidden" name="action" value="link_brand"><input type="hidden" name="link_id" id="lk_id" value="0">
    <div class="grid-2">
      <div class="form-group"><label>Brand *</label><select name="brand_id" id="lk_brand" required><option value="">— Seleziona —</option><?php foreach($all_brands as $b): ?><option value="<?=$b['id']?>"><?=h($b['name'])?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Distributore *</label><select name="distributor_id" id="lk_dist" required><option value="">— Seleziona —</option><?php foreach($all_distributors as $dd): ?><option value="<?=$dd['id']?>"><?=h($dd['name'])?> (<?=h($dd['type'])?>)</option><?php endforeach; ?></select></div>
    </div>
    <div class="grid-2">
      <div class="form-group"><label>Ranking *</label><select name="ranking" id="lk_rank"><option value="primary">⭐ Primario — di riferimento</option><option value="secondary">◐ Secondario — alternativo</option></select></div>
      <div class="form-group"><label>Ordine priorità</label><input type="number" name="priority_order" id="lk_ord" min="1" max="99" value="1"><div style="font-size:10px;color:var(--muted);margin-top:3px">1 = primo nella lista</div></div>
    </div>

    <!-- Modello operativo partnership -->
    <?php if($HAS_MODEL): ?>
    <div style="background:#f8fafc;padding:14px;border-radius:10px;border:1px solid var(--border);margin-bottom:14px">
      <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px"><i class="fa-solid fa-tags" style="color:var(--p)"></i> Modello operativo partnership</div>
      <div style="display:flex;gap:14px;flex-wrap:wrap">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:8px 14px;border-radius:8px;border:1.5px solid #bae6fd;background:#f0f9ff;flex:1;min-width:150px">
          <input type="checkbox" name="is_volume" id="lk_vol" value="1" style="width:18px;height:18px;accent-color:#0ea5e9">
          <div><div style="font-weight:700;font-size:12px;color:#0369a1"><i class="fa-solid fa-cubes-stacked" style="margin-right:4px"></i> Volume</div><div style="font-size:10px;color:#64748b">Grandi quantità, pricing</div></div>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:8px 14px;border-radius:8px;border:1.5px solid #fde68a;background:#fffbeb;flex:1;min-width:150px">
          <input type="checkbox" name="is_value" id="lk_val" value="1" style="width:18px;height:18px;accent-color:#f59e0b">
          <div><div style="font-weight:700;font-size:12px;color:#92400e"><i class="fa-solid fa-gem" style="margin-right:4px"></i> Valore</div><div style="font-size:10px;color:#64748b">Soluzioni, servizi pro</div></div>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:8px 14px;border-radius:8px;border:1.5px solid #d8b4fe;background:#faf5ff;flex:1;min-width:150px">
          <input type="checkbox" name="is_academy" id="lk_aca" value="1" style="width:18px;height:18px;accent-color:#8b5cf6">
          <div><div style="font-weight:700;font-size:12px;color:#6b21a8"><i class="fa-solid fa-graduation-cap" style="margin-right:4px"></i> Academy</div><div style="font-size:10px;color:#64748b">Formazione, voucher, lab</div></div>
        </label>
      </div>
    </div>
    <?php endif; ?>

    <div style="background:#fef3c7;padding:12px;border-radius:10px;border:1px solid #fde68a;margin-bottom:12px">
      <div style="font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;margin-bottom:8px"><i class="fa-solid fa-handshake"></i> Commerciale specifico brand <span style="font-weight:400;text-transform:none">(sovrascrive generico)</span></div>
      <div class="grid-3">
        <div class="form-group" style="margin:0"><label>Referente</label><input type="text" name="commercial_ref" id="lk_cr"></div>
        <div class="form-group" style="margin:0"><label>Email</label><input type="email" name="commercial_email_bd" id="lk_ce"></div>
        <div class="form-group" style="margin:0"><label>Telefono</label><input type="text" name="commercial_phone_bd" id="lk_cp"></div>
      </div>
    </div>
    <div style="background:#e0f2fe;padding:12px;border-radius:10px;border:1px solid #bae6fd;margin-bottom:12px">
      <div style="font-size:10px;font-weight:700;color:#0c4a6e;text-transform:uppercase;margin-bottom:8px"><i class="fa-solid fa-graduation-cap"></i> Academy specifico brand <span style="font-weight:400;text-transform:none">(sovrascrive generico)</span></div>
      <div class="grid-3">
        <div class="form-group" style="margin:0"><label>Referente</label><input type="text" name="academy_ref" id="lk_ar"></div>
        <div class="form-group" style="margin:0"><label>Email</label><input type="email" name="academy_email_bd" id="lk_ae"></div>
        <div class="form-group" style="margin:0"><label>Telefono</label><input type="text" name="academy_phone_bd" id="lk_ap"></div>
      </div>
    </div>
    <div class="grid-2">
      <div class="form-group"><label>Rif. contratto</label><input type="text" name="contract_ref" id="lk_cref"></div>
      <div class="form-group"><label>Sconto %</label><input type="number" name="discount_pct" id="lk_disc" step="0.01" min="0" max="100"></div>
    </div>
    <div class="form-group"><label>Note</label><textarea name="link_notes" id="lk_notes" rows="2"></textarea></div>
    <div style="display:flex;gap:10px"><button type="submit" class="btn btn-primary" style="flex:1;justify-content:center">Salva</button><button type="button" onclick="document.getElementById('mLink').style.display='none'" class="btn" style="flex:1;justify-content:center">Annulla</button></div>
  </form>
</div></div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function(){ $('#tDist').DataTable({pageLength:15,order:[[0,'asc']],language:{url:'//cdn.datatables.net/plug-ins/1.13.6/i18n/it-IT.json'}}); });

const DM={dd_name:'name',dd_type:'type',dd_web:'website',dd_vat:'vat_number',dd_addr:'address',dd_city:'city',dd_prov:'province',dd_st:'status',dd_cn:'commercial_name',dd_ce:'commercial_email',dd_cp:'commercial_phone',dd_an:'academy_name',dd_ae:'academy_email',dd_ap:'academy_phone',dd_notes:'notes'};
var HM=<?=$HAS_MODEL?'true':'false'?>;

function openDist(d){
  document.querySelector('#mDist form').reset(); document.getElementById('dd_id').value=0;
  document.getElementById('mDT').textContent=d?'Modifica: '+d.name:'Nuovo distributore';
  if(d){ document.getElementById('dd_id').value=d.id; Object.entries(DM).forEach(([e,k])=>{var el=document.getElementById(e);if(el&&d[k]!=null)el.value=d[k];}); }
  document.getElementById('mDist').style.display='flex';
}

function openLink(brandId,d){
  document.querySelector('#mLink form').reset(); document.getElementById('lk_id').value=0; document.getElementById('lk_ord').value=1;
  if(HM){document.getElementById('lk_vol').checked=false;document.getElementById('lk_val').checked=false;document.getElementById('lk_aca').checked=false;}
  document.getElementById('mLT').textContent=d?'Modifica associazione':'Associa distributore al brand';
  if(brandId) document.getElementById('lk_brand').value=brandId;
  if(d){
    document.getElementById('lk_id').value=d.id;
    document.getElementById('lk_brand').value=d.brand_id;
    document.getElementById('lk_dist').value=d.distributor_id;
    document.getElementById('lk_rank').value=d.ranking;
    document.getElementById('lk_ord').value=d.priority_order;
    if(HM){document.getElementById('lk_vol').checked=!!+d.is_volume;document.getElementById('lk_val').checked=!!+d.is_value;document.getElementById('lk_aca').checked=!!+d.is_academy;}
    if(d.commercial_ref) document.getElementById('lk_cr').value=d.commercial_ref;
    if(d.commercial_email) document.getElementById('lk_ce').value=d.commercial_email;
    if(d.commercial_phone) document.getElementById('lk_cp').value=d.commercial_phone;
    if(d.academy_ref) document.getElementById('lk_ar').value=d.academy_ref;
    if(d.academy_email) document.getElementById('lk_ae').value=d.academy_email;
    if(d.academy_phone) document.getElementById('lk_ap').value=d.academy_phone;
    if(d.contract_ref) document.getElementById('lk_cref').value=d.contract_ref;
    if(d.discount_pct) document.getElementById('lk_disc').value=d.discount_pct;
    if(d.notes) document.getElementById('lk_notes').value=d.notes;
  }
  document.getElementById('mLink').style.display='flex';
}
</script>
<?php require_once('footer.php'); ?>
