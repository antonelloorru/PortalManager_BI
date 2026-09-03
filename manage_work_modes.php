<?php
/**
 * certV 2.0 — manage_work_modes.php
 * FIX: aggiunto controllo ruolo (mancava) + validazione input
 */
require_once('access_control.php');
require_once('header.php');

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
$msg    = '';

// Solo Admin e HR Director
if (!can('edit')) { header("Location: unauthorized.php"); exit(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id    = (int)($_POST['mode_id'] ?? 0);
    $name  = trim($_POST['name'] ?? '');
    $color = trim($_POST['color_hex'] ?? '#f1f5f9');
    $desc  = trim($_POST['description'] ?? '') ?: null;

    if (!$name) {
        $msg = "<div class='alert alert-warning'>Il nome è obbligatorio.</div>";
    } else {
        // Validazione hex color
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#f1f5f9';

        try {
            if ($id > 0) {
                $pdo->prepare("UPDATE work_modes SET name=?, color_hex=?, description=? WHERE id=?")
                    ->execute([$name, $color, $desc, $id]);
                $msg = "<div class='alert alert-success'>Modalità aggiornata.</div>";
            } else {
                $pdo->prepare("INSERT INTO work_modes (name, color_hex, description) VALUES (?,?,?)")
                    ->execute([$name, $color, $desc]);
                $msg = "<div class='alert alert-success'>Modalità creata.</div>";
            }
            write_log('WorkModes','success',($id>0?'Modifica':'Nuova')." modalità: $name",$u_id);
        } catch (Exception $e) {
            $msg = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
        }
    }
}

$modes = $pdo->query("SELECT * FROM work_modes ORDER BY name")->fetchAll();
?>

<div style="margin-bottom:22px">
  <h1 style="font-size:20px;font-weight:800;margin-bottom:3px">
    <i class="fa-solid fa-laptop-house" style="color:var(--p);margin-right:10px"></i>Modalità di lavoro
  </h1>
  <p style="color:var(--muted);font-size:13px">Configura le opzioni di presenza disponibili per gli utenti</p>
</div>

<?=$msg?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:22px">

  <!-- Lista modalità -->
  <div class="card" style="overflow-x:auto">
    <?php require_once __DIR__ . '/app/ListFilter.php'; ListFilter::render('manage_work_modes', '#lf-table-manage_work_modes', ['export_filename' => 'manage_work_modes', 'title' => 'Modalità lavoro']); ?>
<table id="lf-table-manage_work_modes" class="data-table">
      <thead>
        <tr>
          <th>Nome</th>
          <th>Colore badge</th>
          <th>Descrizione</th>
          <th style="text-align:center">Utenti assegnati</th>
          <th style="text-align:center" class="no-print">Azioni</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($modes as $m):
        $s_cnt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE work_mode_id=? AND status='active'");
        $s_cnt->execute([$m['id']]);
        $cnt = (int)$s_cnt->fetchColumn();
      ?>
      <tr>
        <td><strong><?=h($m['name'])?></strong></td>
        <td>
          <span style="background:<?=h($m['color_hex'])?>;padding:4px 16px;border-radius:20px;font-size:11px;font-weight:700;border:1px solid #ddd;display:inline-block">
            <?=h($m['color_hex'])?>
          </span>
        </td>
        <td style="font-size:12px;color:var(--muted)"><?=h($m['description']??'—')?></td>
        <td style="text-align:center">
          <span style="font-weight:700;color:<?=$cnt>0?'var(--success)':'var(--muted)'?>"><?=$cnt?></span>
        </td>
        <td style="text-align:center" class="no-print">
          <button onclick='editMode(<?=json_encode($m,JSON_HEX_APOS|JSON_HEX_QUOT)?>)' class="btn btn-blue btn-sm">
            <i class="fa-solid fa-pen"></i>
          </button>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($modes)): ?>
      <tr><td colspan="5" style="text-align:center;padding:30px;color:var(--muted)">Nessuna modalità configurata.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Form aggiunta/modifica -->
  <div class="card">
    <div class="card-header"><span class="card-title" id="form_title"><i class="fa-solid fa-plus" style="color:var(--p)"></i> Nuova modalità</span></div>
    <form method="POST">
            <?= csrf_field() ?>
      <input type="hidden" name="mode_id" id="m_id" value="0">
      <div class="form-group">
        <label>Nome *</label>
        <input type="text" name="name" id="m_name" required placeholder="Es. Smart Working">
      </div>
      <div class="form-group">
        <label>Colore badge</label>
        <div style="display:flex;gap:12px;align-items:center">
          <input type="color" name="color_hex" id="m_color" value="#f1f5f9"
                 style="width:56px;height:44px;padding:2px;cursor:pointer;border:1px solid var(--border);border-radius:8px"
                 oninput="updatePreview()">
          <div>
            <div style="font-weight:600;font-size:13px" id="color_hex_lbl">#F1F5F9</div>
            <div style="font-size:11px;color:var(--muted)">Colore del badge in tabella</div>
          </div>
        </div>
        <div id="badge_preview" style="margin-top:10px">
          <span id="badge_demo" style="background:#f1f5f9;padding:4px 16px;border-radius:20px;font-size:11px;font-weight:700;border:1px solid #ddd">Anteprima</span>
        </div>
      </div>
      <div class="form-group">
        <label>Descrizione</label>
        <input type="text" name="description" id="m_desc" placeholder="Es. Lavoro da remoto totale">
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:11px">
          <i class="fa-solid fa-floppy-disk"></i> Salva
        </button>
        <button type="button" onclick="resetForm()" class="btn" style="padding:11px 14px" title="Nuova modalità">
          <i class="fa-solid fa-rotate-left"></i>
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function editMode(m) {
  document.getElementById('m_id').value   = m.id;
  document.getElementById('m_name').value = m.name;
  document.getElementById('m_desc').value = m.description || '';
  document.getElementById('m_color').value= m.color_hex || '#f1f5f9';
  document.getElementById('form_title').innerHTML = '<i class="fa-solid fa-pen" style="color:var(--p)"></i> Modifica: ' + m.name;
  updatePreview();
  document.getElementById('m_name').focus();
}
function resetForm() {
  document.getElementById('m_id').value   = 0;
  document.getElementById('m_name').value = '';
  document.getElementById('m_desc').value = '';
  document.getElementById('m_color').value = '#f1f5f9';
  document.getElementById('form_title').innerHTML = '<i class="fa-solid fa-plus" style="color:var(--p)"></i> Nuova modalità';
  updatePreview();
}
function updatePreview() {
  const c = document.getElementById('m_color').value;
  document.getElementById('color_hex_lbl').textContent = c.toUpperCase();
  document.getElementById('badge_demo').style.background = c;
}
</script>
<?php require_once('footer.php'); ?>
