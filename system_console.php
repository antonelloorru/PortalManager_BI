<?php
/**
 * system_console.php — Console di sistema unificata (v1.7.70)
 *
 * Riunisce in un'unica pagina, a schede, le tre funzioni prima separate:
 *   • Aggiornamento (ZIP)  — ex system_update.php  (analisi + applicazione pacchetti)
 *   • Migrazioni DB        — ex db_upgrade.php      (stato versione + upgrade schema)
 *   • SQL Runner           — ex sql_runner.php      (upload/incolla/esegui script SQL)
 *   • Log                  — visibilità degli eventi app_logs, filtrabili
 *
 * La logica non è duplicata: le funzioni collaudate sono riusate da
 * app/UpdaterCore.php (updater ZIP) e app/SqlConsole.php (tokenizer/classificatore SQL);
 * il motore di upgrade schema è delegato al db_upgrade.php esistente via include mirato.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/UpdaterCore.php');
require_once(__DIR__ . '/app/SqlConsole.php');

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
if ($u_role !== 1) { header('Location: unauthorized.php'); exit(); }

$app_root    = __DIR__;
$backup_dir  = $app_root . '/uploads/backups/';
$temp_dir    = $app_root . '/uploads/_update_temp/';
$sql_dir     = $app_root . '/sql/';
$current_ver = trim(@file_get_contents($app_root . '/VERSION') ?: '?.?');
if (!is_dir($backup_dir)) @mkdir($backup_dir, 0755, true);
if (!is_dir($sql_dir))    @mkdir($sql_dir, 0755, true);

$tab    = $_GET['tab'] ?? 'update';
$msg    = '';
$report = null;
$phase  = 'upload';
$sql_result  = null;
$preview      = null;

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

/**
 * Pianifica la pulizia dei file .sql: raggruppa per "tipo" (famiglia del nome,
 * versione/data rimossa) e per ogni tipo mantiene i $keep file più recenti
 * (ordinamento naturale per versione, poi per data di modifica). Restituisce
 * i gruppi con le liste "keep" e "delete". Nessuna cancellazione qui.
 */
function sc_sql_cleanup_plan(string $dir, int $keep = 2): array
{
    $type = function (string $name): string {
        $b = strtolower($name);
        if (strpos($b, 'migration_') === 0)     return 'migration';
        if (strpos($b, 'upgrade_') === 0)        return 'upgrade';
        if (strpos($b, 'consolidat') !== false)  return 'consolidato';
        // generico: prefisso prima del primo token di versione o data
        $base = preg_replace('/[_-]?v?\d.*$/', '', $b);
        $base = preg_replace('/\.sql$/', '', $base);
        return $base !== '' ? $base : $b;
    };
    $sortval = function (string $name): array {
        preg_match_all('/\d+/', $name, $m);
        return array_map('intval', $m[0]);
    };
    $cmp = function (array $a, array $b) use ($sortval): int {
        $ta = $sortval($a['name']); $tb = $sortval($b['name']);
        $n = max(count($ta), count($tb));
        for ($i = 0; $i < $n; $i++) {
            $x = $ta[$i] ?? -1; $y = $tb[$i] ?? -1;
            if ($x !== $y) return $x <=> $y;
        }
        return $a['mtime'] <=> $b['mtime'];
    };
    $groups = [];
    foreach (glob(rtrim($dir, '/') . '/*.sql') ?: [] as $f) {
        $n = basename($f);
        $groups[$type($n)][] = ['name' => $n, 'size' => @filesize($f), 'mtime' => @filemtime($f)];
    }
    ksort($groups);
    $out = [];
    foreach ($groups as $t => $files) {
        usort($files, $cmp);                 // crescente: i più recenti in fondo
        $del  = array_slice($files, 0, max(0, count($files) - $keep));
        $keepF = array_slice($files, max(0, count($files) - $keep));
        $out[$t] = ['keep' => array_reverse($keepF), 'delete' => array_reverse($del), 'total' => count($files)];
    }
    return $out;
}

