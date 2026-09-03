<?php
/**
 * PortalManager — diag.php
 *
 * Strumento di diagnostica standalone per problemi di login dopo trasferimento server.
 * Verifica in ordine:
 *   1. Esistenza Config.php e contenuto
 *   2. Connessione DB con i parametri di Config.php
 *   3. Esistenza database con quel nome (cerca anche varianti tipiche)
 *   4. Esistenza tabella users e schema corretto
 *   5. Presenza di almeno un account Super Admin (role_id=1)
 *   6. Stato sessione: cookie name, sessione attiva
 *   7. Permessi scrittura uploads/, tmp/, app_logs writability
 *   8. Eventuali sessioni stantie (cookie obsoleti)
 *
 * Da eliminare dopo la diagnosi in ambienti di produzione.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ── No autenticazione: questo è uno strumento diagnostico standalone ─
// In produzione: proteggere via .htaccess o IP whitelist.

$results = [];
function check(string $name, bool $ok, string $detail = '', string $suggestion = ''): void {
    global $results;
    $results[] = compact('name','ok','detail','suggestion');
}

// ── Step 1: Config.php ────────────────────────────────────────────────
$configPath = __DIR__ . '/Config.php';
if (!file_exists($configPath)) {
    check('Config.php', false, 'File non trovato: ' . $configPath,
          'Copiare Config.php nel root del portale.');
} else {
    check('Config.php', true, 'Trovato: ' . $configPath);
    require_once $configPath;

    foreach (['DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_CHARSET'] as $const) {
        check("Costante $const", defined($const),
              defined($const) ? 'Valore: ' . (in_array($const,['DB_PASS'])?'***':constant($const)) : '',
              defined($const) ? '' : "Aggiungere define('$const',...) in Config.php");
    }
}

// ── Step 2: Connessione DB ────────────────────────────────────────────
$pdo_diag = null;
if (defined('DB_HOST')) {
    try {
        $pdo_diag = new PDO(
            "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=" . DB_CHARSET,
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        check('Connessione server MySQL', true,
              sprintf('Connesso a %s:%s come %s', DB_HOST, DB_PORT, DB_USER));
    } catch (Throwable $e) {
        check('Connessione server MySQL', false,
              $e->getMessage(),
              'Verifica che MySQL/MariaDB sia in esecuzione (XAMPP Control Panel → MySQL → Start) e che DB_USER/DB_PASS in Config.php siano corretti.');
    }
}

// ── Step 3: Database con quel nome esiste? ───────────────────────────
$db_found = false;
$db_variants = [];
if ($pdo_diag) {
    try {
        $allDbs = $pdo_diag->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
        $target = defined('DB_NAME') ? DB_NAME : '';
        $db_found = in_array($target, $allDbs, true);

        if ($db_found) {
            check("Database '$target' esiste", true, 'OK');
        } else {
            // Cerca varianti tipiche
            $variants = array_filter($allDbs, function($n) {
                return preg_match('~portal|certv|cert_v~i', $n);
            });
            $db_variants = array_values($variants);
            $hint = $db_variants
                ? "Database trovati con nome simile: " . implode(', ', $db_variants) . ". Aggiornare DB_NAME in Config.php"
                : 'Nessun database compatibile. Importare il dump SQL o creare il database manualmente.';
            check("Database '$target'", false,
                  "Non esiste sul server. Database disponibili: " . implode(', ', $allDbs),
                  $hint);
        }
    } catch (Throwable $e) {
        check('SHOW DATABASES', false, $e->getMessage(),
              'Permesso negato. L\'utente DB_USER deve avere il privilegio SHOW DATABASES.');
    }
}

// ── Step 4: Tabella users ────────────────────────────────────────────
$pdo_db = null;
if ($db_found) {
    try {
        $pdo_db = new PDO(
            "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $tables = $pdo_db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $cnt = count($tables);
        check("Connessione a database '" . DB_NAME . "'", true, "$cnt tabelle trovate");

        $hasUsers = in_array('users', $tables, true);
        check("Tabella 'users'", $hasUsers,
              $hasUsers ? 'OK' : 'NON ESISTE',
              $hasUsers ? '' : 'Importare il dump SQL del backup nel database corretto.');

        if ($hasUsers) {
            // Schema columns
            $cols = $pdo_db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
            $needed = ['id','email','password_hash','role_id','status'];
            $missing = array_diff($needed, $cols);
            check('Schema users', empty($missing),
                  empty($missing) ? 'Colonne richieste presenti' : 'Mancano: ' . implode(', ', $missing),
                  empty($missing) ? '' : 'Eseguire db_upgrade.php per allineare lo schema.');

            // Conteggio utenti
            $tot = (int)$pdo_db->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $admins = (int)$pdo_db->query("SELECT COUNT(*) FROM users WHERE role_id = 1 AND status = 'active'")->fetchColumn();
            check('Utenti totali', $tot > 0,
                  "$tot utenti totali, $admins Super Admin attivi",
                  $admins === 0 ? 'Nessun Super Admin attivo. Usare il reset password sotto.' : '');

            // Elenco admin per email (utile per il login)
            $adminList = $pdo_db->query(
                "SELECT email, status FROM users WHERE role_id IN (1,2) ORDER BY role_id, email"
            )->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        check('Accesso database', false, $e->getMessage());
    }
}

// ── Step 5: Reset password admin (POST handler) ──────────────────────
$reset_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_admin']) && $pdo_db) {
    $email = trim($_POST['reset_email'] ?? '');
    $newPass = $_POST['reset_password'] ?? '';
    if (!$email || strlen($newPass) < 8) {
        $reset_msg = '<div class="msg err">Email e password (min 8 caratteri) obbligatori.</div>';
    } else {
        $s = $pdo_db->prepare("SELECT id, role_id FROM users WHERE email = ? LIMIT 1");
        $s->execute([$email]);
        $u = $s->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            $reset_msg = '<div class="msg err">Utente non trovato: ' . htmlspecialchars($email) . '</div>';
        } else {
            $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 10]);
            $pdo_db->prepare("UPDATE users SET password_hash = ?, status = 'active' WHERE id = ?")
                   ->execute([$hash, $u['id']]);
            $reset_msg = '<div class="msg ok">&#10003; Password aggiornata per <strong>' . htmlspecialchars($email) .
                         '</strong> (ruolo ' . (int)$u['role_id'] . '). Ora puoi fare login.</div>';
        }
    }
}

// ── Step 6: Sessione / Cookie ────────────────────────────────────────
$session_name = 'unknown';
$session_cookies = [];
if (file_exists(__DIR__ . '/app/Session.php')) {
    $src = file_get_contents(__DIR__ . '/app/Session.php');
    if (preg_match('~COOKIE_NAME\s*=\s*[\'"]([^\'"]+)[\'"]~', $src, $m)) {
        $session_name = $m[1];
    }
}
foreach ($_COOKIE as $k => $v) {
    if (preg_match('~(sid|session|sess|certV|portal)~i', $k)) {
        $session_cookies[] = $k;
    }
}
check('Cookie sessione browser', true,
      'Cookie session attesi (Session.php): ' . $session_name .
      '. Cookie nel browser: ' . (empty($session_cookies) ? '(nessuno)' : implode(', ', $session_cookies)),
      'Se il login dopo essere fatto rilancia subito al login, eliminare i cookie dal browser per http://localhost o l\'IP del server (devtools → Application → Cookies → Clear).');

// ── Step 7: Permessi filesystem ──────────────────────────────────────
foreach (['uploads', 'uploads/.ratelimit', 'tmp', 'logs'] as $dir) {
    $p = __DIR__ . '/' . $dir;
    if (!is_dir($p)) {
        @mkdir($p, 0775, true);
    }
    if (is_dir($p)) {
        $writable = is_writable($p);
        check("Cartella $dir/", $writable,
              $writable ? 'Scrivibile' : 'NON scrivibile',
              $writable ? '' : "Su Windows, click destro su $p → Proprietà → Sicurezza → Modifica → IIS_IUSRS/Everyone → Modifica: SI.");
    }
}

// ── Step 8: Versione PHP ─────────────────────────────────────────────
check('Versione PHP', version_compare(PHP_VERSION, '8.0', '>='),
      'PHP ' . PHP_VERSION,
      version_compare(PHP_VERSION, '8.0', '>=') ? '' : 'Richiesto PHP 8.0+');

// Estensioni PHP
foreach (['pdo_mysql','mbstring','openssl','curl','zip','json'] as $ext) {
    check("Estensione $ext", extension_loaded($ext),
          extension_loaded($ext) ? 'Caricata' : 'NON caricata',
          extension_loaded($ext) ? '' : "Abilitare extension=$ext in php.ini e riavviare Apache.");
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>PortalManager — Diagnostica</title>
<style>
  body { font-family: -apple-system, Segoe UI, sans-serif; background:#f1f5f9; color:#0f172a; margin:0; padding:30px }
  .wrap { max-width:1000px; margin:0 auto }
  h1 { font-size:22px; margin:0 0 6px; color:#0a66c2 }
  h1 .v { font-size:12px; background:#0a66c2; color:#fff; padding:3px 10px; border-radius:20px; vertical-align:middle; margin-left:8px }
  .sub { color:#64748b; font-size:13px; margin-bottom:20px }
  .panel { background:#fff; border-radius:10px; box-shadow:0 2px 6px rgba(0,0,0,.06); padding:18px; margin-bottom:14px }
  .panel h2 { margin:0 0 12px; font-size:15px; color:#1e3a8a }
  table { width:100%; border-collapse:collapse; font-size:13px }
  th, td { padding:9px 10px; border-bottom:1px solid #e2e8f0; text-align:left; vertical-align:top }
  th { background:#f8fafc; color:#64748b; font-size:11px; text-transform:uppercase; font-weight:700 }
  .ok { color:#166534 }
  .err { color:#991b1b }
  .check-pill { display:inline-block; padding:2px 9px; border-radius:20px; font-size:10px; font-weight:800; text-transform:uppercase }
  .check-pill.ok { background:#dcfce7; color:#166534 }
  .check-pill.err { background:#fee2e2; color:#991b1b }
  code { background:#f1f5f9; padding:1px 6px; border-radius:3px; font-size:12px }
  .suggestion { background:#fef3c7; border-left:3px solid #f59e0b; padding:6px 10px; margin-top:6px; font-size:11px; color:#78350f; border-radius:0 6px 6px 0 }
  .msg { padding:10px 14px; border-radius:8px; margin-bottom:12px; font-size:13px }
  .msg.ok { background:#dcfce7; color:#166534; border-left:4px solid #16a34a }
  .msg.err { background:#fee2e2; color:#991b1b; border-left:4px solid #dc2626 }
  form { background:#fff8e6; padding:14px; border-radius:8px; border:1px solid #fde68a }
  input[type=text], input[type=password] { width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; margin-bottom:8px }
  button { background:#dc2626; color:#fff; border:0; padding:9px 16px; border-radius:6px; font-weight:700; cursor:pointer; font-size:13px }
  button:hover { background:#991b1b }
  .delete-warn { background:#fee2e2; border:1px solid #fecaca; padding:14px; border-radius:8px; color:#991b1b; font-size:13px; margin-top:20px }
</style>
</head>
<body>
<div class="wrap">

  <h1>PortalManager — Diagnostica server <span class="v">diag.php</span></h1>
  <div class="sub">Strumento standalone per identificare problemi di login dopo trasferimento del portale su nuovo server.</div>

  <!-- ─── Check ─── -->
  <div class="panel">
    <h2>Controlli di sistema</h2>
    <table>
      <thead>
        <tr><th style="width:30px"></th><th>Verifica</th><th>Stato</th></tr>
      </thead>
      <tbody>
        <?php foreach ($results as $r): ?>
        <tr>
          <td>
            <span class="check-pill <?= $r['ok'] ? 'ok' : 'err' ?>">
              <?= $r['ok'] ? 'OK' : 'KO' ?>
            </span>
          </td>
          <td>
            <strong><?= htmlspecialchars($r['name']) ?></strong>
            <?php if ($r['detail']): ?>
              <div style="color:#64748b;font-size:12px;margin-top:2px"><?= htmlspecialchars($r['detail']) ?></div>
            <?php endif; ?>
            <?php if (!$r['ok'] && $r['suggestion']): ?>
              <div class="suggestion"><strong>Suggerimento:</strong> <?= htmlspecialchars($r['suggestion']) ?></div>
            <?php endif; ?>
          </td>
          <td style="color:<?= $r['ok'] ? '#166534' : '#991b1b' ?>;font-weight:700">
            <?= $r['ok'] ? '&#10003;' : '&#10007;' ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if (!empty($db_variants)): ?>
  <div class="panel" style="background:#fef3c7;border:1px solid #fde68a">
    <h2 style="color:#92400e">&#9888; Database trovati con nome simile</h2>
    <p style="font-size:13px;color:#78350f;margin:0 0 10px">Il portale cerca <code><?= htmlspecialchars(DB_NAME) ?></code> ma sul server esistono:</p>
    <ul style="font-size:13px;color:#78350f">
      <?php foreach ($db_variants as $v): ?>
        <li><code><?= htmlspecialchars($v) ?></code></li>
      <?php endforeach; ?>
    </ul>
    <p style="font-size:12px;color:#78350f;margin-top:10px">Aggiorna <code>DB_NAME</code> in <code>Config.php</code> con uno dei nomi sopra (probabilmente quello senza errori di battitura).</p>
  </div>
  <?php endif; ?>

  <!-- ─── Reset password admin ─── -->
  <?php if ($pdo_db): ?>
  <div class="panel">
    <h2>Reset password Super Admin</h2>
    <p style="font-size:13px;color:#64748b;margin-bottom:12px">
      Se l'errore "credenziali non valide" persiste anche dopo aver verificato il database, è possibile che la sessione precedente abbia invalidato la password
      (o il backup importato ha un hash dalla vecchia installazione). Resetta la password qui sotto.
    </p>
    <?= $reset_msg ?>

    <?php if (!empty($adminList)): ?>
      <div style="margin-bottom:12px;font-size:12px;color:#475569">
        <strong>Account amministrativi nel DB:</strong>
        <ul style="margin:6px 0 0 18px">
          <?php foreach ($adminList as $a): ?>
            <li><code><?= htmlspecialchars($a['email']) ?></code>
                <span style="color:<?= $a['status']==='active'?'#166534':'#991b1b' ?>">(<?= $a['status'] ?>)</span></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="POST">
      <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;color:#475569;margin-bottom:4px">Email account</label>
      <input type="text" name="reset_email" required placeholder="admin@example.com" autocomplete="off">

      <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;color:#475569;margin-bottom:4px">Nuova password (min 8 caratteri)</label>
      <input type="text" name="reset_password" required minlength="8" placeholder="PortalManager2026!" autocomplete="off">

      <input type="hidden" name="reset_admin" value="1">
      <button type="submit">&#9888; Reset password</button>
      <span style="font-size:11px;color:#92400e;margin-left:10px">Imposta anche <code>status='active'</code></span>
    </form>
  </div>
  <?php endif; ?>

  <!-- ─── Pulisci sessioni stantie ─── -->
  <div class="panel">
    <h2>Pulizia sessioni / cookie</h2>
    <p style="font-size:13px;color:#64748b">
      Se sul vecchio server eri loggato, il browser potrebbe avere cookie di sessione obsoleti che invalidano il login sul nuovo server.
      Esegui questi passaggi nel browser:
    </p>
    <ol style="font-size:13px;color:#475569;line-height:1.7">
      <li>Apri DevTools (F12) → tab <strong>Application</strong> (Chrome/Edge) o <strong>Storage</strong> (Firefox)</li>
      <li>Cookies → seleziona l'URL del portale (<code>http://localhost/portalmanager</code> o IP del server)</li>
      <li>Elimina tutti i cookie, in particolare quelli con nome <code><?= htmlspecialchars($session_name) ?></code></li>
      <li>Ricarica la pagina di login</li>
    </ol>
  </div>

  <div class="delete-warn">
    <strong>&#9888; Importante:</strong> dopo aver completato la diagnosi, <strong>elimina questo file</strong> (<code>diag.php</code>) dal server.
    Non lasciarlo accessibile in produzione: contiene azioni amministrative non protette.
  </div>

</div>
</body>
</html>
