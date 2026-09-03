<?php
/**
 * ════════════════════════════════════════════════════════════════
 *  certV 2.4 — brand_patch.php
 *  PATCH DA APPLICARE A brand.php
 *
 *  MODIFICHE:
 *  1. Aggiunto campo priority (1-5) e priority_color nel form e CRUD
 *  2. Mappa colori priorità con badge visivo
 *  3. Conteggio tecnologie/servizi/prodotti per brand
 *  4. Link alla nuova pagina brand_technologies.php
 *  5. Ordinamento brand per priorità (1=massima)
 *
 *  ISTRUZIONI: Applicare le modifiche indicate di seguito a brand.php
 *  oppure sostituirlo con questa versione completa.
 * ════════════════════════════════════════════════════════════════
 */
require_once('access_control.php');
require_once('functions.php');

$u_id    = (int)$_SESSION['user_id'];
$u_role  = (int)($_SESSION['role_id'] ?? 99);
$can_edit = can('edit');

// ── MAPPA PRIORITÀ ───────────────────────────────────────────
$PRIORITY_MAP = [
    1 => ['label' => 'Critica',   'color' => '#dc2626', 'icon' => 'fa-fire',          'bg' => '#fef2f2'],
    2 => ['label' => 'Alta',      'color' => '#f59e0b', 'icon' => 'fa-arrow-up',      'bg' => '#fffbeb'],
    3 => ['label' => 'Media',     'color' => '#3b82f6', 'icon' => 'fa-minus',         'bg' => '#eff6ff'],
    4 => ['label' => 'Standard',  'color' => '#8b5cf6', 'icon' => 'fa-arrow-down',    'bg' => '#f5f3ff'],
    5 => ['label' => 'Bassa',     'color' => '#64748b', 'icon' => 'fa-chevron-down',  'bg' => '#f8fafc'],
];

// ─── CRUD (PRIMA di header.php per permettere redirect PRG) ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $id  = (int)($_POST['brand_id'] ?? 0);
    $st  = isset($_POST['storicizza']);

    try {
        $pdo->beginTransaction();

        if ($st && $id > 0) {
            $old = $pdo->prepare("SELECT * FROM brands WHERE id=?"); $old->execute([$id]);
            $old_data = $old->fetch();
            $old->closeCursor(); // FIX: previene unbuffered query
            $pdo->prepare("INSERT INTO brand_contacts_history (brand_id, archived_data, archived_by) VALUES (?,?,?)")
                ->execute([$id, json_encode($old_data), $u_id]);
        }

        // v2.4: Priorità e colore
        $priority = max(1, min(5, (int)($_POST['priority'] ?? 3)));
        $priority_color = $PRIORITY_MAP[$priority]['color'] ?? '#3b82f6';

        $data = [
            $_POST['partnership_level'],
            $priority,
            $priority_color,
            $_POST['pam_name']??null, $_POST['pam_email']??null, $_POST['pam_phone']??null, $_POST['pam_phone2']??null,
            $_POST['internal_bm_name']??null, $_POST['internal_bm_email']??null, $_POST['internal_bm_phone']??null,
            $_POST['brand_sl_name']??null, $_POST['brand_sl_email']??null, $_POST['brand_sl_phone']??null,
            $_POST['internal_sl_name']??null, $_POST['internal_sl_email']??null, $_POST['internal_sl_phone']??null,
            (int)$_POST['req_company'], (int)$_POST['req_commercial'], (int)$_POST['req_technical'],
            $_POST['learning_link']??null, $_POST['tech_doc_link']??null, $_POST['partner_portal_link']??null,
            $_POST['description']??null,
        ];

        if ($id > 0) {
            $sql = "UPDATE brands SET partnership_level=?,priority=?,priority_color=?,
                    pam_name=?,pam_email=?,pam_phone=?,pam_phone2=?,
                    internal_bm_name=?,internal_bm_email=?,internal_bm_phone=?,
                    brand_sl_name=?,brand_sl_email=?,brand_sl_phone=?,
                    internal_sl_name=?,internal_sl_email=?,internal_sl_phone=?,
                    req_company=?,req_commercial=?,req_technical=?,
                    learning_link=?,tech_doc_link=?,partner_portal_link=?,description=?
                    WHERE id=?";
            $pdo->prepare($sql)->execute([...$data, $id]);
        } else {
            $name = trim($_POST['name'] ?? '');
            if (!$name) throw new Exception("Nome brand obbligatorio.");
            $pdo->prepare(
                "INSERT INTO brands (name,partnership_level,priority,priority_color,pam_name,pam_email,pam_phone,pam_phone2,
                 internal_bm_name,internal_bm_email,internal_bm_phone,
                 brand_sl_name,brand_sl_email,brand_sl_phone,
                 internal_sl_name,internal_sl_email,internal_sl_phone,
                 req_company,req_commercial,req_technical,
                 learning_link,tech_doc_link,partner_portal_link,description) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([$name, ...$data]);
        }
        $pdo->commit();
        write_log('Brand', 'success', $id > 0 ? "Brand #$id aggiornato" : "Nuovo brand creato", $u_id);
        // v1.7.40: dopo creazione nuovo brand → redirect a brand_overview per completare info
        //          su update → resta su catalogo
        if ($id == 0) {
            $new_id = (int)$pdo->lastInsertId();
            $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Brand creato. Compila ora le altre sezioni (referenti, distributori, tecnologie).</div>";
            redirect('brand_overview', ['id' => $new_id]);
        }
        $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Brand aggiornato.</div>";
        redirect('brand');
    } catch (Exception $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        $msg = "<div class='alert alert-danger'>".h($e->getMessage())."</div>";
    }
}