// ═══════════════════════ POST (PRG dove possibile) ═══════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ─── Aggiornamento ZIP: analisi ───
    if ($action === 'analyze') {
        $tab = 'update';
        if (!isset($_FILES['update_zip']) || $_FILES['update_zip']['error'] !== UPLOAD_ERR_OK) {
            $msg = "<div class='alert alert-danger'>Errore upload file.</div>";
        } elseif (strtolower(pathinfo($_FILES['update_zip']['name'], PATHINFO_EXTENSION)) !== 'zip') {
            $msg = "<div class='alert alert-danger'>Il file deve essere un .zip</div>";
        } else {
            $saved_zip = $backup_dir . 'pending_update_' . time() . '.zip';
            move_uploaded_file($_FILES['update_zip']['tmp_name'], $saved_zip);
            $_SESSION['pending_update_zip'] = $saved_zip;
            $report = analyze_zip($saved_zip, $app_root, $current_ver);
            $phase  = 'confirm';
        }
    }

    // ─── Aggiornamento ZIP: applicazione ───
    if ($action === 'apply') {
        $tab = 'update';
        $saved_zip = $_SESSION['pending_update_zip'] ?? '';
        if (!$saved_zip || !file_exists($saved_zip)) {
            $msg = "<div class='alert alert-danger'>File ZIP non trovato. Ricaricare il pacchetto.</div>";
        } else {
            set_time_limit(300);
            $report = apply_update($saved_zip, $app_root, $backup_dir, $temp_dir, $current_ver, $pdo, $u_id);
            unset($_SESSION['pending_update_zip']);
            $phase = 'result';
        }
    }

    // ─── SQL Runner: anteprima (upload / incolla / da file sql/) ───
    if ($action === 'sql_preview') {
        $tab = 'sql';
        try {
            $src = $_POST['source'] ?? '';
            if ($src === 'upload') {
                if (!isset($_FILES['sql_file']) || $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) throw new Exception('Errore upload file.');
                $ext = strtolower(pathinfo($_FILES['sql_file']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['sql','txt'], true)) throw new Exception('Formato non valido (solo .sql o .txt).');
                $content = file_get_contents($_FILES['sql_file']['tmp_name']);
                $origin  = 'Upload: ' . $_FILES['sql_file']['name'];
            } elseif ($src === 'paste') {
                $content = (string)($_POST['sql_content'] ?? '');
                if (trim($content) === '') throw new Exception('Nessun contenuto SQL.');
                $origin  = 'Testo incollato';
            } elseif ($src === 'file') {
                $fn = basename($_POST['filename'] ?? '');
                $fp = $sql_dir . $fn;
                if (!is_file($fp)) throw new Exception('File non trovato in /sql/.');
                $content = file_get_contents($fp);
                $origin  = 'File: sql/' . $fn;
            } else {
                throw new Exception('Sorgente non valida.');
            }
            $_SESSION['sql_preview_content'] = $content;
            $_SESSION['sql_preview_origin']  = $origin;
            $preview = ['content' => $content, 'origin' => $origin];
        } catch (Throwable $e) {
            $msg = "<div class='alert alert-danger'>" . $h($e->getMessage()) . "</div>";
        }
    }

    if ($action === 'sql_cancel') { unset($_SESSION['sql_preview_content'], $_SESSION['sql_preview_origin']); $tab = 'sql'; }

    // ─── SQL Runner: esecuzione ───
    if ($action === 'sql_execute') {
        $tab = 'sql';
        $content = $_SESSION['sql_preview_content'] ?? '';
        $origin  = $_SESSION['sql_preview_origin'] ?? 'n/d';
        if (trim($content) === '') {
            $msg = "<div class='alert alert-danger'>Nessuno script in anteprima.</div>";
        } else {
            $stmts = sql_split_statements($content);
            $out = ['ok' => 0, 'err' => 0, 'items' => []];
            foreach ($stmts as $n => $st) {
                $bare = trim(preg_replace('/^\s*--[^\n]*(\n|$)/m', '', $st));
                if ($bare === '' || preg_match('/^\s*(SELECT|SHOW)/i', $bare)) continue;
                try {
                    $pdo->exec($st);
                    $out['ok']++;
                    $out['items'][] = ['n' => $n + 1, 'ok' => true, 'sql' => mb_substr(preg_replace('/\s+/', ' ', $st), 0, 90)];
                } catch (PDOException $e) {
                    $m = $e->getMessage();
                    if (strpos($m, 'Duplicate') !== false || strpos($m, 'already exists') !== false) {
                        $out['ok']++;
                        $out['items'][] = ['n' => $n + 1, 'ok' => true, 'note' => 'già presente', 'sql' => mb_substr(preg_replace('/\s+/', ' ', $st), 0, 90)];
                    } else {
                        $out['err']++;
                        $out['items'][] = ['n' => $n + 1, 'ok' => false, 'error' => mb_substr($m, 0, 140), 'sql' => mb_substr(preg_replace('/\s+/', ' ', $st), 0, 90)];
                    }
                }
            }
            write_log('SqlRunner', $out['err'] ? 'error' : 'success', "Console SQL ($origin): {$out['ok']} ok, {$out['err']} errori", $u_id);
            $_SESSION['sql_result'] = $out;
            unset($_SESSION['sql_preview_content'], $_SESSION['sql_preview_origin']);
        }
    }

    // ─── Pulizia file SQL obsoleti (mantiene gli ultimi 2 per tipo) ───
    if ($action === 'sql_cleanup') {
        $tab = 'sql';
        if (class_exists('Csrf')) { try { Csrf::verify(); } catch (Throwable $e) {} }
        $plan = sc_sql_cleanup_plan($sql_dir, 2);
        $deleted = 0; $freed = 0; $failed = [];
        foreach ($plan as $t => $g) {
            foreach ($g['delete'] as $file) {
                $fp = $sql_dir . basename($file['name']);
                if (is_file($fp)) {
                    $sz = (int)@filesize($fp);
                    if (@unlink($fp)) { $deleted++; $freed += $sz; }
                    else $failed[] = $file['name'];
                }
            }
        }
        write_log('SystemConsole', $failed ? 'warning' : 'success', "Pulizia file SQL: $deleted eliminati" . ($failed ? ', ' . count($failed) . ' non eliminati' : ''), $u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-" . ($failed ? 'warning' : 'success') . "'>Pulizia completata: <strong>$deleted</strong> file eliminati (" . number_format($freed / 1024, 1, ',', '.') . " KB liberati)." . ($failed ? ' Non eliminati: ' . $h(implode(', ', $failed)) . '.' : '') . " Mantenuti gli ultimi 2 file per tipo.</div>";
        header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=sql'); exit;
    }
}

