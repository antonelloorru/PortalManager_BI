<?php
/**
 * PortalManager 1.6.4 — file_manager.php
 *
 * File manager interno per Super Admin.
 *  - Navigazione confinata alla cartella radice del portale (APP_ROOT)
 *  - Operazioni: upload (multi-file, tutti i tipi), download, rinomina, cancella, sposta, mkdir, edit testo
 *  - Path-traversal protection: realpath() + startsWith(APP_ROOT) su ogni input
 *  - Log azioni in app_logs (categoria FileManager)
 *
 * ACCESSO: solo Super Admin (role_id = 1)
 *
 * v1.10.27 — correzioni:
 *  - link e pulsanti puntavano a «?p=…» / «?op=…»: con <base href> di header.php si risolvevano sulla radice del portale
 *    (index), quindi navigazione cartelle, breadcrumb, download, visualizza, modifica e «Chiudi» non funzionavano.
 *    Ora tutti gli URL sono «file_manager.php?…» (fm_url);
 *  - CSRF verificato su ogni POST (Csrf::verify) e PRG: dopo ogni operazione redirect alla cartella corrente con esito
 *    in sessione (niente doppio invio al ricaricamento, si resta nella cartella);
 *  - download / ZIP / visualizza: buffer di output svuotati prima dell'invio (bootstrap apre ob_start: file grandi
 *    finivano in memoria e i binari potevano essere corrotti), invio a blocchi, nome file RFC 5987;
 *  - visualizza: HTML/SVG/XML serviti come testo o con CSP sandbox (nessuna esecuzione di script nell'origine del portale);
 *  - rinomina / elimina: percorsi passati con data-attribute (nomi con apici o caratteri speciali);
 *  - elimina: esito reale (file non eliminati segnalati), protezione delle cartelle di sistema del portale.
 */

require_once('access_control.php');

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);

if ($u_role !== 1) {
    http_response_code(403);
    die('Accesso riservato al Super Admin');
}

// ─────────────────────────────────────────────────────────────────────
// Configurazione sicurezza
// ─────────────────────────────────────────────────────────────────────
$ROOT          = realpath(APP_ROOT);            // /portalmanager/ — root assoluta
$MAX_UPLOAD    = 100 * 1024 * 1024;             // 100 MB per file
$MAX_EDIT_SIZE = 5 * 1024 * 1024;               // 5 MB max per edit testo
$TEXT_EXTS     = ['php','html','htm','css','js','json','txt','md','sql','xml','yml','yaml',
                  'ini','conf','log','csv','tsv','htaccess','env','sh','bat','cmd','ps1'];

// ─────────────────────────────────────────────────────────────────────
// Helpers di sicurezza
// ─────────────────────────────────────────────────────────────────────

/**
 * Verifica che $path sia dentro $ROOT (path-traversal protection).
 * Ritorna il path REALE assoluto oppure null se fuori scope.
 */
function fm_safe_path(string $path, string $root, bool $must_exist = true): ?string {
    // Permetto path relativi alla root
    if ($path === '' || $path === '/') return $root;
    // Path assoluto fuori da root → reject
    $combined = $path;
    if (!preg_match('/^([A-Za-z]:[\\\\\/]|\/)/', $path)) {
        $combined = $root . DIRECTORY_SEPARATOR . $path;
    }
    // Normalizza separatori
    $combined = str_replace(['\\','/'], DIRECTORY_SEPARATOR, $combined);

    if ($must_exist) {
        $real = realpath($combined);
        if ($real === false) return null;
    } else {
        // realpath() ritorna false se il path non esiste
        // Risolvo manualmente il parent + nome
        $parent = dirname($combined);
        $name   = basename($combined);
        $real_parent = realpath($parent);
        if ($real_parent === false) return null;
        $real = $real_parent . DIRECTORY_SEPARATOR . $name;
    }

    // Verifica che inizi con ROOT
    $root_norm = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $real_norm = $real . DIRECTORY_SEPARATOR;
    if (stripos($real_norm, $root_norm) !== 0 && $real !== $root) {
        return null;
    }
    return $real;
}

function fm_rel_path(string $abs, string $root): string {
    $r = rtrim($root, DIRECTORY_SEPARATOR);
    if (stripos($abs, $r) === 0) {
        return ltrim(substr($abs, strlen($r)), DIRECTORY_SEPARATOR);
    }
    return basename($abs);
}

function fm_format_size(int $bytes): string {
    if ($bytes < 1024)              return $bytes . ' B';
    if ($bytes < 1024 * 1024)       return number_format($bytes / 1024, 1) . ' KB';
    if ($bytes < 1024 * 1024 * 1024) return number_format($bytes / 1024 / 1024, 1) . ' MB';
    return number_format($bytes / 1024 / 1024 / 1024, 2) . ' GB';
}

