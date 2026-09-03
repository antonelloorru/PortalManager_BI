<?php
/**
 * certV 2.0 v2.2 — manager_users.php   Gestione utenze di accesso
 * In v2.2 gestisce SOLO l'account (email, password, ruolo, stato).
 * L'anagrafica (nome, sede, contratto ecc.) è in manage_employees.php
 * FIX parse error riga 195: variabile PHP separata per color ternario
 */
require_once('access_control.php');
require_once('header.php');

$u_id    = (int)$_SESSION['user_id'];
$u_role  = (int)($_SESSION['role_id'] ?? 99);
$can_edit = can('edit');
$msg     = '';

// ── CRUD ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $action    = $_POST['action'] ?? '';
    $target_id = (int)($_POST['user_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        if ($action === 'save') {
            $email    = trim($_POST['email'] ?? '');
            $role_id  = (int)($_POST['role_id'] ?? 6);
            $status   = $_POST['status'] ?? 'active';
            $emp_id   = ((int)($_POST['employee_id'] ?? 0)) ?: null;
            $disp     = trim($_POST['display_name'] ?? '') ?: null;

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("Formato email non valido.");

            if ($target_id > 0) {
                // UPDATE — non toccare employee_id se non cambiato
                $pdo->prepare(
                    "UPDATE users SET email=?, role_id=?, status=?, employee_id=?, display_name=? WHERE id=?"
                )->execute([$email, $role_id, $status, $emp_id, $disp, $target_id]);

                if (!empty(trim($_POST['password'] ?? ''))) {
                    $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")
                        ->execute([password_hash(trim($_POST['password']), PASSWORD_BCRYPT), $target_id]);
                }
                $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Account aggiornato.</div>";
                write_log('Users','success',"Modifica account #$target_id",$u_id);
            } else {
                if (empty(trim($_POST['password'] ?? ''))) throw new Exception("Password obbligatoria per nuovi account.");
                $pdo->prepare(
                    "INSERT INTO users (email, password_hash, role_id, status, employee_id, display_name)
                     VALUES (?,?,?,?,?,?)"
                )->execute([$email, password_hash(trim($_POST['password']), PASSWORD_BCRYPT), $role_id, $status, $emp_id, $disp]);
                $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Account creato.</div>";
                write_log('Users','success',"Creazione account: $email",$u_id);
            }
        }

        if ($action === 'toggle_status' && $target_id !== $u_id) {
            $cur = $pdo->prepare("SELECT status FROM users WHERE id=?");
            $cur->execute([$target_id]);
            $new = ($cur->fetchColumn() === 'active') ? 'inactive' : 'active';
            $pdo->prepare("UPDATE users SET status=? WHERE id=?")->execute([$new, $target_id]);
            $msg = "<div class='alert alert-success'>Stato account aggiornato.</div>";
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
    }
}

// ── Filtri ─────────────────────────────────────────────────────────────────────
$s    = trim($_GET['s']    ?? '');
$f_r  = (int)($_GET['f_r'] ?? 0);
$f_st = $_GET['f_st'] ?? 'active';

$where = ["1=1"]; $params = [];
if ($s) {
    // cerca su email oppure su nome/cognome dipendente collegato
    $where[] = "(u.email LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ? OR u.display_name LIKE ?)";
    array_push($params, "%$s%", "%$s%", "%$s%", "%$s%");
}
if ($f_r)  { $where[] = "u.role_id=?"; $params[] = $f_r; }
if ($f_st) { $where[] = "u.status=?";  $params[] = $f_st; }

$users_q = $pdo->prepare(
    "SELECT u.id, u.email, u.role_id, u.status, u.employee_id, u.display_name, u.created_at,
            r.name role_name,
            e.first_name, e.last_name, e.job_title,
            co.name company_name,
            wm.name mode_name, wm.color_hex mode_color,
            (SELECT COUNT(*) FROM user_certifications uc WHERE uc.employee_id=e.id AND uc.status='active') cert_count
     FROM users u
     JOIN roles r                 ON u.role_id = r.id
     LEFT JOIN employees e        ON u.employee_id = e.id
     LEFT JOIN companies co       ON e.company_id = co.id
     LEFT JOIN work_modes wm      ON e.work_mode_id = wm.id
     WHERE " . implode(" AND ", $where) . "
     ORDER BY COALESCE(e.last_name, u.display_name, u.email)"
);
$users_q->execute($params);
$user_list = $users_q->fetchAll();

