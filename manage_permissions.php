<?php
/**
 * certV 4.0 — manage_permissions.php
 * Matrice RBAC per ruolo + override granulare per utente.
 * Azioni: view, create, edit, delete, export.
 */
require_once('access_control.php');
require_once('functions.php');

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
if ($u_role !== 1) { header("Location: unauthorized.php"); exit(); }

// Auto-migration
try { $pdo->query("SELECT id FROM user_permissions LIMIT 0")->closeCursor(); }
catch (\Exception $e) {
    $mf = __DIR__ . '/migration_user_permissions.sql';
    if (file_exists($mf)) { foreach (explode(";", file_get_contents($mf)) as $s) { $s=trim($s); if(!$s||strpos($s,'--')===0)continue; try{$pdo->exec($s);}catch(\Exception $x){} } }
}
foreach (['can_view'=>"ALTER TABLE role_permissions ADD COLUMN can_view TINYINT(1) NOT NULL DEFAULT 1",
          'can_create'=>"ALTER TABLE role_permissions ADD COLUMN can_create TINYINT(1) NOT NULL DEFAULT 1",
          'can_edit'=>"ALTER TABLE role_permissions ADD COLUMN can_edit TINYINT(1) NOT NULL DEFAULT 1",
          'can_delete'=>"ALTER TABLE role_permissions ADD COLUMN can_delete TINYINT(1) NOT NULL DEFAULT 0",
          'can_export'=>"ALTER TABLE role_permissions ADD COLUMN can_export TINYINT(1) NOT NULL DEFAULT 1"] as $c=>$sq) {
    try { $pdo->query("SELECT `$c` FROM role_permissions LIMIT 0")->closeCursor(); }
    catch (\Exception $e) { try { $pdo->exec($sq); } catch (\Exception $x) {} }
}

// CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'save_role_perms') {
        $tid = (int)$_POST['target_role_id'];
        if ($tid === 1) { $_SESSION['flash_msg']="<div class='alert alert-warning'>Permessi Super Admin non modificabili.</div>"; header("Location: manage_permissions.php?role=$tid" . (!empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '')); exit(); }
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM role_permissions WHERE role_id=?")->execute([$tid]);
            $pages = $_POST['pages'] ?? [];
            foreach ($pages as $pg => $actions) {
                $pdo->prepare("INSERT INTO role_permissions (role_id,page_name,can_view,can_create,can_edit,can_delete,can_export) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$tid,$pg,(int)($actions['view']??0),(int)($actions['create']??0),(int)($actions['edit']??0),(int)($actions['delete']??0),(int)($actions['export']??0)]);
            }
            $pdo->commit();
            write_log('Permissions','success',"Permessi ruolo #$tid aggiornati",$u_id);
            $_SESSION['flash_msg']="<div class='alert alert-success'><i class='fa-solid fa-check'></i> Permessi ruolo aggiornati.</div>";
        } catch (Exception $e) { if($pdo->inTransaction())$pdo->rollBack(); $_SESSION['flash_msg']="<div class='alert alert-danger'>".h($e->getMessage())."</div>"; }
        header("Location: manage_permissions.php?role=$tid" . (!empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '')); exit();
    }

    if ($act === 'save_user_perms') {
        $uid_target = (int)$_POST['target_user_id'];
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM user_permissions WHERE user_id=?")->execute([$uid_target]);
            $pages = $_POST['upages'] ?? [];

            // v1.7.30: helper "" / non-set / "null" → NULL (eredita dal ruolo)
            //          "0"/"1" → override esplicito (deny/allow)
            $to_null_or_int = function($val) {
                if ($val === null || $val === '' || $val === 'null') return null;
                return (int)$val;
            };

            foreach ($pages as $pg => $actions) {
                $v = $to_null_or_int($actions['view']   ?? null);
                $c = $to_null_or_int($actions['create'] ?? null);
                $e = $to_null_or_int($actions['edit']   ?? null);
                $d = $to_null_or_int($actions['delete'] ?? null);
                $x = $to_null_or_int($actions['export'] ?? null);
                // Se tutti NULL = nessun override per questa pagina
                if ($v===null && $c===null && $e===null && $d===null && $x===null) continue;
                $pdo->prepare("INSERT INTO user_permissions (user_id,page_name,can_view,can_create,can_edit,can_delete,can_export,updated_by) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$uid_target,$pg,$v,$c,$e,$d,$x,$u_id]);
            }
            $pdo->commit();
            write_log('Permissions','success',"Override utente #$uid_target aggiornati",$u_id);
            $_SESSION['flash_msg']="<div class='alert alert-success'><i class='fa-solid fa-check'></i> Override utente salvati.</div>";
        } catch (Exception $e) { if($pdo->inTransaction())$pdo->rollBack(); $_SESSION['flash_msg']="<div class='alert alert-danger'>".h($e->getMessage())."</div>"; }
        header("Location: manage_permissions.php?tab=users&uid=$uid_target" . (!empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '')); exit();
    }

    if ($act === 'reset_user_perms') {
        $uid_target = (int)$_POST['target_user_id'];
        $pdo->prepare("DELETE FROM user_permissions WHERE user_id=?")->execute([$uid_target]);
        write_log('Permissions','info',"Override utente #$uid_target rimossi",$u_id);
        $_SESSION['flash_msg']="<div class='alert alert-success'>Override rimossi — l'utente usa i permessi del ruolo.</div>";
        header("Location: manage_permissions.php?tab=users&uid=$uid_target" . (!empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '')); exit();
    }
}

