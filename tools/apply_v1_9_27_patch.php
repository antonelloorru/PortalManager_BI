<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.27 — Auto-patch employee_profile.php
 * (Aggiornato: layout GitHub v1.9.27 + fallback ZIP; idempotente; validazione php -l)
 *
 * Bug: il ramo POST 'save_anagrafica' usa $emp[...] per preservare campi non
 * modificati dal form, ma $emp e' caricato solo alla riga ~671. Risultato:
 *   - Warning "Undefined variable $emp"
 *   - Data-loss: contract_type, hire_date, end_date, badge_*, gender,
 *     ccnl, qualification, contract_level, agency, part_time, notes AZZERATI.
 *
 * Fix: pre-fetch di $emp subito dopo la riga `if (!$emp_id) { redirect(...); }`.
 * Il fetch principale piu' in basso sovrascrive $emp con la versione arricchita
 * di JOIN per il rendering.
 *
 * Uso:
 *   php tools\apply_v1_9_27_patch.php                       (default ..\employee_profile.php)
 *   php tools\apply_v1_9_27_patch.php <path>
 *   php tools\apply_v1_9_27_patch.php <path> --dry-run
 */

$dry = false;
$target = null;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run') $dry = true;
    else $target = $a;
}
$target = $target ?: realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'employee_profile.php';

if (!is_file($target)) { fwrite(STDERR, "[ERRORE] File non trovato: $target\n"); exit(1); }
$src = file_get_contents($target);
if ($src === false) { fwrite(STDERR, "[ERRORE] Lettura fallita\n"); exit(1); }

if (strpos($src, 'PM_V1_9_27_APPLIED') !== false) {
    echo "[SKIP] Patch v1.9.27 gia' applicata a $target\n"; exit(0);
}

echo "→ Target: $target (" . strlen($src) . " byte)\n";

$block = <<<'PHP'


// [PM_V1_9_27_APPLIED] Pre-fetch $emp per il branch POST che preserva i campi
// non modificati dal form (evita data-loss silenzioso). Il fetch principale
// piu' in basso sovrascrive $emp con la versione arricchita di JOIN per il render.
try {
    $__pm_pre = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $__pm_pre->execute([$emp_id]);
    $emp = $__pm_pre->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $__pm_e) {
    $emp = [];
}
PHP;

// Ancora tollerante a varianti di spazi/virgolette
$rx = '/(if\s*\(\s*!\s*\$emp_id\s*\)\s*\{\s*redirect\s*\(\s*[\'"]manage_employees[\'"]\s*\)\s*;\s*\}[^\n]*\n)/';
if (!preg_match($rx, $src, $m, PREG_OFFSET_CAPTURE)) {
    fwrite(STDERR, "[ERRORE] Ancora non trovata: `if (!\$emp_id) { redirect('manage_employees'); }`\n");
    fwrite(STDERR, "         Aprire il file, individuare quella riga (di solito ~32) e inserire\n");
    fwrite(STDERR, "         SUBITO DOPO il blocco stampato con --dry-run.\n");
    exit(3);
}
$pos = $m[0][1] + strlen($m[0][0]);
$new = substr($src, 0, $pos) . $block . "\n" . substr($src, $pos);

$line = substr_count(substr($src, 0, $pos), "\n") + 1;
echo "→ Inserimento pre-fetch dopo la riga " . ($line - 1) . " (offset $pos)\n";

if ($dry) {
    echo "\n[DRY-RUN] File NON scritto. Blocco che sarebbe inserito:\n";
    echo "----------------------------------------\n";
    echo $block . "\n";
    echo "----------------------------------------\n";
    exit(0);
}

$bak = $target . '.bak_v1_9_27_' . date('Ymd_His');
if (!copy($target, $bak)) { fwrite(STDERR, "[ERRORE] Backup fallito\n"); exit(1); }
echo "→ Backup: $bak\n";

$tmp = $target . '.tmp_v1_9_27';
if (file_put_contents($tmp, $new) === false) { fwrite(STDERR, "[ERRORE] Scrittura fallita\n"); exit(1); }
if (!rename($tmp, $target)) { fwrite(STDERR, "[ERRORE] Rename fallito\n"); exit(1); }

$lint = shell_exec('php -l ' . escapeshellarg($target) . ' 2>&1');
if (strpos((string)$lint, 'No syntax errors') === false) {
    fwrite(STDERR, "[ROLLBACK] Sintassi non valida. Ripristino backup.\n$lint\n");
    copy($bak, $target);
    exit(2);
}

echo "[OK] Patch v1.9.27 applicata. php -l pulito.\n";
echo "     Backup: $bak\n";
echo "\nRicordati di svuotare OPcache o riavviare Apache perche' la nuova versione entri in servizio:\n";
echo "  net stop Apache2.4 ; net start Apache2.4\n";
exit(0);
