<?php
/**
 * certV 2.0 — notifications.php   Centro notifiche
 */
require_once('access_control.php');

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);

// Marca letta — DEVE essere prima di header.php (può fare redirect)
if (isset($_GET['read'])) {
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND (user_id=? OR role_id=?)")
        ->execute([(int)$_GET['read'], $u_id, $u_role]);
    redirect('notifications');
}
if (isset($_GET['read_all'])) {
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE (user_id=? OR role_id=?) AND is_read=0")
        ->execute([$u_id, $u_role]);
    redirect('notifications');
}

// v1.7.16: header.php DOPO i redirect handlers
require_once('header.php');

$notifs = $pdo->prepare(
    "SELECT * FROM notifications
     WHERE (user_id=? OR role_id=?) AND (expires_at IS NULL OR expires_at>=CURDATE())
     ORDER BY created_at DESC LIMIT 100"
);
$notifs->execute([$u_id, $u_role]);
$all = $notifs->fetchAll();

$type_icon = ['info'=>'fa-circle-info','warning'=>'fa-triangle-exclamation','critical'=>'fa-circle-xmark','success'=>'fa-circle-check'];
$type_color= ['info'=>'var(--info)','warning'=>'var(--warning)','critical'=>'var(--danger)','success'=>'var(--success)'];
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
  <h1 style="font-size:20px;font-weight:800"><i class="fa-solid fa-bell" style="color:var(--p);margin-right:10px"></i>Centro notifiche</h1>
  <a href="<?= qs_self_safe(['read_all'=>'1']) ?>" class="btn btn-sm">Segna tutte lette</a>
</div>

<div class="card">
<?php if(empty($all)): ?>
  <div style="text-align:center;padding:50px;color:var(--muted)">
    <i class="fa-solid fa-bell-slash" style="font-size:36px;margin-bottom:12px;display:block;opacity:.4"></i>
    Nessuna notifica
  </div>
<?php else: ?>
<?php foreach($all as $n):
  $ic=$type_icon[$n['type']]??'fa-circle-info';
  $cl=$type_color[$n['type']]??'var(--info)';
?>
<div style="display:flex;gap:14px;padding:14px 0;border-bottom:1px solid #f1f5f9;opacity:<?=$n['is_read']?.55:1?>">
  <div style="width:36px;height:36px;border-radius:50%;background:<?=$cl?>22;display:flex;align-items:center;justify-content:center;flex-shrink:0">
    <i class="fa-solid <?=$ic?>" style="color:<?=$cl?>;font-size:14px"></i>
  </div>
  <div style="flex:1;min-width:0">
    <div style="font-weight:<?=$n['is_read']?400:700?>;font-size:13px"><?=h($n['title'])?></div>
    <div style="font-size:12px;color:var(--muted);margin-top:2px"><?=h($n['message'])?></div>
    <div style="font-size:10px;color:var(--light);margin-top:4px"><?=format_date($n['created_at'],'d/m/Y H:i')?> · <?=h(ucfirst($n['module']))?></div>
  </div>
  <div style="display:flex;gap:6px;align-items:flex-start;flex-shrink:0">
    <?php if($n['link_url']): ?><a href="<?=h($n['link_url'])?>" class="btn btn-blue btn-sm"><i class="fa-solid fa-arrow-right"></i></a><?php endif; ?>
    <?php if(!$n['is_read']): ?><a href="<?= qs_self_safe(['read'=>''.($n['id']).'']) ?>" class="btn btn-sm" title="Segna letta"><i class="fa-solid fa-check"></i></a><?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>

<?php require_once('footer.php'); ?>