require_once('header.php');
$msg=''; if(!empty($_SESSION['flash_msg'])){$msg=$_SESSION['flash_msg'];unset($_SESSION['flash_msg']);}

$roles = $pdo->query("SELECT * FROM roles ORDER BY id")->fetchAll();
$users_all = $pdo->query("SELECT u.id, u.email, u.display_name, u.role_id, r.name role_name, e.first_name, e.last_name FROM users u LEFT JOIN roles r ON u.role_id=r.id LEFT JOIN employees e ON u.employee_id=e.id WHERE u.status='active' ORDER BY u.email")->fetchAll();

$tab = $_GET['tab'] ?? 'roles';
$target_role = (int)($_GET['role'] ?? 2);
$target_uid  = (int)($_GET['uid'] ?? 0);

// v1.9.83 — catalogo in app/PermissionCatalog.php (prima: array inline da aggiornare a ogni pagina nuova)
require_once __DIR__ . '/app/PermissionCatalog.php';
$page_map = PermissionCatalog::sections();
$all_pages = [];
foreach ($page_map as $sec => $pgs) foreach ($pgs as $f => $info) $all_pages[$f] = $info;

// Carica permessi correnti per il target
$current_role_perms = [];
if ($tab === 'roles') {
    try {
        $rp=$pdo->prepare("SELECT page_name,can_view,can_create,can_edit,can_delete,can_export FROM role_permissions WHERE role_id=?");
        $rp->execute([$target_role]);
        foreach ($rp->fetchAll() as $r) $current_role_perms[$r['page_name']]=$r;
    } catch (\Exception $e) {
        try { $rp=$pdo->prepare("SELECT page_name FROM role_permissions WHERE role_id=?"); $rp->execute([$target_role]);
            foreach ($rp->fetchAll(PDO::FETCH_COLUMN) as $p) $current_role_perms[$p]=['can_view'=>1,'can_create'=>1,'can_edit'=>1,'can_delete'=>0,'can_export'=>1];
        } catch (\Exception $e2) {}
    }
}

$current_user_perms = [];
$user_role_perms = [];
$target_user_info = null;
if ($tab === 'users' && $target_uid) {
    $target_user_info = $pdo->prepare("SELECT u.*, r.name role_name, e.first_name, e.last_name FROM users u LEFT JOIN roles r ON u.role_id=r.id LEFT JOIN employees e ON u.employee_id=e.id WHERE u.id=?");
    $target_user_info->execute([$target_uid]); $target_user_info = $target_user_info->fetch();
    if ($target_user_info) {
        $current_user_perms = load_effective_permissions($target_uid);
        // Carica solo override espliciti
        try {
            $up=$pdo->prepare("SELECT page_name,can_view,can_create,can_edit,can_delete,can_export FROM user_permissions WHERE user_id=?");
            $up->execute([$target_uid]);
            foreach ($up->fetchAll() as $u) $user_role_perms[$u['page_name']]=$u;
        } catch (\Exception $e) {}
    }
}

