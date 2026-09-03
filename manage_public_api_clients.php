<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.24 — Chiavi API pubbliche (Super Admin).
 * Il client_secret NON viene salvato in DB: viene mostrato UNA sola volta.
 * L'operatore deve inserirlo nel file config/api_secrets.php o in variabile d'ambiente.
 */
require __DIR__ . '/bootstrap.php';
require_login();
if (!can('manage_public_api_clients.php')) { http_response_code(403); exit('Accesso negato'); }
csrf_start();

$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    if ($act === 'create') {
        $clientId  = preg_replace('/[^a-z0-9_\-]/i', '_', trim((string)$_POST['client_id'])) ?: 'client_' . bin2hex(random_bytes(3));
        $label     = trim((string)$_POST['label']) ?: $clientId;
        $scopes    = trim((string)$_POST['scopes']) ?: 'positions:read,candidates:check,applications:write';
        $origins   = trim((string)$_POST['allowed_origins']);
        $ips       = trim((string)$_POST['allowed_ips']);
        $secret    = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare(
            "INSERT INTO public_api_clients
               (client_id, client_secret_hash, label, scopes, allowed_origins, allowed_ips, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)"
        );
        $stmt->execute([$clientId, hash('sha256', $secret), $label, $scopes, $origins, $ips]);
        $flash = ['type'=>'ok', 'msg'=>"Chiave creata: <b>$clientId</b><br>Secret (mostrato SOLO ora): <code>$secret</code><br>Salvalo in <code>config/api_secrets.php</code> come <code>'$clientId' => '$secret'</code>."];
    }
    if ($act === 'toggle') {
        $id = (int)$_POST['id'];
        $pdo->prepare("UPDATE public_api_clients SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
    }
    if ($act === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM public_api_clients WHERE id = ?")->execute([$id]);
    }
}
$clients = $pdo->query("SELECT * FROM public_api_clients ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
require __DIR__ . '/partials/header.php';
?>
<h1>Chiavi API — Portale Careers</h1>
<?php if ($flash): ?><div class="alert <?= h($flash['type']) ?>"><?= $flash['msg'] /* html trusted */ ?></div><?php endif; ?>

<h2>Nuova chiave</h2>
<form method="post" class="form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="create">
  <div class="grid">
    <label>Client ID <input name="client_id" placeholder="careers_portal_prod" required></label>
    <label>Etichetta <input name="label" placeholder="Portale Careers PROD"></label>
    <label class="col-2">Scopes <input name="scopes" value="positions:read,candidates:check,applications:write"></label>
    <label class="col-2">Origins consentite (CSV) <input name="allowed_origins" placeholder="https://careers.example.com"></label>
    <label class="col-2">IP/CIDR consentiti (CSV) <input name="allowed_ips" placeholder="10.0.0.0/24"></label>
  </div>
  <button>Genera chiave</button>
</form>

<h2>Chiavi attive</h2>
<table class="tbl">
  <thead><tr><th>ID</th><th>Client</th><th>Etichetta</th><th>Scopes</th><th>Origins</th><th>IP</th><th>Ultimo uso</th><th>Stato</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($clients as $c): ?>
    <tr>
      <td><?= (int)$c['id'] ?></td>
      <td><code><?= h($c['client_id']) ?></code></td>
      <td><?= h($c['label']) ?></td>
      <td><?= h($c['scopes']) ?></td>
      <td><?= h($c['allowed_origins']) ?></td>
      <td><?= h($c['allowed_ips']) ?></td>
      <td><?= h($c['last_used_at']) ?></td>
      <td><span class="badge <?= ((int)$c['is_active']===1)?'b-open':'b-closed' ?>"><?= ((int)$c['is_active']===1)?'attivo':'sospeso' ?></span></td>
      <td>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn-mini">Toggle</button></form>
        <form method="post" class="inline" onsubmit="return confirm('Eliminare?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn-mini danger">Elimina</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php require __DIR__ . '/partials/footer.php'; ?>