function fm_icon(string $ext, bool $is_dir): array {
    if ($is_dir) return ['fa-folder', '#f59e0b'];
    $map = [
        'php' => ['fa-file-code', '#7c3aed'],
        'html' => ['fa-file-code', '#dc2626'], 'htm' => ['fa-file-code', '#dc2626'],
        'css' => ['fa-file-code', '#2563eb'],
        'js' => ['fa-file-code', '#ca8a04'],
        'json' => ['fa-file-code', '#059669'],
        'sql' => ['fa-database', '#0891b2'],
        'md' => ['fa-file-lines', '#6366f1'], 'txt' => ['fa-file-lines', '#475569'],
        'log' => ['fa-file-lines', '#94a3b8'],
        'csv' => ['fa-file-csv', '#16a34a'], 'tsv' => ['fa-file-csv', '#16a34a'],
        'xlsx' => ['fa-file-excel', '#16a34a'], 'xls' => ['fa-file-excel', '#16a34a'],
        'docx' => ['fa-file-word', '#1e40af'], 'doc' => ['fa-file-word', '#1e40af'],
        'pdf' => ['fa-file-pdf', '#dc2626'],
        'zip' => ['fa-file-zipper', '#f59e0b'], 'rar' => ['fa-file-zipper', '#f59e0b'],
        'jpg' => ['fa-file-image', '#ec4899'], 'jpeg' => ['fa-file-image', '#ec4899'],
        'png' => ['fa-file-image', '#ec4899'], 'gif' => ['fa-file-image', '#ec4899'],
        'webp' => ['fa-file-image', '#ec4899'], 'svg' => ['fa-file-image', '#ec4899'],
        'mp3' => ['fa-file-audio', '#8b5cf6'], 'wav' => ['fa-file-audio', '#8b5cf6'],
        'mp4' => ['fa-file-video', '#dc2626'], 'avi' => ['fa-file-video', '#dc2626'],
        'exe' => ['fa-gears', '#dc2626'], 'bat' => ['fa-terminal', '#1f2937'], 'cmd' => ['fa-terminal', '#1f2937'],
    ];
    return $map[$ext] ?? ['fa-file', '#64748b'];
}

function fm_log(PDO $pdo, int $u_id, string $action, string $detail): void {
    if (function_exists('write_log')) {
        write_log('FileManager', 'info', "$action: $detail", $u_id);
    }
}

/** v1.10.27 — URL della pagina (mai «?…» relativo: header.php imposta <base href> sulla radice del portale). */
function fm_url(array $q = []): string {
    $q = array_filter($q, fn($v) => $v !== null && $v !== '');
    return 'file_manager.php' . ($q ? '?' . http_build_query($q) : '');
}

/** v1.10.27 — Svuota i buffer aperti da bootstrap (ob_start) prima di inviare un file. */
function fm_clean_output(): void {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    @ini_set('zlib.output_compression', '0');
    @set_time_limit(0);
}

/** v1.10.27 — Invia un file a blocchi (nessun caricamento in memoria). */
function fm_send_file(string $path, string $mime, string $disposition): void {
    fm_clean_output();
    $name = basename($path);
    $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . $disposition . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    $fh = fopen($path, 'rb');
    if ($fh) { while (!feof($fh)) { echo fread($fh, 1048576); flush(); } fclose($fh); }
    exit;
}

/** v1.10.27 — Esito dell'operazione in sessione + redirect alla cartella (PRG). */
function fm_done(string $html, string $rel_dir): void {
    $_SESSION['fm_flash'] = $html;
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Location: ' . fm_url(['p' => $rel_dir]), true, 303);
    exit;
}

/** v1.10.27 — Elimina file o cartella; ritorna i percorsi non eliminati. */
function fm_delete(string $abs): array {
    $fail = [];
    if (is_dir($abs) && !is_link($abs)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $ok = ($f->isDir() && !$f->isLink()) ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            if (!$ok) $fail[] = $f->getPathname();
        }
        if (!@rmdir($abs)) $fail[] = $abs;
    } elseif (!@unlink($abs)) {
        $fail[] = $abs;
    }
    return $fail;
}

// ─────────────────────────────────────────────────────────────────────
// Stato corrente
// ─────────────────────────────────────────────────────────────────────
$current_rel = $_GET['p'] ?? '';
$current_rel = str_replace(['../', '..\\'], '', $current_rel); // safety extra
$current_dir = fm_safe_path($current_rel, $ROOT, true);
if ($current_dir === null || !is_dir($current_dir)) $current_dir = $ROOT;

