<?php
/**
 * certV 4.2 — manage_users_2fa.php
 * Pannello Super Admin per gestire le autorizzazioni 2FA dei singoli utenti.
 *
 * Funzionalità:
 *   - Lista di tutti gli utenti del portale
 *   - Per ciascuno: switch TOTP autorizzato, switch Email OTP autorizzato
 *   - Stato corrente: configurato dall'utente sì/no, recovery codes count
 *   - Reset 2FA completo (per utenti bloccati fuori)
 *   - Filtro: tutti / abilitati / non abilitati
 */
require_once __DIR__ . '/access_control.php';
require_once __DIR__ . '/app/TwoFactor.php';
require_once __DIR__ . '/app/Totp.php';
require_once __DIR__ . '/app/EmailOtp.php';
require_once __DIR__ . '/app/RecoveryCodes.php';

// Solo Super Admin
if ((int)($_SESSION['role_id'] ?? 99) !== 1) {
    http_response_code(403);
    redirect('unauthorized');
}

$admin_id = (int)$_SESSION['user_id'];
$flash_success = '';
$flash_error = '';

// ── Azioni admin ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = (string)($_POST['action'] ?? '');
    $user_id = (int)($_POST['user_id'] ?? 0);

    if ($user_id <= 0) {
        $flash_error = 'Utente non valido.';
    } else {
        try {
            switch ($action) {
                case 'authorize_totp':
                    TwoFactor::adminAuthorizeTotp($pdo, $user_id, $admin_id);
                    $flash_success = "TOTP autorizzato. L'utente ora può configurarlo dal proprio pannello Sicurezza account.";
                    break;

                case 'revoke_totp':
                    TwoFactor::adminRevokeTotp($pdo, $user_id, $admin_id);
                    $flash_success = 'TOTP revocato e secret cancellato.';
                    break;

                case 'authorize_email':
                    TwoFactor::adminAuthorizeEmail($pdo, $user_id, $admin_id);
                    $flash_success = 'Email OTP autorizzato e attivato.';
                    break;

                case 'revoke_email':
                    TwoFactor::adminRevokeEmail($pdo, $user_id, $admin_id);
                    $flash_success = 'Email OTP revocato.';
                    break;

                case 'full_reset':
                    TwoFactor::adminFullReset($pdo, $user_id, $admin_id);
                    $flash_success = 'Reset 2FA completo eseguito (autorizzazioni, secret e recovery codes cancellati).';
                    break;

                default:
                    $flash_error = 'Azione non riconosciuta.';
            }
        } catch (Throwable $e) {
            $flash_error = 'Errore: ' . $e->getMessage();
            error_log('[manage_users_2fa] ' . $e->getMessage());
        }
    }
}

// ── Filtro ────────────────────────────────────────────────────────
$filter = (string)($_GET['filter'] ?? 'all'); // all | enabled | disabled

// ── Carica lista utenti con stato 2FA ─────────────────────────────
$schema_v22 = false;
try {
    $pdo->query("SELECT employee_id FROM users LIMIT 0")->closeCursor();
    $schema_v22 = true;
} catch (Throwable $e) {}

if ($schema_v22) {
    $sql = "SELECT u.id, u.email, u.role_id, u.status,
                   COALESCE(u.display_name, CONCAT(e.first_name, ' ', e.last_name), u.email) AS display_name,
                   r.name AS role_name,
                   t.totp_authorized, t.totp_enabled, t.totp_secret,
                   t.email_otp_authorized, t.email_otp_enabled,
                   t.authorized_at, t.verified_at, t.last_used_at,
                   (SELECT COUNT(*) FROM user_2fa_recovery_codes
                      WHERE user_id = u.id AND used_at IS NULL) AS recovery_count
              FROM users u
              LEFT JOIN employees e ON e.id = u.employee_id
              LEFT JOIN roles r ON r.id = u.role_id
              LEFT JOIN user_2fa t ON t.user_id = u.id
             WHERE u.status = 'active'
             ORDER BY u.role_id, display_name";
} else {
    $sql = "SELECT u.id, u.email, u.role_id, u.status,
                   CONCAT(u.first_name, ' ', u.last_name) AS display_name,
                   r.name AS role_name,
                   t.totp_authorized, t.totp_enabled, t.totp_secret,
                   t.email_otp_authorized, t.email_otp_enabled,
                   t.authorized_at, t.verified_at, t.last_used_at,
                   (SELECT COUNT(*) FROM user_2fa_recovery_codes
                      WHERE user_id = u.id AND used_at IS NULL) AS recovery_count
              FROM users u
              LEFT JOIN roles r ON r.id = u.role_id
              LEFT JOIN user_2fa t ON t.user_id = u.id
             WHERE u.status = 'active'
             ORDER BY u.role_id, display_name";
}
$stmt = $pdo->query($sql);
$users = $stmt->fetchAll();