// v1.9.82/83 — NESSUN PERMESSO INVISIBILE: oltre alle pagine curate, tutte quelle scoperte dal
// catalogo (menu, router, permessi a DB) raggruppate per sezione, piu' eventuali righe residue del target.
$pm_known = [];
foreach ($page_map as $pgs) foreach ($pgs as $f => $i) $pm_known[$f] = true;
foreach (PermissionCatalog::discover($pdo) as $f => $c) {
    if (isset($pm_known[$f])) continue;
    $page_map[$c['section'] ?: 'Altre pagine'][$f] = [$c['label'], $c['description']];
    $pm_known[$f] = true;
}
foreach (array_merge(array_keys($current_role_perms), array_keys($user_role_perms)) as $f) {
    $f = (string)$f;
    if ($f === '' || isset($pm_known[$f]) || in_array($f, PermissionCatalog::SKIP, true)) continue;
    $page_map['Altre pagine'][$f] = [PermissionCatalog::humanize($f), 'Permesso registrato, pagina non nel catalogo'];
    $pm_known[$f] = true;
}
// pagine comparse dopo la prima sincronizzazione: evidenziate come NUOVE
$pm_new = [];
try {
    $bl = (string)($pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'rbac_baseline_at'")->fetchColumn() ?: '');
    if ($bl !== '') {
        $st_n = $pdo->prepare("SELECT name FROM permissions WHERE is_page = 1 AND first_seen_at > ?");
        $st_n->execute([$bl]);
        $pm_new = array_flip($st_n->fetchAll(PDO::FETCH_COLUMN));
    }
} catch (Throwable $e) {}
$all_pages = [];
foreach ($page_map as $sec => $pgs) foreach ($pgs as $f => $info) $all_pages[$f] = $info;

$actions_labels = ['view'=>['👁','Visualizza','#3b82f6'],'create'=>['+','Crea','#059669'],'edit'=>['✎','Modifica','#f59e0b'],'delete'=>['🗑','Elimina','#dc2626'],'export'=>['↗','Esporta','#8b5cf6']];
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
  <div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:3px"><i class="fa-solid fa-shield-halved" style="color:var(--p);margin-right:10px"></i>Permessi — certV 4.0</h1>
    <p style="color:var(--muted);font-size:13px">Matrice RBAC per ruolo + override granulare per singolo utente</p>
  </div>
  <div style="display:flex;gap:8px">
    <button type="button" id="perm-expand-all" class="btn btn-sm" style="font-size:12px"><i class="fa-solid fa-chevron-down"></i> Espandi tutte</button>
    <button type="button" id="perm-collapse-all" class="btn btn-sm" style="font-size:12px"><i class="fa-solid fa-chevron-right"></i> Comprimi tutte</button>
  </div>
</div>
<?=$msg?>

<!-- Tab -->
<div style="display:flex;gap:0;margin-bottom:22px;border-bottom:2px solid var(--border)">
  <a href="<?= qs_self_safe(['tab'=>'roles']) ?>" style="padding:10px 24px;font-weight:700;font-size:13px;text-decoration:none;border-bottom:2px solid <?=$tab==='roles'?'var(--p)':'transparent'?>;color:<?=$tab==='roles'?'var(--p)':'var(--muted)'?>;margin-bottom:-2px"><i class="fa-solid fa-users-gear"></i> Per ruolo</a>
  <a href="<?= qs_self_safe(['tab'=>'users']) ?>" style="padding:10px 24px;font-weight:700;font-size:13px;text-decoration:none;border-bottom:2px solid <?=$tab==='users'?'var(--p)':'transparent'?>;color:<?=$tab==='users'?'var(--p)':'var(--muted)'?>;margin-bottom:-2px"><i class="fa-solid fa-user-pen"></i> Per utente (override)</a>
</div>

<?php if($tab === 'roles'): ?>
<!-- ═══ TAB RUOLO ═══ -->
<div style="display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap">
  <?php foreach($roles as $r): if($r['id']==1) continue; ?>
  <a href="<?= qs_self_safe(['tab'=>'roles', 'role'=>''.($r['id']).'']) ?>" class="btn btn-sm <?=$target_role==(int)$r['id']?'btn-primary':''?>"><?=h($r['name'])?></a>
  <?php endforeach; ?>
</div>

<form method="POST">
            <?= csrf_field() ?>
<input type="hidden" name="action" value="save_role_perms">
<input type="hidden" name="target_role_id" value="<?=$target_role?>">
<div class="card" style="overflow-x:auto">
<?php require_once __DIR__ . '/app/ListFilter.php'; ListFilter::render('manage_permissions', '#lf-table-manage_permissions', ['export_filename' => 'manage_permissions', 'title' => 'Permessi ruoli']); ?>
<table id="lf-table-manage_permissions" class="data-table" style="font-size:12px">
<thead><tr>
  <th style="width:200px">Pagina</th><th>Descrizione</th>
  <?php foreach($actions_labels as $a=>[$ico,$lbl,$col]): ?>
  <th style="text-align:center;width:70px"><span style="color:<?=$col?>;font-weight:800"><?=$ico?></span><br><span style="font-size:9px"><?=$lbl?></span></th>
  <?php endforeach; ?>
</tr></thead>
<tbody>
<?php foreach($page_map as $sec => $pgs): $sk = 'r_'.substr(md5($sec),0,8);
  $sec_active = 0;
  foreach($pgs as $f2 => $i2) { $r2 = $current_role_perms[$f2] ?? null; if ($r2 && (int)($r2['can_view'] ?? 0)) $sec_active++; }
?>
<tr class="perm-sec" data-sec="<?=$sk?>" style="cursor:pointer" title="Clic per comprimere/espandere">
  <td colspan="7" style="background:#f0f9ff;font-weight:800;font-size:11px;color:#0369a1;text-transform:uppercase;padding:8px 12px;user-select:none">
    <i class="fa-solid fa-chevron-down perm-chevron" style="transition:transform .15s;margin-right:8px"></i><?=$sec?>
    <span style="float:right;text-transform:none;font-weight:600;color:var(--muted)"><?=$sec_active?>/<?=count($pgs)?> attive</span>
  </td>
</tr>
<?php foreach($pgs as $file => [$label,$desc]):
  $rp = $current_role_perms[$file] ?? null;
  $gate = MenuManager::HARD_GATES[substr($file, 0, -4)] ?? null;   // v1.9.82
?>
<tr class="perm-row" data-sec="<?=$sk?>">
  <td><strong><?=h($label)?></strong><?php if (isset($pm_new[$file])): ?> <span style="background:#dcfce7;color:#166534;font-size:9px;font-weight:800;padding:1px 5px;border-radius:4px">NUOVA</span><?php endif; ?><br><code style="font-size:9px;color:var(--muted)"><?=$file?></code>
    <?php if ($gate !== null && $target_role > $gate): ?><br><span style="font-size:9px;color:#b45309;font-weight:700" title="La pagina ammette nel codice solo i ruoli con id fino a <?=$gate?>: il permesso non basta e la voce non compare nel menu">⚠ limitata dal codice ai ruoli ≤ <?=$gate?></span><?php endif; ?></td>
  <td style="font-size:11px;color:var(--muted)"><?=h($desc)?></td>
  <?php foreach(['view','create','edit','delete','export'] as $a): ?>
  <td style="text-align:center">
    <input type="hidden" name="pages[<?=$file?>][<?=$a?>]" value="0">
    <input type="checkbox" name="pages[<?=$file?>][<?=$a?>]" value="1" <?=($rp && (int)($rp["can_$a"]??0))?'checked':''?> style="width:18px;height:18px;accent-color:<?=$actions_labels[$a][2]?>">
  </td>
  <?php endforeach; ?>
</tr>
<?php endforeach; endforeach; ?>
</tbody></table></div>
<div style="margin-top:14px;display:flex;gap:10px">
  <button type="submit" class="btn btn-primary" style="padding:12px 30px"><i class="fa-solid fa-floppy-disk"></i> Salva permessi ruolo</button>
</div>
</form>

<?php else: ?>
<!-- ═══ TAB UTENTE ═══ -->
<div style="display:flex;gap:14px;margin-bottom:18px;flex-wrap:wrap;align-items:flex-end">
  <div class="form-group" style="margin:0;min-width:250px">
    <label>Seleziona utente</label>
    <select onchange="if(this.value) window.location='<?= qs_self_safe(['tab'=>'users']) ?>&uid='+this.value">
      <option value="">— Scegli —</option>
      <?php foreach($users_all as $uu): ?>
      <option value="<?=$uu['id']?>" <?=$target_uid==(int)$uu['id']?'selected':''?>><?=h($uu['email'])?> (<?=h($uu['role_name']??'?')?>)<?=$uu['first_name']?' — '.h($uu['last_name'].' '.$uu['first_name']):''?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if($target_user_info): ?>
  <div style="background:#eff6ff;padding:8px 16px;border-radius:8px;font-size:12px;color:#1e40af">
    <strong><?=h($target_user_info['display_name'] ?: $target_user_info['email'])?></strong> — Ruolo base: <strong><?=h($target_user_info['role_name'])?></strong>
    <?php $ov_count = count($user_role_perms); if($ov_count): ?>
    <span style="margin-left:8px;padding:2px 8px;border-radius:10px;background:#fef3c7;color:#92400e;font-size:10px;font-weight:800"><?=$ov_count?> override attivi</span>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php if($target_user_info): ?>
<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:12px;color:#92400e">
  <i class="fa-solid fa-circle-info"></i> <strong>NULL</strong> = eredita dal ruolo · <strong style="color:#059669">✓</strong> = concesso esplicitamente · <strong style="color:#dc2626">✗</strong> = negato esplicitamente. Solo le celle modificate creano un override; le altre ereditano automaticamente.
</div>

<form method="POST">
            <?= csrf_field() ?>
<input type="hidden" name="action" value="save_user_perms">
<input type="hidden" name="target_user_id" value="<?=$target_uid?>">
<div class="card" style="overflow-x:auto">
<table class="data-table" style="font-size:12px">
<thead><tr>
  <th style="width:200px">Pagina</th>
  <?php foreach($actions_labels as $a=>[$ico,$lbl,$col]): ?>
  <th style="text-align:center;width:90px"><span style="color:<?=$col?>;font-weight:800"><?=$ico?></span> <?=$lbl?><br>
    <span style="font-size:9px;color:var(--muted)">Ruolo</span>
  </th>
  <?php endforeach; ?>
</tr></thead>
<tbody>
<?php foreach($page_map as $sec => $pgs): $sk = 'u_'.substr(md5($sec),0,8);
  $sec_ovr = 0;
  foreach($pgs as $f2 => $i2) { if (isset($user_role_perms[$f2])) $sec_ovr++; }
?>
<tr class="perm-sec" data-sec="<?=$sk?>" style="cursor:pointer" title="Clic per comprimere/espandere">
  <td colspan="6" style="background:#f0f9ff;font-weight:800;font-size:11px;color:#0369a1;text-transform:uppercase;padding:8px 12px;user-select:none">
    <i class="fa-solid fa-chevron-down perm-chevron" style="transition:transform .15s;margin-right:8px"></i><?=$sec?>
    <span style="float:right;text-transform:none;font-weight:600;color:<?=$sec_ovr?'#d97706':'var(--muted)'?>"><?=$sec_ovr?> override</span>
  </td>
</tr>
<?php foreach($pgs as $file => [$label,$desc]):
  $eff = $current_user_perms[$file] ?? ['view'=>0,'create'=>0,'edit'=>0,'delete'=>0,'export'=>0,'source'=>'none'];
  $ov  = $user_role_perms[$file] ?? null;
?>
<tr class="perm-row" data-sec="<?=$sk?>">
  <td><strong><?=h($label)?></strong><?php if (isset($pm_new[$file])): ?> <span style="background:#dcfce7;color:#166534;font-size:9px;font-weight:800;padding:1px 5px;border-radius:4px">NUOVA</span><?php endif; ?><br><code style="font-size:9px;color:var(--muted)"><?=$file?></code></td>
  <?php foreach(['view','create','edit','delete','export'] as $a):
    $role_val = $eff[$a] ?? 0;
    $user_val = $ov ? ($ov["can_$a"] ?? null) : null;
    $has_override = ($user_val !== null);
    $bg = $has_override ? ($user_val ? '#ecfdf5' : '#fee2e2') : '';
  ?>
  <td style="text-align:center;background:<?=$bg?>">
    <select name="upages[<?=$file?>][<?=$a?>]" style="font-size:11px;padding:2px;width:75px;border-color:<?=$has_override?'#f59e0b':'var(--border)'?>">
      <option value="" <?=!$has_override?'selected':''?>>— (<?=$role_val?'✓':'✗'?>)</option>
      <option value="1" <?=$has_override&&$user_val?'selected':''?>>✓ Sì</option>
      <option value="0" <?=$has_override&&!$user_val?'selected':''?>>✗ No</option>
    </select>
  </td>
  <?php endforeach; ?>
</tr>
<?php endforeach; endforeach; ?>
</tbody></table></div>
<div style="margin-top:14px;display:flex;gap:10px">
  <button type="submit" class="btn btn-primary" style="padding:12px 30px"><i class="fa-solid fa-floppy-disk"></i> Salva override utente</button>
  <form method="POST" style="display:inline" onsubmit="return confirm('Rimuovere tutti gli override? L\'utente tornerà ai permessi del ruolo.')">
            <?= csrf_field() ?>
    <input type="hidden" name="action" value="reset_user_perms"><input type="hidden" name="target_user_id" value="<?=$target_uid?>">
    <button type="submit" class="btn btn-danger" style="padding:12px 20px"><i class="fa-solid fa-rotate-left"></i> Reset a ruolo</button>
  </form>
</div>
</form>
<?php endif; ?>
<?php endif; ?>

<script>
/* v1.7.59 — Sezioni permessi comprimibili (stato persistito per sezione) */
(function () {
  var KEY = 'pm_perm_collapsed';
  function load() { try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; } }
  function save(a) { try { localStorage.setItem(KEY, JSON.stringify(a)); } catch (e) {} }

  function apply(sec, collapsed) {
    document.querySelectorAll('tr.perm-row[data-sec="' + sec + '"]').forEach(function (r) {
      r.style.display = collapsed ? 'none' : '';
    });
    var head = document.querySelector('tr.perm-sec[data-sec="' + sec + '"] .perm-chevron');
    if (head) head.style.transform = collapsed ? 'rotate(-90deg)' : '';
  }

  function toggle(sec) {
    var st = load(), i = st.indexOf(sec);
    if (i >= 0) { st.splice(i, 1); apply(sec, false); } else { st.push(sec); apply(sec, true); }
    save(st);
  }

  var state = load();
  document.querySelectorAll('tr.perm-sec').forEach(function (h) {
    var sec = h.dataset.sec;
    apply(sec, state.indexOf(sec) >= 0);
    h.addEventListener('click', function () { toggle(sec); });
  });

  function setAll(collapsed) {
    var secs = [];
    document.querySelectorAll('tr.perm-sec').forEach(function (h) { secs.push(h.dataset.sec); apply(h.dataset.sec, collapsed); });
    save(collapsed ? secs : []);
  }
  var be = document.getElementById('perm-expand-all');
  var bc = document.getElementById('perm-collapse-all');
  if (be) be.addEventListener('click', function (e) { e.preventDefault(); setAll(false); });
  if (bc) bc.addEventListener('click', function (e) { e.preventDefault(); setAll(true); });
})();
</script>

<?php require_once('footer.php'); ?>
