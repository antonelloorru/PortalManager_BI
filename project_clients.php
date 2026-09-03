<?php
/**
 * PortalManager — project_clients.php
 *
 * Gestione anagrafica clienti per i progetti.
 * I clienti sono indipendenti da `companies` (che sono i clienti aziendali
 * gestiti separatamente), ma possono opzionalmente referenziarli.
 */
require_once('access_control.php');

$u_role  = (int)($_SESSION['role_id'] ?? 99);
$u_id    = (int)$_SESSION['user_id'];
$can_create = can('create');
$can_edit   = can('edit');
$can_delete = can('delete');
$msg = '';

// ── POST: SAVE cliente ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_client'])) {
    Csrf::verify();

    $id = (int)($_POST['client_id'] ?? 0);
    $data = [
        'name'            => trim((string)($_POST['name'] ?? '')),
        'size_category'   => $_POST['size_category'] ?? 'PMI',
        'employees_count' => ($_POST['employees_count'] ?? '') !== '' ? (int)$_POST['employees_count'] : null,
        'users_count'     => ($_POST['users_count']     ?? '') !== '' ? (int)$_POST['users_count']     : null,
        'industry'        => trim((string)($_POST['industry'] ?? '')) ?: null,
        'company_id'      => ($_POST['company_id'] ?? '') !== '' ? (int)$_POST['company_id'] : null,
        'notes'           => trim((string)($_POST['notes'] ?? '')) ?: null,
    ];

    if ($data['name'] === '') {
        $msg = "<div class='alert alert-danger'>Nome cliente obbligatorio.</div>";
    } else {
        try {
            if ($id > 0 && $can_edit) {
                $sql = "UPDATE project_clients SET name=?, size_category=?, employees_count=?, users_count=?, industry=?, company_id=?, notes=? WHERE id=?";
                $pdo->prepare($sql)->execute([...array_values($data), $id]);
                $action = 'aggiornato';
            } elseif ($can_create) {
                $sql = "INSERT INTO project_clients (name, size_category, employees_count, users_count, industry, company_id, notes) VALUES (?,?,?,?,?,?,?)";
                $pdo->prepare($sql)->execute(array_values($data));
                $id = (int)$pdo->lastInsertId();
                $action = 'creato';
            } else {
                throw new RuntimeException('Non autorizzato');
            }

            write_log('Projects','success',"Cliente progetto #$id $action: " . $data['name'],$u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Cliente $action.</div>";
            redirect_self();
        } catch (Throwable $e) {
            $msg = "<div class='alert alert-danger'>Errore: " . h($e->getMessage()) . "</div>";
        }
    }
}

// ── POST: DELETE cliente ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_delete && isset($_POST['delete_client'])) {
    Csrf::verify();
    $id = (int)$_POST['client_id'];
    if ($id > 0) {
        try {
            // Verifico se ci sono progetti associati (FK ON DELETE RESTRICT)
            $count = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE client_id = ?");
            $count->execute([$id]);
            $n = (int)$count->fetchColumn();
            if ($n > 0) {
                $_SESSION['flash_msg'] = "<div class='alert alert-warning'>Impossibile eliminare: il cliente ha $n progett" . ($n === 1 ? 'o' : 'i') . " associat" . ($n === 1 ? 'o' : 'i') . ". Eliminare prima i progetti.</div>";
            } else {
                $pdo->prepare("DELETE FROM project_clients WHERE id = ?")->execute([$id]);
                write_log('Projects','success',"Cliente progetto #$id eliminato",$u_id);
                $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Cliente eliminato.</div>";
            }
        } catch (Throwable $e) {
            $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Errore: " . h($e->getMessage()) . "</div>";
        }
    }
    redirect_self();
}

if (!empty($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }

// ── Modalità: lista o form? ──
$edit_id = (int)($_GET['edit'] ?? 0);
$mode_new = isset($_GET['new']);
$show_form = $edit_id > 0 || $mode_new;

$editing = null;
if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM project_clients WHERE id = ?");
    $stmt->execute([$edit_id]);
    $editing = $stmt->fetch() ?: null;
}