$all_roles     = $pdo->query("SELECT * FROM roles ORDER BY id")->fetchAll();
// Dipendenti senza account (per associazione) + quelli già associati all'utente in edit
$free_emps     = $pdo->query(
    "SELECT e.id, e.first_name, e.last_name, e.job_title
     FROM employees e
     WHERE e.status='active'
       AND NOT EXISTS (SELECT 1 FROM users u WHERE u.employee_id = e.id)
     ORDER BY e.last_name"
)->fetchAll();
$all_emps      = $pdo->query("SELECT id, first_name, last_name, job_title FROM employees WHERE status='active' ORDER BY last_name")->fetchAll();
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
  <div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:3px">
      <i class="fa-solid fa-key" style="color:var(--p);margin-right:10px"></i>Gestione accessi
    </h1>
    <p style="color:var(--muted);font-size:13px">
      Account e credenziali — <?=count($user_list)?> account trovati.
      <a href="manage_employees.php" style="color:var(--p);font-weight:600">Anagrafica dipendenti →</a>
    </p>
  </div>
  <?php if($can_edit): ?>
  <button onclick="openModal()" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Nuovo account</button>
  <?php endif; ?>
</div>

<?=$msg?>

<form method="GET" class="filter-bar">
<?php if (!empty($_GET["r"])): ?><input type="hidden" name="r" value="<?= htmlspecialchars($_GET["r"], ENT_QUOTES, "UTF-8") ?>"><?php endif; ?>
  <div class="fg"><label>Cerca</label><input type="text" name="s" value="<?=h($s)?>" placeholder="Email, nome dipendente…" style="min-width:200px"></div>
  <div class="fg">
    <label>Ruolo</label>
    <select name="f_r">
      <option value="0">Tutti</option>
      <?php foreach($all_roles as $r): ?><option value="<?=$r['id']?>" <?=$f_r==$r['id']?'selected':''?>><?=h($r['name'])?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="fg">
    <label>Stato</label>
    <select name="f_st">
      <option value="active"   <?=$f_st==='active'  ?'selected':''?>>Attivi</option>
      <option value="inactive" <?=$f_st==='inactive'?'selected':''?>>Disattivi</option>
      <option value=""                                              >Tutti</option>
    </select>
  </div>
  <button type="submit" class="btn btn-primary">Filtra</button>

  <div class="fg" style="margin-left:auto">
    <label style="visibility:hidden">.</label>
    <button type="button" onclick="window.print()" class="btn btn-sm" title="Stampa pagina"
            style="background:#fef3c7;color:#92400e;border-color:#fde68a">
      <i class="fa-solid fa-print"></i> Stampa
    </button>
  </div>
</form>

<div class="card" style="overflow-x:auto">
  <?php require_once __DIR__ . '/app/ListFilter.php'; ListFilter::render('manager_users', '#tUsers', ['export_filename' => 'manager_users', 'title' => 'Gestione utenti']); ?>
<table class="data-table" id="tUsers">
    <thead>
      <tr>
        <th>Account / Email</th>
        <th>Dipendente collegato</th>
        <th>Ruolo</th>
        <th style="text-align:center">Cert.</th>
        <th style="text-align:center">Stato</th>
        <th style="text-align:center" class="no-print">Azioni</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($user_list as $u): ?>
    <tr>
      <td>
        <div style="font-weight:700;font-size:13px"><?=h($u['email'])?></div>
        <?php if($u['display_name'] && !$u['employee_id']): ?>
        <div style="font-size:11px;color:var(--muted)"><?=h($u['display_name'])?> <em>(account di servizio)</em></div>
        <?php endif; ?>
        <div style="font-size:10px;color:var(--muted)">Creato: <?=format_date($u['created_at'])?></div>
      </td>
      <td>
        <?php if($u['employee_id']): ?>
        <div style="font-weight:600"><?=h($u['last_name'].' '.$u['first_name'])?></div>
        <div style="font-size:11px;color:var(--muted)"><?=h($u['job_title']??'—')?> · <?=h($u['company_name']??'—')?></div>
        <?php if($u['mode_name']): ?>
        <span style="background:<?=h($u['mode_color']??'#eee')?>;padding:2px 8px;border-radius:10px;font-size:9px;font-weight:700;margin-top:3px;display:inline-block"><?=h($u['mode_name'])?></span>
        <?php endif; ?>
        <?php else: ?>
        <span style="color:var(--muted);font-size:12px;font-style:italic">— nessun dipendente associato</span>
        <?php endif; ?>
      </td>
      <td><span class="badge badge-info" style="font-size:9px"><?=h($u['role_name'])?></span></td>
      <td style="text-align:center">
        <?php $cc = $u['cert_count'] > 0 ? 'var(--success)' : 'var(--muted)'; ?>
        <span style="font-weight:800;font-size:14px;color:<?=$cc?>"><?=$u['cert_count']?></span>
      </td>
      <td style="text-align:center">
        <?=$u['status']==='active'
          ? '<span class="badge badge-success">Attivo</span>'
          : '<span class="badge badge-neutral">Inattivo</span>'?>
      </td>
      <td style="text-align:center;white-space:nowrap" class="no-print">
        <?php if($u['employee_id']): ?>
        <a href="user_profile.php?id=<?=$u['id']?>" target="_blank" class="btn btn-sm" title="Dossier"><i class="fa-solid fa-id-card"></i></a>
        <?php endif; ?>
        <?php if($can_edit): ?>
        <button onclick='openModal(<?=json_encode($u,JSON_HEX_APOS|JSON_HEX_QUOT)?>)' class="btn btn-sm" title="Modifica">
          <i class="fa-solid fa-pen"></i>
        </button>
        <?php if($u['id'] !== $u_id): ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Confermi cambio stato?')">
        <?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle_status">
          <input type="hidden" name="user_id" value="<?=$u['id']?>">
          <button type="submit" class="btn btn-sm <?=$u['status']==='active'?'btn-danger':'btn-success'?>"
                  title="<?=$u['status']==='active'?'Disattiva':'Riattiva'?>">
            <i class="fa-solid <?=$u['status']==='active'?'fa-user-slash':'fa-user-check'?>"></i>
          </button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if(empty($user_list)): ?>
    <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--muted)">Nessun account trovato.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if($can_edit): ?>
