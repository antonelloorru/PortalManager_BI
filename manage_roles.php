<?php
/**
 * certV 2.0 — manage_roles.php   Gestione ruoli (solo Super Admin)
 */
require_once('access_control.php');
require_once('header.php');

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
if ($u_role !== 1) { header("Location: unauthorized.php"); exit(); }

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $name = trim($_POST['role_name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            if ($name) {
                $pdo->prepare("INSERT INTO roles (name, description) VALUES (?,?)")->execute([$name, $desc ?: null]);
                $msg = "<div class='alert alert-success'>Ruolo '{$name}' creato.</div>";
                write_log('Roles','success',"Nuovo ruolo: $name",$u_id);
            }
        }
        if ($action === 'edit') {
            $rid  = (int)$_POST['role_id'];
            $name = trim($_POST['role_name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $old  = $pdo->prepare("SELECT * FROM roles WHERE id=?"); $old->execute([$rid]);
            $pdo->prepare("INSERT INTO brand_contacts_history (brand_id,archived_data,archived_by) VALUES (NULL,?,?)")
                ->execute([json_encode(['type'=>'role_rename','old'=>$old->fetch()]), $u_id]);
            $pdo->prepare("UPDATE roles SET name=?, description=? WHERE id=?")->execute([$name, $desc, $rid]);
            $msg = "<div class='alert alert-success'>Ruolo aggiornato.</div>";
        }
        if ($action === 'delete') {
            $rid = (int)$_POST['role_id'];
            if ($rid <= 6) throw new Exception("I ruoli di sistema (1-6) non possono essere eliminati.");
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_id=?"); $cnt->execute([$rid]);
            if ($cnt->fetchColumn() > 0) throw new Exception("Impossibile: ci sono utenti assegnati a questo ruolo.");
            $pdo->prepare("DELETE FROM roles WHERE id=?")->execute([$rid]);
            $msg = "<div class='alert alert-success'>Ruolo eliminato.</div>";
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
    }
}

$roles = $pdo->query(
    "SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id=r.id) user_count FROM roles r ORDER BY r.id"
)->fetchAll();
?>
<div style="margin-bottom:22px">
  <h1 style="font-size:20px;font-weight:800"><i class="fa-solid fa-user-tag" style="color:var(--p);margin-right:10px"></i>Gestione ruoli</h1>
  <p style="color:var(--muted);font-size:13px">I 6 ruoli di sistema (ID 1-6) non possono essere eliminati.</p>
</div>
<?=$msg?>
<div style="display:grid;grid-template-columns:1fr 310px;gap:24px">
  <div class="card" style="overflow-x:auto">
    <table class="data-table" id="tRoles">
      <thead><tr><th style="width:50px">ID</th><th>Nome</th><th>Descrizione</th><th style="text-align:center">Utenti</th><th style="text-align:center" class="no-print">Azioni</th></tr></thead>
      <tbody>
      <?php foreach($roles as $r): ?>
      <tr>
        <td><code style="color:var(--muted)">#<?=$r['id']?></code></td>
        <td><strong><?=h($r['name'])?></strong><?php if($r['id']<=6): ?><span class="badge badge-neutral" style="font-size:8px;margin-left:6px">Sistema</span><?php endif; ?></td>
        <td style="font-size:12px;color:var(--muted)"><?=h($r['description']??'—')?></td>
        <td style="text-align:center"><span style="background:#f1f5f9;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700"><?=$r['user_count']?></span></td>
        <td style="text-align:center;white-space:nowrap" class="no-print">
          <button onclick='openEditModal(<?=json_encode($r,JSON_HEX_APOS|JSON_HEX_QUOT)?>)' class="btn btn-blue btn-sm"><i class="fa-solid fa-pen"></i></button>
          <?php if($r['id']>6): ?>
          <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete"><input type="hidden" name="role_id" value="<?=$r['id']?>">
            <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card" style="background:#e0f2fe;border-color:#bae6fd;height:fit-content">
    <div class="card-header"><span class="card-title" style="color:#0369a1"><i class="fa-solid fa-plus"></i> Nuovo ruolo</span></div>
    <form method="POST">
            <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <div class="form-group"><label>Nome ruolo *</label><input type="text" name="role_name" required placeholder="Es. Tecnico Esterno"></div>
      <div class="form-group"><label>Descrizione</label><textarea name="description" rows="2" placeholder="Breve descrizione..."></textarea></div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:11px"><i class="fa-solid fa-plus"></i> Crea ruolo</button>
    </form>
    <div style="margin-top:12px;font-size:11px;color:#0369a1"><i class="fa-solid fa-circle-info"></i> Dopo la creazione assegna le pagine da Gestione Permessi.</div>
  </div>
</div>
<div id="mEditRole" class="modal-overlay" style="z-index:1000">
  <div class="modal-box" style="width:400px">
    <h3 style="margin:0 0 18px;font-size:16px">Modifica ruolo</h3>
    <form method="POST">
            <?= csrf_field() ?>
      <input type="hidden" name="action" value="edit"><input type="hidden" name="role_id" id="er_id">
      <div class="form-group"><label>Nome *</label><input type="text" name="role_name" id="er_name" required></div>
      <div class="form-group"><label>Descrizione</label><textarea name="description" id="er_desc" rows="2"></textarea></div>
      <div style="display:flex;gap:10px;margin-top:6px">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:12px">Salva</button>
        <button type="button" onclick="document.getElementById('mEditRole').style.display='none'" class="btn" style="flex:1;justify-content:center;padding:12px">Annulla</button>
      </div>
    </form>
  </div>
</div>
<script>
$('#tRoles').DataTable({language:{search:"Cerca:"},pageLength:25,order:[[0,'asc']]});
function openEditModal(r){document.getElementById('er_id').value=r.id;document.getElementById('er_name').value=r.name;document.getElementById('er_desc').value=r.description||'';document.getElementById('mEditRole').style.display='flex';}
</script>
<?php require_once('footer.php'); ?>
