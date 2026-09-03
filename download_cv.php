<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.24 — Download sicuro CV (autenticato + audit).
 */
require __DIR__ . '/bootstrap.php';
require_login();
if (!can('manage_applications.php')) { http_response_code(403); exit; }

$appId = (int)($_GET['app'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT cv.original_name, cv.stored_name, cv.mime_type
     FROM job_applications a
     JOIN candidate_cv_files cv ON cv.id = a.cv_file_id
     WHERE a.id = ? LIMIT 1"
);
$stmt->execute([$appId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit; }

$storeRel = (string)($pdo->query("SELECT svalue FROM app_settings WHERE skey='careers.storage_path'")->fetchColumn() ?: 'uploads/candidates');
$storeAbs = realpath(__DIR__) . '/' . $storeRel;
$path = $storeAbs . '/' . $row['stored_name'];
$real = realpath($path);
if ($real === false || strpos($real, realpath($storeAbs) ?: '') !== 0) { http_response_code(404); exit; }

write_log('download_cv', 'Download CV app #' . $appId, ['app'=>$appId]);
header('Content-Type: ' . $row['mime_type']);
header('Content-Disposition: attachment; filename="' . preg_replace('/["\r\n]/', '', (string)$row['original_name']) . '"');
header('Content-Length: ' . filesize($real));
header('X-Content-Type-Options: nosniff');
readfile($real);
