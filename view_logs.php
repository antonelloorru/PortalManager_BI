<?php
/**
 * certV 2.0 v2.2 — view_logs.php
 * FIX: users.first_name/last_name rimossi in v2.2 → JOIN employees
 */
require_once('access_control.php');
require_once('header.php');
if ((int)($_SESSION['role_id'] ?? 99) !== 1) { header("Location: unauthorized.php"); exit(); }

$f_level    = $_GET['f_level']    ?? '';
$f_category = $_GET['f_category'] ?? '';
$f_user     = trim($_GET['f_user'] ?? '');
$limit      = min(500, max(25, (int)($_GET['limit'] ?? 100)));

$where  = ['1=1']; $params = [];
if ($f_level)    { $where[] = 'l.level=?';    $params[] = $f_level; }
if ($f_category) { $where[] = 'l.category=?'; $params[] = $f_category; }
if ($f_user)     {
    $where[] = '(e.first_name LIKE ? OR e.last_name LIKE ? OR u.email LIKE ?)';
    array_push($params, "%$f_user%", "%$f_user%", "%$f_user%");
}

$logs = $pdo->prepare(
    "SELECT l.*, e.first_name, e.last_name, u.email
     FROM app_logs l
     LEFT JOIN users u     ON l.user_id     = u.id
     LEFT JOIN employees e ON u.employee_id = e.id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY l.created_at DESC LIMIT $limit"
);
$logs->execute($params);
$all_logs   = $logs->fetchAll();
$categories = $pdo->query("SELECT DISTINCT category FROM app_logs ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$level_col  = ['info'=>'var(--info)','success'=>'var(--success)','warning'=>'var(--warning)','error'=>'var(--danger)'];
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
  <div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:3px"><i class="fa-solid fa-list-ul" style="color:var(--p);margin-right:10px"></i>Log di sistema</h1>
    <p style="color:var(--muted);font-size:13px"><?=count($all_logs)?> eventi · solo Super Admin</p>
  </div>
  <button onclick="window.print()" class="btn btn-sm no-print"><i class="fa-solid fa-print"></i></button>
</div>
<form method="GET" class="filter-bar">
<?php if (!empty($_GET["r"])): ?><input type="hidden" name="r" value="<?= htmlspecialchars($_GET["r"], ENT_QUOTES, "UTF-8") ?>"><?php endif; ?>
  <div class="fg"><label>Livello</label>
    <select name="f_level"><option value="">Tutti</option>
      <?php foreach(['info','success','warning','error'] as $lv): ?><option value="<?=$lv?>" <?=$f_level===$lv?'selected':''?>><?=ucfirst($lv)?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="fg"><label>Categoria</label>
    <select name="f_category"><option value="">Tutte</option>
      <?php foreach($categories as $cat): ?><option value="<?=h($cat)?>" <?=$f_category===$cat?'selected':''?>><?=h($cat)?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="fg"><label>Utente</label><input type="text" name="f_user" value="<?=h($f_user)?>" placeholder="Nome o email..." style="width:150px"></div>
  <div class="fg"><label>Ultimi N</label>
    <select name="limit">
      <?php foreach([50,100,250,500] as $n): ?><option value="<?=$n?>" <?=$limit==$n?'selected':''?>><?=$n?></option><?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn btn-primary">Filtra</button>
  <a href="view_logs.php" class="btn btn-sm">Reset</a>

  <div class="fg" style="margin-left:auto">
    <label style="visibility:hidden">.</label>
    <button type="button" onclick="window.print()" class="btn btn-sm" title="Stampa pagina"
            style="background:#fef3c7;color:#92400e;border-color:#fde68a">
      <i class="fa-solid fa-print"></i> Stampa
    </button>
  </div>
</form>
<div class="card" style="overflow-x:auto">
  <?php require_once __DIR__ . '/app/ListFilter.php'; ListFilter::render('view_logs', '#tLogs', ['export_filename' => 'view_logs', 'title' => 'Log applicazione']); ?>
<table class="data-table" id="tLogs">
    <thead><tr><th>Data/ora</th><th>Categoria</th><th>Livello</th><th>Messaggio</th><th>Utente</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach($all_logs as $l):
      $col  = $level_col[$l['level']] ?? 'var(--muted)';
      $nome = $l['first_name'] ? h($l['first_name'][0].'. '.$l['last_name']) : ($l['email'] ? h($l['email']) : 'Sistema');
    ?>
    <tr>
      <td style="white-space:nowrap;font-size:12px;color:var(--muted)"><?=date('d/m/Y H:i:s',strtotime($l['created_at']))?></td>
      <td><span class="badge badge-neutral" style="font-size:9px"><?=h($l['category'])?></span></td>
      <td><span style="font-weight:700;color:<?=$col?>;font-size:12px;text-transform:uppercase"><?=$l['level']?></span></td>
      <td style="font-size:13px"><?=h($l['message'])?><?php if($l['context']): ?><br><code style="font-size:10px;color:var(--muted)"><?=h(substr($l['context'],0,120))?></code><?php endif; ?></td>
      <td style="font-size:12px"><?=$nome?></td>
      <td style="font-size:11px;color:var(--muted)"><?=h($l['ip_address']??'—')?></td>
    </tr>
    <?php endforeach; ?>
    <?php if(empty($all_logs)): ?><tr><td colspan="6" style="text-align:center;padding:30px;color:var(--muted)">Nessun log trovato.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<script>$('#tLogs').DataTable({language:{search:"Cerca:"},pageLength:25,order:[[0,'desc']]});</script>
<?php require_once('footer.php'); ?>
