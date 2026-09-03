<?php
/**
 * certV 5.02.00 — manage_clients.php
 * Anagrafica clienti per i quali sono aperte posizioni di ricerca.
 * Un cliente può essere una società esterna o un'azienda del gruppo (companies).
 */
require_once('access_control.php');
require_once('functions.php');

$u_id     = (int)$_SESSION['user_id'];
$u_role   = (int)($_SESSION['role_id'] ?? 99);
$can_edit   = can('edit',   'manage_clients.php') || $u_role === 1;
$can_create = can('create', 'manage_clients.php') || $u_role === 1;
$can_delete = can('delete', 'manage_clients.php') || $u_role === 1;

// ─── CRUD ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $id = (int)($_POST['client_id'] ?? 0);
    try {
        $pdo->beginTransaction();

        $name = trim($_POST['name'] ?? '');
        if (!$name) throw new Exception("Nome cliente obbligatorio.");

        $is_internal = !empty($_POST['is_internal_company']) ? 1 : 0;
        $internal_id = $is_internal && !empty($_POST['internal_company_id'])
                       ? (int)$_POST['internal_company_id'] : null;

        $data = [
            $name,
            !empty($_POST['vat_number'])     ? trim($_POST['vat_number'])     : null,
            !empty($_POST['fiscal_code'])    ? trim($_POST['fiscal_code'])    : null,
            $is_internal,
            $internal_id,
            !empty($_POST['sector'])         ? trim($_POST['sector'])         : null,
            !empty($_POST['address'])        ? trim($_POST['address'])        : null,
            !empty($_POST['city'])           ? trim($_POST['city'])           : null,
            !empty($_POST['province'])       ? trim($_POST['province'])       : null,
            !empty($_POST['country'])        ? trim($_POST['country'])        : 'Italia',
            !empty($_POST['phone'])          ? trim($_POST['phone'])          : null,
            !empty($_POST['email'])          ? trim($_POST['email'])          : null,
            !empty($_POST['email_pec'])      ? trim($_POST['email_pec'])      : null,
            !empty($_POST['website'])        ? trim($_POST['website'])        : null,
            !empty($_POST['contact_person']) ? trim($_POST['contact_person']) : null,
            !empty($_POST['contact_role'])   ? trim($_POST['contact_role'])   : null,
            !empty($_POST['notes'])          ? trim($_POST['notes'])          : null,
            !empty($_POST['is_active']) ? 1 : 0,
        ];

        if ($id > 0) {
            $pdo->prepare(
                "UPDATE clients SET name=?, vat_number=?, fiscal_code=?, is_internal_company=?, internal_company_id=?,
                 sector=?, address=?, city=?, province=?, country=?, phone=?, email=?, email_pec=?, website=?,
                 contact_person=?, contact_role=?, notes=?, is_active=? WHERE id=?"
            )->execute([...$data, $id]);
            $msg_action = "aggiornato";
        } else {
            $pdo->prepare(
                "INSERT INTO clients (name, vat_number, fiscal_code, is_internal_company, internal_company_id,
                 sector, address, city, province, country, phone, email, email_pec, website,
                 contact_person, contact_role, notes, is_active, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([...$data, $u_id]);
            $id = (int)$pdo->lastInsertId();
            $msg_action = "creato";
        }

        $pdo->commit();
        write_log('Clients', 'success', "Cliente #$id $msg_action: $name", $u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Cliente $msg_action.</div>";
        redirect_self();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
        redirect_self();
    }
}

// ─── DELETE (soft via is_active=0) ──────────────────────────────────────
if (isset($_GET['del']) && $can_delete) {
    $id = (int)$_GET['del'];
    if ($id > 0) {
        try {
            $pdo->prepare("UPDATE clients SET is_active = 0 WHERE id = ?")->execute([$id]);
            write_log('Clients', 'warning', "Cliente #$id disattivato", $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Cliente disattivato.</div>";
        } catch (Exception $e) {
            $_SESSION['flash_msg'] = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
        }
    }
    redirect_self();
}

// ─── REACTIVATE ────────────────────────────────────────────────────────
if (isset($_GET['act']) && $can_edit) {
    $id = (int)$_GET['act'];
    if ($id > 0) {
        $pdo->prepare("UPDATE clients SET is_active = 1 WHERE id = ?")->execute([$id]);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>Cliente riattivato.</div>";
    }
    redirect_self();
}

// ─── FILTRI ────────────────────────────────────────────────────────────
$f_q       = trim($_GET['q'] ?? '');
$f_active  = $_GET['f_active'] ?? '1';
$f_sector  = trim($_GET['f_sector'] ?? '');
$f_internal = $_GET['f_internal'] ?? '';

$where = ['1=1'];
$params = [];
if ($f_q !== '') {
    $where[] = "(c.name LIKE ? OR c.vat_number LIKE ? OR c.contact_person LIKE ? OR c.city LIKE ?)";
    $like = "%{$f_q}%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}
if ($f_active !== 'all' && $f_active !== '') {
    $where[] = "c.is_active = ?";
    $params[] = (int)$f_active;
}
if ($f_sector !== '') {
    $where[] = "c.sector = ?";
    $params[] = $f_sector;
}
if ($f_internal !== '') {
    $where[] = "c.is_internal_company = ?";
    $params[] = (int)$f_internal;
}

$sql = "SELECT c.*,
               co.name AS company_name,
               (SELECT COUNT(*) FROM position_clients pc WHERE pc.client_id = c.id) AS positions_count
          FROM clients c
          LEFT JOIN companies co ON co.id = c.internal_company_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY c.is_active DESC, c.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$clients = $stmt->fetchAll();

// Settori distinti per il filtro
$sectors = $pdo->query("SELECT DISTINCT sector FROM clients WHERE sector IS NOT NULL AND sector != '' ORDER BY sector")->fetchAll(PDO::FETCH_COLUMN);

// Companies per dropdown azienda interna
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();

require_once('header.php');
?>

<div style="max-width:1400px;margin:0 auto">

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
    <div>
      <h1 style="font-size:22px;font-weight:800;margin-bottom:4px">
        <i class="fa-solid fa-building-user" style="color:var(--p)"></i> Anagrafica clienti
      </h1>
      <div style="color:var(--muted);font-size:13px">Clienti per i quali apriamo posizioni di ricerca personale</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php if ($can_create): ?>
        <button onclick="openClientModal()" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Nuovo cliente</button>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($_SESSION['flash_msg'])): ?>
    <?= $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); ?>
  <?php endif; ?>

  <!-- Filtri -->
  <form method="GET" class="filter-bar">
    <?php if (!empty($_GET["r"])): ?><input type="hidden" name="r" value="<?= htmlspecialchars($_GET["r"], ENT_QUOTES, "UTF-8") ?>"><?php endif; ?>
    <div class="fg" style="flex:2;min-width:240px">
      <label>Ricerca</label>
      <input type="search" name="q" value="<?=h($f_q)?>" placeholder="Nome, P.IVA, referente, città...">
    </div>
    <div class="fg">
      <label>Stato</label>
      <select name="f_active">
        <option value="1" <?=$f_active==='1'?'selected':''?>>Attivi</option>
        <option value="0" <?=$f_active==='0'?'selected':''?>>Disattivi</option>
        <option value="all" <?=$f_active==='all'?'selected':''?>>Tutti</option>
      </select>
    </div>
    <div class="fg">
      <label>Tipo</label>
      <select name="f_internal">
        <option value="">Tutti</option>
        <option value="0" <?=$f_internal==='0'?'selected':''?>>Cliente esterno</option>
        <option value="1" <?=$f_internal==='1'?'selected':''?>>Azienda gruppo</option>
      </select>
    </div>
    <div class="fg">
      <label>Settore</label>
      <select name="f_sector">
        <option value="">Tutti</option>
        <?php foreach ($sectors as $s): ?>
          <option value="<?=h($s)?>" <?=$f_sector===$s?'selected':''?>><?=h($s)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="fg">
      <label>&nbsp;</label>
      <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Filtra</button>
    </div>
    <div class="fg" style="margin-left:auto">
      <label style="visibility:hidden">.</label>
      <button type="button" onclick="window.print()" class="btn btn-sm" title="Stampa"
              style="background:#fef3c7;color:#92400e;border-color:#fde68a">
        <i class="fa-solid fa-print"></i> Stampa
      </button>
    </div>
  </form>

  <!-- Statistiche -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:18px">
    <div class="stat-card" style="background:#fff;padding:14px;border-radius:10px;border-left:4px solid #10b981">
      <div style="font-size:10px;text-transform:uppercase;font-weight:700;color:var(--muted)">Clienti totali</div>
      <div style="font-size:24px;font-weight:800;margin-top:4px"><?=count($clients)?></div>
    </div>
    <div class="stat-card" style="background:#fff;padding:14px;border-radius:10px;border-left:4px solid #0ea5e9">
      <div style="font-size:10px;text-transform:uppercase;font-weight:700;color:var(--muted)">Clienti esterni</div>
      <div style="font-size:24px;font-weight:800;margin-top:4px"><?=count(array_filter($clients, fn($c)=>!$c['is_internal_company']))?></div>
    </div>
    <div class="stat-card" style="background:#fff;padding:14px;border-radius:10px;border-left:4px solid #8b5cf6">
      <div style="font-size:10px;text-transform:uppercase;font-weight:700;color:var(--muted)">Aziende gruppo</div>
      <div style="font-size:24px;font-weight:800;margin-top:4px"><?=count(array_filter($clients, fn($c)=>$c['is_internal_company']))?></div>
    </div>
    <div class="stat-card" style="background:#fff;padding:14px;border-radius:10px;border-left:4px solid #f59e0b">
      <div style="font-size:10px;text-transform:uppercase;font-weight:700;color:var(--muted)">Posizioni associate</div>
      <div style="font-size:24px;font-weight:800;margin-top:4px"><?=array_sum(array_column($clients, 'positions_count'))?></div>
    </div>
  </div>

  <!-- Lista clienti -->
  <?php if (empty($clients)): ?>
    <div style="background:#fff;padding:40px;border-radius:12px;text-align:center;color:var(--muted)">
      <i class="fa-solid fa-building-user" style="font-size:32px;margin-bottom:10px;opacity:.4"></i>
      <p>Nessun cliente trovato.</p>
      <?php if ($can_create): ?>
        <button onclick="openClientModal()" class="btn btn-primary" style="margin-top:10px"><i class="fa-solid fa-plus"></i> Crea il primo cliente</button>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.04)">
      <?php require_once __DIR__ . '/app/ListFilter.php'; ListFilter::render('manage_clients', '#lf-table-manage_clients', ['export_filename' => 'manage_clients', 'title' => 'Anagrafica clienti']); ?>
