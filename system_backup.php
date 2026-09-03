<?php
/**
 * certV 5.9.0 — system_backup.php
 *
 * Backup on-demand del portale (file applicazione + dump SQL).
 *
 * Caratteristiche:
 *  - Selezione di cosa includere: file, SQL, configurazione (.env.php opzionale)
 *  - Generazione di un singolo ZIP scaricabile
 *  - Dump SQL via PDO (no dipendenza da mysqldump)
 *  - Scelta del percorso destinazione lato browser:
 *      • File System Access API (Chrome/Edge moderni) → showSaveFilePicker()
 *      • Fallback: download standard, il browser usa la cartella Download
 *        oppure chiede percorso se l'utente ha attivato l'opzione nelle preferenze
 *  - Rate limit: 1 backup ogni 60s per utente
 *  - Audit: ogni richiesta loggata su app_logs
 *
 * Sicurezza:
 *  - Solo Super Admin (ruolo 1)
 *  - .env.php escluso di default (contiene segreti)
 *  - File temporaneo rimosso al termine del download (registered_shutdown)
 *  - Path traversal neutralizzato (uso di realpath + check prefix)
 */
require_once('access_control.php');

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
if ($u_role !== 1) {
    header('Location: ' . (function_exists('url') ? url('unauthorized') : 'unauthorized.php'));
    exit();
}

$ROOT = realpath(__DIR__);

