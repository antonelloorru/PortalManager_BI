<?php
/**
 * certV — fix_password.php
 * Genera e applica il hash bcrypt corretto per admin@certv.local
 * CANCELLARE DOPO L'USO
 */
$pdo = null;
$msg = '';
if (file_exists(__DIR__.'/Config.php')) require_once __DIR__.'/Config.php';

if ($_SERVER['REQUEST_METHOD']==='POST' && $pdo) {
    $pwd  = trim($_POST['pwd'] ?? '');
    $email= trim($_POST['email'] ?? 'admin@certv.local');
    if (strlen($pwd) < 6) {
        $msg = '⚠ Password troppo corta (min 6 caratteri).';
    } else {
        $hash = password_hash($pwd, PASSWORD_BCRYPT, ['cost'=>12]);
        $pdo->prepare("UPDATE users SET password_hash=?, status='active' WHERE email=?")
            ->execute([$hash, $email]);
        $rows = $pdo->query("SELECT id,email,status,role_id FROM users WHERE email='".addslashes($email)."'")->fetch();
        $msg = "✅ Password aggiornata per <strong>$email</strong>.<br>"
             . "Hash: <code style='font-size:11px'>".htmlspecialchars(substr($hash,0,40))."...</code><br><br>"
             . "<strong>Ora vai su login.php e accedi.</strong><br>"
             . "<span style='color:#991b1b'>Poi CANCELLA questo file dal server.</span>";
    }
}

// Lista utenti
$users = [];
if ($pdo) {
    try { $users = $pdo->query("SELECT id,email,status,role_id FROM users ORDER BY role_id,id")->fetchAll(PDO::FETCH_ASSOC); }
    catch(Exception $e){}
}
?><!DOCTYPE html>
<html lang="it"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Fix Password — certV</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#0f172a;display:flex;justify-content:center;align-items:flex-start;min-height:100vh;padding:40px 20px}
.wrap{width:100%;max-width:520px}
.card{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:28px;margin-bottom:16px}
h1{font-size:20px;font-weight:800;color:#f8fafc;margin-bottom:4px}
.sub{font-size:12px;color:#64748b;margin-bottom:20px}
label{display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:5px}
input,select{width:100%;padding:11px 14px;background:#0f172a;color:#e2e8f0;border:1.5px solid #334155;border-radius:8px;font-size:14px;margin-bottom:14px;font-family:inherit}
.btn{width:100%;padding:13px;background:#0ea5e9;color:#fff;border:none;border-radius:9px;font-size:15px;font-weight:700;cursor:pointer}
.btn:hover{background:#0284c7}
.msg{padding:14px;border-radius:9px;margin-bottom:16px;font-size:13px;line-height:1.7;border-left:4px solid #0ea5e9;background:#0c1a2e;color:#93c5fd}
.warn{background:#7f1d1d22;border-color:#ef4444;color:#fca5a5;padding:12px;border-radius:8px;font-size:12px;border-left:4px solid #ef4444;margin-bottom:16px}
table{width:100%;border-collapse:collapse;font-size:12px}
th{padding:8px;text-align:left;color:#64748b;font-weight:700;border-bottom:1px solid #334155}
td{padding:8px;color:#e2e8f0;border-bottom:1px solid #1e293b}
</style></head><body>
<div class="wrap">
<div class="card">
  <h1>🔑 Fix Password — certV</h1>
  <p class="sub">Reimposta la password di un account direttamente nel database.</p>
  <div class="warn">🚨 Sicurezza: cancella questo file dal server non appena hai finito.</div>
  <?php if($msg): ?><div class="msg"><?=$msg?></div><?php endif; ?>
  <?php if($pdo): ?>
  <form method="POST">
    <label>Email account</label>
    <select name="email">
      <?php foreach($users as $u): ?>
      <option value="<?=htmlspecialchars($u['email'])?>" <?=$u['role_id']==1?'selected':''?>>
        <?=htmlspecialchars($u['email'])?> (ruolo <?=$u['role_id']?>, <?=$u['status']?>)
      </option>
      <?php endforeach; ?>
    </select>
    <label>Nuova password</label>
    <input type="text" name="pwd" placeholder="Inserisci la nuova password" autocomplete="off">
    <button type="submit" class="btn">Aggiorna password nel DB</button>
  </form>
  <?php else: ?>
  <div class="msg" style="border-color:#ef4444;color:#fca5a5">❌ Config.php non trovato o connessione DB fallita.</div>
  <?php endif; ?>
</div>
<?php if(!empty($users)): ?>
<div class="card">
  <h1 style="font-size:15px;margin-bottom:12px">Utenti nel sistema</h1>
  <table>
    <tr><th>ID</th><th>Email</th><th>Ruolo</th><th>Stato</th></tr>
    <?php foreach($users as $u): ?>
    <tr><td><?=$u['id']?></td><td><?=htmlspecialchars($u['email'])?></td><td><?=$u['role_id']?></td>
    <td style="color:<?=$u['status']=='active'?'#10b981':'#64748b'?>"><?=$u['status']?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
<p style="text-align:center;font-size:11px;color:#475569;margin-top:8px">
  <a href="login.php" style="color:#0ea5e9">→ Vai al login</a>
</p>
</div></body></html>
