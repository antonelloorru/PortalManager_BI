<?php
/**
 * PortalManager v1.9.49 — rbac_debug.php
 * Diagnostica RBAC a runtime: mostra il ruolo in SESSIONE vs quello assegnato a DB
 * e i permessi effettivi (valore + sorgente) per una pagina.
 *
 * Serve a distinguere le tre cause di "Accesso negato" con ruoli corretti a DB:
 *   1. ruolo di sessione DIVERSO da quello a DB (stale) → risolto da Session::syncRole (v1.9.48)
 *   2. riga role_permissions con can_view=0 (deny esplicito per quel ruolo/pagina)
 *   3. NESSUNA riga per quel ruolo/pagina (deny-by-default: pagina nuova non ancora concessa)
 *
 * Accesso: Super Admin può ispezionare qualsiasi utente/pagina (?uid=&page=);
 * gli altri vedono solo la propria sessione.
 */

require_once('access_control.php');
$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
$isAdmin = ($u_role === 1);

require_once('header.php');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Target di ispezione
$targetUid = $isAdmin && !empty($_GET['uid']) ? (int)$_GET['uid'] : $u_id;
$page      = trim((string)($_GET['page'] ?? 'index.php'));
if (!str_ends_with($page, '.php')) $page .= '.php';

// Ruolo in sessione (solo per l'utente corrente) vs ruolo a DB
$sessRole = ($targetUid === $u_id) ? $u_role : null;
$dbRow = null;
try {
    $s = $pdo->prepare("SELECT u.id, u.role_id, u.status, r.name AS role_name
                        FROM users u LEFT JOIN roles r ON r.id=u.role_id WHERE u.id=?");
    $s->execute([$targetUid]); $dbRow = $s->fetch(PDO::FETCH_ASSOC); $s->closeCursor();
} catch (Throwable $e) {}

$dbRole = $dbRow ? (int)$dbRow['role_id'] : null;
$stale  = ($sessRole !== null && $dbRole !== null && $sessRole !== $dbRole);

// Permessi effettivi per la pagina (usa il ruolo a DB del target)
$eff = function_exists('effective_perms')
     ? effective_perms($page, $targetUid, $dbRole ?? 99)
     : [];

// Righe grezze role/user per la pagina
$rp = null; $up = null;
try {
    $q = $pdo->prepare("SELECT * FROM role_permissions WHERE role_id=? AND page_name=?");
    $q->execute([$dbRole, $page]); $rp = $q->fetch(PDO::FETCH_ASSOC); $q->closeCursor();
} catch (Throwable $e) {}
try {
    $q = $pdo->prepare("SELECT * FROM user_permissions WHERE user_id=? AND page_name=?");
    $q->execute([$targetUid, $page]); $up = $q->fetch(PDO::FETCH_ASSOC); $q->closeCursor();
} catch (Throwable $e) {}

// Causa probabile
$cause = 'OK — la pagina risulta consentita';
if (empty($eff['view']['value'])) {
    if ($stale)                 $cause = 'RUOLO DI SESSIONE STALE: la sessione usa il ruolo ' . $sessRole . ' ma a DB è ' . $dbRole . '. Applica v1.9.48 (Session::syncRole) o rifai login.';
    elseif ($up && $up['can_view'] !== null && (int)$up['can_view'] === 0)
                                $cause = 'DENY da OVERRIDE UTENTE (user_permissions.can_view=0): l\'override prevale sul ruolo.';
    elseif ($rp && (int)$rp['can_view'] === 0)
                                $cause = 'DENY ESPLICITO di ruolo (role_permissions.can_view=0 per ruolo ' . $dbRole . ').';
    elseif (!$rp)               $cause = 'NESSUNA riga role_permissions per ruolo ' . $dbRole . ' su questa pagina → deny-by-default. Concedere il permesso in Gestione permessi.';
    else                        $cause = 'Accesso negato.';
}
$badge = fn($b) => $b
  ? "<span style='background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:6px;font-weight:700'>ALLOW</span>"
  : "<span style='background:#fee2e2;color:#b91c1c;padding:2px 8px;border-radius:6px;font-weight:700'>DENY</span>";
?>
<div class="container" style="max-width:900px;margin:0 auto;padding:18px">
  <h1 style="font-size:20px;font-weight:800;margin-bottom:12px"><i class="fa-solid fa-user-shield"></i> Diagnostica RBAC</h1>

  <form method="get" class="card" style="padding:12px 16px;display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:14px">
    <?php if ($isAdmin): ?>
      <div><label style="font-size:11px;display:block">User ID</label>
        <input type="number" name="uid" value="<?=$h($targetUid)?>" style="width:100px"></div>
    <?php endif; ?>
    <div><label style="font-size:11px;display:block">Pagina</label>
      <input type="text" name="page" value="<?=$h($page)?>" style="width:240px" placeholder="es. recruiting_posizioni.php"></div>
    <button class="btn btn-primary btn-sm">Analizza</button>
  </form>

  <div class="card" style="padding:14px 16px;margin-bottom:14px">
    <table style="width:100%;font-size:13px;border-collapse:collapse">
      <tr><td style="padding:5px 0;color:#64748b">Utente</td><td><strong>#<?=$h($targetUid)?></strong></td></tr>
      <tr><td style="padding:5px 0;color:#64748b">Ruolo a DB</td>
          <td><strong><?=$h($dbRole ?? 'n/d')?></strong> <?=$dbRow ? '· '.$h($dbRow['role_name'] ?? '') : ''?>
              <?=$dbRow && ($dbRow['status'] ?? '')!=='active' ? " <span style='color:#b91c1c'>(status: ".$h($dbRow['status']).")</span>" : ''?></td></tr>
      <?php if ($sessRole !== null): ?>
      <tr><td style="padding:5px 0;color:#64748b">Ruolo in SESSIONE</td>
          <td><strong><?=$h($sessRole)?></strong>
              <?= $stale ? " <span style='background:#fef3c7;color:#b45309;padding:2px 8px;border-radius:6px;font-weight:700'>STALE ≠ DB</span>" : " <span style='color:#15803d'>allineato</span>" ?></td></tr>
      <?php endif; ?>
    </table>
  </div>

  <div class="card" style="padding:14px 16px;margin-bottom:14px">
    <div style="font-weight:700;margin-bottom:8px">Permessi effettivi su <code><?=$h($page)?></code> (ruolo <?=$h($dbRole ?? '?')?>)</div>
    <table style="width:100%;font-size:13px;border-collapse:collapse">
      <thead><tr style="text-align:left;color:#64748b"><th>Azione</th><th>Esito</th><th>Sorgente</th></tr></thead>
      <tbody>
      <?php foreach (['view','create','edit','delete','export'] as $a): $e = $eff[$a] ?? ['value'=>false,'source'=>'default']; ?>
        <tr style="border-top:1px solid var(--border)">
          <td style="padding:6px 0"><?=$a?></td>
          <td><?=$badge(!empty($e['value']))?></td>
          <td style="color:#64748b"><?=$h($e['source'] ?? '')?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card" style="padding:14px 16px;margin-bottom:14px">
    <div style="font-weight:700;margin-bottom:6px">Righe grezze</div>
    <div style="font-size:12px;color:#334155">
      role_permissions(ruolo <?=$h($dbRole ?? '?')?>, <?=$h($page)?>):
      <code><?= $rp ? $h(json_encode(['view'=>$rp['can_view'],'create'=>$rp['can_create'],'edit'=>$rp['can_edit'],'delete'=>$rp['can_delete'],'export'=>$rp['can_export']])) : 'NESSUNA RIGA' ?></code>
      <br>user_permissions(utente <?=$h($targetUid)?>, <?=$h($page)?>):
      <code><?= $up ? $h(json_encode(['view'=>$up['can_view'],'create'=>$up['can_create'],'edit'=>$up['can_edit'],'delete'=>$up['can_delete'],'export'=>$up['can_export']])) : 'nessun override' ?></code>
    </div>
  </div>

  <div class="card" style="padding:14px 16px;border-left:4px solid <?=empty($eff['view']['value'])?'#b91c1c':'#15803d'?>">
    <strong>Causa probabile:</strong> <?=$h($cause)?>
  </div>
</div>
<?php require_once('footer.php'); ?>
