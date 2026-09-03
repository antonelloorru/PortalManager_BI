<?php
/**
 * ═══════════════════════════════════════════════════════════════
 *  certV 2.0 — reset_admin.php
 *  STRUMENTO DI RESET EMERGENZA CREDENZIALI AMMINISTRATORE
 * ═══════════════════════════════════════════════════════════════
 *
 *  ISTRUZIONI:
 *  1. Copia questo file nella cartella certV/ (stessa cartella di Config.php)
 *  2. Aprilo nel browser: http://localhost/certV/reset_admin.php
 *  3. Usa il form per impostare nuova email e/o password
 *  4. CANCELLA IMMEDIATAMENTE questo file dopo l'uso
 *
 *  SICUREZZA: questo file bypassa il login — cancellarlo subito dopo l'uso!
 * ═══════════════════════════════════════════════════════════════
 */

// ── Carica DB ─────────────────────────────────────────────────────────────────
$db_error = null;
$pdo = null;

// Cerca Config.php nella stessa cartella
if (file_exists(__DIR__ . '/Config.php')) {
    try {
        require_once __DIR__ . '/Config.php';
    } catch (Throwable $e) {
        $db_error = $e->getMessage();
    }
} else {
    // Fallback: connessione diretta con valori default XAMPP
    try {
        $pdo = new PDO(
            'mysql:host=localhost;dbname=cert_management;charset=utf8mb4',
            'root', '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (PDOException $e) {
        $db_error = $e->getMessage();
    }
}

// ── Azioni POST ───────────────────────────────────────────────────────────────
$msg     = '';
$msg_type= '';
$done    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo && !$db_error) {
    $action   = $_POST['action'] ?? '';
    $new_email= trim($_POST['new_email'] ?? '');
    $new_pwd  = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    $target_id= (int)($_POST['target_user_id'] ?? 0);

    // ── Reset password ─────────────────────────────────────────────
    if ($action === 'reset_password') {
        if (!$new_pwd) {
            $msg = '⚠ Inserisci la nuova password.';
            $msg_type = 'warning';
        } elseif (strlen($new_pwd) < 8) {
            $msg = '⚠ La password deve essere di almeno 8 caratteri.';
            $msg_type = 'warning';
        } elseif ($new_pwd !== $confirm) {
            $msg = '⚠ Le due password non coincidono.';
            $msg_type = 'warning';
        } else {
            $hash = password_hash($new_pwd, PASSWORD_BCRYPT, ['cost' => 12]);

            if ($target_id > 0) {
                $pdo->prepare("UPDATE users SET password_hash=?, status='active' WHERE id=?")
                    ->execute([$hash, $target_id]);
            } else {
                // Aggiorna tutti i Super Admin (role_id = 1)
                $pdo->prepare("UPDATE users SET password_hash=?, status='active' WHERE role_id=1")
                    ->execute([$hash]);
            }

            // Aggiorna anche la email se fornita
            if ($new_email) {
                $pdo->prepare("UPDATE users SET email=? WHERE " . ($target_id > 0 ? "id=$target_id" : "role_id=1"))
                    ->execute([$new_email]);
            }

            $rows = $pdo->query("SELECT id, email, status, role_id FROM users WHERE role_id=1")->fetchAll();
            $msg = "✅ Password aggiornata con successo! Hash bcrypt (cost 12) generato e salvato.<br>
                    <strong>Puoi accedere al portale con le nuove credenziali.</strong><br><br>
                    <span style='font-family:monospace;font-size:12px;background:#f1f5f9;padding:4px 8px;border-radius:4px'>
                    HASH: " . htmlspecialchars(substr($hash, 0, 30)) . "...</span>";
            $msg_type = 'success';
            $done = true;
        }
    }

    // ── Solo mostrare l'hash (senza modificare il DB) ──────────────
    if ($action === 'show_hash') {
        if (!$new_pwd) {
            $msg = '⚠ Inserisci la password per generare l\'hash.';
            $msg_type = 'warning';
        } else {
            $hash = password_hash($new_pwd, PASSWORD_BCRYPT, ['cost' => 12]);
            $sql_query = "UPDATE users SET password_hash='" . addslashes($hash) . "', status='active' WHERE role_id=1;";
            $msg = "✅ Hash generato (non ancora salvato nel DB).<br><br>
                    <strong>Copia questa query in phpMyAdmin → SQL:</strong><br>
                    <div style='background:#1e293b;color:#e2e8f0;padding:14px;border-radius:8px;font-family:monospace;font-size:12px;margin-top:10px;word-break:break-all'>"
                    . htmlspecialchars($sql_query) . "</div>";
            $msg_type = 'info';
        }
    }
}