$msg = '';
if (!empty($_SESSION['fm_flash'])) { $msg = (string)$_SESSION['fm_flash']; unset($_SESSION['fm_flash']); }
$current_rel_dir = fm_rel_path($current_dir, $ROOT);
// v1.10.27 — cartelle indispensabili al portale: non eliminabili né rinominabili da qui
$PROTECTED = array_map(fn($d) => realpath($ROOT . DIRECTORY_SEPARATOR . $d) ?: '', ['app', 'assets', 'sql', 'uploads', 'vendor']);
$is_protected = fn(string $abs) => $abs !== '' && in_array(realpath($abs) ?: $abs, array_filter($PROTECTED), true);

// ─────────────────────────────────────────────────────────────────────
// DOWNLOAD (deve essere PRIMA dell'include header.php)
// ─────────────────────────────────────────────────────────────────────
if (($_GET['op'] ?? '') === 'download' && !empty($_GET['f'])) {
    $target = fm_safe_path($_GET['f'], $ROOT, true);
    if ($target && is_file($target)) {
        fm_log($pdo, $u_id, 'download', fm_rel_path($target, $ROOT));
        fm_send_file($target, 'application/octet-stream', 'attachment');
    }
    http_response_code(404);
    die('File non trovato o fuori scope.');
}

// ─────────────────────────────────────────────────────────────────────
// VIEW (apri inline per file di testo/immagini)
// ─────────────────────────────────────────────────────────────────────
if (($_GET['op'] ?? '') === 'view' && !empty($_GET['f'])) {
    $target = fm_safe_path($_GET['f'], $ROOT, true);
    if ($target && is_file($target)) {
        $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
        // v1.10.27 — immagini e PDF nel loro tipo; tutto il testo (anche HTML, SVG, XML, PHP) come text/plain;
        // CSP sandbox: nessuno script eseguito nell'origine del portale
        $mime_map = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
            'pdf' => 'application/pdf',
        ];
        $is_text = in_array($ext, $TEXT_EXTS, true) || in_array($ext, ['svg', 'xml', 'htm', 'html', 'log', 'csv'], true);
        $mime = $mime_map[$ext] ?? ($is_text ? 'text/plain; charset=utf-8' : 'application/octet-stream');
        fm_log($pdo, $u_id, 'view', fm_rel_path($target, $ROOT));
        header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; object-src 'self'");
        fm_send_file($target, $mime, $mime === 'application/octet-stream' ? 'attachment' : 'inline');
    }
    http_response_code(404);
    die('File non trovato.');
}

