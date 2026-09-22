<?php
/**
 * certV 4.2 — 2fa_settings.php (admin-controlled v2)
 * Pannello utente per la 2FA, modificato per il modello "admin authorize, user setup".
 *
 * NUOVO COMPORTAMENTO:
 *   - Se l'admin NON ha autorizzato nulla → messaggio "Contatta l'admin"
 *   - Se TOTP autorizzato ma non configurato → bottone "Configura QR"
 *   - Se TOTP configurato → solo informativo (non si può disattivare da qui)
 *   - Email OTP: stato in sola lettura (gestito dall'admin)
 *   - Recovery codes: l'utente PUÒ rigenerarli quando vuole
 */
require_once __DIR__ . '/access_control.php';
require_once __DIR__ . '/app/Totp.php';
require_once __DIR__ . '/app/EmailOtp.php';
require_once __DIR__ . '/app/RecoveryCodes.php';
require_once __DIR__ . '/app/TwoFactor.php';

$u_id = (int)$_SESSION['user_id'];

// Email utente
$us = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$us->execute([$u_id]);
$user_email = (string)$us->fetchColumn();

$state = TwoFactor::getUserState($pdo, $u_id);
$flash_success = '';
$flash_error   = '';
$show_codes    = null;
$setup_data    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        switch ($action) {
            // ── TOTP setup (solo se autorizzato dall'admin) ──────
            case 'totp_start':
                if (!$state['totp_authorized']) {
                    $flash_error = "TOTP non autorizzato. Contatta l'amministratore.";
                } else {
                    $setup_data = TwoFactor::startTotpSetup($pdo, $u_id, $user_email);
                }
                break;

            case 'totp_confirm':
                $code = trim((string)($_POST['totp_code'] ?? ''));
                if (TwoFactor::confirmTotpSetup($pdo, $u_id, $code)) {
                    $flash_success = 'TOTP configurato con successo.';
                    write_log('Auth', 'success', 'TOTP configurato dall\'utente', $u_id);
                    $state = TwoFactor::getUserState($pdo, $u_id);
                    if (RecoveryCodes::remaining($pdo, $u_id) === 0) {
                        $show_codes = RecoveryCodes::regenerate($pdo, $u_id);
                    }
                } else {
                    $flash_error = 'Codice non valido. Verifica che l\'orario del telefono sia sincronizzato e riprova.';
                    $setup = $_SESSION['_2fa_setup'] ?? null;
                    if ($setup) {
                        $setup_data = [
                            'secret'   => $setup['secret'],
                            'uri'      => Totp::provisioningUri($setup['secret'], $user_email),
                            'qr_url'   => Totp::qrCodeUrl(Totp::provisioningUri($setup['secret'], $user_email), 220),
                            'app_name' => 'certV',
                        ];
                    }
                }
                break;

            // ── Recovery codes (l'utente PUÒ rigenerarli) ────────
            case 'recovery_regenerate':
                if (!$state['has_authorization']) {
                    $flash_error = 'Devi essere autorizzato a 2FA per generare recovery codes.';
                } else {
                    $show_codes = RecoveryCodes::regenerate($pdo, $u_id);
                    write_log('Auth', 'info', 'Recovery codes rigenerati dall\'utente', $u_id);
                }
                break;
        }
    } catch (Throwable $e) {
        $flash_error = 'Errore: ' . h($e->getMessage());
        error_log('[2fa_settings] ' . $e->getMessage());
    }
}

$remaining_codes = $state['has_authorization'] ? RecoveryCodes::remaining($pdo, $u_id) : 0;

require_once __DIR__ . '/header.php';
?>