// ── Lista utenti Admin ────────────────────────────────────────────────────────
$admin_users = [];
$all_users   = [];
if ($pdo && !$db_error) {
    try {
        $admin_users = $pdo->query(
            "SELECT u.id, u.email, u.status, u.role_id,
                    COALESCE(e.first_name,'') first_name,
                    COALESCE(e.last_name, u.email) last_name
             FROM users u LEFT JOIN employees e ON u.employee_id=e.id
             WHERE u.role_id<=2 ORDER BY u.role_id,u.id"
        )->fetchAll();
        $all_users = $pdo->query(
            "SELECT u.id, u.email, u.status, u.role_id,
                    COALESCE(e.first_name,'') first_name,
                    COALESCE(e.last_name, u.display_name, u.email) last_name
             FROM users u LEFT JOIN employees e ON u.employee_id=e.id
             ORDER BY u.role_id, e.last_name"
        )->fetchAll();
    } catch (PDOException $e) {
        $db_error = $e->getMessage();
    }
}

$bg_map   = ['success'=>'#d1fae5','warning'=>'#fef3c7','error'=>'#fee2e2','info'=>'#e0f2fe'];
$col_map  = ['success'=>'#065f46','warning'=>'#92400e','error'=>'#991b1b','info'=>'#0369a1'];
$brd_map  = ['success'=>'#10b981','warning'=>'#f59e0b','error'=>'#ef4444','info'=>'#3b82f6'];
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reset Emergenza — certV Admin</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', system-ui, sans-serif; background: #0f172a; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
.wrap { width: 100%; max-width: 700px; }