// ─────────────────────────────────────────────────────────────────────
// POST handlers (operazioni R/W)
// ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();                                                  // v1.10.27
    $action = $_POST['action'] ?? '';
    $back = (string)($_POST['p'] ?? $current_rel_dir);               // cartella in cui tornare (PRG)
    $back_abs = fm_safe_path($back, $ROOT, true);
    $back = ($back_abs && is_dir($back_abs)) ? fm_rel_path($back_abs, $ROOT) : '';
    try {

        // ── UPLOAD (multi-file) ────────────────────────────────────
        if ($action === 'upload') {
            if (empty($_FILES['files']) || !is_array($_FILES['files']['name'])) {
                throw new Exception('Nessun file caricato.');
            }
            $dest_dir = fm_safe_path($_POST['dest'] ?? '', $ROOT, true);
            if (!$dest_dir || !is_dir($dest_dir)) throw new Exception('Cartella destinazione non valida.');

            $uploaded = 0;
            $errors = [];
            foreach ($_FILES['files']['name'] as $i => $name) {
                if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
                    $errors[] = "$name: errore upload (code " . $_FILES['files']['error'][$i] . ')';
                    continue;
                }
                if ($_FILES['files']['size'][$i] > $MAX_UPLOAD) {
                    $errors[] = "$name: troppo grande (>100 MB)";
                    continue;
                }
                $safe_name = preg_replace('/[\x00-\x1f\\\\\/:\*\?"<>\|]/', '_', $name);
                $target = $dest_dir . DIRECTORY_SEPARATOR . $safe_name;
                if (move_uploaded_file($_FILES['files']['tmp_name'][$i], $target)) {
                    $uploaded++;
                    fm_log($pdo, $u_id, 'upload', fm_rel_path($target, $ROOT));
                } else {
                    $errors[] = "$name: move_uploaded_file fallito";
                }
            }
            $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> "
                 . "Caricati <strong>$uploaded</strong> file in <code>" . htmlspecialchars(fm_rel_path($dest_dir, $ROOT) ?: '/') . "</code>.";
            if ($errors) $msg .= '<br><small>Errori: ' . htmlspecialchars(implode('; ', $errors)) . '</small>';
            $msg .= '</div>';
        }

        // ── DELETE (file o cartella) ───────────────────────────────
        elseif ($action === 'delete') {
            $target = fm_safe_path($_POST['target'] ?? '', $ROOT, true);
            if (!$target || $target === $ROOT) throw new Exception('Target non valido.');
            if ($is_protected($target)) throw new Exception('Cartella di sistema del portale: non eliminabile da qui.');
            $fail = fm_delete($target);
            fm_log($pdo, $u_id, 'delete', fm_rel_path($target, $ROOT) . ($fail ? ' (non eliminati: ' . count($fail) . ')' : ''));
            if ($fail) throw new Exception('Eliminazione parziale: ' . count($fail) . ' elementi non eliminati (permessi o file in uso), es. ' . fm_rel_path($fail[0], $ROOT));
            $msg = "<div class='alert alert-info'><i class='fa-solid fa-trash'></i> Eliminato: <code>" . htmlspecialchars(fm_rel_path($target, $ROOT)) . "</code></div>";
        }

        // ── RENAME / MOVE ───────────────────────────────────────────
        elseif ($action === 'rename') {
            $source = fm_safe_path($_POST['source'] ?? '', $ROOT, true);
            if (!$source || $source === $ROOT) throw new Exception('Source non valido.');
            if ($is_protected($source)) throw new Exception('Cartella di sistema del portale: non rinominabile da qui.');
            $new_name = trim($_POST['new_name'] ?? '');
            if ($new_name === '') throw new Exception('Nome vuoto.');
            $new_name = preg_replace('/[\x00-\x1f\\\\\/:\*\?"<>\|]/', '_', $new_name);
            $dest = dirname($source) . DIRECTORY_SEPARATOR . $new_name;
            $dest_safe = fm_safe_path($dest, $ROOT, false);
            if (!$dest_safe) throw new Exception('Destinazione fuori scope.');
            if (file_exists($dest_safe)) throw new Exception('Esiste già un file/cartella con questo nome.');
            if (!rename($source, $dest_safe)) throw new Exception('Rename fallito.');
            fm_log($pdo, $u_id, 'rename', fm_rel_path($source, $ROOT) . ' -> ' . fm_rel_path($dest_safe, $ROOT));
            $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Rinominato in <code>" . htmlspecialchars($new_name) . "</code></div>";
        }

        // ── MKDIR ─────────────────────────────────────────────────
        elseif ($action === 'mkdir') {
            $parent = fm_safe_path($_POST['parent'] ?? '', $ROOT, true);
            if (!$parent || !is_dir($parent)) throw new Exception('Cartella padre non valida.');
            $name = trim($_POST['dir_name'] ?? '');
            if ($name === '') throw new Exception('Nome cartella vuoto.');
            $name = preg_replace('/[\x00-\x1f\\\\\/:\*\?"<>\|]/', '_', $name);
            $new_dir = $parent . DIRECTORY_SEPARATOR . $name;
            $new_dir_safe = fm_safe_path($new_dir, $ROOT, false);
            if (!$new_dir_safe) throw new Exception('Destinazione fuori scope.');
            if (file_exists($new_dir_safe)) throw new Exception('Cartella già esistente.');
            if (!mkdir($new_dir_safe, 0755)) throw new Exception('mkdir fallito.');
            fm_log($pdo, $u_id, 'mkdir', fm_rel_path($new_dir_safe, $ROOT));
            $msg = "<div class='alert alert-success'><i class='fa-solid fa-folder-plus'></i> Creata cartella <code>" . htmlspecialchars($name) . "</code></div>";
        }

        // ── SAVE EDIT ──────────────────────────────────────────────
        elseif ($action === 'save_edit') {
            $target = fm_safe_path($_POST['target'] ?? '', $ROOT, true);
            if (!$target || !is_file($target)) throw new Exception('File non valido.');
            $content = $_POST['content'] ?? '';
            if (strlen($content) > $MAX_EDIT_SIZE) throw new Exception('Contenuto troppo grande (max 5 MB).');
            if (file_put_contents($target, $content) === false) throw new Exception('Scrittura fallita (permessi?)');
            $back = fm_rel_path(dirname($target), $ROOT);   // v1.10.27 — si torna alla cartella del file
            fm_log($pdo, $u_id, 'edit', fm_rel_path($target, $ROOT) . ' (' . strlen($content) . ' bytes)');
            $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Salvato <code>" . htmlspecialchars(basename($target)) . "</code></div>";
        }

        // ── DOWNLOAD ZIP (multi-select) ────────────────────────────
        elseif ($action === 'download_zip') {
            $items = $_POST['items'] ?? [];
            if (empty($items)) throw new Exception('Nessun elemento selezionato.');
            $zip_tmp = tempnam(sys_get_temp_dir(), 'fm_zip_');
            $zip = new ZipArchive();
            if ($zip->open($zip_tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new Exception('Impossibile creare ZIP.');
            $added = 0;
            foreach ($items as $rel) {
                $abs = fm_safe_path($rel, $ROOT, true);
                if (!$abs) continue;
                if (is_file($abs)) {
                    $zip->addFile($abs, basename($abs));
                    $added++;
                } elseif (is_dir($abs)) {
                    $base = basename($abs);
                    $it = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($abs, RecursiveDirectoryIterator::SKIP_DOTS)
                    );
                    foreach ($it as $f) {
                        if ($f->isFile()) {
                            $rel_in_zip = $base . '/' . substr($f->getPathname(), strlen($abs) + 1);
                            $rel_in_zip = str_replace(DIRECTORY_SEPARATOR, '/', $rel_in_zip);
                            $zip->addFile($f->getPathname(), $rel_in_zip);
                            $added++;
                        }
                    }
                }
            }
            $zip->close();
            if ($added === 0) { @unlink($zip_tmp); throw new Exception('Nessun file aggiunto allo ZIP.'); }
            fm_log($pdo, $u_id, 'download_zip', count($items) . ' items, ' . $added . ' files');
            $zip_named = dirname($zip_tmp) . DIRECTORY_SEPARATOR . 'portalmanager_files_' . date('Ymd_His') . '.zip';
            if (!@rename($zip_tmp, $zip_named)) $zip_named = $zip_tmp;
            register_shutdown_function(fn() => @unlink($zip_named));
            fm_send_file($zip_named, 'application/zip', 'attachment');
        }

        // ── DELETE multipla ────────────────────────────────────────
        elseif ($action === 'delete_multi') {
            $items = $_POST['items'] ?? [];
            if (empty($items)) throw new Exception('Nessun elemento selezionato.');
            $deleted = 0; $skipped = [];
            foreach ($items as $rel) {
                $abs = fm_safe_path($rel, $ROOT, true);
                if (!$abs || $abs === $ROOT) continue;
                if ($is_protected($abs)) { $skipped[] = fm_rel_path($abs, $ROOT); continue; }
                $fail = fm_delete($abs);
                if ($fail) $skipped[] = fm_rel_path($abs, $ROOT); else $deleted++;
                fm_log($pdo, $u_id, 'delete_multi', fm_rel_path($abs, $ROOT) . ($fail ? ' (parziale)' : ''));
            }
            $msg = "<div class='alert alert-" . ($skipped ? 'warning' : 'info') . "'><i class='fa-solid fa-trash'></i> Eliminati <strong>$deleted</strong> elementi."
                 . ($skipped ? '<br><small>Non eliminati (sistema, permessi o file in uso): ' . htmlspecialchars(implode(', ', array_slice($skipped, 0, 10))) . '</small>' : '') . '</div>';
        }

    } catch (Exception $e) {
        $msg = "<div class='alert alert-danger'><i class='fa-solid fa-triangle-exclamation'></i> " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    fm_done($msg !== '' ? $msg : "<div class='alert alert-warning'>Operazione non riconosciuta.</div>", $back);
}

