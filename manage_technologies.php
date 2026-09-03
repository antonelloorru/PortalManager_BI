<?php
/**
 * certV 5.7.0 — manage_technologies.php
 *
 * CRUD tecnologie + sync N:M con Brand e Certificazioni.
 * Le tecnologie sono entità trasversali cross-brand.
 */
require_once('access_control.php');
require_once __DIR__ . '/app/TechnologyMapper.php';
require_once __DIR__ . '/app/EntityChangeLog.php';

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
if ($u_role > 2) { header('Location: ' . (function_exists('url') ? url('unauthorized') : 'unauthorized.php')); exit(); }

$mapper = new TechnologyMapper($pdo);

// ─── HANDLE POST ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $payload = [
                'name'        => trim($_POST['name'] ?? ''),
                'description' => trim($_POST['description'] ?? ''),
                'category_id' => !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null,
                'icon'        => trim($_POST['icon'] ?? ''),
                'color'       => trim($_POST['color'] ?? '#0ea5e9'),
                'is_active'   => !empty($_POST['is_active']) ? 1 : 0,
            ];
            if ($payload['name'] === '') throw new RuntimeException('Nome obbligatorio');

            $log = new EntityChangeLog($pdo);
            if ($id > 0) {
                $old = $pdo->prepare("SELECT * FROM technologies WHERE id = ?");
                $old->execute([$id]);
                $oldRow = $old->fetch(PDO::FETCH_ASSOC);
                $pdo->prepare(
                    "UPDATE technologies SET name=?, description=?, category_id=?, icon=?, color=?, is_active=?, updated_at=NOW()
                     WHERE id=?"
                )->execute([$payload['name'], $payload['description'], $payload['category_id'],
                            $payload['icon'], $payload['color'], $payload['is_active'], $id]);
                $log->diffAndLog('technologies', $id, $oldRow ?: [], $payload, 'update', 'ui', null, $u_id);
                $msg = "Tecnologia aggiornata.";
            } else {
                $info = $mapper->findOrCreate($payload['name'], $payload);
                $id = $info['id'];
                $msg = $info['created'] ? "Tecnologia creata." : "Tecnologia esistente aggiornata.";
            }

            // Sync brand
            $brand_ids = isset($_POST['brand_ids']) ? array_map('intval', (array)$_POST['brand_ids']) : [];
            $primary_brand_id = (int)($_POST['primary_brand_id'] ?? 0);
            $assoc = [];
            foreach ($brand_ids as $bid) {
                $assoc[$bid] = $bid === $primary_brand_id;
            }
            $mapper->syncBrands($id, $brand_ids, $u_id);
            // Imposta primary
            if ($primary_brand_id > 0) {
                $pdo->prepare("UPDATE tech_brands SET is_primary = 0 WHERE technology_id = ?")->execute([$id]);
                $pdo->prepare("UPDATE tech_brands SET is_primary = 1 WHERE technology_id = ? AND brand_id = ?")
                    ->execute([$id, $primary_brand_id]);
            }

            // Sync certificazioni
            $cert_ids = isset($_POST['cert_ids']) ? array_map('intval', (array)$_POST['cert_ids']) : [];
            $cert_relevance = $_POST['cert_relevance'] ?? [];
            $cert_assoc = [];
            foreach ($cert_ids as $cid) {
                $rel = $cert_relevance[$cid] ?? 'primary';
                if (!in_array($rel, ['primary','secondary','related'], true)) $rel = 'primary';
                $cert_assoc[$cid] = $rel;
            }
            $mapper->syncCertifications($id, $cert_assoc, $u_id);

            $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> $msg Sincronizzati " . count($brand_ids) . " brand e " . count($cert_ids) . " certificazioni.</div>";
        }
        elseif ($action === 'delete' && $u_role === 1) {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ID non valido.');

            // Conta dipendenze prima di cancellare
            $cert_count = (int)$pdo->query("SELECT COUNT(*) FROM certifications WHERE technology_id = $id")->fetchColumn();
            $tcert_count = (int)$pdo->query("SELECT COUNT(*) FROM tech_certifications WHERE technology_id = $id")->fetchColumn();
            $tbrand_count = (int)$pdo->query("SELECT COUNT(*) FROM tech_brands WHERE technology_id = $id")->fetchColumn();
            $force = !empty($_POST['force']);

            if (!$force && ($cert_count > 0 || $tcert_count > 0 || $tbrand_count > 0)) {
                $deps = [];
                if ($cert_count > 0)   $deps[] = "<strong>$cert_count</strong> certificazioni nel catalogo (verrebbero ELIMINATE in cascata)";
                if ($tcert_count > 0)  $deps[] = "<strong>$tcert_count</strong> link tech↔cert";
                if ($tbrand_count > 0) $deps[] = "<strong>$tbrand_count</strong> link tech↔brand";
                $_SESSION['flash_msg'] = "<div class='alert alert-warning'>" .
                    "<i class='fa-solid fa-triangle-exclamation'></i> Impossibile eliminare: la tecnologia ha dipendenze (" .
                    implode(', ', $deps) . "). Disattiva la tecnologia (toggle Attiva) oppure usa l'eliminazione forzata.</div>";
            } else {
                // Snapshot per audit prima del DELETE
                $snap = $pdo->prepare("SELECT * FROM technologies WHERE id = ?");
                $snap->execute([$id]);
                $oldRow = $snap->fetch(PDO::FETCH_ASSOC);
                if (!$oldRow) throw new RuntimeException('Tecnologia non trovata.');

                $pdo->prepare("DELETE FROM technologies WHERE id = ?")->execute([$id]);

                // Audit con change_action='update' (l'enum non ha 'delete' nello schema attuale)
                (new EntityChangeLog($pdo))->logField('technologies', $id, '__delete__',
                    json_encode($oldRow, JSON_UNESCAPED_UNICODE), null, 'delete', 'ui', null, $u_id);

                $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Tecnologia eliminata" .
                    ($cert_count > 0 ? " (con $cert_count certificazioni in cascata)" : "") . ".</div>";
            }
        }
    } catch (Throwable $e) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
    }
    $qs = !empty($_GET['r']) ? '?r=' . urlencode($_GET['r']) : '';
    header('Location: manage_technologies.php' . $qs);
    exit();
}

