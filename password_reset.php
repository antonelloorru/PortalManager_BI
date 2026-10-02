<?php
/**
 * PortalManager — password_reset.php (v1.9.81)
 *
 * Pagina pubblica, stesso stile del login. Tre passi:
 *   1. richiesta: email → messaggio sempre identico (nessuna enumerazione) + link via email;
 *   2. link ?t=<token>: verificato, spostato in sessione e rimosso dall'URL (redirect),
 *      cosi' non resta in cronologia, log di accesso o header Referer;
 *   3. nuova password (policy) → password aggiornata, token consumato, altre sessioni chiuse.
 * Logica e sicurezza in app/PasswordReset.php.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/PasswordReset.php';

header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, max-age=0');

if (!empty($_SESSION['user_id'])) redirect('index');

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$settings = load_settings();
$primary  = $settings['primary_color'] ?? '#0ea5e9';
$app_name = $settings['app_name'] ?? 'PortalManager';

$step = 'request';          // request | sent | new | done | invalid | disabled
$error = ''; $errors = [];

if (!PasswordReset::enabled($pdo)) {
    $step = 'disabled';
} else {
    // ── 2. arrivo dal link: token in sessione, URL ripulito ──────────────
    if (isset($_GET['t'])) {
        if (!RateLimiter::attempt("pwreset:verify:ip:$ip", 20, 900, 900)) {
            $step = 'invalid';
            write_log('Auth', 'warning', 'Reset password: verifica token limitata per IP', null, ['ip' => $ip]);
        } else {
            $t = (string)$_GET['t'];
            if (PasswordReset::find($pdo, $t)) {
                $_SESSION['pwreset_token'] = $t;
                $_SESSION['pwreset_at']    = time();
                redirect('password_reset', ['step' => 'new']);
            }
            unset($_SESSION['pwreset_token']);
            $step = 'invalid';
            write_log('Auth', 'warning', 'Reset password: link non valido o scaduto', null, ['ip' => $ip]);
        }
    } elseif (($_GET['step'] ?? '') === 'new' || isset($_POST['new_password'])) {
        $tok = (string)($_SESSION['pwreset_token'] ?? '');
        $req = ($tok !== '' && time() - (int)($_SESSION['pwreset_at'] ?? 0) < 3600) ? PasswordReset::find($pdo, $tok) : null;
        if (!$req) {
            unset($_SESSION['pwreset_token'], $_SESSION['pwreset_at']);
            $step = 'invalid';
        } else {
            $step = 'new';
            // ── 3. nuova password ────────────────────────────────────────
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                Csrf::verify();
                $pwd = (string)($_POST['new_password'] ?? '');
                $cnf = (string)($_POST['confirm_password'] ?? '');
                $errors = PasswordReset::validate($pdo, $pwd, $cnf, (string)$req['email'], (string)$req['password_hash']);
                if (!$errors) {
                    if (PasswordReset::complete($pdo, $req, $pwd, $ip)) {
                        unset($_SESSION['pwreset_token'], $_SESSION['pwreset_at']);
                        RateLimiter::reset('login:email:' . strtolower((string)$req['email']));
                        Csrf::rotate();
                        write_log('Auth', 'success', 'Password reimpostata tramite link email', (int)$req['user_id'], ['ip' => $ip]);
                        $step = 'done';
                    } else {
                        unset($_SESSION['pwreset_token'], $_SESSION['pwreset_at']);
                        $step = 'invalid';
                    }
                }
            }
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // ── 1. richiesta ─────────────────────────────────────────────────
        Csrf::verify();
        $t0    = microtime(true);
        $email = trim((string)($_POST['email'] ?? ''));
        if (!RateLimiter::attempt("pwreset:req:ip:$ip", 10, 3600, 3600)) {
            $error = 'Troppe richieste da questo indirizzo. Riprova più tardi.';
            write_log('Auth', 'warning', 'Reset password: richieste limitate per IP', null, ['ip' => $ip]);
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            $error = 'Inserisci un indirizzo email valido.';
        } else {
            // per email: oltre 3 richieste l'ora non si invia altro, ma la risposta non cambia
            $esito = RateLimiter::attempt('pwreset:req:email:' . strtolower($email), 3, 3600, 3600)
                   ? PasswordReset::request($pdo, $email, $ip, $ua) : 'limited';
            write_log('Auth', in_array($esito, ['sent'], true) ? 'info' : 'warning',
                      "Reset password richiesto ($esito)", null, ['ip' => $ip, 'email_hash' => substr(hash('sha256', strtolower($email)), 0, 16)]);
            // tempo di risposta uniforme: l'invio SMTP non deve rivelare se l'account esiste
            $min = 1.5 + random_int(0, 500) / 1000;
            $el = microtime(true) - $t0;
            if ($el < $min) usleep((int)(($min - $el) * 1e6));
            $step = 'sent';
        }
    }
}
$minLen = PasswordReset::minLength($pdo);
$ttl    = PasswordReset::ttlMinutes($pdo);
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="referrer" content="no-referrer">
<title>Reimposta password — <?= h($app_name) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:linear-gradient(135deg,#f0f4f8,#e2e8f0);display:flex;min-height:100vh;align-items:center;justify-content:center}
.wrap{width:100%;max-width:440px;padding:20px}
.box{background:#fff;padding:40px;border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,.1)}
.logo{text-align:center;margin-bottom:26px}
.logo-icon{width:58px;height:58px;background:<?= h($primary) ?>;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:26px;margin-bottom:12px}
.logo h1{font-size:22px;font-weight:800;color:#1e293b}
.logo p{font-size:12px;color:#64748b;margin-top:3px}
.fg{margin-bottom:16px}
.fg label{display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:5px}
.fg input{width:100%;padding:12px 14px;border:1.5px solid #cbd5e1;border-radius:9px;font-size:14px;color:#1e293b;font-family:inherit}
.fg input:focus{outline:none;border-color:<?= h($primary) ?>;box-shadow:0 0 0 3px <?= h($primary) ?>33}
.btn{display:block;text-align:center;text-decoration:none;width:100%;padding:13px;background:<?= h($primary) ?>;color:#fff;border:none;border-radius:9px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit}
.btn:hover{filter:brightness(1.08)}
.lnk{display:block;text-align:center;margin-top:16px;font-size:13px;color:#475569;text-decoration:none}
.lnk:hover{text-decoration:underline}
.err{background:#fee2e2;color:#991b1b;border-left:4px solid #ef4444;padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:13px;line-height:1.6}
.ok{background:#dcfce7;color:#166534;border-left:4px solid #16a34a;padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:13px;line-height:1.6}
.info{background:#eff6ff;color:#1e40af;border-left:4px solid #3b82f6;padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:13px;line-height:1.6}
.rules{font-size:12px;color:#64748b;margin:-4px 0 16px;padding-left:18px;line-height:1.7}
.rules li.ok2{color:#16a34a}
.meter{height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;margin:-8px 0 14px}
.meter span{display:block;height:100%;width:0;transition:.2s}
</style>
</head>
<body>
<div class="wrap">
  <div class="box">
    <div class="logo">
      <div class="logo-icon">🔑</div>
      <h1>Reimposta password</h1>
      <p><?= h($app_name) ?></p>
    </div>

<?php if ($step === 'disabled'): ?>
    <div class="info">La reimpostazione della password in autonomia non è attiva. Contatta l'amministratore del portale.</div>
    <a class="btn" href="<?= url_safe('login') ?>">Torna all'accesso</a>

<?php elseif ($step === 'sent'): ?>
    <div class="ok">Se l'indirizzo corrisponde a un account attivo, riceverai a breve un'email con il link per scegliere
      una nuova password. Il link è valido <strong><?= (int)$ttl ?> minuti</strong> e si può usare una sola volta.</div>
    <p style="font-size:13px;color:#64748b;margin-bottom:18px">Non la trovi? Controlla anche la cartella spam. Puoi ripetere la richiesta tra qualche minuto.</p>
    <a class="btn" href="<?= url_safe('login') ?>">Torna all'accesso</a>

<?php elseif ($step === 'invalid'): ?>
    <div class="err">Il link non è valido, è già stato usato oppure è scaduto.</div>
    <a class="btn" href="<?= url_safe('password_reset') ?>">Richiedi un nuovo link</a>
    <a class="lnk" href="<?= url_safe('login') ?>">Torna all'accesso</a>

<?php elseif ($step === 'done'): ?>
    <div class="ok">Password aggiornata. Le altre sessioni aperte sono state chiuse e ti abbiamo inviato un'email di conferma.</div>
    <a class="btn" href="login.php?r=pwreset">Accedi con la nuova password</a>

<?php elseif ($step === 'new'): ?>
    <?php if ($errors): ?><div class="err"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
    <form method="POST" action="<?= url_safe('password_reset', ['step' => 'new']) ?>" autocomplete="off" novalidate>
      <?= csrf_field() ?>
      <div class="fg">
        <label for="np">Nuova password</label>
        <input id="np" type="password" name="new_password" required autofocus autocomplete="new-password" maxlength="72" minlength="<?= (int)$minLen ?>">
      </div>
      <div class="meter"><span id="pm-meter"></span></div>
      <ul class="rules" id="pm-rules">
        <li data-r="len">almeno <?= (int)$minLen ?> caratteri</li>
        <li data-r="cls">tre tipi fra minuscole, maiuscole, numeri, simboli</li>
        <li data-r="eq">conferma uguale</li>
      </ul>
      <div class="fg">
        <label for="cp">Conferma password</label>
        <input id="cp" type="password" name="confirm_password" required autocomplete="new-password" maxlength="72">
      </div>
      <button type="submit" class="btn">Salva la nuova password</button>
    </form>
    <a class="lnk" href="<?= url_safe('login') ?>">Annulla</a>
    <script>
    (function () {
      var np = document.getElementById('np'), cp = document.getElementById('cp'), m = document.getElementById('pm-meter');
      var min = <?= (int)$minLen ?>;
      function upd() {
        var v = np.value, c = (/[a-z]/.test(v)) + (/[A-Z]/.test(v)) + (/\d/.test(v)) + (/[^A-Za-z0-9]/.test(v));
        var r = { len: v.length >= min, cls: c >= 3, eq: v !== '' && v === cp.value };
        document.querySelectorAll('#pm-rules li').forEach(function (li) { li.className = r[li.dataset.r] ? 'ok2' : ''; });
        var s = Math.min(4, (v.length >= min) + (v.length >= min + 4) + (c >= 3) + (c === 4));
        m.style.width = (s * 25) + '%';
        m.style.background = ['#ef4444', '#ef4444', '#f59e0b', '#84cc16', '#16a34a'][s];
      }
      np.addEventListener('input', upd); cp.addEventListener('input', upd);
    })();
    </script>

<?php else: ?>
    <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
    <p style="font-size:13px;color:#475569;margin-bottom:18px;line-height:1.6">Inserisci l'email con cui accedi al portale:
      ti invieremo un link per scegliere una nuova password.</p>
    <form method="POST" action="<?= url_safe('password_reset') ?>" novalidate autocomplete="off">
      <?= csrf_field() ?>
      <div class="fg">
        <label for="em">Email</label>
        <input id="em" type="email" name="email" value="<?= h($_POST['email'] ?? '') ?>" required autofocus autocomplete="username" maxlength="150">
      </div>
      <button type="submit" class="btn">Invia il link</button>
    </form>
    <a class="lnk" href="<?= url_safe('login') ?>">Torna all'accesso</a>
<?php endif; ?>
  </div>
</div>
</body>
</html>