// ─────────────────────────────────────────────────────────────────────
// EDIT mode (visualizza editor inline)
// ─────────────────────────────────────────────────────────────────────
$edit_file = null;
$edit_content = '';
if (($_GET['op'] ?? '') === 'edit' && !empty($_GET['f'])) {
    $target = fm_safe_path($_GET['f'], $ROOT, true);
    if ($target && is_file($target) && filesize($target) <= $MAX_EDIT_SIZE) {
        $edit_file = $target;
        $edit_content = file_get_contents($target);
    } else {
        $msg = "<div class='alert alert-warning'><i class='fa-solid fa-triangle-exclamation'></i> File non editabile (non esiste o > 5 MB).</div>";
    }
}

// ─────────────────────────────────────────────────────────────────────
// Listing della cartella corrente
// ─────────────────────────────────────────────────────────────────────
$entries = [];
if (is_dir($current_dir)) {
    foreach (new DirectoryIterator($current_dir) as $f) {
        if ($f->isDot()) continue;
        $entries[] = [
            'name'    => $f->getFilename(),
            'is_dir'  => $f->isDir(),
            'size'    => $f->isFile() ? $f->getSize() : 0,
            'mtime'   => $f->getMTime(),
            'rel'     => fm_rel_path($f->getPathname(), $ROOT),
            'ext'     => strtolower($f->getExtension()),
            'writable'=> is_writable($f->getPathname()),
        ];
    }
    // Sort: cartelle prima, alfabetico
    usort($entries, fn($a, $b) => $a['is_dir'] === $b['is_dir']
        ? strcasecmp($a['name'], $b['name'])
        : ($a['is_dir'] ? -1 : 1));
}