// ─── HANDLE POST: GENERAZIONE BACKUP ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backup') {
    try {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Estensione ZipArchive non disponibile su questo server.');
        }

        // Rate limit (60s)
        $rate_dir = __DIR__ . '/uploads/.ratelimit';
        if (!is_dir($rate_dir)) @mkdir($rate_dir, 0775, true);
        $rate_file = $rate_dir . '/backup_user_' . $u_id . '.lock';
        if (is_file($rate_file) && (time() - filemtime($rate_file)) < 60) {
            $wait = 60 - (time() - filemtime($rate_file));
            throw new RuntimeException("Troppo frequente. Attendere $wait secondi prima di un nuovo backup.");
        }
        @touch($rate_file);

        // Opzioni
        $opt_files = !empty($_POST['inc_files']);
        $opt_sql   = !empty($_POST['inc_sql']);
        $opt_env   = !empty($_POST['inc_env']);
        if (!$opt_files && !$opt_sql) {
            throw new RuntimeException('Selezionare almeno una sorgente (file o database).');
        }

        $ts = date('Ymd_His');
        $tmpFile = sys_get_temp_dir() . '/PortalManager_full_' . $u_id . '_' . $ts . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossibile creare il file temporaneo.');
        }

        // Manifest
        $manifest = [
            'generated_at'  => date('c'),
            'generated_by'  => $u_id,
            'portal_root'   => $ROOT,
            'php_version'   => PHP_VERSION,
            'schema_version'=> $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'schema_version'")->fetchColumn() ?: '?',
            'includes'      => array_filter([
                'files' => $opt_files ? 'yes' : null,
                'sql'   => $opt_sql ? 'yes' : null,
                'env'   => $opt_env ? 'yes' : null,
            ]),
        ];
        $zip->addFromString('MANIFEST.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // ── Dump SQL ───────────────────────────────────────────────────
        if ($opt_sql) {
            $sqlFile = sys_get_temp_dir() . '/PortalManager_db_' . $u_id . '_' . $ts . '.sql';
            $h = fopen($sqlFile, 'wb');
            if (!$h) throw new RuntimeException('Impossibile creare dump SQL temporaneo.');

            fwrite($h, "-- ════════════════════════════════════════════════════════════\n");
            fwrite($h, "-- certV — Database backup\n");
            fwrite($h, "-- Generato: " . date('Y-m-d H:i:s') . "\n");
            fwrite($h, "-- Schema:   " . $manifest['schema_version'] . "\n");
            fwrite($h, "-- ════════════════════════════════════════════════════════════\n\n");
            fwrite($h, "/*!40101 SET NAMES utf8mb4 */;\n");
            fwrite($h, "/*!40101 SET CHARACTER_SET_CLIENT=utf8mb4 */;\n");
            fwrite($h, "SET FOREIGN_KEY_CHECKS = 0;\n");
            fwrite($h, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

            // Lista tabelle
            $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")
                          ->fetchAll(PDO::FETCH_COLUMN, 0);
            foreach ($tables as $t) {
                $tEsc = '`' . str_replace('`', '``', $t) . '`';
                fwrite($h, "-- ─────────────────────────────\n");
                fwrite($h, "-- Tabella: $t\n");
                fwrite($h, "-- ─────────────────────────────\n");
                fwrite($h, "DROP TABLE IF EXISTS $tEsc;\n");
                $cr = $pdo->query("SHOW CREATE TABLE $tEsc")->fetch(PDO::FETCH_ASSOC);
                fwrite($h, $cr['Create Table'] . ";\n\n");

                // Dati: stream a chunk per evitare OOM
                $stmt = $pdo->query("SELECT * FROM $tEsc");
                $cnt = 0;
                $batch = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $vals = [];
                    foreach ($row as $v) {
                        if ($v === null)        $vals[] = 'NULL';
                        elseif (is_int($v) || is_float($v)) $vals[] = (string)$v;
                        else                    $vals[] = $pdo->quote((string)$v);
                    }
                    $batch[] = '(' . implode(',', $vals) . ')';
                    $cnt++;
                    if (count($batch) >= 100) {
                        fwrite($h, "INSERT INTO $tEsc VALUES\n" . implode(",\n", $batch) . ";\n");
                        $batch = [];
                    }
                }
                if ($batch) {
                    fwrite($h, "INSERT INTO $tEsc VALUES\n" . implode(",\n", $batch) . ";\n");
                }
                fwrite($h, "\n");
            }
            fwrite($h, "SET FOREIGN_KEY_CHECKS = 1;\n");
            fclose($h);

            $zip->addFile($sqlFile, 'database.sql');
            // Nota: il file resta su disco fino al close del zip
        }

        // ── Files applicazione ─────────────────────────────────────────
        if ($opt_files) {
            $blacklist_prefix = [
                'uploads/.ratelimit',
                'uploads/cache',
                'logs',
                'tmp',
                '.git',
                '.svn',
                'node_modules',
                'backups',          // backup esistenti del db_upgrade
                '.idea',
                '.vscode',
            ];
            $blacklist_files = [
                'installer_disabled.flag',
                '.htpasswd',
            ];
            // .env.php dipende dall'opzione
            if (!$opt_env) $blacklist_files[] = '.env.php';

            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($ROOT, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            $files_added = 0;
            $total_size  = 0;
            foreach ($iter as $f) {
                if (!$f->isFile()) continue;
                $real = $f->getRealPath();
                if ($real === false) continue;
                // Normalizza rispetto a ROOT
                if (strpos($real, $ROOT . DIRECTORY_SEPARATOR) !== 0) continue;
                $rel = substr($real, strlen($ROOT) + 1);
                $rel = str_replace('\\', '/', $rel);

                // Blacklist prefix
                $skip = false;
                foreach ($blacklist_prefix as $bp) {
                    if (strpos($rel, $bp . '/') === 0 || $rel === $bp) { $skip = true; break; }
                }
                if ($skip) continue;
                if (in_array(basename($rel), $blacklist_files, true)) continue;

                // Limite singolo file (sicurezza: max 100 MB per file)
                $sz = $f->getSize();
                if ($sz > 100 * 1024 * 1024) continue;

                $zip->addFile($real, 'files/' . $rel);
                $files_added++;
                $total_size += $sz;
            }
        }

        $zip->close();

        // Pulizia file SQL temporaneo (lo zip lo ha già copiato in memoria)
        if (!empty($sqlFile) && is_file($sqlFile)) @unlink($sqlFile);

        $size = filesize($tmpFile);

        // Audit
        if (function_exists('write_log')) {
            $parts = [];
            if ($opt_files) $parts[] = ($files_added ?? 0) . ' file';
            if ($opt_sql)   $parts[] = count($tables ?? []) . ' tabelle';
            if ($opt_env)   $parts[] = '.env.php incluso';
            write_log('Backup', 'success',
                'Backup generato (' . round($size/1024/1024, 2) . ' MB) — ' . implode(', ', $parts),
                $u_id);
        }

        // Cleanup file zip al termine della richiesta
        register_shutdown_function(function() use ($tmpFile) {
            if (is_file($tmpFile)) @unlink($tmpFile);
        });

        // Stream del file al browser
        $filename = "PortalManager_full_{$ts}.zip";
        // Pulisci ogni eventuale buffer per evitare corruzione del file
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $size);
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
        readfile($tmpFile);
        exit();

    } catch (Throwable $e) {
        if (!empty($tmpFile) && is_file($tmpFile)) @unlink($tmpFile);
        if (!empty($sqlFile) && is_file($sqlFile)) @unlink($sqlFile);
        if (function_exists('write_log')) {
            write_log('Backup', 'error', 'Backup fallito: ' . $e->getMessage(), $u_id);
        }
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'><i class='fa-solid fa-triangle-exclamation'></i> " . h($e->getMessage()) . "</div>";
        $qs = !empty($_GET['r']) ? '?r=' . urlencode($_GET['r']) : '';
        header('Location: system_backup.php' . $qs);
        exit();
    }
}

