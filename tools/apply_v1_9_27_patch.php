<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.27 — Fix DATA-LOSS su employee_profile.php
 *
 * Bug reale (non solo warning):
 *   Il ramo POST 'save_anagrafica' usa $emp['contract_type'], $emp['hire_date'],
 *   $emp['end_date'], $emp['apprenticeship_end_date'], $emp['badge_number'],
 *   $emp['badge_issue_date'], ... per PRESERVARE i campi non modificati dal form.
 *   Ma $emp viene caricato PIÙ IN BASSO (linea ~671, dopo la chiusura del POST).
 *   Risultato: quei campi vengono AZZERATI nell'UPDATE → data-loss silenzioso.
 *
 * Fix: pre-fetch di $emp SUBITO DOPO la riga `if (!$emp_id) { redirect(...); }`.
 * Il fetch grande più in basso viene mantenuto e sovrascrive $emp con la versione
 * arricchita di JOIN per il rendering.
 *
 * Idempotente (marker PM_V1_9_27_APPLIED). Backup + validazione php -l + rollback.
 *
 * Uso:
 *   php tools\apply_v1_9_27_patch.php                       (target ..\employee_profile.php)
 *   php tools\apply_v1_9_27_patch.php <path\employee_profile.php>
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
    echo "[SKIP] Patch v1.9.27 già applicata a $target\n"; exit(0);
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

// Ancora: la riga "if (!$emp_id) { redirect('manage_employees'); }"
// (tolleranza per virgolette e spazi).
$rx = '/(if\s*\(\s*!\s*\$emp_id\s*\)\s*\{\s*redirect\s*\(\s*[\'"]manage_employees[\'"]\s*\)\s*;\s*\}\s*)/';
if (!preg_match($rx, $src, $m, PREG_OFFSET_CAPTURE)) {
    // Fallback: ancora sulla riga di assegnazione $emp_id
    $rx2 = '/(\$emp_id\s*=\s*\(int\)\s*\(\s*\$_GET\s*\[\s*[\'"]id[\'"]\s*\]\s*\?\?\s*0\s*\)\s*;\s*)/';
    if (!preg_match($rx2, $src, $m, PREG_OFFSET_CAPTURE)) {
        fwrite(STDERR, "[ERRORE] Ancore non trovate. File probabilmente diverso dalla revisione attesa.\n");
        fwrite(STDERR, "         Cerca manualmente la riga `if (!\$emp_id) { redirect('manage_employees'); }`\n");
        fwrite(STDERR, "         e inserisci subito dopo il blocco stampato con --dry-run.\n");
        exit(3);
    }
    $rx = $rx2;
}
$pos = $m[0][1] + strlen($m[0][0]);
$new = substr($src, 0, $pos) . "\n" . $block . "\n" . substr($src, $pos);

$offset = $pos;
$line   = substr_count(substr($src, 0, $offset), "\n") + 1;
echo "→ Inserimento pre-fetch dopo la riga $line (offset $offset)\n";

if ($dry) {
    echo "\n[DRY-RUN] File NON scritto. Anteprima del blocco inserito:\n";
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
echo "     Backup di sicurezza: $bak\n";
echo "\nEffetto atteso:\n";
echo "  - Warning `Undefined variable \$emp` alle righe 80-94: RISOLTI.\n";
echo "  - Data-loss silenzioso sui campi preservati nell'UPDATE: RISOLTO.\n";
echo "\nVerifica dal browser:\n";
echo "  1) Apri Anagrafica dipendente e prova a salvare.\n";
echo "  2) Controlla che contract_type / hire_date / end_date / badge_* rimangano invariati\n";
echo "     dopo un salvataggio parziale.\n";
exit(0);