// Breadcrumb
$crumbs = [];
$path_so_far = '';
$current_rel_norm = fm_rel_path($current_dir, $ROOT);
if ($current_rel_norm !== '') {
    foreach (preg_split('/[\\\\\/]+/', $current_rel_norm) as $part) {
        if ($part === '') continue;
        $path_so_far .= ($path_so_far === '' ? '' : '/') . $part;
        $crumbs[] = ['name' => $part, 'rel' => $path_so_far];
    }
}

require_once('header.php');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>

<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:18px;flex-wrap:wrap;gap:12px">
  <div style="flex:1;min-width:300px">
    <h1 style="font-size:22px;font-weight:800;margin:0">
      <i class="fa-solid fa-folder-tree" style="color:#f59e0b"></i> File Manager
    </h1>
    <div style="font-size:11px;color:var(--muted);margin-top:4px">
      Root: <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:10px"><?= $h($ROOT) ?></code>
    </div>
  </div>
  <div style="background:#fef3c7;color:#92400e;padding:8px 14px;border-radius:8px;font-size:11px;font-weight:700;border:1px solid #fbbf24">
    <i class="fa-solid fa-shield-halved"></i> Super Admin · R/W completo · tutti i tipi di file consentiti
  </div>
</div>

<?= $msg ?>

<?php if ($edit_file): ?>
<!-- ═══ EDITOR INLINE ═══ -->
<div class="card" style="margin-bottom:18px;border-left:4px solid #2563eb">
  <div class="card-header" style="background:#eff6ff">
    <span class="card-title" style="color:#1e40af">
      <i class="fa-solid fa-pen-to-square"></i> Editing: <code><?= $h(fm_rel_path($edit_file, $ROOT)) ?></code>
    </span>
    <a href="<?= $h(fm_url(['p' => fm_rel_path(dirname($edit_file), $ROOT)])) ?>" class="btn btn-sm">
      <i class="fa-solid fa-xmark"></i> Chiudi senza salvare
    </a>
  </div>
  <form method="POST">
    <?= csrf_field() ?><input type="hidden" name="p" value="<?= $h($current_rel_dir) ?>">
    <input type="hidden" name="action" value="save_edit">
    <input type="hidden" name="target" value="<?= $h(fm_rel_path($edit_file, $ROOT)) ?>">
    <textarea name="content" spellcheck="false" wrap="off"
              style="width:100%;height:60vh;padding:14px;border:1px solid var(--border);border-radius:7px;font-family:'Consolas','Courier New',monospace;font-size:12px;line-height:1.5;background:#0f172a;color:#e2e8f0;tab-size:4;resize:vertical"><?= $h($edit_content) ?></textarea>
    <div style="display:flex;gap:10px;margin-top:10px;align-items:center;flex-wrap:wrap">
      <button type="submit" class="btn btn-primary" style="padding:10px 22px">
        <i class="fa-solid fa-floppy-disk"></i> Salva modifiche
      </button>
      <a href="<?= $h(fm_url(['op' => 'download', 'f' => fm_rel_path($edit_file, $ROOT)])) ?>" class="btn btn-sm">
        <i class="fa-solid fa-download"></i> Scarica versione corrente
      </a>
      <span style="font-size:11px;color:var(--muted);margin-left:auto">
        <strong><?= number_format(strlen($edit_content)) ?></strong> bytes ·
        <?= count(explode("\n", $edit_content)) ?> righe
      </span>
    </div>
  </form>
</div>

<?php else: ?>