// Lista clienti con conteggio progetti
$clients = $pdo->query("
    SELECT pc.*, COUNT(p.id) AS projects_count
      FROM project_clients pc
      LEFT JOIN projects p ON p.client_id = pc.id
     GROUP BY pc.id
     ORDER BY pc.name
")->fetchAll();

// Companies per dropdown (riferimento opzionale)
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();

require_once('header.php');
?>

<div style="margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:#0f172a;font-size:22px">
      <i class="fa-solid fa-handshake" style="color:#7c3aed"></i> Anagrafica clienti progetti
    </h2>
    <div style="font-size:12px;color:#64748b;margin-top:3px">
      <a href="<?= url_safe('projects') ?>" style="color:#64748b"><i class="fa-solid fa-arrow-left"></i> Torna ai progetti</a>
    </div>
  </div>
  <?php if ($can_create && !$show_form): ?>
  <a href="<?= qs_self_safe(['new' => 1]) ?>" class="btn btn-primary" style="background:#7c3aed">
    <i class="fa-solid fa-plus"></i> Nuovo cliente
  </a>
  <?php endif; ?>
</div>

<?= $msg ?>

<?php if ($show_form): ?>
<!-- ═════ FORM CLIENTE ═════ -->
<div class="card" style="padding:18px;margin-bottom:14px">
  <h3 style="margin:0 0 14px 0;font-size:15px;color:#7c3aed">
    <?= $editing ? 'Modifica cliente: ' . h($editing['name']) : 'Nuovo cliente' ?>
  </h3>
  <form method="POST" action="<?= h(qs_self_safe()) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="save_client" value="1">
    <?php if ($editing): ?><input type="hidden" name="client_id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>

    <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:10px;margin-bottom:10px">
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Ragione sociale *</label>
        <input type="text" name="name" value="<?= h($editing['name'] ?? '') ?>" required maxlength="200"
               style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
      </div>
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Dimensione *</label>
        <select name="size_category" required
                style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
          <option value="PMI"        <?= ($editing['size_category'] ?? 'PMI')==='PMI'?'selected':'' ?>>PMI</option>
          <option value="Enterprise" <?= ($editing['size_category'] ?? '')==='Enterprise'?'selected':'' ?>>Enterprise</option>
          <option value="Core/Infrastruttura Datacenter" <?= ($editing['size_category'] ?? '')==='Core/Infrastruttura Datacenter'?'selected':'' ?>>Core/Infrastruttura Datacenter</option>
        </select>
      </div>
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Settore</label>
        <input type="text" name="industry" value="<?= h($editing['industry'] ?? '') ?>" maxlength="120"
               placeholder="Es: Sanitario, Bancario, ..."
               style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr 2fr;gap:10px;margin-bottom:10px">
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Numero dipendenti</label>
        <input type="number" name="employees_count" value="<?= h($editing['employees_count'] ?? '') ?>" min="0"
               style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
      </div>
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Numero utenti</label>
        <input type="number" name="users_count" value="<?= h($editing['users_count'] ?? '') ?>" min="0"
               style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
      </div>
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Azienda gestita (opz.)</label>
        <select name="company_id"
                style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
          <option value="">— Cliente esterno (non in anagrafica aziendale) —</option>
          <?php foreach ($companies as $co): ?>
          <option value="<?= (int)$co['id'] ?>" <?= (int)($editing['company_id'] ?? 0) === (int)$co['id'] ? 'selected' : '' ?>>
            <?= h($co['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div style="margin-bottom:14px">
      <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Note</label>
      <textarea name="notes" rows="2"
                style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;font-family:inherit"><?= h($editing['notes'] ?? '') ?></textarea>
    </div>

    <div style="display:flex;gap:8px">
      <button type="submit" class="btn btn-primary" style="background:#7c3aed">
        <i class="fa-solid fa-floppy-disk"></i> <?= $editing ? 'Salva' : 'Crea cliente' ?>
      </button>
      <a href="<?= url_safe('project_clients') ?>" class="btn">
        <i class="fa-solid fa-xmark"></i> Annulla
      </a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- ═════ TABELLA CLIENTI ═════ -->
<?php require_once __DIR__ . '/app/ListFilter.php';
ListFilter::render('project_clients', '#tClients', ['export_filename' => 'clienti_progetti', 'title' => 'Clienti progetti']); ?>

<div class="card" style="overflow-x:auto">
  <table id="tClients" class="data-table" style="width:100%;border-collapse:collapse">
    <thead>
      <tr style="background:#1e293b;color:#fff">
        <th style="padding:9px 10px;text-align:left">Cliente</th>
        <th style="padding:9px 10px;text-align:left">Settore</th>
        <th style="padding:9px 10px;text-align:left">Dimensione</th>
        <th style="padding:9px 10px;text-align:right">Dipendenti</th>
        <th style="padding:9px 10px;text-align:right">Utenti</th>
        <th style="padding:9px 10px;text-align:center">Progetti</th>
        <?php if ($can_edit || $can_delete): ?>
        <th style="padding:9px 10px;text-align:center">Azioni</th>
        <?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($clients)): ?>
      <tr><td colspan="7" style="padding:30px;text-align:center;color:#94a3b8;font-style:italic">
        Nessun cliente registrato. <?php if ($can_create): ?><a href="<?= qs_self_safe(['new'=>1]) ?>">Crea il primo →</a><?php endif; ?>
      </td></tr>
      <?php else: ?>
      <?php foreach ($clients as $c): ?>
      <tr style="border-bottom:1px solid #e2e8f0">
        <td style="padding:8px 10px"><strong><?= h($c['name']) ?></strong></td>
        <td style="padding:8px 10px"><?= h($c['industry'] ?? '—') ?></td>
        <td style="padding:8px 10px">
          <?php
            $color = match($c['size_category']) {
                'PMI' => '#0ea5e9',
                'Enterprise' => '#7c3aed',
                default => '#dc2626',
            };
          ?>
          <span style="background:<?= $color ?>15;color:<?= $color ?>;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600;border:1px solid <?= $color ?>40">
            <?= h($c['size_category']) ?>
          </span>
        </td>
        <td style="padding:8px 10px;text-align:right;font-variant-numeric:tabular-nums">
          <?= $c['employees_count'] !== null ? number_format((int)$c['employees_count'], 0, ',', '.') : '—' ?>
        </td>
        <td style="padding:8px 10px;text-align:right;font-variant-numeric:tabular-nums">
          <?= $c['users_count'] !== null ? number_format((int)$c['users_count'], 0, ',', '.') : '—' ?>
        </td>
        <td style="padding:8px 10px;text-align:center">
          <?php if ((int)$c['projects_count'] > 0): ?>
            <a href="<?= url_safe('projects', ['client_filter' => (int)$c['id']]) ?>"
               style="background:#7c3aed15;color:#7c3aed;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:700;text-decoration:none">
              <?= (int)$c['projects_count'] ?>
            </a>
          <?php else: ?>
            <span style="color:#94a3b8;font-size:11px">0</span>
          <?php endif; ?>
        </td>
        <?php if ($can_edit || $can_delete): ?>
        <td style="padding:8px 10px;text-align:center;white-space:nowrap">
          <?php if ($can_edit): ?>
          <a href="<?= qs_self_safe(['edit' => (int)$c['id']]) ?>" class="btn btn-sm" title="Modifica"
             style="background:#3b82f6;color:#fff"><i class="fa-solid fa-pen"></i></a>
          <?php endif; ?>
          <?php if ($can_delete): ?>
          <form method="POST" style="display:inline-block;margin-left:3px"
                onsubmit="return confirm('Eliminare il cliente «<?= h(addslashes($c['name'])) ?>»?');">
            <?= csrf_field() ?>
            <input type="hidden" name="delete_client" value="1">
            <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
            <button type="submit" class="btn btn-sm" title="Elimina"
                    style="background:#dc2626;color:#fff;border:0"
                    <?= (int)$c['projects_count'] > 0 ? 'disabled' : '' ?>>
              <i class="fa-solid fa-trash"></i>
            </button>
          </form>
          <?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require_once('footer.php'); ?>
