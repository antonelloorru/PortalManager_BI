<?php
/**
 * certV 4.0 — unauthorized.php
 * Pagina 403 mostrata quando RBAC nega l'accesso.
 */
require_once __DIR__ . '/app/bootstrap.php';

http_response_code(403);
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Accesso negato</title>
<style>
body{font-family:system-ui,sans-serif;background:#f1f5f9;display:flex;min-height:100vh;align-items:center;justify-content:center;color:#1e293b;margin:0}
.box{max-width:420px;background:#fff;padding:40px;border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,.08);text-align:center}
.icon{font-size:56px;margin-bottom:16px}
h1{font-size:22px;margin:0 0 8px}
p{color:#64748b;margin-bottom:24px}
.btn{display:inline-block;padding:10px 20px;background:#0ea5e9;color:#fff;text-decoration:none;border-radius:8px;font-weight:600}
</style>
</head>
<body>
<div class="box">
  <div class="icon">🔒</div>
  <h1>Accesso negato</h1>
  <p>Non hai i permessi per visualizzare questa pagina.<br>Contatta l'amministratore se ritieni sia un errore.</p>
  <a class="btn" href="<?= url_safe('index') ?>">← Torna alla dashboard</a>
</div>
</body>
</html>