<table id="lf-table-manage_clients" class="data-table" style="width:100%;border-collapse:collapse">
        <thead>
          <tr style="background:#f8fafc">
            <th style="padding:12px;text-align:left;font-size:11px;text-transform:uppercase;color:var(--muted);font-weight:700">Cliente</th>
            <th style="padding:12px;text-align:left;font-size:11px;text-transform:uppercase;color:var(--muted);font-weight:700">P.IVA</th>
            <th style="padding:12px;text-align:left;font-size:11px;text-transform:uppercase;color:var(--muted);font-weight:700">Settore</th>
            <th style="padding:12px;text-align:left;font-size:11px;text-transform:uppercase;color:var(--muted);font-weight:700">Sede</th>
            <th style="padding:12px;text-align:left;font-size:11px;text-transform:uppercase;color:var(--muted);font-weight:700">Referente</th>
            <th style="padding:12px;text-align:center;font-size:11px;text-transform:uppercase;color:var(--muted);font-weight:700">Posizioni</th>
            <th style="padding:12px;text-align:center;font-size:11px;text-transform:uppercase;color:var(--muted);font-weight:700;width:140px" class="no-print">Azioni</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($clients as $c): ?>
            <tr style="border-top:1px solid var(--border);<?=!$c['is_active']?'opacity:.55;background:#fafafa':''?>">
              <td style="padding:12px">
                <div style="font-weight:700;color:#1e293b">
                  <?=h($c['name'])?>
                  <?php if ($c['is_internal_company']): ?>
                    <span style="background:#ede9fe;color:#5b21b6;padding:1px 6px;border-radius:8px;font-size:9px;font-weight:700;margin-left:4px">GRUPPO</span>
                  <?php endif; ?>
                  <?php if (!$c['is_active']): ?>
                    <span style="background:#fee2e2;color:#991b1b;padding:1px 6px;border-radius:8px;font-size:9px;font-weight:700;margin-left:4px">DISATTIVO</span>
                  <?php endif; ?>
                </div>
                <?php if ($c['company_name']): ?>
                  <div style="font-size:11px;color:var(--muted)">→ <?=h($c['company_name'])?></div>
                <?php endif; ?>
              </td>
              <td style="padding:12px;font-family:monospace;font-size:12px;color:#475569"><?=h($c['vat_number'] ?: '—')?></td>
              <td style="padding:12px;font-size:13px"><?=h($c['sector'] ?: '—')?></td>
              <td style="padding:12px;font-size:13px"><?=h(trim(($c['city'] ?? '') . ($c['province'] ? ' (' . $c['province'] . ')' : '')) ?: '—')?></td>
              <td style="padding:12px;font-size:13px">
                <?php if ($c['contact_person']): ?>
                  <div style="font-weight:600"><?=h($c['contact_person'])?></div>
                  <?php if ($c['contact_role']): ?>
                    <div style="font-size:11px;color:var(--muted)"><?=h($c['contact_role'])?></div>
                  <?php endif; ?>
                <?php else: ?>
                  <span style="color:var(--muted)">—</span>
                <?php endif; ?>
              </td>
              <td style="padding:12px;text-align:center">
                <?php if ((int)$c['positions_count'] > 0): ?>
                  <span style="background:#dbeafe;color:#1e40af;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:700"><?=$c['positions_count']?></span>
                <?php else: ?>
                  <span style="color:var(--muted);font-size:12px">0</span>
                <?php endif; ?>
              </td>
              <td style="padding:12px;text-align:center;white-space:nowrap" class="no-print">
                <?php if ($can_edit): ?>
                  <button onclick='openClientModal(<?=htmlspecialchars(json_encode($c, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8")?>)' class="btn btn-sm" title="Modifica">
                    <i class="fa-solid fa-pen"></i>
                  </button>
                <?php endif; ?>
                <?php if ($can_delete && $c['is_active']): ?>
                  <a href="<?= qs_self_safe(['del'=>''.($c['id']).'']) ?>" onclick="return confirm('Disattivare il cliente <?=h(addslashes($c['name']))?>?')" class="btn btn-danger btn-sm" title="Disattiva">
                    <i class="fa-solid fa-trash"></i>
                  </a>
                <?php elseif ($can_edit && !$c['is_active']): ?>
                  <a href="<?= qs_self_safe(['act'=>''.($c['id']).'']) ?>" class="btn btn-sm" title="Riattiva" style="background:#dcfce7;color:#15803d;border-color:#86efac">
                    <i class="fa-solid fa-rotate-left"></i>
                  </a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- Modal Cliente -->
