<?php
/**
 * certV 4.0 - migrate_links.php
 *
 * Tool one-shot per sostituire nei file PHP i link diretti come
 *   href="brand.php"
 * con
 *   href="<short-php-tag-url-safe-call>"
 *
 * USO:
 *   1) Backup della cartella (zip).
 *   2) Login come Super Admin.
 *   3) Aprire migrate_links.php?preview=1  (dry-run, mostra cosa cambierebbe).
 *   4) Aprire migrate_links.php?apply=1    (applica e crea backup .bak).
 *   5) ELIMINARE questo file dal server dopo l'uso.
 *
 * Protezione:
 *   - Richiede ruolo Super Admin (role_id = 1).
 *   - Non tocca i file core gia' aggiornati (vedi $SKIP).
 *   - Salva backup di ogni file modificato in uploads/.migration_backup/
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/access_control.php';

if ((int)($_SESSION['role_id'] ?? 99) !== 1) {
    http_response_code(403);
    die('Solo Super Admin puo eseguire la migrazione dei link.');
}

$preview = isset($_GET['preview']);
$apply   = isset($_GET['apply']);

// File da NON toccare (gia' aggiornati a mano o non rilevanti)
$SKIP = [
    'Config.php', 'Config.php.dist',
    'access_control.php', 'header.php', 'footer.php', 'functions.php',
    'migrate_links.php', 'r.php', 'db_helpers.php',
    'SmartImport.php', 'SmtpMailer.php', 'CalendarHelper.php',
    'doc_download.php', 'download.php',
    'api_filters.php', 'api_cert_search.php', 'api_cert_history.php', 'api_contract_docs.php',
    'install.php', 'reset_admin.php', 'fix_password.php',
    'db_upgrade.php', 'schema_check_upgrade.php', 'system_update.php',
    'health_check.php', 'cron_notifications.php',
];

$PAGES = Router::PAGES;

$report = [];
$changedTotal = 0;

// Pre-calcolo i template di sostituzione usando concatenazione
// per evitare di scrivere letteralmente "<" + "?php" / "?>" nel sorgente.
$OPEN  = '<' . '?= ';
$CLOSE = ' ?' . '>';

foreach (glob(APP_BASE . '/*.php') as $file) {
    $base = basename($file);
    if (in_array($base, $SKIP, true)) continue;

    $src = file_get_contents($file);
    if ($src === false) continue;
    $orig    = $src;
    $changes = 0;

    // PATTERN 1: href="<page>.php"  (senza query string)
    foreach ($PAGES as $p) {
        $quoted = preg_quote($p, '/');
        $re     = '/href="' . $quoted . '\.php"/';
        $repl   = 'href="' . $OPEN . "url_safe('$p')" . $CLOSE . '"';
        $src    = preg_replace_callback($re, function ($m) use ($repl, &$changes) {
            $changes++;
            return $repl;
        }, $src);
    }

    // PATTERN 2: href="<page>.php?param=$var"  (singolo parametro semplice)
    // Match anche se l'espressione e' del tipo  param=<short-tag>$var<close>
    foreach ($PAGES as $p) {
        $quoted = preg_quote($p, '/');
        $re = '/href="' . $quoted . '\.php\?([a-z_]+)=' .
              preg_quote($OPEN, '/') . '\$([a-zA-Z_][a-zA-Z0-9_]*)' . preg_quote($CLOSE, '/') .
              '"/';
        $src = preg_replace_callback($re, function ($m) use ($p, $OPEN, $CLOSE, &$changes) {
            $key = $m[1];
            $val = $m[2];
            $changes++;
            return 'href="' . $OPEN . "url_safe('$p', ['$key' => \$$val])" . $CLOSE . '"';
        }, $src);
    }

    if ($src !== $orig) {
        $report[$base] = $changes;
        $changedTotal += $changes;
        if ($apply) {
            $bakDir = APP_BASE . '/uploads/.migration_backup';
            if (!is_dir($bakDir)) @mkdir($bakDir, 0700, true);
            @file_put_contents($bakDir . '/' . $base . '.bak', $orig, LOCK_EX);
            file_put_contents($file, $src, LOCK_EX);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Migrazione link - certV 4.0</title>
<style>
body{font-family:system-ui,sans-serif;background:#f1f5f9;padding:32px;color:#1e293b;line-height:1.5}
.box{max-width:820px;margin:0 auto;background:#fff;padding:32px;border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,.06)}
h1{font-size:22px;margin-bottom:8px}
.sub{color:#64748b;font-size:13px;margin-bottom:24px}
.stat{display:flex;gap:16px;margin:20px 0}
.stat>div{flex:1;background:#f8fafc;padding:14px;border-radius:8px;border-left:4px solid #0ea5e9}
.stat .lbl{font-size:11px;text-transform:uppercase;color:#64748b;font-weight:700}
.stat .val{font-size:24px;font-weight:800;margin-top:4px}
table{width:100%;border-collapse:collapse;margin-top:16px;font-size:13px}
th,td{padding:8px 12px;text-align:left;border-bottom:1px solid #e2e8f0}
th{background:#f8fafc;font-size:11px;text-transform:uppercase;color:#64748b}
.btn{display:inline-block;padding:10px 18px;background:#0ea5e9;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;margin-right:8px}
.btn.apply{background:#10b981}
.warn{background:#fffbeb;color:#92400e;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:8px;margin:16px 0;font-size:13px}
.ok{background:#f0fdf4;color:#065f46;border-left:4px solid #10b981;padding:12px 16px;border-radius:8px;margin:16px 0;font-size:13px}
code{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:12px}
</style>
</head>
<body>
<div class="box">
  <h1>Migrazione link - slug opachi</h1>
  <p class="sub">Sostituisce nei file PHP gli href diretti con chiamate a <code>url_safe()</code>.</p>

  <?php if ($apply): ?>
    <div class="ok">
      <strong>Modifiche applicate.</strong>
      Backup salvati in <code>uploads/.migration_backup/</code>.
      <br>Ora elimina questo file dal server.
    </div>
  <?php elseif ($preview): ?>
    <div class="warn">
      <strong>Anteprima.</strong> Nessun file e' stato modificato.
      <br><br>
      <a class="btn apply" href="?apply=1">Applica modifiche</a>
    </div>
  <?php else: ?>
    <div class="warn">
      <strong>Scegli un'azione:</strong><br><br>
      <a class="btn" href="?preview=1">Anteprima (dry-run)</a>
      <a class="btn apply" href="?apply=1">Applica modifiche</a>
    </div>
  <?php endif; ?>

  <?php if ($preview || $apply): ?>
    <div class="stat">
      <div>
        <div class="lbl">File modificati</div>
        <div class="val"><?= count($report) ?></div>
      </div>
      <div>
        <div class="lbl">Link sostituiti</div>
        <div class="val"><?= (int)$changedTotal ?></div>
      </div>
    </div>

    <?php if (empty($report)): ?>
      <div class="ok">Nessun link da migrare trovato.</div>
    <?php else: ?>
      <table>
        <thead><tr><th>File</th><th style="text-align:right">Modifiche</th></tr></thead>
        <tbody>
        <?php foreach ($report as $f => $n): ?>
          <tr>
            <td><code><?= h($f) ?></code></td>
            <td style="text-align:right"><strong><?= (int)$n ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endif; ?>

  <h3 style="margin-top:28px">Note</h3>
  <ul style="margin-left:20px;font-size:13px;color:#475569">
    <li>I link con espressioni complesse (concatenazioni, accessi tipo $x[y][z]) NON vengono modificati automaticamente.</li>
    <li>I file core (access_control, header, login, ecc.) sono gia' aggiornati e vengono saltati.</li>
    <li>I backup originali sono in <code>uploads/.migration_backup/*.bak</code>.</li>
    <li><strong>Importante:</strong> elimina questo file dopo l'uso.</li>
  </ul>
</div>
</body>
</html>