<!-- Modal account -->
<div id="mUser" class="modal-overlay" style="z-index:1000">
  <div class="modal-box" style="width:600px">
    <div style="display:flex;justify-content:space-between;margin-bottom:20px">
      <h3 style="margin:0;font-size:16px" id="mUserTitle">Nuovo account</h3>
      <button onclick="closeModal('mUser')" style="border:none;background:none;font-size:22px;cursor:pointer;color:var(--muted)">&times;</button>
    </div>
    <form method="POST" id="userForm">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="user_id" id="m_id" value="0">

      <div class="form-group">
        <label>Email *</label>
        <input type="email" name="email" id="m_em" required>
      </div>
      <div class="grid-2">
        <div class="form-group">
          <label>Ruolo</label>
          <select name="role_id" id="m_ro">
            <?php foreach($all_roles as $r): ?><option value="<?=$r['id']?>"><?=h($r['name'])?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Stato</label>
          <select name="status" id="m_st">
            <option value="active">Attivo</option>
            <option value="inactive">Inattivo</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Password <span id="pwd_hint" style="color:var(--muted);font-weight:400">(obbligatoria per nuovi account)</span></label>
        <input type="password" name="password" placeholder="Lascia vuoto per non cambiare">
      </div>

      <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:9px;padding:14px;margin-bottom:16px">
        <div style="font-size:11px;font-weight:700;color:#0369a1;text-transform:uppercase;margin-bottom:10px">
          Dipendente collegato (opzionale)
        </div>
        <div class="form-group" style="margin-bottom:8px">
          <label>Collega a dipendente in anagrafica</label>
          <select name="employee_id" id="m_emp">
            <option value="0">— Nessun dipendente (account di servizio) —</option>
            <?php foreach($all_emps as $emp): ?>
            <option value="<?=$emp['id']?>"><?=h($emp['last_name'].' '.$emp['first_name'])?><?=$emp['job_title']?' — '.h($emp['job_title']):'';?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin:0">
          <label>Nome visualizzato <span style="font-weight:400;color:var(--muted)">(se account di servizio)</span></label>
          <input type="text" name="display_name" id="m_dn" placeholder="Es. Sistema Automatico, Cron Bot…">
        </div>
      </div>

      <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px;margin-bottom:16px;font-size:11px;color:#92400e">
        <i class="fa-solid fa-lock" style="margin-right:5px"></i>La password viene salvata come hash bcrypt — mai in chiaro nel database.
      </div>
      <div style="display:flex;gap:10px">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:12px">Salva account</button>
        <button type="button" onclick="closeModal('mUser')" class="btn" style="flex:1;justify-content:center;padding:12px">Annulla</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
$('#tUsers').DataTable({language:{search:"Cerca:"},pageLength:25,order:[[0,'asc']]});

function openModal(u=null){
  document.getElementById('userForm').reset();
  document.getElementById('m_id').value = 0;
  document.getElementById('mUserTitle').textContent = 'Nuovo account';
  document.getElementById('m_ro').value = 6;
  document.getElementById('m_st').value = 'active';
  document.getElementById('m_emp').value = 0;
  document.getElementById('pwd_hint').style.display = '';
  if(u){
    document.getElementById('m_id').value = u.id;
    document.getElementById('mUserTitle').textContent = 'Modifica account: ' + u.email;
    document.getElementById('m_em').value  = u.email || '';
    document.getElementById('m_ro').value  = u.role_id || 6;
    document.getElementById('m_st').value  = u.status || 'active';
    document.getElementById('m_emp').value = u.employee_id || 0;
    document.getElementById('m_dn').value  = u.display_name || '';
    document.getElementById('pwd_hint').style.display = 'none';
  }
  document.getElementById('mUser').style.display = 'flex';
}
</script>
<?php require_once('footer.php'); ?>
