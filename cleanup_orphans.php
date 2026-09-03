<?php
/**
 * certV — cleanup_orphans.php (v2 — whitelist v5)
 *
 * Rimuove i file orfani con backslash nel nome creati da estrazioni zip
 * mal fatte. Whitelist estesa per supportare i moduli v5.00.00:
 * PositionHistory, TemplateVersioning, XlsxWriter, ecc.
 *
 * SAFEGUARD multipli:
 *   1. Solo Super Admin
 *   2. Whitelist esplicita: solo file con prefisso noto
 *   3. Verifica: la versione "buona" deve esistere in app/ o sql/
 *   4. Backup automatico in /uploads/.cleanup_backup_<timestamp>/
 *   5. Modalità preview per dry-run
 */

define('CSRF_SKIP', true);
require_once __DIR__ . '/access_control.php';

if ((int)($_SESSION['role_id'] ?? 99) !== 1) {
    http_response_code(403);
    die('Solo Super Admin.');
}

$root    = __DIR__;
$preview = isset($_GET['preview']);
$apply   = isset($_GET['apply']);

// Whitelist file orfani noti (prefisso → file consentiti)
$WHITELIST = [
    'app\\' => [
        // Moduli base v4
        'Csrf.php', 'EmailOtp.php', 'Env.php', 'RateLimiter.php',
        'RecoveryCodes.php', 'Router.php', 'Security.php',
        'Session.php', 'Totp.php', 'TwoFactor.php',
        'UrlHelper.php', 'XlsxWriter.php', 'bootstrap.php',
        // Moduli v5
        'PositionHistory.php', 'TemplateVersioning.php',
    ],
    'sql\\' => [
        'migration_2fa.sql',
        'migration_2fa_v2.sql',
        'migration_2fa_admin.sql',
        'migration_v5.sql',
        'migration_v5_01.sql',
    ],
    'docs\\' => ['*'],
];

// Cartelle annidate da rimuovere completamente (es. app\new\*)
$REMOVE_NESTED = [
    'app\\new\\',  // copie di backup di vecchie release
];

$report = [];
$total_removed = 0;
$total_skipped = 0;
$total_errors  = 0;