// ─── DATA: stima dimensioni preventiva ──────────────────────────────────
function dirSize(string $dir, array $blacklist_prefix = []): array {
    $size = 0; $count = 0;
    if (!is_dir($dir)) return [$size, $count];
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    $rootLen = strlen(realpath($dir)) + 1;
    foreach ($iter as $f) {
        if (!$f->isFile()) continue;
        $rel = str_replace('\\', '/', substr($f->getRealPath(), $rootLen));
        $skip = false;
        foreach ($blacklist_prefix as $bp) {
            if (strpos($rel, $bp . '/') === 0 || $rel === $bp) { $skip = true; break; }
        }
        if ($skip) continue;
        $size += $f->getSize();
        $count++;
    }
    return [$size, $count];
}
[$est_files_size, $est_files_count] = dirSize($ROOT, [
    'uploads/.ratelimit', 'uploads/cache', 'logs', 'tmp', '.git', 'backups', 'node_modules'
]);

// Stima dump SQL: somma data_length + index_length da information_schema (ottimistica)
$db = $pdo->query("SELECT DATABASE()")->fetchColumn();
$sql_size_est = (int)$pdo->query(
    "SELECT COALESCE(SUM(data_length + index_length), 0)
       FROM information_schema.tables
      WHERE table_schema = " . $pdo->quote($db) . " AND table_type = 'BASE TABLE'"
)->fetchColumn();
$tables_count = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = " . $pdo->quote($db) . " AND table_type = 'BASE TABLE'"
)->fetchColumn();

function fmtSize(int $b): string {
    if ($b < 1024) return $b . ' B';
    if ($b < 1024*1024) return round($b/1024, 1) . ' KB';
    if ($b < 1024*1024*1024) return round($b/1024/1024, 1) . ' MB';
    return round($b/1024/1024/1024, 2) . ' GB';
}

require_once('header.php');
?>