<div id="mClient" class="modal-overlay" style="z-index:1000">
  <div class="modal-box" style="width:760px;max-width:96%">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
      <h3 style="margin:0;font-size:15px"><i class="fa-solid fa-building-user" style="color:var(--p);margin-right:8px"></i><span id="cliFormTitle">Nuovo cliente</span></h3>
      <button onclick="closeModal('mClient')" style="border:none;background:none;font-size:22px;cursor:pointer;color:var(--muted)">&times;</button>
    </div>

    <form method="POST" id="cliForm">
      <?= csrf_field() ?>
      <input type="hidden" name="client_id" id="c_id" value="0">

      <!-- Anagrafica -->
      <div style="background:#f8fafc;padding:12px;border-radius:8px;margin-bottom:12px">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:8px"><i class="fa-solid fa-id-card"></i> Anagrafica</div>
        <div class="form-group" style="margin-bottom:8px">
          <label>Ragione sociale *</label>
          <input type="text" name="name" id="c_name" required maxlength="200">
        </div>
        <div class="grid-2">
          <div class="form-group"><label>Partita IVA</label><input type="text" name="vat_number" id="c_vat" maxlength="30"></div>
          <div class="form-group"><label>Codice fiscale</label><input type="text" name="fiscal_code" id="c_cf" maxlength="30"></div>
        </div>
        <div class="grid-2">
          <div class="form-group"><label>Settore</label><input type="text" name="sector" id="c_sector" maxlength="100" placeholder="es. IT, Finance, Manufacturing"></div>
          <div class="form-group" style="display:flex;align-items:center;gap:8px;padding-top:24px">
            <label style="margin:0;display:flex;align-items:center;gap:6px;cursor:pointer">
              <input type="checkbox" name="is_internal_company" id="c_internal" value="1" onchange="toggleInternalCompany()">
              <span>Azienda del gruppo</span>
            </label>
          </div>
        </div>
        <div class="form-group" id="c_internal_wrap" style="display:none">
          <label>Azienda del gruppo</label>
          <select name="internal_company_id" id="c_internal_id">
            <option value="">— seleziona —</option>
            <?php foreach ($companies as $co): ?>
              <option value="<?=$co['id']?>"><?=h($co['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Sede -->
      <div style="background:#f8fafc;padding:12px;border-radius:8px;margin-bottom:12px">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:8px"><i class="fa-solid fa-location-dot"></i> Sede</div>
        <div class="form-group" style="margin-bottom:8px"><label>Indirizzo</label><input type="text" name="address" id="c_addr" maxlength="255"></div>
        <div class="grid-2">
          <div class="form-group"><label>Città</label><input type="text" name="city" id="c_city" maxlength="100"></div>
          <div class="form-group"><label>Provincia</label><input type="text" name="province" id="c_prov" maxlength="50"></div>
        </div>
        <div class="form-group"><label>Stato</label><input type="text" name="country" id="c_country" maxlength="50" value="Italia"></div>
      </div>

      <!-- Contatti -->
      <div style="background:#f8fafc;padding:12px;border-radius:8px;margin-bottom:12px">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:8px"><i class="fa-solid fa-phone"></i> Contatti</div>
        <div class="grid-2">
          <div class="form-group"><label>Telefono</label><input type="text" name="phone" id="c_phone" maxlength="50"></div>
          <div class="form-group"><label>Email</label><input type="email" name="email" id="c_email" maxlength="150"></div>
        </div>
        <div class="grid-2">
          <div class="form-group"><label>PEC</label><input type="email" name="email_pec" id="c_pec" maxlength="150"></div>
          <div class="form-group"><label>Sito web</label><input type="url" name="website" id="c_web" maxlength="200" placeholder="https://"></div>
        </div>
      </div>

      <!-- Referente -->
      <div style="background:#f8fafc;padding:12px;border-radius:8px;margin-bottom:12px">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:8px"><i class="fa-solid fa-user-tie"></i> Referente</div>
        <div class="grid-2">
          <div class="form-group"><label>Nome</label><input type="text" name="contact_person" id="c_cp" maxlength="150"></div>
          <div class="form-group"><label>Ruolo</label><input type="text" name="contact_role" id="c_cr" maxlength="100" placeholder="HR Manager, Direttore..."></div>
        </div>
      </div>

      <div class="form-group"><label>Note</label><textarea name="notes" id="c_notes" rows="2"></textarea></div>

      <div class="form-group" style="display:flex;align-items:center;gap:8px">
        <label style="margin:0;display:flex;align-items:center;gap:6px;cursor:pointer">
          <input type="checkbox" name="is_active" id="c_active" value="1" checked>
          <span>Cliente attivo</span>
        </label>
      </div>

      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
        <button type="button" onclick="closeModal('mClient')" class="btn">Annulla</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Salva</button>
      </div>
    </form>
  </div>
</div>

<script>
function openClientModal(d=null) {
  document.getElementById('cliForm').reset();
  document.getElementById('c_id').value = 0;
  document.getElementById('c_country').value = 'Italia';
  document.getElementById('c_active').checked = true;
  document.getElementById('cliFormTitle').textContent = 'Nuovo cliente';

  if (d) {
    document.getElementById('cliFormTitle').textContent = 'Modifica: ' + d.name;
    const map = {
      c_id: 'id', c_name: 'name', c_vat: 'vat_number', c_cf: 'fiscal_code',
      c_sector: 'sector', c_addr: 'address', c_city: 'city', c_prov: 'province',
      c_country: 'country', c_phone: 'phone', c_email: 'email', c_pec: 'email_pec',
      c_web: 'website', c_cp: 'contact_person', c_cr: 'contact_role', c_notes: 'notes'
    };
    Object.keys(map).forEach(k => {
      const el = document.getElementById(k);
      if (el) el.value = d[map[k]] || '';
    });
    document.getElementById('c_internal').checked = !!parseInt(d.is_internal_company);
    document.getElementById('c_internal_id').value = d.internal_company_id || '';
    document.getElementById('c_active').checked = !!parseInt(d.is_active);
  }
  toggleInternalCompany();
  document.getElementById('mClient').style.display = 'flex';
}

function toggleInternalCompany() {
  const checked = document.getElementById('c_internal').checked;
  document.getElementById('c_internal_wrap').style.display = checked ? 'block' : 'none';
}

function closeModal(id) {
  document.getElementById(id).style.display = 'none';
}
</script>

<?php require_once('footer.php'); ?>
