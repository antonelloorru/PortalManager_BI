<?php
/**
 * PortalManager — manage_departments.php
 *
 * Gestione lookup "Dipartimenti / Unità Organizzative" con storicizzazione.
 * Introdotta in v1.7.58 (refactoring del campo Dipartimento da testo libero).
 *
 * Ogni dipartimento ha:
 *   - name         (univoco)
 *   - value_type   ENUM('Servizio a Valore','Non a Valore')  — obbligatorio
 *   - is_active    (i dipartimenti disattivati non compaiono nella select HR)
 *
 * Ogni mutazione (CREATE/UPDATE/DELETE) è tracciata in department_history
 * (snapshot old→new + autore) oltre che nel log applicativo (write_log).
 *
 * Riservato a Super Admin (1); altri ruoli abilitabili via manage_permissions.
 * Pattern PRG: header.php incluso DOPO i POST handler.
 */
require_once('access_control.php');
require_once(__DIR__ . '/app/RecycleBin.php');
require_once('functions.php');

if (!can('view', 'manage_departments.php')) {
    $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Accesso negato.</div>";
    redirect('manage_employees');
}
$can_edit = can('edit', 'manage_departments.php') || can('create', 'manage_departments.php');
$u_id     = (int)$_SESSION['user_id'];

$VALUE_TYPES = ['Servizio a Valore', 'Non a Valore'];

