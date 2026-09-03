<?php
/**
 * certV 4.0 — download.php
 * Download sicuro file da uploads/ con path traversal prevention.
 * Supporta ?file= (legacy) e ?f= (backups admin).
 */
// v1.7.2 FIX: usa il bootstrap con Session hardened (cookie certV_sid),
// non session_start() nativo che cerca PHPSESSID e non trova la sessione.
if (!class_exists('Session')) {
    require_once __DIR__ . '/app/bootstrap.php';
}
require_once __DIR__ . '/Config.php';

if (!isset($_SESSION['user_id'])) { http_response_code(403); die("Accesso negato."); }

$requested = $_GET['file'] ?? $_GET['f'] ?? '';
if (!$requested) { http_response_code(400); die("Parametro mancante."); }

// v1.7.2: normalizzazione robusta multi-livello con protezione path-traversal.
// Rimuove componenti '..' e '.' invece di tagliare il path a 1 livello.
$requested = str_replace('\\', '/', $requested);
$parts = array_filter(explode('/', $requested), function ($p) {
    return $p !== '' && $p !== '.' && $p !== '..';
});
$file_path = implode('/', $parts);

$real_path = realpath(UPLOAD_DIR . $file_path);
$base_path = realpath(UPLOAD_DIR);

if (!$real_path || !$base_path || strpos($real_path, $base_path) !== 0) {
    http_response_code(403); die("Percorso non valido.");
}

$u_role = (int)($_SESSION['role_id'] ?? 99);

// Backup: solo Super Admin
if (strpos($file_path, 'backups/') === 0 && $u_role !== 1) {
    http_response_code(403); die("Solo Super Admin può scaricare i backup.");
}

// Dipendente: solo i propri documenti
if ($u_role === 6 && strpos($file_path, 'backups/') !== 0) {
    require_once __DIR__ . '/functions.php';
    $s = $pdo->prepare("SELECT id FROM user_certifications WHERE user_id=? AND document_path=?");
    $s->execute([$_SESSION['user_id'], $file_path]);
    if (!$s->fetch()) { http_response_code(403); die("Non autorizzato."); }
}

if (!file_exists($real_path)) { http_response_code(404); die("File non trovato."); }

$mime = mime_content_type($real_path) ?: 'application/octet-stream';
$name = basename($real_path);
$disposition = (preg_match('/\.(zip|sql|csv)$/i', $name)) ? 'attachment' : 'inline';

header("Content-Type: $mime");
header("Content-Disposition: $disposition; filename=\"$name\"");
header("Content-Length: " . filesize($real_path));
header("Cache-Control: no-cache");
readfile($real_path);
exit;