.header-box {
    background: #1e293b;
    border: 2px solid #ef4444;
    border-radius: 14px;
    padding: 22px 28px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
}
.header-box .icon { font-size: 36px; flex-shrink: 0; }
.header-box h1 { font-size: 20px; font-weight: 800; color: #f8fafc; margin-bottom: 4px; }
.header-box p  { font-size: 12px; color: #94a3b8; line-height: 1.5; }

.card { background: #1e293b; border-radius: 14px; border: 1px solid #334155; padding: 26px; margin-bottom: 18px; }
.card h2 { font-size: 14px; font-weight: 700; color: #e2e8f0; margin-bottom: 16px; text-transform: uppercase; letter-spacing: .8px; border-bottom: 1px solid #334155; padding-bottom: 10px; }

.fg { margin-bottom: 14px; }
.fg label { display: block; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 5px; }
.fg input, .fg select {
    width: 100%; padding: 11px 14px;
    background: #0f172a; color: #e2e8f0;
    border: 1.5px solid #334155; border-radius: 9px;
    font-size: 14px; font-family: inherit;
    transition: border-color .15s;
}
.fg input:focus, .fg select:focus { outline: none; border-color: #0ea5e9; }
.fg .hint { font-size: 11px; color: #475569; margin-top: 4px; }

.btn { display: inline-flex; align-items: center; gap: 6px; padding: 12px 22px; border-radius: 9px; font-size: 14px; font-weight: 700; cursor: pointer; border: none; font-family: inherit; transition: .15s; }
.btn-danger  { background: #ef4444; color: #fff; width: 100%; justify-content: center; font-size: 15px; padding: 14px; }
.btn-danger:hover  { background: #dc2626; }
.btn-info    { background: #1d4ed8; color: #fff; }
.btn-info:hover    { background: #1e40af; }

.msg { padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; border-left: 4px solid; font-size: 13px; line-height: 1.7; }

.users-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; }
.user-card { background: #0f172a; border: 1px solid #334155; border-radius: 9px; padding: 12px 14px; cursor: pointer; transition: border-color .15s; }
.user-card:hover { border-color: #0ea5e9; }
.user-card.selected { border-color: #0ea5e9; background: #1e3a5f; }
.user-card .name { font-size: 13px; font-weight: 700; color: #e2e8f0; }
.user-card .email { font-size: 11px; color: #64748b; margin-top: 2px; }
.user-card .badges { margin-top: 6px; display: flex; gap: 5px; flex-wrap: wrap; }
.badge { display: inline-block; padding: 2px 7px; border-radius: 10px; font-size: 9px; font-weight: 800; text-transform: uppercase; }
.badge-admin  { background: #fee2e2; color: #991b1b; }
.badge-hr     { background: #fef3c7; color: #92400e; }
.badge-active { background: #d1fae5; color: #065f46; }
.badge-inactive { background: #f1f5f9; color: #475569; }

.warning-box {
    background: #7f1d1d22;
    border: 1px solid #ef4444;
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 18px;
    font-size: 12px;
    color: #fca5a5;
    line-height: 1.6;
}
.db-error { background: #1e293b; border: 1px solid #ef4444; border-radius: 10px; padding: 20px; color: #fca5a5; font-family: monospace; font-size: 13px; }

.tabs { display: flex; gap: 4px; margin-bottom: 20px; background: #0f172a; padding: 4px; border-radius: 10px; border: 1px solid #334155; }
.tab { flex: 1; padding: 9px; text-align: center; border-radius: 8px; cursor: pointer; font-size: 12px; font-weight: 700; color: #64748b; transition: .15s; border: none; background: none; font-family: inherit; }
.tab.active { background: #1e293b; color: #0ea5e9; }

.hash-display { background: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: 12px 14px; font-family: 'Courier New', monospace; font-size: 11px; color: #94a3b8; word-break: break-all; margin-top: 8px; }
</style>
</head>
<body>
<div class="wrap">

  <!-- Header di emergenza -->
  <div class="header-box">
    <div class="icon">⚡</div>
    <div>
      <h1>Reset Emergenza — Credenziali Admin certV</h1>
      <p>Strumento di ripristino accesso. Esegui solo quando non riesci ad accedere al portale.<br>
         <strong style="color:#ef4444">🚨 CANCELLA questo file immediatamente dopo l'uso!</strong></p>
    </div>
  </div>

  <!-- Avviso sicurezza -->
  <div class="warning-box">
    ⚠ <strong>Avviso di sicurezza:</strong> Questo file bypassa completamente il sistema di autenticazione.
    Chiunque abbia accesso all'URL può resettare le credenziali admin.
    Eliminare il file dal server non appena completato il reset.
  </div>

  <?php if ($db_error): ?>
  <!-- Errore connessione DB -->
  <div class="db-error">
    <div style="font-weight:700;color:#ef4444;margin-bottom:8px">❌ Impossibile connettersi al database</div>
    <div><?=htmlspecialchars($db_error)?></div>
    <div style="margin-top:14px;color:#94a3b8;font-size:12px">
      Verifica:<br>
      • MySQL è avviato in XAMPP Control Panel?<br>
      • Config.php esiste nella stessa cartella di questo file?<br>
      • Le credenziali DB in Config.php sono corrette?
    </div>
  </div>

  <?php elseif ($done): ?>
  <!-- Completato -->
  <?php if ($msg): ?>
  <div class="msg" style="background:<?=$bg_map[$msg_type]?'#d1fae5':'#d1fae5'?>;color:#065f46;border-color:#10b981"><?=$msg?></div>
  <?php endif; ?>
  <div class="card">
    <h2>✅ Reset completato</h2>
    <p style="color:#94a3b8;font-size:14px;margin-bottom:20px">Ora puoi accedere al portale con le nuove credenziali.</p>
    <a href="login.php" style="display:inline-flex;align-items:center;gap:8px;background:#0ea5e9;color:#fff;padding:13px 24px;border-radius:9px;text-decoration:none;font-weight:700;font-size:15px">
      🔑 Vai alla pagina di login
    </a>
    <div style="margin-top:20px;padding:16px;background:#7f1d1d22;border:1px solid #ef4444;border-radius:9px;color:#fca5a5;font-size:13px">
      🚨 <strong>AZIONE OBBLIGATORIA:</strong> Cancella ora il file <code>reset_admin.php</code> dalla cartella certV/
    </div>
  </div>

  <?php else: ?>
  <!-- Form principale -->
  <?php if ($msg): ?>
  <div class="msg" style="background:<?=$bg_map[$msg_type]??'#e0f2fe'?>;color:<?=$col_map[$msg_type]??'#0369a1'?>;border-color:<?=$brd_map[$msg_type]??'#3b82f6'?>"><?=$msg?></div>
  <?php endif; ?>

  <div class="card">
    <h2>👤 Utenti con accesso admin</h2>
    <?php if (empty($admin_users)): ?>
    <p style="color:#64748b;font-size:13px">Nessun utente con ruolo 1 o 2 trovato nel database.</p>
    <?php else: ?>
    <div class="users-grid">
      <?php foreach($admin_users as $u): ?>
      <div class="user-card" onclick="selectUser(<?=$u['id']?>, '<?=htmlspecialchars($u['email'],ENT_QUOTES)?>')" id="uc_<?=$u['id']?>">
        <div class="name"><?=htmlspecialchars($u['first_name'].' '.$u['last_name'])?></div>
        <div class="email"><?=htmlspecialchars($u['email'])?></div>
        <div class="badges">
          <span class="badge <?=$u['role_id']==1?'badge-admin':'badge-hr'?>"><?=$u['role_id']==1?'Super Admin':'HR Director'?></span>
          <span class="badge <?=$u['status']=='active'?'badge-active':'badge-inactive'?>"><?=htmlspecialchars($u['status'])?></span>
          <span style="font-size:9px;color:#475569">ID: <?=$u['id']?></span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <p style="font-size:11px;color:#475569">Clicca su un utente per selezionarlo, oppure lascia "Tutti gli admin" per aggiornare tutti.</p>
    <?php endif; ?>
  </div>

  <!-- Tab: Reset / Hash -->
  <div class="card">
    <div class="tabs">
      <button class="tab active" onclick="showTab('reset',this)">🔑 Reset password (modifica DB)</button>
      <button class="tab" onclick="showTab('hash',this)">🔢 Genera solo l'hash SQL</button>
    </div>

    <!-- Tab Reset -->
    <div id="tab_reset">
      <form method="POST" onsubmit="return confirm('Confermi il reset delle credenziali?')">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="target_user_id" id="target_uid" value="0">

        <div class="fg">
          <label>Utente target</label>
          <div id="target_display" style="background:#0f172a;border:1px solid #334155;border-radius:9px;padding:11px 14px;color:#94a3b8;font-size:14px">
            Tutti gli admin (role_id = 1)
          </div>
        </div>

        <div class="fg">
          <label>Nuova email admin (opzionale)</label>
          <input type="email" name="new_email" placeholder="Lascia vuoto per non modificare l'email">
          <div class="hint">Se vuoi mantenere admin@certv.local lascia vuoto</div>
        </div>

        <div class="fg">
          <label>Nuova password *</label>
          <input type="password" name="new_password" id="pwd1" required placeholder="Minimo 8 caratteri" oninput="checkStrength(this.value)">
          <div id="strength_bar" style="height:4px;border-radius:2px;margin-top:6px;background:#334155;transition:.3s"></div>
          <div id="strength_txt" style="font-size:10px;color:#64748b;margin-top:3px"></div>
        </div>

        <div class="fg">
          <label>Conferma password *</label>
          <input type="password" name="confirm_password" id="pwd2" required placeholder="Ripeti la password" oninput="checkMatch()">
          <div id="match_txt" style="font-size:11px;margin-top:3px"></div>
        </div>

        <div style="background:#1e3a5f22;border:1px solid #1d4ed8;border-radius:9px;padding:12px 14px;margin-bottom:18px;font-size:12px;color:#93c5fd">
          🔒 La password verrà salvata come hash <strong>bcrypt (cost 12)</strong> — mai in chiaro nel database.
        </div>

        <button type="submit" class="btn btn-danger">
          ⚡ Esegui reset e aggiorna database
        </button>
      </form>
    </div>

    <!-- Tab Hash -->
    <div id="tab_hash" style="display:none">
      <p style="color:#94a3b8;font-size:13px;margin-bottom:16px">
        Genera l'hash senza modificare il DB. Copia la query SQL e incollala in phpMyAdmin.
      </p>
      <form method="POST">
        <input type="hidden" name="action" value="show_hash">
        <div class="fg">
          <label>Password da hashare</label>
          <input type="text" name="new_password" placeholder="Inserisci la password">
          <div class="hint">Verrà mostrata la query UPDATE da eseguire manualmente</div>
        </div>
        <button type="submit" class="btn btn-info">
          🔢 Genera hash e query SQL
        </button>
      </form>

      <?php if ($msg && $msg_type === 'info'): ?>
      <div class="msg" style="background:#e0f2fe;color:#0369a1;border-color:#3b82f6;margin-top:16px"><?=$msg?></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Lista tutti gli utenti (collassabile) -->
  <div class="card">
    <h2>📋 Tutti gli utenti nel sistema (<?=count($all_users)?>)</h2>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12px">
      <thead>
        <tr style="background:#0f172a">
          <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700">ID</th>
          <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700">Nome</th>
          <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700">Email</th>
          <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700">Ruolo</th>
          <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700">Stato</th>
          <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700">Azione</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($all_users as $u): ?>
      <tr style="border-top:1px solid #334155">
        <td style="padding:8px 12px;color:#475569"><?=$u['id']?></td>
        <td style="padding:8px 12px;color:#e2e8f0;font-weight:600"><?=htmlspecialchars($u['first_name'].' '.$u['last_name'])?></td>
        <td style="padding:8px 12px;color:#94a3b8;font-family:monospace;font-size:11px"><?=htmlspecialchars($u['email'])?></td>
        <td style="padding:8px 12px">
          <?php $rl=['1'=>'Super Admin','2'=>'HR Director','3'=>'Brand Mgr','4'=>'Team Leader','5'=>'Recruiter','6'=>'Dipendente']; ?>
          <span style="color:#94a3b8"><?=$rl[$u['role_id']]??'Ruolo '.$u['role_id']?></span>
        </td>
        <td style="padding:8px 12px">
          <span style="color:<?=$u['status']=='active'?'#10b981':'#64748b'?>;font-weight:700"><?=$u['status']?></span>
        </td>
        <td style="padding:8px 12px">
          <button onclick="selectUser(<?=$u['id']?>, '<?=htmlspecialchars($u['email'],ENT_QUOTES)?>')"
                  style="background:#1e3a5f;color:#0ea5e9;border:1px solid #1d4ed8;border-radius:6px;padding:4px 10px;font-size:11px;cursor:pointer;font-weight:700">
            Seleziona
          </button>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <?php endif; // fine !done ?>
</div><!-- wrap -->

<script>
let selectedUserId = 0;
let selectedEmail  = 'Tutti gli admin (role_id = 1)';

function selectUser(id, email) {
  // Deseleziona precedente
  document.querySelectorAll('.user-card').forEach(c => c.classList.remove('selected'));
  const el = document.getElementById('uc_'+id);
  if (selectedUserId === id) {
    // Deseleziona
    selectedUserId = 0;
    selectedEmail  = 'Tutti gli admin (role_id = 1)';
    document.getElementById('target_uid').value = 0;
    document.getElementById('target_display').textContent = 'Tutti gli admin (role_id = 1)';
  } else {
    selectedUserId = id;
    selectedEmail  = email;
    if (el) el.classList.add('selected');
    document.getElementById('target_uid').value = id;
    document.getElementById('target_display').textContent = email + ' (ID: ' + id + ')';
  }
}

function showTab(name, btn) {
  document.getElementById('tab_reset').style.display = name==='reset' ? 'block' : 'none';
  document.getElementById('tab_hash').style.display  = name==='hash'  ? 'block' : 'none';
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  btn.classList.add('active');
}

function checkStrength(pwd) {
  const bar = document.getElementById('strength_bar');
  const txt = document.getElementById('strength_txt');
  if (!pwd) { bar.style.width='0'; txt.textContent=''; return; }
  let score = 0;
  if (pwd.length >= 8)  score++;
  if (pwd.length >= 12) score++;
  if (/[A-Z]/.test(pwd)) score++;
  if (/[0-9]/.test(pwd)) score++;
  if (/[^a-zA-Z0-9]/.test(pwd)) score++;
  const levels = [
    {w:'20%',  c:'#ef4444', t:'Molto debole'},
    {w:'40%',  c:'#f97316', t:'Debole'},
    {w:'60%',  c:'#f59e0b', t:'Accettabile'},
    {w:'80%',  c:'#84cc16', t:'Buona'},
    {w:'100%', c:'#10b981', t:'Ottima'},
  ];
  const l = levels[Math.min(score-1,4)] || levels[0];
  bar.style.width = l.w; bar.style.background = l.c;
  txt.textContent = l.t; txt.style.color = l.c;
}

function checkMatch() {
  const p1 = document.getElementById('pwd1').value;
  const p2 = document.getElementById('pwd2').value;
  const mt = document.getElementById('match_txt');
  if (!p2) { mt.textContent=''; return; }
  if (p1===p2) { mt.textContent='✓ Le password coincidono'; mt.style.color='#10b981'; }
  else          { mt.textContent='✗ Le password non coincidono'; mt.style.color='#ef4444'; }
}
</script>
</body>
</html>