<style>
.bk-card { background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); padding:20px; margin-bottom:14px; }
.bk-opt { display:flex; align-items:flex-start; gap:12px; padding:12px; border:2px solid var(--border); border-radius:10px; margin-bottom:10px; cursor:pointer; transition:all .15s; }
.bk-opt:hover { background:#f8fafc; }
.bk-opt input[type=checkbox] { margin-top:3px; transform:scale(1.3); cursor:pointer; }
.bk-opt.warn { border-color:#fde68a; background:#fffbeb; }
.bk-opt.warn:hover { background:#fef3c7; }
.bk-meta { font-size:11px; color:var(--muted); margin-top:3px; }
.bk-stat { display:inline-block; background:#eff6ff; color:#1e40af; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700; margin-left:6px; }
.path-picker { background:linear-gradient(135deg,#eff6ff,#f0f9ff); border:2px dashed #93c5fd; border-radius:10px; padding:14px; margin-top:10px; }
</style>

<div style="max-width:880px;margin:0 auto">
  <div style="margin-bottom:18px">
    <h1 style="font-size:22px;font-weight:800;margin-bottom:4px">
      <i class="fa-solid fa-cloud-arrow-down" style="color:#0ea5e9"></i> Backup applicazione e database
    </h1>
    <div style="color:var(--muted);font-size:13px">Salva un archivio completo (file portale + dump SQL) sul tuo computer.</div>
  </div>

  <?php if (!empty($_SESSION['flash_msg'])): ?><?= $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); ?><?php endif; ?>

  <div class="bk-card">
    <h3 style="font-size:15px;font-weight:800;margin-bottom:10px"><i class="fa-solid fa-list-check"></i> Cosa includere nel backup</h3>

    <form method="POST" id="bkForm" onsubmit="return submitBackup(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="backup">

      <label class="bk-opt">
        <input type="checkbox" name="inc_files" value="1" checked id="opt_files">
        <div style="flex:1">
          <strong><i class="fa-solid fa-folder-tree" style="color:#10b981"></i> File applicazione</strong>
          <div class="bk-meta">Tutti i file PHP/HTML/JS/CSS del portale, immagini caricate, template.</div>
          <div class="bk-meta">
            <span class="bk-stat"><?= $est_files_count ?> file</span>
            <span class="bk-stat" style="background:#dcfce7;color:#166534">~<?= fmtSize($est_files_size) ?></span>
          </div>
        </div>
      </label>

      <label class="bk-opt">
        <input type="checkbox" name="inc_sql" value="1" checked id="opt_sql">
        <div style="flex:1">
          <strong><i class="fa-solid fa-database" style="color:#0ea5e9"></i> Dump database SQL</strong>
          <div class="bk-meta">Schema + dati di tutte le tabelle MariaDB (importabile via phpMyAdmin).</div>
          <div class="bk-meta">
            <span class="bk-stat"><?= $tables_count ?> tabelle</span>
            <span class="bk-stat" style="background:#dbeafe;color:#1e40af">~<?= fmtSize($sql_size_est) ?> stimati</span>
          </div>
        </div>
      </label>

      <label class="bk-opt warn">
        <input type="checkbox" name="inc_env" value="1" id="opt_env">
        <div style="flex:1">
          <strong style="color:#92400e"><i class="fa-solid fa-key"></i> Configurazione (.env.php)</strong>
          <div class="bk-meta" style="color:#92400e">
            <i class="fa-solid fa-triangle-exclamation"></i> <strong>ATTENZIONE</strong>: contiene credenziali DB e chiavi crittografiche (HMAC slug, sale CSRF).
            Includere solo se il backup è destinato a un canale sicuro per disaster recovery.
          </div>
        </div>
      </label>

      <div class="path-picker">
        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
          <input type="checkbox" id="opt_picker" style="margin-top:2px;transform:scale(1.2)">
          <div>
            <strong><i class="fa-solid fa-folder-open" style="color:#0284c7"></i> Scelta percorso destinazione (browser moderno)</strong>
            <div class="bk-meta" style="color:#075985">
              Se attivata, il browser aprirà una finestra <strong>"Salva con nome…"</strong> per permetterti
              di scegliere cartella e nome file. Richiede Chrome/Edge 86+ o browser compatibili con
              <code>File System Access API</code>. Su Firefox e altri browser, l'opzione è ignorata e
              il file viene scaricato nella cartella Download standard.
            </div>
            <div class="bk-meta" style="color:#075985;margin-top:6px">
              <strong>Alternativa universale</strong>: imposta nel browser
              <em>"Chiedi sempre dove salvare i file prima di scaricarli"</em>
              (Chrome/Edge: <code>Impostazioni → Download</code> · Firefox: <code>Impostazioni → File e applicazioni</code>).
            </div>
          </div>
        </label>
      </div>

      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;padding-top:14px;border-top:1px solid var(--border)">
        <button type="submit" class="btn btn-primary" id="bkBtn">
          <i class="fa-solid fa-cloud-arrow-down"></i> Genera e scarica backup
        </button>
      </div>

      <div id="bkProgress" style="display:none;margin-top:12px;padding:12px;background:#f0f9ff;border-radius:8px;color:#075985;font-size:13px">
        <i class="fa-solid fa-circle-notch fa-spin"></i>
        <span id="bkProgressMsg">Generazione in corso… (può richiedere alcuni minuti per backup grandi)</span>
      </div>
    </form>
  </div>

  <div class="bk-card" style="background:#f8fafc">
    <h4 style="font-size:13px;font-weight:800;color:#475569;margin-bottom:8px">
      <i class="fa-solid fa-circle-info"></i> Note operative
    </h4>
    <ul style="font-size:12px;color:#475569;line-height:1.8;margin-left:18px">
      <li>L'operazione è limitata a <strong>1 backup ogni 60 secondi</strong> per utente.</li>
      <li>Il file ZIP viene creato in una cartella temporanea sul server e <strong>cancellato automaticamente</strong> al termine del download.</li>
      <li>Vengono escluse: <code>uploads/.ratelimit</code>, <code>logs</code>, <code>cache</code>, <code>backups</code>, <code>.git</code>, <code>node_modules</code>.</li>
      <li>Il dump SQL è compatibile con <strong>phpMyAdmin → Importa</strong> per ripristino su nuovo server.</li>
      <li>Per il restore completo: estrai i file in webroot, importa <code>database.sql</code>, riconfigura <code>.env.php</code> con le credenziali del nuovo ambiente.</li>
      <li>Tutte le richieste di backup vengono <strong>tracciate nel log eventi</strong> (<a href="<?= function_exists('url_safe') ? url_safe('app_logs') : 'app_logs.php' ?>">visualizza</a>).</li>
    </ul>
  </div>
</div>

<script>
async function submitBackup(ev) {
  const usePicker = document.getElementById('opt_picker').checked;
  const supportsPicker = typeof window.showSaveFilePicker === 'function';

  // Caso A: vuole picker E browser supporta → fetch + showSaveFilePicker
  if (usePicker && supportsPicker) {
    ev.preventDefault();
    const form = ev.target;
    const btn = document.getElementById('bkBtn');
    const prog = document.getElementById('bkProgress');
    const msg = document.getElementById('bkProgressMsg');
    btn.disabled = true;
    prog.style.display = 'block';
    msg.innerText = 'Generazione in corso sul server…';

    try {
      const ts = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 15);
      const suggestedName = `PortalManager_full_${ts}.zip`;

      // 1. Apri prima il dialog (UX: l'utente sceglie subito dove salvare)
      let handle;
      try {
        handle = await window.showSaveFilePicker({
          suggestedName,
          types: [{ description: 'Archivio backup certV', accept: { 'application/zip': ['.zip'] } }]
        });
      } catch (e) {
        if (e.name === 'AbortError') {
          btn.disabled = false; prog.style.display = 'none';
          return false;
        }
        throw e;
      }

      // 2. Genera backup lato server
      msg.innerText = 'Download in corso…';
      const fd = new FormData(form);
      const resp = await fetch(window.location.pathname + window.location.search, {
        method: 'POST', body: fd, credentials: 'same-origin'
      });
      if (!resp.ok) throw new Error('Errore HTTP ' + resp.status);

      // 3. Scrivi il blob nella destinazione scelta
      const writable = await handle.createWritable();
      const reader = resp.body.getReader();
      const total = parseInt(resp.headers.get('Content-Length') || '0', 10);
      let written = 0;
      while (true) {
        const { value, done } = await reader.read();
        if (done) break;
        await writable.write(value);
        written += value.length;
        if (total) msg.innerText = `Scaricamento: ${(written/1024/1024).toFixed(1)} / ${(total/1024/1024).toFixed(1)} MB`;
      }
      await writable.close();

      msg.innerHTML = '<i class="fa-solid fa-circle-check" style="color:#10b981"></i> Backup salvato correttamente.';
      setTimeout(() => { btn.disabled = false; prog.style.display = 'none'; }, 3500);
      return false;

    } catch (err) {
      msg.innerHTML = '<i class="fa-solid fa-triangle-exclamation" style="color:#ef4444"></i> Errore: ' + err.message;
      btn.disabled = false;
      return false;
    }
  }

  // Caso B: avvisa se ha chiesto picker ma il browser non lo supporta
  if (usePicker && !supportsPicker) {
    if (!confirm('Il tuo browser non supporta la scelta del percorso programmatica. Il file verrà scaricato nella cartella Download. Continuare?')) {
      ev.preventDefault();
      return false;
    }
  }

  // Caso C: download standard via form submit
  const btn = document.getElementById('bkBtn');
  const prog = document.getElementById('bkProgress');
  btn.disabled = true;
  prog.style.display = 'block';
  document.getElementById('bkProgressMsg').innerText = 'Generazione e download in corso…';
  // Riabilita dopo qualche secondo (il submit è già partito)
  setTimeout(() => { btn.disabled = false; prog.style.display = 'none'; }, 5000);
  return true;
}
</script>

<?php require_once('footer.php'); ?>
