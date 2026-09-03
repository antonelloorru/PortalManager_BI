<?php
/**
 * certV 2.4 — smtp_settings.php
 * Configurazione motore SMTP — Solo Super Admin (ruolo 1)
 * Indipendente dal SO: usa SmtpMailer.php (socket puri PHP)
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/SmtpMailer.php');

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
if (!can('edit')) { header("Location: unauthorized.php"); exit(); }

$msg = '';
$test_result = null;

// ── SALVATAGGIO ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    // ── Salva configurazione SMTP ────────────────────────────
    if ($action === 'save') {
        $smtp_keys = [
            'smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_encryption',
            'smtp_user', 'smtp_pass', 'smtp_auth', 'smtp_timeout', 'smtp_debug',
            'smtp_test_email', 'mail_from', 'mail_from_name',
        ];
        foreach ($smtp_keys as $k) {
            if (!isset($_POST[$k])) continue;
            $val = trim($_POST[$k]);
            // Validazioni
            if ($k === 'smtp_port')    $val = (string)max(1, min(65535, (int)$val));
            if ($k === 'smtp_timeout') $val = (string)max(5, min(60, (int)$val));
            if ($k === 'smtp_encryption' && !in_array($val, ['tls','ssl','none'])) $val = 'tls';
            save_setting($k, $val);
        }
        $settings = load_settings_fresh();
        write_log('SMTP', 'success', 'Configurazione SMTP aggiornata', $u_id);
        $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Configurazione SMTP salvata.</div>";
    }

    // ── Test connessione ─────────────────────────────────────
    if ($action === 'test_connection') {
        $settings = load_settings_fresh();
        $mailer = new SmtpMailer();
        $mailer->configure($settings);
        $test_result = $mailer->testConnection();
        write_log('SMTP', $test_result['ok'] ? 'success' : 'warning',
            'Test connessione SMTP: ' . ($test_result['ok'] ? 'OK' : $test_result['error']), $u_id);
        if ($test_result['ok']) {
            save_setting('smtp_verified', (string)time());
        }
    }

    // ── Invio email di test ──────────────────────────────────
    if ($action === 'send_test') {
        $settings = load_settings_fresh();
        $test_addr = $settings['smtp_test_email'] ?? '';
        if (!$test_addr || !filter_var($test_addr, FILTER_VALIDATE_EMAIL)) {
            $msg = "<div class='alert alert-warning'><i class='fa-solid fa-triangle-exclamation'></i> Inserire un indirizzo email di test valido e salvare prima.</div>";
        } else {
            $ok = send_certv_email(
                $test_addr,
                '[certV] Email di test SMTP',
                "Questa è un'email di test inviata dal portale certV.\r\n\r\n"
                . "Se la ricevi, la configurazione SMTP è corretta.\r\n\r\n"
                . "Server: " . ($settings['smtp_host'] ?? '') . ":" . ($settings['smtp_port'] ?? '') . "\r\n"
                . "Crittografia: " . ($settings['smtp_encryption'] ?? '') . "\r\n"
                . "Data test: " . date('d/m/Y H:i:s') . "\r\n"
                . "Sistema: PHP " . PHP_VERSION . " su " . PHP_OS . "\r\n\r\n"
                . "— certV SmtpMailer",
                null, [], 'test'
            );
            $msg = $ok
                ? "<div class='alert alert-success'><i class='fa-solid fa-paper-plane'></i> Email di test inviata con successo a <strong>" . h($test_addr) . "</strong>. Controllare la posta in arrivo (anche SPAM).</div>"
                : "<div class='alert alert-danger'><i class='fa-solid fa-circle-xmark'></i> Invio fallito. Controllare i parametri SMTP. Dettagli nel <a href='view_logs.php' style='color:inherit;font-weight:700'>log di sistema</a>.</div>";
        }
    }
}

require_once('header.php');
$s = load_settings_fresh();

// Preset server comuni
$presets = [
    'Gmail'      => ['host'=>'smtp.gmail.com',       'port'=>587, 'enc'=>'tls'],
    'Outlook'    => ['host'=>'smtp.office365.com',    'port'=>587, 'enc'=>'tls'],
    'Yahoo'      => ['host'=>'smtp.mail.yahoo.com',   'port'=>587, 'enc'=>'tls'],
    'Aruba'      => ['host'=>'smtps.aruba.it',        'port'=>465, 'enc'=>'ssl'],
    'SendGrid'   => ['host'=>'smtp.sendgrid.net',     'port'=>587, 'enc'=>'tls'],
    'Mailgun'    => ['host'=>'smtp.mailgun.org',       'port'=>587, 'enc'=>'tls'],
    'Amazon SES' => ['host'=>'email-smtp.eu-west-1.amazonaws.com', 'port'=>587, 'enc'=>'tls'],
];

$smtp_verified = (int)($s['smtp_verified'] ?? 0);
$verified_ago  = $smtp_verified ? round((time() - $smtp_verified) / 3600) : 0;
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
  <div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:3px">
      <i class="fa-solid fa-envelope-circle-check" style="color:var(--p);margin-right:10px"></i>Configurazione SMTP
    </h1>
    <p style="color:var(--muted);font-size:13px">Motore email indipendente dal sistema operativo — Solo Super Admin</p>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <?php if (($s['smtp_enabled'] ?? '0') === '1'): ?>
      <span style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:8px;background:#ecfdf5;color:#065f46;font-size:12px;font-weight:700">
        <i class="fa-solid fa-circle" style="font-size:8px;color:#10b981"></i> SMTP Attivo
      </span>
    <?php else: ?>
      <span style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:8px;background:#fef2f2;color:#991b1b;font-size:12px;font-weight:700">
        <i class="fa-solid fa-circle" style="font-size:8px;color:#ef4444"></i> SMTP Disattivo
      </span>
    <?php endif; ?>
  </div>
</div>

<?=$msg?>

<?php if ($test_result !== null): ?>
<!-- Risultato test connessione -->
<div class="card" style="margin-bottom:22px;border-left:4px solid <?=$test_result['ok']?'var(--success)':'var(--danger)'?>">
  <div class="card-header">
    <span class="card-title" style="color:<?=$test_result['ok']?'var(--success)':'var(--danger)'?>">
      <i class="fa-solid <?=$test_result['ok']?'fa-circle-check':'fa-circle-xmark'?>"></i>
      Test connessione — <?=$test_result['ok']?'Riuscito':'Fallito'?>
    </span>
  </div>
  <?php foreach ($test_result['steps'] as $step): ?>
  <div style="display:flex;align-items:center;gap:12px;padding:10px 20px;border-bottom:1px solid #f1f5f9">
    <div style="width:24px;height:24px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:11px;flex-shrink:0;background:<?=$step[1]?'#ecfdf5':'#fef2f2'?>;color:<?=$step[1]?'var(--success)':'var(--danger)'?>">
      <i class="fa-solid <?=$step[1]?'fa-check':'fa-xmark'?>"></i>
    </div>
    <div style="flex:1;font-weight:600;font-size:13px"><?=h($step[0])?></div>
    <div style="font-size:12px;color:var(--muted);max-width:400px;text-align:right"><?=h(substr($step[2] ?? '', 0, 120))?></div>
  </div>
  <?php endforeach; ?>
  <?php if ($test_result['error'] && !$test_result['ok']): ?>
  <div style="padding:14px 20px;background:#fef2f2;font-size:12px;color:#991b1b">
    <strong>Errore:</strong> <?=h($test_result['error'])?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<form method="POST">
            <?= csrf_field() ?>
<input type="hidden" name="action" value="save">

<div style="display:grid;grid-template-columns:1fr 1fr;gap:22px;margin-bottom:22px">

  <!-- COLONNA 1: Server SMTP -->
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-server" style="color:var(--p)"></i> Server SMTP</span>
    </div>

    <!-- Preset rapidi -->
    <div style="margin-bottom:14px">
      <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:6px">Preset rapidi</label>
      <div style="display:flex;flex-wrap:wrap;gap:6px">
        <?php foreach ($presets as $name => $pr): ?>
        <button type="button" onclick="applyPreset('<?=$pr['host']?>',<?=$pr['port']?>,'<?=$pr['enc']?>')"
                class="btn btn-sm" style="font-size:11px;padding:4px 10px"><?=$name?></button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="form-group">
      <label>Abilita SMTP</label>
      <select name="smtp_enabled" id="smtp_enabled" onchange="toggleSmtp()">
        <option value="0" <?=($s['smtp_enabled']??'0')==='0'?'selected':''?>>Disabilitato — nessuna email inviata</option>
        <option value="1" <?=($s['smtp_enabled']??'0')==='1'?'selected':''?>>Abilitato — usa motore SMTP PHP</option>
      </select>
    </div>

    <div id="smtp_fields">
      <div class="form-group">
        <label>Host SMTP *</label>
        <input type="text" name="smtp_host" id="f_host" value="<?=h($s['smtp_host'] ?? '')?>" placeholder="smtp.gmail.com">
      </div>

      <div class="grid-2">
        <div class="form-group">
          <label>Porta</label>
          <input type="number" name="smtp_port" id="f_port" value="<?=h($s['smtp_port'] ?? '587')?>" min="1" max="65535">
          <div style="font-size:10px;color:var(--muted);margin-top:3px">25=SMTP, 465=SSL, 587=TLS</div>
        </div>
        <div class="form-group">
          <label>Crittografia</label>
          <select name="smtp_encryption" id="f_enc">
            <option value="tls" <?=($s['smtp_encryption']??'tls')==='tls'?'selected':''?>>STARTTLS (porta 587)</option>
            <option value="ssl" <?=($s['smtp_encryption']??'')==='ssl'?'selected':''?>>SSL implicito (porta 465)</option>
            <option value="none" <?=($s['smtp_encryption']??'')==='none'?'selected':''?>>Nessuna (porta 25)</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label><input type="checkbox" name="smtp_auth" value="1" <?=($s['smtp_auth']??'1')==='1'?'checked':''?> style="width:auto;margin-right:8px">Richiede autenticazione</label>
      </div>

      <div class="grid-2">
        <div class="form-group">
          <label>Username</label>
          <input type="text" name="smtp_user" value="<?=h($s['smtp_user'] ?? '')?>" placeholder="utente@azienda.it">
        </div>
        <div class="form-group">
          <label>Password</label>
          <input type="password" name="smtp_pass" value="<?=h($s['smtp_pass'] ?? '')?>" placeholder="••••••••">
          <div style="font-size:10px;color:var(--muted);margin-top:3px">Per Gmail: usare App Password</div>
        </div>
      </div>

      <div class="grid-2">
        <div class="form-group">
          <label>Timeout (secondi)</label>
          <input type="number" name="smtp_timeout" value="<?=h($s['smtp_timeout'] ?? '15')?>" min="5" max="60">
        </div>
        <div class="form-group">
          <label>Debug log</label>
          <select name="smtp_debug">
            <option value="0" <?=($s['smtp_debug']??'0')==='0'?'selected':''?>>Disabilitato</option>
            <option value="1" <?=($s['smtp_debug']??'')==='1'?'selected':''?>>Abilitato — log dettagliato</option>
          </select>
        </div>
      </div>
    </div>
  </div>

  <!-- COLONNA 2: Mittente + Test -->
  <div style="display:flex;flex-direction:column;gap:18px">

    <div class="card" style="flex:1">
      <div class="card-header">
        <span class="card-title"><i class="fa-solid fa-at" style="color:var(--p)"></i> Mittente</span>
      </div>
      <div class="form-group">
        <label>Email mittente</label>
        <input type="email" name="mail_from" value="<?=h($s['mail_from'] ?? 'certv@example.com')?>" placeholder="noreply@azienda.it">
        <div style="font-size:10px;color:var(--muted);margin-top:3px">Alcuni server SMTP richiedono che coincida con lo username</div>
      </div>
      <div class="form-group">
        <label>Nome mittente</label>
        <input type="text" name="mail_from_name" value="<?=h($s['mail_from_name'] ?? 'certV System')?>">
      </div>
      <div class="form-group">
        <label>Email di test</label>
        <input type="email" name="smtp_test_email" value="<?=h($s['smtp_test_email'] ?? '')?>" placeholder="test@example.com">
        <div style="font-size:10px;color:var(--muted);margin-top:3px">Usato dal pulsante 'Invia test'</div>
      </div>
    </div>

    <!-- Test panel -->
    <div class="card" style="background:linear-gradient(135deg,#0f172a,#1e3a5f);color:#e2e8f0;border:none">
      <div style="padding:4px 0">
        <div style="font-size:14px;font-weight:700;margin-bottom:6px">
          <i class="fa-solid fa-flask" style="color:#38bdf8;margin-right:8px"></i>Test SMTP
        </div>
        <p style="font-size:12px;color:#94a3b8;margin-bottom:14px">
          Salva prima la configurazione, poi testa la connessione e invia una email di prova.
        </p>
        <?php if ($smtp_verified > 0): ?>
        <div style="font-size:11px;color:#10b981;margin-bottom:12px">
          <i class="fa-solid fa-circle-check"></i> Ultimo test riuscito: <?=$verified_ago?> ore fa (<?=date('d/m/Y H:i', $smtp_verified)?>)
        </div>
        <?php endif; ?>
        <div style="display:flex;gap:8px">
          <button type="submit" form="ftest" name="action" value="test_connection"
                  class="btn" style="flex:1;justify-content:center;background:#1e293b;border:1px solid #334155;color:#e2e8f0;font-size:12px">
            <i class="fa-solid fa-plug"></i> Testa connessione
          </button>
          <button type="submit" form="ftest" name="action" value="send_test"
                  class="btn" style="flex:1;justify-content:center;background:#0ea5e9;border:none;color:#fff;font-size:12px">
            <i class="fa-solid fa-paper-plane"></i> Invia test
          </button>
        </div>
      </div>
    </div>

    <!-- Info compatibilità -->
    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 16px;font-size:12px;color:#1e40af">
      <div style="font-weight:700;margin-bottom:8px"><i class="fa-solid fa-circle-info"></i> Indipendenza dal SO</div>
      <div style="line-height:1.6;color:#475569">
        Il motore SmtpMailer usa esclusivamente <strong>socket PHP</strong> (stream_socket_client).
        Non dipende da <code>mail()</code>, <code>sendmail</code>, <code>postfix</code> o altri servizi del sistema operativo.
        Funziona identicamente su Windows (XAMPP), Linux (LAMP) e macOS (MAMP).
      </div>
    </div>
  </div>
</div>

<!-- Pulsante salva -->
<div style="display:flex;justify-content:flex-end;gap:10px;margin-bottom:22px">
  <button type="submit" class="btn btn-primary" style="padding:12px 30px;font-size:15px">
    <i class="fa-solid fa-floppy-disk"></i> Salva configurazione SMTP
  </button>
</div>
</form>

<!-- Form separato per i test (non interferisce con il salvataggio) -->
<form id="ftest" method="POST">
            <?= csrf_field() ?></form>

<!-- Log email recenti -->
<?php
$recent_emails = [];
try {
    $recent_emails = $pdo->query(
        "SELECT * FROM email_log ORDER BY created_at DESC LIMIT 15"
    )->fetchAll();
} catch (\Exception $e) { /* tabella non ancora creata */ }
?>
<?php if (!empty($recent_emails)): ?>
<div class="card" style="margin-top:4px">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-clock-rotate-left" style="color:var(--p)"></i> Ultime email inviate</span>
    <span style="font-size:11px;color:var(--muted)"><?=count($recent_emails)?> più recenti</span>
  </div>
  <table style="width:100%;border-collapse:collapse;font-size:12px">
    <tr style="background:#f8fafc">
      <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:800;color:var(--muted);text-transform:uppercase">Data</th>
      <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:800;color:var(--muted);text-transform:uppercase">Destinatario</th>
      <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:800;color:var(--muted);text-transform:uppercase">Oggetto</th>
      <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:800;color:var(--muted);text-transform:uppercase">Stato</th>
      <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:800;color:var(--muted);text-transform:uppercase">Modulo</th>
    </tr>
    <?php foreach ($recent_emails as $em):
      $st_col = match($em['status']) { 'sent'=>'var(--success)', 'failed'=>'var(--danger)', default=>'var(--warning)' };
      $st_lbl = match($em['status']) { 'sent'=>'Inviata', 'failed'=>'Fallita', default=>'In coda' };
    ?>
    <tr style="border-bottom:1px solid #f1f5f9" title="<?=h($em['error_msg'] ?? '')?>">
      <td style="padding:8px 12px;color:var(--muted)"><?=date('d/m H:i', strtotime($em['created_at']))?></td>
      <td style="padding:8px 12px;font-weight:600"><?=h($em['recipient'])?></td>
      <td style="padding:8px 12px;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h($em['subject'])?></td>
      <td style="padding:8px 12px"><span style="color:<?=$st_col?>;font-weight:700;font-size:11px"><?=$st_lbl?></span></td>
      <td style="padding:8px 12px;color:var(--muted)"><?=h($em['module'])?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<script>
function applyPreset(host, port, enc) {
  document.getElementById('f_host').value = host;
  document.getElementById('f_port').value = port;
  document.getElementById('f_enc').value = enc;
}
function toggleSmtp() {
  document.getElementById('smtp_fields').style.opacity =
    document.getElementById('smtp_enabled').value === '1' ? '1' : '.4';
}
toggleSmtp();
</script>

<?php require_once('footer.php'); ?>
