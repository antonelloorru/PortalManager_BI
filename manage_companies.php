<?php
/**
 * certV 2.2 — manage_companies.php
 * FIX v2.2: company_id/location_id in employees (non users)
 * FIX: azienda principale (id=1) modificabile ma non eliminabile
 */
require_once('access_control.php');
require_once('header.php');

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
$msg    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Salva azienda (INSERT o UPDATE) ──────────────────────────────────────
    if ($action === 'save_company') {
        $id   = (int)($_POST['company_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $lr   = trim($_POST['legal_representative'] ?? '') ?: null;
        $vat  = trim($_POST['vat_number'] ?? '') ?: null;

        if (!$name) {
            $msg = "<div class='alert alert-warning'>Il nome è obbligatorio.</div>";
        } else {
            try {
                if ($id > 0) {
                    $pdo->prepare("UPDATE companies SET name=?, legal_representative=?, vat_number=? WHERE id=?")
                        ->execute([$name, $lr, $vat, $id]);
                    write_log('Companies','success', ($id===1?'Rinominata azienda principale':'Modifica azienda')." id=$id", $u_id);
                    $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Azienda " . ($id===1 ? '<strong>principale</strong>' : '') . " aggiornata.</div>";
                } else {
                    $pdo->prepare("INSERT INTO companies (name, legal_representative, vat_number) VALUES (?,?,?)")
                        ->execute([$name, $lr, $vat]);
                    write_log('Companies','success',"Nuova azienda: $name",$u_id);
                    $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Azienda creata.</div>";
                }
            } catch (Exception $e) {
                $msg = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
            }
        }
    }

    // ── Elimina azienda (mai la principale) ──────────────────────────────────
    if ($action === 'delete_company') {
        $id = (int)$_POST['company_id'];
        if ($id === 1) {
            $msg = "<div class='alert alert-warning'>L'azienda principale non può essere eliminata — solo rinominata.</div>";
        } else {
            // v2.2: controlla dipendenti (non users)
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE company_id=?");
            $cnt->execute([$id]);
            if ($cnt->fetchColumn() > 0) {
                $msg = "<div class='alert alert-danger'>Ci sono dipendenti assegnati. Riassegnarli prima di eliminare.</div>";
            } else {
                $pdo->prepare("DELETE FROM companies WHERE id=?")->execute([$id]);
                $msg = "<div class='alert alert-success'>Azienda eliminata.</div>";
            }
        }
    }

    // ── Salva sede ────────────────────────────────────────────────────────────
    if ($action === 'save_location') {
        $id  = (int)($_POST['location_id'] ?? 0);
        $cid = (int)$_POST['company_id_for_loc'];
        $d   = [
            $cid,
            trim($_POST['location_name'] ?? ''),
            trim($_POST['address'] ?? '') ?: null,
            trim($_POST['phone'] ?? '') ?: null,
            trim($_POST['email'] ?? '') ?: null,
            trim($_POST['email_pec'] ?? '') ?: null,
            trim($_POST['manager_site'] ?? '') ?: null,
            trim($_POST['manager_it'] ?? '') ?: null,
            trim($_POST['manager_service'] ?? '') ?: null,
            trim($_POST['manager_admin'] ?? '') ?: null,
        ];
        if ($id > 0) {
            $pdo->prepare("UPDATE company_locations SET company_id=?,location_name=?,address=?,phone=?,email=?,email_pec=?,manager_site=?,manager_it=?,manager_service=?,manager_admin=? WHERE id=?")
                ->execute([...$d, $id]);
        } else {
            $pdo->prepare("INSERT INTO company_locations (company_id,location_name,address,phone,email,email_pec,manager_site,manager_it,manager_service,manager_admin) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute($d);
        }
        $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Sede salvata.</div>";
    }

    // ── Elimina sede ──────────────────────────────────────────────────────────
    if ($action === 'delete_location') {
        $id = (int)$_POST['location_id'];
        // v2.2: nullify location_id in employees (non users)
        $pdo->prepare("UPDATE employees SET location_id=NULL WHERE location_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM company_locations WHERE id=?")->execute([$id]);
        $msg = "<div class='alert alert-success'>Sede rimossa.</div>";
    }
}

// ── LEGGI DATI ────────────────────────────────────────────────────────────────
// v2.2: conta dipendenti da employees.company_id
$companies = $pdo->query(
    "SELECT co.*,
            COUNT(e.id) emp_count,
            (SELECT COUNT(*) FROM company_locations cl WHERE cl.company_id=co.id) loc_count
     FROM companies co
     LEFT JOIN employees e ON e.company_id=co.id AND e.status='active'
     GROUP BY co.id ORDER BY co.id"
)->fetchAll();

$sel = (int)($_GET['c'] ?? 0);
if (!$sel && !empty($companies)) $sel = 1; // default: azienda principale

$locations    = [];
$company_data = null;
if ($sel > 0) {
    $ls = $pdo->prepare("SELECT * FROM company_locations WHERE company_id=? ORDER BY location_name");
    $ls->execute([$sel]);
    $locations = $ls->fetchAll();
    $cs = $pdo->prepare("SELECT * FROM companies WHERE id=?");
    $cs->execute([$sel]);
    $company_data = $cs->fetch();
}
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
  <div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:3px">
      <i class="fa-solid fa-building-user" style="color:var(--p);margin-right:10px"></i>Aziende &amp; Sedi
    </h1>
    <p style="color:var(--muted);font-size:13px"><?=count($companies)?> aziend<?=count($companies)==1?'a':'e'?> registrat<?=count($companies)==1?'a':'e'?></p>
  </div>
  <button onclick="openCoModal()" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Nuova azienda</button>
</div>

<?=$msg?>

<div style="display:grid;grid-template-columns:260px 1fr;gap:22px">

  <!-- ── LISTA AZIENDE ──────────────────────────────────────────────────── -->
  <div class="card" style="padding:0;height:fit-content">
    <div style="padding:10px 16px;background:#f8fafc;border-bottom:1px solid var(--border);font-size:10px;font-weight:700;text-transform:uppercase;color:var(--muted)">
      Aziende
    </div>

    <?php foreach($companies as $co):
      $is_s = ($sel == $co['id']);
      $is_main = ($co['id'] === 1);
    ?>
    <a href="<?= qs_self_safe(['c'=>''.($co['id']).'']) ?>"
       style="display:flex;align-items:center;justify-content:space-between;padding:11px 14px;text-decoration:none;color:#1e293b;border-bottom:1px solid #f8fafc;<?=$is_s?'background:#e0f2fe;border-left:3px solid var(--p);':''?>">
      <div style="min-width:0;flex:1">
        <div style="font-weight:<?=$is_s?700:600?>;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
          <?=h($co['name'])?>
          <?php if($is_main): ?>
          <span style="font-size:9px;background:#fef3c7;color:#92400e;padding:1px 5px;border-radius:4px;margin-left:4px;font-weight:700">PRINCIPALE</span>
          <?php endif; ?>
        </div>
        <div style="font-size:10px;color:var(--muted)"><?=$co['emp_count']?> dipendenti · <?=$co['loc_count']?> sedi</div>
      </div>
      <div style="display:flex;gap:4px;flex-shrink:0;margin-left:6px" onclick="event.stopPropagation()">
        <!-- Modifica: disponibile per TUTTE le aziende inclusa la principale -->
        <button onclick="event.preventDefault();openCoModal(<?=json_encode($co,JSON_HEX_APOS|JSON_HEX_QUOT)?>)"
                class="btn btn-sm" style="padding:3px 7px;font-size:10px" title="Modifica azienda">
          <i class="fa-solid fa-pen"></i>
        </button>
        <!-- Elimina: solo per aziende non principali -->
        <?php if(!$is_main): ?>
        <form method="POST" style="display:inline" onsubmit="event.stopPropagation();return confirm('Eliminare questa azienda?')">
            <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_company">
          <input type="hidden" name="company_id" value="<?=$co['id']?>">
          <button type="submit" class="btn btn-danger btn-sm" style="padding:3px 7px;font-size:10px" title="Elimina">
            <i class="fa-solid fa-trash"></i>
          </button>
        </form>
        <?php else: ?>
        <span style="padding:3px 7px;font-size:9px;color:var(--muted)" title="L'azienda principale non può essere eliminata">
          <i class="fa-solid fa-shield-halved"></i>
        </span>
        <?php endif; ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- ── DETTAGLIO AZIENDA / SEDI ───────────────────────────────────────── -->
  <div>
    <?php if(!$company_data): ?>
    <div style="text-align:center;padding:60px;background:#fff;border-radius:12px;border:1px dashed var(--border);color:var(--muted)">
      <i class="fa-solid fa-building" style="font-size:40px;margin-bottom:12px;display:block;opacity:.3"></i>
      Seleziona un'azienda per gestire le sedi.
    </div>
    <?php else: ?>

    <!-- Header azienda -->
    <div class="card" style="margin-bottom:18px">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
        <div>
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <h2 style="margin:0;font-size:18px"><?=h($company_data['name'])?></h2>
            <?php if($company_data['id']===1): ?>
            <span style="background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:6px;font-size:10px;font-weight:800;text-transform:uppercase">
              <i class="fa-solid fa-shield-halved" style="margin-right:4px"></i>Azienda principale
            </span>
            <?php endif; ?>
          </div>
          <div style="margin-top:6px;font-size:12px;color:var(--muted)">
            <?php if($company_data['legal_representative']): ?>
            <span><i class="fa-solid fa-user-tie" style="width:14px"></i> Legale: <?=h($company_data['legal_representative'])?></span>
            <?php endif; ?>
            <?php if($company_data['vat_number']): ?>
            &nbsp;·&nbsp;<span><i class="fa-solid fa-receipt" style="width:14px"></i> P.IVA: <?=h($company_data['vat_number'])?></span>
            <?php endif; ?>
          </div>
        </div>
        <div style="display:flex;gap:8px">
          <button onclick="openCoModal(<?=htmlspecialchars(json_encode($company_data),ENT_QUOTES)?>)" class="btn btn-sm">
            <i class="fa-solid fa-pen"></i> Modifica azienda
          </button>
          <button onclick="openLocModal(<?=$company_data['id']?>)" class="btn btn-primary btn-sm">
            <i class="fa-solid fa-plus"></i> Nuova sede
          </button>
        </div>
      </div>

      <?php if($company_data['id']===1): ?>
      <div style="margin-top:14px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;font-size:12px;color:#92400e">
        <i class="fa-solid fa-circle-info" style="margin-right:6px"></i>
        Questa è l'azienda principale del sistema. Il nome può essere modificato liberamente — non può essere eliminata.
      </div>
      <?php endif; ?>
    </div>

    <!-- Sedi -->
    <?php if(empty($locations)): ?>
    <div style="text-align:center;padding:40px;background:#fff;border-radius:12px;border:1px dashed var(--border);color:var(--muted)">
      <i class="fa-solid fa-location-dot" style="font-size:30px;margin-bottom:10px;display:block;opacity:.3"></i>
      Nessuna sede configurata.
      <button onclick="openLocModal(<?=$company_data['id']?>)" class="btn btn-primary btn-sm" style="display:block;margin:12px auto 0">
        <i class="fa-solid fa-plus"></i> Aggiungi sede
      </button>
    </div>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px">
      <?php foreach($locations as $loc): ?>
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px">
          <h3 style="margin:0;font-size:14px;font-weight:700"><?=h($loc['location_name'])?></h3>
          <div style="display:flex;gap:5px;flex-shrink:0">
            <button onclick='openLocModal(<?=$company_data["id"]?>,<?=htmlspecialchars(json_encode($loc),ENT_QUOTES)?>)' class="btn btn-sm btn-blue" title="Modifica sede">
              <i class="fa-solid fa-pen"></i>
            </button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare la sede?')">
            <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_location">
              <input type="hidden" name="location_id" value="<?=$loc['id']?>">
              <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i></button>
            </form>
          </div>
        </div>
        <?php $fields = [
          ['fa-map-marker-alt','Indirizzo',$loc['address']??''],
          ['fa-phone',         'Tel.',     $loc['phone']??''],
          ['fa-envelope',      'Email',    $loc['email']??''],
          ['fa-file-contract', 'PEC',      $loc['email_pec']??''],
          ['fa-user-tie',      'Resp. sede',$loc['manager_site']??''],
          ['fa-laptop',        'Resp. IT', $loc['manager_it']??''],
          ['fa-tools',         'Resp. servizi',$loc['manager_service']??''],
          ['fa-calculator',    'Resp. amm.',$loc['manager_admin']??''],
        ];
        foreach($fields as [$ico,$lbl,$val]): if(!$val) continue; ?>
        <div style="display:flex;gap:8px;padding:4px 0;font-size:12px;border-bottom:1px solid #f8fafc">
          <i class="fa-solid <?=$ico?>" style="width:14px;color:var(--muted);margin-top:2px;flex-shrink:0"></i>
          <span style="color:var(--muted);min-width:80px"><?=$lbl?>:</span>
          <span><?=h($val)?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php endif; // fine company_data ?>
  </div>
</div>

<!-- ── MODAL AZIENDA ───────────────────────────────────────────────────────── -->
<div id="mCo" class="modal-overlay" style="z-index:1000">
  <div class="modal-box" style="width:480px">
    <div style="display:flex;justify-content:space-between;margin-bottom:20px;align-items:center">
      <div>
        <h3 style="margin:0;font-size:16px" id="mCoTitle">Nuova azienda</h3>
        <div id="mCoSubtitle" style="font-size:11px;color:var(--muted);margin-top:2px"></div>
      </div>
      <button onclick="closeModal('mCo')" style="border:none;background:none;font-size:22px;cursor:pointer;color:var(--muted)">&times;</button>
    </div>
    <form method="POST">
            <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_company">
      <input type="hidden" name="company_id" id="co_id" value="0">
      <div id="mCoMainAlert" style="display:none;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;font-size:12px;color:#92400e;margin-bottom:16px">
        <i class="fa-solid fa-shield-halved" style="margin-right:5px"></i>
        Stai modificando l'<strong>azienda principale</strong>. Solo il nome e i dati anagrafici possono essere cambiati.
      </div>
      <div class="form-group">
        <label>Nome azienda *</label>
        <input type="text" name="name" id="co_name" required placeholder="Es. Acme S.r.l.">
      </div>
      <div class="form-group">
        <label>Rappresentante legale</label>
        <input type="text" name="legal_representative" id="co_lr" placeholder="Nome e Cognome">
      </div>
      <div class="form-group">
        <label>Partita IVA</label>
        <input type="text" name="vat_number" id="co_vat" placeholder="Es. IT12345678901">
      </div>
      <div style="display:flex;gap:10px;margin-top:6px">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:12px">
          <i class="fa-solid fa-floppy-disk"></i> Salva
        </button>
        <button type="button" onclick="closeModal('mCo')" class="btn" style="flex:1;justify-content:center;padding:12px">
          Annulla
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ── MODAL SEDE ──────────────────────────────────────────────────────────── -->
<div id="mLoc" class="modal-overlay" style="z-index:1001">
  <div class="modal-box" style="width:580px">
    <div style="display:flex;justify-content:space-between;margin-bottom:20px">
      <h3 style="margin:0;font-size:16px" id="mLocTitle">Nuova sede</h3>
      <button onclick="closeModal('mLoc')" style="border:none;background:none;font-size:22px;cursor:pointer;color:var(--muted)">&times;</button>
    </div>
    <form method="POST">
            <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_location">
      <input type="hidden" name="location_id" id="loc_id" value="0">
      <input type="hidden" name="company_id_for_loc" id="loc_cid">
      <div class="grid-2">
        <div class="form-group span-2"><label>Nome sede *</label><input type="text" name="location_name" id="loc_name" required placeholder="Es. Sede Milano"></div>
        <div class="form-group span-2"><label>Indirizzo</label><input type="text" name="address" id="loc_addr" placeholder="Via, CAP, Città"></div>
        <div class="form-group"><label>Telefono</label><input type="text" name="phone" id="loc_phone"></div>
        <div class="form-group"><label>Email</label><input type="email" name="email" id="loc_email"></div>
        <div class="form-group"><label>PEC</label><input type="email" name="email_pec" id="loc_pec"></div>
        <div class="form-group"><label>Resp. sede</label><input type="text" name="manager_site" id="loc_ms"></div>
        <div class="form-group"><label>Resp. IT</label><input type="text" name="manager_it" id="loc_mi"></div>
        <div class="form-group"><label>Resp. servizi</label><input type="text" name="manager_service" id="loc_msv"></div>
        <div class="form-group span-2"><label>Resp. amministrativo</label><input type="text" name="manager_admin" id="loc_ma"></div>
      </div>
      <div style="display:flex;gap:10px;margin-top:6px">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:12px">
          <i class="fa-solid fa-floppy-disk"></i> Salva sede
        </button>
        <button type="button" onclick="closeModal('mLoc')" class="btn" style="flex:1;justify-content:center;padding:12px">Annulla</button>
      </div>
    </form>
  </div>
</div>

<script>
function openCoModal(d = null) {
    document.getElementById('co_id').value   = 0;
    document.getElementById('co_name').value = '';
    document.getElementById('co_lr').value   = '';
    document.getElementById('co_vat').value  = '';
    document.getElementById('mCoTitle').textContent = 'Nuova azienda';
    document.getElementById('mCoSubtitle').textContent = '';
    document.getElementById('mCoMainAlert').style.display = 'none';

    if (d) {
        document.getElementById('co_id').value   = d.id;
        document.getElementById('co_name').value = d.name || '';
        document.getElementById('co_lr').value   = d.legal_representative || '';
        document.getElementById('co_vat').value  = d.vat_number || '';
        document.getElementById('mCoTitle').textContent = 'Modifica: ' + d.name;

        if (d.id == 1) {
            document.getElementById('mCoSubtitle').textContent = 'Azienda principale';
            document.getElementById('mCoMainAlert').style.display = 'block';
        }
    }
    document.getElementById('mCo').style.display = 'flex';
    setTimeout(() => document.getElementById('co_name').focus(), 100);
}

function openLocModal(cid, d = null) {
    ['id','name','addr','phone','email','pec','ms','mi','msv','ma'].forEach(k => {
        const el = document.getElementById('loc_' + k);
        if (el) el.value = '';
    });
    document.getElementById('loc_id').value  = 0;
    document.getElementById('loc_cid').value = cid;
    document.getElementById('mLocTitle').textContent = 'Nuova sede';

    if (d) {
        document.getElementById('loc_id').value    = d.id;
        document.getElementById('loc_name').value  = d.location_name || '';
        document.getElementById('loc_addr').value  = d.address || '';
        document.getElementById('loc_phone').value = d.phone || '';
        document.getElementById('loc_email').value = d.email || '';
        document.getElementById('loc_pec').value   = d.email_pec || '';
        document.getElementById('loc_ms').value    = d.manager_site || '';
        document.getElementById('loc_mi').value    = d.manager_it || '';
        document.getElementById('loc_msv').value   = d.manager_service || '';
        document.getElementById('loc_ma').value    = d.manager_admin || '';
        document.getElementById('mLocTitle').textContent = 'Modifica: ' + (d.location_name || '');
    }
    document.getElementById('mLoc').style.display = 'flex';
}
</script>

<?php require_once('footer.php'); ?>