if (isset($_GET['del']) && $u_role === 1) {
    $pdo->prepare("DELETE FROM brands WHERE id=?")->execute([(int)$_GET['del']]);
    $_SESSION['flash_msg'] = "<div class='alert alert-success'>Brand eliminato.</div>";
    redirect('brand');
}

// ── Da qui in poi si emette output HTML ──────────────────────────────────────
require_once('header.php');

// Leggi flash message (PRG pattern)
if (!isset($msg)) $msg = '';
if (!empty($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

// ─── FILTRI ──────────────────────────────────────────────────────────────────
$f_br = $_GET['f_br'] ?? [];
$f_pr = $_GET['f_pr'] ?? '';
$f_q  = trim((string)($_GET['f_q'] ?? '')); // v1.7.41: ricerca libera
$where = "WHERE 1=1"; $params = [];
if (!empty($f_br)) { $where .= " AND b.id IN(".implode(',',array_fill(0,count($f_br),'?')).")"; $params = $f_br; }
if ($f_pr !== '') { $where .= " AND b.priority = ?"; $params[] = (int)$f_pr; }
if ($f_q !== '') {
    // v1.7.41: cerca in nome, descrizione, partnership_level e in tutti i contatti
    //          (PAM, BM interno, SL brand, SL interno) + tecnologie associate
    $where .= " AND (
        b.name LIKE ? OR b.description LIKE ? OR b.partnership_level LIKE ?
        OR b.pam_name LIKE ? OR b.pam_email LIKE ?
        OR b.internal_bm_name LIKE ? OR b.internal_bm_email LIKE ?
        OR b.brand_sl_name LIKE ? OR b.brand_sl_email LIKE ?
        OR b.internal_sl_name LIKE ? OR b.internal_sl_email LIKE ?
        OR EXISTS (
            SELECT 1 FROM brand_technologies bt
             WHERE bt.brand_id = b.id
               AND (bt.name LIKE ? OR bt.category LIKE ?)
        )
        OR EXISTS (
            SELECT 1 FROM brand_distributors bd
             JOIN distributors d ON bd.distributor_id = d.id
             WHERE bd.brand_id = b.id AND d.name LIKE ?
        )
    )";
    $like = '%' . $f_q . '%';
    for ($i = 0; $i < 14; $i++) $params[] = $like;
}

// v2.4: ordinamento per priorità, poi nome
$brands_q = $pdo->prepare("SELECT b.* FROM brands b $where ORDER BY b.priority ASC, b.name ASC");
$brands_q->execute($params);
$results = $brands_q->fetchAll();
$all_brands = $pdo->query("SELECT id,name FROM brands ORDER BY priority, name")->fetchAll();

// Conteggio tecnologie per brand
$tech_counts = [];
try {
    $tc = $pdo->query(
        "SELECT brand_id, category, COUNT(*) c FROM brand_technologies WHERE status='active' GROUP BY brand_id, category"
    );
    foreach ($tc->fetchAll() as $r) {
        $tech_counts[$r['brand_id']][$r['category']] = (int)$r['c'];
    }
} catch (\Exception $e) { /* tabella non ancora creata */ }

// v1.7.42: pre-calcolo statistiche certificazioni per brand (1 query aggregata)
// Restituisce per ogni brand: cert totali in catalogo, cert possedute attive,
// dipendenti certificati distinti, split per categoria (aziendale/commerciale/tecnica)
$cert_stats = [];
try {
    // Cert totali nel catalogo (template per brand)
    $cs1 = $pdo->query("
        SELECT brand_id, COUNT(*) AS cnt
          FROM certifications
         WHERE is_active = 1
         GROUP BY brand_id
    ");
    foreach ($cs1->fetchAll() as $r) {
        $cert_stats[(int)$r['brand_id']]['catalog_total'] = (int)$r['cnt'];
    }

    // Cert attive POSSEDUTE (per brand + categoria + employees distinti)
    $cs2 = $pdo->query("
        SELECT c.brand_id, c.category,
               COUNT(uc.id) AS held,
               COUNT(DISTINCT uc.employee_id) AS emp_distinct
          FROM user_certifications uc
          JOIN certifications c ON uc.certification_id = c.id
         WHERE uc.status = 'active'
         GROUP BY c.brand_id, c.category
    ");
    foreach ($cs2->fetchAll() as $r) {
        $bid = (int)$r['brand_id'];
        $cat = $r['category'] ?: 'tecnica';
        $cert_stats[$bid]['held_by_cat'][$cat] = (int)$r['held'];
        $cert_stats[$bid]['held_total'] = ($cert_stats[$bid]['held_total'] ?? 0) + (int)$r['held'];
    }

    // Employees distinti certificati per brand (totali, senza split categoria)
    $cs3 = $pdo->query("
        SELECT c.brand_id, COUNT(DISTINCT uc.employee_id) AS emp_n
          FROM user_certifications uc
          JOIN certifications c ON uc.certification_id = c.id
         WHERE uc.status = 'active'
         GROUP BY c.brand_id
    ");
    foreach ($cs3->fetchAll() as $r) {
        $cert_stats[(int)$r['brand_id']]['employees_certified'] = (int)$r['emp_n'];
    }
} catch (\Throwable $e) { /* tabella non ancora creata */ }
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
  <div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:3px"><i class="fa-solid fa-tags" style="color:var(--p);margin-right:10px"></i>Directory Brand & Vendor</h1>
    <p style="color:var(--muted);font-size:13px">Governance centralizzata con classificazione priorità e catalogo tecnologico</p>
  </div>
  <div style="display:flex;gap:8px">
    <button onclick="exportBrandCSV()" class="btn btn-sm no-print"><i class="fa-solid fa-file-csv"></i> Esporta CSV</button>
    <button onclick="window.print()" class="btn btn-sm no-print"><i class="fa-solid fa-print"></i> Stampa</button>
    <a href="brand_technologies.php" class="btn btn-sm" style="background:#6366f120;color:#6366f1;border-color:#6366f130">
      <i class="fa-solid fa-microchip"></i> Catalogo tecnologie
    </a>
    <?php if($can_edit): ?>
    <button onclick="openBrandModal()" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Nuovo brand</button>
    <?php endif; ?>
  </div>
</div>

<?=$msg?>

<!-- Filtri -->
<form method="GET" class="filter-bar" style="align-items:flex-start">
<?php if (!empty($_GET["r"])): ?><input type="hidden" name="r" value="<?= htmlspecialchars($_GET["r"], ENT_QUOTES, "UTF-8") ?>"><?php endif; ?>
  <div class="fg" style="flex:1;min-width:240px">
    <label>Ricerca libera</label>
    <input type="search" name="f_q" value="<?= h($f_q) ?>"
           placeholder="Cerca nome, contatto, tecnologia, distributore..."
           style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px;font-size:13px;background:#fff">
    <div style="font-size:10px;color:var(--muted);margin-top:3px">
      Cerca in nome, descrizione, partnership, contatti (PAM, BM, SL), tecnologie, distributori
    </div>
  </div>
  <div class="fg">
    <label>Filtra per brand</label>
    <div style="background:#fff;border:1px solid var(--border);border-radius:8px;height:90px;overflow-y:auto;padding:8px;min-width:200px">
      <?php foreach($all_brands as $b): ?>
      <label style="display:flex;gap:7px;font-size:12px;margin-bottom:3px;cursor:pointer;align-items:center">
        <input type="checkbox" name="f_br[]" value="<?=$b['id']?>" <?=in_array($b['id'],$f_br)?'checked':''?>>
        <?=h($b['name'])?>
      </label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="fg">
    <label>Priorità</label>
    <select name="f_pr">
      <option value="">Tutte</option>
      <?php foreach($PRIORITY_MAP as $k=>$v): ?>
      <option value="<?=$k?>" <?=$f_pr!==''&&(int)$f_pr===$k?'selected':''?>>
        <?=$k?> — <?=$v['label']?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div style="display:flex;flex-direction:column;gap:6px;padding-top:22px">
    <button type="submit" class="btn btn-primary">Filtra</button>
    <a href="brand.php" class="btn btn-sm" style="text-align:center">Reset</a>
  </div>

  <div class="fg" style="margin-left:auto">
    <label style="visibility:hidden">.</label>
    <button type="button" onclick="window.print()" class="btn btn-sm" title="Stampa pagina"
            style="background:#fef3c7;color:#92400e;border-color:#fde68a">
      <i class="fa-solid fa-print"></i> Stampa
    </button>
  </div>
</form>

<!-- Legenda priorità -->
<div style="display:flex;gap:12px;margin-bottom:18px;flex-wrap:wrap">
  <?php foreach($PRIORITY_MAP as $k=>$v): ?>
  <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;color:<?=$v['color']?>">
    <span style="width:10px;height:10px;border-radius:3px;background:<?=$v['color']?>"></span>
    P<?=$k?>: <?=$v['label']?>
  </span>
  <?php endforeach; ?>
</div>

<!-- Cards brand -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(380px,1fr));gap:20px">
<?php foreach($results as $b):
    $req=(int)$b['req_technical'];
    $pct_val = 100;
    // v1.7.42: usa $cert_stats pre-calcolato invece di N query
    $cs = $cert_stats[(int)$b['id']] ?? [];
    $emp_certified = (int)($cs['employees_certified'] ?? 0);
    if($req>0){
        $pct_val=min(100,round($emp_certified/$req*100));
    }
    $comp_col=$pct_val>=80?'var(--success)':($pct_val>=60?'var(--warning)':'var(--danger)');
    $pr = (int)($b['priority'] ?? 3);
    $pm = $PRIORITY_MAP[$pr] ?? $PRIORITY_MAP[3];
    $tc = $tech_counts[$b['id']] ?? [];
    $tc_total = array_sum($tc);
?>
<div style="background:#fff;border-radius:14px;border:1px solid var(--border);overflow:hidden;display:flex;flex-direction:column;box-shadow:0 1px 3px rgba(0,0,0,.04);border-top:3px solid <?=$pm['color']?>">

  <!-- Header card -->
  <div style="padding:16px 20px;background:#f8fafc;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
    <div>
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
        <h3 style="margin:0;color:var(--p);font-size:17px">
          <a href="<?= url_safe('brand_overview', ['id' => (int)$b['id']]) ?>" style="color:var(--p);text-decoration:none" title="Vista 360°"><?=h($b['name'])?></a>
        </h3>
        <!-- Badge priorità -->
        <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:6px;font-size:9px;font-weight:800;background:<?=$pm['bg']?>;color:<?=$pm['color']?>;text-transform:uppercase;letter-spacing:.3px">
          <i class="fa-solid <?=$pm['icon']?>" style="font-size:8px"></i> P<?=$pr?> <?=$pm['label']?>
        </span>
      </div>
      <?php if($b['description']): ?><div style="font-size:11px;color:var(--muted);margin-top:2px"><?=h(substr($b['description'],0,60))?></div><?php endif; ?>
    </div>
    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:5px">
      <span class="badge badge-info"><?=h($b['partnership_level'])?></span>
      <span style="font-weight:800;font-size:13px;color:<?=$comp_col?>"><?=$pct_val?>% compliance</span>
    </div>
  </div>

  <!-- Compliance bar -->
  <div style="padding:0 20px;margin-top:12px">
    <div style="height:6px;background:#f1f5f9;border-radius:3px">
      <div style="height:6px;background:<?=$comp_col?>;border-radius:3px;width:<?=$pct_val?>%;transition:width .4s"></div>
    </div>
  </div>

  <!-- Dati -->
  <div style="padding:14px 20px;flex:1">

    <!-- v1.7.42: Certificazioni possedute -->
    <?php
      $cat_total   = (int)($cs['catalog_total']  ?? 0);
      $held_total  = (int)($cs['held_total']     ?? 0);
      $held_az     = (int)($cs['held_by_cat']['aziendale']   ?? 0);
      $held_comm   = (int)($cs['held_by_cat']['commerciale'] ?? 0);
      $held_tech   = (int)($cs['held_by_cat']['tecnica']     ?? 0);
    ?>
    <?php if ($cat_total > 0 || $held_total > 0 || $emp_certified > 0): ?>
    <div style="background:#fafbfc;border:1px solid var(--border);border-radius:8px;padding:10px 12px;margin-bottom:12px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px">
          <i class="fa-solid fa-certificate" style="color:#dc2626"></i> Certificazioni possedute
        </div>
        <div style="font-size:11px;color:#64748b">
          <strong style="color:#0f172a;font-size:14px"><?= $held_total ?></strong> attive
          · <?= $emp_certified ?> dipendenti
          <?php if ($cat_total > 0): ?> · catalogo <?= $cat_total ?><?php endif; ?>
        </div>
      </div>
      <?php if ($held_total > 0): ?>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <?php if ($held_az > 0): ?>
        <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:6px;background:#7c3aed15;color:#7c3aed;font-size:10px;font-weight:700" title="Aziendali">
          <i class="fa-solid fa-building" style="font-size:9px"></i> <?= $held_az ?> Az.
        </span>
        <?php endif; ?>
        <?php if ($held_comm > 0): ?>
        <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:6px;background:#0ea5e915;color:#0ea5e9;font-size:10px;font-weight:700" title="Commerciali">
          <i class="fa-solid fa-handshake" style="font-size:9px"></i> <?= $held_comm ?> Comm.
        </span>
        <?php endif; ?>
        <?php if ($held_tech > 0): ?>
        <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:6px;background:#16a34a15;color:#16a34a;font-size:10px;font-weight:700" title="Tecniche">
          <i class="fa-solid fa-wrench" style="font-size:9px"></i> <?= $held_tech ?> Tec.
        </span>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <div style="font-size:11px;color:#94a3b8;font-style:italic">Nessuna certificazione attiva</div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Tecnologie/Servizi/Prodotti -->
    <?php if($tc_total > 0): ?>
    <div style="display:flex;gap:10px;margin-bottom:12px;flex-wrap:wrap">
      <?php if(!empty($tc['Tecnologia'])): ?>
      <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:6px;background:#6366f115;color:#6366f1;font-size:10px;font-weight:700">
        <i class="fa-solid fa-microchip" style="font-size:9px"></i> <?=$tc['Tecnologia']?> Tecnologie
      </span>
      <?php endif; ?>
      <?php if(!empty($tc['Servizio'])): ?>
      <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:6px;background:#0ea5e915;color:#0ea5e9;font-size:10px;font-weight:700">
        <i class="fa-solid fa-cloud" style="font-size:9px"></i> <?=$tc['Servizio']?> Servizi
      </span>
      <?php endif; ?>
      <?php if(!empty($tc['Prodotto'])): ?>
      <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:6px;background:#f59e0b15;color:#f59e0b;font-size:10px;font-weight:700">
        <i class="fa-solid fa-box-open" style="font-size:9px"></i> <?=$tc['Prodotto']?> Prodotti
      </span>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Requisiti -->
    <div style="font-size:9px;font-weight:800;text-transform:uppercase;color:var(--muted);margin-bottom:6px">Requisiti partnership</div>
    <div style="display:flex;gap:16px;font-size:12px;color:#475569">
      <span>🏢 Aziendale: <strong><?=$b['req_company']?></strong></span>
      <span>💰 Commerciale: <strong><?=$b['req_commercial']?></strong></span>
      <span>🔧 Tecnico: <strong><?=$b['req_technical']?></strong></span>
    </div>
  </div>

  <!-- Footer -->
  <div style="padding:12px 20px;background:#f8fafc;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
    <div style="display:flex;gap:8px">
      <?php if($b['learning_link']): ?><a href="<?=h($b['learning_link'])?>" target="_blank" class="btn btn-sm"><i class="fa-solid fa-graduation-cap"></i></a><?php endif; ?>
      <?php if($b['tech_doc_link']): ?><a href="<?=h($b['tech_doc_link'])?>" target="_blank" class="btn btn-sm"><i class="fa-solid fa-book"></i></a><?php endif; ?>
      <?php if($b['partner_portal_link']): ?><a href="<?=h($b['partner_portal_link'])?>" target="_blank" class="btn btn-sm"><i class="fa-solid fa-globe"></i></a><?php endif; ?>
      <a href="brand_technologies.php?brand_id=<?=$b['id']?>" class="btn btn-sm" style="color:#6366f1" title="Tecnologie"><i class="fa-solid fa-microchip"></i></a>
    </div>
    <div style="display:flex;gap:8px">
      <a href="brand_referents.php?brand_id=<?=$b['id']?>" class="btn btn-blue btn-sm"><i class="fa-solid fa-users"></i></a>
      <a href="<?= url_safe('brand_overview', ['id' => (int)$b['id']]) ?>" class="btn btn-sm btn-primary" style="background:#7c3aed" title="Apri scheda completa con tutte le sezioni editabili"><i class="fa-solid fa-arrow-up-right-from-square"></i> Apri scheda</a>
      <?php if($can_edit): ?><button onclick='openBrandModal(<?=htmlspecialchars(json_encode($b), ENT_QUOTES, "UTF-8")?>)' class="btn btn-sm" title="Quick-edit campi essenziali"><i class="fa-solid fa-pen"></i></button><?php endif; ?>
      <?php if($u_role===1): ?><a href="<?= qs_self_safe(['del'=>''.($b['id']).'']) ?>" class="btn btn-danger btn-sm" onclick="return confirm('Eliminare brand e tutti i dati collegati?')"><i class="fa-solid fa-trash"></i></a><?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php if(empty($results)): ?>
<div style="grid-column:span 3;text-align:center;padding:60px;background:#fff;border-radius:12px;border:1px dashed var(--border);color:var(--muted)">
  <i class="fa-solid fa-tags" style="font-size:40px;margin-bottom:16px;display:block;opacity:.4"></i>
  Nessun brand trovato<?php if($f_q): ?> per la ricerca "<strong><?= h($f_q) ?></strong>"<?php endif; ?>. <a href="brand.php" style="color:var(--p)">Reset filtri</a>
</div>
<?php endif; ?>
</div>

<!-- MODAL BRAND v2.4 (con priorità) -->
<div id="mBrand" class="modal-overlay">
<div class="modal-box" style="width:720px">
  <div style="display:flex;justify-content:space-between;margin-bottom:20px">
    <h3 id="mBrandTitle" style="margin:0;font-size:16px">Brand</h3>
    <button onclick="document.getElementById('mBrand').style.display='none'" style="border:none;background:none;font-size:20px;cursor:pointer">&times;</button>
  </div>
  <form method="POST">
            <?= csrf_field() ?>
    <input type="hidden" name="brand_id" id="mb_id" value="0">

    <div id="mb_name_row" class="form-group">
      <label>Nome brand *</label>
      <input type="text" name="name" id="mb_name" placeholder="Es. Microsoft, Cisco, AWS...">
    </div>

    <!-- v2.4: Priorità e livello partnership -->
    <div style="background:#f0f9ff;padding:14px;border-radius:10px;border:1px solid #bae6fd;margin-bottom:14px">
      <div style="font-size:10px;font-weight:700;color:#0369a1;text-transform:uppercase;margin-bottom:10px">
        <i class="fa-solid fa-star"></i> Classificazione
      </div>
      <div class="grid-2">
        <div class="form-group" style="margin:0">
          <label>Priorità / Importanza</label>
          <select name="priority" id="mb_priority" onchange="updatePriorityPreview(this.value)">
            <?php foreach($PRIORITY_MAP as $k=>$v): ?>
            <option value="<?=$k?>" <?=$k===3?'selected':''?>  data-color="<?=$v['color']?>">
              <?=$k?> — <?=$v['label']?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin:0">
          <label>Livello partnership</label>
          <input type="text" name="partnership_level" id="mb_level" placeholder="Gold, Silver, Platinum...">
        </div>
      </div>
      <div id="priority_preview" style="margin-top:10px;display:flex;align-items:center;gap:10px">
        <div id="pp_badge" style="display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:8px;font-size:11px;font-weight:800;background:#eff6ff;color:#3b82f6">
          <i class="fa-solid fa-minus" id="pp_icon"></i> <span id="pp_text">P3 Media</span>
        </div>
        <span style="font-size:11px;color:var(--muted)">Anteprima badge nella card</span>
      </div>
    </div>

    <div class="form-group"><label>Note / descrizione</label><input type="text" name="description" id="mb_desc"></div>

    <div style="background:#f0f9ff;padding:14px;border-radius:10px;border:1px solid #bae6fd;margin-bottom:14px">
      <div style="font-size:10px;font-weight:700;color:#0369a1;text-transform:uppercase;margin-bottom:10px">PAM — Partner Account Manager</div>
      <div class="grid-2">
        <div class="form-group" style="margin:0"><label>Nome</label><input type="text" name="pam_name" id="mb_pam_name"></div>
        <div class="form-group" style="margin:0"><label>Email</label><input type="email" name="pam_email" id="mb_pam_email"></div>
        <div class="form-group" style="margin:0"><label>Telefono 1</label><input type="text" name="pam_phone" id="mb_pam_phone"></div>
        <div class="form-group" style="margin:0"><label>Telefono 2</label><input type="text" name="pam_phone2" id="mb_pam_phone2"></div>
      </div>
    </div>

    <div style="background:#f0fdf4;padding:14px;border-radius:10px;border:1px solid #bbf7d0;margin-bottom:14px">
      <div style="font-size:10px;font-weight:700;color:#166534;text-transform:uppercase;margin-bottom:10px">Brand Manager interno</div>
      <div class="grid-2">
        <div class="form-group" style="margin:0"><label>Nome</label><input type="text" name="internal_bm_name" id="mb_bm_name"></div>
        <div class="form-group" style="margin:0"><label>Email</label><input type="email" name="internal_bm_email" id="mb_bm_email"></div>
        <div class="form-group" style="margin:0"><label>Telefono</label><input type="text" name="internal_bm_phone" id="mb_bm_phone"></div>
      </div>
    </div>

    <div style="background:#fff7ed;padding:14px;border-radius:10px;border:1px solid #fed7aa;margin-bottom:14px">
      <div style="font-size:10px;font-weight:700;color:#9a3412;text-transform:uppercase;margin-bottom:10px">Referente Sales</div>
      <div class="grid-2">
        <div class="form-group" style="margin:0"><label>SL Vendor</label><input type="text" name="brand_sl_name" id="mb_sl_name"></div>
        <div class="form-group" style="margin:0"><label>Email SL vendor</label><input type="email" name="brand_sl_email" id="mb_sl_email"></div>
        <div class="form-group" style="margin:0"><label>SL Interno</label><input type="text" name="internal_sl_name" id="mb_isl_name"></div>
        <div class="form-group" style="margin:0"><label>Email SL interno</label><input type="email" name="internal_sl_email" id="mb_isl_email"></div>
      </div>
    </div>

    <div class="grid-3">
      <div class="form-group"><label>Req. aziendale</label><input type="number" name="req_company" id="mb_req_co" min="0" value="0"></div>
      <div class="form-group"><label>Req. commerciale</label><input type="number" name="req_commercial" id="mb_req_com" min="0" value="0"></div>
      <div class="form-group"><label>Req. tecnico</label><input type="number" name="req_technical" id="mb_req_tec" min="0" value="0"></div>
    </div>
    <div class="grid-3">
      <div class="form-group"><label>Link Learning</label><input type="text" name="learning_link" id="mb_learn"></div>
      <div class="form-group"><label>Link Tech Docs</label><input type="text" name="tech_doc_link" id="mb_docs"></div>
      <div class="form-group"><label>Partner Portal</label><input type="text" name="partner_portal_link" id="mb_portal"></div>
    </div>

    <div style="background:#fff7ed;padding:12px;border-radius:9px;border:1px solid #fed7aa;margin-bottom:16px">
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
        <input type="checkbox" name="storicizza" value="1" checked style="width:18px;height:18px">
        <div><strong style="font-size:13px;color:#9a3412">Storicizza questa modifica</strong><br><span style="font-size:11px;color:#c2410c">Salva copia JSON dei dati attuali prima di sovrascrivere</span></div>
      </label>
    </div>

    <div style="display:flex;gap:10px">
      <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:12px">Salva brand</button>
      <button type="button" onclick="document.getElementById('mBrand').style.display='none'" class="btn" style="flex:1;justify-content:center;padding:12px">Annulla</button>
    </div>
  </form>
</div>
</div>

<script>
const PMAP = <?=json_encode($PRIORITY_MAP)?>;
const MAP = {
  mb_level:'partnership_level',mb_desc:'description',
  mb_pam_name:'pam_name',mb_pam_email:'pam_email',mb_pam_phone:'pam_phone',mb_pam_phone2:'pam_phone2',
  mb_bm_name:'internal_bm_name',mb_bm_email:'internal_bm_email',mb_bm_phone:'internal_bm_phone',
  mb_sl_name:'brand_sl_name',mb_sl_email:'brand_sl_email',
  mb_isl_name:'internal_sl_name',mb_isl_email:'internal_sl_email',
  mb_req_co:'req_company',mb_req_com:'req_commercial',mb_req_tec:'req_technical',
  mb_learn:'learning_link',mb_docs:'tech_doc_link',mb_portal:'partner_portal_link'
};

function updatePriorityPreview(val) {
  const p = PMAP[val] || PMAP['3'];
  document.getElementById('pp_badge').style.background = p.bg;
  document.getElementById('pp_badge').style.color = p.color;
  document.getElementById('pp_icon').className = 'fa-solid ' + p.icon;
  document.getElementById('pp_text').textContent = 'P' + val + ' ' + p.label;
}

function openBrandModal(data=null){
  document.querySelector('#mBrand form').reset();
  document.getElementById('mb_id').value=0;
  document.getElementById('mb_req_co').value=0;
  document.getElementById('mb_req_com').value=0;
  document.getElementById('mb_req_tec').value=0;
  document.getElementById('mb_priority').value=3;
  updatePriorityPreview(3);
  document.getElementById('mb_name_row').style.display=data?'none':'block';
  document.getElementById('mBrandTitle').textContent=data?'Modifica: '+data.name:'Nuovo brand';
  if(data){
    document.getElementById('mb_id').value=data.id;
    if(data.priority) { document.getElementById('mb_priority').value=data.priority; updatePriorityPreview(data.priority); }
    Object.entries(MAP).forEach(([eid,key])=>{
      const el=document.getElementById(eid);
      if(el&&data[key]!==null&&data[key]!==undefined) el.value=data[key];
    });
  }
  document.getElementById('mBrand').style.display='flex';
}
</script>

<script>
function exportBrandCSV(){
  let rows = "\uFEFF" + "Nome,Priorità,Livello Partnership,Compliance %,PAM,PAM Email,Brand Manager,BM Email,Brand SL,SL Email,Internal SL,ISL Email,Req Aziendali,Req Commerciali,Req Tecnici,Descrizione\n";
  <?php foreach($results as $b):
    $req=(int)$b['req_technical']; $pv=100;
    if($req>0){ $aq=$pdo->prepare("SELECT COUNT(DISTINCT uc.employee_id) FROM user_certifications uc JOIN certifications c ON uc.certification_id=c.id WHERE c.brand_id=? AND uc.status='active'"); $aq->execute([$b['id']]); $pv=min(100,round((int)$aq->fetchColumn()/$req*100)); $aq->closeCursor(); }
  ?>
  rows += <?=json_encode(
    '"'.addslashes($b['name']).'","P'.($b['priority']??3).' '.($PRIORITY_MAP[(int)($b['priority']??3)]['label']??'').'",'
    .'"'.addslashes($b['partnership_level']??'').'",'
    .'"'.$pv.'%",'
    .'"'.addslashes($b['pam_name']??'').'","'.addslashes($b['pam_email']??'').'",'
    .'"'.addslashes($b['internal_bm_name']??'').'","'.addslashes($b['internal_bm_email']??'').'",'
    .'"'.addslashes($b['brand_sl_name']??'').'","'.addslashes($b['brand_sl_email']??'').'",'
    .'"'.addslashes($b['internal_sl_name']??'').'","'.addslashes($b['internal_sl_email']??'').'",'
    .'"'.($b['req_company']??0).'","'.($b['req_commercial']??0).'","'.($b['req_technical']??0).'",'
    .'"'.addslashes(str_replace(["\r","\n"],' ',$b['description']??'')).'"'
  )?> + "\n";
  <?php endforeach; ?>
  const a = document.createElement('a');
  a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(rows);
  a.download = 'brand_directory_<?=date('Y-m-d')?>.csv';
  a.click();
}
</script>

<?php require_once('footer.php'); ?>
