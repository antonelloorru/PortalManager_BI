<?php
/**
 * certV — _nav_system.php
 * Mini-barra di navigazione per le pagine di sistema (db_upgrade, health_check,
 * schema_check_upgrade, system_update, install). Non dipende da header.php
 * né da altri moduli, così resta utilizzabile anche in stato d'emergenza
 * (Config rotto, schema misallineato, sessione persa).
 *
 * USO: subito dopo <body> nella pagina di sistema:
 *   <?php $page_title = 'DB Upgrade'; require __DIR__ . '/_nav_system.php'; ?>
 *
 * Il link "Esci" e' visibile solo se c'è una sessione attiva.
 */

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$is_logged = !empty($_SESSION['user_id']);
$user_name = $is_logged ? ($_SESSION['user_name'] ?? 'Utente') : '';
$page_title = $page_title ?? 'Sistema';
?>
<style>
.sysnav-bar{
  position:sticky;top:0;z-index:1000;
  background:#0f172a;color:#e2e8f0;
  display:flex;align-items:center;justify-content:space-between;
  padding:10px 20px;
  font-family:'Segoe UI',system-ui,sans-serif;
  box-shadow:0 2px 8px rgba(0,0,0,.15);
  margin-bottom:18px;
}
.sysnav-left{display:flex;align-items:center;gap:14px;font-size:13px}
.sysnav-left a{color:#94a3b8;text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:6px;transition:.15s}
.sysnav-left a:hover{background:#1e293b;color:#0ea5e9}
.sysnav-title{color:#e2e8f0;font-weight:700;font-size:14px;border-left:1px solid #334155;padding-left:14px;margin-left:4px}
.sysnav-title .badge{background:#0ea5e9;color:#fff;font-size:10px;padding:2px 8px;border-radius:10px;margin-left:8px;text-transform:uppercase;font-weight:700;letter-spacing:.5px}
.sysnav-right{display:flex;align-items:center;gap:12px;font-size:12px;color:#94a3b8}
.sysnav-right .uname{color:#cbd5e1}
.sysnav-right .logout{color:#fb7185;text-decoration:none;padding:5px 10px;border-radius:6px}
.sysnav-right .logout:hover{background:#1e293b}
@media (max-width:600px){
  .sysnav-bar{padding:8px 12px;flex-wrap:wrap;gap:8px}
  .sysnav-title{font-size:12px;padding-left:8px}
  .sysnav-title .badge{display:none}
}
</style>
<nav class="sysnav-bar no-print">
  <div class="sysnav-left">
    <a href="index.php" title="Torna alla Dashboard">&larr; Dashboard</a>
    <span class="sysnav-title">
      <?= htmlspecialchars($page_title, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>
      <span class="badge">Sistema</span>
    </span>
  </div>
  <div class="sysnav-right">
    <?php if ($is_logged): ?>
      <span class="uname"><?= htmlspecialchars($user_name, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></span>
      <a href="logout.php" class="logout">Esci</a>
    <?php else: ?>
      <a href="login.php" class="logout" style="color:#0ea5e9">Login</a>
    <?php endif; ?>
  </div>
</nav>
