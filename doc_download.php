<?php
/**
 * certV 2.4 — doc_download.php
 * Download sicuro documenti con controllo accesso basato su ruolo.
 * Solo utenti autorizzati possono scaricare in base a document_access_rules.
 */
// v1.7.2 FIX: usa il bootstrap con Session hardened (cookie certV_sid),
// non session_start() nativo che cerca PHPSESSID e non trova la sessione.
if (!class_exists('Session')) {
    require_once __DIR__ . '/app/bootstrap.php';
}
require_once __DIR__ . '/Config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
$u_emp  = (int)($_SESSION['employee_id'] ?? 0);
$doc_id = (int)($_GET['id'] ?? 0);

if (!$doc_id) { http_response_code(400); die("ID documento mancante."); }

// Carica documento
$s = $pdo->prepare("SELECT * FROM person_documents WHERE id=?");
$s->execute([$doc_id]);
$doc = $s->fetch();
$s->closeCursor();

if (!$doc) { http_response_code(404); die("Documento non trovato."); }

// ── Controllo accesso ────────────────────────────────────────────────────────

// Super Admin: accesso sempre
if ($u_role > 1) {
    // 1. Controlla matrice accesso per tipo documento
    $aq = $pdo->prepare("SELECT can_download FROM document_access_rules WHERE doc_type=? AND role_id=?");
    $aq->execute([$doc['doc_type'], $u_role]);
    $rule = $aq->fetch();
    $aq->closeCursor();

    if (!$rule || !(int)$rule['can_download']) {
        http_response_code(403);
        die("Accesso negato: il tuo ruolo non ha permesso di scaricare documenti di tipo '{$doc['doc_type']}'.");
    }

    // 2. Dipendente (ruolo 6): può scaricare solo i PROPRI documenti
    if ($u_role >= 6) {
        $is_own = false;
        if ($doc['employee_id'] && $doc['employee_id'] == $u_emp) $is_own = true;
        // Oppure se il candidato è stato convertito a dipendente
        // (il check avviene sul employee_id impostato nel documento)
        if (!$is_own) {
            http_response_code(403);
            die("Accesso negato: puoi scaricare solo i tuoi documenti personali.");
        }
    }

    // 3. Controlla visibilità e ruolo minimo impostato sul documento
    if ($doc['visibility'] === 'restricted' && $u_role > (int)$doc['min_role_download']) {
        http_response_code(403);
        die("Accesso negato: livello di riservatezza insufficiente.");
    }
}

// ── Serve il file ────────────────────────────────────────────────────────────
$filepath = __DIR__ . '/uploads/documenti/' . $doc['file_name'];

if (!file_exists($filepath)) {
    http_response_code(404);
    die("File non trovato su disco.");
}

// Log download
try {
    $pdo->prepare("INSERT INTO app_logs (category, level, message, user_id, ip_address) VALUES (?,?,?,?,?)")
        ->execute(['Documenti', 'info', "Download doc #{$doc_id} ({$doc['doc_type']}) — {$doc['original_name']}", $u_id, $_SERVER['REMOTE_ADDR'] ?? '']);
} catch (\Exception $e) {}

// Headers per download
$mime = $doc['mime_type'] ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
// v5.02.05: supporta visualizzazione inline (per PDF/immagini) tramite ?inline=1
$disposition = !empty($_GET['inline']) ? 'inline' : 'attachment';
header('Content-Disposition: ' . $disposition . '; filename="' . $doc['original_name'] . '"');
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($filepath);
exit();