if ($preview || $apply) {
    if ($apply) {
        $bakDir = $root . '/uploads/.cleanup_backup_' . date('Ymd_His');
        if (!is_dir($bakDir)) @mkdir($bakDir, 0700, true);
    }

    foreach (scandir($root) as $name) {
        if ($name === '.' || $name === '..') continue;
        $full = $root . DIRECTORY_SEPARATOR . $name;
        if (!is_file($full)) continue;
        if (strpos($name, '\\') === false) continue;

        // Caso speciale: file in cartella annidata da rimuovere completamente
        $is_nested = false;
        foreach ($REMOVE_NESTED as $nested) {
            if (strpos($name, $nested) === 0) {
                $is_nested = true;
                break;
            }
        }

        if ($is_nested) {
            $action_taken = '';
            if ($apply) {
                @copy($full, $bakDir . '/' . str_replace('\\', '__', $name));
                if (@unlink($full)) {
                    $action_taken = 'rimosso (annidato)';
                    $total_removed++;
                } else {
                    $action_taken = 'errore_rimozione';
                    $total_errors++;
                }
            } else {
                $action_taken = 'da_rimuovere (cartella annidata)';
            }
            $report[] = [
                'name' => $name, 'size' => filesize($full),
                'target' => '(da eliminare)', 'real_ok' => null,
                'action' => $action_taken,
            ];
            continue;
        }

        $matched = false;
        foreach ($WHITELIST as $prefix => $allowed_files) {
            if (strpos($name, $prefix) !== 0) continue;
            $clean = substr($name, strlen($prefix));

            if (in_array('*', $allowed_files, true) || in_array($clean, $allowed_files, true)) {
                $matched = true;
                $target_dir = rtrim($prefix, '\\');
                $real_target = $root . '/' . $target_dir . '/' . $clean;
                $real_target_exists = file_exists($real_target);

                $action_taken = '';
                if ($real_target_exists) {
                    if ($apply) {
                        @copy($full, $bakDir . '/' . str_replace('\\', '__', $name));
                        if (@unlink($full)) {
                            $action_taken = 'rimosso';
                            $total_removed++;
                        } else {
                            $action_taken = 'errore_rimozione';
                            $total_errors++;
                        }
                    } else {
                        $action_taken = 'da_rimuovere (versione corretta in ' . $target_dir . '/' . $clean . ')';
                    }
                } else {
                    // L'orfano potrebbe essere l'unica copia. Lo "promuoviamo" spostandolo.
                    if ($apply) {
                        if (!is_dir($root . '/' . $target_dir)) {
                            @mkdir($root . '/' . $target_dir, 0755, true);
                        }
                        if (@rename($full, $real_target)) {
                            $action_taken = 'spostato in ' . $target_dir . '/' . $clean;
                            $total_removed++;
                        } else {
                            $action_taken = 'errore_spostamento';
                            $total_errors++;
                        }
                    } else {
                        $action_taken = 'da_spostare (in ' . $target_dir . '/' . $clean . ')';
                    }
                }

                $report[] = [
                    'name' => $name, 'size' => filesize($full),
                    'target' => $target_dir . '/' . $clean,
                    'real_ok' => $real_target_exists,
                    'action' => $action_taken,
                ];
                break;
            }
        }
        if (!$matched) {
            $report[] = [
                'name' => $name, 'size' => filesize($full),
                'target' => '—', 'real_ok' => false,
                'action' => 'SKIP — non in whitelist',
            ];
            $total_skipped++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Cleanup orfani — certV</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#f1f5f9;padding:32px;color:#1e293b}
.container{max-width:1000px;margin:0 auto;background:#fff;padding:32px;border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05)}
h1{font-size:24px;margin-bottom:6px}
.sub{color:#64748b;font-size:13px;margin-bottom:24px}
.actions{display:flex;gap:10px;margin-bottom:24px;flex-wrap:wrap}
.btn{padding:10px 18px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:8px;cursor:pointer;border:none;font-family:inherit}
.btn.preview{background:#0ea5e9;color:#fff}
.btn.apply{background:#ef4444;color:#fff}
.btn:hover{filter:brightness(1.08)}
.summary{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px}
.summary-card{padding:16px;border-radius:8px;border-left:4px solid}
.summary-card.removed{background:#f0fdf4;border-color:#10b981}
.summary-card.skipped{background:#fffbeb;border-color:#f59e0b}
.summary-card.errors{background:#fef2f2;border-color:#ef4444}
.summary-card .lbl{font-size:11px;text-transform:uppercase;color:#64748b;font-weight:700}
.summary-card .val{font-size:28px;font-weight:800;margin-top:4px}
table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:24px}
th,td{padding:8px 12px;text-align:left;border-bottom:1px solid #e2e8f0}
th{background:#f8fafc;font-size:11px;text-transform:uppercase;color:#64748b;font-weight:700}
td.file{font-family:Consolas,monospace;font-size:12px}
.warn{background:#fffbeb;color:#92400e;border-left:4px solid #f59e0b;padding:14px 18px;border-radius:8px;margin-bottom:18px;font-size:13px;line-height:1.5}
.ok{background:#f0fdf4;color:#065f46;border-left:4px solid #10b981;padding:14px 18px;border-radius:8px;margin-bottom:18px;font-size:13px}
.empty{text-align:center;padding:30px;color:#64748b}
code{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:12px}
.act-rimosso{color:#065f46;font-weight:700}
.act-spostato{color:#0369a1;font-weight:700}
.act-da_rimuovere,.act-da_spostare{color:#92400e;font-weight:700}
.act-SKIP{color:#94a3b8}
.act-errore_rimozione,.act-errore_spostamento{color:#991b1b;font-weight:700}
</style>
</head>
<body>
<div class="container">
  <h1>🧹 Cleanup file orfani v2</h1>
  <p class="sub">Sistema i file con <code>\</code> nel nome creati da estrazioni zip mal fatte. Whitelist estesa con i moduli v5.</p>

  <?php if (!$preview && !$apply): ?>
    <div class="warn">
      <strong>Scegli un'azione:</strong><br><br>
      <a class="btn preview" href="?preview=1">👁 Anteprima</a>
      <a class="btn apply" href="?apply=1" onclick="return confirm('Procedere con la pulizia?\n\nVerrà fatto un backup automatico in uploads/.cleanup_backup_*/')">🧹 Applica</a>
    </div>
  <?php endif; ?>

  <?php if ($preview || $apply): ?>
    <div class="summary">
      <div class="summary-card removed">
        <div class="lbl"><?= $apply ? 'Sistemati' : 'Da sistemare' ?></div>
        <div class="val" style="color:#10b981"><?= $apply ? $total_removed : count(array_filter($report, fn($r) => str_contains($r['action'], 'da_'))) ?></div>
      </div>
      <div class="summary-card skipped">
        <div class="lbl">Saltati</div>
        <div class="val" style="color:#f59e0b"><?= $total_skipped ?></div>
      </div>
      <div class="summary-card errors">
        <div class="lbl">Errori</div>
        <div class="val" style="color:#ef4444"><?= $total_errors ?></div>
      </div>
    </div>

    <?php if ($apply && $total_removed > 0): ?>
      <div class="ok">
        ✅ <strong><?= $total_removed ?> file orfani sistemati.</strong> Backup in <code>uploads/.cleanup_backup_*/</code>
      </div>
    <?php elseif ($preview && !empty($report)): ?>
      <div class="warn">👁 <strong>Anteprima.</strong> Nessun file modificato. Click "Applica" per procedere.</div>
    <?php elseif (empty($report)): ?>
      <div class="ok">✅ Nessun file orfano trovato. Il portale è pulito.</div>
    <?php endif; ?>

    <?php if (!empty($report)): ?>
      <table>
        <thead>
          <tr>
            <th>File orfano</th>
            <th style="text-align:right">Size</th>
            <th>Target corretto</th>
            <th>Azione</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($report as $r): ?>
            <tr>
              <td class="file"><?= htmlspecialchars($r['name']) ?></td>
              <td style="text-align:right;font-family:monospace;font-size:11px"><?= number_format($r['size']) ?></td>
              <td class="file" style="color:#64748b"><?= htmlspecialchars($r['target']) ?></td>
              <td>
                <?php
                  $action_class = strtok($r['action'], ' ');
                  echo '<span class="act-' . htmlspecialchars($action_class) . '">' . htmlspecialchars($r['action']) . '</span>';
                ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endif; ?>

  <div class="warn">
    ⚠ <strong>Importante:</strong> dopo aver verificato il funzionamento, <strong>elimina questo file</strong> (<code>cleanup_orphans.php</code>) dal server.
  </div>

  <p style="text-align:center;color:#94a3b8;font-size:12px;margin-top:30px">
    <a href="index.php" style="color:#64748b">← Dashboard</a>
  </p>
</div>
</body>
</html>