// Applica filtro in PHP
$filtered = array_filter($users, function ($u) use ($filter) {
    $hasAuth = (int)$u['totp_authorized'] || (int)$u['email_otp_authorized'];
    if ($filter === 'enabled')  return $hasAuth;
    if ($filter === 'disabled') return !$hasAuth;
    return true;
});

// Statistiche
$total = count($users);
$enabled = count(array_filter($users, fn($u) =>
    (int)$u['totp_authorized'] || (int)$u['email_otp_authorized']
));

require_once __DIR__ . '/header.php';
?>

<style>
.page-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.page-head h1{font-size:22px;font-weight:800}
.page-head .sub{color:var(--muted);font-size:13px;margin-top:3px}

.stat-row{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:18px}
.stat-row .stat-card{padding:14px}
.stat-row .stat-card .sl{font-size:10px}
.stat-row .stat-card .sv{font-size:22px}

.filter-tabs{display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap}
.filter-tabs a{padding:7px 14px;background:#f1f5f9;color:#475569;border-radius:8px;font-size:12px;font-weight:600;text-decoration:none;border:1px solid var(--border)}
.filter-tabs a.active{background:var(--p);color:#fff;border-color:var(--p)}

.user-table{background:#fff;border-radius:10px;border:1px solid var(--border);overflow:hidden}
.user-row{display:grid;grid-template-columns:2fr 1.2fr 90px 90px 100px auto;gap:14px;padding:14px 18px;align-items:center;border-bottom:1px solid #f1f5f9;font-size:13px}
.user-row:last-child{border-bottom:none}
.user-row:hover{background:#fafbfc}
.user-row.head{background:#f8fafc;font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
.user-name{font-weight:600;color:#1e293b}
.user-email{font-size:11px;color:var(--muted);margin-top:2px}
.role-pill{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;text-transform:uppercase;background:#e0f2fe;color:#0369a1}

.toggle-form{display:inline-flex;align-items:center;gap:6px}
.toggle-btn{padding:5px 10px;border-radius:6px;border:1px solid var(--border);background:#fff;cursor:pointer;font-size:11px;font-weight:600;font-family:inherit;display:inline-flex;align-items:center;gap:5px;color:#64748b;transition:.15s}
.toggle-btn:hover{background:#f8fafc}
.toggle-btn.on{background:#d1fae5;color:#065f46;border-color:#10b981}
.toggle-btn.on:hover{background:#a7f3d0}
.toggle-btn.off{background:#f1f5f9;color:#64748b}

.status-pill{padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;display:inline-block}
.status-pill.ok{background:#d1fae5;color:#065f46}
.status-pill.pending{background:#fef3c7;color:#92400e}
.status-pill.none{background:#f1f5f9;color:#64748b}

.recovery-badge{font-size:11px;color:var(--muted);font-family:Consolas,monospace}
.recovery-badge.few{color:#92400e;font-weight:700}

.actions-cell{display:flex;gap:6px;align-items:center;flex-wrap:wrap;justify-content:flex-end}
.btn-reset{padding:5px 10px;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit}
.btn-reset:hover{background:#fecaca}

.empty{text-align:center;padding:40px;color:var(--muted);font-size:13px}

@media (max-width:1024px){
  .user-row{grid-template-columns:1fr;gap:8px;padding:14px}
  .user-row.head{display:none}
  .stat-row{grid-template-columns:1fr 1fr 1fr}
}
@media (max-width:600px){
  .stat-row{grid-template-columns:1fr 1fr}
}
</style>

<div class="page-head">
  <div>
    <h1>🔐 Gestione 2FA Utenti</h1>
    <div class="sub">Autorizza singoli utenti a usare l'autenticazione a due fattori. Solo gli utenti autorizzati vedranno il pannello "Sicurezza account" nel proprio menu.</div>
  </div>
</div>

<?php if ($flash_success): ?>
  <div class="alert alert-success">✓ <?= h($flash_success) ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
  <div class="alert alert-danger">✗ <?= h($flash_error) ?></div>
<?php endif; ?>

<div class="stat-row">
  <div class="stat-card" style="border-left-color:#0ea5e9">
    <div class="sl">Utenti totali</div>
    <div class="sv" style="color:#0ea5e9"><?= $total ?></div>
  </div>
  <div class="stat-card" style="border-left-color:#10b981">
    <div class="sl">Con 2FA autorizzata</div>
    <div class="sv" style="color:#10b981"><?= $enabled ?></div>
  </div>
  <div class="stat-card" style="border-left-color:#94a3b8">
    <div class="sl">Senza 2FA</div>
    <div class="sv" style="color:#64748b"><?= $total - $enabled ?></div>
  </div>
</div>

<div class="filter-tabs">
  <a href="<?= qs_self_safe(['filter'=>'all']) ?>" class="<?= $filter === 'all' ? 'active' : '' ?>">Tutti (<?= $total ?>)</a>
  <a href="<?= qs_self_safe(['filter'=>'enabled']) ?>" class="<?= $filter === 'enabled' ? 'active' : '' ?>">Con 2FA (<?= $enabled ?>)</a>
  <a href="<?= qs_self_safe(['filter'=>'disabled']) ?>" class="<?= $filter === 'disabled' ? 'active' : '' ?>">Senza 2FA (<?= $total - $enabled ?>)</a>
</div>

<div class="user-table">
  <div class="user-row head">
    <div>Utente</div>
    <div>Ruolo</div>
    <div>TOTP</div>
    <div>Email OTP</div>
    <div>Recovery</div>
    <div style="text-align:right">Azioni</div>
  </div>

  <?php if (empty($filtered)): ?>
    <div class="empty">Nessun utente trovato per questo filtro.</div>
  <?php endif; ?>

  <?php foreach ($filtered as $u):
    $totp_auth   = (int)$u['totp_authorized'];
    $totp_done   = $totp_auth && !empty($u['totp_secret']) && (int)$u['totp_enabled'];
    $email_auth  = (int)$u['email_otp_authorized'];
    $email_done  = $email_auth && (int)$u['email_otp_enabled'];
    $rec         = (int)$u['recovery_count'];
    $hasAuth     = $totp_auth || $email_auth;
  ?>
    <div class="user-row">
      <div>
        <div class="user-name"><?= h($u['display_name']) ?></div>
        <div class="user-email"><?= h($u['email']) ?></div>
      </div>

      <div>
        <span class="role-pill"><?= h($u['role_name'] ?? 'Ruolo ' . $u['role_id']) ?></span>
      </div>

      <div>
        <form method="POST" class="toggle-form">
          <?= csrf_field() ?>
          <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
          <input type="hidden" name="action" value="<?= $totp_auth ? 'revoke_totp' : 'authorize_totp' ?>">
          <button type="submit" class="toggle-btn <?= $totp_auth ? 'on' : 'off' ?>"
                  title="<?= $totp_auth ? 'Clicca per revocare TOTP' : 'Clicca per autorizzare TOTP' ?>">
            <?= $totp_auth ? '✓ Sì' : '○ No' ?>
          </button>
        </form>
        <?php if ($totp_auth): ?>
          <div style="margin-top:3px">
            <?php if ($totp_done): ?>
              <span class="status-pill ok">configurato</span>
            <?php else: ?>
              <span class="status-pill pending">in attesa</span>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <div>
        <form method="POST" class="toggle-form">
          <?= csrf_field() ?>
          <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
          <input type="hidden" name="action" value="<?= $email_auth ? 'revoke_email' : 'authorize_email' ?>">
          <button type="submit" class="toggle-btn <?= $email_auth ? 'on' : 'off' ?>"
                  title="<?= $email_auth ? 'Clicca per revocare Email OTP' : 'Clicca per autorizzare Email OTP' ?>">
            <?= $email_auth ? '✓ Sì' : '○ No' ?>
          </button>
        </form>
      </div>

      <div>
        <?php if ($rec > 0): ?>
          <span class="recovery-badge <?= $rec < 4 ? 'few' : '' ?>"><?= $rec ?>/10</span>
        <?php else: ?>
          <span class="recovery-badge">—</span>
        <?php endif; ?>
      </div>

      <div class="actions-cell">
        <?php if ($hasAuth): ?>
          <form method="POST" onsubmit="return confirm('Reset 2FA per <?= h($u['display_name']) ?>?\n\nVerranno cancellati:\n- Tutte le autorizzazioni\n- Il secret TOTP\n- Tutti i recovery codes\n\nL\'utente potrà accedere con sola password fino a una nuova autorizzazione.');">
            <?= csrf_field() ?>
            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
            <input type="hidden" name="action" value="full_reset">
            <button type="submit" class="btn-reset" title="Reset completo 2FA per questo utente">🔄 Reset</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card" style="margin-top:24px;background:#f8fafc">
  <h3 style="font-size:14px;margin-bottom:10px;color:#1e293b;font-weight:700">📖 Come funziona</h3>
  <ul style="font-size:12.5px;color:var(--muted);line-height:1.7;margin-left:18px">
    <li><strong>TOTP (App authenticator):</strong> dopo l'autorizzazione, l'utente vede il pannello "Sicurezza account" nel suo menu e configura da solo l'app (Google/MS Authenticator) scansionando il QR. Stato "in attesa" significa che l'admin ha autorizzato ma l'utente non ha ancora completato il setup.</li>
    <li><strong>Email OTP:</strong> si attiva subito dopo l'autorizzazione admin. L'utente riceverà i codici via email al login. Richiede SMTP configurato.</li>
    <li><strong>Recovery codes:</strong> 10 codici one-time generati automaticamente quando l'utente attiva TOTP. L'utente può rigenerarli quando vuole dal proprio pannello.</li>
    <li><strong>Reset:</strong> usalo se l'utente ha perso accesso totale (telefono, email e recovery codes). Cancella tutto. L'utente potrà fare login con sola password fino a una nuova autorizzazione.</li>
  </ul>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