<!-- ═══ BREADCRUMB ═══ -->
<div style="background:#fff;border:1px solid var(--border);border-radius:8px;padding:10px 14px;margin-bottom:14px;display:flex;align-items:center;gap:6px;font-size:13px;flex-wrap:wrap">
  <a href="<?= $h(fm_url()) ?>" style="color:var(--p);text-decoration:none;font-weight:700">
    <i class="fa-solid fa-house"></i> /
  </a>
  <?php foreach ($crumbs as $i => $c): ?>
    <span style="color:var(--muted)">/</span>
    <?php if ($i < count($crumbs) - 1): ?>
      <a href="<?= $h(fm_url(['p' => $c['rel']])) ?>" style="color:var(--p);text-decoration:none"><?= $h($c['name']) ?></a>
    <?php else: ?>
      <strong><?= $h($c['name']) ?></strong>
    <?php endif; ?>
  <?php endforeach; ?>

  <span style="margin-left:auto;font-size:11px;color:var(--muted)">
    <?= count($entries) ?> elementi · <?= count(array_filter($entries, fn($e) => $e['is_dir'])) ?> cartelle
  </span>
</div>

<!-- ═══ TOOLBAR AZIONI ═══ -->
<div class="card" style="margin-bottom:14px">
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">

    <!-- Upload multi-file -->
    <form method="POST" enctype="multipart/form-data" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
      <?= csrf_field() ?><input type="hidden" name="p" value="<?= $h($current_rel_dir) ?>">
      <input type="hidden" name="action" value="upload">
      <input type="hidden" name="dest" value="<?= $h(fm_rel_path($current_dir, $ROOT)) ?>">
      <input type="file" name="files[]" multiple required
             style="padding:5px;border:1px dashed var(--border);border-radius:6px;font-size:11px">
      <button type="submit" class="btn btn-sm btn-primary">
        <i class="fa-solid fa-cloud-arrow-up"></i> Carica
      </button>
    </form>

    <span style="color:var(--border)">|</span>

    <!-- Nuova cartella -->
    <form method="POST" style="display:flex;gap:6px;align-items:center">
      <?= csrf_field() ?><input type="hidden" name="p" value="<?= $h($current_rel_dir) ?>">
      <input type="hidden" name="action" value="mkdir">
      <input type="hidden" name="parent" value="<?= $h(fm_rel_path($current_dir, $ROOT)) ?>">
      <input type="text" name="dir_name" placeholder="Nome nuova cartella" required
             style="padding:6px 8px;border:1px solid var(--border);border-radius:5px;font-size:11px;width:160px">
      <button type="submit" class="btn btn-sm">
        <i class="fa-solid fa-folder-plus"></i> Crea
      </button>
    </form>

    <span style="margin-left:auto;font-size:11px;color:var(--muted)">
      Max upload: 100 MB/file
    </span>
  </div>
</div>