// recupera risultati/anteprima post-redirect e da sessione
if (!$preview && !empty($_SESSION['sql_preview_content'])) {
    $preview = ['content' => $_SESSION['sql_preview_content'], 'origin' => $_SESSION['sql_preview_origin'] ?? ''];
}
if (!empty($_SESSION['sql_result'])) { $sql_result = $_SESSION['sql_result']; unset($_SESSION['sql_result']); }
if (!empty($_SESSION['flash_msg']))  { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }

// ═══════════════════════ Dati per le schede ═══════════════════════
// Stato versione (Migrazioni DB)
$ver_rows = [];
try {
    $ver_rows = $pdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('app_version','schema_version','release_label')")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) {}

// File SQL disponibili in /sql/
$sql_files = [];
foreach (glob($sql_dir . '*.sql') as $f) {
    $sql_files[] = ['name' => basename($f), 'size' => filesize($f), 'mtime' => filemtime($f)];
}
usort($sql_files, fn($a, $b) => strcmp($b['name'], $a['name']));

// Piano di pulizia file SQL (mantiene gli ultimi 2 per tipo)
$cleanup_plan = sc_sql_cleanup_plan($sql_dir, 2);
$cleanup_del  = 0; $cleanup_freed = 0;
foreach ($cleanup_plan as $g) { $cleanup_del += count($g['delete']); foreach ($g['delete'] as $d) $cleanup_freed += (int)$d['size']; }

// Backup disponibili (Aggiornamento)
$backups = [];
foreach (glob($backup_dir . 'backup_*.zip') as $f) $backups[] = ['name' => basename($f), 'size' => filesize($f), 'mtime' => filemtime($f)];
usort($backups, fn($a, $b) => $b['mtime'] <=> $a['mtime']);

// Log (app_logs) con filtri
$lf_cat   = trim($_GET['lcat'] ?? '');
$lf_level = trim($_GET['llevel'] ?? '');
$lf_q     = trim($_GET['lq'] ?? '');
$lf_page  = max(1, (int)($_GET['lpage'] ?? 1));
$lf_per   = 40;
$log_cats = [];
$logs = []; $log_total = 0; $log_error = '';
try {
    $log_cats = $pdo->query("SELECT DISTINCT category FROM app_logs ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
    $w = []; $args = [];
    if ($lf_cat !== '')   { $w[] = "category = ?"; $args[] = $lf_cat; }
    if ($lf_level !== '') { $w[] = "level = ?";    $args[] = $lf_level; }
    if ($lf_q !== '')     { $w[] = "message LIKE ?"; $args[] = '%' . $lf_q . '%'; }
    $wsql = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $stC = $pdo->prepare("SELECT COUNT(*) FROM app_logs $wsql"); $stC->execute($args);
    $log_total = (int)$stC->fetchColumn();
    $off = ($lf_page - 1) * $lf_per;
    // v1.7.75: users non ha più first_name/last_name (rimossi in v2.2); il nome
    // si ottiene da employees via users.employee_id. La query precedente
    // (CONCAT(u.first_name,...)) falliva con "Unknown column" e, intercettata dal
    // catch, lasciava la tabella log vuota.
    $stL = $pdo->prepare(
        "SELECT l.*, TRIM(CONCAT(COALESCE(e.first_name,''),' ',COALESCE(e.last_name,''))) AS uname, u.email AS uemail
           FROM app_logs l
           LEFT JOIN users u     ON u.id = l.user_id
           LEFT JOIN employees e ON e.id = u.employee_id
         $wsql ORDER BY l.id DESC LIMIT $lf_per OFFSET $off"
    );
    $stL->execute($args);
    $logs = $stL->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $log_error = $e->getMessage(); }
$log_pages = max(1, (int)ceil($log_total / $lf_per));

require_once('header.php');

$tabs = ['update' => ['Aggiornamento (ZIP)', 'fa-cloud-arrow-down'],
         'db'     => ['Migrazioni DB', 'fa-database'],
         'sql'    => ['SQL Runner', 'fa-terminal'],
         'logs'   => ['Log', 'fa-file-lines']];
$turl = fn($t) => url_safe('system_console', ['tab' => $t]);
$fmt  = function ($b) { $u = ['B','KB','MB','GB']; $i = 0; while ($b >= 1024 && $i < 3) { $b /= 1024; $i++; } return round($b, 1) . ' ' . $u[$i]; };
?>
<div class="page-header">
  <h1><i class="fa-solid fa-sliders"></i> Console di sistema</h1>
  <p style="color:var(--muted);font-size:13px">Aggiornamenti, migrazioni schema, esecuzione SQL e log — in un'unica pagina. Versione installata: <strong><?=$h($current_ver)?></strong>.</p>
</div>
<?= $msg ?>

<div class="tab-nav" style="display:flex;gap:4px;border-bottom:2px solid var(--border);margin-bottom:18px;flex-wrap:wrap">
  <?php foreach ($tabs as $k => [$lbl, $ic]): ?>
    <a href="<?=$turl($k)?>" class="tab-btn <?=$tab===$k?'active':''?>"
       style="padding:9px 16px;text-decoration:none;border:0;border-bottom:3px solid <?=$tab===$k?'var(--p)':'transparent'?>;color:<?=$tab===$k?'var(--p)':'var(--muted)'?>;font-weight:<?=$tab===$k?'700':'500'?>;background:none">
      <i class="fa-solid <?=$ic?>"></i> <?=$lbl?>
    </a>
  <?php endforeach; ?>
</div>

<!-- ════════════════ TAB: AGGIORNAMENTO (ZIP) ════════════════ -->
<div class="tab-pane" style="<?=$tab==='update'?'':'display:none'?>">
<?php if ($phase === 'confirm' && $report): ?>
  <div class="card" style="margin-bottom:16px;border:2px solid #f59e0b">
    <div class="card-header" style="background:#fffbeb"><span class="card-title" style="color:#92400e"><i class="fa-solid fa-eye"></i> Anteprima aggiornamento — conferma</span></div>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;padding:10px 0">
      <?php
        $cnt = fn($v) => is_array($v) ? count($v) : (int)$v;
        foreach ([
          ['Versione',       ($report['old_version']??$current_ver).' → '.($report['new_version']??'?')],
          ['File nel pacchetto', $cnt($report['files_total']??0)],
          ['Nuovi',          $cnt($report['files_new']??0)],
          ['Modificati',     $cnt($report['files_modified']??0)],
          ['Invariati',      $cnt($report['files_unchanged']??0)],
          ['Migrazioni SQL', $cnt($report['sql_migrations']??[])],
        ] as [$k,$v]): ?>
        <div style="text-align:center;padding:12px;background:#f8fafc;border-radius:8px">
          <div style="font-size:18px;font-weight:800"><?=$h($v)?></div><div style="font-size:10px;color:var(--muted);font-weight:700"><?=$k?></div></div>
      <?php endforeach; ?>
    </div>
    <?php if (!empty($report['sql_migrations'])): ?>
      <p style="font-size:12px;color:var(--muted)">Migrazioni da eseguire: <?php foreach ($report['sql_migrations'] as $sf): ?><code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;margin-right:4px"><?=$h(is_array($sf)?($sf['file']??''):$sf)?></code><?php endforeach; ?></p>
    <?php endif; ?>
    <div style="background:#fef2f2;border:1px solid #fecaca;padding:12px;border-radius:8px;margin-top:8px;font-size:12px;color:#991b1b">
      <i class="fa-solid fa-triangle-exclamation"></i> Verrà creato un backup automatico dei file e del DB prima di applicare. I file protetti (Config.php, .htaccess, uploads/) non vengono toccati.</div>
    <div style="display:flex;gap:10px;margin-top:14px">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="apply">
        <button class="btn btn-primary" style="background:#dc2626;border:0" onclick="return confirm('Applicare l\'aggiornamento?')"><i class="fa-solid fa-play"></i> Applica aggiornamento</button></form>
      <a class="btn" href="<?=$turl('update')?>">Annulla</a>
    </div>
  </div>
<?php elseif ($phase === 'result' && $report): ?>
  <div class="card" style="margin-bottom:16px;border:2px solid <?=empty($report['sql_errors'])&&empty($report['errors'])?'#16a34a':'#dc2626'?>">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-circle-check"></i> Aggiornamento completato — <?=$h($report['old_version']??'?')?> → <?=$h($report['new_version']??'?')?></span></div>
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;padding:10px 0">
      <?php foreach ([['Aggiornati',$report['files_updated']??0],['Nuovi',$report['files_added']??0],['Saltati',$report['files_skipped']??0],['SQL eseguiti',is_array($report['sql_executed']??null)?count($report['sql_executed']):0],['Durata',($report['duration']??0).'s']] as [$k,$v]): ?>
        <div style="text-align:center;padding:12px;background:#f8fafc;border-radius:8px"><div style="font-size:18px;font-weight:800"><?=$h($v)?></div><div style="font-size:10px;color:var(--muted);font-weight:700"><?=$k?></div></div>
      <?php endforeach; ?>
    </div>
    <?php if (!empty($report['sql_errors'])): ?><div class="alert alert-danger"><strong>Errori SQL:</strong><ul style="margin:6px 0 0 18px"><?php foreach ($report['sql_errors'] as $e): ?><li style="font-size:12px"><?=$h($e)?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if (!empty($report['backup_file'])): ?><p style="font-size:12px;color:var(--muted)">Backup file: <code><?=$h($report['backup_file'])?></code><?=!empty($report['backup_db'])?' · Backup DB: <code>'.$h($report['backup_db']).'</code>':''?></p><?php endif; ?>
    <div style="background:#eff6ff;border:1px solid #bfdbfe;padding:12px;border-radius:8px;font-size:12px;color:#1e40af">
      <i class="fa-solid fa-circle-info"></i> Per rendere effettive tutte le modifiche: riavvia Apache (Stop+Start) e ricarica con Ctrl+F5.</div>
  </div>
<?php else: ?>
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-cloud-arrow-up"></i> Carica pacchetto di aggiornamento (.zip)</span></div>
    <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
      <?= csrf_field() ?><input type="hidden" name="action" value="analyze">
      <div class="form-group" style="margin:0;flex:1"><label>File ZIP della release</label><input type="file" name="update_zip" accept=".zip" required></div>
      <button class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Analizza</button>
    </form>
    <p style="font-size:12px;color:var(--muted);margin-top:8px">Il pacchetto viene prima analizzato; nessuna modifica avviene senza conferma. Backup automatico di file e DB incluso.</p>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-box-archive"></i> Backup disponibili</span></div>
    <table class="data-table" style="width:100%">
      <thead><tr><th>File</th><th>Dimensione</th><th>Data</th></tr></thead>
      <tbody>
      <?php if (!$backups): ?><tr><td colspan="3" style="text-align:center;color:var(--muted);padding:14px">Nessun backup.</td></tr>
      <?php else: foreach (array_slice($backups, 0, 10) as $b): ?>
        <tr><td><code><?=$h($b['name'])?></code></td><td><?=$fmt($b['size'])?></td><td><?=date('d/m/Y H:i', $b['mtime'])?></td></tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</div>

<!-- ════════════════ TAB: MIGRAZIONI DB ════════════════ -->
<div class="tab-pane" style="<?=$tab==='db'?'':'display:none'?>">
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-database"></i> Stato versione</span></div>
    <table class="data-table" style="width:100%">
      <thead><tr><th>Chiave</th><th>Valore a DB</th><th>Codice installato</th><th>Stato</th></tr></thead>
      <tbody>
        <?php
          $keys = ['app_version'=>'Versione applicazione','schema_version'=>'Versione schema','release_label'=>'Etichetta release'];
          foreach ($keys as $k=>$lbl):
            $db = $ver_rows[$k] ?? '—';
            $aligned = ($k==='release_label') ? true : ($db === $current_ver);
        ?>
        <tr><td><?=$lbl?></td><td><code><?=$h($db)?></code></td><td><code><?=$h($current_ver)?></code></td>
            <td><?=$aligned?'<span style="color:#16a34a;font-weight:700">allineato</span>':'<span style="color:#d97706;font-weight:700">da aggiornare</span>'?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-arrow-up-right-dots"></i> Aggiornamento schema</span></div>
    <p style="font-size:13px;color:var(--muted)">Il motore di migrazione applica in sequenza tutte le versioni mancanti fino alla più recente, con backup e guardia anti-regressione.</p>
    <a class="btn btn-primary" href="<?=url_safe('db_upgrade')?>"><i class="fa-solid fa-play"></i> Apri procedura di upgrade schema</a>
    <p style="font-size:11px;color:var(--muted);margin-top:8px">La procedura completa (con backup pre-upgrade e log dettagliato per statement) resta nella pagina dedicata, richiamata qui per coerenza operativa.</p>
  </div>
</div>

<!-- ════════════════ TAB: SQL RUNNER ════════════════ -->
<div class="tab-pane" style="<?=$tab==='sql'?'':'display:none'?>">
<?php if ($sql_result): ?>
  <div class="card" style="margin-bottom:16px;border:2px solid <?=$sql_result['err']?'#dc2626':'#16a34a'?>">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-list-check"></i> Esito esecuzione — <?=$sql_result['ok']?> ok, <?=$sql_result['err']?> errori</span></div>
    <table class="data-table" style="width:100%;font-size:12px">
      <thead><tr><th>#</th><th>Esito</th><th>Statement</th></tr></thead>
      <tbody>
      <?php foreach ($sql_result['items'] as $it): ?>
        <tr><td><?=$it['n']?></td>
          <td><?=$it['ok']?'<span style="color:#16a34a">✔'.(isset($it['note'])?' '.$h($it['note']):'').'</span>':'<span style="color:#dc2626">✘</span>'?></td>
          <td><code><?=$h($it['sql'])?></code><?=isset($it['error'])?'<div style="color:#dc2626;font-size:11px">'.$h($it['error']).'</div>':''?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <a class="btn" href="<?=$turl('sql')?>" style="margin-top:10px">Nuovo script</a>
  </div>
<?php elseif ($preview): $pstmts = sql_split_statements($preview['content']); $danger = 0; foreach ($pstmts as $s0) if (sql_classify($s0)['danger']) $danger++; ?>
  <div class="card" style="margin-bottom:16px;border:2px solid #f59e0b">
    <div class="card-header" style="background:#fffbeb"><span class="card-title" style="color:#92400e"><i class="fa-solid fa-eye"></i> Anteprima — <?=count($pstmts)?> statement<?=$danger?' · <span style="color:#dc2626">⚠ '.$danger.' potenzialmente distruttivi</span>':''?></span></div>
    <p style="font-size:12px;color:var(--muted)">Sorgente: <code><?=$h($preview['origin'])?></code></p>
    <pre style="background:#1e293b;color:#e2e8f0;padding:14px;border-radius:8px;font-size:11px;line-height:1.5;max-height:360px;overflow:auto;white-space:pre-wrap"><?=$h($preview['content'])?></pre>
    <div style="background:#fef2f2;border:1px solid #fecaca;padding:12px;border-radius:8px;margin-top:12px;font-size:12px;color:#991b1b"><i class="fa-solid fa-triangle-exclamation"></i> L'esecuzione modifica direttamente il database. Assicurati di avere un backup recente (tab Aggiornamento o Migrazioni DB).</div>
    <div style="display:flex;gap:10px;margin-top:12px">
      <form method="post" onsubmit="return confirm('Eseguire <?=count($pstmts)?> statement?')"><?= csrf_field() ?><input type="hidden" name="action" value="sql_execute">
        <button class="btn btn-primary" style="background:#dc2626;border:0"><i class="fa-solid fa-play"></i> Esegui ora</button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sql_cancel"><button class="btn">Annulla</button></form>
    </div>
  </div>
<?php else: ?>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="fa-solid fa-cloud-arrow-up"></i> Carica file .sql</span></div>
      <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="sql_preview"><input type="hidden" name="source" value="upload">
        <input type="file" name="sql_file" accept=".sql,.txt" required style="margin-bottom:10px">
        <button class="btn btn-primary"><i class="fa-solid fa-eye"></i> Anteprima</button></form>
    </div>
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="fa-solid fa-keyboard"></i> Incolla SQL</span></div>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sql_preview"><input type="hidden" name="source" value="paste">
        <textarea name="sql_content" rows="5" placeholder="-- il tuo SQL qui" style="width:100%;font-family:monospace;font-size:11px;padding:10px;border:1px solid var(--border);border-radius:7px;margin-bottom:10px;resize:vertical"></textarea>
        <button class="btn btn-primary"><i class="fa-solid fa-eye"></i> Anteprima</button></form>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-folder-open"></i> File in /sql/</span></div>
    <table class="data-table" style="width:100%">
      <thead><tr><th>File</th><th>Dimensione</th><th>Modificato</th><th></th></tr></thead>
      <tbody>
      <?php if (!$sql_files): ?><tr><td colspan="4" style="text-align:center;color:var(--muted);padding:14px">Nessun file .sql.</td></tr>
      <?php else: foreach ($sql_files as $sf): ?>
        <tr><td><code><?=$h($sf['name'])?></code></td><td><?=$fmt($sf['size'])?></td><td><?=date('d/m/Y H:i',$sf['mtime'])?></td>
          <td><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="sql_preview"><input type="hidden" name="source" value="file"><input type="hidden" name="filename" value="<?=$h($sf['name'])?>">
            <button class="btn btn-sm"><i class="fa-solid fa-eye"></i> Anteprima</button></form></td></tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card" style="border:1px solid #fca5a5">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-broom" style="color:#b91c1c"></i> Pulizia file SQL obsoleti</span></div>
    <p style="color:var(--muted);font-size:12px;margin:0 0 10px">Mantiene solo gli <strong>ultimi 2 file per tipo</strong> (es. <code>migration_*</code>, <code>upgrade_*</code>) ed elimina i più vecchi. Le migrazioni eliminate risultano già applicate; lo script consolidato più recente viene conservato per il replay completo.</p>
    <?php if ($cleanup_del === 0): ?>
      <div class="alert alert-success" style="margin:0"><i class="fa-solid fa-check"></i> Nessun file obsoleto: sono già presenti al massimo 2 file per tipo.</div>
    <?php else: ?>
    <table class="data-table" style="width:100%;font-size:12px;margin-bottom:10px">
      <thead><tr><th>Tipo</th><th>Totale</th><th>Mantenuti (ultimi 2)</th><th>Da eliminare</th></tr></thead>
      <tbody>
      <?php foreach ($cleanup_plan as $t => $g): if (!$g['delete']) continue; ?>
        <tr>
          <td><strong><?=$h($t)?></strong></td>
          <td><?=$g['total']?></td>
          <td style="color:#166534"><?=$h(implode(', ', array_map(fn($x)=>$x['name'], $g['keep'])))?></td>
          <td style="color:#b91c1c"><?=$h(implode(', ', array_map(fn($x)=>$x['name'], $g['delete'])))?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <form method="post" onsubmit="return confirm('Eliminare definitivamente <?=$cleanup_del?> file SQL obsoleti? I file eliminati non sono recuperabili.')">
      <?= csrf_field() ?><input type="hidden" name="action" value="sql_cleanup">
      <button class="btn btn-danger"><i class="fa-solid fa-trash"></i> Elimina <?=$cleanup_del?> file obsoleti (<?=number_format($cleanup_freed/1024,1,',','.')?> KB)</button>
    </form>
    <?php endif; ?>
  </div>
<?php endif; ?>
</div>

<!-- ════════════════ TAB: LOG ════════════════ -->
<div class="tab-pane" style="<?=$tab==='logs'?'':'display:none'?>">
  <div class="card" style="margin-bottom:12px">
    <form method="get" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
      <input type="hidden" name="tab" value="logs">
      <div class="form-group" style="margin:0"><label>Categoria</label>
        <select name="lcat"><option value="">tutte</option>
          <?php foreach ($log_cats as $c): ?><option value="<?=$h($c)?>" <?=$lf_cat===$c?'selected':''?>><?=$h($c)?></option><?php endforeach; ?></select></div>
      <div class="form-group" style="margin:0"><label>Livello</label>
        <select name="llevel"><option value="">tutti</option>
          <?php foreach (['info','success','warning','error'] as $lv): ?><option value="<?=$lv?>" <?=$lf_level===$lv?'selected':''?>><?=$lv?></option><?php endforeach; ?></select></div>
      <div class="form-group" style="margin:0"><label>Cerca nel messaggio</label><input type="text" name="lq" value="<?=$h($lf_q)?>" style="width:240px"></div>
      <button class="btn">Filtra</button>
      <span style="color:var(--muted);font-size:12px;align-self:center"><strong><?=$log_total?></strong> eventi</span>
    </form>
  </div>
  <div class="card">
    <table class="data-table" style="width:100%;font-size:12px">
      <thead><tr><th>Data</th><th>Livello</th><th>Categoria</th><th>Messaggio</th><th>Utente</th><th>IP</th></tr></thead>
      <tbody>
      <?php if (!empty($log_error)): ?><tr><td colspan="6" style="text-align:center;color:#dc2626;padding:16px">Impossibile leggere i log: <?=$h($log_error)?></td></tr>
      <?php elseif (!$logs): ?><tr><td colspan="6" style="text-align:center;color:var(--muted);padding:16px">Nessun evento.</td></tr>
      <?php else: $lc=['info'=>'#0369a1','success'=>'#16a34a','warning'=>'#d97706','error'=>'#dc2626']; foreach ($logs as $lg): ?>
        <tr>
          <td style="white-space:nowrap"><?=date('d/m/Y H:i:s', strtotime($lg['created_at']))?></td>
          <td><span style="color:<?=$lc[$lg['level']]??'#64748b'?>;font-weight:700"><?=$h($lg['level'])?></span></td>
          <td><?=$h($lg['category'])?></td>
          <td><?=$h($lg['message'])?><?php if(!empty($lg['context'])):?><div style="color:var(--muted);font-size:10px"><?=$h(mb_strimwidth($lg['context'],0,120,'…'))?></div><?php endif;?></td>
          <td><?=$h(trim($lg['uname'] ?? '') ?: ($lg['uemail'] ?? '') ?: ($lg['user_id'] ? '#'.$lg['user_id'] : '—'))?></td>
          <td style="color:var(--muted)"><?=$h($lg['ip_address'] ?? '—')?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    <?php if ($log_pages > 1): $lq = fn($p)=>url_safe('system_console', array_filter(['tab'=>'logs','lpage'=>$p,'lcat'=>$lf_cat,'llevel'=>$lf_level,'lq'=>$lf_q], fn($v)=>$v!=='')); ?>
    <div style="display:flex;gap:6px;justify-content:center;margin-top:12px;align-items:center">
      <?php if($lf_page>1):?><a class="btn btn-sm" href="<?=$lq($lf_page-1)?>">‹</a><?php endif;?>
      <span style="color:var(--muted);font-size:12px">pagina <?=$lf_page?> di <?=$log_pages?></span>
      <?php if($lf_page<$log_pages):?><a class="btn btn-sm" href="<?=$lq($lf_page+1)?>">›</a><?php endif;?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once('footer.php'); ?>
