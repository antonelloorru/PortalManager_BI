<?php
/**
 * certV 4.1 — 2fa_verify.php
 * Pagina mostrata DOPO la password quando l'utente ha 2FA attiva.
 * Permette di scegliere il metodo (TOTP / email / recovery) e inserire il codice.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/Totp.php';
require_once __DIR__ . '/app/EmailOtp.php';
require_once __DIR__ . '/app/RecoveryCodes.php';
require_once __DIR__ . '/app/TwoFactor.php';

$pending = TwoFactor::getPending();
if (!$pending) {
    // Nessun login pending → ritorna al login
    redirect('login');
}

$pdo_state = TwoFactor::getUserState($pdo, (int)$pending['user_id']);

// v1.4.3 FIX: se l'admin ha resettato la 2FA mentre l'utente era in pending,
// completa il login senza chiedere il codice (l'utente non ha più 2FA attiva).
if (!$pdo_state['enabled']) {
    // Costruisco il payload come fa completePendingLogin
    Session::onLogin(
        (int)$pending['user_id'],
        (int)$pending['role_id'],
        [
            'employee_id' => $pending['employee_id'] ?? null,
            'user_name'   => $pending['user_name'] ?? '',
        ]
    );
    Csrf::rotate();
    TwoFactor::clearPending();
    if (function_exists('write_log')) {
        write_log('Auth', 'info', '2FA bypass: utente senza 2FA configurata (probabile reset admin)', (int)$pending['user_id']);
    }
    redirect('index');
}

$error = '';
$info  = '';

// Rate limit per il numero di tentativi 2FA per IP
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateKey = "2fa:ip:$ip";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Azione: invio codice email su richiesta
    if (isset($_POST['action']) && $_POST['action'] === 'send_email') {
        if (!$pdo_state['email_otp_enabled']) {
            $error = 'Email OTP non abilitato per questo account.';
        } else {
            $cooldown = EmailOtp::resendCooldown();
            if ($cooldown > 0) {
                $error = "Attendi $cooldown secondi prima di richiedere un nuovo codice.";
            } else {
                $code = EmailOtp::reissue((int)$pending['user_id']);
                if ($code) {
                    EmailOtp::send($pending['email'], $pending['user_name'], $code);
                    $info = 'Codice inviato alla tua email.';
                    write_log('Auth', 'info', '2FA email OTP inviato', (int)$pending['user_id']);
                } else {
                    $error = 'Impossibile inviare il codice in questo momento.';
                }
            }
        }
    }
    // Azione: annulla e torna al login
    elseif (isset($_POST['action']) && $_POST['action'] === 'cancel') {
        TwoFactor::clearPending();
        redirect('login');
    }
    // Azione: verifica codice
    else {
        if (!RateLimiter::attempt($rateKey, 10, 600, 600)) {
            $error = 'Troppi tentativi. Riprova tra 10 minuti.';
        } else {
            $code = trim((string)($_POST['code'] ?? ''));
            if (!$code) {
                $error = 'Inserisci il codice.';
            } else {
                $userData = TwoFactor::completePendingLogin($pdo, $code);
                if ($userData) {
                    RateLimiter::reset($rateKey);

                    // Login completo: setta sessione e redirect
                    Session::onLogin(
                        (int)$userData['user_id'],
                        (int)$userData['role_id'],
                        [
                            'employee_id' => $userData['employee_id'],
                            'user_name'   => $userData['user_name'],
                        ]
                    );
                    Csrf::rotate();

                    // Aggiorna last_used_at sulla 2FA
                    try {
                        $pdo->prepare("UPDATE user_2fa SET last_used_at = NOW() WHERE user_id = ?")
                            ->execute([(int)$userData['user_id']]);
                    } catch (Throwable $e) {}

                    redirect('index');
                } else {
                    $error = 'Codice non valido o scaduto.';
                    write_log('Auth', 'warning', '2FA codice non valido',
                              (int)$pending['user_id'], ['ip' => $ip]);
                    usleep(random_int(200000, 500000));
                }
            }
        }
    }
}

$settings = load_settings();
$primary  = $settings['primary_color'] ?? '#0ea5e9';
$app_name = $settings['app_name']      ?? 'certV';

$emailMasked = self_mask_email($pending['email']);

function self_mask_email(string $email): string {
    [$user, $dom] = array_pad(explode('@', $email, 2), 2, '');
    if (strlen($user) <= 2) {
        $maskedUser = $user[0] . '***';
    } else {
        $maskedUser = substr($user, 0, 2) . str_repeat('*', max(1, strlen($user) - 4)) . substr($user, -2);
    }
    return $maskedUser . '@' . $dom;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Verifica 2FA — <?= h($app_name) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:linear-gradient(135deg,#f0f4f8,#e2e8f0);display:flex;min-height:100vh;align-items:center;justify-content:center}
.wrap{width:100%;max-width:440px;padding:20px}
.box{background:#fff;padding:36px;border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,.1)}
.logo{text-align:center;margin-bottom:24px}
.logo-icon{width:58px;height:58px;background:<?= h($primary) ?>;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:26px;margin-bottom:12px}
h1{font-size:20px;font-weight:800;color:#1e293b;margin-bottom:6px;text-align:center}
.greeting{text-align:center;color:#64748b;font-size:13px;margin-bottom:24px}
.greeting strong{color:#1e293b}
.tabs{display:flex;background:#f1f5f9;border-radius:9px;padding:4px;margin-bottom:18px;gap:2px}
.tab{flex:1;padding:9px;border:none;background:transparent;border-radius:7px;font-size:12px;font-weight:600;color:#64748b;cursor:pointer;transition:.15s}
.tab.active{background:#fff;color:#1e293b;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.method-box{display:none}
.method-box.active{display:block}
.method-desc{font-size:13px;color:#64748b;line-height:1.5;margin-bottom:18px;padding:12px;background:#f8fafc;border-radius:8px;border-left:3px solid <?= h($primary) ?>}
.fg{margin-bottom:14px}
.fg label{display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:6px;letter-spacing:.5px}
.fg input{width:100%;padding:14px;border:1.5px solid #cbd5e1;border-radius:9px;font-size:18px;color:#1e293b;font-family:'Courier New',monospace;letter-spacing:4px;text-align:center;font-weight:700}
.fg input:focus{outline:none;border-color:<?= h($primary) ?>;box-shadow:0 0 0 3px <?= h($primary) ?>33}
.btn{width:100%;padding:13px;background:<?= h($primary) ?>;color:#fff;border:none;border-radius:9px;font-size:14px;font-weight:700;cursor:pointer;transition:.15s;font-family:inherit}
.btn:hover{filter:brightness(1.08)}
.btn:disabled{opacity:.5;cursor:not-allowed}
.btn-secondary{background:#fff;color:#64748b;border:1.5px solid #cbd5e1;margin-top:8px}
.btn-secondary:hover{background:#f8fafc}
.err{background:#fee2e2;color:#991b1b;border-left:4px solid #ef4444;padding:11px 14px;border-radius:7px;margin-bottom:16px;font-size:13px;line-height:1.5}
.info{background:#dbeafe;color:#1e40af;border-left:4px solid #3b82f6;padding:11px 14px;border-radius:7px;margin-bottom:16px;font-size:13px;line-height:1.5}
.cancel-link{text-align:center;margin-top:18px;font-size:12px}
.cancel-link a{color:#64748b;text-decoration:none}
.cancel-link a:hover{color:#0ea5e9}
.send-info{font-size:12px;color:#64748b;margin-bottom:14px;text-align:center}
.send-info strong{color:#1e293b}
</style>
</head>
<body>
<div class="wrap">
  <div class="box">
    <div class="logo">
      <div class="logo-icon">🔐</div>
    </div>
    <h1>Verifica in due passaggi</h1>
    <p class="greeting">Ciao <strong><?= h(explode(' ', $pending['user_name'])[0]) ?></strong>, conferma la tua identità per accedere.</p>

    <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
    <?php if ($info):  ?><div class="info"><?= h($info) ?></div><?php endif; ?>

    <?php
    $availableMethods = [];
    if ($pdo_state['totp_enabled'])      $availableMethods[] = 'totp';
    if ($pdo_state['email_otp_enabled']) $availableMethods[] = 'email';
    $availableMethods[] = 'recovery';  // sempre disponibile come fallback
    $defaultMethod = $availableMethods[0];
    ?>

    <?php if (count($availableMethods) > 1): ?>
    <div class="tabs">
      <?php if (in_array('totp', $availableMethods)): ?>
        <button type="button" class="tab active" data-method="totp" onclick="showMethod('totp')">App</button>
      <?php endif; ?>
      <?php if (in_array('email', $availableMethods)): ?>
        <button type="button" class="tab <?= $defaultMethod === 'email' ? 'active' : '' ?>" data-method="email" onclick="showMethod('email')">Email</button>
      <?php endif; ?>
      <button type="button" class="tab" data-method="recovery" onclick="showMethod('recovery')">Recupero</button>
    </div>
    <?php endif; ?>

    <!-- METODO: TOTP -->
    <?php if (in_array('totp', $availableMethods)): ?>
    <div class="method-box <?= $defaultMethod === 'totp' ? 'active' : '' ?>" id="m-totp">
      <p class="method-desc">Apri la tua app di autenticazione (Google Authenticator, Microsoft Authenticator, Authy, ecc.) e inserisci il codice a 6 cifre.</p>
      <form method="POST" autocomplete="off">
        <?= csrf_field() ?>
        <div class="fg">
          <label>Codice app</label>
          <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus placeholder="000000">
        </div>
        <button type="submit" class="btn">Verifica</button>
      </form>
    </div>
    <?php endif; ?>

    <!-- METODO: EMAIL OTP -->
    <?php if (in_array('email', $availableMethods)): ?>
    <div class="method-box <?= $defaultMethod === 'email' ? 'active' : '' ?>" id="m-email">
      <p class="method-desc">Riceverai un codice a 6 cifre all'indirizzo <strong><?= h($emailMasked) ?></strong>. Il codice è valido per 10 minuti.</p>

      <?php $cooldown = EmailOtp::resendCooldown(); ?>
      <?php if (!isset($_SESSION['_email_otp']) || $cooldown === 0): ?>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="send_email">
          <button type="submit" class="btn" <?= $cooldown > 0 ? 'disabled' : '' ?>>
            <?= isset($_SESSION['_email_otp']) ? 'Reinvia codice email' : 'Invia codice email' ?>
          </button>
        </form>
      <?php else: ?>
        <p class="send-info">Codice già inviato. Reinvia tra <strong><?= $cooldown ?>s</strong></p>
      <?php endif; ?>

      <?php if (isset($_SESSION['_email_otp'])): ?>
        <form method="POST" autocomplete="off" style="margin-top:14px">
          <?= csrf_field() ?>
          <div class="fg">
            <label>Codice ricevuto via email</label>
            <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required placeholder="000000">
          </div>
          <button type="submit" class="btn">Verifica</button>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- METODO: RECOVERY CODE -->
    <div class="method-box" id="m-recovery">
      <p class="method-desc">Hai perso accesso al tuo metodo principale? Usa uno dei codici di recupero che ti sono stati forniti durante il setup. Ogni codice è valido <strong>una sola volta</strong>.</p>
      <form method="POST" autocomplete="off">
        <?= csrf_field() ?>
        <div class="fg">
          <label>Codice di recupero</label>
          <input type="text" name="code" maxlength="12" required placeholder="XXXX-XXXX" style="letter-spacing:2px;text-transform:uppercase">
        </div>
        <button type="submit" class="btn">Verifica codice di recupero</button>
      </form>
    </div>

    <div class="cancel-link">
      <form method="POST" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel">
        <a href="#" onclick="this.closest('form').submit();return false">← Annulla e torna al login</a>
      </form>
    </div>
  </div>
</div>

<script>
function showMethod(name) {
  document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.dataset.method === name));
  document.querySelectorAll('.method-box').forEach(b => b.classList.remove('active'));
  document.getElementById('m-' + name).classList.add('active');
  // Focus sull'input del metodo attivo
  const inp = document.querySelector('#m-' + name + ' input[name="code"]');
  if (inp) inp.focus();
}

// Auto-uppercase per recovery code
document.querySelectorAll('#m-recovery input[name="code"]').forEach(i => {
  i.addEventListener('input', e => {
    e.target.value = e.target.value.toUpperCase();
  });
});
</script>
</body>
</html>
