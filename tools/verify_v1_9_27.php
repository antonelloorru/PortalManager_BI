<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.27 — Verifica installazione patch employee_profile.php
 * Uso: php tools\verify_v1_9_27.php [path\employee_profile.php]
 */
$target = $argv[1] ?? realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'employee_profile.php';
if (!is_file($target)) { fwrite(STDERR, "Non trovato: $target\n"); exit(1); }
$src = file_get_contents($target) ?: '';

$hasMarker = strpos($src, 'PM_V1_9_27_APPLIED') !== false;
$empBeforePost = false;
$preLines = explode("\n", $src);
$foundPost = null; $foundPre = null;
foreach ($preLines as $i => $ln) {
    if ($foundPre === null && preg_match('/\$emp\s*=\s*\$__pm_pre->fetch/', $ln)) $foundPre = $i + 1;
    if ($foundPost === null && preg_match('/REQUEST_METHOD.*POST/', $ln)) $foundPost = $i + 1;
}
if ($foundPre !== null && $foundPost !== null) $empBeforePost = $foundPre < $foundPost;

echo "File:              $target\n";
echo "Marker presente:   " . ($hasMarker ? 'SI' : 'NO') . "\n";
echo "Pre-fetch riga:    " . ($foundPre ?? '-') . "\n";
echo "POST branch riga:  " . ($foundPost ?? '-') . "\n";
echo "Pre-fetch PRIMA di POST: " . ($empBeforePost ? 'SI' : 'NO') . "\n";

$exit = ($hasMarker && $empBeforePost) ? 0 : 1;
echo "\n" . ($exit === 0 ? '[OK] Patch v1.9.27 correttamente installata.' : '[KO] Patch NON attiva o mal posizionata.') . "\n";
exit($exit);