<!-- ═══ LISTING ═══ -->
<form method="POST" id="bulkForm">
  <?= csrf_field() ?><input type="hidden" name="p" value="<?= $h($current_rel_dir) ?>">
  <input type="hidden" name="action" id="bulkAction">
  <div class="card">
    <div class="card-header">
      <span class="card-title">
        <i class="fa-solid fa-list"></i> Contenuto cartella
      </span>
      <div style="display:flex;gap:8px;align-items:center">
        <span id="selCount" style="font-size:11px;color:var(--muted)">0 selezionati</span>
        <button type="button" onclick="submitBulk('download_zip')" class="btn btn-sm" style="background:#0ea5e9;color:#fff;border:0" disabled id="btnZip">
          <i class="fa-solid fa-file-zipper"></i> ZIP
        </button>
        <button type="button" onclick="if(confirm('Eliminare ' + selectedCount() + ' elementi?')) submitBulk('delete_multi')" class="btn btn-sm" style="background:#dc2626;color:#fff;border:0" disabled id="btnDelMulti">
          <i class="fa-solid fa-trash"></i> Elimina selezionati
        </button>
      </div>
    </div>

    <?php if (empty($entries)): ?>
      <div style="text-align:center;padding:40px;color:var(--muted)">
        <i class="fa-solid fa-folder-open" style="font-size:36px;opacity:.3;display:block;margin-bottom:10px"></i>
        Cartella vuota
      </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="data-table" id="fileTable">
      <thead><tr>
        <th style="width:30px"><input type="checkbox" id="selAll" onchange="toggleAll(this)"></th>
        <th>Nome</th>
        <th style="width:90px">Dimensione</th>
        <th style="width:130px">Modificato</th>
        <th style="width:240px;text-align:right">Azioni</th>
      </tr></thead>
      <tbody>
        <?php foreach ($entries as $e):
          [$icon, $color] = fm_icon($e['ext'], $e['is_dir']);
          $is_text = !$e['is_dir'] && in_array($e['ext'], $TEXT_EXTS, true) && $e['size'] <= $MAX_EDIT_SIZE;
        ?>
        <tr>
          <td><input type="checkbox" name="items[]" value="<?= $h($e['rel']) ?>" class="rowSel" onchange="updateSel()"></td>
          <td>
            <?php if ($e['is_dir']): ?>
              <a href="<?= $h(fm_url(['p' => $e['rel']])) ?>" style="text-decoration:none;color:inherit;font-weight:700">
                <i class="fa-solid <?= $icon ?>" style="color:<?= $color ?>;margin-right:6px"></i><?= $h($e['name']) ?>
              </a>
            <?php else: ?>
              <span style="font-size:12px">
                <i class="fa-solid <?= $icon ?>" style="color:<?= $color ?>;margin-right:6px"></i><?= $h($e['name']) ?>
                <?php if (!$e['writable']): ?><i class="fa-solid fa-lock" style="color:#94a3b8;font-size:9px;margin-left:4px" title="Read-only"></i><?php endif; ?>
              </span>
            <?php endif; ?>
          </td>
          <td style="font-size:11px;color:var(--muted)"><?= $e['is_dir'] ? '—' : fm_format_size($e['size']) ?></td>
          <td style="font-size:11px;color:var(--muted)"><?= date('d/m/Y H:i', $e['mtime']) ?></td>
          <td style="text-align:right;white-space:nowrap">
            <?php if (!$e['is_dir']): ?>
              <a href="<?= $h(fm_url(['op' => 'view', 'f' => $e['rel']])) ?>" target="_blank" rel="noopener" class="btn btn-sm" title="Visualizza inline">
                <i class="fa-solid fa-eye"></i>
              </a>
              <a href="<?= $h(fm_url(['op' => 'download', 'f' => $e['rel']])) ?>" class="btn btn-sm" title="Scarica">
                <i class="fa-solid fa-download"></i>
              </a>
              <?php if ($is_text): ?>
              <a href="<?= $h(fm_url(['op' => 'edit', 'f' => $e['rel']])) ?>" class="btn btn-sm" style="background:#dbeafe;color:#1e40af" title="Modifica testo">
                <i class="fa-solid fa-pen"></i>
              </a>
              <?php endif; ?>
            <?php endif; ?>
            <button type="button" data-rel="<?= $h($e['rel']) ?>" data-name="<?= $h($e['name']) ?>" onclick="renameItem(this.dataset.rel, this.dataset.name)" class="btn btn-sm" title="Rinomina">
              <i class="fa-solid fa-i-cursor"></i>
            </button>
            <button type="button" data-rel="<?= $h($e['rel']) ?>" data-name="<?= $h($e['name']) ?>" onclick="deleteItem(this.dataset.rel, this.dataset.name)" class="btn btn-sm" style="background:#fee2e2;color:#991b1b" title="Elimina">
              <i class="fa-solid fa-trash"></i>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</form>

<!-- Form nascosti per azioni singole -->
<form method="POST" id="renameForm" style="display:none">
  <?= csrf_field() ?><input type="hidden" name="p" value="<?= $h($current_rel_dir) ?>">
  <input type="hidden" name="action" value="rename">
  <input type="hidden" name="source" id="renameSource">
  <input type="hidden" name="new_name" id="renameNewName">
</form>
<form method="POST" id="deleteForm" style="display:none">
  <?= csrf_field() ?><input type="hidden" name="p" value="<?= $h($current_rel_dir) ?>">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="target" id="deleteTarget">
</form>

<script>
function toggleAll(cb) {
  document.querySelectorAll('.rowSel').forEach(c => c.checked = cb.checked);
  updateSel();
}
function selectedCount() {
  return document.querySelectorAll('.rowSel:checked').length;
}
function updateSel() {
  const n = selectedCount();
  document.getElementById('selCount').textContent = n + ' selezionati';
  document.getElementById('btnZip').disabled = n === 0;
  document.getElementById('btnDelMulti').disabled = n === 0;
}
function submitBulk(action) {
  document.getElementById('bulkAction').value = action;
  document.getElementById('bulkForm').submit();
}
function renameItem(rel, oldName) {
  const newName = prompt('Nuovo nome:', oldName);
  if (!newName || newName === oldName) return;
  document.getElementById('renameSource').value = rel;
  document.getElementById('renameNewName').value = newName;
  document.getElementById('renameForm').submit();
}
function deleteItem(rel, name) {
  if (!confirm('Eliminare definitivamente "' + name + '"?\n\nSe è una cartella, viene eliminato anche tutto il contenuto.')) return;
  document.getElementById('deleteTarget').value = rel;
  document.getElementById('deleteForm').submit();
}
</script>

<?php endif; ?>

<?php require_once('footer.php'); ?>
