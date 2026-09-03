<?php
/**
 * certV 4.1 — login.php (con supporto 2FA)
 *
 * Flusso:
 *   1. Utente inserisce email + password
 *   2. Verifica password (con rate limit, csrf, ecc.)
 *   3. Se 2FA NON attiva → Session::onLogin() e redirect a index
 *   4. Se 2FA attiva    → TwoFactor::startPendingLogin() e redirect a 2fa_verify.php
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/Totp.php';
require_once __DIR__ . '/app/EmailOtp.php';
require_once __DIR__ . '/app/RecoveryCodes.php';
require_once __DIR__ . '/app/TwoFactor.php';

// Se già loggato, vai a index
if (!empty($_SESSION['user_id'])) {
    redirect('index');
}

// Se c'è una 2FA pending, vai direttamente alla verifica
if (TwoFactor::getPending()) {
    redirect('2fa_verify');
}

$error     = '';
$ip        = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$locked_in = RateLimiter::lockedFor("login:ip:$ip");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $pwd   = (string)($_POST['password'] ?? '');

    if (!RateLimiter::attempt("login:ip:$ip", 20, 900, 1800)) {
        $error = 'Troppi tentativi da questo indirizzo. Riprova tra qualche minuto.';
        write_log('Auth', 'warning', 'Login rate-limited by IP', null, ['ip' => $ip]);
    } elseif ($email && !RateLimiter::attempt("login:email:" . strtolower($email), 5, 900, 900)) {
        $error = 'Credenziali non valide.';
        write_log('Auth', 'warning', "Login rate-limited by email: $email", null, ['ip' => $ip]);
    } elseif (!$email || !$pwd) {
        $error = 'Inserisci email e password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Credenziali non valide.';
    } else {
        try {
            // Rilevamento schema v2.2+
            $schema_v22 = false;
            try {
                $pdo->query("SELECT `employee_id` FROM `users` LIMIT 0")->closeCursor();
                $schema_v22 = true;
            } catch (\Throwable $e) {}

            if ($schema_v22) {
                $s = $pdo->prepare(
                    "SELECT u.id, u.employee_id, u.email, u.display_name,
                            u.password_hash, u.role_id, u.status
                     FROM users u WHERE u.email = ? LIMIT 1"
                );
            } else {
                $s = $pdo->prepare(
                    "SELECT id, NULL AS employee_id, email,
                            CONCAT(first_name,' ',last_name) AS display_name,
                            password_hash, role_id, status
                     FROM users WHERE email = ? LIMIT 1"
                );
            }
            $s->execute([$email]);
            $user = $s->fetch();
            $s->closeCursor();

            $valid = $user
                  && $user['status'] === 'active'
                  && password_verify($pwd, $user['password_hash']);

            if ($valid) {
                RateLimiter::reset("login:ip:$ip");
                RateLimiter::reset("login:email:" . strtolower($email));

                $emp = null;
                if ($schema_v22 && $user['employee_id']) {
                    $es = $pdo->prepare("SELECT id, first_name, last_name FROM employees WHERE id=?");
                    $es->execute([$user['employee_id']]);
                    $emp = $es->fetch();
                    $es->closeCursor();
                }

                $userName = $emp
                    ? trim($emp['first_name'] . ' ' . $emp['last_name'])
                    : ($user['display_name'] ?? 'Utente');
                $employeeId = $emp ? (int)$emp['id'] : null;

                // ── CHECK 2FA ──────────────────────────────────────
                if (TwoFactor::isEnabled($pdo, (int)$user['id'])) {
                    // Password OK ma serve secondo fattore.
                    // NON popoliamo ancora $_SESSION['user_id']: lo facciamo solo dopo la 2FA.
                    TwoFactor::startPendingLogin(
                        (int)$user['id'],
                        (int)$user['role_id'],
                        $employeeId,
                        $userName,
                        (string)$user['email']
                    );

                    write_log('Auth', 'info', 'Password OK, 2FA pending', (int)$user['id'], ['ip' => $ip]);
                    redirect('2fa_verify');
                }

                // ── No 2FA: login completo ─────────────────────────
                Session::onLogin((int)$user['id'], (int)$user['role_id'], [
                    'employee_id' => $employeeId,
                    'user_name'   => $userName,
                ]);
                Csrf::rotate();

                write_log('Auth', 'success', 'Login (no 2FA)', (int)$user['id'], ['ip' => $ip]);
                redirect('index');
            } else {
                $error = 'Credenziali non valide.';
                write_log('Auth', 'warning', "Login fallito: $email", null, ['ip' => $ip]);
                usleep(random_int(200000, 500000));
            }
        } catch (\Throwable $e) {
            error_log('[certV login] ' . $e->getMessage());
            $error = 'Servizio temporaneamente non disponibile.';
            if (Env::isDebug()) {
                $error .= ' (debug: ' . htmlspecialchars(substr($e->getMessage(), 0, 200)) . ')';
            }
        }
    }
}

$settings = load_settings();
$primary  = $settings['primary_color'] ?? '#0ea5e9';
$app_name = $settings['app_name']      ?? 'certV';

$reason = $_GET['r'] ?? '';
$info_message = match($reason) {
    'idle'            => 'Sessione scaduta per inattività. Accedi nuovamente.',
    'session_expired' => 'La sessione è scaduta. Accedi nuovamente.',
    'invalid'         => 'Sessione non valida. Accedi nuovamente.',
    default           => ''
};
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Accesso — <?= h($app_name) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:linear-gradient(135deg,#f0f4f8,#e2e8f0);display:flex;min-height:100vh;align-items:center;justify-content:center}
.wrap{width:100%;max-width:420px;padding:20px}
.box{background:#fff;padding:40px;border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,.1)}
.logo{text-align:center;margin-bottom:30px}
.logo-icon{width:58px;height:58px;background:<?= h($primary) ?>;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:26px;margin-bottom:12px}
.logo h1{font-size:24px;font-weight:800;color:#1e293b}
.logo p{font-size:12px;color:#64748b;margin-top:3px}
.fg{margin-bottom:18px}
.fg label{display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:5px}
.fg input{width:100%;padding:12px 14px;border:1.5px solid #cbd5e1;border-radius:9px;font-size:14px;color:#1e293b;transition:.2s;font-family:inherit}
.fg input:focus{outline:none;border-color:<?= h($primary) ?>;box-shadow:0 0 0 3px <?= h($primary) ?>33}
.btn{width:100%;padding:13px;background:<?= h($primary) ?>;color:#fff;border:none;border-radius:9px;font-size:15px;font-weight:700;cursor:pointer;transition:.15s;font-family:inherit}
.btn:hover{filter:brightness(1.08)}
.btn:disabled{opacity:.5;cursor:not-allowed}
.err{background:#fee2e2;color:#991b1b;border-left:4px solid #ef4444;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:13px;line-height:1.6}
.info{background:#eff6ff;color:#1e40af;border-left:4px solid #3b82f6;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:13px;line-height:1.6}
.lock{background:#fffbeb;color:#92400e;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:13px}
</style>
</head>
<body>
<div class="wrap">
  <div class="box">
    <div class="logo">
      <div class="logo-icon">🎓</div>
      <h1><?= h($app_name) ?></h1>
      <p>Portale integrato Governance &amp; Talent</p>
    </div>

    <?php if ($locked_in > 0): ?>
      <div class="lock">Accesso temporaneamente bloccato da questo indirizzo. Riprova tra <?= (int)ceil($locked_in / 60) ?> minuti.</div>
    <?php elseif ($info_message): ?>
      <div class="info"><?= h($info_message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="err"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="POST" novalidate autocomplete="off">
      <?= csrf_field() ?>
      <div class="fg">
        <label>Email</label>
        <input type="email" name="email" value="<?= h($_POST['email'] ?? '') ?>"
               required autofocus autocomplete="username" maxlength="120">
      </div>
      <div class="fg">
        <label>Password</label>
        <input type="password" name="password" required
               autocomplete="current-password" maxlength="200">
      </div>
      <button type="submit" class="btn" <?= $locked_in > 0 ? 'disabled' : '' ?>>
        Entra nel sistema
      </button>
    </form>
  </div>
</div>
</body>
</html>
