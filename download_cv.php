<?php
declare(strict_types=1);
/**
 * PortalManager — Download sicuro CV (autenticato + audit).
 * v1.9.53: corretto include bootstrap, controllo sessione nativo e allineamento schema tabelle.
 */
require_once __DIR__ . '/app/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die('Accesso non autorizzato. Effettuare il login.');
}

if (!can('view', 'manage_applications.php') && !can('view', 'recruiting_candidati.php') && (int)($_SESSION['role_id'] ?? 99) !== 1) {
    http_response_code(403);
    die('Permesso negato.');
}

$appId = (int)($_GET['app'] ?? 0);
$candId = (int)($_GET['cand'] ?? 0);
if ($appId <= 0 && $candId <= 0) {
    http_response_code(400);
    die('Parametro richiesta mancante.');
}

$row = null;

// 1. Tentativo sullo schema reale (candidate_applications + candidate_documents)
try {
    if ($appId > 0) {
        $stmt = $pdo->prepare(
            "SELECT d.original_filename AS original_name, d.file_path AS stored_name, d.mime_type
             FROM candidate_applications a
             JOIN candidate_documents d ON d.candidate_id = a.candidate_id AND d.doc_type = 'cv'
             WHERE a.id = ?
             ORDER BY d.id DESC LIMIT 1"
        );
        $stmt->execute([$appId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $candId > 0) {
        $stmt = $pdo->prepare(
            "SELECT original_filename AS original_name, file_path AS stored_name, mime_type
             FROM candidate_documents
             WHERE candidate_id = ? AND doc_type = 'cv'
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$candId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (\Throwable $e) {}

// 2. Tentativo di fallback su schema legacy (job_applications + candidate_cv_files)
if (!$row && $appId > 0) {
    try {
        $stmt = $pdo->prepare(
            "SELECT cv.original_name, cv.stored_name, cv.mime_type
             FROM job_applications a
             JOIN candidate_cv_files cv ON cv.id = a.cv_file_id
             WHERE a.id = ? LIMIT 1"
        );
        $stmt->execute([$appId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
}

if (!$row || empty($row['stored_name'])) {
    http_response_code(404);
    die('CV non trovato nel database.');
}

// Risoluzione sicura del percorso del file
$storeRel = '';
try {
    $storeRel = (string)($pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='careers.storage_path' LIMIT 1")->fetchColumn() ?: 'uploads/cv_imports');
} catch (\Throwable $e) {
    $storeRel = 'uploads/cv_imports';
}

$storedName = (string)$row['stored_name'];
$appRootReal = realpath(__DIR__);
$candidates = [
    realpath(__DIR__ . '/uploads') ? realpath(__DIR__ . '/uploads') . '/' . ltrim($storedName, '/') : null,
    $appRootReal ? $appRootReal . '/' . ltrim($storeRel, '/') . '/' . basename($storedName) : null,
    realpath(__DIR__ . '/uploads/cv_imports') ? realpath(__DIR__ . '/uploads/cv_imports') . '/' . basename($storedName) : null,
    realpath(__DIR__ . '/uploads/candidates') ? realpath(__DIR__ . '/uploads/candidates') . '/' . basename($storedName) : null,
];

$real = false;
foreach ($candidates as $cand) {
    if (!$cand) continue;
    $cReal = realpath($cand);
    if ($cReal && $appRootReal && strpos($cReal, $appRootReal . DIRECTORY_SEPARATOR . 'uploads') === 0 && is_file($cReal)) {
        $real = $cReal;
        break;
    }
}

if ($real === false) {
    http_response_code(404);
    die('File CV non presente su disco.');
}

if (function_exists('write_log')) {
    write_log('download_cv', 'success', 'Download CV #' . ($appId ?: $candId), (int)$_SESSION['user_id']);
}

$mimeType = !empty($row['mime_type']) ? $row['mime_type'] : 'application/octet-stream';
$filename = !empty($row['original_name']) ? basename((string)$row['original_name']) : basename($real);

header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . preg_replace('/["\r\n]/', '', $filename) . '"');
header('Content-Length: ' . filesize($real));
header('X-Content-Type-Options: nosniff');
readfile($real);
exit;