// ── Auto-migration robusta (garantisce funzionamento anche pre-SQL Runner) ───
try { $pdo->query("SELECT id FROM departments LIMIT 0")->closeCursor(); }
catch (\Exception $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS departments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(150) NOT NULL,
            value_type ENUM('Servizio a Valore','Non a Valore') NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY uq_department_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (\Exception $ex) {}
}
try { $pdo->query("SELECT id FROM department_history LIMIT 0")->closeCursor(); }
catch (\Exception $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS department_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            department_id INT UNSIGNED NULL,
            action ENUM('CREATE','UPDATE','DELETE') NOT NULL,
            old_name VARCHAR(150) NULL, new_name VARCHAR(150) NULL,
            old_value_type ENUM('Servizio a Valore','Non a Valore') NULL,
            new_value_type ENUM('Servizio a Valore','Non a Valore') NULL,
            old_is_active TINYINT(1) NULL, new_is_active TINYINT(1) NULL,
            changed_by INT UNSIGNED NULL,
            changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY idx_dh_department (department_id), KEY idx_dh_changed_at (changed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (\Exception $ex) {}
}

// ── Helper storicizzazione ──────────────────────────────────────────────────
function dept_snapshot(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT id,name,value_type,is_active,parent_id FROM departments WHERE id=?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function dept_log_change(PDO $pdo, string $action, ?array $old, ?array $new, ?int $id, int $u_id): void {
    $st = $pdo->prepare("INSERT INTO department_history
        (department_id,action,old_name,new_name,old_value_type,new_value_type,old_is_active,new_is_active,changed_by)
        VALUES (?,?,?,?,?,?,?,?,?)");
    $st->execute([
        $id, $action,
        $old['name'] ?? null,       $new['name'] ?? null,
        $old['value_type'] ?? null, $new['value_type'] ?? null,
        isset($old['is_active']) ? (int)$old['is_active'] : null,
        isset($new['is_active']) ? (int)$new['is_active'] : null,
        $u_id ?: null,
    ]);
}

// ── POST handlers (PRG) — PRIMA di header.php ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $action = $_POST['action'] ?? '';

    if (!$can_edit) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>";
        redirect_self();
    }

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $vt   = $_POST['value_type'] ?? '';
        if ($name === '' || !in_array($vt, $VALUE_TYPES, true)) {
            $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Nome e tipologia obbligatori.</div>";
        } else {
            $pu = ($_POST['parent_id'] ?? '') !== '' ? (int)$_POST['parent_id'] : null;
            if ($pu !== null) { $c=$pdo->prepare("SELECT id FROM departments WHERE id=?"); $c->execute([$pu]); if($c->fetchColumn()===false) $pu=null; }
            $st = $pdo->prepare("INSERT IGNORE INTO departments (name,value_type,parent_id) VALUES (?,?,?)");
            $st->execute([$name, $vt, $pu]);
            if ($st->rowCount() > 0) {
                $id = (int)$pdo->lastInsertId();
                dept_log_change($pdo, 'CREATE', null, ['name'=>$name,'value_type'=>$vt,'is_active'=>1], $id, $u_id);
                write_log('Departments','success',"Creato dipartimento #$id ($name / $vt)",$u_id);
                $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Dipartimento creato.</div>";
            } else {
                $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Esiste già un dipartimento con questo nome.</div>";
            }
        }
        redirect_self();
    }

    if ($action === 'update') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $vt   = $_POST['value_type'] ?? '';
        $act  = isset($_POST['is_active']) ? 1 : 0;
        $old  = dept_snapshot($pdo, $id);
        if (!$old || $name === '' || !in_array($vt, $VALUE_TYPES, true)) {
            $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Dati non validi.</div>";
        } else {
            $pu = ($_POST['parent_id'] ?? '') !== '' ? (int)$_POST['parent_id'] : null;
            if ($pu === $id) $pu = null;
            if ($pu !== null) {
                // impedisci cicli: il nuovo padre non deve essere un discendente di $id
                $all = $pdo->query("SELECT id,parent_id FROM departments")->fetchAll(PDO::FETCH_KEY_PAIR);
                $desc = []; $stack = [$id];
                while ($stack) { $cur = array_pop($stack); foreach ($all as $cid=>$pid) { if ((int)$pid === (int)$cur && !isset($desc[$cid])) { $desc[$cid]=true; $stack[]=$cid; } } }
                if (isset($desc[$pu])) $pu = null;
                $ck=$pdo->prepare("SELECT id FROM departments WHERE id=?"); $ck->execute([$pu]); if($pu!==null && $ck->fetchColumn()===false) $pu=null;
            }
            $new = ['name'=>$name,'value_type'=>$vt,'is_active'=>$act,'parent_id'=>$pu];
            if ($old['name'] !== $name || $old['value_type'] !== $vt || (int)$old['is_active'] !== $act || (int)($old['parent_id'] ?? 0) !== (int)($pu ?? 0)) {
                try {
                    $pdo->prepare("UPDATE departments SET name=?,value_type=?,is_active=?,parent_id=? WHERE id=?")
                        ->execute([$name, $vt, $act, $pu, $id]);
                    dept_log_change($pdo, 'UPDATE', $old, $new, $id, $u_id);
                    write_log('Departments','success',"Modificato dipartimento #$id ($name / $vt / active=$act)",$u_id);
                    $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Dipartimento aggiornato.</div>";
                } catch (\Exception $e) {
                    $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Nome duplicato o errore: ".h($e->getMessage())."</div>";
                }
            } else {
                $_SESSION['flash_msg'] = "<div class='alert alert-info'>Nessuna modifica.</div>";
            }
        }
        redirect_self();
    }

    if ($action === 'delete') {
        $id  = (int)($_POST['id'] ?? 0);
        $old = dept_snapshot($pdo, $id);
        if ($old) {
            // scollega i dipendenti dalle sotto-categorie della categoria (poi CASCADE le elimina)
            $pdo->prepare("UPDATE employees e JOIN department_subcategories s ON s.id=e.subcategory_id SET e.subcategory_id=NULL WHERE s.department_id=?")->execute([$id]);
            // employees.department_id -> NULL via FK ON DELETE SET NULL
            RecycleBin::capture($pdo, 'departments', 'id=?', [$id], $u_id, 'manage_departments.php');
            dept_log_change($pdo, 'DELETE', $old, null, $id, $u_id);
            write_log('Departments','success',"Eliminato dipartimento #$id ({$old['name']})",$u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Dipartimento eliminato. I dipendenti collegati sono stati scollegati.</div>";
        }
        redirect_self();
    }

    // ── Sotto-categorie (relazione 1-a-molti: 1 categoria -> N sotto-categorie) ──
    if ($action === 'subcat_create') {
        $did = (int)($_POST['department_id'] ?? 0);
        $nm  = trim($_POST['subcat_name'] ?? '');
        $svt = $_POST['subcat_value_type'] ?? '';
        $svt = in_array($svt, $VALUE_TYPES, true) ? $svt : null; // NULL = eredita dalla categoria
        $okc = $pdo->prepare("SELECT id FROM departments WHERE id=?"); $okc->execute([$did]);
        if ($nm === '' || $okc->fetchColumn() === false) {
            $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Categoria e nome sotto-categoria obbligatori.</div>";
        } else {
            $st = $pdo->prepare("INSERT IGNORE INTO department_subcategories (department_id,name,value_type) VALUES (?,?,?)");
            $st->execute([$did, $nm, $svt]);
            $_SESSION['flash_msg'] = $st->rowCount() > 0
                ? "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Sotto-categoria creata.</div>"
                : "<div class='alert alert-danger'>Esiste gia una sotto-categoria con questo nome in questa categoria.</div>";
            if ($st->rowCount() > 0) write_log('Departments','success',"Creata sotto-categoria \"$nm\" (dip #$did)",$u_id);
        }
        redirect_self();
    }
    if ($action === 'subcat_update') {
        $sid = (int)($_POST['id'] ?? 0);
        $nm  = trim($_POST['subcat_name'] ?? '');
        $act = isset($_POST['is_active']) ? 1 : 0;
        $svt = $_POST['subcat_value_type'] ?? '';
        $svt = in_array($svt, $VALUE_TYPES, true) ? $svt : null; // NULL = eredita dalla categoria
        // La sotto-categoria resta legata alla SUA categoria (non spostabile): nessun cambio department_id.
        if ($sid <= 0 || $nm === '') {
            $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Dati sotto-categoria non validi.</div>";
        } else {
            try {
                $pdo->prepare("UPDATE department_subcategories SET name=?,value_type=?,is_active=? WHERE id=?")->execute([$nm,$svt,$act,$sid]);
                write_log('Departments','success',"Modificata sotto-categoria #$sid ($nm / active=$act)",$u_id);
                $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Sotto-categoria aggiornata.</div>";
            } catch (\Exception $e) {
                $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Nome duplicato nella categoria o errore.</div>";
            }
        }
        redirect_self();
    }
    if ($action === 'subcat_delete') {
        $sid = (int)($_POST['id'] ?? 0);
        if ($sid > 0) {
            $pdo->prepare("UPDATE employees SET subcategory_id=NULL WHERE subcategory_id=?")->execute([$sid]);
            $pdo->prepare("DELETE FROM department_subcategories WHERE id=?")->execute([$sid]);
            write_log('Departments','success',"Eliminata sotto-categoria #$sid",$u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Sotto-categoria eliminata. I dipendenti collegati sono stati scollegati.</div>";
        }
        redirect_self();
    }

    redirect_self();
}

// ── Dati vista ──────────────────────────────────────────────────────────────
$rows = $pdo->query(
    "SELECT d.*, p.name AS parent_name,
            (SELECT COUNT(*) FROM employees e WHERE e.department_id=d.id) AS used
     FROM departments d LEFT JOIN departments p ON p.id=d.parent_id
     ORDER BY d.name"
)->fetchAll(PDO::FETCH_ASSOC);
$all_units = $pdo->query("SELECT id,name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$cats = $pdo->query("SELECT id,name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$subcats = $pdo->query(
    "SELECT s.*, d.name AS cat_name, d.value_type AS cat_value_type,
            COALESCE(s.value_type, d.value_type) AS eff_value_type
     FROM department_subcategories s
     JOIN departments d ON d.id=s.department_id
     ORDER BY d.name, s.name"
)->fetchAll(PDO::FETCH_ASSOC);

$hist = $pdo->query(
    "SELECT h.*, d.name AS cur_name FROM department_history h
     LEFT JOIN departments d ON d.id=h.department_id
     ORDER BY h.changed_at DESC LIMIT 100"
)->fetchAll(PDO::FETCH_ASSOC);

$msg = '';
if (!empty($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }

require_once('header.php');

$fmt_bool = fn($v) => $v === null || $v === '' ? '—' : ((int)$v ? 'Sì' : 'No');
?>
<div class="page-header">
  <h1 style="display:flex;align-items:center;gap:12px;flex-wrap:wrap"><i class="fa-solid fa-sitemap"></i> Dipartimenti / Unità Organizzative
    <a class="btn btn-sm btn-primary" style="margin-left:auto" href="<?=url_safe('organigramma')?>"><i class="fa-solid fa-diagram-project"></i> Organigramma</a>
  </h1>
</div>

<?= $msg ?>

<?php if ($can_edit): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-plus"></i> Nuovo dipartimento</span></div>
  <form method="post" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="form-group" style="margin:0;flex:1;min-width:220px">
      <label>Nome</label>
      <input type="text" name="name" maxlength="150" placeholder="Es. Cloud Practice" required>
    </div>
    <div class="form-group" style="margin:0;min-width:200px">
      <label>Tipologia</label>
      <select name="value_type" required>
        <option value="">— Seleziona —</option>
        <?php foreach ($VALUE_TYPES as $vt): ?>
          <option value="<?=h($vt)?>"><?=h($vt)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;min-width:220px">
      <label>Unità superiore <small style="color:var(--muted)">(gerarchia)</small></label>
      <select name="parent_id">
        <option value="">— Nessuna (vertice) —</option>
        <?php foreach ($all_units as $u): ?><option value="<?=(int)$u['id']?>"><?=h($u['name'])?></option><?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Aggiungi</button>
  </form>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-list"></i> Elenco (<?=count($rows)?>)</span></div>
  <table class="data-table" style="width:100%">
    <thead>
      <tr><th>Nome</th><th>Unità superiore</th><th>Tipologia</th><th>Attivo</th><th>Dipendenti</th><?php if($can_edit):?><th style="width:220px">Azioni</th><?php endif;?></tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="<?=$can_edit?6:5?>" style="text-align:center;color:var(--muted);padding:24px">Nessun dipartimento censito.</td></tr>
    <?php else: foreach ($rows as $r): ?>
      <?php if ($can_edit): ?>
      <tr>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="<?=(int)$r['id']?>">
          <td><input type="text" name="name" value="<?=h($r['name'])?>" maxlength="150" required style="width:100%"></td>
          <td>
            <select name="parent_id">
              <option value="">— Vertice —</option>
              <?php foreach ($all_units as $u): if((int)$u['id']===(int)$r['id']) continue; ?>
                <option value="<?=(int)$u['id']?>" <?=(int)$r['parent_id']===(int)$u['id']?'selected':''?>><?=h($u['name'])?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td>
            <select name="value_type" required>
              <?php foreach ($VALUE_TYPES as $vt): ?>
                <option value="<?=h($vt)?>" <?=$r['value_type']===$vt?'selected':''?>><?=h($vt)?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td style="text-align:center"><input type="checkbox" name="is_active" value="1" <?=$r['is_active']?'checked':''?>></td>
          <td style="text-align:center"><?=(int)$r['used']?></td>
          <td>
            <button type="submit" class="btn btn-sm btn-success"><i class="fa-solid fa-floppy-disk"></i> Salva</button>
        </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Eliminare &quot;<?=h($r['name'])?>&quot;? I dipendenti collegati verranno scollegati.');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?=(int)$r['id']?>">
              <button type="submit" class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i></button>
            </form>
          </td>
      </tr>
      <?php else: ?>
      <tr>
        <td><?=h($r['name'])?></td>
        <td><?=h($r['parent_name'] ?? '—')?></td>
        <td><?=h($r['value_type'])?></td>
        <td style="text-align:center"><?=$r['is_active']?'Sì':'No'?></td>
        <td style="text-align:center"><?=(int)$r['used']?></td>
      </tr>
      <?php endif; ?>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php if ($can_edit): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-diagram-project"></i> Nuova sotto-categoria</span></div>
  <form method="post" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="subcat_create">
    <div class="form-group" style="margin:0;min-width:240px">
      <label>Categoria</label>
      <select name="department_id" required>
        <option value="">— Seleziona categoria —</option>
        <?php foreach ($cats as $c): ?><option value="<?=(int)$c['id']?>"><?=h($c['name'])?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;flex:1;min-width:220px">
      <label>Nome sotto-categoria</label>
      <input type="text" name="subcat_name" maxlength="150" placeholder="Es. K-lab Tecnico" required>
    </div>
    <div class="form-group" style="margin:0;min-width:200px">
      <label>Tipologia</label>
      <select name="subcat_value_type">
        <option value="">— Come categoria (default) —</option>
        <?php foreach ($VALUE_TYPES as $vt): ?><option value="<?=h($vt)?>"><?=h($vt)?></option><?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Aggiungi</button>
  </form>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-sitemap"></i> Sotto-categorie (<?=count($subcats)?>)</span></div>
  <table class="data-table" style="width:100%">
    <thead>
      <tr><th>Categoria</th><th>Sotto-categoria</th><th>Tipologia</th><th>Attiva</th><?php if($can_edit):?><th style="width:220px">Azioni</th><?php endif;?></tr>
    </thead>
    <tbody>
    <?php if (!$subcats): ?>
      <tr><td colspan="<?=$can_edit?5:4?>" style="text-align:center;color:var(--muted);padding:24px">Nessuna sotto-categoria. Ogni sotto-categoria appartiene a una sola categoria.</td></tr>
    <?php else: foreach ($subcats as $sc): ?>
      <?php if ($can_edit): ?>
      <tr>
        <td><?=h($sc['cat_name'])?></td>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="subcat_update">
          <input type="hidden" name="id" value="<?=(int)$sc['id']?>">
          <td><input type="text" name="subcat_name" value="<?=h($sc['name'])?>" maxlength="150" required style="width:100%"></td>
          <td>
            <select name="subcat_value_type">
              <option value="">Come categoria (<?=h($sc['cat_value_type'])?>)</option>
              <?php foreach ($VALUE_TYPES as $vt): ?><option value="<?=h($vt)?>" <?=($sc['value_type']===$vt)?'selected':''?>><?=h($vt)?></option><?php endforeach; ?>
            </select>
          </td>
          <td style="text-align:center"><input type="checkbox" name="is_active" value="1" <?=$sc['is_active']?'checked':''?>></td>
          <td>
            <button type="submit" class="btn btn-sm btn-success"><i class="fa-solid fa-floppy-disk"></i> Salva</button>
        </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Eliminare la sotto-categoria &quot;<?=h($sc['name'])?>&quot;? I dipendenti collegati verranno scollegati.');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="subcat_delete">
              <input type="hidden" name="id" value="<?=(int)$sc['id']?>">
              <button type="submit" class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i></button>
            </form>
          </td>
      </tr>
      <?php else: ?>
      <tr><td><?=h($sc['cat_name'])?></td><td><?=h($sc['name'])?></td><td><?=h($sc['eff_value_type'])?><?php if($sc['value_type']===null):?> <span style="color:var(--muted);font-size:11px">(eredita)</span><?php endif;?></td><td style="text-align:center"><?=$sc['is_active']?'Sì':'No'?></td></tr>
      <?php endif; ?>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Storico modifiche (ultime 100)</span></div>
  <table class="data-table" style="width:100%">
    <thead>
      <tr><th>Data</th><th>Azione</th><th>Dipartimento</th><th>Nome (old→new)</th><th>Tipologia (old→new)</th><th>Attivo (old→new)</th></tr>
    </thead>
    <tbody>
    <?php if (!$hist): ?>
      <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:24px">Nessuna modifica registrata.</td></tr>
    <?php else: foreach ($hist as $hh): ?>
      <tr>
        <td><?=h($hh['changed_at'])?></td>
        <td><?=h($hh['action'])?></td>
        <td><?=h($hh['cur_name'] ?? ('#'.$hh['department_id']))?></td>
        <td><?=h(($hh['old_name']??'—').' → '.($hh['new_name']??'—'))?></td>
        <td><?=h(($hh['old_value_type']??'—').' → '.($hh['new_value_type']??'—'))?></td>
        <td><?=h($fmt_bool($hh['old_is_active']).' → '.$fmt_bool($hh['new_is_active']))?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php require_once('footer.php'); ?>