// ─── DATA LOAD ──────────────────────────────────────────────────────────
$f_category = (int)($_GET['f_category'] ?? 0);
$overview = $mapper->getOverview($f_category > 0 ? $f_category : null, false);
$categories = $pdo->query("SELECT * FROM tech_categories ORDER BY display_order, name")->fetchAll();
$brands = $pdo->query("SELECT id, name FROM brands ORDER BY name")->fetchAll();
$certifications = $pdo->query("SELECT c.id, c.code, c.name, b.name AS brand_name FROM certifications c LEFT JOIN brands b ON b.id = c.brand_id WHERE c.is_active = 1 ORDER BY b.name, c.name")->fetchAll();

// Pre-load mappings per ogni tech
$tech_brands_map = [];
$tech_certs_map = [];
foreach ($overview as $t) {
    $tech_brands_map[$t['id']] = $mapper->getBrands($t['id']);
    $tech_certs_map[$t['id']]  = $mapper->getCertifications($t['id']);
}

require_once('header.php');
?>

<style>
.tech-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(380px,1fr)); gap:16px; }
.tech-card { background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); padding:18px; border-top:4px solid #0ea5e9; }
.tech-card.inactive { opacity:.5; }
.tech-card-h { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
.tech-icon { width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; color:#fff; font-size:18px; }
.tech-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:6px; margin:10px 0; }
.tech-stat { background:#f8fafc; padding:6px 8px; border-radius:6px; text-align:center; }
.tech-stat-num { font-weight:800; font-size:16px; color:#1e293b; }
.tech-stat-lbl { font-size:9px; color:var(--muted); text-transform:uppercase; letter-spacing:.3px; }
.brand-chip { display:inline-flex; align-items:center; gap:4px; background:#eff6ff; color:#1e40af; padding:2px 8px; border-radius:8px; font-size:10px; font-weight:600; margin:2px; }
.brand-chip.primary { background:#dbeafe; font-weight:800; }
.brand-chip.primary::before { content:'★ '; }
.modal-bg { position:fixed; inset:0; background:rgba(0,0,0,.5); display:none; z-index:9000; }
.modal-bg.show { display:flex; align-items:center; justify-content:center; }
.modal-box { background:#fff; border-radius:12px; width:90%; max-width:780px; max-height:90vh; overflow:auto; padding:24px; }
.cert-list { max-height:240px; overflow:auto; border:1px solid var(--border); border-radius:6px; padding:6px; }
.cert-item { display:flex; align-items:center; gap:8px; padding:4px 6px; border-radius:4px; }
.cert-item:hover { background:#f8fafc; }
.cert-item label { flex:1; font-size:12px; cursor:pointer; }
.cert-item select { font-size:10px; padding:2px 4px; }
</style>

<div style="max-width:1300px;margin:0 auto">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
    <div>
      <h1 style="font-size:22px;font-weight:800;margin-bottom:4px">
        <i class="fa-solid fa-microchip" style="color:#10b981"></i> Tecnologie cross-brand
      </h1>
      <div style="color:var(--muted);font-size:13px">Entità trasversali con mapping N:M verso brand e certificazioni</div>
    </div>
    <button onclick="openTechModal()" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Nuova tecnologia</button>
  </div>

  <?php if (!empty($_SESSION['flash_msg'])): ?><?= $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); ?><?php endif; ?>

  <!-- FILTRI -->
  <form method="GET" class="filter-bar" style="margin-bottom:14px">
    <?php if (!empty($_GET['r'])): ?><input type="hidden" name="r" value="<?= h($_GET['r']) ?>"><?php endif; ?>
    <div class="fg">
      <label>Categoria</label>
      <select name="f_category" onchange="this.form.submit()">
        <option value="0">Tutte</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $f_category===(int)$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="fg" style="margin-left:auto"><label>&nbsp;</label>
      <span style="font-weight:700;color:var(--muted);font-size:12px"><?= count($overview) ?> tecnologie</span>
    </div>
  </form>

  <!-- GRID TECNOLOGIE -->
  <?php if (empty($overview)): ?>
    <div style="background:#fff;padding:60px 30px;border-radius:12px;text-align:center;color:var(--muted)">
      <i class="fa-solid fa-microchip" style="font-size:48px;opacity:.3;margin-bottom:14px"></i>
      <h3 style="margin:0 0 6px">Nessuna tecnologia</h3>
      <p style="margin:0;font-size:13px">Crea la prima tecnologia trasversale per iniziare a mappare brand e certificazioni.</p>
    </div>
  <?php else: ?>
    <div class="tech-grid">
      <?php foreach ($overview as $t):
        $color = $t['color'] ?: '#0ea5e9';
        $icon  = $t['icon']  ?: 'fa-microchip';
        $tech_brands = $tech_brands_map[$t['id']] ?? [];
        $tech_certs  = $tech_certs_map[$t['id']]  ?? [];
      ?>
        <div class="tech-card <?= !$t['is_active'] ? 'inactive' : '' ?>" style="border-top-color:<?= h($color) ?>">
          <div class="tech-card-h">
            <div class="tech-icon" style="background:<?= h($color) ?>">
              <i class="fa-solid <?= h($icon) ?>"></i>
            </div>
            <div style="flex:1">
              <div style="font-weight:800;font-size:15px"><?= h($t['name']) ?></div>
              <div style="font-size:10px;color:var(--muted)">
                <?= h($t['category_name'] ?? 'Senza categoria') ?>
                <?php if (!$t['is_active']): ?> · <span style="color:#dc2626">DISATTIVATA</span><?php endif; ?>
              </div>
            </div>
            <div style="display:flex;gap:4px">
              <button onclick='openTechModal(<?= json_encode($t, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)' class="btn btn-sm" title="Modifica"><i class="fa-solid fa-pen"></i></button>
              <?php if ($u_role === 1): ?>
                <form method="POST" style="display:inline" onsubmit="return confirmDelete(this, <?= (int)($t['legacy_cert_count'] ?? 0) ?>, '<?= h($t['name']) ?>')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $t['id'] ?>">
                  <input type="hidden" name="force" value="0" class="force-flag">
                  <button type="submit" class="btn btn-sm btn-danger" title="Elimina"><i class="fa-solid fa-trash"></i></button>
                </form>
              <?php endif; ?>
            </div>
          </div>

          <?php if (!empty($t['description'])): ?>
            <div style="font-size:12px;color:#475569;margin-bottom:8px;line-height:1.4"><?= h($t['description']) ?></div>
          <?php endif; ?>

          <div class="tech-stats">
            <div class="tech-stat">
              <div class="tech-stat-num"><?= (int)$t['brand_count'] ?></div>
              <div class="tech-stat-lbl">Brand</div>
            </div>
            <div class="tech-stat">
              <div class="tech-stat-num"><?= (int)$t['cert_count'] ?></div>
              <div class="tech-stat-lbl">Cert</div>
            </div>
            <div class="tech-stat">
              <div class="tech-stat-num"><?= (int)$t['held_count'] ?></div>
              <div class="tech-stat-lbl">Possedute</div>
            </div>
            <div class="tech-stat">
              <div class="tech-stat-num"><?= (int)$t['skilled_employees'] ?></div>
              <div class="tech-stat-lbl">Risorse</div>
            </div>
          </div>

          <?php if (!empty($tech_brands)): ?>
            <div style="margin-top:8px">
              <div style="font-size:10px;color:var(--muted);text-transform:uppercase;font-weight:700;margin-bottom:4px">Brand associati</div>
              <?php foreach ($tech_brands as $tb): ?>
                <span class="brand-chip <?= $tb['is_primary'] ? 'primary' : '' ?>"><?= h($tb['name']) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- MODAL CRUD TECNOLOGIA -->
<div class="modal-bg" id="techModal">
  <div class="modal-box">
    <h2 id="techModalTitle" style="margin-bottom:14px;font-size:18px">Nuova tecnologia</h2>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="t_id" value="0">

      <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px">
        <div class="form-group"><label>Nome <span style="color:#ef4444">*</span></label>
          <input type="text" name="name" id="t_name" required maxlength="100">
        </div>
        <div class="form-group"><label>Categoria</label>
          <select name="category_id" id="t_category">
            <option value="">—</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group"><label>Descrizione</label>
        <textarea name="description" id="t_desc" rows="2" maxlength="500"></textarea>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
        <div class="form-group"><label>Icona (FontAwesome)</label>
          <input type="text" name="icon" id="t_icon" placeholder="fa-network-wired" maxlength="50">
        </div>
        <div class="form-group"><label>Colore</label>
          <input type="color" name="color" id="t_color" value="#0ea5e9">
        </div>
        <div class="form-group" style="display:flex;align-items:center">
          <label style="display:flex;gap:6px;align-items:center;margin-top:18px">
            <input type="checkbox" name="is_active" id="t_active" value="1" checked>
            <span>Attiva</span>
          </label>
        </div>
      </div>

      <h3 style="margin:16px 0 8px;font-size:14px;color:#1e40af"><i class="fa-solid fa-tags"></i> Brand associati (N:M)</h3>
      <div style="background:#f8fafc;padding:10px;border-radius:6px;max-height:140px;overflow:auto">
        <?php foreach ($brands as $b): ?>
          <label style="display:inline-flex;align-items:center;gap:5px;font-size:12px;background:#fff;padding:4px 8px;border-radius:5px;margin:2px;cursor:pointer;border:1px solid var(--border)">
            <input type="checkbox" name="brand_ids[]" value="<?= $b['id'] ?>" class="t_brand_cb">
            <span><?= h($b['name']) ?></span>
            <input type="radio" name="primary_brand_id" value="<?= $b['id'] ?>" class="t_primary_rb" title="Imposta primario" style="margin-left:4px">
          </label>
        <?php endforeach; ?>
      </div>
      <div style="font-size:10px;color:var(--muted);margin-top:4px">Spunta i brand applicabili. Il radio button indica il brand <strong>primario</strong>.</div>

      <h3 style="margin:16px 0 8px;font-size:14px;color:#1e40af"><i class="fa-solid fa-certificate"></i> Certificazioni associate (N:M con relevance)</h3>
      <div class="cert-list">
        <?php foreach ($certifications as $c): ?>
          <div class="cert-item">
            <input type="checkbox" name="cert_ids[]" value="<?= $c['id'] ?>" id="cert_<?= $c['id'] ?>" class="t_cert_cb">
            <label for="cert_<?= $c['id'] ?>"><strong><?= h($c['code']) ?></strong> — <?= h($c['name']) ?> <span style="color:var(--muted)">(<?= h($c['brand_name']) ?>)</span></label>
            <select name="cert_relevance[<?= $c['id'] ?>]">
              <option value="primary">primary</option>
              <option value="secondary">secondary</option>
              <option value="related">related</option>
            </select>
          </div>
        <?php endforeach; ?>
      </div>

      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
        <button type="button" onclick="closeTechModal()" class="btn">Annulla</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Salva</button>
      </div>
    </form>
  </div>
</div>

<script>
const techBrandsMap = <?= json_encode($tech_brands_map) ?>;
const techCertsMap  = <?= json_encode($tech_certs_map) ?>;

function openTechModal(t = null) {
  document.getElementById("techModalTitle").innerText = t ? "Modifica tecnologia" : "Nuova tecnologia";
  document.getElementById("t_id").value      = t ? t.id : 0;
  document.getElementById("t_name").value    = t ? t.name : "";
  document.getElementById("t_desc").value    = t && t.description ? t.description : "";
  document.getElementById("t_category").value= t && t.category_id ? t.category_id : "";
  document.getElementById("t_icon").value    = t && t.icon ? t.icon : "";
  document.getElementById("t_color").value   = t && t.color ? t.color : "#0ea5e9";
  document.getElementById("t_active").checked= t ? !!parseInt(t.is_active) : true;

  // Reset checkboxes
  document.querySelectorAll(".t_brand_cb").forEach(cb => cb.checked = false);
  document.querySelectorAll(".t_primary_rb").forEach(rb => rb.checked = false);
  document.querySelectorAll(".t_cert_cb").forEach(cb => cb.checked = false);

  if (t && t.id) {
    (techBrandsMap[t.id] || []).forEach(tb => {
      const cb = document.querySelector('.t_brand_cb[value="' + tb.id + '"]');
      if (cb) cb.checked = true;
      if (tb.is_primary) {
        const rb = document.querySelector('.t_primary_rb[value="' + tb.id + '"]');
        if (rb) rb.checked = true;
      }
    });
    (techCertsMap[t.id] || []).forEach(tc => {
      const cb = document.querySelector('.t_cert_cb[value="' + tc.id + '"]');
      if (cb) cb.checked = true;
      const sel = document.querySelector('select[name="cert_relevance[' + tc.id + ']"]');
      if (sel) sel.value = tc.relevance || 'primary';
    });
  }
  document.getElementById("techModal").classList.add("show");
}
function confirmDelete(form, certCount, name) {
  if (certCount > 0) {
    if (!confirm("ATTENZIONE: la tecnologia '" + name + "' ha " + certCount + " certificazioni nel catalogo. Eliminandola, anche le certificazioni verranno ELIMINATE (FK CASCADE).\n\nProcedere comunque?")) return false;
    form.querySelector('.force-flag').value = '1';
    return confirm("Conferma definitiva: eliminare '" + name + "' E " + certCount + " certificazioni associate?");
  }
  return confirm("Eliminare la tecnologia '" + name + "'?");
}
function closeTechModal() { document.getElementById("techModal").classList.remove("show"); }
document.getElementById("techModal").addEventListener("click", e => { if (e.target.id === "techModal") closeTechModal(); });
</script>

<?php require_once('footer.php'); ?>
