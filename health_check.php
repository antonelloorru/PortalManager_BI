<?php
/**
 * certV 2.2 — health_check.php
 * Diagnostica rapida dell'installazione.
 * ELIMINARE dopo l'uso in produzione.
 */

$checks = [];
$overall = true;

// PHP
$checks[] = ['PHP Version', PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>=')];

// Extensions
foreach (['pdo', 'pdo_mysql', 'mbstring', 'json', 'session', 'fileinfo'] as $ext) {
    $ok = extension_loaded($ext);
    $checks[] = ["ext-$ext", $ok ? 'Loaded' : 'MISSING', $ok];
    if (!$ok) $overall = false;
}

// Config.php
$configOk = file_exists(__DIR__ . '/Config.php');
$checks[] = ['Config.php', $configOk ? 'Found' : 'MISSING', $configOk];
if (!$configOk) $overall = false;

// DB Connection
$dbOk = false;
$tableCount = 0;
if ($configOk) {
    try {
        require_once __DIR__ . '/Config.php';
        $tableCount = count($pdo->query("SHOW TABLES")->fetchAll());
        $dbOk = true;
    } catch (Exception $e) {
        $checks[] = ['DB Connection', $e->getMessage(), false];
    }
}
$checks[] = ['DB Connection', $dbOk ? "OK ($tableCount tables)" : 'FAILED', $dbOk];
if (!$dbOk) $overall = false;

// Key tables
if ($dbOk) {
    $required = ['users', 'employees', 'roles', 'brands', 'certifications', 'user_certifications',
                 'job_positions', 'candidates', 'candidate_applications', 'agencies', 'app_settings'];
    foreach ($required as $tbl) {
        try {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
            $checks[] = ["Table: $tbl", "$count rows", true];
        } catch (Exception $e) {
            $checks[] = ["Table: $tbl", 'MISSING', false];
            $overall = false;
        }
    }
}

// Writable dirs
foreach (['uploads', 'uploads/candidati', 'uploads/certificati'] as $dir) {
    $path = __DIR__ . '/' . $dir;
    $exists = is_dir($path);
    $writable = $exists && is_writable($path);
    $checks[] = ["Dir: $dir", $writable ? 'Writable' : ($exists ? 'NOT WRITABLE' : 'NOT FOUND'), $writable];
    if (!$writable) $overall = false;
}

// .htaccess
$checks[] = ['.htaccess', file_exists(__DIR__ . '/.htaccess') ? 'Found' : 'Missing (recommended)', file_exists(__DIR__ . '/.htaccess')];

// install.php safety
$installExists = file_exists(__DIR__ . '/install.php');
$checks[] = ['install.php', $installExists ? 'STILL PRESENT (rename to .done)' : 'Secured', !$installExists];

// Output
header('Content-Type: text/html; charset=utf-8');
$title = $overall ? '✅ Sistema OK' : '⚠️ Problemi rilevati';
?>
<!DOCTYPE html>
<html lang="it">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>certV Health Check</title>
<style>
body{font-family:system-ui,sans-serif;background:#f0f4f8;padding:30px;color:#1e293b;font-size:14px}
.box{max-width:700px;margin:0 auto;background:#fff;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid #e2e8f0;overflow:hidden}
.hd{padding:20px 24px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:12px}
.hd h1{font-size:18px}
.row{display:flex;align-items:center;padding:10px 24px;border-bottom:1px solid #f1f5f9;gap:12px}
.row:last-child{border-bottom:none}
.icon{width:24px;height:24px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0}
.ok{background:#ecfdf5;color:#059669}.fail{background:#fef2f2;color:#dc2626}
.name{flex:1;font-weight:600;font-size:13px}.val{font-size:12px;color:#64748b;font-family:Consolas,monospace}
.ft{padding:16px 24px;background:#f8fafc;font-size:11px;color:#64748b;text-align:center}
</style></head><body>
<?php $page_title = "Health Check"; require __DIR__ . "/_nav_system.php"; ?>
<div class="box">
<div class="hd"><h1><?=$title?></h1></div>
<?php foreach($checks as $c): ?>
<div class="row">
<div class="icon <?=$c[2]?'ok':'fail'?>"><?=$c[2]?'✓':'✗'?></div>
<div class="name"><?=htmlspecialchars($c[0])?></div>
<div class="val"><?=htmlspecialchars($c[1])?></div>
</div>
<?php endforeach; ?>
<div class="ft">certV 2.2 Health Check — <?=date('Y-m-d H:i:s')?> — Eliminare questo file in produzione</div>
</div></body></html>