<style>
.sec-card{background:#fff;border:1px solid var(--border);border-radius:12px;padding:24px;margin-bottom:20px}
.sec-head{display:flex;align-items:center;gap:14px;margin-bottom:16px}
.sec-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;flex-shrink:0}
.sec-title{font-size:16px;font-weight:700;color:#1e293b}
.sec-desc{font-size:13px;color:var(--muted);margin-top:2px}
.status-pill{display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;text-transform:uppercase}
.status-pill.ok{background:#d1fae5;color:#065f46}
.status-pill.pending{background:#fef3c7;color:#92400e}
.status-pill.off{background:#f1f5f9;color:#64748b}
.qr-box{display:flex;gap:24px;align-items:flex-start;background:#f8fafc;padding:20px;border-radius:10px;margin:16px 0;flex-wrap:wrap}
.qr-img{background:#fff;padding:12px;border-radius:8px;border:1px solid var(--border)}
.qr-img img{display:block;width:200px;height:200px}
.qr-info{flex:1;min-width:240px}
.secret-display{font-family:'Courier New',monospace;background:#fff;border:1px dashed #cbd5e1;padding:10px 14px;border-radius:6px;font-size:13px;letter-spacing:2px;word-break:break-all;margin:8px 0}
.codes-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;background:#fffbeb;border:1px solid #f59e0b;border-radius:10px;padding:18px;margin:16px 0}
.code-item{font-family:'Courier New',monospace;font-size:14px;font-weight:700;color:#1e293b;background:#fff;padding:10px 14px;border-radius:6px;text-align:center;letter-spacing:1px}
.warn-box{background:#fef3c7;color:#92400e;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:7px;font-size:13px;line-height:1.5;margin:12px 0}
input.code-input{padding:10px 14px;border:1.5px solid #cbd5e1;border-radius:8px;font-family:'Courier New',monospace;font-size:16px;letter-spacing:4px;text-align:center;width:140px}
.no-auth-banner{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;border-radius:10px;padding:24px;text-align:center}
.no-auth-banner .ic{font-size:38px;margin-bottom:10px}
.no-auth-banner h2{font-size:18px;margin-bottom:8px}
.no-auth-banner p{font-size:13px;line-height:1.5}
.read-only-info{background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:14px 18px;font-size:13px;color:#475569}
.read-only-info strong{color:#1e293b}
@media (max-width:768px){
  .codes-grid{grid-template-columns:1fr}
  .qr-box{flex-direction:column}
}
</style>

<div style="max-width:780px;margin:0 auto">

  <div style="margin-bottom:24px">
    <h1 style="font-size:22px;font-weight:800;color:#1e293b">Sicurezza account</h1>
    <p style="color:var(--muted);font-size:13px;margin-top:4px">Gestisci la verifica in due passaggi del tuo account.</p>
  </div>

  <?php if ($flash_success): ?>
    <div class="alert alert-success">✓ <?= h($flash_success) ?></div>
  <?php endif; ?>
  <?php if ($flash_error): ?>
    <div class="alert alert-danger">✗ <?= h($flash_error) ?></div>
  <?php endif; ?>

  <!-- Caso 1: nessuna autorizzazione admin → messaggio informativo -->
  <?php if (!$state['has_authorization']): ?>
    <div class="no-auth-banner">
      <div class="ic">🔒</div>
      <h2>2FA non autorizzata per il tuo account</h2>
      <p>L'autenticazione a due fattori non è stata abilitata per il tuo profilo.<br>
         Se desideri attivarla, contatta l'amministratore di sistema.</p>
    </div>
  <?php else: ?>

    <!-- Recovery codes appena generati -->
    <?php if ($show_codes): ?>
    <div class="sec-card" style="border-color:#f59e0b;border-width:2px">
      <div class="sec-head">
        <div class="sec-icon" style="background:#f59e0b">🔑</div>
        <div>
          <div class="sec-title">I tuoi codici di recupero</div>
          <div class="sec-desc">Salvali subito in un posto sicuro: NON saranno più mostrati.</div>
        </div>
      </div>
      <div class="codes-grid">
        <?php foreach ($show_codes as $c): ?>
          <div class="code-item"><?= h($c) ?></div>
        <?php endforeach; ?>
      </div>
      <div class="warn-box">
        <strong>⚠ Importante:</strong> ogni codice è valido <strong>una sola volta</strong>. Stampa questa pagina o copia i codici in un password manager. Se perdi sia l'app TOTP che questi codici, dovrai chiedere all'amministratore di resettare la 2FA.
      </div>
      <button onclick="window.print()" class="btn btn-blue">🖨 Stampa</button>
      <button onclick="copyAllCodes()" class="btn">📋 Copia tutti</button>
    </div>
    <script>
    function copyAllCodes() {
      const codes = <?= json_encode($show_codes) ?>;
      navigator.clipboard.writeText(codes.join('\n')).then(() => alert('Codici copiati!'));
    }
    </script>
    <?php endif; ?>

    <!-- ═══ TOTP ═══ -->
    <?php if ($state['totp_authorized']): ?>
    <div class="sec-card">
      <div class="sec-head">
        <div class="sec-icon" style="background:#0ea5e9">📱</div>
        <div>
          <div class="sec-title">App di autenticazione (TOTP)</div>
          <div class="sec-desc">Google Authenticator, Microsoft Authenticator, Authy, 1Password, ecc.</div>
        </div>
        <span class="status-pill <?= $state['totp_active'] ? 'ok' : 'pending' ?>">
          <?= $state['totp_active'] ? 'Configurato' : 'Da configurare' ?>
        </span>
      </div>

      <?php if ($setup_data): ?>
        <!-- Fase setup -->
        <div class="qr-box">
          <div class="qr-img">
            <img src="<?= h($setup_data['qr_url']) ?>" alt="QR code TOTP" loading="lazy">
          </div>
          <div class="qr-info">
            <h3 style="font-size:14px;margin-bottom:10px">1. Scansiona il QR</h3>
            <p style="font-size:13px;color:var(--muted);margin-bottom:14px">Apri la tua app authenticator e scansiona il codice. In alternativa, inserisci manualmente questa chiave:</p>
            <div class="secret-display"><?= h($setup_data['secret']) ?></div>
          </div>
        </div>

        <h3 style="font-size:14px;margin:18px 0 10px">2. Inserisci il codice generato dall'app</h3>
        <form method="POST" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="totp_confirm">
          <input class="code-input" type="text" name="totp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus placeholder="000000">
          <button type="submit" class="btn btn-primary">Verifica e attiva</button>
          <a href="<?= url_safe('2fa_settings') ?>" class="btn">Annulla</a>
        </form>

      <?php elseif ($state['totp_active']): ?>
        <p style="color:var(--muted);font-size:13px;margin-bottom:14px">✓ TOTP è attivo. Ad ogni login dovrai inserire un codice generato dalla tua app.</p>
        <div class="read-only-info">
          <strong>Nota:</strong> per disattivare il TOTP è necessario contattare l'amministratore. La rimozione non può avvenire dal pannello utente.
        </div>
      <?php else: ?>
        <p style="color:var(--muted);font-size:13px;margin-bottom:14px">L'amministratore ha autorizzato TOTP per il tuo account. Configura un'app di autenticazione sul tuo smartphone per generare codici a 6 cifre offline.</p>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="totp_start">
          <button type="submit" class="btn btn-primary">⚙ Configura TOTP</button>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ═══ Email OTP (sola lettura per l'utente) ═══ -->
    <?php if ($state['email_authorized']): ?>
    <div class="sec-card">
      <div class="sec-head">
        <div class="sec-icon" style="background:#8b5cf6">✉</div>
        <div>
          <div class="sec-title">Codice via Email</div>
          <div class="sec-desc">Riceverai un codice a 6 cifre all'indirizzo del tuo account.</div>
        </div>
        <span class="status-pill ok">Attivo</span>
      </div>
      <div class="read-only-info">
        Indirizzo email: <strong><?= h($user_email) ?></strong><br>
        <span style="font-size:12px;color:var(--muted);margin-top:4px;display:block">Email OTP è gestito dall'amministratore. Per disattivarlo o modificarlo, contatta l'admin.</span>
      </div>
    </div>
    <?php endif; ?>

    <!-- ═══ Recovery Codes (l'utente PUÒ rigenerarli) ═══ -->
    <div class="sec-card">
      <div class="sec-head">
        <div class="sec-icon" style="background:#f59e0b">🔑</div>
        <div>
          <div class="sec-title">Codici di recupero</div>
          <div class="sec-desc">10 codici one-time per accedere se perdi l'app o l'email.</div>
        </div>
        <?php if ($remaining_codes > 0): ?>
          <span class="status-pill ok"><?= $remaining_codes ?> disponibili</span>
        <?php else: ?>
          <span class="status-pill off">Nessuno</span>
        <?php endif; ?>
      </div>

      <p style="font-size:13px;color:var(--muted);margin-bottom:14px">
        I codici di recupero ti permettono di accedere se perdi l'accesso ai metodi principali.
        <?php if ($remaining_codes > 0 && $remaining_codes < 4): ?>
          <br><strong style="color:#92400e">Hai solo <?= $remaining_codes ?> codici rimasti — considera di rigenerarli.</strong>
        <?php endif; ?>
      </p>
      <form method="POST" onsubmit="return confirm('Generare nuovi codici? Quelli vecchi NON saranno più validi.')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="recovery_regenerate">
        <button type="submit" class="btn <?= $remaining_codes === 0 ? 'btn-primary' : 'btn-blue' ?>">
          <?= $remaining_codes === 0 ? '🔑 Genera codici di recupero' : '🔄 Rigenera codici' ?>
        </button>
      </form>
    </div>

    <!-- Info di sintesi -->
    <div class="sec-card" style="background:#f8fafc">
      <h3 style="font-size:13px;font-weight:700;margin-bottom:10px;color:#1e293b">💡 Note</h3>
      <ul style="font-size:12.5px;color:var(--muted);line-height:1.7;margin-left:18px">
        <li>L'attivazione/disattivazione di <strong>TOTP</strong> e <strong>Email OTP</strong> è gestita dall'amministratore.</li>
        <li>Tu puoi <strong>completare il setup TOTP</strong> (scansione QR) e <strong>rigenerare i recovery codes</strong> quando vuoi.</li>
        <li>I <strong>recovery codes</strong> sono il tuo paracadute: stampali e tienili in un posto sicuro.</li>
      </ul>
    </div>

  <?php endif; // has_authorization ?>

</div>

<?php require_once __DIR__ . '/footer.php'; ?>
